<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$users  = require __DIR__ . '/users.php';
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

require_once __DIR__ . '/db.php';

// ─── helpers ───────────────────────────────────────────────────
function setSession($user) {
    $_SESSION['auth'] = [
        'id'     => $user['id'],
        'name'   => $user['name'],
        'email'  => $user['email'],
        'role'   => $user['role'],
        'avatar' => $user['avatar'],
    ];
}
function ok($data=[])  { echo json_encode(array_merge(['ok'=>true],  $data)); exit; }
function fail($msg)    { http_response_code(401); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

// ─── check ─────────────────────────────────────────────────────
if ($action === 'check') {
    echo json_encode(['ok' => !empty($_SESSION['auth']), 'user' => $_SESSION['auth'] ?? null]);
    exit;
}

// ─── logout ────────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    ok();
}

// ─── login ─────────────────────────────────────────────────────
if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    $hash     = hash('sha256', $password);

    foreach ($users as $user) {
        if ($user['username'] === $username) {
            // check DB override first, fall back to users.php
            $stmt = db()->prepare('SELECT password_hash FROM user_passwords WHERE user_id = ?');
            $stmt->execute([$user['id']]);
            $row      = $stmt->fetch();
            $expected = $row ? $row['password_hash'] : $user['password_sha256'];

            if ($expected === $hash) {
                setSession($user);
                ok(['name' => $user['name'], 'avatar' => $user['avatar']]);
            }
            break;
        }
    }
    fail('שם משתמש או סיסמה שגויים');
}

// ─── reset-request ─────────────────────────────────────────────
if ($action === 'reset-request') {
    $email = strtolower(trim($body['email'] ?? ''));
    if (!$email) fail('חסר מייל');

    $targetUser = null;
    foreach ($users as $user) {
        if (strtolower($user['email']) === $email) { $targetUser = $user; break; }
    }
    if (!$targetUser) fail('כתובת המייל לא נמצאה במערכת');

    $token  = bin2hex(random_bytes(32));
    $expiry = time() + 3600;

    // delete any existing token for this user, then insert new one
    $pdo = db();
    $pdo->prepare('DELETE FROM reset_tokens WHERE user_id = ?')->execute([$targetUser['id']]);
    $pdo->prepare('INSERT INTO reset_tokens (token, user_id, expires_at) VALUES (?,?,?)')->execute([$token, $targetUser['id'], $expiry]);

    $proto    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $resetUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . '/price/login.html?reset=' . $token;
    $subject  = '=?UTF-8?B?' . base64_encode('איפוס סיסמה - Quotes') . '?=';
    $name     = $targetUser['name'];
    $message  = "שלום $name,\n\nלחץ על הקישור הבא לאיפוס הסיסמה שלך:\n\n$resetUrl\n\nהקישור תקף לשעה אחת.\n\nאם לא ביקשת איפוס, אפשר להתעלם מהמייל הזה.";
    $headers  = "From: Quotes <noreply@ariel-azulay.co.il>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";

    if (!@mail($email, $subject, $message, $headers)) fail('שגיאה בשליחת המייל');
    ok();
}

// ─── reset-confirm ─────────────────────────────────────────────
if ($action === 'reset-confirm') {
    $token    = trim($body['token'] ?? '');
    $password = trim($body['password'] ?? '');
    if (!$token || !$password) fail('חסרים פרטים');
    if (strlen($password) < 6) fail('הסיסמה חייבת להכיל לפחות 6 תווים');

    $pdo  = db();
    $stmt = $pdo->prepare('SELECT user_id FROM reset_tokens WHERE token = ? AND expires_at > ?');
    $stmt->execute([$token, time()]);
    $row  = $stmt->fetch();
    if (!$row) fail('הקישור פג תוקף או לא תקין');

    $uid = $row['user_id'];
    $pdo->prepare('INSERT INTO user_passwords (user_id, password_hash) VALUES (?,?)
        ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')->execute([$uid, hash('sha256', $password)]);
    $pdo->prepare('DELETE FROM reset_tokens WHERE token = ?')->execute([$token]);
    ok();
}

// ─── Google Sign-In ─────────────────────────────────────────────
if ($action === 'google') {
    $token = $body['credential'] ?? '';
    if (!$token) fail('חסר טוקן');

    $r    = @file_get_contents("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
    $data = $r ? json_decode($r, true) : null;
    if (!$data || empty($data['email'])) fail('טוקן לא תקין');

    $email = strtolower($data['email']);
    foreach ($users as $user) {
        if (strtolower($user['email']) === $email) {
            setSession($user);
            ok(['name' => $user['name'], 'avatar' => $user['avatar']]);
        }
    }
    fail('המייל הזה לא מורשה');
}

// ─── WebAuthn: get challenge ────────────────────────────────────
if ($action === 'webauthn-challenge') {
    $challenge = base64_encode(random_bytes(32));
    $_SESSION['webauthn_challenge'] = $challenge;
    $userId = $body['userId'] ?? '1';

    $stmt = db()->prepare('SELECT credential_id FROM webauthn_creds WHERE user_id = ?');
    $stmt->execute([$userId]);
    $creds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    ok(['challenge' => $challenge, 'credentials' => $creds]);
}

// ─── WebAuthn: register ─────────────────────────────────────────
if ($action === 'webauthn-register') {
    if (empty($_SESSION['auth'])) fail('לא מחובר');
    $credId = $body['credentialId'] ?? '';
    if (!$credId) fail('חסר credential');

    db()->prepare('INSERT INTO webauthn_creds (user_id, credential_id, name) VALUES (?,?,?)')->execute([
        $_SESSION['auth']['id'], $credId, $_SESSION['auth']['name'],
    ]);
    ok();
}

// ─── WebAuthn: verify ───────────────────────────────────────────
if ($action === 'webauthn-verify') {
    $credId = $body['credentialId'] ?? '';
    $stmt   = db()->prepare('SELECT user_id FROM webauthn_creds WHERE credential_id = ?');
    $stmt->execute([$credId]);
    $row = $stmt->fetch();
    if (!$row) fail('לא מורשה');

    foreach ($users as $user) {
        if ($user['id'] === $row['user_id']) {
            setSession($user);
            ok(['name' => $user['name'], 'avatar' => $user['avatar']]);
        }
    }
    fail('לא מורשה');
}

fail('פעולה לא מוכרת');

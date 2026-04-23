<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/config.php';
$users  = require __DIR__ . '/users.php';
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

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

function getPasswordHash($userId) {
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare('SELECT password_hash FROM user_passwords WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? $row['password_hash'] : null;
    }
    $pwFile = __DIR__ . '/data/passwords.json';
    $pw = file_exists($pwFile) ? json_decode(file_get_contents($pwFile), true) ?? [] : [];
    return $pw[$userId] ?? null;
}

function savePasswordHash($userId, $hash) {
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        db()->prepare('INSERT INTO user_passwords (user_id, password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')
            ->execute([$userId, $hash]);
    } else {
        $pwFile = __DIR__ . '/data/passwords.json';
        $pw = file_exists($pwFile) ? json_decode(file_get_contents($pwFile), true) ?? [] : [];
        $pw[$userId] = $hash;
        file_put_contents($pwFile, json_encode($pw));
    }
}

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
            $override = getPasswordHash($user['id']);
            $expected = $override ?? $user['password_sha256'];
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

    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $pdo = db();
        $pdo->prepare('DELETE FROM reset_tokens WHERE user_id = ?')->execute([$targetUser['id']]);
        $pdo->prepare('INSERT INTO reset_tokens (token, user_id, expires_at) VALUES (?,?,?)')->execute([$token, $targetUser['id'], $expiry]);
    } else {
        $dir        = __DIR__ . '/data/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $tokensFile = $dir . 'reset_tokens.json';
        $tokens     = file_exists($tokensFile) ? json_decode(file_get_contents($tokensFile), true) ?? [] : [];
        $tokens     = array_values(array_filter($tokens, fn($t) => $t['userId'] !== $targetUser['id']));
        $tokens[]   = ['token' => $token, 'userId' => $targetUser['id'], 'expiry' => $expiry];
        file_put_contents($tokensFile, json_encode($tokens));
    }

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

    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT user_id FROM reset_tokens WHERE token = ? AND expires_at > ?');
        $stmt->execute([$token, time()]);
        $row  = $stmt->fetch();
        if (!$row) fail('הקישור פג תוקף או לא תקין');
        savePasswordHash($row['user_id'], hash('sha256', $password));
        $pdo->prepare('DELETE FROM reset_tokens WHERE token = ?')->execute([$token]);
    } else {
        $tokensFile = __DIR__ . '/data/reset_tokens.json';
        if (!file_exists($tokensFile)) fail('קישור לא תקין');
        $tokens = json_decode(file_get_contents($tokensFile), true) ?? [];
        $found  = null;
        foreach ($tokens as $t) {
            if ($t['token'] === $token && $t['expiry'] > time()) { $found = $t; break; }
        }
        if (!$found) fail('הקישור פג תוקף או לא תקין');
        savePasswordHash($found['userId'], hash('sha256', $password));
        $tokens = array_values(array_filter($tokens, fn($t) => $t['token'] !== $token));
        file_put_contents(__DIR__ . '/data/reset_tokens.json', json_encode($tokens));
    }
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

    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare('SELECT credential_id FROM webauthn_creds WHERE user_id = ?');
        $stmt->execute([$userId]);
        $creds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $credFile = __DIR__ . '/data/webauthn_creds.json';
        $stored   = file_exists($credFile) ? json_decode(file_get_contents($credFile), true) ?? [] : [];
        $creds    = array_column(array_filter($stored, fn($c)=>$c['userId']===$userId), 'credentialId');
    }
    ok(['challenge' => $challenge, 'credentials' => $creds]);
}

// ─── WebAuthn: register ─────────────────────────────────────────
if ($action === 'webauthn-register') {
    if (empty($_SESSION['auth'])) fail('לא מחובר');
    $credId = $body['credentialId'] ?? '';
    if (!$credId) fail('חסר credential');
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        db()->prepare('INSERT INTO webauthn_creds (user_id, credential_id, name) VALUES (?,?,?)')
            ->execute([$_SESSION['auth']['id'], $credId, $_SESSION['auth']['name']]);
    } else {
        $credFile = __DIR__ . '/data/webauthn_creds.json';
        $stored   = file_exists($credFile) ? json_decode(file_get_contents($credFile), true) : [];
        $stored[] = ['userId' => $_SESSION['auth']['id'], 'credentialId' => $credId, 'name' => $_SESSION['auth']['name']];
        file_put_contents($credFile, json_encode($stored));
    }
    ok();
}

// ─── WebAuthn: verify ───────────────────────────────────────────
if ($action === 'webauthn-verify') {
    $credId = $body['credentialId'] ?? '';
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare('SELECT user_id FROM webauthn_creds WHERE credential_id = ?');
        $stmt->execute([$credId]);
        $row = $stmt->fetch();
        $uid = $row['user_id'] ?? null;
    } else {
        $credFile = __DIR__ . '/data/webauthn_creds.json';
        $stored   = file_exists($credFile) ? json_decode(file_get_contents($credFile), true) ?? [] : [];
        $uid = null;
        foreach ($stored as $cred) {
            if ($cred['credentialId'] === $credId) { $uid = $cred['userId']; break; }
        }
    }
    if (!$uid) fail('לא מורשה');
    foreach ($users as $user) {
        if ($user['id'] === $uid) {
            setSession($user);
            ok(['name' => $user['name'], 'avatar' => $user['avatar']]);
        }
    }
    fail('לא מורשה');
}

fail('פעולה לא מוכרת');

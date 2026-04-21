<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

$users = require __DIR__ . '/users.php';
$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── helpers ───────────────────────────────────────────────
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

// ─── check ─────────────────────────────────────────────────
if ($action === 'check') {
    echo json_encode(['ok' => !empty($_SESSION['auth']), 'user' => $_SESSION['auth'] ?? null]);
    exit;
}

// ─── logout ────────────────────────────────────────────────
if ($action === 'logout') {
    session_destroy();
    ok();
}

// ─── login (username + password) ───────────────────────────
if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    $hash = hash('sha256', $password);
    foreach ($users as $user) {
        if ($user['username'] === $username && $user['password_sha256'] === $hash) {
            setSession($user);
            ok(['name' => $user['name'], 'avatar' => $user['avatar']]);
        }
    }
    fail('שם משתמש או סיסמה שגויים');
}

// ─── Google Sign-In ─────────────────────────────────────────
if ($action === 'google') {
    $token = $body['credential'] ?? '';
    if (!$token) fail('חסר טוקן');

    $r = @file_get_contents("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
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

// ─── WebAuthn: get challenge ────────────────────────────────
if ($action === 'webauthn-challenge') {
    $challenge = base64_encode(random_bytes(32));
    $_SESSION['webauthn_challenge'] = $challenge;
    $userId = $body['userId'] ?? '1';
    $stored = [];
    $credFile = __DIR__ . '/data/webauthn_creds.json';
    if (file_exists($credFile)) $stored = json_decode(file_get_contents($credFile), true) ?? [];
    ok(['challenge' => $challenge, 'credentials' => array_column(array_filter($stored, fn($c)=>$c['userId']===$userId), 'credentialId')]);
}

// ─── WebAuthn: register ─────────────────────────────────────
if ($action === 'webauthn-register') {
    if (empty($_SESSION['auth'])) fail('לא מחובר');
    $credId = $body['credentialId'] ?? '';
    if (!$credId) fail('חסר credential');
    $credFile = __DIR__ . '/data/webauthn_creds.json';
    $stored = file_exists($credFile) ? json_decode(file_get_contents($credFile), true) : [];
    $stored[] = ['userId' => $_SESSION['auth']['id'], 'credentialId' => $credId, 'name' => $_SESSION['auth']['name']];
    file_put_contents($credFile, json_encode($stored));
    ok();
}

// ─── WebAuthn: verify ───────────────────────────────────────
if ($action === 'webauthn-verify') {
    $credId = $body['credentialId'] ?? '';
    $credFile = __DIR__ . '/data/webauthn_creds.json';
    if (!file_exists($credFile)) fail('לא נמצא');
    $stored = json_decode(file_get_contents($credFile), true) ?? [];
    foreach ($stored as $cred) {
        if ($cred['credentialId'] === $credId) {
            foreach ($users as $user) {
                if ($user['id'] === $cred['userId']) {
                    setSession($user);
                    ok(['name' => $user['name'], 'avatar' => $user['avatar']]);
                }
            }
        }
    }
    fail('לא מורשה');
}

fail('פעולה לא מוכרת');

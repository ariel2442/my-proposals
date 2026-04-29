<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

if (empty($_SESSION['auth']) || ($_SESSION['auth']['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

function apiOk(array $data = []): void { echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE); exit; }
function apiFail(string $msg, int $code = 400): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

function stripPassword(array $u): array { unset($u['password_sha256']); return $u; }

// ─── list ──────────────────────────────────────────────────────
if ($action === 'list') {
    apiOk(['users' => array_map('stripPassword', getUsersList())]);
}

// ─── add ───────────────────────────────────────────────────────
if ($action === 'add') {
    $name     = trim($body['name']     ?? '');
    $username = trim($body['username'] ?? '');
    $email    = strtolower(trim($body['email'] ?? ''));
    $role     = in_array($body['role'] ?? '', ['admin', 'agent']) ? $body['role'] : 'agent';
    $password = trim($body['password'] ?? '');

    if (!$name || !$username || !$email || !$password) apiFail('יש למלא את כל השדות');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     apiFail('כתובת מייל לא תקינה');
    if (strlen($password) < 6)                          apiFail('הסיסמה חייבת להכיל לפחות 6 תווים');
    if (!preg_match('/^[a-z0-9_\-\.]+$/i', $username)) apiFail('שם משתמש יכול להכיל רק אותיות, מספרים וקו תחתון');

    $users = getUsersList();
    foreach ($users as $u) {
        if (strtolower($u['username']) === strtolower($username)) apiFail('שם המשתמש כבר קיים');
        if (strtolower($u['email'])    === $email)                apiFail('כתובת המייל כבר קיימת');
    }

    $id      = (string)(time() . rand(100, 999));
    $avatar  = mb_substr($name, 0, 1, 'UTF-8');
    $newUser = ['id' => $id, 'name' => $name, 'username' => $username, 'email' => $email, 'role' => $role, 'avatar' => $avatar, 'password_sha256' => hash('sha256', $password)];
    $users[] = $newUser;
    saveUsersList($users);
    apiOk(['user' => stripPassword($newUser)]);
}

// ─── edit ──────────────────────────────────────────────────────
if ($action === 'edit') {
    $id    = trim($body['id']    ?? '');
    $name  = trim($body['name']  ?? '');
    $email = strtolower(trim($body['email'] ?? ''));
    $role  = in_array($body['role'] ?? '', ['admin', 'agent']) ? $body['role'] : 'agent';

    if (!$id || !$name || !$email) apiFail('יש למלא את כל השדות');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) apiFail('כתובת מייל לא תקינה');

    $users = getUsersList();
    $found = false;
    foreach ($users as &$u) {
        if ($u['id'] !== $id) continue;
        foreach ($users as $other) {
            if ($other['id'] !== $id && strtolower($other['email']) === $email) apiFail('המייל כבר בשימוש');
        }
        $u['name']   = $name;
        $u['email']  = $email;
        $u['role']   = $role;
        $u['avatar'] = mb_substr($name, 0, 1, 'UTF-8');
        $found = true;
        break;
    }
    if (!$found) apiFail('משתמש לא נמצא');
    saveUsersList($users);
    apiOk();
}

// ─── delete ────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = trim($body['id'] ?? '');
    if (!$id) apiFail('חסר ID');
    if ($id === $_SESSION['auth']['id']) apiFail('לא ניתן למחוק את עצמך');

    $users    = getUsersList();
    $filtered = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
    if (count($filtered) === count($users)) apiFail('משתמש לא נמצא');
    saveUsersList($filtered);
    apiOk();
}

// ─── set-password ──────────────────────────────────────────────
if ($action === 'set-password') {
    $id       = trim($body['id']       ?? '');
    $password = trim($body['password'] ?? '');
    if (!$id || !$password)   apiFail('חסרים פרטים');
    if (strlen($password) < 6) apiFail('הסיסמה חייבת להכיל לפחות 6 תווים');

    $users = getUsersList();
    if (!array_filter($users, fn($u) => $u['id'] === $id)) apiFail('משתמש לא נמצא');
    savePasswordHash($id, hash('sha256', $password));
    apiOk();
}

apiFail('פעולה לא מוכרת');

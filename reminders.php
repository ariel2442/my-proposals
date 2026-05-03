<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$s     = getSettings();
$token = $_GET['token'] ?? '';

// Allow if valid cron token OR authenticated session
if (!($token && $token === ($s['cronToken'] ?? '')) && empty($_SESSION['auth'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$type   = $_GET['type'] ?? 'all';
$result = runReminders($type, $s);
echo json_encode(array_merge(['ok' => true], $result), JSON_UNESCAPED_UNICODE);

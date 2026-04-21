<?php
// Token סודי — שנה אותו וזכור אותו, תצטרך אותו ב-Make
define('SECRET_TOKEN', 'CHANGE_THIS_SECRET_TOKEN');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? '';
if ($token !== SECRET_TOKEN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data || empty($data['id'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id']);
    exit;
}

$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['id']);
$dir = __DIR__ . '/data/';
$file = $dir . $id . '.json';

if (!is_dir($dir)) mkdir($dir, 0755, true);

if (file_put_contents($file, $body) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save file']);
    exit;
}

echo json_encode(['ok' => true, 'id' => $id]);

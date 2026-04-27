<?php
// External webhook endpoint (used by Make/n8n). Secured by SECRET_TOKEN.
define('SECRET_TOKEN', '4ae9a09533485edf7a5e8f6bd11862a5');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }

$token = $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? '';
if ($token !== SECRET_TOKEN) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$body = file_get_contents('php://input');
$data = json_decode($body, true);
if (!$data || empty($data['id'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Missing id']); exit; }

$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['id']);

require_once __DIR__ . '/config.php';

if (USE_DB) {
    require_once __DIR__ . '/db.php';
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id FROM proposals WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    $userId   = $existing ? $existing['user_id'] : '1';
    $pdo->prepare('INSERT INTO proposals (id, user_id, data, status, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), status=VALUES(status), updated_at=VALUES(updated_at)')
        ->execute([$id, $userId, $body, $data['status'] ?? 'draft', time() * 1000]);
} else {
    $dir  = __DIR__ . '/data/';
    $file = $dir . $id . '.json';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if (file_put_contents($file, $body) === false) {
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Could not save file']); exit;
    }
}

echo json_encode(['ok' => true, 'id' => $id]);

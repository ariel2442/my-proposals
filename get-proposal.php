<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['id'] ?? '');
if (!$id) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }

require_once __DIR__ . '/config.php';

if (USE_DB) {
    require_once __DIR__ . '/db.php';
    $stmt = db()->prepare('SELECT data FROM proposals WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }
    echo $row['data'];
} else {
    $file = __DIR__ . '/data/' . $id . '.json';
    if (!file_exists($file)) { http_response_code(404); echo json_encode(['error' => 'not_found']); exit; }
    echo file_get_contents($file);
}

<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// must be logged in
if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$userId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_SESSION['auth']['id']);
$dir    = __DIR__ . '/data/';
$file   = $dir . 'proposals_' . $userId . '.json';

if (!is_dir($dir)) mkdir($dir, 0755, true);

// GET — return proposals
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists($file)) { echo json_encode(['ok' => true, 'proposals' => [], 'library' => null]); exit; }
    echo file_get_contents($file);
    exit;
}

// POST — save proposals
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!$data) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Bad JSON']); exit; }
    $data['ok'] = true;
    $data['updatedAt'] = time() * 1000;
    if (file_put_contents($file, json_encode($data)) === false) {
        http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Write failed']); exit;
    }
    // שמור קובץ נפרד לכל הצעה (לדף /p/)
    if (!empty($data['proposals']) && is_array($data['proposals'])) {
        foreach ($data['proposals'] as $proposal) {
            if (!empty($proposal['id'])) {
                $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $proposal['id']);
                file_put_contents($dir . $pid . '.json', json_encode($proposal));
            }
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

// DELETE — remove single proposal file (optional cleanup)
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents('php://input'), $params);
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $params['id'] ?? '');
    if ($pid && file_exists($dir . $pid . '.json')) unlink($dir . $pid . '.json');
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

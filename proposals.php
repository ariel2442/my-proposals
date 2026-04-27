<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

if (empty($_SESSION['auth'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
$userId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_SESSION['auth']['id']);
$dir    = __DIR__ . '/data/';
$file   = $dir . 'proposals_' . $userId . '.json';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$statusRank = ['draft'=>0,'sent'=>1,'opened'=>2,'signed'=>3];

// ══════════════════════════════════════════════════════════════
// GET
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $pdo = db();

        $stmt = $pdo->prepare('SELECT proposal_id FROM deleted_proposals WHERE user_id = ?');
        $stmt->execute([$userId]);
        $deletedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare('SELECT data FROM proposals WHERE user_id = ? ORDER BY updated_at DESC');
        $stmt->execute([$userId]);
        $proposals = [];
        while ($row = $stmt->fetch()) {
            $p = json_decode($row['data'], true);
            if ($p && !in_array($p['id'], $deletedIds)) $proposals[] = $p;
        }

        $stmt = $pdo->prepare('SELECT settings, library FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $sr = $stmt->fetch();
        $settings = $sr && $sr['settings'] ? json_decode($sr['settings'], true) : null;
        $library  = $sr && $sr['library']  ? json_decode($sr['library'],  true) : null;

        echo json_encode(['ok'=>true,'proposals'=>$proposals,'settings'=>$settings,'library'=>$library,'deletedIds'=>array_values($deletedIds),'updatedAt'=>time()*1000]);
        exit;
    }

    // file-based
    if (!file_exists($file)) { echo json_encode(['ok'=>true,'proposals'=>[],'library'=>null]); exit; }
    echo file_get_contents($file);
    exit;
}

// ══════════════════════════════════════════════════════════════
// POST
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!$data) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad JSON']); exit; }

    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $pdo = db();

        // deletedIds
        $newDeleted = $data['deletedIds'] ?? [];
        if ($newDeleted) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO deleted_proposals (user_id, proposal_id) VALUES (?,?)');
            foreach ($newDeleted as $pid) {
                $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pid);
                if ($clean) $stmt->execute([$userId, $clean]);
            }
        }

        // proposals
        if (!empty($data['proposals']) && is_array($data['proposals'])) {
            $selStmt = $pdo->prepare('SELECT data FROM proposals WHERE id = ?');
            $upStmt  = $pdo->prepare('INSERT INTO proposals (id, user_id, data, status, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), status=VALUES(status), updated_at=VALUES(updated_at)');
            $now = time() * 1000;
            foreach ($data['proposals'] as $p) {
                if (empty($p['id'])) continue;
                $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $p['id']);
                $selStmt->execute([$pid]);
                $existing = $selStmt->fetch();
                if ($existing) {
                    $ex = json_decode($existing['data'], true) ?? [];
                    $inRank = $statusRank[$p['status'] ?? 'draft'] ?? 0;
                    $exRank = $statusRank[$ex['status'] ?? 'draft'] ?? 0;
                    if ($exRank > $inRank) {
                        $p['status'] = $ex['status'];
                        foreach (['openedAt','signedAt','signerName','paymentMethod'] as $f) {
                            if (!empty($ex[$f])) $p[$f] = $ex[$f];
                        }
                    }
                }
                $upStmt->execute([$pid, $userId, json_encode($p), $p['status'] ?? 'draft', $now]);
            }
        }

        // settings / library
        if (!empty($data['settings']) || !empty($data['library'])) {
            $chk = $pdo->prepare('SELECT user_id FROM user_settings WHERE user_id = ?');
            $chk->execute([$userId]);
            if ($chk->fetch()) {
                $parts = []; $args = [];
                if (!empty($data['settings'])) { $parts[] = 'settings=?'; $args[] = json_encode($data['settings']); }
                if (!empty($data['library']))  { $parts[] = 'library=?';  $args[] = json_encode($data['library']); }
                $args[] = $userId;
                $pdo->prepare('UPDATE user_settings SET '.implode(',', $parts).' WHERE user_id=?')->execute($args);
            } else {
                $pdo->prepare('INSERT INTO user_settings (user_id, settings, library) VALUES (?,?,?)')->execute([
                    $userId,
                    !empty($data['settings']) ? json_encode($data['settings']) : null,
                    !empty($data['library'])  ? json_encode($data['library'])  : null,
                ]);
            }
        }

        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── file-based ──────────────────────────────────────────────
    if (file_exists($file)) {
        $existing = json_decode(file_get_contents($file), true) ?? [];
        if (empty($data['settings']) && !empty($existing['settings'])) {
            $data['settings'] = $existing['settings'];
        }
        $existingDeleted = $existing['deletedIds'] ?? [];
        $newDeleted = $data['deletedIds'] ?? [];
        $data['deletedIds'] = array_values(array_unique(array_merge($existingDeleted, $newDeleted)));
        if (!empty($data['deletedIds']) && !empty($data['proposals'])) {
            $data['proposals'] = array_values(array_filter($data['proposals'], function($p) use ($data) {
                return !in_array($p['id'], $data['deletedIds']);
            }));
        }
        $existingById = [];
        foreach ($existing['proposals'] ?? [] as $p) $existingById[$p['id']] = $p;
        foreach ($data['proposals'] as &$p) {
            $eid = $p['id'] ?? '';
            if (!isset($existingById[$eid])) continue;
            $ex = $existingById[$eid];
            $inRank = $statusRank[$p['status'] ?? 'draft'] ?? 0;
            $exRank = $statusRank[$ex['status'] ?? 'draft'] ?? 0;
            if ($exRank > $inRank) {
                $p['status'] = $ex['status'];
                foreach (['openedAt','signedAt','signerName','paymentMethod'] as $field) {
                    if (!empty($ex[$field])) $p[$field] = $ex[$field];
                }
            }
        }
        unset($p);
    }

    $data['ok'] = true;
    $data['updatedAt'] = time() * 1000;
    if (file_put_contents($file, json_encode($data)) === false) {
        http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Write failed']); exit;
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
    echo json_encode(['ok'=>true]);
    exit;
}

// ══════════════════════════════════════════════════════════════
// DELETE
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents('php://input'), $params);
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $params['id'] ?? '');
    if ($pid) {
        if (USE_DB) {
            require_once __DIR__ . '/db.php';
            db()->prepare('INSERT IGNORE INTO deleted_proposals (user_id, proposal_id) VALUES (?,?)')->execute([$userId, $pid]);
        } else {
            if (file_exists($dir . $pid . '.json')) unlink($dir . $pid . '.json');
        }
    }
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok'=>false,'error'=>'Method not allowed']);

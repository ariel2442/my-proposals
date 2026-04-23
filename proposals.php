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

require_once __DIR__ . '/db.php';
$userId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_SESSION['auth']['id']);

// ── GET — return proposals ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = db();

    // deleted ids for this user
    $stmt = $pdo->prepare('SELECT proposal_id FROM deleted_proposals WHERE user_id = ?');
    $stmt->execute([$userId]);
    $deletedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // proposals
    $stmt = $pdo->prepare('SELECT data FROM proposals WHERE user_id = ? ORDER BY updated_at DESC');
    $stmt->execute([$userId]);
    $proposals = [];
    while ($row = $stmt->fetch()) {
        $p = json_decode($row['data'], true);
        if ($p && !in_array($p['id'], $deletedIds)) {
            $proposals[] = $p;
        }
    }

    // settings / library
    $stmt = $pdo->prepare('SELECT settings, library FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $settingsRow = $stmt->fetch();
    $settings = $settingsRow && $settingsRow['settings'] ? json_decode($settingsRow['settings'], true) : null;
    $library  = $settingsRow && $settingsRow['library']  ? json_decode($settingsRow['library'],  true) : null;

    echo json_encode([
        'ok'         => true,
        'proposals'  => $proposals,
        'settings'   => $settings,
        'library'    => $library,
        'deletedIds' => array_values($deletedIds),
        'updatedAt'  => time() * 1000,
    ]);
    exit;
}

// ── POST — save proposals ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body, true);
    if (!$data) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Bad JSON']); exit; }

    $pdo = db();
    $statusRank = ['draft'=>0, 'sent'=>1, 'opened'=>2, 'signed'=>3];

    // ── deletedIds ──
    $newDeleted = $data['deletedIds'] ?? [];
    if ($newDeleted) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO deleted_proposals (user_id, proposal_id) VALUES (?,?)');
        foreach ($newDeleted as $pid) {
            $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $pid);
            if ($clean) $stmt->execute([$userId, $clean]);
        }
    }

    // ── proposals ──
    if (!empty($data['proposals']) && is_array($data['proposals'])) {
        $selectStmt = $pdo->prepare('SELECT data FROM proposals WHERE id = ?');
        $upsertStmt = $pdo->prepare('
            INSERT INTO proposals (id, user_id, data, status, updated_at)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE data=VALUES(data), status=VALUES(status), updated_at=VALUES(updated_at)
        ');
        $now = time() * 1000;

        foreach ($data['proposals'] as $p) {
            if (empty($p['id'])) continue;
            $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $p['id']);

            // merge: preserve advanced status from DB
            $selectStmt->execute([$pid]);
            $existing = $selectStmt->fetch();
            if ($existing) {
                $ex     = json_decode($existing['data'], true) ?? [];
                $inRank = $statusRank[$p['status']  ?? 'draft'] ?? 0;
                $exRank = $statusRank[$ex['status'] ?? 'draft'] ?? 0;
                if ($exRank > $inRank) {
                    $p['status'] = $ex['status'];
                    foreach (['openedAt','signedAt','signerName','paymentMethod'] as $f) {
                        if (!empty($ex[$f])) $p[$f] = $ex[$f];
                    }
                }
            }

            $upsertStmt->execute([$pid, $userId, json_encode($p), $p['status'] ?? 'draft', $now]);
        }
    }

    // ── settings / library ──
    if (!empty($data['settings']) || !empty($data['library'])) {
        $stmt = $pdo->prepare('SELECT user_id FROM user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $parts = [];
            $args  = [];
            if (!empty($data['settings'])) { $parts[] = 'settings=?'; $args[] = json_encode($data['settings']); }
            if (!empty($data['library']))  { $parts[] = 'library=?';  $args[] = json_encode($data['library']); }
            $args[] = $userId;
            $pdo->prepare('UPDATE user_settings SET ' . implode(',', $parts) . ' WHERE user_id=?')->execute($args);
        } else {
            $pdo->prepare('INSERT INTO user_settings (user_id, settings, library) VALUES (?,?,?)')->execute([
                $userId,
                !empty($data['settings']) ? json_encode($data['settings']) : null,
                !empty($data['library'])  ? json_encode($data['library'])  : null,
            ]);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE — soft-delete a proposal ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    parse_str(file_get_contents('php://input'), $params);
    $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $params['id'] ?? '');
    if ($pid) {
        $pdo = db();
        $pdo->prepare('INSERT IGNORE INTO deleted_proposals (user_id, proposal_id) VALUES (?,?)')->execute([$userId, $pid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

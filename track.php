<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

require_once __DIR__ . '/config.php';

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$id     = preg_replace('/[^a-zA-Z0-9_\-]/', '', $body['id'] ?? '');

if (!$id || !$action) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing params']); exit; }

$dir  = __DIR__ . '/data/';
$file = $dir . $id . '.json';

// ── helpers ────────────────────────────────────────────────────
function loadProposal($id, $file) {
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare('SELECT data, user_id FROM proposals WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
    if (!file_exists($file)) return null;
    $p = json_decode(file_get_contents($file), true) ?? [];
    return ['data' => file_get_contents($file), 'user_id' => null, '_decoded' => $p];
}

function saveProposal($id, $userId, $proposal, $status) {
    if (USE_DB) {
        db()->prepare('UPDATE proposals SET data=?, status=?, updated_at=? WHERE id=?')
            ->execute([json_encode($proposal), $status, time()*1000, $id]);
    } else {
        $dir  = __DIR__ . '/data/';
        $file = $dir . $id . '.json';
        file_put_contents($file, json_encode($proposal));
        // update main proposals file too
        updateMainFile($dir, $id, array_filter([
            'status'        => $proposal['status']        ?? null,
            'openedAt'      => $proposal['openedAt']      ?? null,
            'signedAt'      => $proposal['signedAt']      ?? null,
            'signerName'    => $proposal['signerName']    ?? null,
            'paymentMethod' => $proposal['paymentMethod'] ?? null,
        ]));
    }
}

function updateMainFile($dir, $id, $changes) {
    foreach (glob($dir . 'proposals_*.json') as $pFile) {
        $data = json_decode(file_get_contents($pFile), true) ?? [];
        if (empty($data['proposals'])) continue;
        $found = false;
        foreach ($data['proposals'] as &$p) {
            if ($p['id'] === $id) {
                foreach ($changes as $k => $v) if($v!==null) $p[$k] = $v;
                $found = true;
                break;
            }
        }
        unset($p);
        if ($found) { file_put_contents($pFile, json_encode($data)); return; }
    }
}

// ── load proposal ──────────────────────────────────────────────
$row = loadProposal($id, $file);
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

$proposal = USE_DB
    ? (json_decode($row['data'], true) ?? [])
    : ($row['_decoded'] ?? []);
$userId = $row['user_id'] ?? null;

// ─── open ──────────────────────────────────────────────────────
if ($action === 'open') {
    if (empty($proposal['openedAt'])) {
        $proposal['openedAt'] = time() * 1000;
        $proposal['status']   = 'opened';
        saveProposal($id, $userId, $proposal, 'opened');
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// ─── sign ──────────────────────────────────────────────────────
if ($action === 'sign') {
    $signerName    = $body['signerName']    ?? '';
    $paymentMethod = $body['paymentMethod'] ?? '';
    $signature     = $body['signature']     ?? '';
    $signedAt      = time() * 1000;

    $proposal['status']        = 'signed';
    $proposal['signedAt']      = $signedAt;
    $proposal['signerName']    = $signerName;
    $proposal['paymentMethod'] = $paymentMethod;
    saveProposal($id, $userId, $proposal, 'signed');

    // שמור הסכם חתום
    $signed = [
        'id'            => $id,
        'proposalNum'   => $proposal['proposalNum'] ?? '',
        'clientName'    => $proposal['clientName']  ?? '',
        'clientPhone'   => $proposal['clientPhone'] ?? '',
        'total'         => $proposal['total']       ?? 0,
        'signerName'    => $signerName,
        'signedAt'      => $signedAt,
        'paymentMethod' => $paymentMethod,
        'signature'     => $signature,
    ];

    if (USE_DB) {
        db()->prepare('INSERT INTO signed_agreements (proposal_id, data, signed_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), signed_at=VALUES(signed_at)')
            ->execute([$id, json_encode($signed), $signedAt]);
    } else {
        file_put_contents($dir . $id . '_signed.json', json_encode($signed));
    }

    // מייל לאדמין
    $users      = require __DIR__ . '/users.php';
    $adminEmail = $users[0]['email'] ?? '';
    if ($adminEmail) {
        $proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $viewUrl    = $proto . '://' . $_SERVER['HTTP_HOST'] . '/price/view-signed.php?id=' . $id;
        $clientName = htmlspecialchars($proposal['clientName'] ?? '');
        $total      = number_format($proposal['total'] ?? 0, 0, '.', ',');
        $date       = date('d/m/Y H:i');
        $pay        = $paymentMethod === 'credit' ? 'אשראי' : 'העברה בנקאית';
        $subject    = '=?UTF-8?B?' . base64_encode("הצעת מחיר נחתמה — $clientName") . '?=';
        $message    = "שלום,\n\nלקוח חתם על הצעת מחיר!\n\nלקוח: $clientName\nסכום: ₪$total\nתאריך: $date\nתשלום: $pay\nחתום על ידי: $signerName\n\nצפייה בהסכם:\n$viewUrl\n\nאריאל קוטס";
        $headers    = "From: Quotes <noreply@ariel-azulay.co.il>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
        @mail($adminEmail, $subject, $message, $headers);
    }

    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok'=>false,'error'=>'unknown action']);

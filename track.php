<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

require_once __DIR__ . '/db.php';

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$id     = preg_replace('/[^a-zA-Z0-9_\-]/', '', $body['id'] ?? '');

if (!$id || !$action) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing params']); exit; }

$pdo  = db();
$stmt = $pdo->prepare('SELECT data, user_id FROM proposals WHERE id = ?');
$stmt->execute([$id]);
$row  = $stmt->fetch();
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

$proposal = json_decode($row['data'], true) ?? [];
$userId   = $row['user_id'];

// ─── open ──────────────────────────────────────────────────────
if ($action === 'open') {
    if (empty($proposal['openedAt'])) {
        $proposal['openedAt'] = time() * 1000;
        $proposal['status']   = 'opened';
        $pdo->prepare('UPDATE proposals SET data=?, status=\'opened\', updated_at=? WHERE id=?')
            ->execute([json_encode($proposal), time() * 1000, $id]);
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

    // update proposal (without signature image)
    $pdo->prepare('UPDATE proposals SET data=?, status=\'signed\', updated_at=? WHERE id=?')
        ->execute([json_encode($proposal), $signedAt, $id]);

    // save signed agreement (includes signature PNG)
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
    $pdo->prepare('INSERT INTO signed_agreements (proposal_id, data, signed_at) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE data=VALUES(data), signed_at=VALUES(signed_at)')
        ->execute([$id, json_encode($signed), $signedAt]);

    // email admin
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

<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$id     = preg_replace('/[^a-zA-Z0-9_\-]/', '', $body['id'] ?? '');

if (!$id || !$action) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing params']); exit; }

$dir  = __DIR__ . '/data/';
$file = $dir . $id . '.json';
if (!file_exists($file)) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

$proposal = json_decode(file_get_contents($file), true) ?? [];

// עדכן קובץ proposals_*.json כך שהניהול יראה את השינוי בסנכרון
function updateMainFile($dir, $id, $changes) {
    foreach (glob($dir . 'proposals_*.json') as $pFile) {
        $data = json_decode(file_get_contents($pFile), true) ?? [];
        if (empty($data['proposals'])) continue;
        $found = false;
        foreach ($data['proposals'] as &$p) {
            if ($p['id'] === $id) {
                foreach ($changes as $k => $v) $p[$k] = $v;
                $found = true;
                break;
            }
        }
        unset($p);
        if ($found) { file_put_contents($pFile, json_encode($data)); return; }
    }
}

// ─── open ──────────────────────────────────────────────────
if ($action === 'open') {
    if (empty($proposal['openedAt'])) {
        $changes = ['openedAt' => time() * 1000, 'status' => 'opened'];
        foreach ($changes as $k => $v) $proposal[$k] = $v;
        file_put_contents($file, json_encode($proposal));
        updateMainFile($dir, $id, $changes);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// ─── sign ──────────────────────────────────────────────────
if ($action === 'sign') {
    $signerName    = $body['signerName']    ?? '';
    $paymentMethod = $body['paymentMethod'] ?? '';
    $signature     = $body['signature']     ?? '';
    $signedAt      = time() * 1000;

    // עדכן קובץ ההצעה (ללא תמונת החתימה — גדולה מדי)
    $changes = ['status'=>'signed','signedAt'=>$signedAt,'signerName'=>$signerName,'paymentMethod'=>$paymentMethod];
    foreach ($changes as $k => $v) $proposal[$k] = $v;
    file_put_contents($file, json_encode($proposal));

    // שמור הסכם חתום נפרד (כולל תמונת חתימה)
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
    file_put_contents($dir . $id . '_signed.json', json_encode($signed));

    // עדכן קובץ הניהול
    updateMainFile($dir, $id, $changes);

    // מייל גיבוי לאדמין
    $users       = require __DIR__ . '/users.php';
    $adminEmail  = $users[0]['email'] ?? '';
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

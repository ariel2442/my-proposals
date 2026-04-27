<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? 'view';
$id     = $body['id'] ?? '';
if (!$id) { echo json_encode(['ok' => false]); exit; }

$p = readProposal($id);
if (!$p) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'not found']); exit; }

$now = date('c');
$ip  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua  = $_SERVER['HTTP_USER_AGENT'] ?? '';

// ─── view / open ───────────────────────────────────────────────
if ($action === 'view' || $action === 'open') {
    $duration = max(0, intval($body['durationSeconds'] ?? 0));

    $view  = ['viewedAt' => $now, 'ip' => $ip, 'userAgent' => $ua, 'durationSeconds' => $duration];
    $p['views']            = array_slice(array_merge([$view], $p['views'] ?? []), 0, 50);
    $p['totalViews']       = ($p['totalViews']       ?? 0) + 1;
    $p['totalViewSeconds'] = ($p['totalViewSeconds'] ?? 0) + $duration;
    if (empty($p['firstViewedAt'])) $p['firstViewedAt'] = $now;
    $p['lastViewedAt'] = $now;

    if (in_array($p['status'] ?? 'draft', ['draft', 'sent'], true)) {
        $p['status'] = 'viewed';
    }

    writeProposal($p);

    // ─── WhatsApp to sales rep (throttle: first view OR > 3 h) ────
    $lastNotified = $p['lastNotifiedAt'] ?? null;
    $shouldNotify = !$lastNotified || (time() - strtotime($lastNotified)) > 3 * 3600;

    if ($shouldNotify && !empty($p['salesRepPhone'])) {
        $clientName = $p['clientName'] ?? 'לקוח';
        $s          = getSettings();
        $baseUrl    = rtrim($s['baseUrl'] ?? '', '/');
        $link       = $baseUrl ? $baseUrl . '/p/?id=' . $p['id'] : '';
        $propNum    = $p['proposalNum'] ?? '';

        $msg = $lastNotified
            ? "👀 {$clientName} חזר/ה לראות את ההצעה!\n\n📄 הצעה #{$propNum}"
                . ($link ? "\n🔗 {$link}" : '') . "\n\nגלוי עניין מחודש 🔥"
            : "🎉 {$clientName} פתח/ה את הצעת המחיר!\n\n📄 הצעה #{$propNum}"
                . ($link ? "\n🔗 {$link}" : '') . "\n\nזה הזמן לסגור 💪";

        if (sendWhatsapp($p['salesRepPhone'], $msg)) {
            $p['lastNotifiedAt'] = $now;
            writeProposal($p);
        }
    }

    echo json_encode(['ok' => true, 'status' => $p['status'], 'totalViews' => $p['totalViews']]);
    exit;
}

// ─── sign ──────────────────────────────────────────────────────
if ($action === 'sign') {
    $signerName    = $body['signerName']    ?? '';
    $paymentMethod = $body['paymentMethod'] ?? '';
    $signature     = $body['signature']     ?? '';
    $signedAt      = time() * 1000;

    $p['status']        = 'signed';
    $p['signedAt']      = $signedAt;
    $p['signerName']    = $signerName;
    $p['paymentMethod'] = $paymentMethod;
    writeProposal($p);

    // שמור הסכם חתום
    $signed = [
        'id'            => $id,
        'proposalNum'   => $p['proposalNum'] ?? '',
        'clientName'    => $p['clientName']  ?? '',
        'clientPhone'   => $p['clientPhone'] ?? '',
        'total'         => $p['total']       ?? 0,
        'signerName'    => $signerName,
        'signedAt'      => $signedAt,
        'paymentMethod' => $paymentMethod,
        'signature'     => $signature,
    ];
    file_put_contents(DATA_DIR . safeId($id) . '_signed.json', json_encode($signed, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // מייל לאדמין
    $usersFile  = __DIR__ . '/users.php';
    $users      = file_exists($usersFile) ? (require $usersFile) : [];
    $adminEmail = $users[0]['email'] ?? '';
    if ($adminEmail) {
        $proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $viewUrl    = $proto . '://' . $_SERVER['HTTP_HOST'] . '/price/view-signed.php?id=' . $id;
        $clientName = htmlspecialchars($p['clientName'] ?? '');
        $total      = number_format($p['total'] ?? 0, 0, '.', ',');
        $date       = date('d/m/Y H:i');
        $pay        = $paymentMethod === 'credit' ? 'אשראי' : 'העברה בנקאית';
        $subject    = '=?UTF-8?B?' . base64_encode("הצעת מחיר נחתמה — $clientName") . '?=';
        $message    = "שלום,\n\nלקוח חתם על הצעת מחיר!\n\nלקוח: $clientName\nסכום: ₪$total\nתאריך: $date\nתשלום: $pay\nחתום על ידי: $signerName\n\nצפייה בהסכם:\n$viewUrl\n\nאריאל קוטס";
        $headers    = "From: Quotes <noreply@ariel-azulay.co.il>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
        @mail($adminEmail, $subject, $message, $headers);
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action']);

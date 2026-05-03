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
    $s = getSettings();

    if ($shouldNotify && !empty($p['salesRepPhone']) && ($s['autoRepView'] ?? true)) {
        $baseUrl = rtrim($s['baseUrl'] ?? '', '/');
        $link    = $baseUrl ? $baseUrl . '/p/?id=' . $p['id'] : '';
        $vars    = ['name' => $p['clientName'] ?? 'לקוח', 'num' => $p['proposalNum'] ?? '', 'link' => $link];

        $tpl = $lastNotified
            ? ($s['msgViewReturn'] ?? "👀 {name} חזר/ה לראות את ההצעה!\n\n📄 הצעה #{num}\n🔗 {link}\n\nגלוי עניין מחודש 🔥")
            : ($s['msgViewFirst']  ?? "🎉 {name} פתח/ה את הצעת המחיר!\n\n📄 הצעה #{num}\n🔗 {link}\n\nזה הזמן לסגור 💪");

        if (sendWhatsapp($p['salesRepPhone'], fillTemplate($tpl, $vars))) {
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

    // ─── WhatsApp ללקוח + לינק GROW (ישיר, ללא Make) ─────────────
    $s3           = getSettings();
    $clientPhone3 = $p['clientPhone'] ?? '';
    $clientName3  = $p['clientName']  ?? 'לקוח';
    $total3       = number_format($p['total'] ?? 0, 0, '.', ',');

    if ($clientPhone3 && ($s3['autoClientPayment'] ?? true)) {
        $clientVars = ['name' => $clientName3, 'num' => $p['proposalNum'] ?? '', 'total' => $total3];
        if ($paymentMethod === 'credit') {
            // proposal-level link takes priority; fall back to GROW API
            $payLink = !empty($p['paymentLink'])
                ? $p['paymentLink']
                : createGrowPaymentLink($p);
            if ($payLink) {
                $tpl = $s3['msgSignCredit'] ?? "שלום {name} 👋\n\nתודה על חתימתך!\n\nלתשלום לחץ על הקישור:\n🔗 {payLink}\n\nסכום לתשלום: ₪{total}";
                sendWhatsapp($clientPhone3, fillTemplate($tpl, $clientVars + ['payLink' => $payLink]));
            } else {
                $tpl = $s3['msgSignNoLink'] ?? "שלום {name} 👋\n\nתודה על חתימתך! ✍️\n\nלינק התשלום יישלח אליך בקרוב.";
                sendWhatsapp($clientPhone3, fillTemplate($tpl, $clientVars));
            }
        } else {
            $bank = $p['biz']['bank'] ?? ($s3['bizBank'] ?? '');
            $tpl  = $s3['msgSignBank'] ?? "שלום {name} 👋\n\nתודה על חתימתך! ✍️\n\nלהעברה בנקאית:\n{bank}\n\nסכום: ₪{total}\nנא לציין בהעברה: הצעה #{num}";
            sendWhatsapp($clientPhone3, fillTemplate($tpl, $clientVars + ['bank' => $bank]));
        }
    }

    // ─── WhatsApp לנציג מכירות על חתימה ──────────────────────────
    $s2       = $s3;
    $repPhone = $p['salesRepPhone'] ?? $s2['salesRepPhone'] ?? '';
    $propNum2 = $p['proposalNum']  ?? '';
    $total2   = number_format($p['total'] ?? 0, 0, '.', ',');
    $payLabel = $paymentMethod === 'credit' ? 'אשראי 💳' : 'העברה בנקאית 🏦';
    $baseUrl2 = rtrim($s2['baseUrl'] ?? '', '/');
    $viewUrl2 = $baseUrl2 ? $baseUrl2 . '/price/view-signed.php?id=' . $id : '';

    if ($repPhone && ($s2['autoRepSign'] ?? true)) {
        $tpl   = $s2['msgSignRep'] ?? "✅ {name} חתמ/ה על הצעת המחיר!\n\n📄 הצעה #{num}\n💰 סכום: ₪{total}\n💳 תשלום: {payMethod}\n✍️ חתם/ה: {signerName}\n\n🔗 {link}";
        $waMsg = fillTemplate($tpl, [
            'name'       => $p['clientName'] ?? 'לקוח',
            'num'        => $propNum2,
            'total'      => $total2,
            'payMethod'  => $payLabel,
            'signerName' => $signerName,
            'link'       => $viewUrl2,
        ]);
        sendWhatsapp($repPhone, $waMsg);
    }

    // ─── העלאה לגוגל דרייב ────────────────────────────────────
    if ($s2['autoDrive'] ?? true) {
        $driveText = implode("\n", [
            "הסכם חתום — הצעה #{$propNum2}",
            str_repeat('─', 40),
            "לקוח:        {$clientName2}",
            "טלפון:       " . ($p['clientPhone'] ?? ''),
            "סכום:        ₪{$total2}",
            "תשלום:       " . ($paymentMethod === 'credit' ? 'אשראי' : 'העברה בנקאית'),
            "חתם/ה:       {$signerName}",
            "תאריך חתימה: " . date('d/m/Y H:i', intval($signedAt / 1000)),
            "",
            "פרטי עסק:    " . ($p['biz']['name'] ?? ''),
            "מספר הצעה:   {$propNum2}",
            "ID:          {$id}",
        ]);
        $driveFilename = "הצעה_{$propNum2}_{$clientName2}_" . date('Y-m-d') . '.txt';
        uploadToDrive($driveFilename, $driveText, 'text/plain');
    }

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

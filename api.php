<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── list proposals ────────────────────────────────────────────
if ($action === 'list') {
    requireAuth();
    $idx       = getProposalIndex();
    $proposals = [];
    $now       = time();
    foreach ($idx['ids'] as $id) {
        $p = readProposal($id);
        if (!$p) continue;
        // auto-expire
        if (!empty($p['expiresAt']) && strtotime($p['expiresAt']) < $now
            && !in_array($p['status'] ?? '', ['signed', 'expired'], true)) {
            $p['status'] = 'expired';
            writeProposal($p);
        }
        $proposals[] = $p;
    }
    jsonOk(['proposals' => $proposals]);
}

// ─── get single proposal (public — used by client page) ────────
if ($action === 'get') {
    $id = $_GET['id'] ?? $body['id'] ?? '';
    if (!$id) jsonFail('חסר ID');
    $p = readProposal($id);
    if (!$p) jsonFail('הצעה לא נמצאה', 404);
    jsonOk(['proposal' => $p]);
}

// ─── save draft ────────────────────────────────────────────────
if ($action === 'save') {
    requireAuth();
    if (empty($body['id'])) jsonFail('חסר ID');
    $existing = readProposal($body['id']);
    $p = $existing
        ? array_merge($existing, $body)
        : array_merge(['createdAt' => date('c'), 'totalViews' => 0, 'totalViewSeconds' => 0, 'views' => []], $body);
    writeProposal($p);
    jsonOk(['proposal' => $p]);
}

// ─── send to client (save + WhatsApp) ─────────────────────────
if ($action === 'send') {
    $user = requireAuth();
    if (empty($body['id'])) jsonFail('חסר ID');
    $s       = getSettings();
    $baseUrl = rtrim($s['baseUrl'] ?? '', '/');

    $p = array_merge(
        ['createdAt' => date('c'), 'totalViews' => 0, 'totalViewSeconds' => 0, 'views' => []],
        $body,
        [
            'status'       => 'sent',
            'sentAt'       => date('c'),
            'salesRepName' => $user['name'],
            'salesRepPhone' => $s['salesRepPhone'] ?? '',
        ]
    );
    writeProposal($p);

    // WhatsApp to client
    if (!empty($p['clientPhone']) && $baseUrl && ($s['autoSendClient'] ?? true)) {
        $link = $baseUrl . '/p/?id=' . $p['id'];
        $tpl  = $s['msgSendClient'] ?? "שלום {name} 👋\n\nהצעת המחיר שלך מוכנה לצפייה:\n🔗 {link}\n\nלכל שאלה — אשמח לעזור!";
        $msg  = fillTemplate($tpl, [
            'name' => $p['clientName'] ?? 'לקוח יקר',
            'num'  => $p['proposalNum'] ?? '',
            'link' => $link,
        ]);
        sendWhatsapp($p['clientPhone'], $msg);
    }

    jsonOk(['proposal' => $p]);
}

// ─── partial update (status, fields) ──────────────────────────
if ($action === 'update') {
    requireAuth();
    $id = $body['id'] ?? '';
    if (!$id) jsonFail('חסר ID');
    $p = readProposal($id);
    if (!$p) jsonFail('הצעה לא נמצאה', 404);
    foreach (['status','sentAt','clientName','clientPhone','clientEmail','expiresAt','notes'] as $k) {
        if (array_key_exists($k, $body)) $p[$k] = $body[$k];
    }
    writeProposal($p);
    jsonOk(['proposal' => $p]);
}

// ─── delete ────────────────────────────────────────────────────
if ($action === 'delete') {
    requireAuth();
    $id = $body['id'] ?? '';
    if (!$id) jsonFail('חסר ID');
    deleteProposalFile($id);
    jsonOk();
}

// ─── next proposal number (atomic counter) ────────────────────
if ($action === 'next-num') {
    requireAuth();
    $file = DATA_DIR . 'proposal_counter.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $num  = max(100, (int)($data['next'] ?? 100));
    file_put_contents($file, json_encode(['next' => $num + 1]), LOCK_EX);
    jsonOk(['num' => $num]);
}

// ─── duplicate ─────────────────────────────────────────────────
if ($action === 'duplicate') {
    requireAuth();
    $id       = $body['id'] ?? '';
    $original = $id ? readProposal($id) : null;
    if (!$original) jsonFail('הצעה לא נמצאה', 404);

    $counterFile = DATA_DIR . 'proposal_counter.json';
    $counterData = file_exists($counterFile) ? json_decode(file_get_contents($counterFile), true) : [];
    $nextNum     = max(100, (int)($counterData['next'] ?? 100));
    file_put_contents($counterFile, json_encode(['next' => $nextNum + 1]), LOCK_EX);

    $copy = array_merge($original, [
        'id'              => 'prop_' . time() . '_' . rand(100, 999),
        'status'          => 'draft',
        'createdAt'       => date('c'),
        'date'            => date('c'),
        'sentAt'          => null,
        'firstViewedAt'   => null,
        'lastViewedAt'    => null,
        'lastNotifiedAt'  => null,
        'totalViews'      => 0,
        'totalViewSeconds'=> 0,
        'views'           => [],
        'clientName'      => ($original['clientName'] ?? '') . ' (העתק)',
        'proposalNum'     => (string)$nextNum,
    ]);
    writeProposal($copy);
    jsonOk(['proposal' => $copy]);
}

// ─── get payment link (public — called by client page) ────────
if ($action === 'get-pay-link') {
    $id = $_GET['id'] ?? $body['id'] ?? '';
    if (!$id) jsonFail('חסר ID');
    $p = readProposal($id);
    if (!$p) jsonFail('הצעה לא נמצאה', 404);
    // use saved payment link first; fall back to webhook
    $url = !empty($p['paymentLink']) ? $p['paymentLink'] : createGrowPaymentLink($p);
    if (!$url) jsonFail('לא הוגדר Webhook URL ליצירת לינק תשלום');
    jsonOk(['url' => $url]);
}

// ─── get signed agreement ──────────────────────────────────────
if ($action === 'get-signed') {
    $id = $_GET['id'] ?? $body['id'] ?? '';
    if (!$id) jsonFail('חסר ID');
    $file = DATA_DIR . safeId($id) . '_signed.json';
    if (!file_exists($file)) jsonFail('לא נמצא', 404);
    $signed = json_decode(file_get_contents($file), true) ?? [];
    jsonOk(['signed' => $signed]);
}

// ─── serve signature image (PNG) — used by Green API sendFileByUrl ─
if ($action === 'get-sig-img') {
    $id   = safeId($_GET['id'] ?? '');
    if (!$id) { http_response_code(400); exit; }
    $file = DATA_DIR . $id . '_signed.json';
    if (!file_exists($file)) { http_response_code(404); exit; }
    $signed = json_decode(file_get_contents($file), true) ?? [];
    $sig    = $signed['signature'] ?? '';
    if (!$sig || !str_starts_with($sig, 'data:image/')) { http_response_code(404); exit; }
    $raw = base64_decode(substr($sig, strpos($sig, ',') + 1));
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($raw));
    header('Cache-Control: public, max-age=86400');
    echo $raw;
    exit;
}

// ─── settings ──────────────────────────────────────────────────
if ($action === 'get-settings') {
    requireAuth();
    jsonOk(['settings' => getSettings()]);
}

if ($action === 'save-settings') {
    requireAuth();
    $allowed = ['bizName','bizEmail','bizPhone','bizBank','baseUrl',
                'termsLanding','termsBrochure','termsSales','termsCRM',
                'greenApiInstance','greenApiToken','salesRepPhone','driveFolderId',
                'growWebhookUrl','signWebhookUrl',
                'autoSendClient','autoRepView','autoRepSign','autoClientPayment','autoDrive',
                'msgSendClient','msgViewFirst','msgViewReturn','msgSignRep',
                'msgSignCredit','msgSignNoLink','msgSignBank',
                'reminderNotOpenEnabled','reminderNotOpenHours',
                'reminderNotSignedEnabled','reminderNotSignedHours',
                'msgReminderNotOpen','msgReminderNotSigned','cronToken'];
    $s = getSettings();
    foreach ($allowed as $k) {
        if (isset($body[$k])) $s[$k] = $body[$k];
    }
    saveSettingsFile($s);
    jsonOk();
}

// ─── run reminders (manual trigger) ──────────────────────────
if ($action === 'run-reminders') {
    requireAuth();
    $type = $body['type'] ?? 'all';
    $s    = getSettings();
    $result = runReminders($type, $s);
    jsonOk($result);
}

// ─── Google Drive: upload service account JSON ────────────────
if ($action === 'drive-upload-sa') {
    requireAuth();
    $content = $body['content'] ?? '';
    if (!$content) jsonFail('חסר תוכן קובץ');
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || empty($parsed['private_key']) || empty($parsed['client_email'])) {
        jsonFail('קובץ לא תקין — ודא שזה Service Account JSON של Google');
    }
    ensureDataDir();
    file_put_contents(DATA_DIR . 'google-service-account.json', $content);
    jsonOk(['email' => $parsed['client_email']]);
}

// ─── Google Drive: check service account status ───────────────
if ($action === 'drive-check-sa') {
    requireAuth();
    $file = DATA_DIR . 'google-service-account.json';
    if (!file_exists($file)) jsonOk(['configured' => false]);
    $sa = json_decode(file_get_contents($file), true) ?? [];
    jsonOk(['configured' => !empty($sa['client_email']), 'email' => $sa['client_email'] ?? '']);
}

// ─── Google Drive: test upload ────────────────────────────────
if ($action === 'drive-test') {
    requireAuth();
    $s = getSettings();
    if (empty($s['driveFolderId'])) jsonFail('חסר Drive Folder ID');
    $file = DATA_DIR . 'google-service-account.json';
    if (!file_exists($file)) jsonFail('קובץ Service Account לא נמצא');
    $result = uploadToDrive('drive-test-' . date('Y-m-d-His') . '.txt', 'בדיקת חיבור ל-Google Drive ✓', 'text/plain');
    if ($result) jsonOk(['fileId' => $result]);
    jsonFail('העלאה נכשלה — בדוק Folder ID ושיתוף התיקיה עם ה-Service Account');
}

jsonFail('פעולה לא מוכרת');

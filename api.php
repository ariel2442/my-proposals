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
    if (!empty($p['clientPhone']) && $baseUrl) {
        $link = $baseUrl . '/p/?id=' . $p['id'];
        $name = $p['clientName'] ?? 'לקוח יקר';
        sendWhatsapp($p['clientPhone'],
            "שלום {$name} 👋\n\nהצעת המחיר שלך מוכנה לצפייה:\n🔗 {$link}\n\nלכל שאלה — אשמח לעזור!"
        );
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

// ─── duplicate ─────────────────────────────────────────────────
if ($action === 'duplicate') {
    requireAuth();
    $id       = $body['id'] ?? '';
    $original = $id ? readProposal($id) : null;
    if (!$original) jsonFail('הצעה לא נמצאה', 404);

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
        'proposalNum'     => date('Y') . '-' . rand(100, 999),
    ]);
    writeProposal($copy);
    jsonOk(['proposal' => $copy]);
}

// ─── settings ──────────────────────────────────────────────────
if ($action === 'get-settings') {
    requireAuth();
    jsonOk(['settings' => getSettings()]);
}

if ($action === 'save-settings') {
    requireAuth();
    $allowed = ['bizName','bizEmail','bizPhone','bizBank','baseUrl','defaultTerms',
                'greenApiInstance','greenApiToken','salesRepPhone'];
    $s = getSettings();
    foreach ($allowed as $k) {
        if (isset($body[$k])) $s[$k] = $body[$k];
    }
    saveSettingsFile($s);
    jsonOk();
}

jsonFail('פעולה לא מוכרת');

<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id   = $body['id'] ?? '';
if (!$id) { echo json_encode(['ok' => false]); exit; }

$p = readProposal($id);
if (!$p)  { echo json_encode(['ok' => false]); exit; }

$now      = date('c');
$ip       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
$duration = max(0, intval($body['durationSeconds'] ?? 0));

// record view
$view  = ['viewedAt' => $now, 'ip' => $ip, 'userAgent' => $ua, 'durationSeconds' => $duration];
$p['views']            = array_slice(array_merge([$view], $p['views'] ?? []), 0, 50);
$p['totalViews']       = ($p['totalViews']       ?? 0) + 1;
$p['totalViewSeconds'] = ($p['totalViewSeconds'] ?? 0) + $duration;
if (empty($p['firstViewedAt'])) $p['firstViewedAt'] = $now;
$p['lastViewedAt'] = $now;

// auto-transition status
if (in_array($p['status'] ?? 'draft', ['draft', 'sent'], true)) {
    $p['status'] = 'viewed';
}

writeProposal($p);

// ─── WhatsApp to sales rep (throttle: first view OR > 3 h) ────
$lastNotified  = $p['lastNotifiedAt'] ?? null;
$shouldNotify  = !$lastNotified || (time() - strtotime($lastNotified)) > 3 * 3600;

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

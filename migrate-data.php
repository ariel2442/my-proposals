<?php
// One-time migration from /data/*.json files → MySQL.
// Run once: https://yoursite.com/price/migrate-data.php?key=MIGRATE_KEY
// Then delete this file.
define('MIGRATE_KEY', 'change-me-before-running');

if (($_GET['key'] ?? '') !== MIGRATE_KEY) {
    http_response_code(403);
    die('Forbidden. Set ?key=MIGRATE_KEY in the URL.');
}

require_once __DIR__ . '/db.php';
$pdo = db();
$dir = __DIR__ . '/data/';
$log = [];

// ── 1. Migrate proposals from proposals_*.json ──────────────────
foreach (glob($dir . 'proposals_*.json') as $file) {
    $userId = preg_replace('/^.*proposals_([^.]+)\.json$/', '$1', $file);
    $json   = json_decode(file_get_contents($file), true) ?? [];

    // settings / library
    if (!empty($json['settings']) || !empty($json['library'])) {
        $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, settings, library) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE settings=VALUES(settings), library=VALUES(library)');
        $stmt->execute([
            $userId,
            !empty($json['settings']) ? json_encode($json['settings']) : null,
            !empty($json['library'])  ? json_encode($json['library'])  : null,
        ]);
        $log[] = "user_settings: saved for user $userId";
    }

    // deletedIds
    if (!empty($json['deletedIds'])) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO deleted_proposals (user_id, proposal_id) VALUES (?,?)');
        foreach ($json['deletedIds'] as $pid) {
            $stmt->execute([$userId, $pid]);
        }
        $log[] = 'deleted_proposals: ' . count($json['deletedIds']) . " ids for user $userId";
    }

    // proposals
    $proposals = $json['proposals'] ?? [];
    $stmt = $pdo->prepare('INSERT INTO proposals (id, user_id, data, status, updated_at) VALUES (?,?,?,?,?)
        ON DUPLICATE KEY UPDATE data=VALUES(data), status=VALUES(status), updated_at=VALUES(updated_at)');
    $count = 0;
    foreach ($proposals as $p) {
        if (empty($p['id'])) continue;
        $pid = preg_replace('/[^a-zA-Z0-9_\-]/', '', $p['id']);
        $stmt->execute([$pid, $userId, json_encode($p), $p['status'] ?? 'draft', time() * 1000]);
        $count++;
    }
    $log[] = "proposals: $count proposals for user $userId";
}

// ── 2. Migrate signed agreements (*_signed.json) ───────────────
foreach (glob($dir . '*_signed.json') as $file) {
    $base = basename($file, '_signed.json');
    $pid  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $base);
    $data = file_get_contents($file);
    $dec  = json_decode($data, true) ?? [];
    $at   = $dec['signedAt'] ?? 0;
    $stmt = $pdo->prepare('INSERT INTO signed_agreements (proposal_id, data, signed_at) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE data=VALUES(data), signed_at=VALUES(signed_at)');
    $stmt->execute([$pid, $data, $at]);
    $log[] = "signed_agreements: $pid";
}

// ── 3. Migrate passwords.json ──────────────────────────────────
$pwFile = $dir . 'passwords.json';
if (file_exists($pwFile)) {
    $pw   = json_decode(file_get_contents($pwFile), true) ?? [];
    $stmt = $pdo->prepare('INSERT INTO user_passwords (user_id, password_hash) VALUES (?,?)
        ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)');
    foreach ($pw as $uid => $hash) {
        $stmt->execute([$uid, $hash]);
    }
    $log[] = 'user_passwords: ' . count($pw) . ' records';
}

// ── 4. Migrate webauthn_creds.json ─────────────────────────────
$credFile = $dir . 'webauthn_creds.json';
if (file_exists($credFile)) {
    $creds = json_decode(file_get_contents($credFile), true) ?? [];
    $stmt  = $pdo->prepare('INSERT INTO webauthn_creds (user_id, credential_id, name) VALUES (?,?,?)');
    foreach ($creds as $c) {
        try { $stmt->execute([$c['userId'], $c['credentialId'], $c['name'] ?? '']); } catch(Exception $e) {}
    }
    $log[] = 'webauthn_creds: ' . count($creds) . ' records';
}

// ── 5. reset_tokens.json is ephemeral — skip ──────────────────

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;padding:20px">';
echo "<b>Migration log:</b>\n\n";
foreach ($log as $line) echo "  $line\n";
echo "\n<b>Done. Delete this file from the server.</b>";
echo '</pre>';

<?php
session_start();

define('DATA_DIR',      __DIR__ . '/data/');
define('SETTINGS_FILE', DATA_DIR . 'settings.json');
define('INDEX_FILE',    DATA_DIR . '__index__.json');

// ─── auth ──────────────────────────────────────────────────────
function requireAuth(): array {
    if (empty($_SESSION['auth'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $_SESSION['auth'];
}

// ─── response helpers ──────────────────────────────────────────
function jsonOk(array $data = []): void {
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonFail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── settings ─────────────────────────────────────────────────
function getSettings(): array {
    if (!file_exists(SETTINGS_FILE)) return [];
    return json_decode(file_get_contents(SETTINGS_FILE), true) ?? [];
}
function saveSettingsFile(array $s): void {
    ensureDataDir();
    file_put_contents(SETTINGS_FILE, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ─── proposal index ────────────────────────────────────────────
function getProposalIndex(): array {
    if (!file_exists(INDEX_FILE)) return ['ids' => []];
    return json_decode(file_get_contents(INDEX_FILE), true) ?? ['ids' => []];
}
function saveProposalIndex(array $idx): void {
    ensureDataDir();
    file_put_contents(INDEX_FILE, json_encode($idx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ─── proposal file I/O ─────────────────────────────────────────
function safeId(string $id): string {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $id);
}
function readProposal(string $id): ?array {
    $file = DATA_DIR . safeId($id) . '.json';
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}
function writeProposal(array $p): void {
    ensureDataDir();
    $id = safeId($p['id']);
    file_put_contents(DATA_DIR . $id . '.json', json_encode($p, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    // keep index in sync
    $idx = getProposalIndex();
    if (!in_array($p['id'], $idx['ids'], true)) {
        array_unshift($idx['ids'], $p['id']);
        saveProposalIndex($idx);
    }
}
function deleteProposalFile(string $id): void {
    $safe = safeId($id);
    $file = DATA_DIR . $safe . '.json';
    if (file_exists($file)) unlink($file);
    $idx = getProposalIndex();
    $idx['ids'] = array_values(array_filter($idx['ids'], fn($x) => $x !== $id));
    saveProposalIndex($idx);
}
function ensureDataDir(): void {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
}

// ─── WhatsApp via Green API ────────────────────────────────────
function normalizePhone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) < 9) return '';
    if (str_starts_with($digits, '0'))   return '972' . substr($digits, 1);
    if (!str_starts_with($digits, '972')) return '972' . $digits;
    return $digits;
}

function sendWhatsapp(string $phone, string $message): bool {
    $s          = getSettings();
    $instanceId = $s['greenApiInstance'] ?? '';
    $token      = $s['greenApiToken']    ?? '';
    if (!$instanceId || !$token) return false;

    $normalized = normalizePhone($phone);
    if (!$normalized) return false;

    $url = "https://api.green-api.com/waInstance{$instanceId}/sendMessage/{$token}";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(
            ['chatId' => $normalized . '@c.us', 'message' => $message],
            JSON_UNESCAPED_UNICODE
        ),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result !== false;
}

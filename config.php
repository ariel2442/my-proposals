<?php
session_start();

// ─── Storage backend ───────────────────────────────────────────
// false = file-based storage (default, works immediately)
// true  = MySQL (set after running setup-db.php and filling credentials below)
define('USE_DB', false);

// ─── Database credentials (only needed when USE_DB = true) ─────
define('DB_HOST',    'localhost');
define('DB_NAME',    'quotes_db');
define('DB_USER',    'your_db_user');
define('DB_PASS',    'your_db_password');
define('DB_CHARSET', 'utf8mb4');

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

// ─── Users (dynamic management) ──────────────────────────────
function getUsersList(): array {
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        try {
            $rows = db()->query('SELECT * FROM users ORDER BY CAST(id AS UNSIGNED)')->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (Exception $e) { return require __DIR__ . '/users.php'; }
    }
    $usersFile = DATA_DIR . 'users.json';
    if (file_exists($usersFile)) {
        return json_decode(file_get_contents($usersFile), true) ?? [];
    }
    return require __DIR__ . '/users.php';
}

function saveUsersList(array $users): void {
    ensureDataDir();
    file_put_contents(DATA_DIR . 'users.json', json_encode(array_values($users), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getPasswordHash(string $userId): ?string {
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        $stmt = db()->prepare('SELECT password_hash FROM user_passwords WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? $row['password_hash'] : null;
    }
    $pwFile = DATA_DIR . 'passwords.json';
    $pw = file_exists($pwFile) ? json_decode(file_get_contents($pwFile), true) ?? [] : [];
    return $pw[$userId] ?? null;
}

function savePasswordHash(string $userId, string $hash): void {
    if (USE_DB) {
        require_once __DIR__ . '/db.php';
        db()->prepare('INSERT INTO user_passwords (user_id, password_hash) VALUES (?,?) ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash)')
            ->execute([$userId, $hash]);
    } else {
        ensureDataDir();
        $pwFile = DATA_DIR . 'passwords.json';
        $pw = file_exists($pwFile) ? json_decode(file_get_contents($pwFile), true) ?? [] : [];
        $pw[$userId] = $hash;
        file_put_contents($pwFile, json_encode($pw));
    }
}

// ─── reminders engine ─────────────────────────────────────────
function runReminders(string $type, array $s): array {
    $idx       = getProposalIndex();
    $now       = time();
    $sent      = 0;
    $processed = 0;
    $baseUrl   = rtrim($s['baseUrl'] ?? '', '/');

    foreach ($idx['ids'] as $id) {
        $p = readProposal($id);
        if (!$p) continue;
        $processed++;

        $clientPhone = $p['clientPhone'] ?? '';
        if (!$clientPhone) continue;
        $status = $p['status'] ?? 'draft';

        // ── Reminder 1: sent but not yet opened ────────────────
        if (in_array($type, ['all', 'not-open'], true) && ($s['reminderNotOpenEnabled'] ?? false)) {
            if ($status === 'sent' && !empty($p['sentAt']) && empty($p['reminderNotOpenSentAt'])) {
                $hoursSince    = ($now - strtotime($p['sentAt'])) / 3600;
                $requiredHours = (float)($s['reminderNotOpenHours'] ?? 24);
                if ($hoursSince >= $requiredHours) {
                    $link = $baseUrl ? $baseUrl . '/p/?id=' . $p['id'] : '';
                    $tpl  = $s['msgReminderNotOpen']
                        ?? "שלום {name} 👋\n\nשלחתי לך הצעת מחיר לפני {hours} שעות 📄\n\nהצעה #{num} עדיין מחכה לצפייה שלך:\n🔗 {link}\n\nלכל שאלה — אשמח לעזור!";
                    $msg  = fillTemplate($tpl, [
                        'name'  => $p['clientName']   ?? 'לקוח',
                        'num'   => $p['proposalNum']  ?? '',
                        'link'  => $link,
                        'hours' => (int)floor($hoursSince),
                    ]);
                    if (sendWhatsapp($clientPhone, $msg)) {
                        $p['reminderNotOpenSentAt'] = date('c');
                        writeProposal($p);
                        $sent++;
                    }
                }
            }
        }

        // ── Reminder 2: viewed but not signed ──────────────────
        if (in_array($type, ['all', 'not-signed'], true) && ($s['reminderNotSignedEnabled'] ?? false)) {
            if ($status === 'viewed' && !empty($p['firstViewedAt']) && empty($p['reminderNotSignedSentAt'])) {
                $hoursSince    = ($now - strtotime($p['firstViewedAt'])) / 3600;
                $requiredHours = (float)($s['reminderNotSignedHours'] ?? 48);
                if ($hoursSince >= $requiredHours) {
                    $link = $baseUrl ? $baseUrl . '/p/?id=' . $p['id'] : '';
                    $tpl  = $s['msgReminderNotSigned']
                        ?? "שלום {name} 👋\n\nראיתי שצפית בהצעת המחיר שלנו ☺️\n\nנשמח לענות על כל שאלה ולסגור יחד!\n\n📄 הצעה #{num}\n🔗 {link}";
                    $msg  = fillTemplate($tpl, [
                        'name'  => $p['clientName']   ?? 'לקוח',
                        'num'   => $p['proposalNum']  ?? '',
                        'link'  => $link,
                        'hours' => (int)floor($hoursSince),
                    ]);
                    if (sendWhatsapp($clientPhone, $msg)) {
                        $p['reminderNotSignedSentAt'] = date('c');
                        writeProposal($p);
                        $sent++;
                    }
                }
            }
        }
    }

    return ['sent' => $sent, 'processed' => $processed];
}

// ─── message template helper ──────────────────────────────────
function fillTemplate(string $tpl, array $vars): string {
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{' . $k . '}', (string)$v, $tpl);
    }
    return $tpl;
}

// ─── GROW / Meshulam payment link ─────────────────────────────
function createGrowPaymentLink(array $p): ?string {
    $s        = getSettings();
    $userId   = $s['growUserId']   ?? '';
    $pageCode = $s['growPageCode'] ?? '';
    if (!$userId || !$pageCode) return null;

    $sandbox    = !empty($s['growSandbox']);
    $base       = $sandbox
        ? 'https://sandbox.meshulam.co.il'
        : 'https://meshulam.co.il';
    $endpoint   = $base . '/api/light/server/1.0/createPaymentProcess';
    $successUrl = $s['growSuccessUrl'] ?? '';

    $post = [
        'pageCode'            => $pageCode,
        'userId'              => $userId,
        'sum'                 => (float)($p['total'] ?? 0),
        'description'         => 'הצעה ' . ($p['proposalNum'] ?? ''),
        'successUrl'          => $successUrl,
        'cancelUrl'           => $successUrl,
        'pageField[fullName]' => $p['clientName']  ?? '',
        'pageField[phone]'    => $p['clientPhone'] ?? '',
        'cField1'             => $p['id']          ?? '',
        'cField2'             => $p['proposalNum'] ?? '',
    ];
    if (!empty($p['clientVatId'])) {
        $post['pageField[invoiceLicenseNumber]'] = $p['clientVatId'];
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,   // array → multipart/form-data
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $res  = curl_exec($ch);
    unset($ch);
    $json = json_decode($res, true);
    if (($json['status'] ?? 0) === 1 && !empty($json['data']['url'])) {
        return $json['data']['url'];
    }
    return null;
}

// ─── Google Drive upload (service account) ────────────────────
function uploadToDrive(string $filename, string $content, string $mimeType = 'text/plain'): ?string {
    $s        = getSettings();
    $folderId = $s['driveFolderId'] ?? '';
    $saFile   = DATA_DIR . 'google-service-account.json';

    if (!$folderId || !file_exists($saFile)) return null;

    $sa = json_decode(file_get_contents($saFile), true);
    if (empty($sa['private_key']) || empty($sa['client_email'])) return null;

    $b64url = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

    $now    = time();
    $header = $b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = $b64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $sig = '';
    openssl_sign($header . '.' . $claims, $sig, $sa['private_key'], 'SHA256');
    $jwt = $header . '.' . $claims . '.' . $b64url($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
    $token = json_decode(curl_exec($ch), true)['access_token'] ?? '';
    unset($ch);
    if (!$token) return null;

    $boundary = 'drv_' . uniqid();
    $meta     = json_encode(['name' => $filename, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE);
    $body     = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n"
              . "--{$boundary}\r\nContent-Type: {$mimeType}\r\n\r\n{$content}\r\n--{$boundary}--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: multipart/related; boundary=' . $boundary]]);
    $fileData = json_decode(curl_exec($ch), true);
    unset($ch);
    return $fileData['id'] ?? null;
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
    unset($ch);
    return $result !== false;
}

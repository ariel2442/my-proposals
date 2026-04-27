<?php
// Run once to create all tables: https://yoursite.com/price/setup-db.php?key=SETUP_KEY
// Change SETUP_KEY to something secret, then delete this file after running.
define('SETUP_KEY', 'change-me-before-running');

if (($_GET['key'] ?? '') !== SETUP_KEY) {
    http_response_code(403);
    die('Forbidden. Set ?key=SETUP_KEY in the URL.');
}

require_once __DIR__ . '/db.php';

$tables = [

'proposals' => '
CREATE TABLE IF NOT EXISTS proposals (
  id         VARCHAR(64)  NOT NULL,
  user_id    VARCHAR(64)  NOT NULL,
  data       MEDIUMTEXT   NOT NULL,
  status     VARCHAR(32)  NOT NULL DEFAULT \'draft\',
  updated_at BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  INDEX idx_user   (user_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

'deleted_proposals' => '
CREATE TABLE IF NOT EXISTS deleted_proposals (
  user_id     VARCHAR(64) NOT NULL,
  proposal_id VARCHAR(64) NOT NULL,
  PRIMARY KEY (user_id, proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

'signed_agreements' => '
CREATE TABLE IF NOT EXISTS signed_agreements (
  proposal_id VARCHAR(64)  NOT NULL,
  data        LONGTEXT     NOT NULL,
  signed_at   BIGINT       NOT NULL DEFAULT 0,
  PRIMARY KEY (proposal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

'user_settings' => '
CREATE TABLE IF NOT EXISTS user_settings (
  user_id  VARCHAR(64) NOT NULL,
  settings MEDIUMTEXT  DEFAULT NULL,
  library  MEDIUMTEXT  DEFAULT NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

'reset_tokens' => '
CREATE TABLE IF NOT EXISTS reset_tokens (
  token      VARCHAR(128) NOT NULL,
  user_id    VARCHAR(64)  NOT NULL,
  expires_at INT          NOT NULL,
  PRIMARY KEY (token),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

'user_passwords' => '
CREATE TABLE IF NOT EXISTS user_passwords (
  user_id       VARCHAR(64) NOT NULL,
  password_hash VARCHAR(64) NOT NULL,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

'webauthn_creds' => '
CREATE TABLE IF NOT EXISTS webauthn_creds (
  id            INT         NOT NULL AUTO_INCREMENT,
  user_id       VARCHAR(64) NOT NULL,
  credential_id TEXT        NOT NULL,
  name          VARCHAR(255),
  PRIMARY KEY (id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

];

$pdo = db();
$results = [];
foreach ($tables as $name => $sql) {
    try {
        $pdo->exec($sql);
        $results[$name] = 'OK';
    } catch (PDOException $e) {
        $results[$name] = 'ERROR: ' . $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;padding:20px">';
echo "<b>Database setup results:</b>\n\n";
foreach ($results as $table => $status) {
    echo str_pad($table, 22) . " → $status\n";
}
echo "\n<b>Done. Delete this file from the server.</b>";
echo '</pre>';

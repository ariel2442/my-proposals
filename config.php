<?php
// ─── Switch ────────────────────────────────────────────────────
// false = file-based storage (default, works immediately)
// true  = MySQL (set after running setup-db.php and filling credentials below)
define('USE_DB', false);

// ─── Database credentials (only needed when USE_DB = true) ─────
define('DB_HOST',    'localhost');
define('DB_NAME',    'quotes_db');
define('DB_USER',    'your_db_user');
define('DB_PASS',    'your_db_password');
define('DB_CHARSET', 'utf8mb4');

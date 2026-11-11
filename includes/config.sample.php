<?php
// includes/config.sample.php - Public Configuration Template for LocalNeta
// Copy this file to `includes/config.local.php` and fill in your real credentials.
// NEVER commit `includes/config.local.php` to version control.

if (!defined('APP_INIT') && !defined('PPMS_INIT') && !defined('LOCALNETA_DB_INIT') && !defined('LOCALNETA_SMS_HELPER_LOADED')) {
    http_response_code(403);
    exit('Forbidden');
}

// MySQL Database Settings
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'localneta_db');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'your_mysql_user');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', 'your_mysql_password');
}

// Optional SMS OTP Gateway Settings (Production)
// if (!defined('LOCALNETA_SEND_LIVE_SMS')) { define('LOCALNETA_SEND_LIVE_SMS', false); }
// if (!defined('LOCALNETA_SMS_URL')) { define('LOCALNETA_SMS_URL', ''); }
// if (!defined('LOCALNETA_SMS_AUTH_KEY')) { define('LOCALNETA_SMS_AUTH_KEY', ''); }
// if (!defined('LOCALNETA_SMS_SENDER_ID')) { define('LOCALNETA_SMS_SENDER_ID', 'LOCNTA'); }

// GitHub Version Control Settings
if (!defined('LOCALNETA_GITHUB_REPO')) {
    define('LOCALNETA_GITHUB_REPO', 'https://github.com/kgauravindia/localneta.git');
}
if (!defined('LOCALNETA_GITHUB_BRANCH')) {
    define('LOCALNETA_GITHUB_BRANCH', 'main');
}


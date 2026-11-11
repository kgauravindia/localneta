<?php
// logout.php - Public User / Politician Sign Out (Isolated from PPMS Session)
define('APP_INIT', true);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$redirectTarget = is_safe_local_redirect($_GET['redirect'] ?? '');
logout_user_account();

header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : get_site_url('login?logged_out=1')));
exit;


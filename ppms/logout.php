<?php
// ppms/logout.php - Sign Out Handler for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$pdo = ppms_db();
$user = ppms_current_admin($pdo);

if (!$user) {
    header('Location: ' . get_site_url('ppms/login'), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ppms_verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        ppms_set_flash('error', 'Invalid security token for sign out.');
        header('Location: ' . get_site_url('ppms'), true, 302);
        exit;
    }

    ppms_log_action($pdo, $user, 'LOGOUT', null, null, 'Signed out of PPMS', 'user');
    ppms_clear_session();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    header('Location: ' . get_site_url('ppms/login'), true, 302);
    exit;
}

$activeNav = 'dashboard';
$ppmsPageTitle = 'Sign Out';
require_once __DIR__ . '/includes/layout_top.php';
?>
<div class="ppms-card" style="max-width:500px; margin:40px auto;">
    <div class="ppms-card-header">
        <h2 class="ppms-card-title"><i class="fas fa-sign-out-alt text-danger"></i> Confirm Sign Out</h2>
    </div>
    <div class="ppms-card-body">
        <p class="text-secondary small mb-4">Are you sure you want to end your active PPMS session as <strong><?= e($user['username']) ?></strong>?</p>
        <div class="d-flex gap-2">
            <form method="POST" action="<?= e(get_site_url('ppms/logout')) ?>">
                <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                <button type="submit" class="btn btn-danger btn-sm px-3 fw-bold">
                    <i class="fas fa-sign-out-alt me-1"></i> Yes, Sign Out
                </button>
            </form>
            <a href="<?= e(get_site_url('ppms')) ?>" class="btn btn-light border btn-sm px-3 fw-semibold">Cancel</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>


<?php
// forgot-password.php - OTP-Verified Password Reset Flow
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();

if (is_user_logged_in()) {
    header('Location: ' . get_site_url('dashboard'));
    exit;
}

$step = ($_GET['step'] ?? '') === 'new_password' ? 'new_password' : 'request';
$resetUserId = (int)($_SESSION['reset_verified_user_id'] ?? 0);
if ($step === 'new_password' && $resetUserId <= 0) {
    $step = 'request';
}

$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verify_user_csrf_token($csrf)) {
        $errorMsg = 'Security token expired. Please reload and try again.';
    } elseif ($step === 'new_password') {
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirmPass = (string)($_POST['confirm_password'] ?? '');
        if (strlen($newPass) < 6) {
            $errorMsg = 'New password must be at least 6 characters long.';
        } elseif ($newPass !== $confirmPass) {
            $errorMsg = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password = ?, is_mobile_verified = 1 WHERE id = ?");
            $upd->execute([$hash, $resetUserId]);
            unset($_SESSION['reset_verified_user_id']);
            header('Location: ' . get_site_url('login?reset=1'));
            exit;
        }
    } else {
        $cleanMobile = normalize_indian_mobile((string)($_POST['mobile'] ?? ''));
        if ($cleanMobile === '') {
            $errorMsg = 'Please enter a valid 10-digit registered mobile number.';
        } else {
            $stmt = $pdo->prepare("SELECT id, mobile FROM users WHERE mobile = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$cleanMobile]);
            $u = $stmt->fetch();
            if (!$u) {
                $errorMsg = 'No active account found with that mobile number.';
            } else {
                $_SESSION['pending_user_id'] = (int)$u['id'];
                $_SESSION['pending_mobile'] = (string)$u['mobile'];
                $_SESSION['pending_purpose'] = 'password_reset';
                send_user_otp($pdo, (int)$u['id'], 'password_reset');
                header('Location: ' . get_site_url('verify-otp'));
                exit;
            }
        }
    }
}

$pageTitle = 'Reset Password';
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1><i class="fas fa-unlock-keyhole" style="color:var(--saffron-main);"></i> Reset Your Password</h1>
                <p><?= $step === 'new_password' ? 'Set a strong new password for your verified LocalNeta account.' : 'Enter your registered 10-digit mobile number to receive a password reset OTP.' ?></p>
            </div>

            <div class="auth-card-body">
                <?php if ($errorMsg !== ''): ?>
                    <div class="alert-box alert-error">
                        <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                        <div><?= e($errorMsg) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($step === 'new_password'): ?>
                    <form method="POST" action="<?= get_site_url('forgot-password?step=new_password') ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">
                        <div class="form-group">
                            <label class="form-label" for="newPass">New Password (Min 6 characters)</label>
                            <input type="password" id="newPass" name="new_password" class="form-control" required autofocus>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="confirmPass">Confirm New Password</label>
                            <input type="password" id="confirmPass" name="confirm_password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn-green-tiranga" style="width:100%; padding:12px 20px;">
                            <i class="fas fa-check"></i> Save New Password
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?= get_site_url('forgot-password') ?>">
                        <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">
                        <div class="form-group">
                            <label class="form-label" for="resetMobile">Registered 10-Digit Mobile Number</label>
                            <input type="tel" id="resetMobile" name="mobile" class="form-control" placeholder="e.g. 9876543210" maxlength="13" required autofocus>
                        </div>
                        <button type="submit" class="btn-primary-tiranga" style="width:100%; padding:12px 20px;">
                            <i class="fas fa-paper-plane"></i> Send Reset OTP
                        </button>
                    </form>
                <?php endif; ?>

                <div style="text-align:center; margin-top:18px; padding-top:16px; border-top:1px solid var(--border-light); font-size:0.86rem;">
                    <a href="<?= get_site_url('login') ?>" style="color:var(--navy-accent); font-weight:700;">
                        <i class="fas fa-arrow-left"></i> Back to Sign In
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


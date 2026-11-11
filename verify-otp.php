<?php
// verify-otp.php - Mobile OTP Verification Page
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();

$pendingUserId = (int)($_SESSION['pending_user_id'] ?? 0);
$pendingPurpose = (string)($_SESSION['pending_purpose'] ?? 'verification');
$pendingRedirect = is_safe_local_redirect($_SESSION['pending_redirect'] ?? ($_GET['redirect'] ?? ''));

if ($pendingUserId <= 0) {
    header('Location: ' . get_site_url('login'));
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$pendingUserId]);
$pendingUser = $stmt->fetch();

if (!$pendingUser) {
    unset($_SESSION['pending_user_id'], $_SESSION['pending_mobile'], $_SESSION['pending_purpose']);
    header('Location: ' . get_site_url('login'));
    exit;
}

$errorMsg = '';
$successMsg = '';

if (isset($_GET['action']) && $_GET['action'] === 'resend') {
    $lastSent = (int)($_SESSION['otp_last_resend_ts'] ?? 0);
    if (time() - $lastSent < 15) {
        $errorMsg = 'Please wait a few seconds before requesting another OTP.';
    } else {
        $sendRes = send_user_otp($pdo, $pendingUserId, $pendingPurpose);
        if (!empty($sendRes['success'])) {
            $_SESSION['otp_last_resend_ts'] = time();
            $successMsg = 'A fresh 6-digit OTP has been sent to ' . ($sendRes['masked_mobile'] ?? mask_phone_number((string)$pendingUser['mobile'])) . '.';
        } else {
            $errorMsg = (string)($sendRes['message'] ?? 'Unable to resend OTP.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $otpInput = trim((string)($_POST['otp_code'] ?? ''));

    if (!verify_user_csrf_token($csrf)) {
        $errorMsg = 'Security token expired. Please refresh and try again.';
    } elseif (!preg_match('/^\d{6}$/', $otpInput)) {
        $errorMsg = 'Please enter a valid 6-digit numeric OTP.';
    } elseif (!verify_user_otp($pdo, $pendingUserId, $otpInput)) {
        $errorMsg = 'Invalid or expired OTP code. Please check carefully or click Resend OTP.';
    } else {
        // Reload fresh verified user row
        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([$pendingUserId]);
        $verifiedUser = $uStmt->fetch();

        if ($pendingPurpose === 'password_reset') {
            $_SESSION['reset_verified_user_id'] = $pendingUserId;
            unset($_SESSION['pending_user_id'], $_SESSION['pending_mobile'], $_SESSION['pending_purpose'], $_SESSION['local_dev_otp']);
            header('Location: ' . get_site_url('forgot-password?step=new_password'));
            exit;
        }

        unset($_SESSION['pending_redirect']);
        if ($verifiedUser) {
            set_user_session($pdo, $verifiedUser);
        }
        $dest = $pendingRedirect !== '' ? $pendingRedirect : get_site_url('dashboard?verified=1');
        header('Location: ' . $dest);
        exit;
    }
}

// Refresh latest OTP row for local dev banner display
$stmt->execute([$pendingUserId]);
$pendingUser = $stmt->fetch() ?: $pendingUser;
$maskedMobile = mask_phone_number((string)$pendingUser['mobile']);
$localDevOtp = is_local_dev_env() ? (string)($_SESSION['local_dev_otp'] ?? ($pendingUser['otp_code'] ?? '')) : '';

$pageTitle = 'Verify Mobile OTP';
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1><i class="fas fa-mobile-screen-button" style="color:var(--saffron-main);"></i> Mobile OTP Verification</h1>
                <p>Enter the 6-digit verification code sent to <strong><?= e($maskedMobile) ?></strong></p>
            </div>

            <div class="auth-card-body">
                <?php if ($errorMsg !== ''): ?>
                    <div class="alert-box alert-error">
                        <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                        <div><?= e($errorMsg) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($successMsg !== ''): ?>
                    <div class="alert-box alert-success">
                        <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                        <div><?= e($successMsg) ?></div>
                    </div>
                <?php endif; ?>

                <?php if (is_local_dev_env() && $localDevOtp !== ''): ?>
                    <div class="local-otp-banner">
                        <i class="fas fa-flask" style="color:#D97706;"></i>
                        <strong>Local Dev Mode OTP:</strong>
                        <code id="localDevOtpCode" style="font-size:1rem; font-weight:800; background:#FEF3C7; padding:2px 8px; border-radius:4px; color:#92400E;"><?= e($localDevOtp) ?></code>
                        <span style="display:block; font-size:0.78rem; margin-top:4px;">External SMS gateway is disabled on localhost. Use the generated OTP above (or <code>123456</code>).</span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= get_site_url('verify-otp') ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">
                    <div class="form-group">
                        <label class="form-label" for="otpInput">Enter 6-Digit OTP</label>
                        <input type="text" id="otpInput" name="otp_code" class="form-control otp-input-field" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="••••••" autocomplete="one-time-code" required autofocus>
                    </div>

                    <button type="submit" class="btn-primary-tiranga" style="width:100%; padding:12px 20px; font-size:0.96rem;">
                        <i class="fas fa-check-circle"></i> Verify &amp; Continue
                    </button>
                </form>

                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:18px; padding-top:16px; border-top:1px solid var(--border-light); font-size:0.84rem;">
                    <div>
                        <span id="resendCountdownWrap" style="color:var(--text-muted);">Resend OTP in <strong id="resendSeconds">45</strong>s</span>
                        <a href="<?= get_site_url('verify-otp?action=resend') ?>" id="resendOtpLink" hidden style="color:var(--saffron-dark); font-weight:700; text-decoration:underline;">
                            <i class="fas fa-rotate-right"></i> Resend OTP Now
                        </a>
                    </div>
                    <a href="<?= get_site_url('login') ?>" style="color:var(--navy-accent); font-weight:700;">
                        <i class="fas fa-arrow-left"></i> Change Mobile / Sign In
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function() {
    let sec = 45;
    const secEl = document.getElementById('resendSeconds');
    const wrapEl = document.getElementById('resendCountdownWrap');
    const linkEl = document.getElementById('resendOtpLink');
    const timer = setInterval(() => {
        sec--;
        if (secEl) secEl.textContent = String(sec);
        if (sec <= 0) {
            clearInterval(timer);
            if (wrapEl) wrapEl.setAttribute('hidden', '');
            if (linkEl) linkEl.removeAttribute('hidden');
        }
    }, 1000);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


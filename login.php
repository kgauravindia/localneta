<?php
// login.php - Citizen & Politician Sign In (Password or Mobile OTP)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();

$redirectTarget = is_safe_local_redirect($_GET['redirect'] ?? ($_POST['redirect'] ?? ''));

if (is_user_logged_in()) {
    header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : get_site_url('dashboard')));
    exit;
}

$errorMsg = '';
$infoMsg = '';
$otpNeedsName = false;
if (isset($_GET['logged_out'])) {
    $infoMsg = 'You have been signed out safely.';
} elseif (isset($_GET['reset'])) {
    $infoMsg = 'Your password has been updated. You can now sign in with your new password.';
} elseif ($redirectTarget !== '' && preg_match('~^/(?:localneta/)?studio(?:[/?#]|$)~i', $redirectTarget)) {
    $infoMsg = 'Please sign in or verify your mobile with OTP to access LocalNeta Poster Studio.';
}

$activeTab = ($_POST['login_mode'] ?? ($_GET['mode'] ?? 'password')) === 'otp' ? 'otp' : 'password';
$identifierVal = trim((string)($_POST['identifier'] ?? ''));
$otpMobileVal = trim((string)($_POST['otp_mobile'] ?? ''));
$otpNameVal = trim((string)($_POST['otp_name'] ?? ''));
$otpAccountTypeVal = ($_POST['otp_account_type'] ?? 'user') === 'politician' ? 'politician' : 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!verify_user_csrf_token($csrf)) {
        $errorMsg = 'Security token expired. Please refresh the page and try again.';
    } elseif ($activeTab === 'otp') {
        $cleanMobile = normalize_indian_mobile($otpMobileVal);
        if ($cleanMobile === '') {
            $errorMsg = 'Please enter a valid 10-digit Indian mobile number.';
        } else {
            $otpMobileVal = $cleanMobile;
            $stmt = $pdo->prepare("SELECT * FROM users WHERE mobile = ? LIMIT 1");
            $stmt->execute([$cleanMobile]);
            $user = $stmt->fetch();

            if ($user) {
                if (($user['status'] ?? 'active') !== 'active') {
                    $errorMsg = 'This account is currently suspended. Please contact support.';
                } else {
                    $_SESSION['pending_user_id'] = (int)$user['id'];
                    $_SESSION['pending_mobile'] = (string)$user['mobile'];
                    $_SESSION['pending_purpose'] = ((int)$user['is_mobile_verified'] === 1) ? 'login' : 'verification';
                    if ($redirectTarget !== '') {
                        $_SESSION['pending_redirect'] = $redirectTarget;
                    }
                    send_user_otp($pdo, (int)$user['id'], $_SESSION['pending_purpose']);
                    header('Location: ' . get_site_url('verify-otp'));
                    exit;
                }
            } else {
                // Unregistered mobile -> ask for Name right here, register user & send OTP
                if (!isset($_POST['otp_name'])) {
                    $otpNeedsName = true;
                    $infoMsg = 'No account is registered with ' . mask_phone_number($cleanMobile) . '. Enter your full name below to register & verify OTP.';
                } elseif (mb_strlen($otpNameVal) < 2) {
                    $otpNeedsName = true;
                    $errorMsg = 'Please enter your full name (at least 2 characters) to complete registration.';
                } else {
                    $uniqueHandle = generate_unique_user_handle($pdo, $otpNameVal);
                    $passHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $roleVal = $otpAccountTypeVal === 'politician' ? 'politician' : 'citizen';
                    $leaderCatVal = $otpAccountTypeVal === 'politician' ? 'social-worker' : null;
                    $desigVal = $otpAccountTypeVal === 'politician' ? 'Public Representative' : 'Citizen';

                    $ins = $pdo->prepare("
                        INSERT INTO users (
                            account_type, role, leader_category, name, full_name, username_handle,
                            mobile, whatsapp, password, designation,
                            profile_visibility, mobile_visibility, email_visibility, address_visibility,
                            status, is_mobile_verified
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?,
                            'public', 'masked', 'masked', 'public',
                            'active', 0
                        )
                    ");
                    $ins->execute([
                        $otpAccountTypeVal,
                        $roleVal,
                        $leaderCatVal,
                        $otpNameVal,
                        $otpNameVal,
                        $uniqueHandle,
                        $cleanMobile,
                        $cleanMobile,
                        $passHash,
                        $desigVal,
                    ]);

                    $newUserId = (int)$pdo->lastInsertId();
                    $_SESSION['pending_user_id'] = $newUserId;
                    $_SESSION['pending_mobile'] = $cleanMobile;
                    $_SESSION['pending_purpose'] = 'registration';
                    if ($redirectTarget !== '') {
                        $_SESSION['pending_redirect'] = $redirectTarget;
                    }
                    send_user_otp($pdo, $newUserId, 'registration');
                    header('Location: ' . get_site_url('verify-otp'));
                    exit;
                }
            }
        }
    } else {
        $password = (string)($_POST['password'] ?? '');
        if ($identifierVal === '' || $password === '') {
            $errorMsg = 'Please enter your Mobile / Email / Handle and Password.';
        } else {
            $cleanId = ltrim($identifierVal, '@');
            $normMob = normalize_indian_mobile($cleanId);
            $stmt = $pdo->prepare("
                SELECT * FROM users
                WHERE status = 'active'
                  AND (mobile = ? OR email = ? OR username_handle = ?)
                LIMIT 1
            ");
            $stmt->execute([$normMob !== '' ? $normMob : $cleanId, $cleanId, $cleanId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, (string)$user['password'])) {
                $errorMsg = 'Invalid credentials. Check your mobile/email/handle or use Mobile OTP Login.';
            } elseif ((int)$user['is_mobile_verified'] !== 1) {
                $_SESSION['pending_user_id'] = (int)$user['id'];
                $_SESSION['pending_mobile'] = (string)$user['mobile'];
                $_SESSION['pending_purpose'] = 'verification';
                if ($redirectTarget !== '') {
                    $_SESSION['pending_redirect'] = $redirectTarget;
                }
                send_user_otp($pdo, (int)$user['id'], 'verification');
                header('Location: ' . get_site_url('verify-otp'));
                exit;
            } else {
                set_user_session($pdo, $user);
                header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : get_site_url('dashboard')));
                exit;
            }
        }
    }
}

$pageTitle = 'Sign In — Citizen & Politician Portal';
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-card-header">
                <h1><i class="fas fa-right-to-bracket" style="color:var(--saffron-main);"></i> Sign In to LocalNeta</h1>
                <p>Access your Citizen or Politician dashboard and unlock full mobile numbers &amp; email IDs across India.</p>
            </div>

            <div class="auth-card-body">
                <?php if ($errorMsg !== ''): ?>
                    <div class="alert-box alert-error">
                        <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                        <div><?= e($errorMsg) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($infoMsg !== ''): ?>
                    <div class="alert-box alert-success">
                        <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                        <div><?= e($infoMsg) ?></div>
                    </div>
                <?php endif; ?>

                <div class="auth-tabs" role="tablist">
                    <button type="button" class="auth-tab-btn <?= $activeTab === 'password' ? 'active' : '' ?>" id="tabPasswordLogin" onclick="switchLoginTab('password')">
                        <i class="fas fa-key"></i> Password Login
                    </button>
                    <button type="button" class="auth-tab-btn <?= $activeTab === 'otp' ? 'active' : '' ?>" id="tabOtpLogin" onclick="switchLoginTab('otp')">
                        <i class="fas fa-mobile-screen-button"></i> OTP Fast Login
                    </button>
                </div>

                <!-- Tab 1: Password Login -->
                <form method="POST" action="<?= get_site_url('login') ?>" id="formPasswordLogin" <?= $activeTab === 'password' ? '' : 'hidden' ?>>
                    <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">
                    <input type="hidden" name="login_mode" value="password">
                    <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

                    <div class="form-group">
                        <label class="form-label" for="loginIdentifier">Mobile Number, Email ID, or @Handle</label>
                        <input type="text" id="loginIdentifier" name="identifier" value="<?= e($identifierVal) ?>" class="form-control" placeholder="e.g. 9876543210 or name@example.com" required>
                    </div>

                    <div class="form-group">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <label class="form-label" for="loginPassword" style="margin-bottom:0;">Password</label>
                            <a href="<?= get_site_url('forgot-password') ?>" style="font-size:0.78rem; color:var(--saffron-dark); font-weight:700;">Forgot Password?</a>
                        </div>
                        <input type="password" id="loginPassword" name="password" class="form-control" placeholder="Enter your password" style="margin-top:5px;" required>
                    </div>

                    <button type="submit" class="btn-primary-tiranga" style="width:100%; padding:12px 20px; font-size:0.96rem;">
                        <i class="fas fa-right-to-bracket"></i> Sign In Now
                    </button>
                </form>

                <!-- Tab 2: OTP Fast Login (Auto-prompts Name for unregistered mobile) -->
                <form method="POST" action="<?= get_site_url('login') ?>" id="formOtpLogin" <?= $activeTab === 'otp' ? '' : 'hidden' ?>>
                    <input type="hidden" name="csrf_token" value="<?= e(get_user_csrf_token()) ?>">
                    <input type="hidden" name="login_mode" value="otp">
                    <input type="hidden" name="redirect" value="<?= e($redirectTarget) ?>">

                    <div class="form-group">
                        <label class="form-label" for="otpMobileInput">10-Digit Mobile Number</label>
                        <input type="tel" id="otpMobileInput" name="otp_mobile" value="<?= e($otpMobileVal) ?>" class="form-control" placeholder="e.g. 9876543210" maxlength="13" required>
                    </div>

                    <?php if ($otpNeedsName): ?>
                        <div class="form-group" id="otpQuickRegBox" style="background:var(--white-surface); border:1px solid var(--border-color); border-left:4px solid var(--saffron-main); border-radius:var(--radius-md); padding:12px;">
                            <div style="font-size:0.82rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">
                                <i class="fas fa-user-plus" style="color:var(--saffron-main);"></i> Quick Mobile Registration
                            </div>
                            <div class="form-group" style="margin-bottom:10px;">
                                <label class="form-label" for="otpQuickName">Your Full Name <span style="color:#DC2626;">*</span></label>
                                <input type="text" id="otpQuickName" name="otp_name" value="<?= e($otpNameVal) ?>" class="form-control" placeholder="Enter your full name" minlength="2" required autofocus>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label" for="otpQuickType">Account Type</label>
                                <select id="otpQuickType" name="otp_account_type" class="form-control">
                                    <option value="user" <?= $otpAccountTypeVal === 'user' ? 'selected' : '' ?>>Citizen / Voter</option>
                                    <option value="politician" <?= $otpAccountTypeVal === 'politician' ? 'selected' : '' ?>>Politician / Public Leader</option>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-green-tiranga" style="width:100%; padding:12px 20px; font-size:0.96rem;">
                        <i class="fas fa-paper-plane"></i> <?= $otpNeedsName ? 'Register &amp; Send 6-Digit OTP' : 'Send 6-Digit Login OTP' ?>
                    </button>
                </form>

                <div style="text-align:center; margin-top:20px; padding-top:16px; border-top:1px solid var(--border-light); font-size:0.88rem; color:var(--text-secondary);">
                    New to LocalNeta?
                    <a href="<?= get_site_url('register' . ($redirectTarget !== '' ? '?redirect=' . urlencode($redirectTarget) : '')) ?>" style="color:var(--green-main); font-weight:800; text-decoration:underline;">
                        Create Free Citizen / Politician Account
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
function switchLoginTab(mode) {
    const isOtp = mode === 'otp';
    document.getElementById('tabPasswordLogin')?.classList.toggle('active', !isOtp);
    document.getElementById('tabOtpLogin')?.classList.toggle('active', isOtp);
    document.getElementById('formPasswordLogin')?.toggleAttribute('hidden', isOtp);
    document.getElementById('formOtpLogin')?.toggleAttribute('hidden', !isOtp);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


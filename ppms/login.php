<?php
// ppms/login.php - Authentication Portal for LocalNeta PPMS (Political Party Management System)
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$pdo = ppms_db();
if (ppms_current_admin($pdo)) {
    header('Location: ' . get_site_url('ppms'), true, 302);
    exit;
}

$hasUsers = ppms_has_users($pdo);
$error = '';
$usernameInput = '';
$fullNameInput = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $mode = trim((string)($_POST['mode'] ?? 'login'));

    if (!ppms_verify_csrf($csrf)) {
        $error = 'Security token expired. Please reload and try again.';
    } elseif (!$hasUsers && $mode === 'setup') {
        $usernameInput = strtolower(trim((string)($_POST['username'] ?? '')));
        $fullNameInput = trim((string)($_POST['full_name'] ?? ''));
        $emailInput = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $usernameInput)) {
            $error = 'Username must be 3-50 characters (lowercase letters, numbers, dot, underscore, hyphen).';
        } elseif ($fullNameInput === '') {
            $error = 'Full name is required.';
        } elseif ($emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address or leave email blank.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password and confirmation do not match.';
        } else {
            try {
                $pdo->beginTransaction();
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM ppms_users FOR UPDATE")->fetchColumn();
                if ($cnt > 0) {
                    $pdo->rollBack();
                    $hasUsers = true;
                    $error = 'An administrator account already exists. Please sign in.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare("
                        INSERT INTO ppms_users (username, password_hash, full_name, email, role, status, last_login)
                        VALUES (?, ?, ?, ?, 'superadmin', 'ACTIVE', NOW())
                    ");
                    $ins->execute([
                        $usernameInput,
                        $hash,
                        $fullNameInput,
                        $emailInput !== '' ? $emailInput : null,
                    ]);
                    $newUserId = (int)$pdo->lastInsertId();
                    $pdo->commit();

                    $newUser = [
                        'id' => $newUserId,
                        'username' => $usernameInput,
                        'full_name' => $fullNameInput,
                        'role' => 'superadmin',
                    ];

                    session_regenerate_id(true);
                    $_SESSION['ppms_csrf'] = bin2hex(random_bytes(32));
                    $_SESSION['ppms_auth'] = true;
                    $_SESSION['ppms_admin_id'] = $newUserId;
                    $_SESSION['ppms_admin_user'] = $usernameInput;
                    $_SESSION['ppms_admin_name'] = $fullNameInput;
                    $_SESSION['ppms_admin_role'] = 'superadmin';

                    ppms_log_action($pdo, $newUser, 'USER_UPDATE', null, $usernameInput, 'Initialized first PPMS superadmin account', 'user');
                    ppms_set_flash('success', 'Superadmin account created. Welcome to LocalNeta PPMS!');

                    header('Location: ' . get_site_url('ppms'), true, 302);
                    exit;
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not create initial superadmin account. Please try again.';
            }
        }
    } elseif (!$hasUsers) {
        $error = 'No administrator accounts exist yet. Create the first superadmin account to continue.';
    } else {
        $usernameInput = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($usernameInput === '' || $password === '') {
            $error = 'Please enter both username/email and password.';
        } else {
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, full_name, email, role, status
                FROM ppms_users
                WHERE username = ? OR email = ?
                LIMIT 1
            ");
            $stmt->execute([$usernameInput, $usernameInput]);
            $user = $stmt->fetch();

            if ($user && strtoupper((string)$user['status']) !== 'ACTIVE') {
                $error = 'This administrator account is inactive. Contact a superadmin.';
            } elseif ($user && password_verify($password, (string)$user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['ppms_csrf'] = bin2hex(random_bytes(32));
                $_SESSION['ppms_auth'] = true;
                $_SESSION['ppms_admin_id'] = (int)$user['id'];
                $_SESSION['ppms_admin_user'] = (string)$user['username'];
                $_SESSION['ppms_admin_name'] = (string)$user['full_name'];
                $_SESSION['ppms_admin_role'] = (string)$user['role'];

                $pdo->prepare("UPDATE ppms_users SET last_login = NOW() WHERE id = ?")->execute([(int)$user['id']]);
                ppms_log_action($pdo, $user, 'LOGIN', null, null, 'Signed into PPMS console', 'user');

                header('Location: ' . get_site_url('ppms'), true, 302);
                exit;
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}

$logoUrl = get_site_url('localneta.png');
$csrfToken = ppms_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hasUsers ? 'PPMS Login' : 'PPMS Initial Setup' ?> | LocalNeta — Political Party Management System</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(get_site_url('assets/images/favicon-32x32.png')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/images/favicon-32x32.png') ?: 1 ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(get_site_url('assets/images/favicon-192x192.png')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/images/favicon-192x192.png') ?: 1 ?>">
    <link rel="icon" href="<?= e(get_site_url('favicon.ico')) ?>?v=<?= @filemtime(__DIR__ . '/../favicon.ico') ?: 1 ?>" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(get_site_url('assets/images/apple-touch-icon.png')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/images/apple-touch-icon.png') ?: 1 ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: radial-gradient(circle at top right, #172B4D 0%, #0A192F 55%, #050E1B 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            width: 100%;
            max-width: 430px;
            background: #FFFFFF;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        }
        .tiranga-strip {
            height: 6px;
            background: linear-gradient(90deg, #FF671F 0%, #FF671F 33.3%, #FFFFFF 33.3%, #FFFFFF 66.6%, #046A38 66.6%, #046A38 100%);
        }
        .login-hero {
            background: #0A192F;
            color: #FFFFFF;
            padding: 28px 26px 22px;
            text-align: center;
        }
        .login-hero img {
            width: 68px;
            height: 68px;
            border-radius: 14px;
            background: #FFFFFF;
            padding: 5px;
            object-fit: contain;
            margin-bottom: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.35);
        }
        .login-hero h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 800;
            margin: 0 0 4px;
        }
        .login-hero h1 span {
            color: #FF671F;
        }
        .login-body {
            padding: 26px;
        }
        .btn-login {
            background: linear-gradient(135deg, #FF671F 0%, #E0520B 100%);
            border: none;
            color: #FFFFFF;
            font-weight: 700;
            padding: 11px 16px;
            border-radius: 10px;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #E0520B 0%, #C2410C 100%);
            color: #FFFFFF;
        }
    </style>
</head>
<body>
<div class="login-card">
    <div class="tiranga-strip"></div>
    <div class="login-hero">
        <img src="<?= e($logoUrl) ?>" alt="LocalNeta Emblem">
        <h1>Local<span>Neta</span> PPMS</h1>
        <p class="mb-0 small text-white-50">Political Party Management System</p>
    </div>
    <div class="login-body">
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2 px-3 small mb-3" role="alert">
                <i class="fas fa-exclamation-triangle me-1"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($hasUsers): ?>
        <form method="POST" action="<?= e(get_site_url('ppms/login')) ?>" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="mode" value="login">

            <div class="mb-3">
                <label for="loginUsername" class="form-label small fw-bold text-secondary">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                    <input type="text" id="loginUsername" name="username" class="form-control" value="<?= e($usernameInput) ?>" placeholder="Enter username or email" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="loginPassword" class="form-label small fw-bold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" id="loginPassword" name="password" class="form-control" placeholder="Enter password" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePwdBtn" title="Show/Hide Password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-login w-100 mb-3">
                <i class="fas fa-sign-in-alt me-1"></i> Sign In to PPMS
            </button>
        </form>
        <?php else: ?>
        <div class="alert alert-info py-2 px-3 small mb-3" role="status">
            <i class="fas fa-shield-alt me-1"></i> First-time setup: create the initial Superadmin account to access PPMS.
        </div>

        <form method="POST" action="<?= e(get_site_url('ppms/login')) ?>" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="mode" value="setup">

            <div class="mb-2">
                <label for="setupUsername" class="form-label small fw-bold text-secondary">Superadmin Username <span class="text-danger">*</span></label>
                <input type="text" id="setupUsername" name="username" class="form-control" value="<?= e($usernameInput) ?>" pattern="[a-z0-9_.-]{3,50}" placeholder="Lowercase username (3-50 chars)" required autofocus>
            </div>

            <div class="mb-2">
                <label for="setupFullName" class="form-label small fw-bold text-secondary">Full Name <span class="text-danger">*</span></label>
                <input type="text" id="setupFullName" name="full_name" class="form-control" value="<?= e($fullNameInput) ?>" placeholder="Administrator full name" required>
            </div>

            <div class="mb-2">
                <label for="setupEmail" class="form-label small fw-bold text-secondary">Email (Optional)</label>
                <input type="email" id="setupEmail" name="email" class="form-control" value="<?= e($emailInput) ?>" placeholder="Operator email address">
            </div>

            <div class="mb-2">
                <label for="loginPassword" class="form-label small fw-bold text-secondary">Password (min 8 chars) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span>
                    <input type="password" id="loginPassword" name="password" class="form-control" minlength="8" placeholder="Create strong password" autocomplete="new-password" required>
                    <button class="btn btn-outline-secondary" type="button" id="togglePwdBtn" title="Show/Hide Password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="mb-4">
                <label for="setupConfirmPassword" class="form-label small fw-bold text-secondary">Confirm Password <span class="text-danger">*</span></label>
                <input type="password" id="setupConfirmPassword" name="confirm_password" class="form-control" minlength="8" placeholder="Re-enter password" autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn btn-login w-100 mb-3">
                <i class="fas fa-user-shield me-1"></i> Create Superadmin &amp; Sign In
            </button>
        </form>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center pt-2 border-top small text-muted">
            <a href="<?= e(get_site_url('parties')) ?>" class="text-decoration-none fw-semibold" style="color:#046A38;">
                <i class="fas fa-arrow-left me-1"></i> Public Parties Directory
            </a>
            <span>An initiative of OfferPlant</span>
        </div>
    </div>
</div>

<script>
document.getElementById('togglePwdBtn')?.addEventListener('click', function () {
    const inp = document.getElementById('loginPassword');
    const icon = this.querySelector('i');
    if (!inp || !icon) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
});
</script>
</body>
</html>


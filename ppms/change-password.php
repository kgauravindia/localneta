<?php
// ppms/change-password.php - Profile & Password Update for Signed-in PPMS Admin
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$fullName = (string)$adminUser['full_name'];
$email = (string)($adminUser['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ppms_verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . get_site_url('ppms/change-password'), true, 302);
        exit;
    }

    $mode = trim((string)($_POST['mode'] ?? ''));

    if ($mode === 'profile') {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $submittedEmail = trim((string)($_POST['email'] ?? ''));
        $nextEmail = $submittedEmail !== '' ? $submittedEmail : $email;
        if ($fullName === '') {
            ppms_set_flash('error', 'Full name cannot be empty.');
        } elseif ($nextEmail !== '' && !filter_var($nextEmail, FILTER_VALIDATE_EMAIL)) {
            ppms_set_flash('error', 'Please enter a valid email address.');
        } else {
            $pdo->prepare("UPDATE ppms_users SET full_name = ?, email = ? WHERE id = ?")
                ->execute([$fullName, $nextEmail !== '' ? $nextEmail : null, (int)$adminUser['id']]);
            $_SESSION['ppms_admin_name'] = $fullName;
            ppms_log_action($pdo, $adminUser, 'USER_UPDATE', null, $adminUser['username'], 'Updated profile details', 'user');
            ppms_set_flash('success', 'Profile information updated.');
        }
        header('Location: ' . get_site_url('ppms/change-password'), true, 302);
        exit;
    }

    if ($mode === 'password') {
        $currentPwd = (string)($_POST['current_password'] ?? '');
        $newPwd = (string)($_POST['new_password'] ?? '');
        $confirmPwd = (string)($_POST['confirm_password'] ?? '');

        $hashStmt = $pdo->prepare("SELECT password_hash FROM ppms_users WHERE id = ? LIMIT 1");
        $hashStmt->execute([(int)$adminUser['id']]);
        $existingHash = (string)$hashStmt->fetchColumn();

        if (!password_verify($currentPwd, $existingHash)) {
            ppms_set_flash('error', 'Current password is incorrect.');
        } elseif (strlen($newPwd) < 8) {
            ppms_set_flash('error', 'New password must be at least 8 characters long.');
        } elseif ($newPwd !== $confirmPwd) {
            ppms_set_flash('error', 'New password and confirmation do not match.');
        } else {
            $newHash = password_hash($newPwd, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE ppms_users SET password_hash = ? WHERE id = ?")
                ->execute([$newHash, (int)$adminUser['id']]);
            ppms_log_action($pdo, $adminUser, 'PASSWORD_CHANGE', null, $adminUser['username'], 'Changed account password', 'user');
            ppms_set_flash('success', 'Password changed successfully.');
        }
        header('Location: ' . get_site_url('ppms/change-password'), true, 302);
        exit;
    }
}

$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];
$activeNav = 'change-password';
$ppmsPageTitle = 'Profile & Password';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="ppms-card mb-0 h-100">
            <div class="ppms-card-header">
                <h2 class="ppms-card-title"><i class="fas fa-key text-warning"></i> Change Password</h2>
            </div>
            <div class="ppms-card-body">
                <form method="POST" action="<?= e(get_site_url('ppms/change-password')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="mode" value="password">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Current Password <span class="text-danger">*</span></label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">New Password (min 8 chars) <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-ppms-saffron btn-sm px-4 py-2">
                        <i class="fas fa-save me-1"></i> Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="ppms-card mb-0 h-100">
            <div class="ppms-card-header">
                <h2 class="ppms-card-title"><i class="fas fa-id-badge text-primary"></i> Account Profile</h2>
            </div>
            <div class="ppms-card-body">
                <form method="POST" action="<?= e(get_site_url('ppms/change-password')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="mode" value="profile">

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Username</label>
                        <input type="text" class="form-control bg-light" value="<?= e($adminUser['username']) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Role</label>
                        <input type="text" class="form-control bg-light" value="<?= e(strtoupper((string)$adminUser['role'])) ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" value="<?= e($fullName) ?>" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email</label>
                        <?php $maskedAdminEmail = mask_email_address($email); ?>
                        <input type="email" name="email" value="" class="form-control" placeholder="<?= $maskedAdminEmail !== '' ? 'Current (masked): ' . e($maskedAdminEmail) . ' — leave blank to keep' : 'Enter new operator email address' ?>">
                    </div>
                    <button type="submit" class="btn btn-ppms-navy btn-sm px-4 py-2">
                        <i class="fas fa-check me-1"></i> Save Profile
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>


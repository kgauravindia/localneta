<?php
// ppms/users.php - Superadmin Operator Management for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
if (($adminUser['role'] ?? '') !== 'superadmin') {
    ppms_set_flash('error', 'Only a Superadmin can manage PPMS operator accounts.');
    header('Location: ' . get_site_url('ppms'), true, 302);
    exit;
}

$pdo = ppms_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ppms_verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . get_site_url('ppms/users'), true, 302);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'create_user') {
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = ($_POST['role'] ?? 'editor') === 'superadmin' ? 'superadmin' : 'editor';
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $username)) {
            ppms_set_flash('error', 'Username must be 3-50 characters (letters, numbers, dot, underscore, hyphen).');
        } elseif ($fullName === '' || strlen($password) < 8) {
            ppms_set_flash('error', 'Full name is required and password must be at least 8 characters.');
        } else {
            $dup = $pdo->prepare("SELECT id FROM ppms_users WHERE username = ? LIMIT 1");
            $dup->execute([$username]);
            if ($dup->fetch()) {
                ppms_set_flash('error', "Username '@{$username}' already exists.");
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("INSERT INTO ppms_users (username, password_hash, full_name, email, role, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
                $ins->execute([$username, $hash, $fullName, $email !== '' ? $email : null, $role]);
                ppms_log_action($pdo, $adminUser, 'USER_UPDATE', null, $username, "Created {$role} account", 'user');
                ppms_set_flash('success', "Created PPMS user '@{$username}'.");
            }
        }
        header('Location: ' . get_site_url('ppms/users'), true, 302);
        exit;
    }

    if ($action === 'toggle_status') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId === (int)$adminUser['id']) {
            ppms_set_flash('error', 'You cannot deactivate your own currently signed-in account.');
        } elseif ($targetId > 0) {
            $uStmt = $pdo->prepare("SELECT id, username, role, status FROM ppms_users WHERE id = ? LIMIT 1");
            $uStmt->execute([$targetId]);
            $target = $uStmt->fetch();
            if ($target) {
                $newStat = strtoupper((string)$target['status']) === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
                if ($newStat === 'INACTIVE' && $target['role'] === 'superadmin') {
                    $activeSupers = (int)$pdo->query("SELECT COUNT(*) FROM ppms_users WHERE role = 'superadmin' AND status = 'ACTIVE'")->fetchColumn();
                    if ($activeSupers <= 1) {
                        ppms_set_flash('error', 'Cannot deactivate the last active superadmin account.');
                        header('Location: ' . get_site_url('ppms/users'), true, 302);
                        exit;
                    }
                }
                $pdo->prepare("UPDATE ppms_users SET status = ? WHERE id = ?")->execute([$newStat, $targetId]);
                ppms_log_action($pdo, $adminUser, 'USER_UPDATE', null, $target['username'], "Status set to {$newStat}", 'user');
                ppms_set_flash('success', "Updated '@{$target['username']}' status to {$newStat}.");
            }
        }
        header('Location: ' . get_site_url('ppms/users'), true, 302);
        exit;
    }

    if ($action === 'reset_password') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newPwd = (string)($_POST['new_password'] ?? '');
        if ($targetId > 0 && strlen($newPwd) >= 8) {
            $uStmt = $pdo->prepare("SELECT username FROM ppms_users WHERE id = ? LIMIT 1");
            $uStmt->execute([$targetId]);
            $uname = (string)($uStmt->fetchColumn() ?: '');
            if ($uname !== '') {
                $hash = password_hash($newPwd, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE ppms_users SET password_hash = ? WHERE id = ?")->execute([$hash, $targetId]);
                ppms_log_action($pdo, $adminUser, 'USER_UPDATE', null, $uname, 'Admin password reset', 'user');
                ppms_set_flash('success', "Password updated for '@{$uname}'.");
            }
        } else {
            ppms_set_flash('error', 'New password must be at least 8 characters.');
        }
        header('Location: ' . get_site_url('ppms/users'), true, 302);
        exit;
    }
}

$users = $pdo->query("SELECT id, username, full_name, email, role, status, last_login, created_at FROM ppms_users ORDER BY id ASC")->fetchAll();
$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];

$activeNav = 'users';
$ppmsPageTitle = 'PPMS Admin Users';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="ppms-card mb-0">
            <div class="ppms-card-header">
                <h2 class="ppms-card-title"><i class="fas fa-users-cog text-primary"></i> Administrator Accounts (<?= count($users) ?>)</h2>
            </div>
            <div class="table-responsive">
                <table class="table ppms-table mb-0">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u):
                            $uid = (int)$u['id'];
                            $isSelf = $uid === (int)$adminUser['id'];
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">
                                        <?= e($u['full_name']) ?>
                                        <?php if ($isSelf): ?><span class="badge bg-info text-dark ms-1" style="font-size:0.65rem;">YOU</span><?php endif; ?>
                                    </div>
                                    <div class="small text-muted">@<?= e($u['username']) ?> <?= !empty($u['email']) ? '&bull; ' . e(mask_email_address((string)$u['email'])) : '' ?></div>
                                </td>
                                <td>
                                    <span class="badge <?= $u['role'] === 'superadmin' ? 'bg-primary' : 'bg-secondary' ?>">
                                        <?= e(strtoupper($u['role'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="ppms-badge <?= strtoupper((string)$u['status']) === 'ACTIVE' ? 'ppms-badge-active' : 'ppms-badge-delisted' ?>">
                                        <?= e($u['status']) ?>
                                    </span>
                                </td>
                                <td class="small text-muted">
                                    <?= $u['last_login'] ? e(date('d M Y, H:i', strtotime((string)$u['last_login']))) : 'Never' ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button"
                                            class="btn btn-outline-warning btn-sm py-0 px-2"
                                            data-uid="<?= $uid ?>"
                                            data-uname="<?= e($u['username']) ?>"
                                            onclick="openResetPwdModal(this)">
                                        <i class="fas fa-key"></i> Reset
                                    </button>
                                    <?php if (!$isSelf): ?>
                                        <form method="POST" action="<?= e(get_site_url('ppms/users')) ?>" class="d-inline m-0">
                                            <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $uid ?>">
                                            <button type="submit" class="btn btn-outline-secondary btn-sm py-0 px-2">
                                                <?= strtoupper((string)$u['status']) === 'ACTIVE' ? 'Disable' : 'Enable' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="ppms-card mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-user-plus text-success"></i> Add Operator</h3>
            </div>
            <div class="ppms-card-body">
                <form method="POST" action="<?= e(get_site_url('ppms/users')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_user">

                    <div class="mb-2">
                        <label class="form-label small fw-bold mb-1">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control form-control-sm" required placeholder="e.g. party_editor">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold mb-1">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control form-control-sm" required placeholder="Operator Name">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold mb-1">Email</label>
                        <input type="email" name="email" class="form-control form-control-sm" placeholder="Operator email address">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold mb-1">Role</label>
                        <select name="role" class="form-select form-select-sm">
                            <option value="editor">Editor (Manage Parties &amp; Symbols)</option>
                            <option value="superadmin">Superadmin (Full Access + Users)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold mb-1">Initial Password (min 8 chars) <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control form-control-sm" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-ppms-saffron btn-sm w-100 py-2">
                        <i class="fas fa-plus me-1"></i> Create Account
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPwdModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-key text-warning me-2"></i> Reset Password for <span id="resetPwdUname"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= e(get_site_url('ppms/users')) ?>">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" id="resetPwdUid" value="">
                    <label class="form-label small fw-bold">New Password (min 8 characters)</label>
                    <input type="password" name="new_password" class="form-control" minlength="8" required>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-ppms-navy btn-sm fw-bold">Save Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$ppmsExtraScripts = <<<'HTML'
<script>
function openResetPwdModal(btn) {
    document.getElementById('resetPwdUid').value = btn.getAttribute('data-uid') || '';
    document.getElementById('resetPwdUname').textContent = '@' + (btn.getAttribute('data-uname') || '');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resetPwdModal')).show();
}
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>


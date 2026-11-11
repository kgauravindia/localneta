<?php
// ppms/updates.php - Superadmin Version Control & GitHub Sync Console for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/vcs.php';

$adminUser = ppms_require_auth();
if (($adminUser['role'] ?? '') !== 'superadmin') {
    ppms_set_flash('error', 'Only a Superadmin can manage version control and GitHub updates.');
    header('Location: ' . get_site_url('ppms'), true, 302);
    exit;
}

$pdo = ppms_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ppms_verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . get_site_url('ppms/updates'), true, 302);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'fetch') {
        $res = localneta_vcs_fetch();
        $st = $res['status'] ?? [];
        $_SESSION['ppms_vcs_console'] = [
            'action' => 'git fetch origin --prune --tags',
            'ok' => !empty($res['ok']),
            'output' => (string)($res['output'] ?? ''),
            'time' => date('d M Y, H:i:s'),
        ];
        $targetLabel = 'origin/' . ($st['branch'] ?? localneta_github_branch());
        ppms_log_action($pdo, $adminUser, 'GITHUB_FETCH', null, $targetLabel, (string)$res['message'], 'system');
        ppms_set_flash(!empty($res['ok']) ? 'success' : 'error', (string)$res['message']);
        header('Location: ' . get_site_url('ppms/updates'), true, 302);
        exit;
    }

    if ($action === 'pull') {
        $res = localneta_vcs_pull();
        $st = $res['status'] ?? [];
        $_SESSION['ppms_vcs_console'] = [
            'action' => 'git pull --ff-only origin ' . ($st['branch'] ?? localneta_github_branch()),
            'ok' => !empty($res['ok']),
            'output' => (string)($res['output'] ?? ''),
            'time' => date('d M Y, H:i:s'),
        ];
        if (!empty($res['ok'])) {
            // Re-run automatic database schema checks after pulling code updates
            get_db_connection();
            ppms_ensure_schema($pdo);
        }
        $targetLabel = 'origin/' . ($st['branch'] ?? localneta_github_branch());
        ppms_log_action($pdo, $adminUser, 'GITHUB_PULL', null, $targetLabel, (string)$res['message'], 'system');
        ppms_set_flash(!empty($res['ok']) ? 'success' : 'error', (string)$res['message']);
        header('Location: ' . get_site_url('ppms/updates'), true, 302);
        exit;
    }
}

$vcsConsole = null;
if (!empty($_SESSION['ppms_vcs_console']) && is_array($_SESSION['ppms_vcs_console'])) {
    $vcsConsole = $_SESSION['ppms_vcs_console'];
    unset($_SESSION['ppms_vcs_console']);
}

$vcs = localneta_vcs_status();
$excludedItems = localneta_vcs_excluded_items();
$hasSecretWarning = false;
foreach ($excludedItems as $exItem) {
    if (!empty($exItem['is_tracked_warning'])) {
        $hasSecretWarning = true;
        break;
    }
}

$appMeta = $vcs['app'] ?? localneta_app_version();
$appVer = (string)($appMeta['version'] ?? '1.2.0');
$releasedAt = (string)($appMeta['released_at'] ?? '');
$repoDisplayUrl = (string)($vcs['origin_url'] ?: $vcs['expected_repo']);
$repoBrowseUrl = preg_replace('/\.git$/i', '', $repoDisplayUrl);

// Recent GitHub / System sync logs
$syncLogsStmt = $pdo->query("
    SELECT * FROM ppms_audit_logs
    WHERE entity_type = 'system'
    ORDER BY created_at DESC, id DESC
    LIMIT 8
");
$syncLogs = $syncLogsStmt ? $syncLogsStmt->fetchAll() : [];

$statusBadgeMap = [
    'up_to_date' => ['bg' => 'ppms-badge-active', 'icon' => 'fa-check-circle'],
    'update_available' => ['bg' => 'ppms-badge-inactive', 'icon' => 'fa-cloud-download-alt'],
    'ahead' => ['bg' => 'ppms-badge-national', 'icon' => 'fa-upload'],
    'diverged' => ['bg' => 'ppms-badge-delisted', 'icon' => 'fa-code-branch'],
    'no_remote_ref' => ['bg' => 'ppms-badge-registered', 'icon' => 'fa-clock'],
    'no_commits' => ['bg' => 'ppms-badge-inactive', 'icon' => 'fa-code-commit'],
    'not_initialized' => ['bg' => 'ppms-badge-delisted', 'icon' => 'fa-exclamation-triangle'],
    'git_missing' => ['bg' => 'ppms-badge-delisted', 'icon' => 'fa-ban'],
];
$stateMeta = $statusBadgeMap[$vcs['status_state']] ?? ['bg' => 'ppms-badge-registered', 'icon' => 'fa-info-circle'];
$pullBlocked = !$vcs['git_available'] || !$vcs['is_repo'] || !empty($vcs['dirty_files']) || ($vcs['ahead_count'] > 0 && $vcs['behind_count'] > 0);

$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];
$activeNav = 'updates';
$ppmsPageTitle = 'Version Control & GitHub Sync';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Hero Card -->
<div class="ppms-card" style="border-top:4px solid var(--ppms-saffron);">
    <div class="ppms-card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h2 class="h5 fw-bold mb-0" style="color:var(--ppms-navy);">
                    <i class="fab fa-github me-1"></i> Version Control &amp; GitHub Sync
                </h2>
                <span class="badge bg-dark">v<?= e($appVer) ?></span>
                <span class="ppms-badge <?= e($stateMeta['bg']) ?>">
                    <i class="fas <?= e($stateMeta['icon']) ?>"></i> <?= e($vcs['status_label']) ?>
                </span>
            </div>
            <p class="small text-muted mb-0">
                Public Repository:
                <a href="<?= e((string)$repoBrowseUrl) ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                    <?= e($repoDisplayUrl) ?> <i class="fas fa-external-link-alt ms-1" style="font-size:0.72rem;"></i>
                </a>
                &bull; Branch: <code><?= e($vcs['branch']) ?></code>
                <?php if ($releasedAt !== ''): ?>&bull; Release: <strong><?= e($releasedAt) ?></strong><?php endif; ?>
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <form method="POST" action="<?= e(get_site_url('ppms/updates')) ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                <input type="hidden" name="action" value="fetch">
                <button type="submit" class="btn btn-ppms-navy btn-sm px-3 py-2" <?= (!$vcs['git_available'] || !$vcs['is_repo']) ? 'disabled' : '' ?>>
                    <i class="fas fa-sync-alt me-1"></i> Check GitHub Updates
                </button>
            </form>

            <form method="POST" action="<?= e(get_site_url('ppms/updates')) ?>" class="m-0"
                  onsubmit="return confirm('Pull latest fast-forward commits from origin/<?= e($vcs['branch']) ?> into this server? Tracked local files must remain clean.');">
                <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                <input type="hidden" name="action" value="pull">
                <button type="submit" class="btn btn-ppms-green btn-sm px-3 py-2" <?= $pullBlocked ? 'disabled' : '' ?>>
                    <i class="fas fa-cloud-download-alt me-1"></i>
                    <?= $vcs['behind_count'] > 0 ? ('Pull ' . (int)$vcs['behind_count'] . ' Update' . ($vcs['behind_count'] === 1 ? '' : 's')) : 'Pull Latest Updates' ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-navy);">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">App Version</span>
                <span class="ppms-kpi-icon" style="background:#EEF2FF; color:var(--ppms-navy);"><i class="fas fa-tag"></i></span>
            </div>
            <div class="ppms-kpi-value">v<?= e($appVer) ?></div>
            <div class="small text-muted mt-2"><?= e((string)($appMeta['codename'] ?? 'LocalNeta')) ?></div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-saffron);">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Local Commit (HEAD)</span>
                <span class="ppms-kpi-icon" style="background:#FFF3EB; color:var(--ppms-saffron);"><i class="fas fa-code-commit"></i></span>
            </div>
            <div class="ppms-kpi-value"><code><?= e($vcs['head_short'] ?: 'NONE') ?></code></div>
            <div class="small text-muted mt-2 text-truncate" title="<?= e($vcs['head_subject'] ?: '') ?>">
                <?= e($vcs['head_subject'] ?: 'Waiting for initial commit') ?>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid #0284C7;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Remote (<?= e($vcs['tracking_ref']) ?>)</span>
                <span class="ppms-kpi-icon" style="background:#E0F2FE; color:#0284C7;"><i class="fab fa-github"></i></span>
            </div>
            <div class="ppms-kpi-value"><code><?= e($vcs['remote_head_short'] ?: 'UNSYNCED') ?></code></div>
            <div class="small text-muted mt-2">
                Behind: <strong><?= (int)$vcs['behind_count'] ?></strong> &bull; Ahead: <strong><?= (int)$vcs['ahead_count'] ?></strong>
            </div>
        </div>
    </div>

    <div class="col-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid <?= $hasSecretWarning ? '#DC2626' : 'var(--ppms-green)' ?>;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Public Repo Shield</span>
                <span class="ppms-kpi-icon" style="background:<?= $hasSecretWarning ? '#FEE2E2; color:#DC2626' : '#EAF7F0; color:var(--ppms-green)' ?>;">
                    <i class="fas fa-shield-alt"></i>
                </span>
            </div>
            <div class="ppms-kpi-value" style="color:<?= $hasSecretWarning ? '#B91C1C' : 'var(--ppms-green)' ?>;">
                <?= $hasSecretWarning ? 'Check Alert' : 'Protected' ?>
            </div>
            <div class="small text-muted mt-2">
                Dirty tracked: <strong><?= count($vcs['dirty_files']) ?></strong> &bull; Excluded rules: <strong><?= count($excludedItems) ?></strong>
            </div>
        </div>
    </div>
</div>

<?php if ($vcsConsole): ?>
    <div class="ppms-card">
        <div class="ppms-card-header">
            <h3 class="ppms-card-title">
                <i class="fas fa-terminal <?= $vcsConsole['ok'] ? 'text-success' : 'text-danger' ?>"></i>
                Last Git Operation Output
            </h3>
            <span class="small text-muted"><code><?= e($vcsConsole['action']) ?></code> &bull; <?= e($vcsConsole['time']) ?></span>
        </div>
        <div class="ppms-card-body p-0">
            <pre class="m-0 p-3 bg-dark text-light small" style="white-space:pre-wrap; word-break:break-word; max-height:240px; overflow:auto;"><?= e($vcsConsole['output'] ?: 'Done.') ?></pre>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($vcs['dirty_files'])): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <div class="fw-bold mb-1"><i class="fas fa-exclamation-triangle me-1"></i> Uncommitted Tracked Changes Detected (Pull Safely Paused)</div>
        <div class="small mb-2">Fast-forward GitHub pulls require clean tracked files so local changes are never overwritten:</div>
        <ul class="small mb-0 ps-3">
            <?php foreach (array_slice($vcs['dirty_files'], 0, 12) as $df): ?>
                <li><code>[<?= e($df['code']) ?>]</code> <code><?= e($df['path']) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($vcs['incoming_commits'])): ?>
    <div class="ppms-card" style="border-left:4px solid var(--ppms-saffron);">
        <div class="ppms-card-header">
            <h3 class="ppms-card-title"><i class="fas fa-cloud-download-alt text-warning"></i> Incoming Commits Waiting on GitHub (<?= count($vcs['incoming_commits']) ?>)</h3>
        </div>
        <div class="table-responsive">
            <table class="table ppms-table mb-0">
                <thead>
                    <tr>
                        <th>Commit</th>
                        <th>Message</th>
                        <th>Author</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vcs['incoming_commits'] as $ic): ?>
                        <tr>
                            <td><code><?= e($ic['short']) ?></code></td>
                            <td class="fw-semibold text-dark"><?= e($ic['subject']) ?></td>
                            <td class="small text-muted"><?= e($ic['author']) ?></td>
                            <td class="small text-muted text-nowrap"><?= e($ic['date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Explicit Excluded List (Not Pushed to Public Repo) -->
<div class="ppms-card">
    <div class="ppms-card-header">
        <h3 class="ppms-card-title"><i class="fas fa-user-secret text-danger"></i> Files &amp; Folders NOT Pushed to Public GitHub</h3>
        <span class="small text-muted">Enforced via <code>.gitignore</code> &amp; web server <code>403</code> rules</span>
    </div>
    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Excluded Path / Pattern</th>
                    <th>Why Excluded from Public Repo</th>
                    <th class="text-nowrap">Local Disk Status</th>
                    <th class="text-end">Git Shield</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($excludedItems as $ex): ?>
                    <tr>
                        <td class="fw-bold text-dark text-nowrap"><?= e($ex['category']) ?></td>
                        <td><code class="small"><?= e($ex['pattern']) ?></code></td>
                        <td class="small text-secondary"><?= e($ex['reason']) ?></td>
                        <td class="small text-muted text-nowrap">
                            <?php if ($ex['exists_on_disk']): ?>
                                <strong><?= (int)$ex['file_count'] ?></strong> file<?= (int)$ex['file_count'] === 1 ? '' : 's' ?> &bull; <?= e($ex['size_label']) ?>
                            <?php else: ?>
                                <span>None on disk</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($ex['is_tracked_warning']): ?>
                                <span class="ppms-badge ppms-badge-delisted"><i class="fas fa-exclamation-circle"></i> Tracked Alert</span>
                            <?php elseif ($ex['is_ignored']): ?>
                                <span class="ppms-badge ppms-badge-active"><i class="fas fa-shield-halved"></i> Ignored &amp; Safe</span>
                            <?php else: ?>
                                <span class="ppms-badge ppms-badge-inactive"><i class="fas fa-clock"></i> Pending Git Init</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Local Commit History & Sync Audit Trail -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-code-branch text-primary"></i> Local Commit History</h3>
                <?php if ($vcs['git_version'] !== ''): ?>
                    <span class="small text-muted"><?= e($vcs['git_version']) ?></span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table ppms-table mb-0">
                    <thead>
                        <tr>
                            <th>SHA</th>
                            <th>Commit Summary</th>
                            <th>Author</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vcs['recent_commits'])): ?>
                            <tr><td colspan="4" class="text-center py-4 text-muted">No Git commits recorded in this directory yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vcs['recent_commits'] as $cm): ?>
                                <tr>
                                    <td><code><?= e($cm['short']) ?></code></td>
                                    <td class="small fw-semibold text-dark"><?= e($cm['subject']) ?></td>
                                    <td class="small text-muted"><?= e($cm['author']) ?></td>
                                    <td class="small text-muted text-nowrap"><?= e(date('d M Y, H:i', strtotime($cm['date']) ?: time())) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-history text-success"></i> Recent GitHub Sync Logs</h3>
                <a href="<?= e(get_site_url('ppms/logs?entity_type=system')) ?>" class="btn btn-light border btn-sm fw-semibold">All Logs</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($syncLogs)): ?>
                    <div class="list-group-item text-muted small py-4 text-center">
                        No GitHub fetch or pull operations logged yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($syncLogs as $sl): ?>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-light text-dark border" style="font-size:0.68rem;"><?= e($sl['action']) ?></span>
                                <small class="text-muted" style="font-size:0.72rem;"><?= e(date('d M, H:i', strtotime((string)$sl['created_at']))) ?></small>
                            </div>
                            <div class="small fw-semibold text-dark mt-1"><?= e($sl['details'] ?: $sl['party_name'] ?: '-') ?></div>
                            <div class="text-muted" style="font-size:0.72rem;">By @<?= e($sl['username']) ?> &bull; <?= e($sl['party_name'] ?: 'origin') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>

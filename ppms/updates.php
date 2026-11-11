<?php
// ppms/updates.php - Clean Superadmin GitHub Updates Console for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/vcs.php';

$adminUser = ppms_require_auth();
if (($adminUser['role'] ?? '') !== 'superadmin') {
    ppms_set_flash('error', 'Only a Superadmin can manage system updates.');
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
        $targetLabel = 'origin/' . ($st['branch'] ?? localneta_github_branch());
        ppms_log_action($pdo, $adminUser, 'GITHUB_FETCH', null, $targetLabel, (string)$res['message'], 'system');
        ppms_set_flash(!empty($res['ok']) ? 'success' : 'error', (string)$res['message']);
        header('Location: ' . get_site_url('ppms/updates'), true, 302);
        exit;
    }

    if ($action === 'pull') {
        $res = localneta_vcs_pull();
        $st = $res['status'] ?? [];
        if (!empty($res['ok'])) {
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

$vcs = localneta_vcs_status();
$caps = $vcs['caps'] ?? [];
$syncMode = (string)($vcs['sync_mode'] ?? 'unavailable');
$excludedItems = localneta_vcs_excluded_items();
$trackedSecrets = [];
foreach ($excludedItems as $exItem) {
    if (!empty($exItem['is_tracked_warning'])) {
        $trackedSecrets[] = (string)$exItem['category'] . ' (' . (string)$exItem['pattern'] . ')';
    }
}
$hasSecretWarning = !empty($trackedSecrets);

$appMeta = $vcs['app'] ?? localneta_app_version();
$appVer = (string)($appMeta['version'] ?? '1.2.1');
$releasedAt = (string)($appMeta['released_at'] ?? '');
$repoDisplayUrl = (string)($vcs['origin_url'] ?: $vcs['expected_repo']);
$repoBrowseUrl = preg_replace('/\.git$/i', '', $repoDisplayUrl);
$repoShortLabel = preg_replace('#^https?://github\.com/#i', '', (string)$repoBrowseUrl);

$syncLogsStmt = $pdo->query("
    SELECT * FROM ppms_audit_logs
    WHERE entity_type = 'system'
    ORDER BY created_at DESC, id DESC
    LIMIT 6
");
$syncLogs = $syncLogsStmt ? $syncLogsStmt->fetchAll() : [];

$statusBadgeMap = [
    'up_to_date' => ['bg' => 'ppms-badge-active', 'icon' => 'fa-check-circle'],
    'update_available' => ['bg' => 'ppms-badge-inactive', 'icon' => 'fa-cloud-download-alt'],
    'https_ready' => ['bg' => 'ppms-badge-registered', 'icon' => 'fa-cloud'],
    'ahead' => ['bg' => 'ppms-badge-national', 'icon' => 'fa-upload'],
    'diverged' => ['bg' => 'ppms-badge-delisted', 'icon' => 'fa-code-branch'],
    'no_remote_ref' => ['bg' => 'ppms-badge-registered', 'icon' => 'fa-clock'],
    'no_commits' => ['bg' => 'ppms-badge-inactive', 'icon' => 'fa-code-commit'],
    'git_missing' => ['bg' => 'ppms-badge-delisted', 'icon' => 'fa-ban'],
    'unavailable' => ['bg' => 'ppms-badge-delisted', 'icon' => 'fa-ban'],
];
$stateMeta = $statusBadgeMap[$vcs['status_state']] ?? ['bg' => 'ppms-badge-registered', 'icon' => 'fa-info-circle'];

$fetchBlocked = ($syncMode === 'unavailable');
$pullBlocked = ($syncMode === 'unavailable')
    || ($syncMode === 'git_cli' && (!empty($vcs['dirty_files']) || ($vcs['ahead_count'] > 0 && $vcs['behind_count'] > 0)))
    || ($syncMode === 'github_https' && empty($caps['zip_ready']));

$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];
$activeNav = 'updates';
$ppmsPageTitle = 'GitHub Updates';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Clean Update Action Bar -->
<div class="ppms-card" style="border-top:4px solid var(--ppms-saffron);">
    <div class="ppms-card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <h2 class="h5 fw-bold mb-0" style="color:var(--ppms-navy);">
                    <i class="fab fa-github me-1"></i> GitHub Updates
                </h2>
                <span class="badge bg-dark">v<?= e($appVer) ?></span>
                <span class="ppms-badge <?= e($stateMeta['bg']) ?>">
                    <i class="fas <?= e($stateMeta['icon']) ?>"></i> <?= e($vcs['status_label']) ?>
                </span>
            </div>
            <p class="small text-muted mb-0">
                <a href="<?= e((string)$repoBrowseUrl) ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                    <?= e($repoShortLabel) ?> <i class="fas fa-external-link-alt ms-1" style="font-size:0.7rem;"></i>
                </a>
                &bull; Branch: <strong><?= e($vcs['branch']) ?></strong>
                <?php if ($releasedAt !== ''): ?>&bull; Released <?= e(date('d M Y', strtotime($releasedAt) ?: time())) ?><?php endif; ?>
                <?php if (!empty($vcs['last_fetched_at'])): ?>&bull; Last checked <?= e(date('d M, H:i', strtotime((string)$vcs['last_fetched_at']) ?: time())) ?><?php endif; ?>
            </p>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <form method="POST" action="<?= e(get_site_url('ppms/updates')) ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                <input type="hidden" name="action" value="fetch">
                <button type="submit" class="btn btn-ppms-navy btn-sm px-3 py-2" <?= $fetchBlocked ? 'disabled' : '' ?>>
                    <i class="fas fa-sync-alt me-1"></i> Check Updates
                </button>
            </form>

            <form method="POST" action="<?= e(get_site_url('ppms/updates')) ?>" class="m-0"
                  onsubmit="return confirm('Pull latest updates from GitHub into this server? Local configs, database dumps, and uploads remain protected.');">
                <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                <input type="hidden" name="action" value="pull">
                <button type="submit" class="btn btn-ppms-green btn-sm px-3 py-2" <?= $pullBlocked ? 'disabled' : '' ?>>
                    <i class="fas fa-cloud-download-alt me-1"></i>
                    <?= $vcs['behind_count'] > 0 ? ('Pull ' . (int)$vcs['behind_count'] . ' Update' . ($vcs['behind_count'] === 1 ? '' : 's')) : 'Pull Updates' ?>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Compact Summary Row -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-navy);">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Installed Version</span>
                <span class="ppms-kpi-icon" style="background:#EEF2FF; color:var(--ppms-navy);"><i class="fas fa-tag"></i></span>
            </div>
            <div class="ppms-kpi-value">v<?= e($appVer) ?></div>
            <div class="small text-muted mt-2 text-truncate">
                <?= !empty($vcs['head_short']) ? ('Commit ' . e($vcs['head_short']) . (!empty($vcs['head_subject']) ? ' &bull; ' . e($vcs['head_subject']) : '')) : e((string)($appMeta['codename'] ?? 'LocalNeta')) ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="ppms-kpi-card" style="border-top:3px solid #0284C7;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Remote Status</span>
                <span class="ppms-kpi-icon" style="background:#E0F2FE; color:#0284C7;"><i class="fab fa-github"></i></span>
            </div>
            <div class="ppms-kpi-value">
                <?php if ($vcs['behind_count'] > 0): ?>
                    <?= (int)$vcs['behind_count'] ?> Update<?= (int)$vcs['behind_count'] === 1 ? '' : 's' ?> Ready
                <?php elseif (!empty($vcs['remote_version'])): ?>
                    v<?= e((string)$vcs['remote_version']) ?>
                <?php elseif (!empty($vcs['remote_head_short'])): ?>
                    <?= e((string)$vcs['remote_head_short']) ?>
                <?php else: ?>
                    Ready
                <?php endif; ?>
            </div>
            <div class="small text-muted mt-2">
                <?= $vcs['behind_count'] > 0 ? 'Click Pull Updates to apply latest changes' : 'Click Check Updates anytime to verify GitHub' ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="ppms-kpi-card" style="border-top:3px solid <?= $hasSecretWarning ? '#DC2626' : 'var(--ppms-green)' ?>;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Data Safety</span>
                <span class="ppms-kpi-icon" style="background:<?= $hasSecretWarning ? '#FEE2E2; color:#DC2626' : '#EAF7F0; color:var(--ppms-green)' ?>;">
                    <i class="fas fa-shield-alt"></i>
                </span>
            </div>
            <div class="ppms-kpi-value" style="color:<?= $hasSecretWarning ? '#B91C1C' : 'var(--ppms-green)' ?>;">
                <?= $hasSecretWarning ? 'Check Alert' : 'Protected' ?>
            </div>
            <div class="small text-muted mt-2">
                Configs, SQL backups &amp; uploads are preserved during sync
            </div>
        </div>
    </div>
</div>

<!-- Alerts shown only when action or attention is needed -->
<?php if ($hasSecretWarning): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <strong>Sensitive Path Tracked:</strong> Remove <?= e(implode(', ', $trackedSecrets)) ?> from Git tracking before pushing to the public repository.
    </div>
<?php endif; ?>

<?php if ($syncMode === 'unavailable'): ?>
    <div class="alert alert-danger border-0 shadow-sm mb-4">
        <i class="fas fa-ban me-1"></i>
        <strong>GitHub Sync Unavailable:</strong> <?= e((string)($caps['reason'] ?? 'Outbound HTTP requests or Git commands are unavailable on this server.')) ?>
    </div>
<?php elseif ($syncMode === 'github_https' && empty($caps['zip_ready'])): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <i class="fas fa-exclamation-triangle me-1"></i>
        <strong>ZipArchive Required:</strong> Enable the PHP <code>zip</code> extension in your hosting control panel to allow one-click updates.
    </div>
<?php endif; ?>

<?php if ($syncMode === 'git_cli' && !empty($vcs['dirty_files'])): ?>
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <div class="fw-bold mb-1"><i class="fas fa-exclamation-triangle me-1"></i> Local File Changes Detected</div>
        <div class="small mb-2">Updates are paused until local tracked modifications are committed or reverted:</div>
        <ul class="small mb-0 ps-3">
            <?php foreach (array_slice($vcs['dirty_files'], 0, 8) as $df): ?>
                <li><code><?= e($df['path']) ?></code></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!empty($vcs['incoming_commits'])): ?>
    <div class="ppms-card" style="border-left:4px solid var(--ppms-saffron);">
        <div class="ppms-card-header">
            <h3 class="ppms-card-title"><i class="fas fa-cloud-download-alt text-warning"></i> Pending Updates (<?= count($vcs['incoming_commits']) ?>)</h3>
        </div>
        <div class="table-responsive">
            <table class="table ppms-table mb-0">
                <thead>
                    <tr>
                        <th>Commit</th>
                        <th>Summary</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vcs['incoming_commits'] as $ic): ?>
                        <tr>
                            <td><code><?= e($ic['short']) ?></code></td>
                            <td class="fw-semibold text-dark"><?= e($ic['subject']) ?></td>
                            <td class="small text-muted text-nowrap"><?= e($ic['date'] ? date('d M Y, H:i', strtotime((string)$ic['date']) ?: time()) : '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Clean Commit History & Sync Activity -->
<div class="row g-3">
    <div class="col-lg-7">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title">
                    <i class="fas fa-code-branch text-primary"></i> Recent Commits
                </h3>
            </div>
            <div class="table-responsive">
                <table class="table ppms-table mb-0">
                    <thead>
                        <tr>
                            <th>Commit</th>
                            <th>Summary</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vcs['recent_commits'])): ?>
                            <tr>
                                <td colspan="3" class="text-center py-4 text-muted">
                                    Click &ldquo;Check Updates&rdquo; to load recent commits from GitHub.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (array_slice($vcs['recent_commits'], 0, 6) as $cm): ?>
                                <tr>
                                    <td><code><?= e($cm['short']) ?></code></td>
                                    <td class="small fw-semibold text-dark"><?= e($cm['subject']) ?></td>
                                    <td class="small text-muted text-nowrap"><?= e($cm['date'] ? date('d M Y, H:i', strtotime((string)$cm['date']) ?: time()) : '-') ?></td>
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
                <h3 class="ppms-card-title"><i class="fas fa-history text-success"></i> Sync Activity</h3>
                <a href="<?= e(get_site_url('ppms/logs?entity_type=system')) ?>" class="btn btn-light border btn-sm fw-semibold">All Logs</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($syncLogs)): ?>
                    <div class="list-group-item text-muted small py-4 text-center">
                        No update checks or pulls logged yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($syncLogs as $sl): ?>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-light text-dark border" style="font-size:0.68rem;">
                                    <?= $sl['action'] === 'GITHUB_PULL' ? 'Pull Update' : 'Check Updates' ?>
                                </span>
                                <small class="text-muted" style="font-size:0.72rem;"><?= e(date('d M, H:i', strtotime((string)$sl['created_at']))) ?></small>
                            </div>
                            <div class="small fw-semibold text-dark mt-1"><?= e($sl['details'] ?: '-') ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>

<?php
// ppms/logs.php - Multi-Entity Administrative Audit Log for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$actionFilter = trim((string)($_GET['action'] ?? ''));
$entityFilter = strtolower(trim((string)($_GET['entity_type'] ?? '')));
if (!in_array($entityFilter, ['', 'party', 'mp', 'mlc', 'village', 'user', 'system'], true)) {
    $entityFilter = '';
}

$where = " WHERE 1=1";
$params = [];
if ($actionFilter !== '') {
    $where .= " AND action = ?";
    $params[] = $actionFilter;
}
if ($entityFilter !== '') {
    $where .= " AND entity_type = ?";
    $params[] = $entityFilter;
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM ppms_audit_logs {$where}");
$cntStmt->execute($params);
$totalLogs = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM ppms_audit_logs {$where} ORDER BY created_at DESC, id DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$entityTargetUrl = static function (string $eType, int $eId, string $act): ?string {
    if ($eId <= 0 || $act === 'DELETE') {
        return null;
    }
    return match ($eType) {
        'party' => get_site_url('ppms/party-form?id=' . $eId),
        'mp' => get_site_url('ppms/mp-form?id=' . $eId),
        'mlc' => get_site_url('ppms/mlc-form?id=' . $eId),
        'village' => get_site_url('ppms/village-form?id=' . $eId),
        default => null,
    };
};

$entityBadgeClass = static function (string $eType): string {
    return match ($eType) {
        'mp' => 'bg-danger-subtle text-danger border-danger-subtle',
        'mlc' => 'bg-primary-subtle text-primary border-primary-subtle',
        'village' => 'bg-success-subtle text-success border-success-subtle',
        'user' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
        'system' => 'bg-info-subtle text-info-emphasis border-info-subtle',
        default => 'bg-secondary-subtle text-dark border-secondary-subtle',
    };
};

$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];
$activeNav = 'logs';
$ppmsPageTitle = 'PPMS Audit Logs';
require_once __DIR__ . '/includes/layout_top.php';
?>

<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <h2 class="ppms-card-title"><i class="fas fa-history text-primary"></i> Activity &amp; Change Audit Trail (<?= number_format($totalLogs) ?>)</h2>
        <form method="GET" action="<?= e(get_site_url('ppms/logs')) ?>" class="d-flex gap-2 flex-wrap">
            <select name="entity_type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Entities</option>
                <?php foreach (['party' => 'Political Parties', 'mp' => 'MPs', 'mlc' => 'MLCs', 'village' => 'Villages', 'user' => 'Admin Users', 'system' => 'System / GitHub'] as $ek => $elbl): ?>
                    <option value="<?= e($ek) ?>" <?= $entityFilter === $ek ? 'selected' : '' ?>><?= e($elbl) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="action" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Actions</option>
                <?php foreach (['LOGIN', 'LOGOUT', 'CREATE', 'UPDATE', 'STATUS_CHANGE', 'DELETE', 'SYMBOL_UPLOAD', 'USER_UPDATE', 'PASSWORD_CHANGE', 'GITHUB_FETCH', 'GITHUB_PULL'] as $act): ?>
                    <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>><?= e($act) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($actionFilter !== '' || $entityFilter !== ''): ?>
                <a href="<?= e(get_site_url('ppms/logs')) ?>" class="btn btn-light border btn-sm" title="Reset"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Operator</th>
                    <th>Entity</th>
                    <th>Action</th>
                    <th>Record / Target</th>
                    <th>Details</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">No audit logs recorded yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $lg):
                        $eType = strtolower(trim((string)($lg['entity_type'] ?? 'party'))) ?: 'party';
                        $eId = (int)(($lg['entity_id'] ?? 0) ?: ($lg['party_id'] ?? 0));
                        $targetUrl = $entityTargetUrl($eType, $eId, (string)$lg['action']);
                    ?>
                        <tr>
                            <td class="text-nowrap small text-muted"><?= e(date('d M Y, H:i:s', strtotime((string)$lg['created_at']))) ?></td>
                            <td class="fw-semibold">@<?= e($lg['username']) ?></td>
                            <td>
                                <span class="badge border <?= $entityBadgeClass($eType) ?>" style="font-size:0.7rem;">
                                    <?= e(strtoupper($eType)) ?>
                                </span>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($lg['action']) ?></span></td>
                            <td>
                                <?php if ($targetUrl): ?>
                                    <a href="<?= e($targetUrl) ?>" class="text-decoration-none fw-semibold text-dark">
                                        #<?= $eId ?> <?= e($lg['party_name'] ?: '') ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-secondary small">
                                        <?= $eId > 0 ? '#' . $eId . ' ' : '' ?><?= e($lg['party_name'] ?: '-') ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary"><?= e($lg['details'] ?: '-') ?></td>
                            <td class="small text-muted"><code><?= e($lg['ip_address'] ?: '-') ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="ppms-card-body border-top py-3 d-flex justify-content-between align-items-center">
            <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
            <div class="d-flex gap-1">
                <?php if ($page > 1): ?>
                    <a href="<?= e(get_site_url('ppms/logs?' . http_build_query(array_filter(['entity_type' => $entityFilter, 'action' => $actionFilter, 'page' => $page - 1])))) ?>" class="btn btn-light border btn-sm">&lsaquo; Prev</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(get_site_url('ppms/logs?' . http_build_query(array_filter(['entity_type' => $entityFilter, 'action' => $actionFilter, 'page' => $page + 1])))) ?>" class="btn btn-light border btn-sm">Next &rsaquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>

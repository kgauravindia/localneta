<?php
// ppms/mps.php - Parliament MPs (Lok Sabha & Rajya Sabha) CRUD Registry for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

// Handle POST Actions (Quick Membership Status Switch & Safe Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $returnQs = trim((string)($_POST['return_qs'] ?? ''));
    $redirectTarget = get_site_url('ppms/mps' . ($returnQs !== '' ? '?' . ltrim($returnQs, '?') : ''));

    if (!ppms_verify_csrf($csrf)) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $mpId = (int)($_POST['mp_id'] ?? 0);

    if ($action === 'change_status' && $mpId > 0) {
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        if (!in_array($newStatus, ['Sitting', 'Died', 'Resigned', 'Former'], true)) {
            ppms_set_flash('error', 'Invalid MP membership status selected.');
        } else {
            $curStmt = $pdo->prepare("SELECT id, member_name, membership_status FROM mp WHERE id = ? LIMIT 1");
            $curStmt->execute([$mpId]);
            $curRow = $curStmt->fetch();
            if ($curRow) {
                $oldStatus = (string)$curRow['membership_status'];
                $upd = $pdo->prepare("UPDATE mp SET membership_status = ? WHERE id = ?");
                $upd->execute([$newStatus, $mpId]);
                $mName = clean_mp_display_name($curRow['member_name']);
                ppms_log_action(
                    $pdo,
                    $adminUser,
                    'STATUS_CHANGE',
                    $mpId,
                    $mName,
                    "Membership status {$oldStatus} -> {$newStatus}",
                    'mp'
                );
                ppms_set_flash('success', "MP #{$mpId} ({$mName}) status updated to {$newStatus}.");
            } else {
                ppms_set_flash('error', "MP #{$mpId} was not found.");
            }
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    if ($action === 'delete_mp' && $mpId > 0) {
        $curStmt = $pdo->prepare("SELECT id, member_name, constituency, type, state FROM mp WHERE id = ? LIMIT 1");
        $curStmt->execute([$mpId]);
        $curRow = $curStmt->fetch();
        if ($curRow) {
            $del = $pdo->prepare("DELETE FROM mp WHERE id = ? LIMIT 1");
            $del->execute([$mpId]);
            $mName = clean_mp_display_name($curRow['member_name']);
            $houseLabel = $curRow['type'] === 'rs' ? 'Rajya Sabha' : 'Lok Sabha';
            ppms_log_action(
                $pdo,
                $adminUser,
                'DELETE',
                $mpId,
                $mName,
                "Deleted {$houseLabel} MP ({$curRow['constituency']}, {$curRow['state']})",
                'mp'
            );
            ppms_set_flash('success', "Deleted MP #{$mpId}: {$mName}");
        } else {
            ppms_set_flash('error', "MP #{$mpId} was not found.");
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }
}

// GET Filters
$filterType = trim((string)($_GET['type'] ?? ''));
if (!in_array($filterType, ['', 'ls', 'rs'], true)) {
    $filterType = '';
}
$filterStatus = trim((string)($_GET['membership_status'] ?? ''));
if (!in_array($filterStatus, ['', 'Sitting', 'Died', 'Resigned', 'Former'], true)) {
    $filterStatus = '';
}
$filterState = trim((string)($_GET['state_code'] ?? ''));
$filterQuery = trim((string)($_GET['q'] ?? ''));
$sortKey = trim((string)($_GET['sort'] ?? 'hierarchy'));
if (!in_array($sortKey, ['hierarchy', 'newest', 'name_asc', 'state', 'id_asc'], true)) {
    $sortKey = 'hierarchy';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$mpSummary = ppms_mp_summary($pdo);
$ppmsTotalMps = $mpSummary['total_rows'];
$stateOptions = ppms_get_states($pdo);

$where = " WHERE 1=1";
$params = [];

if ($filterType !== '') {
    $where .= " AND m.type = ?";
    $params[] = $filterType;
}
if ($filterStatus !== '') {
    $where .= " AND m.membership_status = ?";
    $params[] = $filterStatus;
}
if ($filterState !== '') {
    if ($filterState === 'NOMINATED') {
        $where .= " AND (m.state_code IS NULL OR m.state_code = '' OR LOWER(TRIM(m.state)) = 'nominated')";
    } else {
        $where .= " AND m.state_code = ?";
        $params[] = $filterState;
    }
}
if ($filterQuery !== '') {
    if (ctype_digit($filterQuery)) {
        $where .= " AND (m.id = ? OR m.member_name LIKE ? OR m.constituency LIKE ? OR m.party_name LIKE ? OR COALESCE(m.election, '') LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, (int)$filterQuery, $wild, $wild, $wild, $wild);
    } else {
        $where .= " AND (m.member_name LIKE ? OR m.slug LIKE ? OR m.constituency LIKE ? OR m.party_name LIKE ? OR m.state LIKE ? OR COALESCE(s.state, '') LIKE ? OR COALESCE(m.election, '') LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }
}

$cntSql = "
    SELECT COUNT(*)
    FROM mp m
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON m.state_code = s.state_code
    {$where}
";
$cntStmt = $pdo->prepare($cntSql);
$cntStmt->execute($params);
$filteredTotal = (int)$cntStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$orderBySql = match ($sortKey) {
    'newest' => "m.id DESC",
    'id_asc' => "m.id ASC",
    'name_asc' => "m.member_name ASC, m.id ASC",
    'state' => "state_display ASC, m.constituency ASC, m.member_name ASC",
    default => "(m.type = 'ls') DESC, (m.membership_status = 'Sitting') DESC, state_display ASC, m.constituency ASC, m.member_name ASC, m.id ASC",
};

$dataSql = "
    SELECT m.*,
           COALESCE(s.state, m.state) AS state_display
    FROM mp m
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON m.state_code = s.state_code
    {$where}
    ORDER BY {$orderBySql}
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$currentFilterParams = array_filter([
    'type' => $filterType,
    'membership_status' => $filterStatus,
    'state_code' => $filterState,
    'q' => $filterQuery,
    'sort' => $sortKey !== 'hierarchy' ? $sortKey : '',
], static fn($v): bool => $v !== '');
$returnQs = http_build_query($currentFilterParams + ($page > 1 ? ['page' => $page] : []));

$activeNav = 'mps';
$ppmsPageTitle = 'Manage Parliament MPs';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Filter & Quick Tabs Card -->
<div class="ppms-card">
    <div class="ppms-card-header">
        <div class="d-flex flex-wrap gap-2">
            <?php
            $diedCount = (int)$pdo->query("SELECT SUM(membership_status = 'Died') FROM mp")->fetchColumn();
            $resignedCount = (int)$pdo->query("SELECT SUM(membership_status = 'Resigned') FROM mp")->fetchColumn();
            $quickTabs = [
                ['label' => 'All MPs (' . number_format($mpSummary['total_rows']) . ')', 'type' => '', 'status' => ''],
                ['label' => 'Lok Sabha (' . number_format($mpSummary['ls_count']) . ')', 'type' => 'ls', 'status' => ''],
                ['label' => 'Rajya Sabha (' . number_format($mpSummary['rs_count']) . ')', 'type' => 'rs', 'status' => ''],
                ['label' => 'Sitting (' . number_format($mpSummary['sitting_count']) . ')', 'type' => '', 'status' => 'Sitting'],
                ['label' => 'Died (' . number_format($diedCount) . ')', 'type' => '', 'status' => 'Died'],
                ['label' => 'Resigned (' . number_format($resignedCount) . ')', 'type' => '', 'status' => 'Resigned'],
            ];
            foreach ($quickTabs as $qt):
                $isAct = ($filterType === $qt['type'] && $filterStatus === $qt['status'] && $filterState === '' && $filterQuery === '');
                $qtParams = array_filter(['type' => $qt['type'], 'membership_status' => $qt['status']]);
                $qtUrl = get_site_url('ppms/mps' . (!empty($qtParams) ? '?' . http_build_query($qtParams) : ''));
            ?>
                <a href="<?= e($qtUrl) ?>" class="btn btn-sm rounded-pill fw-bold <?= $isAct ? 'btn-ppms-saffron' : 'btn-light border text-secondary' ?>" style="font-size:0.78rem;">
                    <?= e($qt['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="<?= e(get_site_url('ppms/mp-form')) ?>" class="btn btn-ppms-green btn-sm px-3">
            <i class="fas fa-plus me-1"></i> New MP
        </a>
    </div>

    <div class="ppms-card-body">
        <form method="GET" action="<?= e(get_site_url('ppms/mps')) ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary mb-1">Search (Name / Constituency / Party / Slug / ID)</label>
                <input type="text" name="q" value="<?= e($filterQuery) ?>" class="form-control form-control-sm" placeholder="e.g. Rajiv Pratap Rudy, Saran, BJP, 25...">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">House</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Houses</option>
                    <option value="ls" <?= $filterType === 'ls' ? 'selected' : '' ?>>Lok Sabha (LS)</option>
                    <option value="rs" <?= $filterType === 'rs' ? 'selected' : '' ?>>Rajya Sabha (RS)</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">Status</label>
                <select name="membership_status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['Sitting', 'Died', 'Resigned', 'Former'] as $stOpt): ?>
                        <option value="<?= e($stOpt) ?>" <?= $filterStatus === $stOpt ? 'selected' : '' ?>><?= e($stOpt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">State / UT</label>
                <select name="state_code" class="form-select form-select-sm">
                    <option value="">All States &amp; UTs</option>
                    <option value="NOMINATED" <?= $filterState === 'NOMINATED' ? 'selected' : '' ?>>Nominated (RS)</option>
                    <?php foreach ($stateOptions as $st): ?>
                        <option value="<?= e((string)$st['state_code']) ?>" <?= $filterState === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-1">
                <select name="sort" class="form-select form-select-sm" title="Sort Order">
                    <option value="hierarchy" <?= $sortKey === 'hierarchy' ? 'selected' : '' ?>>Sort: House &amp; State</option>
                    <option value="newest" <?= $sortKey === 'newest' ? 'selected' : '' ?>>Newest ID</option>
                    <option value="name_asc" <?= $sortKey === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="state" <?= $sortKey === 'state' ? 'selected' : '' ?>>State &amp; Seat</option>
                    <option value="id_asc" <?= $sortKey === 'id_asc' ? 'selected' : '' ?>>ID Asc</option>
                </select>
                <button type="submit" class="btn btn-ppms-navy btn-sm px-2" title="Apply Filter"><i class="fas fa-filter"></i></button>
                <?php if (!empty($currentFilterParams)): ?>
                    <a href="<?= e(get_site_url('ppms/mps')) ?>" class="btn btn-light border btn-sm px-2" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Results Table Card -->
<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <span class="small text-secondary fw-semibold">
            Showing <strong><?= count($rows) ?></strong> of <strong><?= number_format($filteredTotal) ?></strong> Parliament MPs
            (Page <?= $page ?> of <?= number_format($totalPages) ?>)
        </span>
        <span class="small text-muted">Direct CRUD on <code>mp</code></span>
    </div>

    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>ID / House</th>
                    <th>Member of Parliament</th>
                    <th>Constituency &amp; State</th>
                    <th>Political Party</th>
                    <th>Status (Quick Switch)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-landmark fa-2x mb-2 d-block opacity-50"></i>
                            No MP records matched your current filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $mid = (int)$row['id'];
                        $mName = clean_mp_display_name($row['member_name']);
                        $isRs = strtolower((string)$row['type']) === 'rs';
                        $mStatus = (string)($row['membership_status'] ?: 'Sitting');
                        $publicUrl = get_mp_page_url((string)$row['slug'], $mid);
                        $stateDisp = clean_party_display_text($row['state_display'] ?: $row['state']);
                    ?>
                        <tr>
                            <td class="text-nowrap">
                                <div class="fw-bold text-dark">#<?= $mid ?></div>
                                <span class="badge <?= $isRs ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle' ?>" style="font-size:0.68rem;">
                                    <?= $isRs ? 'Rajya Sabha' : 'Lok Sabha' ?>
                                </span>
                            </td>
                            <td style="min-width:220px;">
                                <a href="<?= e(get_site_url('ppms/mp-form?id=' . $mid)) ?>" class="fw-bold text-decoration-none text-dark d-block">
                                    <?= e($mName) ?>
                                </a>
                                <div class="text-muted" style="font-size:0.72rem;">
                                    <code>/mp/<?= e($row['slug']) ?></code>
                                    <?php if (!empty($row['election'])): ?> &bull; <?= e($row['election']) ?><?php endif; ?>
                                    <?php if (!empty($row['term_period'])): ?> &bull; <?= e($row['term_period']) ?><?php endif; ?>
                                </div>
                            </td>
                            <td style="min-width:170px;">
                                <div class="fw-semibold text-dark small"><?= e($row['constituency']) ?></div>
                                <div class="text-muted" style="font-size:0.74rem;"><?= e($stateDisp ?: 'All India') ?></div>
                            </td>
                            <td class="small text-secondary" style="max-width:210px;">
                                <?= e($row['party_name']) ?>
                            </td>
                            <td>
                                <form method="POST" action="<?= e(get_site_url('ppms/mps')) ?>" class="d-flex align-items-center gap-1 m-0">
                                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="mp_id" value="<?= $mid ?>">
                                    <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                                    <select name="new_status" onchange="this.form.submit()" class="form-select form-select-sm fw-bold <?= $mStatus === 'Sitting' ? 'text-success border-success' : ($mStatus === 'Died' ? 'text-danger border-danger' : 'text-warning border-warning') ?>" style="width:118px;font-size:0.75rem;">
                                        <?php foreach (['Sitting', 'Died', 'Resigned', 'Former'] as $stOpt): ?>
                                            <option value="<?= e($stOpt) ?>" <?= $mStatus === $stOpt ? 'selected' : '' ?>><?= e($stOpt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="<?= e(get_site_url('ppms/mp-form?id=' . $mid)) ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="Edit MP">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm py-1 px-2" title="View Public MP Page">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm py-1 px-2"
                                        title="Delete MP"
                                        data-mp-id="<?= $mid ?>"
                                        data-mp-name="<?= e($mName) ?>"
                                        onclick="openDeleteMpModal(this)">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="ppms-card-body border-top py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-muted">Page <?= $page ?> of <?= number_format($totalPages) ?></div>
            <div class="d-flex gap-1 flex-wrap">
                <?php if ($page > 1): ?>
                    <a href="<?= e(get_site_url('ppms/mps?' . http_build_query($currentFilterParams + ['page' => 1]))) ?>" class="btn btn-light border btn-sm">&laquo; First</a>
                    <a href="<?= e(get_site_url('ppms/mps?' . http_build_query($currentFilterParams + ['page' => $page - 1]))) ?>" class="btn btn-light border btn-sm">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php
                $winStart = max(1, $page - 2);
                $winEnd = min($totalPages, $page + 2);
                for ($p = $winStart; $p <= $winEnd; $p++):
                ?>
                    <a href="<?= e(get_site_url('ppms/mps?' . http_build_query($currentFilterParams + ['page' => $p]))) ?>"
                       class="btn btn-sm <?= $p === $page ? 'btn-ppms-navy' : 'btn-light border' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(get_site_url('ppms/mps?' . http_build_query($currentFilterParams + ['page' => $page + 1]))) ?>" class="btn btn-light border btn-sm">Next &rsaquo;</a>
                    <a href="<?= e(get_site_url('ppms/mps?' . http_build_query($currentFilterParams + ['page' => $totalPages]))) ?>" class="btn btn-light border btn-sm">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Safe Delete Confirmation Modal -->
<div class="modal fade" id="ppmsDeleteMpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-exclamation-triangle me-2"></i> Confirm Delete Parliament MP</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small text-secondary">Are you sure you want to permanently remove this MP from <code>mp</code>?</p>
                <div class="p-2 rounded bg-light border fw-bold text-dark small" id="deleteModalMpName"></div>
                <p class="text-danger mt-2 mb-0" style="font-size:0.76rem;">This removes the public <code>/mp/</code> profile URL immediately and cannot be undone.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/mps')) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_mp">
                    <input type="hidden" name="mp_id" id="deleteModalMpId" value="">
                    <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                    <button type="submit" class="btn btn-danger btn-sm fw-bold">
                        <i class="fas fa-trash-alt me-1"></i> Delete Permanently
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$ppmsExtraScripts = <<<'HTML'
<script>
function openDeleteMpModal(btn) {
    const id = btn.getAttribute('data-mp-id') || '';
    const name = btn.getAttribute('data-mp-name') || '';
    document.getElementById('deleteModalMpId').value = id;
    document.getElementById('deleteModalMpName').textContent = '#' + id + ' — ' + name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ppmsDeleteMpModal')).show();
}
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>


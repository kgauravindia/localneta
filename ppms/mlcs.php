<?php
// ppms/mlcs.php - Members of Legislative Council (Vidhan Parishad) CRUD Registry for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

// Handle POST Actions (Quick Status Switch & Safe Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $returnQs = trim((string)($_POST['return_qs'] ?? ''));
    $redirectTarget = get_site_url('ppms/mlcs' . ($returnQs !== '' ? '?' . ltrim($returnQs, '?') : ''));

    if (!ppms_verify_csrf($csrf)) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $mlcId = (int)($_POST['mlc_id'] ?? 0);

    if ($action === 'change_status' && $mlcId > 0) {
        $newStatus = strtoupper(trim((string)($_POST['new_status'] ?? '')));
        if (!in_array($newStatus, ['ACTIVE', 'INACTIVE'], true)) {
            ppms_set_flash('error', 'Invalid MLC status selected.');
        } else {
            $curStmt = $pdo->prepare("SELECT id, member_name, status FROM mlc WHERE id = ? LIMIT 1");
            $curStmt->execute([$mlcId]);
            $curRow = $curStmt->fetch();
            if ($curRow) {
                $oldStatus = strtoupper(trim((string)$curRow['status']));
                $upd = $pdo->prepare("UPDATE mlc SET status = ? WHERE id = ?");
                $upd->execute([$newStatus, $mlcId]);
                $mName = clean_mlc_display_name($curRow['member_name']);
                ppms_log_action(
                    $pdo,
                    $adminUser,
                    'STATUS_CHANGE',
                    $mlcId,
                    $mName,
                    "Status changed {$oldStatus} -> {$newStatus}",
                    'mlc'
                );
                ppms_set_flash('success', "MLC #{$mlcId} ({$mName}) status updated to {$newStatus}.");
            } else {
                ppms_set_flash('error', "MLC #{$mlcId} was not found.");
            }
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    if ($action === 'delete_mlc' && $mlcId > 0) {
        $curStmt = $pdo->prepare("SELECT id, member_name, constituency, state FROM mlc WHERE id = ? LIMIT 1");
        $curStmt->execute([$mlcId]);
        $curRow = $curStmt->fetch();
        if ($curRow) {
            $del = $pdo->prepare("DELETE FROM mlc WHERE id = ? LIMIT 1");
            $del->execute([$mlcId]);
            $mName = clean_mlc_display_name($curRow['member_name']);
            ppms_log_action(
                $pdo,
                $adminUser,
                'DELETE',
                $mlcId,
                $mName,
                "Deleted MLC ({$curRow['constituency']}, {$curRow['state']})",
                'mlc'
            );
            ppms_set_flash('success', "Deleted MLC #{$mlcId}: {$mName}");
        } else {
            ppms_set_flash('error', "MLC #{$mlcId} was not found.");
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }
}

// GET Filters
$filterStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!in_array($filterStatus, ['', 'ACTIVE', 'INACTIVE'], true)) {
    $filterStatus = '';
}
$filterState = trim((string)($_GET['state_code'] ?? ''));
$filterCtype = trim((string)($_GET['constituency_type'] ?? ''));
$filterQuery = trim((string)($_GET['q'] ?? ''));
$sortKey = trim((string)($_GET['sort'] ?? 'hierarchy'));
if (!in_array($sortKey, ['hierarchy', 'updated', 'newest', 'name_asc', 'id_asc'], true)) {
    $sortKey = 'hierarchy';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$mlcSummary = ppms_mlc_summary($pdo);
$ppmsTotalMlcs = $mlcSummary['total_rows'];
$stateOptions = ppms_get_states($pdo);
$ctypeOptions = $pdo->query("SELECT DISTINCT constituency_type FROM mlc WHERE constituency_type <> '' ORDER BY constituency_type ASC")->fetchAll(PDO::FETCH_COLUMN);

$where = " WHERE 1=1";
$params = [];

if ($filterStatus !== '') {
    $where .= " AND UPPER(TRIM(m.status)) = ?";
    $params[] = $filterStatus;
}
if ($filterState !== '') {
    $where .= " AND m.state_code = ?";
    $params[] = $filterState;
}
if ($filterCtype !== '') {
    $where .= " AND m.constituency_type = ?";
    $params[] = $filterCtype;
}
if ($filterQuery !== '') {
    if (ctype_digit($filterQuery)) {
        $where .= " AND (m.id = ? OR m.seat_no = ? OR m.neva_member_id = ? OR m.member_name LIKE ? OR m.constituency LIKE ? OR m.party_name LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, (int)$filterQuery, (int)$filterQuery, (int)$filterQuery, $wild, $wild, $wild);
    } else {
        $where .= " AND (m.member_name LIKE ? OR m.slug LIKE ? OR m.constituency LIKE ? OR COALESCE(m.constituency_area, '') LIKE ? OR m.party_name LIKE ? OR COALESCE(m.role_designation, '') LIKE ? OR m.state LIKE ? OR COALESCE(s.state, '') LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }
}

$cntSql = "
    SELECT COUNT(*)
    FROM mlc m
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
    'updated' => "m.updated_at DESC, m.id DESC",
    'newest' => "m.id DESC",
    'name_asc' => "m.member_name ASC, m.id ASC",
    'id_asc' => "m.id ASC",
    default => "FIELD(m.state_code, '10', '9', '28'), m.seat_no IS NULL ASC, m.seat_no ASC, m.member_name ASC, m.id ASC",
};

$dataSql = "
    SELECT m.id, m.neva_member_id, m.seat_no, m.member_name, m.slug, m.party_name,
           m.role_designation, m.constituency, m.constituency_type, m.constituency_area,
           m.state, m.state_code, m.house, m.membership_status, m.status, m.term_period, m.updated_at,
           COALESCE(s.state, m.state) AS state_display
    FROM mlc m
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON m.state_code = s.state_code
    {$where}
    ORDER BY {$orderBySql}
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$currentFilterParams = array_filter([
    'status' => $filterStatus,
    'state_code' => $filterState,
    'constituency_type' => $filterCtype,
    'q' => $filterQuery,
    'sort' => $sortKey !== 'hierarchy' ? $sortKey : '',
], static fn($v): bool => $v !== '');
$returnQs = http_build_query($currentFilterParams + ($page > 1 ? ['page' => $page] : []));

$activeNav = 'mlcs';
$ppmsPageTitle = 'Manage Council MLCs';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Filter & Quick Tabs Card -->
<div class="ppms-card">
    <div class="ppms-card-header">
        <div class="d-flex flex-wrap gap-2">
            <?php
            $quickTabs = [
                ['label' => 'All MLCs (' . number_format($mlcSummary['total_rows']) . ')', 'status' => '', 'state_code' => ''],
                ['label' => 'Active (' . number_format($mlcSummary['active_count']) . ')', 'status' => 'ACTIVE', 'state_code' => ''],
                ['label' => 'Bihar (' . number_format($mlcSummary['br_count']) . ')', 'status' => '', 'state_code' => '10'],
                ['label' => 'Uttar Pradesh (' . number_format($mlcSummary['up_count']) . ')', 'status' => '', 'state_code' => '9'],
                ['label' => 'Andhra Pradesh (' . number_format($mlcSummary['ap_count']) . ')', 'status' => '', 'state_code' => '28'],
                ['label' => 'Inactive (' . number_format($mlcSummary['inactive_count']) . ')', 'status' => 'INACTIVE', 'state_code' => ''],
            ];
            foreach ($quickTabs as $qt):
                $isAct = ($filterStatus === $qt['status'] && $filterState === $qt['state_code'] && $filterCtype === '' && $filterQuery === '');
                $qtParams = array_filter(['status' => $qt['status'], 'state_code' => $qt['state_code']]);
                $qtUrl = get_site_url('ppms/mlcs' . (!empty($qtParams) ? '?' . http_build_query($qtParams) : ''));
            ?>
                <a href="<?= e($qtUrl) ?>" class="btn btn-sm rounded-pill fw-bold <?= $isAct ? 'btn-ppms-saffron' : 'btn-light border text-secondary' ?>" style="font-size:0.78rem;">
                    <?= e($qt['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="<?= e(get_site_url('ppms/mlc-form')) ?>" class="btn btn-ppms-green btn-sm px-3">
            <i class="fas fa-plus me-1"></i> New MLC
        </a>
    </div>

    <div class="ppms-card-body">
        <form method="GET" action="<?= e(get_site_url('ppms/mlcs')) ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-secondary mb-1">Search (Name / Constituency / Party / Designation / ID)</label>
                <input type="text" name="q" value="<?= e($filterQuery) ?>" class="form-control form-control-sm" placeholder="e.g. Nawal Kishore, Patna, Keshav Prasad, BJP...">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">State / Council</label>
                <select name="state_code" class="form-select form-select-sm">
                    <option value="">All States</option>
                    <?php foreach ($stateOptions as $st): ?>
                        <option value="<?= e((string)$st['state_code']) ?>" <?= $filterState === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">Quota / Type</label>
                <select name="constituency_type" class="form-select form-select-sm">
                    <option value="">All Quota Types</option>
                    <?php foreach ($ctypeOptions as $ct): ?>
                        <option value="<?= e((string)$ct) ?>" <?= $filterCtype === (string)$ct ? 'selected' : '' ?>><?= e((string)$ct) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-secondary mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="ACTIVE" <?= $filterStatus === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="INACTIVE" <?= $filterStatus === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-flex gap-1">
                <select name="sort" class="form-select form-select-sm" title="Sort Order">
                    <option value="hierarchy" <?= $sortKey === 'hierarchy' ? 'selected' : '' ?>>Sort: State &amp; Seat</option>
                    <option value="updated" <?= $sortKey === 'updated' ? 'selected' : '' ?>>Recently Updated</option>
                    <option value="newest" <?= $sortKey === 'newest' ? 'selected' : '' ?>>Newest ID</option>
                    <option value="name_asc" <?= $sortKey === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="id_asc" <?= $sortKey === 'id_asc' ? 'selected' : '' ?>>ID Asc</option>
                </select>
                <button type="submit" class="btn btn-ppms-navy btn-sm px-2" title="Apply Filter"><i class="fas fa-filter"></i></button>
                <?php if (!empty($currentFilterParams)): ?>
                    <a href="<?= e(get_site_url('ppms/mlcs')) ?>" class="btn btn-light border btn-sm px-2" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Results Table Card -->
<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <span class="small text-secondary fw-semibold">
            Showing <strong><?= count($rows) ?></strong> of <strong><?= number_format($filteredTotal) ?></strong> MLC records
            (Page <?= $page ?> of <?= number_format($totalPages) ?>)
        </span>
        <span class="small text-muted">Direct CRUD on <code>mlc</code></span>
    </div>

    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>ID / Prefix</th>
                    <th>MLC Name &amp; Slug</th>
                    <th>Constituency &amp; Quota</th>
                    <th>State &amp; Party</th>
                    <th>Status (Quick Switch)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-scroll fa-2x mb-2 d-block opacity-50"></i>
                            No MLC records matched your current filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $cid = (int)$row['id'];
                        $mName = clean_mlc_display_name($row['member_name']);
                        $stateDisp = clean_party_display_text($row['state_display'] ?: $row['state']);
                        $pref = strtoupper(get_mlc_state_slug_prefix((string)$row['state_code'], $stateDisp));
                        $mStatus = strtoupper(trim((string)($row['status'] ?: 'ACTIVE')));
                        $publicUrl = get_mlc_page_url((string)$row['slug'], $cid);
                    ?>
                        <tr>
                            <td class="text-nowrap">
                                <div class="fw-bold text-dark">#<?= $cid ?></div>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:0.68rem;">
                                    <?= e($pref) ?> MLC<?= !empty($row['seat_no']) ? ' #' . (int)$row['seat_no'] : '' ?>
                                </span>
                            </td>
                            <td style="min-width:230px;">
                                <a href="<?= e(get_site_url('ppms/mlc-form?id=' . $cid)) ?>" class="fw-bold text-decoration-none text-dark d-block">
                                    <?= e($mName) ?>
                                </a>
                                <?php if (!empty($row['role_designation'])): ?>
                                    <div class="small text-success fw-semibold"><?= e($row['role_designation']) ?></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:0.72rem;">
                                    <code>/mlc/<?= e($row['slug']) ?></code>
                                </div>
                            </td>
                            <td style="min-width:180px;">
                                <div class="fw-semibold text-dark small"><?= e($row['constituency']) ?></div>
                                <div class="text-muted" style="font-size:0.74rem;"><?= e($row['constituency_type']) ?></div>
                            </td>
                            <td style="min-width:170px;">
                                <div class="small fw-semibold text-dark"><?= e($stateDisp) ?></div>
                                <div class="text-muted" style="font-size:0.74rem;"><?= e($row['party_name']) ?></div>
                            </td>
                            <td>
                                <form method="POST" action="<?= e(get_site_url('ppms/mlcs')) ?>" class="d-flex align-items-center gap-1 m-0">
                                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="mlc_id" value="<?= $cid ?>">
                                    <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                                    <select name="new_status" onchange="this.form.submit()" class="form-select form-select-sm fw-bold <?= $mStatus === 'ACTIVE' ? 'text-success border-success' : 'text-warning border-warning' ?>" style="width:118px;font-size:0.75rem;">
                                        <option value="ACTIVE" <?= $mStatus === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                                        <option value="INACTIVE" <?= $mStatus === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
                                    </select>
                                </form>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="<?= e(get_site_url('ppms/mlc-form?id=' . $cid)) ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="Edit MLC">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm py-1 px-2" title="View Public MLC Page">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm py-1 px-2"
                                        title="Delete MLC"
                                        data-mlc-id="<?= $cid ?>"
                                        data-mlc-name="<?= e($mName) ?>"
                                        onclick="openDeleteMlcModal(this)">
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
                    <a href="<?= e(get_site_url('ppms/mlcs?' . http_build_query($currentFilterParams + ['page' => 1]))) ?>" class="btn btn-light border btn-sm">&laquo; First</a>
                    <a href="<?= e(get_site_url('ppms/mlcs?' . http_build_query($currentFilterParams + ['page' => $page - 1]))) ?>" class="btn btn-light border btn-sm">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php
                $winStart = max(1, $page - 2);
                $winEnd = min($totalPages, $page + 2);
                for ($p = $winStart; $p <= $winEnd; $p++):
                ?>
                    <a href="<?= e(get_site_url('ppms/mlcs?' . http_build_query($currentFilterParams + ['page' => $p]))) ?>"
                       class="btn btn-sm <?= $p === $page ? 'btn-ppms-navy' : 'btn-light border' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(get_site_url('ppms/mlcs?' . http_build_query($currentFilterParams + ['page' => $page + 1]))) ?>" class="btn btn-light border btn-sm">Next &rsaquo;</a>
                    <a href="<?= e(get_site_url('ppms/mlcs?' . http_build_query($currentFilterParams + ['page' => $totalPages]))) ?>" class="btn btn-light border btn-sm">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Safe Delete Confirmation Modal -->
<div class="modal fade" id="ppmsDeleteMlcModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-exclamation-triangle me-2"></i> Confirm Delete MLC</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small text-secondary">Are you sure you want to permanently remove this MLC record from <code>mlc</code>?</p>
                <div class="p-2 rounded bg-light border fw-bold text-dark small" id="deleteModalMlcName"></div>
                <p class="text-danger mt-2 mb-0" style="font-size:0.76rem;">This permanently deletes the public <code>/mlc/</code> profile page. To temporarily hide the profile from directories and sitemaps, set Status to INACTIVE instead.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/mlcs')) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_mlc">
                    <input type="hidden" name="mlc_id" id="deleteModalMlcId" value="">
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
function openDeleteMlcModal(btn) {
    const id = btn.getAttribute('data-mlc-id') || '';
    const name = btn.getAttribute('data-mlc-name') || '';
    document.getElementById('deleteModalMlcId').value = id;
    document.getElementById('deleteModalMlcName').textContent = '#' + id + ' — ' + name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ppmsDeleteMlcModal')).show();
}
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>


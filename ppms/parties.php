<?php
// ppms/parties.php - Full Political Parties CRUD Registry for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

// Handle POST Actions (Quick Status Update & Safe Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    $returnQs = trim((string)($_POST['return_qs'] ?? ''));
    $redirectTarget = get_site_url('ppms/parties' . ($returnQs !== '' ? '?' . ltrim($returnQs, '?') : ''));

    if (!ppms_verify_csrf($csrf)) {
        ppms_set_flash('error', 'Security token expired. Please try again.');
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $partyId = (int)($_POST['party_id'] ?? 0);

    if ($action === 'change_status' && $partyId > 0) {
        $newStatus = strtoupper(trim((string)($_POST['new_status'] ?? '')));
        if (!in_array($newStatus, ['ACTIVE', 'INACTIVE', 'DELISTED'], true)) {
            ppms_set_flash('error', 'Invalid status selected.');
        } else {
            $curStmt = $pdo->prepare("SELECT id, name, status FROM political_party WHERE id = ? LIMIT 1");
            $curStmt->execute([$partyId]);
            $curRow = $curStmt->fetch();
            if ($curRow) {
                $oldStatus = strtoupper(trim((string)$curRow['status']));
                $upd = $pdo->prepare("UPDATE political_party SET status = ?, updated_by = ? WHERE id = ?");
                $upd->execute([$newStatus, (int)$adminUser['id'], $partyId]);
                ppms_log_action(
                    $pdo,
                    $adminUser,
                    'STATUS_CHANGE',
                    $partyId,
                    clean_party_display_name($curRow['name']),
                    "Status changed {$oldStatus} -> {$newStatus}"
                );
                ppms_set_flash('success', "Party #{$partyId} status updated to {$newStatus}.");
            } else {
                ppms_set_flash('error', "Party #{$partyId} was not found.");
            }
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }

    if ($action === 'delete_party' && $partyId > 0) {
        $curStmt = $pdo->prepare("SELECT id, name, type, state FROM political_party WHERE id = ? LIMIT 1");
        $curStmt->execute([$partyId]);
        $curRow = $curStmt->fetch();
        if ($curRow) {
            $del = $pdo->prepare("DELETE FROM political_party WHERE id = ? LIMIT 1");
            $del->execute([$partyId]);
            $pName = clean_party_display_name($curRow['name']);
            ppms_log_action(
                $pdo,
                $adminUser,
                'DELETE',
                $partyId,
                $pName,
                "Deleted {$curRow['type']} party ({$curRow['state']})"
            );
            ppms_set_flash('success', "Deleted political party #{$partyId}: {$pName}");
        } else {
            ppms_set_flash('error', "Party #{$partyId} was not found.");
        }
        header('Location: ' . $redirectTarget, true, 302);
        exit;
    }
}

// GET Filters
$filterType = trim((string)($_GET['type'] ?? ''));
if (!in_array($filterType, ['', 'National', 'State', 'Registered'], true)) {
    $filterType = '';
}
$filterStatus = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!in_array($filterStatus, ['', 'ACTIVE', 'INACTIVE', 'DELISTED'], true)) {
    $filterStatus = '';
}
$filterState = trim((string)($_GET['state_code'] ?? ''));
$filterSymbol = trim((string)($_GET['has_symbol'] ?? ''));
if (!in_array($filterSymbol, ['', '1', '0'], true)) {
    $filterSymbol = '';
}
$filterContact = trim((string)($_GET['has_contact'] ?? ''));
if (!in_array($filterContact, ['', '1', '0'], true)) {
    $filterContact = '';
}
$filterQuery = trim((string)($_GET['q'] ?? ''));
$sortKey = trim((string)($_GET['sort'] ?? 'hierarchy'));
if (!in_array($sortKey, ['hierarchy', 'updated', 'newest', 'name_asc', 'id_asc'], true)) {
    $sortKey = 'hierarchy';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];
$stateOptions = ppms_get_states($pdo);

// Build Query
$where = " WHERE 1=1";
$params = [];

if ($filterType !== '') {
    $where .= " AND TRIM(pp.type) = ?";
    $params[] = $filterType;
}
if ($filterStatus !== '') {
    $where .= " AND UPPER(TRIM(pp.status)) = ?";
    $params[] = $filterStatus;
}
if ($filterState !== '') {
    if ($filterState === 'ALL_INDIA') {
        $where .= " AND (TRIM(pp.type) = 'National' OR UPPER(TRIM(pp.state)) = 'ALL')";
    } else {
        $where .= " AND pp.state_code = ?";
        $params[] = $filterState;
    }
}
if ($filterSymbol === '1') {
    $where .= " AND pp.symbol IS NOT NULL AND TRIM(pp.symbol) <> '' AND UPPER(TRIM(pp.symbol)) NOT IN ('-', 'NA', 'N/A', 'NONE', 'NIL')";
} elseif ($filterSymbol === '0') {
    $where .= " AND (pp.symbol IS NULL OR TRIM(pp.symbol) = '' OR UPPER(TRIM(pp.symbol)) IN ('-', 'NA', 'N/A', 'NONE', 'NIL'))";
}
$hasContactExpr = "(COALESCE(NULLIF(TRIM(pp.mobile), ''), NULLIF(TRIM(pp.whatsapp), ''), NULLIF(TRIM(pp.misscall), ''), NULLIF(TRIM(pp.email), ''), NULLIF(TRIM(pp.facebook), ''), NULLIF(TRIM(pp.instagram), ''), NULLIF(TRIM(pp.x), ''), NULLIF(TRIM(pp.youtube), ''), NULLIF(TRIM(pp.linkedin), '')) IS NOT NULL)";
if ($filterContact === '1') {
    $where .= " AND {$hasContactExpr}";
} elseif ($filterContact === '0') {
    $where .= " AND NOT {$hasContactExpr}";
}
if ($filterQuery !== '') {
    if (ctype_digit($filterQuery)) {
        $where .= " AND (pp.id = ? OR pp.no = ? OR pp.name LIKE ? OR COALESCE(pp.name_hi, '') LIKE ? OR pp.address LIKE ? OR COALESCE(pp.mobile, '') LIKE ? OR COALESCE(pp.whatsapp, '') LIKE ? OR COALESCE(pp.misscall, '') LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, (int)$filterQuery, $filterQuery, $wild, $wild, $wild, $wild, $wild, $wild);
    } else {
        $where .= " AND (pp.name LIKE ? OR COALESCE(pp.name_hi, '') LIKE ? OR pp.slug LIKE ? OR pp.symbol LIKE ? OR pp.address LIKE ? OR COALESCE(pp.address_hi, '') LIKE ? OR pp.state LIKE ? OR s.state LIKE ? OR pp.url LIKE ? OR COALESCE(pp.mobile, '') LIKE ? OR COALESCE(pp.whatsapp, '') LIKE ? OR COALESCE(pp.misscall, '') LIKE ? OR COALESCE(pp.email, '') LIKE ? OR COALESCE(pp.facebook, '') LIKE ? OR COALESCE(pp.instagram, '') LIKE ? OR COALESCE(pp.x, '') LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }
}

$cntSql = "
    SELECT COUNT(*)
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
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
    'updated' => "pp.updated_at DESC, pp.id DESC",
    'newest' => "pp.id DESC",
    'name_asc' => "pp.name ASC, pp.id ASC",
    'id_asc' => "pp.id ASC",
    default => "CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
                CASE UPPER(TRIM(pp.status)) WHEN 'ACTIVE' THEN 1 WHEN 'INACTIVE' THEN 2 WHEN 'DELISTED' THEN 3 ELSE 4 END ASC,
                state_display ASC,
                pp.id ASC",
};

$dataSql = "
    SELECT pp.*,
           COALESCE(s.state, pp.state) AS state_display
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
    {$where}
    ORDER BY {$orderBySql}
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($dataSql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$currentFilterParams = array_filter([
    'type' => $filterType,
    'status' => $filterStatus,
    'state_code' => $filterState,
    'has_symbol' => $filterSymbol,
    'has_contact' => $filterContact,
    'q' => $filterQuery,
    'sort' => $sortKey !== 'hierarchy' ? $sortKey : '',
], static fn($v): bool => $v !== '');
$returnQs = http_build_query($currentFilterParams + ($page > 1 ? ['page' => $page] : []));

$activeNav = 'parties';
$ppmsPageTitle = 'Manage Political Parties';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Filter & Search Card -->
<div class="ppms-card">
    <div class="ppms-card-header">
        <div class="d-flex flex-wrap gap-2">
            <?php
            $quickTabs = [
                ['label' => 'All (' . number_format($summary['total_rows']) . ')', 'type' => '', 'status' => ''],
                ['label' => 'Active (' . number_format($summary['active_count']) . ')', 'type' => '', 'status' => 'ACTIVE'],
                ['label' => 'National (' . number_format($summary['national_count']) . ')', 'type' => 'National', 'status' => ''],
                ['label' => 'State (' . number_format($summary['state_count']) . ')', 'type' => 'State', 'status' => ''],
                ['label' => 'Registered (' . number_format($summary['registered_count']) . ')', 'type' => 'Registered', 'status' => ''],
                ['label' => 'Inactive (' . number_format($summary['inactive_count']) . ')', 'type' => '', 'status' => 'INACTIVE'],
                ['label' => 'Delisted (' . number_format($summary['delisted_count']) . ')', 'type' => '', 'status' => 'DELISTED'],
            ];
            foreach ($quickTabs as $qt):
                $isAct = ($filterType === $qt['type'] && $filterStatus === $qt['status'] && $filterSymbol === '' && $filterContact === '' && $filterState === '' && $filterQuery === '');
                $qtUrl = get_site_url('ppms/parties' . (!empty(array_filter(['type' => $qt['type'], 'status' => $qt['status']])) ? '?' . http_build_query(array_filter(['type' => $qt['type'], 'status' => $qt['status']])) : ''));
            ?>
                <a href="<?= e($qtUrl) ?>" class="btn btn-sm rounded-pill fw-bold <?= $isAct ? 'btn-ppms-saffron' : 'btn-light border text-secondary' ?>" style="font-size:0.78rem;">
                    <?= e($qt['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="<?= e(get_site_url('ppms/party-form')) ?>" class="btn btn-ppms-green btn-sm px-3">
            <i class="fas fa-plus me-1"></i> New Party
        </a>
    </div>

    <div class="ppms-card-body">
        <form method="GET" action="<?= e(get_site_url('ppms/parties')) ?>" class="row g-2 align-items-end">
            <div class="col-12 col-md-6 col-xl-2">
                <label class="form-label small fw-bold text-secondary mb-1">Search (Name / Contact / ID)</label>
                <input type="text" name="q" value="<?= e($filterQuery) ?>" class="form-control form-control-sm" placeholder="e.g. Janata, 8800..., email, 68...">
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small fw-bold text-secondary mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="National" <?= $filterType === 'National' ? 'selected' : '' ?>>National</option>
                    <option value="State" <?= $filterType === 'State' ? 'selected' : '' ?>>State</option>
                    <option value="Registered" <?= $filterType === 'Registered' ? 'selected' : '' ?>>Registered (RUPP)</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2">
                <label class="form-label small fw-bold text-secondary mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="ACTIVE" <?= $filterStatus === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="INACTIVE" <?= $filterStatus === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
                    <option value="DELISTED" <?= $filterStatus === 'DELISTED' ? 'selected' : '' ?>>DELISTED</option>
                </select>
            </div>
            <div class="col-12 col-md-4 col-xl-2">
                <label class="form-label small fw-bold text-secondary mb-1">State / UT</label>
                <select name="state_code" class="form-select form-select-sm">
                    <option value="">All States &amp; UTs</option>
                    <?php foreach ($stateOptions as $st): ?>
                        <option value="<?= e((string)$st['state_code']) ?>" <?= $filterState === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2 col-xl-1">
                <label class="form-label small fw-bold text-secondary mb-1">Symbol</label>
                <select name="has_symbol" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="1" <?= $filterSymbol === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $filterSymbol === '0' ? 'selected' : '' ?>>None</option>
                </select>
            </div>
            <div class="col-6 col-md-2 col-xl-1">
                <label class="form-label small fw-bold text-secondary mb-1">Contact</label>
                <select name="has_contact" class="form-select form-select-sm">
                    <option value="">Any</option>
                    <option value="1" <?= $filterContact === '1' ? 'selected' : '' ?>>Filled</option>
                    <option value="0" <?= $filterContact === '0' ? 'selected' : '' ?>>Empty</option>
                </select>
            </div>
            <div class="col-12 col-md-4 col-xl-2 d-flex gap-1">
                <select name="sort" class="form-select form-select-sm" title="Sort Order">
                    <option value="hierarchy" <?= $sortKey === 'hierarchy' ? 'selected' : '' ?>>Sort: Hierarchy</option>
                    <option value="updated" <?= $sortKey === 'updated' ? 'selected' : '' ?>>Recently Updated</option>
                    <option value="newest" <?= $sortKey === 'newest' ? 'selected' : '' ?>>Newest ID</option>
                    <option value="name_asc" <?= $sortKey === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                    <option value="id_asc" <?= $sortKey === 'id_asc' ? 'selected' : '' ?>>ID Asc</option>
                </select>
                <button type="submit" class="btn btn-ppms-navy btn-sm px-2" title="Apply Filter"><i class="fas fa-filter"></i></button>
                <?php if (!empty($currentFilterParams)): ?>
                    <a href="<?= e(get_site_url('ppms/parties')) ?>" class="btn btn-light border btn-sm px-2" title="Reset Filters"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Results Table Card -->
<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <span class="small text-secondary fw-semibold">
            Showing <strong><?= count($rows) ?></strong> of <strong><?= number_format($filteredTotal) ?></strong> matching records
            (Page <?= $page ?> of <?= number_format($totalPages) ?>)
        </span>
        <span class="small text-muted">Direct CRUD on <code>political_party</code></span>
    </div>

    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>ID / No</th>
                    <th>Political Party (EN / हिंदी)</th>
                    <th>Type</th>
                    <th>Symbol</th>
                    <th>State / Scope</th>
                    <th>Status (Quick Switch)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-search fa-2x mb-2 d-block opacity-50"></i>
                            No political parties matched your current filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row):
                        $pid = (int)$row['id'];
                        $pName = clean_party_display_name($row['name']);
                        $pNameHi = clean_party_display_text($row['name_hi'] ?? '');
                        $pType = clean_party_display_text($row['type']) ?: 'Registered';
                        $pStatus = strtoupper(clean_party_display_text($row['status']) ?: 'ACTIVE');
                        $pStateDisp = clean_party_display_text($row['state_display']);
                        $isNat = strcasecmp($pType, 'National') === 0;
                        $typeBadgeCls = $isNat ? 'ppms-badge-national' : (strcasecmp($pType, 'State') === 0 ? 'ppms-badge-state' : 'ppms-badge-registered');
                        $symInfo = get_party_symbol_image($row['symbol'] ?? '');
                        $publicUrl = get_party_page_url((string)$row['slug'], $pid);
                        $pUrl = trim((string)($row['url'] ?? ''));
                        $pMob = trim((string)($row['mobile'] ?? ''));
                        $pWa = trim((string)($row['whatsapp'] ?? ''));
                        $pMc = trim((string)($row['misscall'] ?? ''));
                        $pEm = trim((string)($row['email'] ?? ''));
                        $pFb = trim((string)($row['facebook'] ?? ''));
                        $pIg = trim((string)($row['instagram'] ?? ''));
                        $pX = trim((string)($row['x'] ?? ''));
                        $pYt = trim((string)($row['youtube'] ?? ''));
                        $pLi = trim((string)($row['linkedin'] ?? ''));
                        $hasAnyChannel = ($pMob !== '' || $pWa !== '' || $pMc !== '' || $pEm !== '' || $pFb !== '' || $pIg !== '' || $pX !== '' || $pYt !== '' || $pLi !== '');
                        $lastUpd = format_eci_date($row['last_update']);
                    ?>
                        <tr>
                            <td class="text-nowrap">
                                <div class="fw-bold text-dark">#<?= $pid ?></div>
                                <?php if (!empty($row['no'])): ?>
                                    <div class="text-muted" style="font-size:0.72rem;">S.No <?= e($row['no']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="min-width:240px;">
                                <a href="<?= e(get_site_url('ppms/party-form?id=' . $pid)) ?>" class="fw-bold text-decoration-none text-dark d-block">
                                    <?= e($pName) ?>
                                </a>
                                <?php if ($pNameHi !== ''): ?>
                                    <div class="small text-success lang-hi" lang="hi"><?= e($pNameHi) ?></div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:0.72rem;">
                                    <code><?= e($row['slug']) ?></code>
                                    <?php if ($lastUpd !== ''): ?> &bull; ECI: <?= e($lastUpd) ?><?php endif; ?>
                                    <?php if ($pUrl !== '' && preg_match('#^https?://#i', $pUrl)): ?>
                                        &bull; <a href="<?= e($pUrl) ?>" target="_blank" rel="noopener" class="text-primary text-decoration-none">Website</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($hasAnyChannel): ?>
                                    <div class="d-flex flex-wrap gap-1 mt-1" style="font-size:0.68rem;">
                                        <?php if ($pMob !== ''): ?><span class="badge bg-light text-dark border" title="Mobile: <?= e($pMob) ?>"><i class="fas fa-phone text-primary"></i> <?= e($pMob) ?></span><?php endif; ?>
                                        <?php if ($pWa !== ''): ?><span class="badge bg-light text-dark border" title="WhatsApp: <?= e($pWa) ?>"><i class="fab fa-whatsapp text-success"></i></span><?php endif; ?>
                                        <?php if ($pMc !== ''): ?><span class="badge bg-light text-dark border" title="Missed Call: <?= e($pMc) ?>"><i class="fas fa-phone-volume text-warning"></i> <?= e($pMc) ?></span><?php endif; ?>
                                        <?php if ($pEm !== ''): ?><span class="badge bg-light text-dark border" title="Email: <?= e($pEm) ?>"><i class="fas fa-envelope text-danger"></i></span><?php endif; ?>
                                        <?php if ($pFb !== ''): ?><span class="badge bg-light text-dark border" title="Facebook"><i class="fab fa-facebook text-primary"></i></span><?php endif; ?>
                                        <?php if ($pIg !== ''): ?><span class="badge bg-light text-dark border" title="Instagram"><i class="fab fa-instagram text-danger"></i></span><?php endif; ?>
                                        <?php if ($pX !== ''): ?><span class="badge bg-light text-dark border" title="X"><i class="fab fa-x-twitter text-dark"></i></span><?php endif; ?>
                                        <?php if ($pYt !== ''): ?><span class="badge bg-light text-dark border" title="YouTube"><i class="fab fa-youtube text-danger"></i></span><?php endif; ?>
                                        <?php if ($pLi !== ''): ?><span class="badge bg-light text-dark border" title="LinkedIn"><i class="fab fa-linkedin text-primary"></i></span><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="ppms-badge <?= $typeBadgeCls ?>"><?= e($pType) ?></span></td>
                            <td style="min-width:150px;">
                                <?php if ($symInfo): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if (!empty($symInfo['url'])): ?>
                                            <img src="<?= e($symInfo['url']) ?>" alt="<?= e($symInfo['label']) ?>" loading="lazy" class="ppms-sym-thumb">
                                        <?php endif; ?>
                                        <span class="small fw-semibold text-dark" title="<?= e($symInfo['raw']) ?>"><?= e($symInfo['label']) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Unreserved</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-secondary text-nowrap">
                                <?= $isNat ? e('All India (HQ: ' . ($pStateDisp ?: 'ALL') . ')') : e($pStateDisp ?: '-') ?>
                            </td>
                            <td>
                                <form method="POST" action="<?= e(get_site_url('ppms/parties')) ?>" class="d-flex align-items-center gap-1 m-0">
                                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="party_id" value="<?= $pid ?>">
                                    <input type="hidden" name="return_qs" value="<?= e($returnQs) ?>">
                                    <select name="new_status" onchange="this.form.submit()" class="form-select form-select-sm fw-bold <?= $pStatus === 'ACTIVE' ? 'text-success border-success' : ($pStatus === 'DELISTED' ? 'text-danger border-danger' : 'text-warning border-warning') ?>" style="width:118px;font-size:0.75rem;">
                                        <option value="ACTIVE" <?= $pStatus === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                                        <option value="INACTIVE" <?= $pStatus === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
                                        <option value="DELISTED" <?= $pStatus === 'DELISTED' ? 'selected' : '' ?>>DELISTED</option>
                                    </select>
                                </form>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="<?= e(get_site_url('ppms/party-form?id=' . $pid)) ?>" class="btn btn-outline-primary btn-sm py-1 px-2" title="Edit Party">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm py-1 px-2" title="View Public Party Page">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button"
                                        class="btn btn-outline-danger btn-sm py-1 px-2"
                                        title="Delete Party"
                                        data-party-id="<?= $pid ?>"
                                        data-party-name="<?= e($pName) ?>"
                                        onclick="openDeleteModal(this)">
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
                    <a href="<?= e(get_site_url('ppms/parties?' . http_build_query($currentFilterParams + ['page' => 1]))) ?>" class="btn btn-light border btn-sm">&laquo; First</a>
                    <a href="<?= e(get_site_url('ppms/parties?' . http_build_query($currentFilterParams + ['page' => $page - 1]))) ?>" class="btn btn-light border btn-sm">&lsaquo; Prev</a>
                <?php endif; ?>

                <?php
                $winStart = max(1, $page - 2);
                $winEnd = min($totalPages, $page + 2);
                for ($p = $winStart; $p <= $winEnd; $p++):
                ?>
                    <a href="<?= e(get_site_url('ppms/parties?' . http_build_query($currentFilterParams + ['page' => $p]))) ?>"
                       class="btn btn-sm <?= $p === $page ? 'btn-ppms-navy' : 'btn-light border' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(get_site_url('ppms/parties?' . http_build_query($currentFilterParams + ['page' => $page + 1]))) ?>" class="btn btn-light border btn-sm">Next &rsaquo;</a>
                    <a href="<?= e(get_site_url('ppms/parties?' . http_build_query($currentFilterParams + ['page' => $totalPages]))) ?>" class="btn btn-light border btn-sm">Last &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Safe Delete Confirmation Modal -->
<div class="modal fade" id="ppmsDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="fas fa-exclamation-triangle me-2"></i> Confirm Delete Political Party</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 small text-secondary">Are you sure you want to permanently remove this record from <code>political_party</code>?</p>
                <div class="p-2 rounded bg-light border fw-bold text-dark small" id="deleteModalPartyName"></div>
                <p class="text-danger mt-2 mb-0" style="font-size:0.76rem;">This action cannot be undone. Consider setting status to DELISTED or INACTIVE instead if preserving historical records.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light border btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= e(get_site_url('ppms/parties')) ?>" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= e(ppms_csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete_party">
                    <input type="hidden" name="party_id" id="deleteModalPartyId" value="">
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
function openDeleteModal(btn) {
    const id = btn.getAttribute('data-party-id') || '';
    const name = btn.getAttribute('data-party-name') || '';
    document.getElementById('deleteModalPartyId').value = id;
    document.getElementById('deleteModalPartyName').textContent = '#' + id + ' — ' + name;
    const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('ppmsDeleteModal'));
    modal.show();
}
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>


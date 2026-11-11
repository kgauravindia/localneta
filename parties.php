<?php
// parties.php - Political Parties Directory (Powered by `political_party` + `op_sdb`, English UI, Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

function clean_party_text(?string $text): string {
    if ($text === null) {
        return '';
    }
    $text = str_replace("\xC2\xA0", ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function clean_party_name(?string $name): string {
    $clean = clean_party_text($name);
    return trim(preg_replace('/^\d+\.\s*/u', '', $clean) ?? $clean);
}

$filterType = trim($_GET['type'] ?? '');
if (!in_array($filterType, ['', 'National', 'State', 'Registered'], true)) {
    $filterType = '';
}
$filterStatus = strtoupper(trim($_GET['status'] ?? ''));
if (!in_array($filterStatus, ['', 'ACTIVE', 'INACTIVE', 'DELISTED'], true)) {
    $filterStatus = '';
}
$filterState = trim($_GET['state_code'] ?? '');
$filterSymbol = trim($_GET['has_symbol'] ?? '') === '1' ? '1' : '';
$filterQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'political_party'")->fetch();

$summary = [
    'total_rows' => 0,
    'national_count' => 0,
    'state_count' => 0,
    'registered_count' => 0,
    'registered_active_count' => 0,
    'symbol_count' => 0,
    'active_count' => 0,
    'delisted_count' => 0,
    'inactive_count' => 0,
];
$stateOptions = [];
$rows = [];
$filteredTotal = 0;
$totalPages = 1;
$offset = 0;

if ($tableExists) {
    $summary = $pdo->query("
        SELECT
            COUNT(*) AS total_rows,
            SUM(TRIM(type) = 'National') AS national_count,
            SUM(TRIM(type) = 'State') AS state_count,
            SUM(TRIM(type) = 'Registered') AS registered_count,
            SUM(TRIM(type) = 'Registered' AND UPPER(TRIM(status)) = 'ACTIVE') AS registered_active_count,
            SUM(symbol IS NOT NULL AND TRIM(symbol) <> '') AS symbol_count,
            SUM(UPPER(TRIM(status)) = 'ACTIVE') AS active_count,
            SUM(UPPER(TRIM(status)) = 'DELISTED') AS delisted_count,
            SUM(UPPER(TRIM(status)) = 'INACTIVE') AS inactive_count
        FROM political_party
    ")->fetch() ?: $summary;

    $stateOptions = $pdo->query("
        SELECT pp.state_code,
               COALESCE(s.state, pp.state) AS display_name,
               COUNT(*) AS party_count
        FROM political_party pp
        LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
               ON pp.state_code = s.state_code
        GROUP BY pp.state_code, COALESCE(s.state, pp.state)
        ORDER BY display_name ASC
    ")->fetchAll();

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
    if ($filterState !== '' && $filterType !== 'National') {
        $where .= " AND pp.state_code = ?";
        $params[] = $filterState;
    }
    if ($filterSymbol === '1') {
        $where .= " AND pp.symbol IS NOT NULL AND TRIM(pp.symbol) <> ''";
    }
    if ($filterQuery !== '') {
        $where .= " AND (pp.name LIKE ? OR COALESCE(pp.name_hi, '') LIKE ? OR pp.symbol LIKE ? OR pp.address LIKE ? OR COALESCE(pp.address_hi, '') LIKE ? OR pp.state LIKE ? OR s.state LIKE ? OR COALESCE(pp.mobile, '') LIKE ? OR COALESCE(pp.misscall, '') LIKE ? OR COALESCE(pp.whatsapp, '') LIKE ? OR COALESCE(pp.email, '') LIKE ?)";
        $wild = '%' . $filterQuery . '%';
        array_push($params, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }

    $cntSql = "
        SELECT COUNT(*)
        FROM political_party pp
        LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
               ON pp.state_code = s.state_code
        " . $where;
    $cntStmt = $pdo->prepare($cntSql);
    $cntStmt->execute($params);
    $filteredTotal = (int)$cntStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($filteredTotal / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $dataSql = "
        SELECT pp.*,
               COALESCE(s.state, pp.state) AS state_display
        FROM political_party pp
        LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
               ON pp.state_code = s.state_code
        " . $where . "
        ORDER BY CASE TRIM(pp.type)
                    WHEN 'National' THEN 1
                    WHEN 'State' THEN 2
                    ELSE 3
                 END ASC,
                 CASE UPPER(TRIM(pp.status))
                    WHEN 'ACTIVE' THEN 1
                    WHEN 'INACTIVE' THEN 2
                    WHEN 'DELISTED' THEN 3
                    ELSE 4
                 END ASC,
                 state_display ASC,
                 pp.id ASC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    $stmt = $pdo->prepare($dataSql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$pageTitle = "Political Parties Directory";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Page Banner -->
    <div class="tiranga-banner" style="margin-bottom:24px;">
        <div>
            <h2><i class="fas fa-flag" style="color:var(--saffron-main);"></i> Political Parties Directory</h2>
            <p>Browse National Parties (26-03-2024), State Parties (23-03-2024) with reserved election symbols, and Registered Unrecognized Political Parties (RUPPs) from ECI Notifications 27-03-2024 &amp; S.O. 57(E) dated 10-10-2025 in English &amp; Hindi.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <a href="<?= get_site_url('source#source-eci') ?>" class="btn-green-tiranga" style="padding:8px 16px; font-size:0.85rem;">
                <i class="fas fa-database"></i> ECI Data Sources
            </a>
            <a href="<?= get_site_url('elections#mp-directory') ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.85rem;">
                <i class="fas fa-landmark"></i> MP Directory
            </a>
        </div>
    </div>

    <!-- Summary Strip -->
    <section style="display:grid; grid-template-columns:repeat(auto-fit, minmax(145px, 1fr)); gap:12px; margin-bottom:20px;">
        <div style="background:#FFFFFF; padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid var(--navy-main); box-shadow:var(--shadow-sm);">
            <div style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">Total Records</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--navy-main);"><?= number_format((int)($summary['total_rows'] ?? 0)) ?></div>
        </div>
        <div style="background:#FFFFFF; padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid var(--green-main); box-shadow:var(--shadow-sm);">
            <div style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">Active Parties</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--green-main);"><?= number_format((int)($summary['active_count'] ?? 0)) ?></div>
        </div>
        <div style="background:#FFFFFF; padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid var(--saffron-main); box-shadow:var(--shadow-sm);">
            <div style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">National Parties</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--saffron-main);"><?= number_format((int)($summary['national_count'] ?? 0)) ?></div>
        </div>
        <div style="background:#FFFFFF; padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid var(--navy-accent); box-shadow:var(--shadow-sm);">
            <div style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">State Recognized</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--navy-accent);"><?= number_format((int)($summary['state_count'] ?? 0)) ?></div>
        </div>
        <div style="background:#FFFFFF; padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid #D97706; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">Inactive (2024)</div>
            <div style="font-size:1.35rem; font-weight:800; color:#92400E;"><?= number_format((int)($summary['inactive_count'] ?? 0)) ?></div>
        </div>
        <div style="background:#FFFFFF; padding:12px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid #DC2626; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">Delisted RUPPs</div>
            <div style="font-size:1.35rem; font-weight:800; color:#DC2626;"><?= number_format((int)($summary['delisted_count'] ?? 0)) ?></div>
        </div>
    </section>

    <!-- Directory Card -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--green-main); padding:22px; margin-bottom:30px; box-shadow:var(--shadow-sm);">

        <!-- Quick Category & Status Tabs -->
        <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:12px; margin-bottom:16px; border-bottom:1px solid var(--border-light);">
            <?php
            $typeTabs = [
                'all' => ['label' => 'All (' . number_format((int)$summary['total_rows']) . ')', 'type' => '', 'status' => '', 'symbol' => '', 'keep_state' => true],
                'active' => ['label' => 'Active (' . number_format((int)$summary['active_count']) . ')', 'type' => '', 'status' => 'ACTIVE', 'symbol' => '', 'keep_state' => true],
                'national' => ['label' => 'National (' . (int)$summary['national_count'] . ')', 'type' => 'National', 'status' => '', 'symbol' => '', 'keep_state' => false],
                'state' => ['label' => 'State Recognized (' . (int)$summary['state_count'] . ')', 'type' => 'State', 'status' => '', 'symbol' => '', 'keep_state' => true],
                'reg_active' => ['label' => 'Active RUPPs (' . number_format((int)$summary['registered_active_count']) . ')', 'type' => 'Registered', 'status' => 'ACTIVE', 'symbol' => '', 'keep_state' => true],
                'inactive' => ['label' => 'Inactive (' . number_format((int)$summary['inactive_count']) . ')', 'type' => '', 'status' => 'INACTIVE', 'symbol' => '', 'keep_state' => true],
                'delisted' => ['label' => 'Delisted (' . number_format((int)$summary['delisted_count']) . ')', 'type' => '', 'status' => 'DELISTED', 'symbol' => '', 'keep_state' => true],
                'symbols' => ['label' => 'With Symbol (' . (int)$summary['symbol_count'] . ')', 'type' => '', 'status' => '', 'symbol' => '1', 'keep_state' => true],
            ];
            foreach ($typeTabs as $tKey => $tab):
                $isActiveTab = ($filterType === $tab['type'] && $filterStatus === $tab['status'] && $filterSymbol === $tab['symbol']);
                $tabParams = array_filter([
                    'type' => $tab['type'],
                    'status' => $tab['status'],
                    'state_code' => $tab['keep_state'] ? $filterState : '',
                    'has_symbol' => $tab['symbol'],
                    'q' => $filterQuery,
                ], fn($v) => $v !== '');
            ?>
                <a href="<?= get_site_url('parties' . (!empty($tabParams) ? '?' . http_build_query($tabParams) : '')) ?>"
                   style="padding:6px 14px; border-radius:var(--radius-full); font-size:0.84rem; font-weight:700; white-space:nowrap; border:1px solid <?= $isActiveTab ? 'var(--saffron-main)' : 'var(--border-color)' ?>; background:<?= $isActiveTab ? 'var(--saffron-main)' : '#FFFFFF' ?>; color:<?= $isActiveTab ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                    <?= e($tab['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filter Form -->
        <form action="<?= get_site_url('parties') ?>" method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; align-items:flex-end; margin-bottom:18px; background:var(--white-surface); padding:14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Recognition Type</label>
                <select name="type" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Types</option>
                    <option value="National" <?= $filterType === 'National' ? 'selected' : '' ?>>National Party</option>
                    <option value="State" <?= $filterType === 'State' ? 'selected' : '' ?>>State Party</option>
                    <option value="Registered" <?= $filterType === 'Registered' ? 'selected' : '' ?>>Registered Party</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">ECI Status</label>
                <select name="status" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Statuses</option>
                    <option value="ACTIVE" <?= $filterStatus === 'ACTIVE' ? 'selected' : '' ?>>Active Only</option>
                    <option value="INACTIVE" <?= $filterStatus === 'INACTIVE' ? 'selected' : '' ?>>Inactive (Table 1(C), Mar 2024)</option>
                    <option value="DELISTED" <?= $filterStatus === 'DELISTED' ? 'selected' : '' ?>>Delisted (2024 &amp; 2025)</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">State / UT</label>
                <select name="state_code" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All States &amp; UTs</option>
                    <?php foreach ($stateOptions as $st): ?>
                        <option value="<?= e((string)$st['state_code']) ?>" <?= $filterState === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e(clean_party_text($st['display_name'])) ?> (<?= (int)$st['party_count'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Election Symbol</label>
                <select name="has_symbol" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All (With &amp; Without)</option>
                    <option value="1" <?= $filterSymbol === '1' ? 'selected' : '' ?>>Reserved Symbol Only</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Search EN / हिंदी / HQ</label>
                <input type="text" name="q" value="<?= e($filterQuery) ?>" placeholder="e.g. Bharatiya, आदर्श, Patna..." class="form-control" style="padding:7px 10px; font-size:0.85rem;">
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn-primary-tiranga" style="padding:8px 14px; font-size:0.85rem; flex:1;">
                    <i class="fas fa-search"></i> Filter
                </button>
                <?php if ($filterType !== '' || $filterStatus !== '' || $filterState !== '' || $filterSymbol !== '' || $filterQuery !== ''): ?>
                    <a href="<?= get_site_url('parties') ?>" class="btn-profile-view" style="padding:8px 10px; font-size:0.82rem; flex:unset;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($filterState !== '' && $filterType === ''): ?>
            <div style="margin-bottom:14px; padding:10px 14px; border-radius:var(--radius-md); background:#FFFBF5; border:1px solid rgba(255, 103, 31, 0.25); font-size:0.82rem; color:var(--text-secondary); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span><i class="fas fa-info-circle" style="color:var(--saffron-main);"></i> Showing State &amp; Registered parties registered in your selected State/UT. All <?= (int)$summary['national_count'] ?> National parties also contest nationwide.</span>
                <a href="<?= get_site_url('parties?type=National') ?>" style="font-weight:700; color:var(--saffron-main); text-decoration:none;">
                    View <?= (int)$summary['national_count'] ?> National Parties <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

        <?php if ($filterStatus === 'DELISTED'): ?>
            <div style="margin-bottom:14px; padding:10px 14px; border-radius:var(--radius-md); background:#FEF2F2; border:1px solid #FECACA; font-size:0.82rem; color:#991B1B; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span><i class="fas fa-triangle-exclamation"></i> Showing <?= number_format((int)$summary['delisted_count']) ?> RUPPs delisted by the Election Commission of India across the 27-03-2024 (Table I(B)) and 10-10-2025 (Table I(B) &amp; I(C)) notifications.</span>
                <a href="<?= get_site_url('parties?status=ACTIVE') ?>" style="font-weight:700; color:#991B1B; text-decoration:none;">
                    View Active Parties <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php elseif ($filterStatus === 'INACTIVE'): ?>
            <div style="margin-bottom:14px; padding:10px 14px; border-radius:var(--radius-md); background:#FFFBEB; border:1px solid #FDE68A; font-size:0.82rem; color:#92400E; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span><i class="fas fa-pause-circle"></i> Showing <?= number_format((int)$summary['inactive_count']) ?> inactive RUPPs listed under Table I(C) of the main ECI Notification dated 27-03-2024.</span>
                <a href="<?= get_site_url('parties?status=ACTIVE') ?>" style="font-weight:700; color:#92400E; text-decoration:none;">
                    View Active Parties <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>

        <!-- Results Meta -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px; font-size:0.82rem; color:var(--text-muted);">
            <span>Showing <strong><?= count($rows) ?></strong> of <strong><?= number_format($filteredTotal) ?></strong> matching political parties (Page <?= $page ?> of <?= $totalPages ?>)</span>
        </div>

        <!-- Parties Table -->
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                        <th style="padding:10px;">#</th>
                        <th style="padding:10px;">Political Party (EN / हिंदी)</th>
                        <th style="padding:10px;">Type</th>
                        <th style="padding:10px;">Election Symbol</th>
                        <th style="padding:10px;">State / Scope</th>
                        <th style="padding:10px;">Headquarters Address (EN / हिंदी)</th>
                        <th style="padding:10px;">Status</th>
                        <th style="padding:10px;">MPs / Links</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="8" style="padding:28px; text-align:center; color:var(--text-muted);">
                                No political parties matched your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $idx => $party):
                            $rowNo = $offset + $idx + 1;
                            $rawType = clean_party_text($party['type']);
                            $partyName = clean_party_name($party['name']);
                            $partyNameHi = clean_party_text($party['name_hi'] ?? '');
                            $symbolText = clean_party_text($party['symbol']);
                            $addressText = clean_party_text($party['address']);
                            $addressHi = clean_party_text($party['address_hi'] ?? '');
                            $stateDisplay = clean_party_text($party['state_display']);
                            $isNational = strcasecmp($rawType, 'National') === 0;
                            $isState = strcasecmp($rawType, 'State') === 0;
                            $typePillCls = $isNational ? 'bg-indigo' : ($isState ? 'bg-emerald' : 'bg-blue');
                            $scopeLabel = $isNational ? ('All India (HQ: ' . $stateDisplay . ')') : $stateDisplay;
                            $partyUrl = trim((string)($party['url'] ?? ''));
                            $validUrl = $partyUrl !== '' && preg_match('#^https?://#i', $partyUrl);
                            $statusVal = strtoupper(clean_party_text($party['status']) ?: 'ACTIVE');
                            $statusCls = $statusVal === 'ACTIVE'
                                ? 'status-success'
                                : ($statusVal === 'DELISTED'
                                    ? 'status-danger'
                                    : ($statusVal === 'INACTIVE' ? 'status-warning' : 'status-muted'));
                            $lastUpdate = format_eci_date($party['last_update']);
                            $singlePartyUrl = get_party_page_url((string)($party['slug'] ?? ''), (int)$party['id']);
                            $symbolInfo = get_party_symbol_image($party['symbol'] ?? '');
                        ?>
                            <tr style="border-bottom:1px solid var(--border-light); vertical-align:top;">
                                <td style="padding:10px; color:var(--text-muted); font-weight:600;"><?= $rowNo ?></td>
                                <td style="padding:10px; min-width:230px;">
                                    <a href="<?= e($singlePartyUrl) ?>" style="font-weight:700; color:var(--navy-main); line-height:1.35; text-decoration:none; display:inline-block;">
                                        <?= e($partyName) ?>
                                    </a>
                                    <?php if ($partyNameHi !== ''): ?>
                                        <div style="font-family:var(--font-hi); font-size:0.86rem; font-weight:600; color:var(--green-dark); line-height:1.35; margin-top:2px;" lang="hi"><?= e($partyNameHi) ?></div>
                                    <?php endif; ?>
                                    <?php if ($lastUpdate !== ''): ?>
                                        <div style="font-size:0.72rem; color:var(--text-muted); margin-top:3px;">Updated: <?= e($lastUpdate) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <span class="cat-pill <?= $typePillCls ?>"><?= e($rawType) ?></span>
                                </td>
                                <td style="padding:10px; min-width:155px;">
                                    <?php if ($symbolInfo): ?>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <?php if (!empty($symbolInfo['url'])): ?>
                                                <img src="<?= e($symbolInfo['url']) ?>" alt="<?= e($symbolInfo['label']) ?>" loading="lazy"
                                                     style="width:34px; height:34px; object-fit:contain; background:#FFFFFF; border:1px solid var(--border-color); border-radius:6px; padding:3px; flex-shrink:0;">
                                            <?php endif; ?>
                                            <span class="badge-tag badge-elected" title="<?= e($symbolInfo['raw']) ?>">
                                                <?php if (empty($symbolInfo['url'])): ?><i class="fas fa-certificate"></i> <?php endif; ?>
                                                <?= e($symbolInfo['label']) ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size:0.78rem; color:var(--text-muted);">Unreserved</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px; color:var(--text-secondary); white-space:nowrap;">
                                    <?= e($scopeLabel) ?>
                                </td>
                                <td style="padding:10px; font-size:0.8rem; color:var(--text-secondary); min-width:240px; max-width:380px; line-height:1.4;">
                                    <div><?= e($addressText ?: 'Not listed') ?></div>
                                    <?php if ($addressHi !== ''): ?>
                                        <div style="font-family:var(--font-hi); font-size:0.79rem; color:var(--text-muted); margin-top:4px; padding-top:4px; border-top:1px dashed var(--border-light); line-height:1.4;" lang="hi"><?= e($addressHi) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <span class="status-chip <?= $statusCls ?>"><span class="status-dot"></span> <?= e($statusVal) ?></span>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <a href="<?= e($singlePartyUrl) ?>"
                                           style="font-size:0.78rem; font-weight:700; color:var(--navy-main); text-decoration:none;">
                                            Party Page <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i>
                                        </a>
                                        <?php if ($isNational || $isState): ?>
                                            <a href="<?= get_site_url('elections?' . http_build_query(['mp_q' => $partyName]) . '#mp-directory') ?>"
                                               style="font-size:0.78rem; font-weight:700; color:var(--saffron-main); text-decoration:none;">
                                                MPs
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($validUrl): ?>
                                            <a href="<?= e($partyUrl) ?>" target="_blank" rel="noopener"
                                               style="font-size:0.78rem; font-weight:700; color:var(--green-main); text-decoration:none;">
                                                Site <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $rowFb = normalize_party_social_url($party['facebook'] ?? '', 'facebook');
                                    $rowIg = normalize_party_social_url($party['instagram'] ?? '', 'instagram');
                                    $rowX = normalize_party_social_url($party['x'] ?? '', 'x');
                                    $rowYt = normalize_party_social_url($party['youtube'] ?? '', 'youtube');
                                    $rowLi = normalize_party_social_url($party['linkedin'] ?? '', 'linkedin');
                                    $rowWa = build_whatsapp_action_url($party['whatsapp'] ?? '');
                                    $rowMob = clean_party_text($party['mobile'] ?? '');
                                    $rowMobTel = preg_replace('/[^0-9+]/', '', $rowMob) ?? '';
                                    $rowMc = clean_party_text($party['misscall'] ?? '');
                                    $rowMcTel = preg_replace('/[^0-9+]/', '', $rowMc) ?? '';
                                    $rowEmailRaw = strtolower(clean_party_text($party['email'] ?? ''));
                                    $rowEmail = $rowEmailRaw !== '' && filter_var($rowEmailRaw, FILTER_VALIDATE_EMAIL) ? $rowEmailRaw : '';
                                    if ($rowFb !== '' || $rowIg !== '' || $rowX !== '' || $rowYt !== '' || $rowLi !== '' || $rowWa !== '' || $rowMobTel !== '' || $rowMcTel !== '' || $rowEmail !== ''):
                                    ?>
                                        <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap; margin-top:5px; font-size:0.76rem;">
                                            <?php if ($rowMobTel !== ''): ?><a href="tel:<?= e($rowMobTel) ?>" title="Helpline: <?= e($rowMob) ?>" style="color:var(--navy-accent);"><i class="fas fa-phone"></i></a><?php endif; ?>
                                            <?php if ($rowMcTel !== ''): ?><a href="tel:<?= e($rowMcTel) ?>" title="Missed Call: <?= e($rowMc) ?>" style="color:var(--saffron-main);"><i class="fas fa-phone-volume"></i></a><?php endif; ?>
                                            <?php if ($rowWa !== ''): ?><a href="<?= e($rowWa) ?>" target="_blank" rel="noopener" title="WhatsApp" style="color:#16A34A;"><i class="fab fa-whatsapp"></i></a><?php endif; ?>
                                            <?php if ($rowEmail !== ''): ?><a href="mailto:<?= e($rowEmail) ?>" title="Email: <?= e($rowEmail) ?>" style="color:#DC2626;"><i class="fas fa-envelope"></i></a><?php endif; ?>
                                            <?php if ($rowFb !== ''): ?><a href="<?= e($rowFb) ?>" target="_blank" rel="noopener" title="Facebook" style="color:#1877F2;"><i class="fab fa-facebook"></i></a><?php endif; ?>
                                            <?php if ($rowIg !== ''): ?><a href="<?= e($rowIg) ?>" target="_blank" rel="noopener" title="Instagram" style="color:#E1306C;"><i class="fab fa-instagram"></i></a><?php endif; ?>
                                            <?php if ($rowX !== ''): ?><a href="<?= e($rowX) ?>" target="_blank" rel="noopener" title="X (Twitter)" style="color:#0F172A;"><i class="fab fa-x-twitter"></i></a><?php endif; ?>
                                            <?php if ($rowYt !== ''): ?><a href="<?= e($rowYt) ?>" target="_blank" rel="noopener" title="YouTube" style="color:#DC2626;"><i class="fab fa-youtube"></i></a><?php endif; ?>
                                            <?php if ($rowLi !== ''): ?><a href="<?= e($rowLi) ?>" target="_blank" rel="noopener" title="LinkedIn" style="color:#0A66C2;"><i class="fab fa-linkedin"></i></a><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display:flex; justify-content:center; align-items:center; gap:8px; flex-wrap:wrap; margin-top:18px;">
                <?php
                $baseParams = array_filter([
                    'type' => $filterType,
                    'status' => $filterStatus,
                    'state_code' => $filterState,
                    'has_symbol' => $filterSymbol,
                    'q' => $filterQuery,
                ], fn($v) => $v !== '');
                if ($page > 1):
                    $prevUrl = get_site_url('parties?' . http_build_query($baseParams + ['page' => $page - 1]));
                ?>
                    <a href="<?= $prevUrl ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>

                <span style="font-size:0.84rem; font-weight:700; color:var(--navy-main); padding:0 8px;">
                    Page <?= $page ?> / <?= number_format($totalPages) ?>
                </span>

                <?php if ($page < $totalPages):
                    $nextUrl = get_site_url('parties?' . http_build_query($baseParams + ['page' => $page + 1]));
                ?>
                    <a href="<?= $nextUrl ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


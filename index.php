<?php
// index.php - Local Leaders Directory (100% Real MySQL Data, Tiranga Civic Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/pincode_helper.php';

$pdo = get_db_connection();

// Inline area filter parameters from GET query string (no fixed or session-locked area)
$viewStateCode = trim((string)($_GET['state_code'] ?? ''));
$viewDistrictCode = trim((string)($_GET['district_code'] ?? ''));
$viewBlockCode = trim((string)($_GET['block_code'] ?? ''));

$rawCategory = trim($_GET['cat'] ?? 'all');
$selectedCategory = ($rawCategory === '' || strtolower($rawCategory) === 'all')
    ? 'all'
    : normalize_leader_category_slug($rawCategory);
$searchQuery = trim($_GET['q'] ?? '');
$pincodeInput = normalize_pincode_digits($_GET['pincode'] ?? '');

// Support direct 6-digit PIN lookup via `?pincode=841301` OR typing a 6-digit PIN in `q`
$activePincode = '';
$resolvedPinSummary = null;
if ($pincodeInput !== '' && is_valid_indian_pincode($pincodeInput)) {
    $activePincode = $pincodeInput;
} elseif ($searchQuery !== '' && is_valid_indian_pincode($searchQuery) && $pincodeInput === '') {
    $activePincode = $searchQuery;
    $searchQuery = '';
}
if ($activePincode !== '') {
    $resolvedPinSummary = get_pincode_area_summary($pdo, $activePincode);
    if ($resolvedPinSummary !== null && $viewStateCode === '' && $viewDistrictCode === '' && $viewBlockCode === '') {
        $priPin = $resolvedPinSummary['primary'];
        $viewStateCode = (string)($priPin['state_code'] ?? '');
        $viewDistrictCode = (string)($priPin['district_code'] ?? '');
        if (!empty($priPin['lgd_matched'])) {
            $viewBlockCode = (string)($priPin['block_code'] ?? '');
        }
    }
}

// Resolve real State, District & Block names from `op_sdb`
$states = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();
$stateNameMap = [];
foreach ($states as $stRow) {
    $stateNameMap[(string)$stRow['state_code']] = $stRow['state'];
}
if ($viewStateCode !== '' && !isset($stateNameMap[$viewStateCode])) {
    $viewStateCode = '';
}
$viewStateName = $viewStateCode !== '' ? ($stateNameMap[$viewStateCode] ?? 'All India') : 'All India';

$districts = [];
$districtNameMap = [];
if ($viewStateCode !== '') {
    $distStmt = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $distStmt->execute([$viewStateCode]);
    $districts = $distStmt->fetchAll();
    foreach ($districts as $dtRow) {
        $districtNameMap[(string)$dtRow['district_code']] = $dtRow['district'];
    }
}
if ($viewDistrictCode !== '' && !isset($districtNameMap[$viewDistrictCode])) {
    $viewDistrictCode = '';
}
$viewDistrictName = $districtNameMap[$viewDistrictCode] ?? '';

$blocks = [];
$blockNameMap = [];
if ($viewDistrictCode !== '') {
    $blkStmt = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $blkStmt->execute([$viewDistrictCode]);
    $blocks = $blkStmt->fetchAll();
    foreach ($blocks as $bRow) {
        $blockNameMap[(string)$bRow['block_code']] = $bRow['block'];
    }
}
if ($viewBlockCode !== '' && !isset($blockNameMap[$viewBlockCode])) {
    $viewBlockCode = '';
}
$viewBlockName = $blockNameMap[$viewBlockCode] ?? '';

// Canonical 25-type taxonomy from `leader_types`
$leaderTypeGroups = get_leader_types($pdo, true, 'alpha');
$leaderTypeMap = [];
foreach (get_leader_types($pdo) as $ltItem) {
    $leaderTypeMap[$ltItem['slug']] = $ltItem;
}
if ($selectedCategory !== 'all' && $selectedCategory !== 'mp' && $selectedCategory !== 'legislators' && !isset($leaderTypeMap[$selectedCategory])) {
    $selectedCategory = 'all';
}
$selectedTypeMeta = ($selectedCategory !== 'all' && $selectedCategory !== 'mp' && $selectedCategory !== 'legislators') ? get_leader_type_meta($selectedCategory, $pdo) : null;

// Table existence checks
$hasPoliticalPartyTable = (bool)$pdo->query("SHOW TABLES LIKE 'political_party'")->fetch();
$hasMlcTable = (bool)$pdo->query("SHOW TABLES LIKE 'mlc'")->fetch();

// 1. Live National Directory Totals from Real Pan-India Tables
$globalTotals = [
    'mps' => (int)$pdo->query("SELECT COUNT(*) FROM mp")->fetchColumn(),
    'mp_ls' => (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'ls'")->fetchColumn(),
    'mp_rs' => (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'rs'")->fetchColumn(),
    'mlcs' => $hasMlcTable ? (int)$pdo->query("SELECT COUNT(*) FROM mlc WHERE status = 'ACTIVE'")->fetchColumn() : 0,
    'parties' => $hasPoliticalPartyTable ? (int)$pdo->query("SELECT COUNT(*) FROM political_party")->fetchColumn() : 0,
    'blocks' => (int)$pdo->query("SELECT COUNT(*) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
];

$mlcStateCounts = [];
if ($hasMlcTable) {
    foreach ($pdo->query("SELECT state_code, COUNT(*) AS cnt FROM mlc WHERE status = 'ACTIVE' GROUP BY state_code") as $msRow) {
        $mlcStateCounts[(string)$msRow['state_code']] = (int)$msRow['cnt'];
    }
}

// 2. Category Pill Live Counts for active State/UT (Mukhiya & MLA scheduled for next phase on index.php)
$typeCounts = [];
foreach ($pdo->query("SELECT category, COUNT(*) AS cnt FROM leaders GROUP BY category") as $row) {
    $norm = normalize_leader_category_slug($row['category']);
    $typeCounts[$norm] = ($typeCounts[$norm] ?? 0) + (int)$row['cnt'];
}
$mpCntStmt = $pdo->prepare("SELECT type, COUNT(*) AS cnt FROM mp WHERE (? = '' OR state_code = ?) GROUP BY type");
$mpCntStmt->execute([$viewStateCode, $viewStateCode]);
foreach ($mpCntStmt as $row) {
    if ($row['type'] === 'ls') {
        $typeCounts['mp-lok-sabha'] = ($typeCounts['mp-lok-sabha'] ?? 0) + (int)$row['cnt'];
    } elseif ($row['type'] === 'rs') {
        $typeCounts['mp-rajya-sabha'] = ($typeCounts['mp-rajya-sabha'] ?? 0) + (int)$row['cnt'];
    }
}
if ($hasMlcTable) {
    $mlcCntStmt = $pdo->prepare("SELECT COUNT(*) FROM mlc WHERE status = 'ACTIVE' AND (? = '' OR state_code = ?)");
    $mlcCntStmt->execute([$viewStateCode, $viewStateCode]);
    $typeCounts['mlc'] = ($typeCounts['mlc'] ?? 0) + (int)$mlcCntStmt->fetchColumn();
}
if ($hasPoliticalPartyTable) {
    $poStatStmt = $pdo->prepare("SELECT COUNT(*) FROM political_party WHERE UPPER(TRIM(status)) = 'ACTIVE' AND (? = '' OR TRIM(type) = 'National' OR state_code = ?)");
    $poStatStmt->execute([$viewStateCode, $viewStateCode]);
    $typeCounts['political-party-office'] = ($typeCounts['political-party-office'] ?? 0) + (int)$poStatStmt->fetchColumn();
}

// Decide which real directory sections should render on index.php
$showMpSection = in_array($selectedCategory, ['all', 'mp', 'mp-lok-sabha', 'mp-rajya-sabha'], true);
$showMlcSection = in_array($selectedCategory, ['all', 'mlc', 'legislators'], true);
$showPartySection = in_array($selectedCategory, ['all', 'political-party-office'], true);

// 3. Query Real Members of Parliament (`mp` table — `sansad.in`)
$areaMps = [];
if ($showMpSection) {
    $mpWhere = ["1=1"];
    $mpParams = [];
    if ($selectedCategory === 'mp-lok-sabha') {
        $mpWhere[] = "type = 'ls'";
    } elseif ($selectedCategory === 'mp-rajya-sabha') {
        $mpWhere[] = "type = 'rs'";
    }
    if ($viewStateCode !== '') {
        $mpWhere[] = "state_code = ?";
        $mpParams[] = $viewStateCode;
    }
    if ($searchQuery !== '') {
        $mpWhere[] = "(member_name LIKE ? OR constituency LIKE ? OR party_name LIKE ? OR state LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($mpParams, $wild, $wild, $wild, $wild);
    }

    $mpWhereSql = implode(' AND ', $mpWhere);
    $mpLimit = in_array($selectedCategory, ['mp', 'mp-lok-sabha', 'mp-rajya-sabha'], true) ? 36 : 8;
    $distMatchParam = $viewDistrictName !== '' ? ('%' . $viewDistrictName . '%') : '__no_match__';

    $mpSql = "
        SELECT * FROM mp
        WHERE {$mpWhereSql}
        ORDER BY CASE WHEN constituency LIKE ? THEN 0 ELSE 1 END ASC,
                 type = 'ls' DESC,
                 membership_status = 'Sitting' DESC,
                 constituency ASC,
                 member_name ASC
        LIMIT {$mpLimit}
    ";
    $mpStmt = $pdo->prepare($mpSql);
    $mpStmt->execute(array_merge($mpParams, [$distMatchParam]));
    $areaMps = $mpStmt->fetchAll();
}

// 3b. Query Real Members of Legislative Council (`mlc` table — neva.gov.in UP, Bihar & AP)
$areaMlcs = [];
$areaMlcsTotal = 0;
if ($showMlcSection && $hasMlcTable) {
    $mlcWhere = ["status = 'ACTIVE'"];
    $mlcParams = [];
    if ($viewStateCode !== '') {
        $mlcWhere[] = "state_code = ?";
        $mlcParams[] = $viewStateCode;
    }
    if ($searchQuery !== '') {
        $mlcWhere[] = "(member_name LIKE ? OR constituency LIKE ? OR COALESCE(constituency_area, '') LIKE ? OR constituency_type LIKE ? OR party_name LIKE ? OR COALESCE(role_designation, '') LIKE ? OR state LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($mlcParams, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }
    $mlcWhereSql = implode(' AND ', $mlcWhere);

    $mlcCntQ = $pdo->prepare("SELECT COUNT(*) FROM mlc WHERE {$mlcWhereSql}");
    $mlcCntQ->execute($mlcParams);
    $areaMlcsTotal = (int)$mlcCntQ->fetchColumn();

    if ($selectedCategory === 'all' && $viewStateCode === '' && $searchQuery === '' && $viewDistrictName === '') {
        // Balanced preview: top 3 MLCs each from Bihar (br-), UP (up-), and AP (ap-)
        $mlcSql = "
            SELECT ranked.* FROM (
                SELECT m.*,
                       ROW_NUMBER() OVER (
                           PARTITION BY m.state_code
                           ORDER BY (m.role_designation IS NOT NULL AND m.role_designation <> '') DESC,
                                    (m.photo_url IS NOT NULL AND m.photo_url <> '') DESC,
                                    m.seat_no IS NULL ASC,
                                    m.seat_no ASC,
                                    m.id ASC
                       ) AS state_rn
                FROM mlc m
                WHERE m.status = 'ACTIVE'
            ) ranked
            WHERE ranked.state_rn <= 3
            ORDER BY FIELD(ranked.state_code, '10', '9', '28'), ranked.state_rn ASC
            LIMIT 9
        ";
        $areaMlcs = $pdo->query($mlcSql)->fetchAll();
    } else {
        $mlcLimit = in_array($selectedCategory, ['mlc', 'legislators'], true)
            ? ($viewStateCode !== '' ? 110 : 36)
            : 9;
        $mlcDistMatch = $viewDistrictName !== '' ? ('%' . $viewDistrictName . '%') : '__no_match__';
        $mlcSql = "
            SELECT * FROM mlc
            WHERE {$mlcWhereSql}
            ORDER BY CASE WHEN (constituency LIKE ? OR COALESCE(constituency_area, '') LIKE ? OR COALESCE(permanent_address, '') LIKE ?) THEN 0 ELSE 1 END ASC,
                     (role_designation IS NOT NULL AND role_designation <> '') DESC,
                     membership_status = 'Sitting' DESC,
                     (photo_url IS NOT NULL AND photo_url <> '') DESC,
                     FIELD(state_code, '10', '9', '28'),
                     seat_no IS NULL ASC,
                     seat_no ASC,
                     member_name ASC
            LIMIT {$mlcLimit}
        ";
        $mlcStmt = $pdo->prepare($mlcSql);
        $mlcStmt->execute(array_merge($mlcParams, [$mlcDistMatch, $mlcDistMatch, $mlcDistMatch]));
        $areaMlcs = $mlcStmt->fetchAll();
    }
}

// 6. Query Real Political Party Offices (`political_party` table)
$partyOffices = [];
$partyOfficesTotal = 0;
if ($showPartySection && $hasPoliticalPartyTable) {
    $poWhere = ["UPPER(TRIM(pp.status)) = 'ACTIVE'"];
    $poParams = [];
    if ($viewStateCode !== '') {
        $poWhere[] = "(TRIM(pp.type) = 'National' OR pp.state_code = ?)";
        $poParams[] = $viewStateCode;
    }
    if ($searchQuery !== '') {
        $poWhere[] = "(pp.name LIKE ? OR COALESCE(pp.name_hi, '') LIKE ? OR pp.address LIKE ? OR COALESCE(pp.address_hi, '') LIKE ? OR pp.symbol LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($poParams, $wild, $wild, $wild, $wild, $wild);
    }
    $poWhereSql = implode(' AND ', $poWhere);

    $poCnt = $pdo->prepare("SELECT COUNT(*) FROM political_party pp WHERE {$poWhereSql}");
    $poCnt->execute($poParams);
    $partyOfficesTotal = (int)$poCnt->fetchColumn();

    $poLimit = $selectedCategory === 'political-party-office' ? 24 : 6;
    $poSql = "
        SELECT pp.*, COALESCE(s.state, pp.state) AS state_display
        FROM political_party pp
        LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
               ON pp.state_code = s.state_code
        WHERE {$poWhereSql}
        ORDER BY CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
                 CASE WHEN (? <> '' AND pp.state_code = ?) THEN 0 ELSE 1 END ASC,
                 pp.name ASC
        LIMIT {$poLimit}
    ";
    $poStmt = $pdo->prepare($poSql);
    $poStmt->execute(array_merge($poParams, [$viewStateCode, $viewStateCode]));
    $partyOffices = $poStmt->fetchAll();
}

// Query Custom Registered Leaders (`leaders` table) ONLY when non-empty (excluding Mukhiya & MLA until next phase)
$customLeaders = [];
$leaderCount = (int)$pdo->query("SELECT COUNT(*) FROM leaders")->fetchColumn();
if ($leaderCount > 0 && !in_array($selectedCategory, ['political-party-office', 'mukhiya', 'mla', 'mlc', 'legislators'], true)) {
    $ldrWhere = ["l.category NOT IN ('mukhiya', 'mla', 'mlc')"];
    $ldrParams = [];
    if ($selectedCategory === 'mp') {
        $ldrWhere[] = "l.category IN ('mp-lok-sabha', 'mp-rajya-sabha', 'mp')";
    } elseif ($selectedCategory !== 'all') {
        $ldrWhere[] = "l.category = ?";
        $ldrParams[] = $selectedCategory;
    }
    if ($viewStateCode !== '') {
        $ldrWhere[] = "l.state_code = ?";
        $ldrParams[] = $viewStateCode;
    }
    if ($viewDistrictCode !== '') {
        $ldrWhere[] = "l.district_code = ?";
        $ldrParams[] = $viewDistrictCode;
    }
    if ($viewBlockCode !== '') {
        $higherSlugsSql = implode(', ', array_map(fn($s) => $pdo->quote($s), get_district_and_higher_leader_slugs()));
        $ldrWhere[] = "(l.block_code = ? OR l.category IN ({$higherSlugsSql}))";
        $ldrParams[] = $viewBlockCode;
    }
    if ($searchQuery !== '') {
        $ldrWhere[] = "(l.full_name_en LIKE ? OR l.full_name_hi LIKE ? OR l.designation_hi LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($ldrParams, $wild, $wild, $wild);
    }

    $leaderOrderSql = get_leader_category_order_sql('l.category');
    $ldrSql = "
        SELECT l.*,
               p.name_en AS panchayat_name,
               sdb.block AS block_name,
               COALESCE(sdb.district, dist.district) AS district_name,
               COALESCE(rt.review_count, 0) AS review_count,
               rt.avg_rating,
               COALESCE(wk.works_count, 0) AS works_count
        FROM leaders l
        LEFT JOIN panchayats p ON l.panchayat_id = p.id
        LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code
        LEFT JOIN (SELECT DISTINCT district_code, district FROM op_sdb) dist ON l.district_code = dist.district_code
        LEFT JOIN (
            SELECT leader_id,
                   COUNT(*) AS review_count,
                   ROUND(AVG((road_drainage + sanitation + drinking_water + accessibility + grievance_redressal) / 5.0), 1) AS avg_rating
            FROM public_ratings
            GROUP BY leader_id
        ) rt ON l.id = rt.leader_id
        LEFT JOIN (
            SELECT leader_id, COUNT(*) AS works_count
            FROM development_works
            GROUP BY leader_id
        ) wk ON l.id = wk.leader_id
        WHERE " . implode(' AND ', $ldrWhere) . "
        ORDER BY {$leaderOrderSql}, l.views_count DESC
        LIMIT 12
    ";
    $ldrStmt = $pdo->prepare($ldrSql);
    $ldrStmt->execute($ldrParams);
    $customLeaders = $ldrStmt->fetchAll();
}

$pageTitle = "Local Leaders Directory";
$pageDescription = "Local Leaders Directory — Verified directory of Lok Sabha & Rajya Sabha MPs, UP/Bihar/AP Legislative Council MLCs, ECI Political Parties, and 25 Representative Categories across India.";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Section A: Local Leaders Directory Hero Banner -->
    <div class="tiranga-banner">
        <div>
            <h1><i class="fas fa-address-book" style="color:var(--saffron-main);"></i> Local Leaders Directory</h1>
            <p>
                Showing public records compiled from <a href="<?= get_site_url('source') ?>" style="color:#FF9933; text-decoration:underline; font-weight:700;">verified official sources</a> across India.
            </p>
        </div>
    </div>

    <!-- Section B: Quick Database Coverage KPI Strip -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:18px;">
        <a href="#mp-section" style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid var(--saffron-main); border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm); display:block; text-decoration:none;">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Lok Sabha MPs</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--saffron-dark); margin-top:2px;"><?= number_format($globalTotals['mp_ls']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">18th Lok Sabha (sansad.in)</div>
        </a>
        <a href="#mp-section" style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid var(--green-main); border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm); display:block; text-decoration:none;">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Rajya Sabha MPs</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--green-dark); margin-top:2px;"><?= number_format($globalTotals['mp_rs']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">Upper House &amp; Nominated</div>
        </a>
        <a href="<?= $globalTotals['mlcs'] > 0 ? '#mlc-section' : get_site_url('index?cat=mlc') ?>" style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid #E11D48; border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm); display:block; text-decoration:none;">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">State MLCs</div>
            <div style="font-size:1.35rem; font-weight:800; color:#BE123C; margin-top:2px;"><?= number_format($globalTotals['mlcs']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">UP, Bihar &amp; AP Councils</div>
        </a>
        <a href="<?= get_site_url('parties') ?>" style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid #7C3AED; border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm); display:block; text-decoration:none;">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Political Parties (ECI)</div>
            <div style="font-size:1.35rem; font-weight:800; color:#5B21B6; margin-top:2px;"><?= number_format($globalTotals['parties']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">With official ECI symbols</div>
        </a>
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid #0284C7; border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Covered Blocks</div>
            <div style="font-size:1.35rem; font-weight:800; color:#0369A1; margin-top:2px;"><?= number_format($globalTotals['blocks']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">36 States &amp; 783 Districts</div>
        </div>
    </div>

    <!-- Section C: Interactive Local Leaders Lookup Filter -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:16px 18px; margin-bottom:20px; box-shadow:var(--shadow-sm);">
        <form action="<?= get_site_url('index') ?>" method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(155px, 1fr)); gap:10px; align-items:flex-end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Type of Leader</label>
                <select name="cat" class="form-control" style="padding:8px 10px; font-size:0.86rem;">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All Directory Categories</option>
                    <?php foreach ($leaderTypeGroups as $group): ?>
                        <optgroup label="<?= (int)$group['group_no'] ?>. <?= e($group['group_name']) ?>">
                            <?php foreach ($group['items'] as $lt): ?>
                                <option value="<?= e($lt['slug']) ?>" <?= $selectedCategory === $lt['slug'] ? 'selected' : '' ?>>
                                    <?= e($lt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">State / UT</label>
                <select name="state_code" id="homeFilterState" class="form-control" style="padding:8px 10px; font-size:0.86rem;" onchange="loadHomeDistricts(this.value)">
                    <option value="">All States &amp; UTs</option>
                    <?php foreach ($states as $st): ?>
                        <option value="<?= e($st['state_code']) ?>" <?= $viewStateCode === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">District</label>
                <select name="district_code" id="homeFilterDistrict" class="form-control" style="padding:8px 10px; font-size:0.86rem;" onchange="loadHomeBlocks(this.value)">
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $dt): ?>
                        <option value="<?= e($dt['district_code']) ?>" <?= $viewDistrictCode === (string)$dt['district_code'] ? 'selected' : '' ?>>
                            <?= e($dt['district']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Block (Prakhand)</label>
                <select name="block_code" id="homeFilterBlock" class="form-control" style="padding:8px 10px; font-size:0.86rem;">
                    <option value="">All Blocks in District</option>
                    <?php foreach ($blocks as $blk): ?>
                        <option value="<?= e($blk['block_code']) ?>" <?= $viewBlockCode === (string)$blk['block_code'] ? 'selected' : '' ?>>
                            <?= e($blk['block']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Search Name / Constituency / Party</label>
                <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="e.g. Rajiv Pratap Rudy, Saran, BJP..." class="form-control" style="padding:8px 10px; font-size:0.86rem;">
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">PIN Code (Village Lookup)</label>
                <input type="text" name="pincode" value="<?= e($activePincode ?: $pincodeInput) ?>" maxlength="6" inputmode="numeric" placeholder="e.g. 841301" class="form-control" style="padding:8px 10px; font-size:0.86rem;">
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn-primary-tiranga" style="padding:9px 14px; font-size:0.86rem; flex:1;">
                    <i class="fas fa-search"></i> Find Leaders
                </button>
                <?php if ($selectedCategory !== 'all' || $searchQuery !== '' || $activePincode !== '' || $viewStateCode !== '' || $viewDistrictCode !== '' || $viewBlockCode !== ''): ?>
                    <a href="<?= get_site_url('index') ?>" class="btn-profile-view" style="padding:9px 10px; font-size:0.82rem; flex:unset;" title="Reset Filters">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($activePincode !== ''): ?>
            <div style="margin-top:12px; padding:10px 14px; border-radius:var(--radius-md); background:<?= $resolvedPinSummary ? '#ECFDF5' : '#FEF2F2' ?>; border:1px solid <?= $resolvedPinSummary ? '#A7F3D0' : '#FECACA' ?>; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; font-size:0.85rem;">
                <?php if ($resolvedPinSummary):
                    $priPin = $resolvedPinSummary['primary'];
                ?>
                    <div style="color:#065F46;">
                        <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                        <strong>PIN Code <?= e($activePincode) ?> Resolved:</strong>
                        <?= e($priPin['block_name']) ?> Block • <?= e($priPin['district_name']) ?> District • <?= e($priPin['state_name']) ?>
                        (<strong><?= number_format($resolvedPinSummary['village_count']) ?></strong> Villages)
                    </div>
                    <a href="<?= e(get_pincode_page_url($activePincode)) ?>" class="btn-green-tiranga" style="padding:6px 14px; font-size:0.8rem;">
                        <i class="fas fa-house-chimney"></i> View <?= number_format($resolvedPinSummary['village_count']) ?> Villages
                    </a>
                <?php else: ?>
                    <div style="color:#991B1B;">
                        <i class="fas fa-circle-exclamation"></i>
                        No villages matched PIN Code <strong><?= e($activePincode) ?></strong> in the directory.
                    </div>
                    <a href="<?= get_site_url('pincode') ?>" class="btn-profile-view" style="flex:unset; padding:6px 12px; font-size:0.8rem;">
                        Open PIN Finder
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Section D: Quick-Access Directory Shortcuts -->
    <div class="quick-shortcuts">
        <a href="<?= get_site_url('index?' . http_build_query(array_filter(['cat' => 'mp-lok-sabha', 'state_code' => $viewStateCode, 'district_code' => $viewDistrictCode], fn($v) => $v !== ''))) ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-navy">
                <i class="fas fa-landmark"></i>
            </div>
            <span>Lok Sabha MPs</span>
        </a>
        <a href="<?= get_site_url('index?' . http_build_query(array_filter(['cat' => 'mp-rajya-sabha', 'state_code' => $viewStateCode, 'district_code' => $viewDistrictCode], fn($v) => $v !== ''))) ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-green">
                <i class="fas fa-building-columns"></i>
            </div>
            <span>Rajya Sabha MPs</span>
        </a>
        <a href="<?= get_site_url('index?' . http_build_query(array_filter(['cat' => 'mlc', 'state_code' => $viewStateCode, 'district_code' => $viewDistrictCode], fn($v) => $v !== ''))) ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-indigo">
                <i class="fas fa-scroll"></i>
            </div>
            <span>Council MLCs</span>
        </a>
        <a href="<?= get_site_url('pincode') ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-purple">
                <i class="fas fa-map-pin"></i>
            </div>
            <span>PIN Code Finder</span>
        </a>
        <a href="<?= get_site_url('parties') ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-purple">
                <i class="fas fa-flag"></i>
            </div>
            <span>Political Parties</span>
        </a>
        <a href="<?= get_site_url('leaders') ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-rose">
                <i class="fas fa-layer-group"></i>
            </div>
            <span>All 25 Types</span>
        </a>
        <a href="<?= get_site_url('studio') ?>" class="shortcut-card">
            <div class="shortcut-icon-wrapper icon-orange">
                <i class="fas fa-paint-brush"></i>
            </div>
            <span>Poster Studio</span>
        </a>
    </div>

    <!-- Section E: Canonical 25-Type Leader Taxonomy Pills -->
    <section style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
            <h2 style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin:0;">
                <i class="fas fa-sitemap" style="color:var(--saffron-main);"></i> Browse by Type of Leader (25 Categories)
            </h2>
            <a href="<?= get_site_url('index?' . http_build_query(array_filter(['cat' => 'all', 'state_code' => $viewStateCode, 'district_code' => $viewDistrictCode, 'block_code' => $viewBlockCode, 'q' => $searchQuery], fn($v) => $v !== ''))) ?>"
               style="padding:4px 12px; border-radius:var(--radius-full); font-size:0.78rem; font-weight:700; text-decoration:none; border:1px solid <?= $selectedCategory === 'all' ? 'var(--saffron-main)' : 'var(--border-color)' ?>; background:<?= $selectedCategory === 'all' ? 'var(--saffron-main)' : '#FFFFFF' ?>; color:<?= $selectedCategory === 'all' ? '#FFFFFF' : 'var(--navy-main)' ?>;">
                Show Full Directory
            </a>
        </div>

        <?php foreach ($leaderTypeGroups as $group): ?>
            <div style="<?= (int)$group['group_no'] > 1 ? 'margin-top:10px; padding-top:10px; border-top:1px dashed var(--border-color);' : '' ?>">
                <div style="font-size:0.74rem; font-weight:800; color:<?= (int)$group['group_no'] === 2 ? 'var(--green-main)' : 'var(--text-muted)' ?>; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:6px;">
                    <?= (int)$group['group_no'] ?>. <?= e($group['group_name']) ?>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <?php foreach ($group['items'] as $lt):
                        $slug = $lt['slug'];
                        $cnt = $typeCounts[$slug] ?? 0;
                        $isActive = ($selectedCategory === $slug) || ($selectedCategory === 'mp' && in_array($slug, ['mp-lok-sabha', 'mp-rajya-sabha'], true));
                        $pillUrl = get_site_url('index?' . http_build_query(array_filter([
                            'cat' => $slug,
                            'state_code' => $viewStateCode,
                            'district_code' => $viewDistrictCode,
                            'block_code' => $viewBlockCode,
                            'q' => $searchQuery,
                        ], fn($v) => $v !== '')));
                    ?>
                        <a href="<?= $pillUrl ?>"
                           style="display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:var(--radius-full); font-size:0.78rem; font-weight:700; text-decoration:none; border:1px solid <?= $isActive ? 'var(--saffron-main)' : 'var(--border-color)' ?>; background:<?= $isActive ? 'var(--saffron-main)' : 'var(--white-surface)' ?>; color:<?= $isActive ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                            <i class="fas <?= e($lt['icon_class']) ?>" style="font-size:0.7rem;"></i>
                            <span><?= e($lt['name']) ?></span>
                            <?php if ($cnt > 0): ?>
                                <span style="padding:1px 6px; border-radius:var(--radius-full); font-size:0.68rem; background:<?= $isActive ? 'rgba(255,255,255,0.25)' : '#E2E8F0' ?>; color:<?= $isActive ? '#FFFFFF' : 'var(--navy-main)' ?>;">
                                    <?= number_format($cnt) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- Section F: Members of Parliament (`mp` table — Lok Sabha LS-18 & Rajya Sabha via sansad.in) -->
    <?php if ($showMpSection && (!empty($areaMps) || in_array($selectedCategory, ['mp', 'mp-lok-sabha', 'mp-rajya-sabha'], true))): ?>
        <section id="mp-section" style="margin-bottom:32px; background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:18px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-landmark"></i> Members of Parliament (Lok Sabha &amp; Rajya Sabha) — <?= e($viewStateName) ?>
                    </h2>
                    <p style="font-size:0.83rem; color:var(--text-muted); margin-top:2px;">
                        Showing <strong><?= count($areaMps) ?></strong> Parliament records from <code>sansad.in</code><?= $viewDistrictName !== '' ? ' (Prioritizing ' . e($viewDistrictName) . ')' : '' ?>
                    </p>
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <a href="<?= get_site_url('source#source-sansad') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                        <i class="fas fa-database"></i> Source: sansad.in
                    </a>
                    <a href="<?= get_site_url('elections?mp_state=' . urlencode($viewStateCode) . '#mp-directory') ?>" class="section-link">
                        Full 789 MP Directory <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <?php if (empty($areaMps)): ?>
                <p style="color:var(--text-muted); font-size:0.88rem; padding:16px 0;">
                    No Members of Parliament matched your filter in <?= e($viewStateName) ?>. <a href="<?= get_site_url('elections#mp-directory') ?>" style="color:var(--saffron-main); font-weight:700;">Browse All 789 MPs</a>.
                </p>
            <?php else: ?>
                <div class="cards-grid" style="margin-bottom:0;">
                    <?php foreach ($areaMps as $mp):
                        $isRs = $mp['type'] === 'rs';
                        $isNominated = $isRs && empty($mp['election']);
                        $houseBadge = !$isRs ? strtoupper($mp['election'] ?: 'LS-18') : ($mp['election'] ?: 'RS Nominated');
                        $mpUrl = get_mp_page_url((string)($mp['slug'] ?? ''), (int)$mp['id']);
                        $mpPartyMeta = resolve_party_card_meta($pdo, $mp['party_name'] ?? '');
                        $termSummary = $isRs
                            ? ('Term: ' . ($mp['term_period'] ?: 'Rajya Sabha') . (!empty($mp['age']) ? ' • Age: ' . (int)$mp['age'] : ''))
                            : ('LS Terms: ' . $mp['lok_sabha_terms']);
                    ?>
                        <div class="leader-card">
                            <div class="leader-card-cover" style="height:52px; background:<?= $isRs ? 'linear-gradient(135deg, #046A38, #0A192F)' : 'var(--navy-gradient)' ?>;">
                                <div style="position:absolute; top:10px; right:10px; z-index:2;">
                                    <span class="cat-pill <?= $isRs ? ($isNominated ? 'bg-purple' : 'bg-emerald') : 'bg-rose' ?>"><?= e($houseBadge) ?></span>
                                </div>
                            </div>
                            <div class="leader-card-body">
                                <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main);">
                                    <?php if (!empty($mp['photo_url'])): ?>
                                        <img src="<?= e($mp['photo_url']) ?>" alt="<?= e($mp['member_name']) ?>" loading="lazy"
                                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.25rem; color:var(--navy-main);">
                                            <i class="fas fa-landmark"></i>
                                        </span>
                                    <?php else: ?>
                                        <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.25rem; color:var(--navy-main);">
                                            <i class="fas fa-landmark"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="leader-header-info">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:6px;">
                                        <h3 class="leader-name" style="margin:0;">
                                            <a href="<?= e($mpUrl) ?>" style="color:inherit; text-decoration:none;"><?= e($mp['member_name']) ?></a>
                                        </h3>
                                        <span class="badge-tag <?= $mp['membership_status'] === 'Sitting' ? 'badge-elected' : 'badge-unverified' ?>" style="flex-shrink:0;">
                                            <?= e($mp['membership_status']) ?>
                                        </span>
                                    </div>
                                    <div class="leader-designation"><?= $isRs ? 'MP (Rajya Sabha)' : 'MP (Lok Sabha)' ?></div>
                                </div>

                                <div class="leader-panchayat">
                                    <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                    <span><strong><?= e($mp['constituency']) ?></strong><?= !$isRs ? ', ' . e($mp['state']) : '' ?></span>
                                </div>

                                <?php if ($mpPartyMeta): ?>
                                    <div style="margin-top:8px;">
                                        <?= render_party_chip_html($mpPartyMeta) ?>
                                    </div>
                                <?php endif; ?>

                                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:6px;">
                                    <i class="fas fa-history" style="color:var(--navy-accent);"></i> <?= e($termSummary) ?>
                                </div>

                                <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                    <a href="<?= e($mpUrl) ?>" class="btn-profile-view">
                                        View MP Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                    </a>
                                    <a href="<?= get_site_url('studio?mp_id=' . (int)$mp['id']) ?>" class="btn-profile-view" style="flex:unset; padding:8px 12px;" title="Create Greeting Poster">
                                        <i class="fas fa-paint-brush" style="color:var(--saffron-main);"></i> Poster
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Section G: Members of Legislative Council (`mlc` table — Vidhan Parishad via neva.gov.in) -->
    <?php if ($showMlcSection && (!empty($areaMlcs) || in_array($selectedCategory, ['mlc', 'legislators'], true))): ?>
        <section id="mlc-section" style="margin-bottom:32px; background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--green-main); border-radius:var(--radius-lg); padding:18px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-scroll"></i> Members of Legislative Council (MLCs — Vidhan Parishad) — <?= e($viewStateName) ?>
                    </h2>
                    <p style="font-size:0.83rem; color:var(--text-muted); margin-top:2px;">
                        Showing <strong><?= count($areaMlcs) ?></strong> of <strong><?= number_format($areaMlcsTotal) ?></strong> Council records from <code>neva.gov.in</code><?= $viewDistrictName !== '' ? ' (Prioritizing ' . e($viewDistrictName) . ')' : '' ?>
                    </p>
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <a href="<?= get_site_url('source#source-neva') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                        <i class="fas fa-database"></i> Source: neva.gov.in
                    </a>
                    <a href="<?= get_site_url('elections?' . http_build_query(array_filter(['mlc_state' => in_array($viewStateCode, ['9', '10', '28'], true) ? $viewStateCode : ''], fn($v) => $v !== '')) . '#mlc-directory') ?>" class="section-link">
                        All <?= number_format($globalTotals['mlcs']) ?> MLCs Directory <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- State Council Switcher Pills -->
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
                <?php
                $mlcStateTabs = [
                    ['code' => '',   'label' => 'All Councils (' . number_format($globalTotals['mlcs']) . ')'],
                    ['code' => '10', 'label' => 'Bihar (' . number_format($mlcStateCounts['10'] ?? 0) . ')'],
                    ['code' => '9',  'label' => 'Uttar Pradesh (' . number_format($mlcStateCounts['9'] ?? 0) . ')'],
                    ['code' => '28', 'label' => 'Andhra Pradesh (' . number_format($mlcStateCounts['28'] ?? 0) . ')'],
                ];
                foreach ($mlcStateTabs as $mTab):
                    $mActive = ($viewStateCode === $mTab['code']);
                    $mUrl = get_site_url('index?' . http_build_query(array_filter([
                        'cat' => in_array($selectedCategory, ['mlc', 'legislators'], true) ? $selectedCategory : 'mlc',
                        'state_code' => $mTab['code'],
                        'q' => $searchQuery,
                    ], fn($v) => $v !== '')) . '#mlc-section');
                ?>
                    <a href="<?= $mUrl ?>"
                       style="padding:4px 12px; border-radius:var(--radius-full); font-size:0.78rem; font-weight:700; text-decoration:none; border:1px solid <?= $mActive ? 'var(--green-main)' : 'var(--border-color)' ?>; background:<?= $mActive ? 'var(--green-main)' : 'var(--white-surface)' ?>; color:<?= $mActive ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                        <?= e($mTab['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($areaMlcs)): ?>
                <div style="padding:18px 14px; background:var(--white-surface); border:1px dashed var(--border-color); border-radius:var(--radius-md); text-align:center;">
                    <p style="color:var(--text-secondary); font-size:0.88rem; margin:0 0 10px;">
                        No Legislative Council (MLC) records are currently indexed for <strong><?= e($viewStateName) ?></strong><?= $searchQuery !== '' ? ' matching "' . e($searchQuery) . '"' : '' ?>. Currently verified Councils: Uttar Pradesh (99), Bihar (75), and Andhra Pradesh (57).
                    </p>
                    <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
                        <a href="<?= get_site_url('index?cat=mlc&state_code=10') ?>" class="btn-green-tiranga" style="padding:6px 14px; font-size:0.82rem;">Bihar MLCs (75)</a>
                        <a href="<?= get_site_url('index?cat=mlc&state_code=9') ?>" class="btn-primary-tiranga" style="padding:6px 14px; font-size:0.82rem;">UP MLCs (99)</a>
                        <a href="<?= get_site_url('index?cat=mlc&state_code=28') ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">Andhra Pradesh MLCs (57)</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="cards-grid" style="margin-bottom:0;">
                    <?php foreach ($areaMlcs as $mlcRow):
                        $mlcUrl = get_mlc_page_url((string)($mlcRow['slug'] ?? ''), (int)$mlcRow['id']);
                        $mlcPrefixBadge = strtoupper(get_mlc_state_slug_prefix((string)$mlcRow['state_code'], (string)$mlcRow['state'])) . ' MLC';
                        $mlcPartyMeta = resolve_party_card_meta($pdo, $mlcRow['party_name'] ?? '');
                        $mlcSeatLabel = trim((string)($mlcRow['constituency'] ?: $mlcRow['constituency_type']));
                        $mlcCoverBg = match ((string)$mlcRow['state_code']) {
                            '10' => 'linear-gradient(135deg, #046A38, #0A192F)',
                            '9'  => 'linear-gradient(135deg, #0A192F, #1E3A8A)',
                            default => 'linear-gradient(135deg, #5B21B6, #0A192F)',
                        };
                    ?>
                        <div class="leader-card">
                            <div class="leader-card-cover" style="height:52px; background:<?= $mlcCoverBg ?>;">
                                <div style="position:absolute; top:10px; right:10px; z-index:2;">
                                    <span class="cat-pill bg-purple"><?= e($mlcPrefixBadge) ?></span>
                                </div>
                            </div>
                            <div class="leader-card-body">
                                <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main);">
                                    <?php if (!empty($mlcRow['photo_url'])): ?>
                                        <img src="<?= e($mlcRow['photo_url']) ?>" alt="<?= e($mlcRow['member_name']) ?>" loading="lazy"
                                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.25rem; color:var(--navy-main);">
                                            <i class="fas fa-scroll"></i>
                                        </span>
                                    <?php else: ?>
                                        <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.25rem; color:var(--navy-main);">
                                            <i class="fas fa-scroll"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="leader-header-info">
                                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:6px;">
                                        <h3 class="leader-name" style="margin:0;">
                                            <a href="<?= e($mlcUrl) ?>" style="color:inherit; text-decoration:none;"><?= e($mlcRow['member_name']) ?></a>
                                        </h3>
                                        <span class="badge-tag badge-elected" style="flex-shrink:0;">
                                            <?= e($mlcRow['membership_status'] ?: 'Sitting') ?>
                                        </span>
                                    </div>
                                    <div class="leader-designation">
                                        <?= e($mlcRow['role_designation'] ?: 'Member of Legislative Council') ?>
                                    </div>
                                </div>

                                <div class="leader-panchayat">
                                    <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                    <span><strong><?= e($mlcSeatLabel) ?></strong>, <?= e($mlcRow['state']) ?></span>
                                </div>

                                <?php if ($mlcPartyMeta): ?>
                                    <div style="margin-top:8px;">
                                        <?= render_party_chip_html($mlcPartyMeta) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($mlcRow['constituency_type'])): ?>
                                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:6px;">
                                        <i class="fas fa-building-columns" style="color:var(--navy-accent);"></i> Quota: <?= e($mlcRow['constituency_type']) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                    <a href="<?= e($mlcUrl) ?>" class="btn-profile-view">
                                        View MLC Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                    </a>
                                    <a href="<?= get_site_url('studio?mlc_id=' . (int)$mlcRow['id']) ?>" class="btn-profile-view" style="flex:unset; padding:8px 12px;" title="Create Greeting Poster">
                                        <i class="fas fa-paint-brush" style="color:var(--saffron-main);"></i> Poster
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Section I: Political Party Offices (`Businesses & Establishments` -> `political_party`) -->
    <?php if ($showPartySection && (!empty($partyOffices) || $selectedCategory === 'political-party-office')): ?>
        <section id="party-office-section" style="margin-bottom:32px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-flag"></i> Political Party Offices (Businesses &amp; Establishments)
                    </h2>
                    <p style="font-size:0.83rem; color:var(--text-muted); margin-top:2px;">
                        Active ECI National &amp; <?= $viewStateCode !== '' ? e($viewStateName) . ' State' : 'State' ?> Parties with official Election Symbols • Showing <strong><?= count($partyOffices) ?></strong> of <strong><?= number_format($partyOfficesTotal) ?></strong> active offices
                    </p>
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <a href="<?= get_site_url('source#source-eci') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                        <i class="fas fa-database"></i> Source: eci.gov.in
                    </a>
                    <a href="<?= get_site_url('parties?' . http_build_query(array_filter(['state_code' => $viewStateCode, 'q' => $searchQuery], fn($v) => $v !== ''))) ?>" class="section-link">
                        All <?= number_format($globalTotals['parties']) ?> Political Parties <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <div class="cards-grid">
                <?php foreach ($partyOffices as $po):
                    $poType = trim((string)($po['type'] ?? 'Registered'));
                    $poPillClass = match (strtolower($poType)) {
                        'national' => 'bg-rose',
                        'state' => 'bg-indigo',
                        default => 'bg-amber',
                    };
                    $poName = clean_party_display_name($po['name']);
                    $poNameHi = clean_party_display_text($po['name_hi'] ?? '');
                    $poAddr = clean_party_display_text($po['address'] ?? '');
                    $poAddrHi = clean_party_display_text($po['address_hi'] ?? '');
                    $poSym = get_party_symbol_image($po['symbol'] ?? '');
                    $poUrl = get_party_page_url((string)($po['slug'] ?? ''), (int)$po['id']);
                ?>
                    <div class="leader-card">
                        <div class="leader-card-cover" style="height:52px;">
                            <div style="position:absolute; top:10px; right:10px;">
                                <span class="cat-pill <?= $poPillClass ?>"><?= e($poType) ?> Party</span>
                            </div>
                        </div>
                        <div class="leader-card-body" style="padding-top:14px;">
                            <div style="display:flex; gap:10px; align-items:flex-start;">
                                <?php if ($poSym && !empty($poSym['url'])): ?>
                                    <img src="<?= e($poSym['url']) ?>" alt="<?= e($poSym['label']) ?>" loading="lazy"
                                         style="width:44px; height:44px; object-fit:contain; border:1px solid var(--border-color); border-radius:8px; background:#FFFFFF; padding:4px; flex-shrink:0;">
                                <?php endif; ?>
                                <div style="flex:1; min-width:0;">
                                    <h3 class="leader-name" style="margin:0; font-size:1rem;">
                                        <a href="<?= e($poUrl) ?>" style="color:var(--navy-main); text-decoration:none;"><?= e($poName) ?></a>
                                    </h3>
                                    <?php if ($poNameHi !== ''): ?>
                                        <div style="font-size:0.84rem; color:var(--green-main); font-weight:700; margin-top:2px; font-family:var(--font-hi);" lang="hi">
                                            <?= e($poNameHi) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:8px; display:flex; gap:10px; flex-wrap:wrap;">
                                <span><i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> <?= strcasecmp($poType, 'National') === 0 ? 'All India HQ' : e($po['state_display']) ?></span>
                                <?php if ($poSym): ?>
                                    <span><i class="fas fa-stamp" style="color:var(--navy-accent);"></i> Symbol: <strong><?= e($poSym['label']) ?></strong></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($poAddr !== ''): ?>
                                <div style="font-size:0.8rem; color:var(--text-secondary); margin-top:8px; line-height:1.4;">
                                    <i class="fas fa-location-dot" style="color:var(--navy-main);"></i> <?= e($poAddr) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($poAddrHi !== ''): ?>
                                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px; line-height:1.4; font-family:var(--font-hi);" lang="hi">
                                    <?= e($poAddrHi) ?>
                                </div>
                            <?php endif; ?>

                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= e($poUrl) ?>" class="btn-profile-view">
                                    Open Party Page <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Section J: Custom Verified Representative Profiles (`leaders` table — shown only when real entries exist) -->
    <?php if (!empty($customLeaders)): ?>
        <section style="margin-bottom:32px;">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-user-tie"></i> Verified Local Profiles</h2>
                <a href="<?= get_site_url('leaders') ?>" class="section-link">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="cards-grid">
                <?php foreach ($customLeaders as $leader): ?>
                    <div class="leader-card">
                        <div class="leader-card-cover">
                            <div style="position:absolute; top:10px; right:10px; z-index:2;">
                                <?= get_category_badge($leader['category'], $pdo) ?>
                            </div>
                        </div>
                        <div class="leader-card-body">
                            <div class="leader-avatar-wrapper">
                                <?php if (!empty($leader['avatar_url'])): ?>
                                    <img src="<?= e($leader['avatar_url']) ?>" alt="<?= e($leader['full_name_en']) ?>">
                                <?php else: ?>
                                    <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main); font-size:1.3rem;">
                                        <i class="fas fa-user-tie"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="leader-header-info">
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:4px;">
                                    <h3 class="leader-name"><?= e($leader['full_name_en']) ?></h3>
                                    <?= get_verification_badge_html($leader['verification_badge'], (bool)$leader['is_verified']) ?>
                                </div>
                                <div class="leader-designation"><?= e($leader['designation_hi']) ?></div>
                            </div>
                            <div class="leader-panchayat">
                                <i class="fas fa-building" style="color:var(--saffron-main);"></i>
                                <?= e($leader['constituency_name_hi'] ?: ($leader['panchayat_name'] ? 'Gram Panchayat ' . $leader['panchayat_name'] : ($leader['district_name'] ?: $viewDistrictName))) ?>
                            </div>
                            <div class="leader-metrics-strip">
                                <div class="metric-item">
                                    <div class="metric-value"><?= (int)$leader['review_count'] > 0 ? e((string)$leader['avg_rating']) . ' <i class="fas fa-star" style="color:#F59E0B; font-size:0.75rem;"></i>' : '—' ?></div>
                                    <div class="metric-label">Public Rating</div>
                                </div>
                                <div class="metric-item">
                                    <div class="metric-value"><?= (int)$leader['works_count'] ?></div>
                                    <div class="metric-label">Works Documented</div>
                                </div>
                                <div class="metric-item">
                                    <div class="metric-value"><?= number_format((int)$leader['views_count']) ?></div>
                                    <div class="metric-label">Citizen Views</div>
                                </div>
                            </div>
                            <div class="leader-card-footer">
                                <a href="<?= get_site_url('profile?id=' . (int)$leader['id']) ?>" class="btn-profile-view">
                                    View Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php elseif (
        !$showMpSection &&
        !$showMlcSection &&
        !$showPartySection &&
        empty($customLeaders)
    ): ?>
        <section style="background:#FFFFFF; padding:36px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color); margin-bottom:30px;">
            <i class="fas <?= e($selectedTypeMeta['icon_class'] ?? 'fa-user-tie') ?>" style="font-size:2.3rem; color:var(--text-muted); margin-bottom:10px;"></i>
            <h3 style="font-size:1.1rem; color:var(--navy-main);">
                <?php if (in_array($selectedCategory, ['mukhiya', 'mla'], true)): ?>
                    <?= e($selectedTypeMeta['name'] ?? strtoupper($selectedCategory)) ?> directory will be updated in the next phase
                <?php else: ?>
                    No verified <?= $selectedTypeMeta ? e($selectedTypeMeta['name']) : 'representative' ?> records published yet for this area
                <?php endif; ?>
            </h3>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">
                Explore Parliament MPs (<?= number_format($globalTotals['mps']) ?>), State Council MLCs (<?= number_format($globalTotals['mlcs']) ?>), and Political Party Offices (<?= number_format($globalTotals['parties']) ?>) across India.
            </p>
            <?php
            $showAllQuery = http_build_query(array_filter([
                'state_code' => $viewStateCode,
                'district_code' => $viewDistrictCode,
                'block_code' => $viewBlockCode,
            ], fn($v) => $v !== ''));
            $showAllUrl = get_site_url($showAllQuery !== '' ? 'index?' . $showAllQuery : 'index');
            ?>
            <a href="<?= $showAllUrl ?>" class="btn-primary-tiranga" style="margin-top:14px; padding:7px 18px; font-size:0.85rem;">
                Show All Local Leaders
            </a>
        </section>
    <?php endif; ?>

    <!-- Section K: Official Election & Civic Resources Banner -->
    <section style="background:linear-gradient(135deg, #0A192F, #1E3A8A); border-radius:var(--radius-lg); padding:20px; color:#FFFFFF; margin-bottom:30px; display:flex; flex-direction:column; gap:12px; border-left:5px solid var(--saffron-main);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
            <div>
                <span class="badge-tag badge-elected" style="background:#046A38; color:#fff; margin-bottom:6px;"><i class="fas fa-check-shield"></i> Official Election Resources</span>
                <h3 style="font-size:1.15rem; font-weight:700;">Political Parties, Election Symbols &amp; Voter Services</h3>
                <p style="font-size:0.85rem; color:#E2E8F0; margin-top:2px;">Explore all <?= number_format($globalTotals['parties']) ?> registered ECI Political Parties with reserved symbols, search <?= number_format($globalTotals['mps']) ?> Lok Sabha &amp; Rajya Sabha MPs and <?= number_format($globalTotals['mlcs']) ?> State MLCs, and access ECI Voter Portals.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <a href="<?= get_site_url('parties') ?>" class="btn-green-tiranga">
                    <i class="fas fa-flag"></i> Political Parties
                </a>
                <a href="<?= get_site_url('elections') ?>" class="btn-primary-tiranga">
                    <i class="fas fa-landmark"></i> MP &amp; Voter Hub
                </a>
            </div>
        </div>
    </section>

</main>

<script>
async function loadHomeDistricts(stateCode) {
    const distEl = document.getElementById('homeFilterDistrict');
    const blkEl = document.getElementById('homeFilterBlock');
    if (!distEl) return;

    distEl.innerHTML = '<option value="">All Districts</option>';
    if (blkEl) blkEl.innerHTML = '<option value="">All Blocks in District</option>';
    if (!stateCode) return;

    distEl.innerHTML = '<option value="">Loading...</option>';
    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=districts&state_code=${encodeURIComponent(stateCode)}`);
    const json = await res.json();
    distEl.innerHTML = '<option value="">All Districts</option>';
    (json.data || []).forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.district_code;
        opt.textContent = d.district;
        distEl.appendChild(opt);
    });
}

async function loadHomeBlocks(districtCode) {
    const blkEl = document.getElementById('homeFilterBlock');
    if (!blkEl) return;

    blkEl.innerHTML = '<option value="">All Blocks in District</option>';
    if (!districtCode) return;

    const res = await fetch(`<?= get_site_url('api/locations') ?>?type=blocks&district_code=${encodeURIComponent(districtCode)}`);
    const json = await res.json();
    (json.data || []).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.block_code;
        opt.textContent = b.block;
        blkEl.appendChild(opt);
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

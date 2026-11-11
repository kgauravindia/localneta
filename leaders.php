<?php
// leaders.php - Know Your Leaders Directory (Powered by op_sdb, English UI with Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/pincode_helper.php';

$pdo = get_db_connection();
$viewer = get_current_user_account($pdo);
$unlockedTodayMap = $viewer ? get_user_revealed_entity_keys_today($pdo, (int)$viewer['id']) : [];

$rawCategory = trim($_GET['cat'] ?? 'all');
$selectedCategory = ($rawCategory === '' || strtolower($rawCategory) === 'all')
    ? 'all'
    : normalize_leader_category_slug($rawCategory);
$selectedState = trim((string)($_GET['state_code'] ?? ''));
$selectedDistrict = trim((string)($_GET['district_code'] ?? ''));
$selectedBlock = trim((string)($_GET['block_code'] ?? ''));
$searchQuery = trim($_GET['q'] ?? '');
$pincodeInput = normalize_pincode_digits($_GET['pincode'] ?? '');

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
    if ($resolvedPinSummary !== null && $selectedState === '' && $selectedDistrict === '' && $selectedBlock === '') {
        $priPin = $resolvedPinSummary['primary'];
        $selectedState = (string)($priPin['state_code'] ?? '');
        $selectedDistrict = (string)($priPin['district_code'] ?? '');
        if (!empty($priPin['lgd_matched'])) {
            $selectedBlock = (string)($priPin['block_code'] ?? '');
        }
    }
}

// Load canonical 25-type leader taxonomy (sorted alphabetically inside Group 1 & Group 2 for discovery)
$leaderTypeGroups = get_leader_types($pdo, true, 'alpha');
$groupDisplayNames = [
    1 => 'Person',
    2 => 'Place',
];
$leaderTypeMap = [];
foreach (get_leader_types($pdo) as $ltItem) {
    $leaderTypeMap[$ltItem['slug']] = $ltItem;
}

if ($selectedCategory !== 'all' && $selectedCategory !== 'mp' && !isset($leaderTypeMap[$selectedCategory])) {
    $selectedCategory = 'all';
}

$selectedTypeMeta = $selectedCategory !== 'all'
    ? get_leader_type_meta($selectedCategory, $pdo)
    : null;

$isMpView = in_array($selectedCategory, ['all', 'mp', 'mp-lok-sabha', 'mp-rajya-sabha'], true);
$isPartyOfficeOnlyView = ($selectedCategory === 'political-party-office');
$isLegislatorView = in_array($selectedCategory, ['all', 'mla'], true);
$isMlcView = in_array($selectedCategory, ['all', 'mlc'], true);
$showLeadersGrid = !$isPartyOfficeOnlyView;

// Fetch States, Districts, Blocks from op_sdb first so real names are resolved cleanly
$states = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();
$stateNameMap = [];
foreach ($states as $stRow) {
    $stateNameMap[(string)$stRow['state_code']] = $stRow['state'];
}
$selectedStateName = $selectedState === '' ? 'All India' : ($stateNameMap[$selectedState] ?? 'All India');

$districts = [];
$districtNameMap = [];
if ($selectedState !== '') {
    $distStmt = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $distStmt->execute([$selectedState]);
    $districts = $distStmt->fetchAll();
    foreach ($districts as $dtRow) {
        $districtNameMap[(string)$dtRow['district_code']] = $dtRow['district'];
    }
}
if ($selectedDistrict !== '' && !isset($districtNameMap[$selectedDistrict])) {
    $selectedDistrict = '';
}
$selectedDistrictName = $districtNameMap[$selectedDistrict] ?? '';

$blocks = [];
$blockNameMap = [];
if ($selectedDistrict !== '') {
    $blkStmt = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $blkStmt->execute([$selectedDistrict]);
    $blocks = $blkStmt->fetchAll();
    foreach ($blocks as $bRow) {
        $blockNameMap[(string)$bRow['block_code']] = $bRow['block'];
    }
}
if ($selectedBlock !== '' && !isset($blockNameMap[$selectedBlock])) {
    $selectedBlock = '';
}
$selectedBlockName = $blockNameMap[$selectedBlock] ?? '';

// Base query on `leaders` table with real aggregated ratings & works counts
$leaders = [];
if ($showLeadersGrid && $selectedCategory !== 'mukhiya' && (int)$pdo->query("SELECT COUNT(*) FROM leaders")->fetchColumn() > 0) {
    $higherSlugsSql = implode(', ', array_map(fn($s) => $pdo->quote($s), get_district_and_higher_leader_slugs()));
    $leaderOrderSql = get_leader_category_order_sql('l.category');

    $sql = "
        SELECT l.*,
               lt.name as leader_type_name,
               p.name_en as panchayat_name,
               sdb.block as block_name,
               COALESCE(sdb.district, dist.district) as district_name,
               COALESCE(sdb.state, dist.state) as state_name,
               COALESCE(rt.review_count, 0) as review_count,
               rt.avg_rating,
               COALESCE(wk.works_count, 0) as works_count
        FROM leaders l
        LEFT JOIN leader_types lt ON l.category = lt.slug
        LEFT JOIN panchayats p ON l.panchayat_id = p.id
        LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code
        LEFT JOIN (SELECT DISTINCT district_code, district, state_code, state FROM op_sdb) dist ON l.district_code = dist.district_code
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
        WHERE l.category <> 'mukhiya'
    ";
    $params = [];

    if ($selectedCategory === 'mp') {
        $sql .= " AND l.category IN ('mp-lok-sabha', 'mp-rajya-sabha', 'mp')";
    } elseif ($selectedCategory !== 'all') {
        $sql .= " AND l.category = ?";
        $params[] = $selectedCategory;
    }

    if (!empty($selectedState)) {
        $sql .= " AND l.state_code = ?";
        $params[] = $selectedState;
    }

    if (!empty($selectedDistrict)) {
        $sql .= " AND l.district_code = ?";
        $params[] = $selectedDistrict;
    }

    if (!empty($selectedBlock)) {
        $sql .= " AND (l.block_code = ? OR l.category IN ({$higherSlugsSql}))";
        $params[] = $selectedBlock;
    }

    if (!empty($searchQuery)) {
        $sql .= " AND (l.full_name_en LIKE ? OR l.designation_hi LIKE ? OR COALESCE(lt.name, '') LIKE ? OR p.name_en LIKE ? OR sdb.block LIKE ? OR dist.district LIKE ?)";
        $searchWild = '%' . $searchQuery . '%';
        array_push($params, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild);
    }

    $sql .= " ORDER BY {$leaderOrderSql}, l.views_count DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leaders = $stmt->fetchAll();
}

// Fetch Registered Verified Politicians (`users` table with account_type = 'politician')
$registeredPoliticians = [];
if ($showLeadersGrid && $pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
    $regWhere = ["u.account_type = 'politician'", "u.status = 'active'", "u.profile_visibility = 'public'", "u.is_mobile_verified = 1"];
    $regParams = [];
    if ($selectedCategory === 'mp') {
        $regWhere[] = "u.leader_category IN ('mp-lok-sabha', 'mp-rajya-sabha', 'mp')";
    } elseif ($selectedCategory !== 'all') {
        $regWhere[] = "u.leader_category = ?";
        $regParams[] = $selectedCategory;
    }
    if ($selectedState !== '') {
        $regWhere[] = "u.state_code = ?";
        $regParams[] = $selectedState;
    }
    if ($selectedDistrict !== '') {
        $regWhere[] = "u.district_code = ?";
        $regParams[] = $selectedDistrict;
    }
    if ($selectedBlock !== '' && $activePincode !== '') {
        $regWhere[] = "(u.block_code = ? OR u.pincode = ?)";
        $regParams[] = $selectedBlock;
        $regParams[] = $activePincode;
    } elseif ($selectedBlock !== '') {
        $regWhere[] = "u.block_code = ?";
        $regParams[] = $selectedBlock;
    } elseif ($activePincode !== '') {
        $regWhere[] = "u.pincode = ?";
        $regParams[] = $activePincode;
    }
    if ($searchQuery !== '') {
        $regWhere[] = "(u.name LIKE ? OR COALESCE(u.full_name, '') LIKE ? OR COALESCE(u.constituency, '') LIKE ? OR COALESCE(u.party_name, '') LIKE ? OR COALESCE(u.designation, '') LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($regParams, $wild, $wild, $wild, $wild, $wild);
    }
    $regOrderPinSql = $activePincode !== '' ? "(u.pincode = ?) DESC, " : '';
    if ($activePincode !== '') {
        $regParams[] = $activePincode;
    }
    $regStmt = $pdo->prepare("
        SELECT u.*, lt.name AS leader_type_name
        FROM users u
        LEFT JOIN leader_types lt ON u.leader_category = lt.slug
        WHERE " . implode(' AND ', $regWhere) . "
        ORDER BY {$regOrderPinSql}u.id DESC
        LIMIT 24
    ");
    $regStmt->execute($regParams);
    $registeredPoliticians = $regStmt->fetchAll();
}

// Fetch Real MLAs (`politician` table — active non-vacant records)
$hasPoliticianTable = (bool)$pdo->query("SHOW TABLES LIKE 'politician'")->fetch();
$legislators = [];
if ($isLegislatorView && $hasPoliticianTable && ($selectedState === '10' || $selectedState === '')) {
    $polWhere = ["LOWER(TRIM(name)) NOT IN ('', 'vacant')", "c_type = 'MLA'"];
    $polParams = [];
    if ($selectedDistrict !== '' && $selectedDistrictName !== '') {
        [$dCondSql, $dCondParams] = get_district_politician_where($pdo, $selectedDistrict, $selectedDistrictName);
        $polWhere[] = $dCondSql;
        $polParams = array_merge($polParams, $dCondParams);
    }
    if ($searchQuery !== '') {
        $polWhere[] = "(name LIKE ? OR c_name LIKE ? OR political_party LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($polParams, $wild, $wild, $wild);
    }
    $polWhereSql = implode(' AND ', $polWhere);
    $polLimit = $selectedCategory === 'mla' ? 100 : 12;
    $polStmt = $pdo->prepare("SELECT * FROM politician WHERE {$polWhereSql} ORDER BY CAST(c_number AS UNSIGNED) ASC, name ASC LIMIT {$polLimit}");
    $polStmt->execute($polParams);
    $legislators = $polStmt->fetchAll();
}

// Fetch Real MLCs (`mlc` table — UP, Bihar & AP Legislative Councils via neva.gov.in)
$hasMlcTable = (bool)$pdo->query("SHOW TABLES LIKE 'mlc'")->fetch();
$councilMlcs = [];
if ($isMlcView && $hasMlcTable) {
    $mlcWhere = ["status = 'ACTIVE'"];
    $mlcParams = [];
    if ($selectedState !== '') {
        $mlcWhere[] = "state_code = ?";
        $mlcParams[] = $selectedState;
    }
    if ($searchQuery !== '') {
        $mlcWhere[] = "(member_name LIKE ? OR constituency LIKE ? OR COALESCE(constituency_area, '') LIKE ? OR constituency_type LIKE ? OR party_name LIKE ? OR COALESCE(role_designation, '') LIKE ? OR state LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($mlcParams, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }
    $mlcWhereSql = implode(' AND ', $mlcWhere);
    $mlcLimit = $selectedCategory === 'mlc' ? 150 : 12;
    $mlcDistOrder = $selectedDistrictName !== '' ? ('%' . $selectedDistrictName . '%') : '__no_match__';
    $mlcStmt = $pdo->prepare("
        SELECT * FROM mlc
        WHERE {$mlcWhereSql}
        ORDER BY CASE WHEN (constituency LIKE ? OR COALESCE(constituency_area, '') LIKE ?) THEN 0 ELSE 1 END ASC,
                 (role_designation IS NOT NULL AND role_designation <> '') DESC,
                 FIELD(state_code, '10', '9', '28'),
                 seat_no IS NULL ASC,
                 seat_no ASC,
                 member_name ASC
        LIMIT {$mlcLimit}
    ");
    $mlcStmt->execute(array_merge($mlcParams, [$mlcDistOrder, $mlcDistOrder]));
    $councilMlcs = $mlcStmt->fetchAll();
}

// Fetch Lok Sabha (LS-18) & Rajya Sabha MPs from `mp` table (source: https://sansad.in/)
$mpHouseFilter = trim($_GET['mp_type'] ?? '');
if ($selectedCategory === 'mp-lok-sabha') {
    $mpHouseFilter = 'ls';
} elseif ($selectedCategory === 'mp-rajya-sabha') {
    $mpHouseFilter = 'rs';
} elseif (!in_array($mpHouseFilter, ['', 'ls', 'rs'], true)) {
    $mpHouseFilter = '';
}

$parliamentMps = [];
if ($isMpView) {
    $mpSql = "SELECT * FROM mp WHERE 1=1";
    $mpParams = [];
    if ($mpHouseFilter !== '') {
        $mpSql .= " AND type = ?";
        $mpParams[] = $mpHouseFilter;
    }
    if (!empty($selectedState)) {
        $mpSql .= " AND state_code = ?";
        $mpParams[] = $selectedState;
    }
    if (!empty($searchQuery)) {
        $mpSql .= " AND (member_name LIKE ? OR constituency LIKE ? OR party_name LIKE ?)";
        $wild = '%' . $searchQuery . '%';
        array_push($mpParams, $wild, $wild, $wild);
    }
    $mpSql .= " ORDER BY type = 'ls' DESC, constituency ASC, member_name ASC LIMIT 150";
    $mpStmt = $pdo->prepare($mpSql);
    $mpStmt->execute($mpParams);
    $parliamentMps = $mpStmt->fetchAll();
}

// Fetch Political Party Offices (`Place` -> `political-party-office`) from `political_party`
$partyOffices = [];
$partyOfficesTotal = 0;
$hasPoliticalPartyTable = (bool)$pdo->query("SHOW TABLES LIKE 'political_party'")->fetch();
if ($hasPoliticalPartyTable && ($selectedCategory === 'political-party-office' || $selectedCategory === 'all')) {
    $poWhere = ["UPPER(TRIM(pp.status)) = 'ACTIVE'"];
    $poParams = [];
    if (!empty($selectedState)) {
        $poWhere[] = "(TRIM(pp.type) = 'National' OR pp.state_code = ?)";
        $poParams[] = $selectedState;
    }
    if (!empty($searchQuery)) {
        $poWhere[] = "(pp.name LIKE ? OR COALESCE(pp.name_hi, '') LIKE ? OR pp.address LIKE ? OR COALESCE(pp.address_hi, '') LIKE ? OR pp.symbol LIKE ?)";
        $poWild = '%' . $searchQuery . '%';
        array_push($poParams, $poWild, $poWild, $poWild, $poWild, $poWild);
    }
    $poWhereSql = implode(' AND ', $poWhere);

    $poCntStmt = $pdo->prepare("SELECT COUNT(*) FROM political_party pp WHERE {$poWhereSql}");
    $poCntStmt->execute($poParams);
    $partyOfficesTotal = (int)$poCntStmt->fetchColumn();

    $poLimit = $selectedCategory === 'political-party-office' ? 60 : 6;
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
    $poStmt->execute(array_merge($poParams, [$selectedState, $selectedState]));
    $partyOffices = $poStmt->fetchAll();
}

// Compute live category badge counts across tables for pills (Mukhiya removed until next phase)
$typeCounts = [];
foreach ($pdo->query("SELECT category, COUNT(*) AS cnt FROM leaders GROUP BY category") as $row) {
    $norm = normalize_leader_category_slug($row['category']);
    if ($norm === 'mukhiya') continue;
    $typeCounts[$norm] = ($typeCounts[$norm] ?? 0) + (int)$row['cnt'];
}
if ($hasPoliticianTable && ($selectedState === '10' || $selectedState === '')) {
    if ($selectedDistrict !== '' && $selectedDistrictName !== '') {
        [$pCondSql, $pCondParams] = get_district_politician_where($pdo, $selectedDistrict, $selectedDistrictName);
        $polCntStmt = $pdo->prepare("SELECT COUNT(*) FROM politician WHERE LOWER(TRIM(name)) NOT IN ('', 'vacant') AND c_type = 'MLA' AND {$pCondSql}");
        $polCntStmt->execute($pCondParams);
    } else {
        $polCntStmt = $pdo->query("SELECT COUNT(*) FROM politician WHERE LOWER(TRIM(name)) NOT IN ('', 'vacant') AND c_type = 'MLA'");
    }
    $typeCounts['mla'] = ($typeCounts['mla'] ?? 0) + (int)$polCntStmt->fetchColumn();
}
if ($hasMlcTable) {
    $mlcCntStmt = $pdo->prepare("SELECT COUNT(*) FROM mlc WHERE status = 'ACTIVE' AND (? = '' OR state_code = ?)");
    $mlcCntStmt->execute([$selectedState, $selectedState]);
    $typeCounts['mlc'] = ($typeCounts['mlc'] ?? 0) + (int)$mlcCntStmt->fetchColumn();
}
$mpCntStmt = $pdo->prepare("SELECT type, COUNT(*) AS cnt FROM mp WHERE (? = '' OR state_code = ?) GROUP BY type");
$mpCntStmt->execute([$selectedState, $selectedState]);
foreach ($mpCntStmt as $row) {
    if ($row['type'] === 'ls') {
        $typeCounts['mp-lok-sabha'] = ($typeCounts['mp-lok-sabha'] ?? 0) + (int)$row['cnt'];
    } elseif ($row['type'] === 'rs') {
        $typeCounts['mp-rajya-sabha'] = ($typeCounts['mp-rajya-sabha'] ?? 0) + (int)$row['cnt'];
    }
}
if ($hasPoliticalPartyTable) {
    $poStatStmt = $pdo->prepare("SELECT COUNT(*) FROM political_party WHERE UPPER(TRIM(status)) = 'ACTIVE' AND (? = '' OR TRIM(type) = 'National' OR state_code = ?)");
    $poStatStmt->execute([$selectedState, $selectedState]);
    $typeCounts['political-party-office'] = ($typeCounts['political-party-office'] ?? 0) + (int)$poStatStmt->fetchColumn();
}

$pageTitle = $selectedTypeMeta ? ($selectedTypeMeta['name'] . " — Leaders Directory") : "Know Your Leaders Directory";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Page Header -->
    <div style="margin-bottom:20px;">
        <h1 style="font-size:1.6rem; font-weight:800; color:var(--navy-main);">
            <i class="fas fa-user-tie" style="color:var(--saffron-main);"></i> Know Your Elected Representatives &amp; Political Offices
        </h1>
        <p style="color:var(--text-secondary); font-size:0.92rem;">
            Verified civic directory covering all <strong>25 Leader &amp; Establishment Types</strong> across Gram Panchayats, Blocks, Urban Wards, Jila Parishads, State Legislatures, Parliament (LS &amp; RS), and ECI Political Party Offices powered by India LGD mapping.
        </p>
    </div>

    <!-- Representative Lookup Tool (State -> District -> Block + Type of Leader) -->
    <div style="background:#FFFFFF; border:2px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:18px; margin-bottom:20px; box-shadow:var(--shadow-sm);">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
            <div style="width:32px; height:32px; border-radius:50%; background:var(--green-light); color:var(--green-main); display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-sitemap"></i>
            </div>
            <div>
                <h3 style="font-size:1.05rem; font-weight:700; color:var(--navy-main);">Representative Hierarchy Lookup</h3>
                <p style="font-size:0.8rem; color:var(--text-muted);">Select Type of Leader, State, District, and Block to discover your local governance hierarchy.</p>
            </div>
        </div>

        <form action="<?= get_site_url('leaders') ?>" method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(165px, 1fr)); gap:10px; align-items:flex-end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Type of Leader</label>
                <select name="cat" class="form-control" style="padding:8px 12px; font-size:0.88rem;">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>All 25 Leader Types</option>
                    <?php foreach ($leaderTypeGroups as $group): ?>
                        <optgroup label="<?= (int)$group['group_no'] ?>. <?= e($groupDisplayNames[(int)$group['group_no']] ?? $group['group_name']) ?>">
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
                <select name="state_code" id="filterState" class="form-control" style="padding:8px 12px; font-size:0.88rem;" onchange="loadFilterDistricts(this.value)">
                    <option value="">All States &amp; UTs</option>
                    <?php foreach ($states as $st): ?>
                        <option value="<?= e($st['state_code']) ?>" <?= $selectedState === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">District</label>
                <select name="district_code" id="filterDistrict" class="form-control" style="padding:8px 12px; font-size:0.88rem;" onchange="loadFilterBlocks(this.value)">
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $dt): ?>
                        <option value="<?= e($dt['district_code']) ?>" <?= $selectedDistrict === (string)$dt['district_code'] ? 'selected' : '' ?>>
                            <?= e($dt['district']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Block (Prakhand)</label>
                <select name="block_code" id="filterBlock" class="form-control" style="padding:8px 12px; font-size:0.88rem;">
                    <option value="">All Blocks</option>
                    <?php foreach ($blocks as $blk): ?>
                        <option value="<?= e($blk['block_code']) ?>" <?= $selectedBlock === (string)$blk['block_code'] ? 'selected' : '' ?>>
                            <?= e($blk['block']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Search by Name / Office</label>
                <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="e.g. Rudy, Gupta, Rekha, BJP..." class="form-control" style="padding:8px 12px; font-size:0.88rem;">
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">PIN Code</label>
                <input type="text" name="pincode" value="<?= e($activePincode ?: $pincodeInput) ?>" maxlength="6" inputmode="numeric" placeholder="e.g. 841301" class="form-control" style="padding:8px 12px; font-size:0.88rem;">
            </div>

            <button type="submit" class="btn-primary-tiranga" style="padding:9px 16px; font-size:0.88rem; width:100%;">
                <i class="fas fa-filter"></i> Apply
            </button>
        </form>
        <?php if ($activePincode !== ''): ?>
            <div style="margin-top:12px; padding:10px 14px; border-radius:var(--radius-md); background:<?= $resolvedPinSummary ? '#ECFDF5' : '#FEF2F2' ?>; border:1px solid <?= $resolvedPinSummary ? '#A7F3D0' : '#FECACA' ?>; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; font-size:0.84rem;">
                <?php if ($resolvedPinSummary):
                    $priPin = $resolvedPinSummary['primary'];
                ?>
                    <div style="color:#065F46;">
                        <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                        <strong>PIN <?= e($activePincode) ?> Resolved:</strong>
                        <?= e($priPin['block_name']) ?> Block • <?= e($priPin['district_name']) ?> • <?= e($priPin['state_name']) ?>
                        (<strong><?= number_format($resolvedPinSummary['village_count']) ?></strong> Villages)
                    </div>
                    <a href="<?= e(get_pincode_page_url($activePincode)) ?>" class="btn-green-tiranga" style="padding:6px 14px; font-size:0.78rem;">
                        <i class="fas fa-house-chimney"></i> Browse <?= number_format($resolvedPinSummary['village_count']) ?> Villages
                    </a>
                <?php else: ?>
                    <div style="color:#991B1B;">
                        <i class="fas fa-circle-exclamation"></i>
                        No villages matched PIN Code <strong><?= e($activePincode) ?></strong>.
                    </div>
                    <a href="<?= get_site_url('pincode') ?>" class="btn-profile-view" style="flex:unset; padding:6px 12px; font-size:0.78rem;">
                        PIN Code Finder
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Type of Leaders Grouped Filter Directory (25 Canonical Types) -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px; margin-bottom:22px; box-shadow:var(--shadow-sm);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
            <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main);">
                <i class="fas fa-layer-group" style="color:var(--saffron-main);"></i> Type of Leaders Taxonomy
            </div>
            <?php
            $allUrlParams = http_build_query(array_filter([
                'cat' => 'all',
                'state_code' => $selectedState,
                'district_code' => $selectedDistrict,
                'block_code' => $selectedBlock,
                'q' => $searchQuery,
            ], fn($v) => $v !== '' && $v !== null));
            ?>
            <a href="<?= get_site_url('leaders?' . $allUrlParams) ?>"
               style="padding:5px 14px; border-radius:var(--radius-full); font-size:0.82rem; font-weight:700; text-decoration:none; border:1px solid <?= $selectedCategory === 'all' ? 'var(--saffron-main)' : 'var(--border-color)' ?>; background:<?= $selectedCategory === 'all' ? 'var(--saffron-main)' : '#FFFFFF' ?>; color:<?= $selectedCategory === 'all' ? '#FFFFFF' : 'var(--navy-main)' ?>;">
                <i class="fas fa-border-all"></i> All Categories (25)
            </a>
        </div>

        <?php foreach ($leaderTypeGroups as $group): ?>
            <div style="<?= (int)$group['group_no'] > 1 ? 'margin-top:12px; padding-top:12px; border-top:1px dashed var(--border-color);' : '' ?>">
                <div style="font-size:0.78rem; font-weight:800; color:<?= (int)$group['group_no'] === 2 ? 'var(--green-main)' : 'var(--text-secondary)' ?>; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:8px;">
                    <?= (int)$group['group_no'] ?>. <?= e($groupDisplayNames[(int)$group['group_no']] ?? $group['group_name']) ?>
                </div>
                <div style="display:flex; gap:7px; flex-wrap:wrap;">
                    <?php foreach ($group['items'] as $lt):
                        $slug = $lt['slug'];
                        $isActive = ($selectedCategory === $slug) || ($selectedCategory === 'mp' && in_array($slug, ['mp-lok-sabha', 'mp-rajya-sabha'], true));
                        $cnt = $typeCounts[$slug] ?? 0;
                        $pillParams = http_build_query(array_filter([
                            'cat' => $slug,
                            'state_code' => $selectedState,
                            'district_code' => $selectedDistrict,
                            'block_code' => $selectedBlock,
                            'q' => $searchQuery,
                        ], fn($v) => $v !== '' && $v !== null));
                    ?>
                        <a href="<?= get_site_url('leaders?' . $pillParams) ?>"
                           style="display:inline-flex; align-items:center; gap:5px; padding:5px 11px; border-radius:var(--radius-full); font-size:0.8rem; font-weight:700; text-decoration:none; border:1px solid <?= $isActive ? 'var(--saffron-main)' : 'var(--border-color)' ?>; background:<?= $isActive ? 'var(--saffron-main)' : 'var(--white-surface)' ?>; color:<?= $isActive ? '#FFFFFF' : 'var(--text-secondary)' ?>; transition:all 0.15s;">
                            <i class="fas <?= e($lt['icon_class']) ?>" style="font-size:0.74rem; opacity:0.88;"></i>
                            <span><?= e($lt['name']) ?></span>
                            <?php if ($cnt > 0): ?>
                                <span style="padding:1px 6px; border-radius:var(--radius-full); font-size:0.7rem; background:<?= $isActive ? 'rgba(255,255,255,0.25)' : '#E2E8F0' ?>; color:<?= $isActive ? '#FFFFFF' : 'var(--navy-main)' ?>;">
                                    <?= number_format($cnt) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    $hasAnyOtherRealSection = !empty($legislators) || !empty($councilMlcs) || !empty($parliamentMps) || !empty($partyOffices) || !empty($registeredPoliticians);
    if ($showLeadersGrid && (!empty($leaders) || (!$isMpView && !$isLegislatorView && !$isMlcView && !$isPartyOfficeOnlyView && !$hasAnyOtherRealSection))): ?>
    <!-- Custom Registered Profiles (`leaders` table) -->
    <div class="section-header" style="flex-wrap:wrap; gap:8px;">
        <h2 class="section-title">
            <i class="fas <?= e($selectedTypeMeta['icon_class'] ?? 'fa-users') ?>"></i>
            <?= $selectedTypeMeta ? e($selectedTypeMeta['name']) . ' Directory' : 'Verified Custom Representative Profiles' ?>
        </h2>
        <span class="badge-tag badge-verified"><?= count($leaders) ?> Profile<?= count($leaders) === 1 ? '' : 's' ?></span>
    </div>

    <div class="cards-grid">
        <?php if (empty($leaders)): ?>
            <div style="grid-column: 1 / -1; background:#FFFFFF; padding:40px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                <i class="fas fa-user-slash" style="font-size:2.5rem; color:var(--text-muted); margin-bottom:12px;"></i>
                <h3 style="font-size:1.1rem; color:var(--navy-main);">
                    <?php if ($selectedCategory === 'mukhiya'): ?>
                        Mukhiya directory will be updated in the next phase
                    <?php else: ?>
                        No <?= $selectedTypeMeta ? e($selectedTypeMeta['name']) : 'representative' ?> profiles found for this filter yet
                    <?php endif; ?>
                </h3>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">
                    <?= $selectedCategory === 'mukhiya' ? 'Pan-India Mukhiya records are scheduled for the next update. Meanwhile explore MLAs, MPs, and Political Parties.' : 'Try switching State/District/Block filters or browsing all 25 representative categories.' ?>
                </p>
                <div style="display:flex; justify-content:center; gap:10px; flex-wrap:wrap; margin-top:14px;">
                    <a href="<?= get_site_url('leaders') ?>" class="btn-primary-tiranga" style="padding:6px 16px; font-size:0.85rem;">
                        Show All Categories
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($leaders as $leader): ?>
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
                            <?= e($leader['constituency_name_hi'] ?: ($leader['panchayat_name'] ? 'Gram Panchayat ' . $leader['panchayat_name'] : ($leader['district_name'] . ' District'))) ?>
                        </div>

                        <div style="font-size:0.82rem; color:var(--text-muted); margin-top:4px;">
                            <i class="fas fa-map-pin" style="color:var(--navy-accent);"></i>
                            <?= e(($leader['block_name'] ? $leader['block_name'] . ', ' : '') . ($leader['district_name'] ?? '') . ', ' . ($leader['state_name'] ?? '')) ?>
                        </div>

                        <div style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;">
                            <i class="fas fa-flag" style="color:var(--green-main);"></i> Party: <?= e($leader['party_name_hi']) ?>
                        </div>

                        <!-- Real Metrics Strip -->
                        <div class="leader-metrics-strip">
                            <div class="metric-item">
                                <div class="metric-value"><?= (int)$leader['review_count'] > 0 ? e((string)$leader['avg_rating']) . ' <i class="fas fa-star" style="color:#F59E0B; font-size:0.75rem;"></i>' : '—' ?></div>
                                <div class="metric-label">Public Rating</div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-value"><?= (int)$leader['works_count'] ?></div>
                                <div class="metric-label">Documented Works</div>
                            </div>
                            <div class="metric-item">
                                <div class="metric-value"><?= number_format((int)$leader['views_count']) ?></div>
                                <div class="metric-label">Views</div>
                            </div>
                        </div>

                        <div class="leader-card-footer">
                            <a href="<?= get_site_url('profile?id=' . (int)$leader['id']) ?>" class="btn-profile-view">
                                Full Profile &amp; Works <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                            </a>
                            <?php
                            $ldrId = (int)$leader['id'];
                            $ldrMaskedWa = mask_phone_number((string)($leader['whatsapp'] ?? $leader['phone'] ?? ''));
                            if ($ldrMaskedWa !== ''):
                                echo render_contact_reveal_trigger('leader', $ldrId, 'mobile', $ldrMaskedWa, (string)$leader['full_name_en'], [
                                    'variant' => 'pill',
                                    'icon' => 'fab fa-whatsapp',
                                    'unlocked_today' => !empty($unlockedTodayMap['leader:' . $ldrId]),
                                ]);
                            endif;
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isLegislatorView && (!empty($legislators) || $selectedCategory === 'mla')): ?>
        <section style="margin-bottom:34px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-building-columns"></i>
                        MLA Directory<?= $selectedDistrictName !== '' && $selectedState === '10' ? ' — ' . e($selectedDistrictName) : '' ?>
                    </h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        Showing <strong><?= count($legislators) ?></strong> state assembly representatives from <code>politician</code>
                    </p>
                </div>
                <a href="<?= get_site_url('source#source-neva') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                    <i class="fas fa-database"></i> Source: neva.gov.in
                </a>
            </div>
            <?php if (empty($legislators)): ?>
                <div style="background:#FFFFFF; padding:30px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                    <p style="color:var(--text-muted); font-size:0.9rem;">No MLAs matched your filter in <?= e($selectedStateName) ?>.</p>
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($legislators as $pol):
                        $polType = strtoupper(trim((string)$pol['c_type']));
                        $polName = format_civic_title($pol['name']);
                        $polSeat = format_civic_title($pol['c_name']);
                        $polNum = trim((string)($pol['c_number'] ?? ''));
                        $polPartyMeta = resolve_party_card_meta($pdo, $pol['political_party'] ?? '');
                        $polMaskedPhone = mask_phone_number((string)($pol['mobile'] ?? $pol['tel'] ?? ''));
                    ?>
                        <div class="leader-card">
                            <div class="leader-card-cover" style="height:56px;">
                                <div style="position:absolute; top:10px; right:10px;">
                                    <span class="cat-pill bg-indigo"><?= e($polType) ?><?= $polNum !== '' ? ' #' . e($polNum) : '' ?></span>
                                </div>
                            </div>
                            <div class="leader-card-body">
                                <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-accent); color:#FFFFFF; font-size:1.2rem;">
                                    <i class="fas fa-building-columns"></i>
                                </div>
                                <div class="leader-header-info">
                                    <h3 class="leader-name" style="margin:0;"><?= e($polName) ?></h3>
                                    <div class="leader-designation">Member of Legislative Assembly</div>
                                </div>
                                <div class="leader-panchayat">
                                    <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                    <strong><?= $polNum !== '' ? e($polNum) . ' — ' : '' ?><?= e($polSeat) ?></strong>, <?= e($selectedStateName) ?>
                                </div>
                                <?php if ($polPartyMeta): ?>
                                    <div style="margin-top:8px;"><?= render_party_chip_html($polPartyMeta) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($pol['tenure'])): ?>
                                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:6px;">Tenure: <strong><?= e($pol['tenure']) ?></strong></div>
                                <?php endif; ?>
                                <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                    <?php if ($polMaskedPhone !== ''):
                                        $polRowId = (int)$pol['id'];
                                        echo render_contact_reveal_trigger('politician', $polRowId, 'mobile', $polMaskedPhone, $polName, [
                                            'variant' => 'pill',
                                            'icon' => 'fas fa-shield-halved',
                                            'unlocked_today' => !empty($unlockedTodayMap['politician:' . $polRowId]),
                                        ]);
                                    endif; ?>
                                    <a href="<?= get_site_url('studio?politician_id=' . (int)$pol['id']) ?>" class="btn-profile-view" style="flex:unset; padding:8px 12px;"><i class="fas fa-paint-brush" style="color:var(--saffron-main);"></i> Poster</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($isMlcView && (!empty($councilMlcs) || $selectedCategory === 'mlc')): ?>
        <section style="margin-bottom:34px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-scroll"></i> MLC Directory — Vidhan Parishad (UP, Bihar &amp; AP)
                    </h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        Showing <strong><?= count($councilMlcs) ?></strong> verified Members of Legislative Council for <?= e($selectedStateName) ?>
                    </p>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <a href="<?= get_site_url('source#source-neva') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                        <i class="fas fa-database"></i> Source: neva.gov.in
                    </a>
                    <a href="<?= get_site_url('elections#mlc-directory') ?>" class="section-link">
                        Full 231 MLC Directory <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
            <?php if (empty($councilMlcs)): ?>
                <div style="background:#FFFFFF; padding:30px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:12px;">No Legislative Council (MLC) records indexed for <?= e($selectedStateName) ?> yet. Currently indexed: Uttar Pradesh (99), Bihar (75), Andhra Pradesh (57).</p>
                    <a href="<?= get_site_url('elections#mlc-directory') ?>" class="btn-primary-tiranga" style="padding:7px 16px; font-size:0.84rem;">Browse All 231 MLCs</a>
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($councilMlcs as $mlcItem):
                        $mlcUrl = get_mlc_page_url((string)($mlcItem['slug'] ?? ''), (int)$mlcItem['id']);
                        $mlcPartyMeta = resolve_party_card_meta($pdo, $mlcItem['party_name'] ?? '');
                        $mlcBadge = strtoupper(get_mlc_state_slug_prefix((string)$mlcItem['state_code'], (string)$mlcItem['state'])) . ' MLC';
                    ?>
                        <div class="leader-card">
                            <div class="leader-card-cover" style="height:56px; background:linear-gradient(135deg, #5B21B6, #0A192F);">
                                <div style="position:absolute; top:10px; right:10px;">
                                    <span class="cat-pill bg-purple"><?= e($mlcBadge) ?></span>
                                </div>
                            </div>
                            <div class="leader-card-body">
                                <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main);">
                                    <?php if (!empty($mlcItem['photo_url'])): ?>
                                        <img src="<?= e($mlcItem['photo_url']) ?>" alt="<?= e($mlcItem['member_name']) ?>" loading="lazy"
                                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.2rem; color:var(--navy-main);">
                                            <i class="fas fa-scroll"></i>
                                        </span>
                                    <?php else: ?>
                                        <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.2rem; color:var(--navy-main);">
                                            <i class="fas fa-scroll"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="leader-header-info">
                                    <h3 class="leader-name" style="margin:0;">
                                        <a href="<?= e($mlcUrl) ?>" style="color:inherit; text-decoration:none;"><?= e($mlcItem['member_name']) ?></a>
                                    </h3>
                                    <div class="leader-designation"><?= e($mlcItem['role_designation'] ?: 'Member of Legislative Council') ?></div>
                                </div>
                                <div class="leader-panchayat">
                                    <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                    <strong><?= e($mlcItem['constituency']) ?></strong>, <?= e($mlcItem['state']) ?>
                                </div>
                                <?php if ($mlcPartyMeta): ?>
                                    <div style="margin-top:8px;"><?= render_party_chip_html($mlcPartyMeta) ?></div>
                                <?php endif; ?>
                                <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                    <a href="<?= e($mlcUrl) ?>" class="btn-profile-view">
                                        View MLC Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                    </a>
                                    <a href="<?= get_site_url('studio?mlc_id=' . (int)$mlcItem['id']) ?>" class="btn-profile-view" style="flex:unset; padding:8px 12px;"><i class="fas fa-paint-brush" style="color:var(--saffron-main);"></i> Poster</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($isMpView && (!empty($parliamentMps) || in_array($selectedCategory, ['mp', 'mp-lok-sabha', 'mp-rajya-sabha'], true))): ?>
        <section style="margin-top:<?= empty($leaders) ? '0' : '34px' ?>;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-landmark"></i>
                        <?php if ($selectedCategory === 'mp-lok-sabha'): ?>
                            MP (Lok Sabha) — 18th Lok Sabha
                        <?php elseif ($selectedCategory === 'mp-rajya-sabha'): ?>
                            MP (Rajya Sabha) — Upper House
                        <?php else: ?>
                            Members of Parliament (Lok Sabha &amp; Rajya Sabha)
                        <?php endif; ?>
                    </h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        Parliamentary Representatives (<code>LS-18</code> &amp; Rajya Sabha) for <?= e($selectedStateName) ?> • Showing <strong><?= count($parliamentMps) ?></strong> MPs
                    </p>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <a href="https://sansad.in/" target="_blank" rel="noopener" class="badge-tag badge-verified" style="text-decoration:none;">
                        <i class="fas fa-link"></i> sansad.in
                    </a>
                    <a href="<?= get_site_url('source#source-sansad') ?>" class="badge-tag badge-unverified" style="text-decoration:none;">
                        <i class="fas fa-database"></i> View All Sources
                    </a>
                    <a href="<?= get_site_url('elections?' . http_build_query(array_filter(['mp_type' => $mpHouseFilter, 'mp_state' => $selectedState, 'mp_q' => $searchQuery], fn($v) => $v !== '')) . '#mp-directory') ?>" class="section-link">
                        Full 789 MP Directory <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <!-- House Toggle Pills -->
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
                <?php
                $houseTabs = [
                    ['cat' => 'mp', 'mp_type' => '', 'label' => 'All Houses (LS + RS)'],
                    ['cat' => 'mp-lok-sabha', 'mp_type' => 'ls', 'label' => 'MP (Lok Sabha)'],
                    ['cat' => 'mp-rajya-sabha', 'mp_type' => 'rs', 'label' => 'MP (Rajya Sabha)'],
                ];
                foreach ($houseTabs as $hTab):
                    $hActive = ($hTab['mp_type'] === '' && $mpHouseFilter === '') || ($hTab['mp_type'] !== '' && $mpHouseFilter === $hTab['mp_type']);
                    $hCatTarget = ($selectedCategory === 'all') ? 'all' : $hTab['cat'];
                    $hParams = http_build_query(array_filter([
                        'cat' => $hCatTarget,
                        'mp_type' => $hTab['mp_type'],
                        'state_code' => $selectedState,
                        'district_code' => $selectedDistrict,
                        'block_code' => $selectedBlock,
                        'q' => $searchQuery
                    ], fn($v) => $v !== '' && $v !== null));
                ?>
                    <a href="<?= get_site_url('leaders?' . $hParams) ?>"
                       style="padding:5px 12px; border-radius:var(--radius-full); font-size:0.8rem; font-weight:700; border:1px solid <?= $hActive ? 'var(--green-main)' : 'var(--border-color)' ?>; background:<?= $hActive ? 'var(--green-main)' : '#FFFFFF' ?>; color:<?= $hActive ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                        <?= e($hTab['label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($parliamentMps)): ?>
                <div style="background:#FFFFFF; padding:32px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                    <i class="fas fa-landmark" style="font-size:2.2rem; color:var(--text-muted); margin-bottom:10px;"></i>
                    <h3 style="font-size:1.05rem; color:var(--navy-main);">No MPs matched for the selected State / House filter</h3>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:4px;">Some Union Territories do not have Rajya Sabha seats, and Nominated RS members sit nationally.</p>
                    <a href="<?= get_site_url('elections#mp-directory') ?>" class="btn-primary-tiranga" style="margin-top:12px; padding:7px 16px; font-size:0.84rem;">
                        Browse All 789 MPs (Including Nominated)
                    </a>
                </div>
            <?php else: ?>
                <div class="cards-grid">
                    <?php foreach ($parliamentMps as $mp):
                        $isRs = $mp['type'] === 'rs';
                        $isNominated = $isRs && empty($mp['election']);
                        $houseBadge = !$isRs
                            ? strtoupper($mp['election'] ?: 'LS-18')
                            : ($mp['election'] ?: 'RS Nominated');
                        $mpUrl = get_mp_page_url((string)($mp['slug'] ?? ''), (int)$mp['id']);
                    ?>
                        <div class="leader-card">
                            <div class="leader-card-cover" style="height:64px; background:<?= $isRs ? 'linear-gradient(135deg, #046A38, #0A192F)' : 'var(--navy-gradient)' ?>;"></div>
                            <div class="leader-card-body">
                                <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main);">
                                    <?php if (!empty($mp['photo_url'])): ?>
                                        <img src="<?= e($mp['photo_url']) ?>" alt="<?= e($mp['member_name']) ?>" loading="lazy"
                                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.3rem; color:var(--navy-main);">
                                            <i class="fas fa-landmark"></i>
                                        </span>
                                    <?php else: ?>
                                        <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.3rem; color:var(--navy-main);">
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
                                    <div class="leader-designation" style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-top:3px;">
                                        <span class="cat-pill <?= $isRs ? ($isNominated ? 'bg-purple' : 'bg-emerald') : 'bg-rose' ?>"><?= e($houseBadge) ?></span>
                                        <span><?= $isRs ? 'MP (Rajya Sabha)' : 'MP (Lok Sabha)' ?></span>
                                    </div>
                                </div>

                                <div class="leader-panchayat">
                                    <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                    <?= e($mp['constituency']) ?><?= !$isRs ? ', ' . e($mp['state']) : '' ?>
                                </div>
                                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:4px;">
                                    <i class="fas fa-flag" style="color:var(--green-main);"></i> <?= e($mp['party_name']) ?>
                                </div>
                                <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px; display:flex; gap:10px; flex-wrap:wrap;">
                                    <?php if ($isRs): ?>
                                        <span><i class="fas fa-calendar-alt" style="color:var(--navy-accent);"></i> Term: <strong><?= e($mp['term_period'] ?: 'Rajya Sabha') ?></strong></span>
                                        <?php if ($mp['total_terms'] !== null): ?>
                                            <span>• Terms: <strong><?= (int)$mp['total_terms'] ?></strong></span>
                                        <?php endif; ?>
                                        <?php if ($mp['age'] !== null): ?>
                                            <span>• Age: <strong><?= (int)$mp['age'] ?></strong></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span><i class="fas fa-history" style="color:var(--navy-accent);"></i> Lok Sabha Terms: <strong><?= e($mp['lok_sabha_terms']) ?></strong></span>
                                    <?php endif; ?>
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

    <?php if (!empty($registeredPoliticians)): ?>
        <!-- Registered Politicians & Public Community Leaders (`users` table) -->
        <section style="margin-bottom:34px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-user-check"></i> Registered Politicians &amp; Public Representatives
                    </h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        OTP-verified member cards with privacy-masked mobile numbers and email IDs
                    </p>
                </div>
                <a href="<?= get_site_url('register?type=politician') ?>" class="btn-green-tiranga" style="padding:7px 14px; font-size:0.8rem;">
                    <i class="fas fa-user-plus"></i> Register as Politician
                </a>
            </div>
            <div class="cards-grid">
                <?php foreach ($registeredPoliticians as $rp):
                    $rpId = (int)$rp['id'];
                    $rpName = (string)($rp['full_name'] ?: $rp['name']);
                    $rpUrl = get_user_profile_url((string)($rp['username_handle'] ?? ''), $rpId);
                    $rpLoc = implode(', ', array_filter([trim((string)($rp['block_name'] ?? '')), trim((string)($rp['district'] ?? '')), trim((string)($rp['state_name'] ?? ''))]));
                    $rpMaskedMob = (($rp['mobile_visibility'] ?? 'masked') !== 'private') ? mask_phone_number((string)($rp['mobile'] ?? '')) : '';
                    $rpMaskedEmail = (($rp['email_visibility'] ?? 'masked') !== 'private' && !empty($rp['email']) && filter_var($rp['email'], FILTER_VALIDATE_EMAIL)) ? mask_email_address((string)$rp['email']) : '';
                    $rpUnlocked = !empty($unlockedTodayMap['user:' . $rpId]);
                ?>
                    <div class="leader-card">
                        <div class="leader-card-cover" style="height:56px;">
                            <div style="position:absolute; top:10px; right:10px;">
                                <span class="cat-pill bg-amber"><?= e($rp['leader_type_name'] ?: 'Politician') ?></span>
                            </div>
                        </div>
                        <div class="leader-card-body">
                            <div class="leader-avatar-wrapper">
                                <img src="<?= e(get_user_avatar_url($rp['profile_photo'] ?? null)) ?>" alt="<?= e($rpName) ?>" loading="lazy">
                            </div>
                            <div class="leader-header-info">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:6px;">
                                    <h3 class="leader-name" style="margin:0;">
                                        <a href="<?= e($rpUrl) ?>" style="color:inherit; text-decoration:none;"><?= e($rpName) ?></a>
                                    </h3>
                                    <span class="badge-tag badge-elected"><i class="fas fa-check-circle"></i> Verified</span>
                                </div>
                                <div class="leader-designation"><?= e($rp['designation'] ?: ($rp['leader_type_name'] ?: 'Public Representative')) ?></div>
                            </div>
                            <?php if ($rpLoc !== '' || !empty($rp['constituency'])): ?>
                                <div class="leader-panchayat">
                                    <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                    <span><strong><?= e($rp['constituency'] ?: $rpLoc) ?></strong><?= !empty($rp['constituency']) && $rpLoc !== '' ? ' • ' . e($rpLoc) : '' ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($rp['party_name'])): ?>
                                <div style="font-size:0.8rem; color:var(--text-muted); margin-top:4px;">
                                    <i class="fas fa-flag" style="color:var(--green-main);"></i> <?= e($rp['party_name']) ?>
                                </div>
                            <?php endif; ?>
                            <div style="display:flex; flex-direction:column; gap:6px; margin-top:10px;">
                                <?php if ($rpMaskedMob !== ''):
                                    echo render_contact_reveal_trigger('user', $rpId, 'mobile', $rpMaskedMob, $rpName, [
                                        'variant' => 'pill',
                                        'icon' => 'fas fa-mobile-screen-button',
                                        'unlocked_today' => $rpUnlocked,
                                    ]);
                                endif; ?>
                                <?php if ($rpMaskedEmail !== ''):
                                    echo render_contact_reveal_trigger('user', $rpId, 'email', $rpMaskedEmail, $rpName, [
                                        'variant' => 'pill',
                                        'icon' => 'fas fa-envelope',
                                        'unlocked_today' => $rpUnlocked,
                                    ]);
                                endif; ?>
                            </div>
                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= e($rpUrl) ?>" class="btn-profile-view">
                                    View Profile Card <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($selectedCategory === 'political-party-office' || ($selectedCategory === 'all' && !empty($partyOffices))): ?>
        <!-- Group 2: Place -> Political Party Offices (`political_party`) -->
        <section style="margin-top:<?= ($isPartyOfficeOnlyView ? '0' : '34px') ?>;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-flag"></i> Political Party Offices (Place)
                    </h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        Verified active ECI National, State &amp; Registered Party HQ Offices for All India &amp; <?= e($selectedStateName) ?> • Showing <strong><?= count($partyOffices) ?></strong> of <strong><?= number_format($partyOfficesTotal) ?></strong> active offices
                    </p>
                </div>
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <span class="cat-pill bg-amber">Place</span>
                    <a href="<?= get_site_url('source#source-eci') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                        <i class="fas fa-database"></i> Source: eci.gov.in
                    </a>
                    <a href="<?= get_site_url('parties?' . http_build_query(array_filter(['state_code' => $selectedState, 'q' => $searchQuery], fn($v) => $v !== ''))) ?>" class="section-link">
                        Full 3,445 ECI Party Directory <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>

            <?php if (empty($partyOffices)): ?>
                <div style="background:#FFFFFF; padding:32px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                    <i class="fas fa-flag" style="font-size:2.2rem; color:var(--text-muted); margin-bottom:10px;"></i>
                    <h3 style="font-size:1.05rem; color:var(--navy-main);">No active Political Party Offices matched your search</h3>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:4px;">Browse Inactive or Delisted ECI RUPP parties or clear your keyword search in the Parties Directory.</p>
                    <a href="<?= get_site_url('parties') ?>" class="btn-primary-tiranga" style="margin-top:12px; padding:7px 16px; font-size:0.84rem;">
                        Open Full Parties Directory
                    </a>
                </div>
            <?php else: ?>
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
                        $poPartyUrl = get_party_page_url((string)($po['slug'] ?? ''), (int)$po['id']);
                    ?>
                        <div class="leader-card">
                            <div class="leader-card-cover" style="height:56px;">
                                <div style="position:absolute; top:10px; right:10px; display:flex; gap:6px;">
                                    <span class="cat-pill <?= $poPillClass ?>"><?= e($poType) ?> Party</span>
                                </div>
                            </div>
                            <div class="leader-card-body" style="padding-top:14px;">
                                <div style="display:flex; gap:10px; align-items:flex-start;">
                                    <?php if ($poSym && !empty($poSym['url'])): ?>
                                        <img src="<?= e($poSym['url']) ?>" alt="<?= e($poSym['label']) ?>" loading="lazy"
                                             style="width:42px; height:42px; object-fit:contain; border:1px solid var(--border-color); border-radius:8px; background:#FFFFFF; padding:4px; flex-shrink:0;">
                                    <?php endif; ?>
                                    <div style="flex:1; min-width:0;">
                                        <h3 class="leader-name" style="margin:0; font-size:1rem;">
                                            <a href="<?= e($poPartyUrl) ?>" style="color:var(--navy-main); text-decoration:none;"><?= e($poName) ?></a>
                                        </h3>
                                        <?php if ($poNameHi !== ''): ?>
                                            <div style="font-size:0.84rem; color:var(--green-main); font-weight:700; margin-top:2px; font-family:'Hind', sans-serif;">
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
                                    <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px; line-height:1.4; font-family:'Hind', sans-serif;">
                                        <?= e($poAddrHi) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                    <a href="<?= e($poPartyUrl) ?>" class="btn-profile-view">
                                        Open Party Page <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

</main>

<script>
async function loadFilterDistricts(stateCode) {
    const distEl = document.getElementById('filterDistrict');
    const blkEl = document.getElementById('filterBlock');
    if (!distEl) return;

    distEl.innerHTML = '<option value="">All Districts</option>';
    if (blkEl) blkEl.innerHTML = '<option value="">All Blocks</option>';
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

async function loadFilterBlocks(districtCode) {
    const blkEl = document.getElementById('filterBlock');
    if (!blkEl) return;

    blkEl.innerHTML = '<option value="">All Blocks</option>';
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

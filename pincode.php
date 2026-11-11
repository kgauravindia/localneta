<?php
// pincode.php - Pan-India PIN Code & Village Directory (Powered by `village` + `op_sdb`)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';
require_once __DIR__ . '/includes/pincode_helper.php';

$pdo = get_db_connection();
$viewer = get_current_user_account($pdo);
$unlockedTodayMap = $viewer ? get_user_revealed_entity_keys_today($pdo, (int)$viewer['id']) : [];

$rawPinParam = trim((string)($_GET['pincode'] ?? ''));
$rawQuery = trim((string)($_GET['q'] ?? ''));
$stateCode = trim((string)($_GET['state_code'] ?? ''));
$districtCode = trim((string)($_GET['district_code'] ?? ''));
$blockCode = trim((string)($_GET['block_code'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Normalize incoming PIN (handles /pincode/841301 or ?q=841301)
$activePincode = '';
$pinValidationError = '';

if ($rawPinParam !== '') {
    $digits = normalize_pincode_digits($rawPinParam);
    if (is_valid_indian_pincode($digits)) {
        $activePincode = $digits;
    } else {
        $pinValidationError = 'Please enter a valid 6-digit Indian PIN code (for example: 841301, 226002, or 110010).';
    }
} elseif ($rawQuery !== '' && is_valid_indian_pincode($rawQuery)) {
    $activePincode = $rawQuery;
}

if ($rawQuery === '' && $activePincode !== '') {
    $rawQuery = $activePincode;
}

// Resolve `op_sdb` cascade options
$states = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();
$stateNameMap = [];
foreach ($states as $stRow) {
    $stateNameMap[(string)$stRow['state_code']] = (string)$stRow['state'];
}
if ($stateCode !== '' && !isset($stateNameMap[$stateCode])) {
    $stateCode = '';
}
$selectedStateName = $stateCode !== '' ? ($stateNameMap[$stateCode] ?? '') : '';

$districts = [];
$districtNameMap = [];
if ($stateCode !== '') {
    $ds = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $ds->execute([$stateCode]);
    $districts = $ds->fetchAll();
    foreach ($districts as $dtRow) {
        $districtNameMap[(string)$dtRow['district_code']] = (string)$dtRow['district'];
    }
}
if ($districtCode !== '' && !isset($districtNameMap[$districtCode])) {
    $districtCode = '';
}
$selectedDistrictName = $districtCode !== '' ? ($districtNameMap[$districtCode] ?? '') : '';

$blocks = [];
$blockNameMap = [];
if ($districtCode !== '') {
    $bs = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $bs->execute([$districtCode]);
    $blocks = $bs->fetchAll();
    foreach ($blocks as $bRow) {
        $blockNameMap[(string)$bRow['block_code']] = (string)$bRow['block'];
    }
}

$datasetStats = get_pincode_dataset_stats($pdo);
$hasVillageTable = has_village_pincode_table($pdo);

$pinSummary = null;
$villagesResult = ['total' => 0, 'rows' => []];
$pincodeGroups = [];
$hasSearchAttempt = ($activePincode !== '' || $rawQuery !== '' || $stateCode !== '' || $districtCode !== '' || $blockCode !== '');

// Matched civic entities for exact 6-digit PIN mode
$pinMps = [];
$pinMlcs = [];
$pinRegisteredPols = [];
$pinParties = [];

if ($hasVillageTable && $activePincode !== '') {
    $pinSummary = get_pincode_area_summary($pdo, $activePincode);
    if ($pinSummary !== null) {
        $villagesResult = search_pincode_villages($pdo, [
            'pincode' => $activePincode,
            'block_code' => $blockCode,
        ], $perPage, $offset);

        $pri = $pinSummary['primary'];
        $priStateCode = (string)$pri['state_code'];
        $priDistName = (string)$pri['district_name'];
        $priBlockCode = (string)$pri['block_code'];
        $priDistCode = (string)$pri['district_code'];
        $distLike = $priDistName !== '' ? ('%' . $priDistName . '%') : '__no_dist__';
        $pinLike = '%' . $activePincode . '%';

        // Local MPs matching State + District keyword prioritization
        $mpQ = $pdo->prepare("
            SELECT id, slug, member_name, party_name, constituency, state, state_code, membership_status, type, election, photo_url
            FROM mp
            WHERE state_code = ?
            ORDER BY CASE WHEN constituency LIKE ? THEN 0 ELSE 1 END ASC,
                     type = 'ls' DESC,
                     membership_status = 'Sitting' DESC,
                     member_name ASC
            LIMIT 6
        ");
        $mpQ->execute([$priStateCode, $distLike]);
        $pinMps = $mpQ->fetchAll();

        // State Council MLCs matching State + District or Address PIN
        if ($pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()) {
            $mlcQ = $pdo->prepare("
                SELECT id, slug, member_name, party_name, role_designation, constituency, constituency_area, constituency_type, state, state_code, photo_url, mobile, alternate_mobile, office_tel, email
                FROM mlc
                WHERE status = 'ACTIVE'
                  AND state_code = ?
                ORDER BY CASE WHEN (COALESCE(permanent_address, '') LIKE ? OR COALESCE(local_address, '') LIKE ?) THEN 0 ELSE 1 END ASC,
                         CASE WHEN (constituency LIKE ? OR COALESCE(constituency_area, '') LIKE ? OR COALESCE(permanent_address, '') LIKE ?) THEN 0 ELSE 1 END ASC,
                         (role_designation IS NOT NULL AND role_designation <> '') DESC,
                         member_name ASC
                LIMIT 6
            ");
            $mlcQ->execute([$priStateCode, $pinLike, $pinLike, $distLike, $distLike, $distLike]);
            $pinMlcs = $mlcQ->fetchAll();
        }

        // Registered Public Politicians in PIN or resolved op_sdb Block/District
        if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
            $uQ = $pdo->prepare("
                SELECT u.*, lt.name AS leader_type_name
                FROM users u
                LEFT JOIN leader_types lt ON u.leader_category = lt.slug
                WHERE u.account_type = 'politician'
                  AND u.status = 'active'
                  AND u.profile_visibility = 'public'
                  AND u.is_mobile_verified = 1
                  AND (u.pincode = ? OR u.block_code = ? OR u.district_code = ?)
                ORDER BY (u.pincode = ?) DESC, (u.block_code = ?) DESC, u.id DESC
                LIMIT 6
            ");
            $uQ->execute([$activePincode, $priBlockCode, $priDistCode, $activePincode, $priBlockCode]);
            $pinRegisteredPols = $uQ->fetchAll();
        }

        // ECI Political Party HQ Offices whose address includes this PIN code
        if ($pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
            $ppQ = $pdo->prepare("
                SELECT pp.id, pp.slug, pp.name, pp.name_hi, pp.symbol, pp.type, pp.state, pp.state_code, pp.address
                FROM political_party pp
                WHERE UPPER(TRIM(pp.status)) = 'ACTIVE'
                  AND pp.address LIKE ?
                ORDER BY CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC, pp.name ASC
                LIMIT 6
            ");
            $ppQ->execute([$pinLike]);
            $pinParties = $ppQ->fetchAll();
        }
    }
} elseif ($hasVillageTable && $hasSearchAttempt && $pinValidationError === '') {
    $searchFilters = [
        'q' => $rawQuery,
        'state_code' => $stateCode,
        'district_code' => $districtCode,
        'block_code' => $blockCode,
    ];
    if ($rawQuery === '' || !preg_match('/^\d{7,}$/', $rawQuery)) {
        $pincodeGroups = search_matching_pincode_groups($pdo, $searchFilters, 36);
        $villagesResult = search_pincode_villages($pdo, $searchFilters, $perPage, $offset);
    }
}

// State-wise summary grid on default landing (fast indexed aggregation via village + op_sdb)
$stateCoverageRows = [];
if ($hasVillageTable && !$hasSearchAttempt) {
    $sdbMaps = get_op_sdb_lookup_maps($pdo);
    $stRows = $pdo->query("
        SELECT v.state_code, COUNT(*) AS village_count, COUNT(DISTINCT v.district_code) AS district_count
        FROM village v
        WHERE v.state_code IS NOT NULL
        GROUP BY v.state_code
        ORDER BY village_count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stRows as $sr) {
        $sc = (string)$sr['state_code'];
        $stateCoverageRows[] = [
            'state_code' => $sc,
            'state_name' => $sdbMaps['states'][$sc] ?? ('State #' . $sc),
            'village_count' => (int)$sr['village_count'],
            'district_count' => (int)$sr['district_count'],
        ];
    }
}

$totalVillages = (int)$villagesResult['total'];
$totalPages = max(1, (int)ceil($totalVillages / $perPage));

if ($pinSummary !== null) {
    $pri = $pinSummary['primary'];
    $pageTitle = "PIN Code {$activePincode} — {$pri['block_name']}, {$pri['district_name']}, {$pri['state_name']}";
    $pageDescription = "PIN Code {$activePincode} covers {$pinSummary['village_count']} villages in {$pri['block_name']} block, {$pri['district_name']} district, {$pri['state_name']}. Explore villages, block & district hierarchy, and local leaders.";
    $canonicalUrl = get_pincode_page_url($activePincode);
} else {
    $pageTitle = "India PIN Code & Village Finder";
    $pageDescription = "Search " . number_format($datasetStats['pincodes']) . " Indian PIN codes and " . number_format($datasetStats['villages']) . " villages mapped across LGD States, Districts & Blocks in India.";
    $canonicalUrl = get_site_url('pincode');
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Hero Banner -->
    <div class="tiranga-banner" style="flex-wrap:wrap; gap:14px;">
        <div>
            <h1><i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> India PIN Code &amp; Village Directory</h1>
            <p>
                Search <strong><?= number_format($datasetStats['pincodes']) ?></strong> Indian PIN codes across <strong><?= number_format($datasetStats['villages']) ?></strong> villages synced with official LGD States, Districts &amp; Blocks.
            </p>
        </div>
        <a href="<?= get_site_url('index') ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.85rem;">
            <i class="fas fa-arrow-left"></i> Local Leaders Directory
        </a>
    </div>

    <!-- Coverage KPIs -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:18px;">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid var(--saffron-main); border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Villages Directory</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--saffron-dark); margin-top:2px;"><?= number_format($datasetStats['villages']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">Across <?= number_format($datasetStats['village_states']) ?> States &amp; UTs</div>
        </div>
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid var(--green-main); border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Unique PIN Codes</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--green-dark); margin-top:2px;"><?= number_format($datasetStats['pincodes']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">Verified 6-digit postal codes</div>
        </div>
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid #0284C7; border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">LGD Blocks</div>
            <div style="font-size:1.35rem; font-weight:800; color:#0369A1; margin-top:2px;"><?= number_format($datasetStats['sdb_blocks']) ?></div>
            <div style="font-size:0.74rem; color:var(--text-secondary);"><?= $datasetStats['sdb_states'] ?> States • <?= number_format($datasetStats['sdb_districts']) ?> Districts</div>
        </div>
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid #7C3AED; border-radius:var(--radius-md); padding:12px 14px; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Census &amp; LGD Bridge</div>
            <div style="font-size:1.1rem; font-weight:800; color:#5B21B6; margin-top:4px;">PIN → Block → Netas</div>
            <div style="font-size:0.74rem; color:var(--text-secondary);">Direct leader &amp; GP discovery</div>
        </div>
    </div>

    <!-- Search & State / District / Block Cascade Filter Form -->
    <section style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--green-main); border-radius:var(--radius-lg); padding:18px; margin-bottom:20px; box-shadow:var(--shadow-sm);">
        <form action="<?= get_site_url('pincode') ?>" method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; align-items:flex-end;">
            <div class="form-group" style="margin:0;">
                <label class="form-label" for="pinQueryInput" style="font-size:0.78rem;">PIN Code or Village / Block Name</label>
                <input type="text" id="pinQueryInput" name="q" value="<?= e($rawQuery) ?>" class="form-control" style="padding:8px 10px; font-size:0.88rem;" placeholder="e.g. 841301, 8413, Dahiawan, Ekma...">
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="pinStateSelect" style="font-size:0.78rem;">State / UT</label>
                <select id="pinStateSelect" name="state_code" class="form-control" style="padding:8px 10px; font-size:0.88rem;" onchange="loadPinDistricts(this.value)">
                    <option value="">All States &amp; UTs</option>
                    <?php foreach ($states as $st): ?>
                        <option value="<?= e($st['state_code']) ?>" <?= $stateCode === (string)$st['state_code'] ? 'selected' : '' ?>>
                            <?= e($st['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="pinDistrictSelect" style="font-size:0.78rem;">District</label>
                <select id="pinDistrictSelect" name="district_code" class="form-control" style="padding:8px 10px; font-size:0.88rem;" onchange="loadPinBlocks(this.value)">
                    <option value="">All Districts</option>
                    <?php foreach ($districts as $dt): ?>
                        <option value="<?= e($dt['district_code']) ?>" <?= $districtCode === (string)$dt['district_code'] ? 'selected' : '' ?>>
                            <?= e($dt['district']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" for="pinBlockSelect" style="font-size:0.78rem;">Block / Sub-District</label>
                <select id="pinBlockSelect" name="block_code" class="form-control" style="padding:8px 10px; font-size:0.88rem;">
                    <option value="">All Blocks</option>
                    <?php foreach ($blocks as $blk): ?>
                        <option value="<?= e($blk['block_code']) ?>" <?= $blockCode === (string)$blk['block_code'] ? 'selected' : '' ?>>
                            <?= e($blk['block']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn-primary-tiranga" style="padding:9px 16px; font-size:0.88rem; flex:1;">
                    <i class="fas fa-search"></i> Search PIN
                </button>
                <?php if ($hasSearchAttempt || $pinValidationError !== ''): ?>
                    <a href="<?= get_site_url('pincode') ?>" class="btn-profile-view" style="padding:9px 12px; font-size:0.84rem; flex:unset;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Quick Sample PIN Chips -->
        <div style="margin-top:12px; padding-top:10px; border-top:1px dashed var(--border-color); display:flex; align-items:center; flex-wrap:wrap; gap:6px;">
            <span style="font-size:0.76rem; font-weight:700; color:var(--text-muted); margin-right:4px;">Quick Samples:</span>
            <?php
            $samplePins = [
                '841301' => '841301 • Chapra (Bihar)',
                '841305' => '841305 • Revelganj (Bihar)',
                '841212' => '841212 • Ekma (Bihar)',
                '800001' => '800001 • Patna',
                '226002' => '226002 • Lucknow (UP)',
                '522001' => '522001 • Guntur (AP)',
                '110010' => '110010 • New Delhi',
                '744301' => '744301 • Car Nicobar',
            ];
            foreach ($samplePins as $sPin => $sLabel):
                $sActive = ($activePincode === $sPin);
            ?>
                <a href="<?= e(get_pincode_page_url($sPin)) ?>"
                   style="padding:3px 10px; border-radius:var(--radius-full); font-size:0.75rem; font-weight:700; text-decoration:none; border:1px solid <?= $sActive ? 'var(--saffron-main)' : 'var(--border-color)' ?>; background:<?= $sActive ? 'var(--saffron-main)' : 'var(--white-surface)' ?>; color:<?= $sActive ? '#FFFFFF' : 'var(--navy-main)' ?>;">
                    <?= e($sLabel) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($pinValidationError !== ''): ?>
        <div class="alert-box alert-error">
            <i class="fas fa-circle-exclamation"></i>
            <div><?= e($pinValidationError) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($activePincode !== ''): ?>
        <?php if ($pinSummary === null): ?>
            <section style="background:#FFFFFF; padding:36px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color); margin-bottom:28px;">
                <i class="fas fa-map-location-dot" style="font-size:2.2rem; color:var(--text-muted); margin-bottom:10px;"></i>
                <h2 style="font-size:1.15rem; color:var(--navy-main);">No villages matched PIN Code <?= e($activePincode) ?></h2>
                <p style="font-size:0.86rem; color:var(--text-muted); margin-top:4px;">
                    Try searching the 3–4 digit prefix (e.g. <strong><?= e(substr($activePincode, 0, 4)) ?></strong>) or browse by State, District &amp; Block above.
                </p>
                <a href="<?= get_site_url('pincode?q=' . urlencode(substr($activePincode, 0, 4))) ?>" class="btn-primary-tiranga" style="margin-top:12px; padding:7px 16px; font-size:0.84rem;">
                    Search Prefix <?= e(substr($activePincode, 0, 4)) ?>
                </a>
            </section>
        <?php else:
            $pri = $pinSummary['primary'];
            $areaFilterParams = array_filter([
                'state_code' => $pri['state_code'],
                'district_code' => $pri['district_code'],
                'block_code' => $pri['lgd_matched'] ? $pri['block_code'] : '',
                'pincode' => $activePincode,
            ], fn($v) => $v !== '');
            $homeAreaUrl = get_site_url('index?' . http_build_query($areaFilterParams));
            $leadersAreaUrl = get_site_url('leaders?' . http_build_query($areaFilterParams));
            $electionsUrl = get_site_url('elections?mp_state=' . urlencode($pri['state_code']) . '#mp-directory');
            $partiesUrl = get_site_url('parties?state_code=' . urlencode($pri['state_code']));
        ?>
            <!-- Exact PIN Official Block Mapping Summary -->
            <section style="background:#FFFFFF; border:1px solid var(--border-color); border-left:5px solid var(--saffron-main); border-radius:var(--radius-lg); padding:20px; margin-bottom:22px; box-shadow:var(--shadow-sm);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
                    <div>
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
                            <span class="cat-pill bg-rose" style="font-size:0.8rem;"><i class="fas fa-location-dot"></i> PIN <?= e($activePincode) ?></span>
                            <span class="badge-tag badge-elected"><i class="fas fa-check-circle"></i> Verified LGD Directory Mapping</span>
                            <?php if ($pinSummary['panchayat_count'] > 0): ?>
                                <span class="badge-tag badge-verified"><?= number_format($pinSummary['panchayat_count']) ?> Gram Panchayats</span>
                            <?php endif; ?>
                        </div>
                        <h2 style="font-size:1.35rem; font-weight:800; color:var(--navy-main); margin:0 0 4px;">
                            <?= e(implode(' / ', array_map(fn($d) => $d['district_name'], $pinSummary['districts']))) ?>,
                            <?= e(implode(' / ', array_map(fn($s) => $s['state_name'], $pinSummary['states']))) ?>
                        </h2>
                        <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">
                            Covers <strong><?= number_format($pinSummary['village_count']) ?></strong> village<?= $pinSummary['village_count'] === 1 ? '' : 's' ?> across
                            <strong><?= count($pinSummary['blocks']) ?></strong> sub-district/block<?= count($pinSummary['blocks']) === 1 ? '' : 's' ?>
                            (Primary LGD Block: <strong><?= e($pri['block_name']) ?></strong><?= $pri['c_2011'] !== '' ? ' • LGD/Census 2011: ' . e($pri['c_2011']) : '' ?>).
                        </p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="<?= e($homeAreaUrl) ?>" class="btn-primary-tiranga" style="padding:8px 14px; font-size:0.84rem;">
                            <i class="fas fa-address-book"></i> Local Leaders in Area
                        </a>
                        <a href="<?= e($leadersAreaUrl) ?>" class="btn-green-tiranga" style="padding:8px 14px; font-size:0.84rem;">
                            <i class="fas fa-layer-group"></i> All 25 Leader Types
                        </a>
                        <a href="<?= e($electionsUrl) ?>" class="btn-profile-view" style="flex:unset; padding:8px 12px; font-size:0.82rem;">
                            MPs &amp; MLCs
                        </a>
                        <a href="<?= e($partiesUrl) ?>" class="btn-profile-view" style="flex:unset; padding:8px 12px; font-size:0.82rem;">
                            Parties
                        </a>
                    </div>
                </div>

                <!-- Block Breakdown Strip -->
                <div style="margin-top:14px; padding-top:12px; border-top:1px dashed var(--border-color); display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span style="font-size:0.78rem; font-weight:700; color:var(--text-muted);">Blocks under <?= e($activePincode) ?>:</span>
                    <?php if (count($pinSummary['blocks']) > 1): ?>
                        <a href="<?= e(get_pincode_page_url($activePincode)) ?>"
                           style="padding:4px 12px; border-radius:var(--radius-full); font-size:0.78rem; font-weight:700; text-decoration:none; border:1px solid <?= $blockCode === '' ? 'var(--navy-main)' : 'var(--border-color)' ?>; background:<?= $blockCode === '' ? 'var(--navy-main)' : 'var(--white-surface)' ?>; color:<?= $blockCode === '' ? '#FFFFFF' : 'var(--text-secondary)' ?>;">
                            All Blocks (<?= number_format($pinSummary['village_count']) ?>)
                        </a>
                    <?php endif; ?>
                    <?php foreach ($pinSummary['blocks'] as $blkMeta):
                        $isBlkSel = ($blockCode !== '' && $blockCode === (string)$blkMeta['block_code']);
                        $blkUrl = get_site_url('pincode/' . urlencode($activePincode) . '?block_code=' . urlencode((string)$blkMeta['block_code']));
                    ?>
                        <a href="<?= e($blkUrl) ?>"
                           style="padding:4px 12px; border-radius:var(--radius-full); font-size:0.78rem; font-weight:700; text-decoration:none; border:1px solid <?= $isBlkSel ? 'var(--green-main)' : 'var(--border-color)' ?>; background:<?= $isBlkSel ? 'var(--green-main)' : 'var(--white-surface)' ?>; color:<?= $isBlkSel ? '#FFFFFF' : 'var(--navy-main)' ?>;">
                            <?= e($blkMeta['block_name']) ?> (<?= number_format($blkMeta['village_count']) ?>)
                            <?php if ($blkMeta['district_name'] !== $pri['district_name']): ?>
                                • <?= e($blkMeta['district_name']) ?>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Villages Table for Exact PIN Code -->
            <section style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:18px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
                <div class="section-header" style="flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                    <div>
                        <h2 class="section-title"><i class="fas fa-house-chimney"></i> Villages under PIN <?= e($activePincode) ?></h2>
                        <p style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;">
                            Showing <?= count($villagesResult['rows']) ?> of <strong><?= number_format($totalVillages) ?></strong> village records (Page <?= $page ?> of <?= $totalPages ?>)
                        </p>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                        <thead>
                            <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                                <th style="padding:10px;">#</th>
                                <th style="padding:10px;">Village Name</th>
                                <th style="padding:10px;">LGD Village Code</th>
                                <th style="padding:10px;">Gram Panchayat</th>
                                <th style="padding:10px;">Block / Sub-District</th>
                                <th style="padding:10px;">District</th>
                                <th style="padding:10px;">State / UT</th>
                                <th style="padding:10px;">PIN Code</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($villagesResult['rows'] as $idx => $vRow): ?>
                                <tr style="border-bottom:1px solid var(--border-light);">
                                    <td style="padding:9px 10px; color:var(--text-muted);"><?= $offset + $idx + 1 ?></td>
                                    <td style="padding:9px 10px; font-weight:700; color:var(--navy-main);"><?= e($vRow['village']) ?></td>
                                    <td style="padding:9px 10px; font-family:monospace; color:var(--text-secondary);"><?= (int)$vRow['village_code'] ?></td>
                                    <td style="padding:9px 10px; color:var(--text-secondary);">
                                        <?= $vRow['panchayat_name'] !== '' ? '<span class="badge-tag badge-elected">' . e($vRow['panchayat_name']) . '</span>' : '<span style="color:var(--text-muted);">—</span>' ?>
                                    </td>
                                    <td style="padding:9px 10px;">
                                        <a href="<?= get_site_url('index?' . http_build_query(['state_code' => $vRow['state_code'], 'district_code' => $vRow['district_code'], 'block_code' => $vRow['block_code']])) ?>" style="color:var(--green-dark); font-weight:700; text-decoration:none;">
                                            <?= e($vRow['block_name']) ?>
                                        </a>
                                    </td>
                                    <td style="padding:9px 10px; color:var(--text-primary);"><?= e($vRow['district_name']) ?></td>
                                    <td style="padding:9px 10px; color:var(--text-secondary);"><?= e($vRow['state_name']) ?></td>
                                    <td style="padding:9px 10px;"><span class="cat-pill bg-indigo"><?= e($vRow['pincode']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-top:14px; padding-top:12px; border-top:1px solid var(--border-light);">
                        <div style="font-size:0.82rem; color:var(--text-muted);">
                            Page <?= $page ?> of <?= $totalPages ?> (<?= number_format($totalVillages) ?> villages)
                        </div>
                        <div style="display:flex; gap:8px;">
                            <?php if ($page > 1): ?>
                                <a href="<?= get_site_url('pincode/' . urlencode($activePincode) . '?' . http_build_query(array_filter(['block_code' => $blockCode, 'page' => $page - 1], fn($v) => $v !== ''))) ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="<?= get_site_url('pincode/' . urlencode($activePincode) . '?' . http_build_query(array_filter(['block_code' => $blockCode, 'page' => $page + 1], fn($v) => $v !== ''))) ?>" class="btn-primary-tiranga" style="padding:6px 14px; font-size:0.82rem;">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Local Representatives & Offices Resolved from Area & PIN -->
            <?php if (!empty($pinMps) || !empty($pinMlcs) || !empty($pinRegisteredPols) || !empty($pinParties)): ?>
                <section style="margin-bottom:28px;">
                    <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                        <div>
                            <h2 class="section-title"><i class="fas fa-users"></i> Representatives &amp; Offices for PIN <?= e($activePincode) ?> Area</h2>
                            <p style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;">
                                Matched using District (<?= e($pri['district_name']) ?>), State (<?= e($pri['state_name']) ?>) &amp; PIN code <?= e($activePincode) ?>
                            </p>
                        </div>
                    </div>

                    <?php if (!empty($pinMps)): ?>
                        <h3 style="font-size:0.96rem; font-weight:800; color:var(--navy-main); margin:12px 0 10px;"><i class="fas fa-landmark" style="color:var(--saffron-main);"></i> Parliament MPs (<?= e($pri['state_name']) ?>)</h3>
                        <div class="cards-grid" style="margin-bottom:20px;">
                            <?php foreach ($pinMps as $mp):
                                $mpUrl = get_mp_page_url((string)($mp['slug'] ?? ''), (int)$mp['id']);
                                $isRs = $mp['type'] === 'rs';
                            ?>
                                <div class="leader-card">
                                    <div class="leader-card-cover" style="height:46px; background:<?= $isRs ? 'linear-gradient(135deg, #046A38, #0A192F)' : 'var(--navy-gradient)' ?>;">
                                        <div style="position:absolute; top:8px; right:10px;"><span class="cat-pill <?= $isRs ? 'bg-emerald' : 'bg-rose' ?>"><?= $isRs ? 'Rajya Sabha' : 'Lok Sabha' ?></span></div>
                                    </div>
                                    <div class="leader-card-body">
                                        <h4 class="leader-name" style="margin:0 0 4px;"><a href="<?= e($mpUrl) ?>" style="color:var(--navy-main); text-decoration:none;"><?= e($mp['member_name']) ?></a></h4>
                                        <div style="font-size:0.8rem; color:var(--text-secondary);"><i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> <?= e($mp['constituency']) ?><?= !$isRs ? ', ' . e($mp['state']) : '' ?></div>
                                        <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;"><?= e($mp['party_name']) ?></div>
                                        <div class="leader-card-footer" style="margin-top:auto; padding-top:10px;">
                                            <a href="<?= e($mpUrl) ?>" class="btn-profile-view">View MP Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i></a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($pinMlcs)): ?>
                        <h3 style="font-size:0.96rem; font-weight:800; color:var(--navy-main); margin:12px 0 10px;"><i class="fas fa-scroll" style="color:var(--green-main);"></i> Legislative Council MLCs (<?= e($pri['state_name']) ?>)</h3>
                        <div class="cards-grid" style="margin-bottom:20px;">
                            <?php foreach ($pinMlcs as $mlcRow):
                                $mlcUrl = get_mlc_page_url((string)($mlcRow['slug'] ?? ''), (int)$mlcRow['id']);
                                $mId = (int)$mlcRow['id'];
                                $mName = (string)$mlcRow['member_name'];
                                $mPref = strtoupper(get_mlc_state_slug_prefix((string)$mlcRow['state_code'], (string)$mlcRow['state']));
                            ?>
                                <div class="leader-card">
                                    <div class="leader-card-cover" style="height:46px; background:linear-gradient(135deg, #046A38, #0A192F);">
                                        <div style="position:absolute; top:8px; right:10px;"><span class="cat-pill bg-purple"><?= e($mPref) ?> MLC</span></div>
                                    </div>
                                    <div class="leader-card-body">
                                        <h4 class="leader-name" style="margin:0 0 4px;"><a href="<?= e($mlcUrl) ?>" style="color:var(--navy-main); text-decoration:none;"><?= e($mName) ?></a></h4>
                                        <div style="font-size:0.8rem; color:var(--text-secondary);"><?= e($mlcRow['constituency'] ?: $mlcRow['constituency_type']) ?> • <?= e($mlcRow['party_name']) ?></div>
                                        <?php if (!empty($mlcRow['mobile'])): ?>
                                            <div style="margin-top:8px;">
                                                <?= render_contact_reveal_trigger('mlc', $mId, 'mobile', mask_phone_number((string)$mlcRow['mobile']), $mName, [
                                                    'unlocked_value' => isset($unlockedTodayMap['mlc:' . $mId]) ? (string)$mlcRow['mobile'] : '',
                                                    'compact' => true,
                                                    'btn_label' => 'View Number',
                                                ]) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="leader-card-footer" style="margin-top:auto; padding-top:10px;">
                                            <a href="<?= e($mlcUrl) ?>" class="btn-profile-view">View MLC Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i></a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($pinRegisteredPols)): ?>
                        <h3 style="font-size:0.96rem; font-weight:800; color:var(--navy-main); margin:12px 0 10px;"><i class="fas fa-user-check" style="color:var(--saffron-main);"></i> Verified Local Politicians in <?= e($activePincode) ?> / <?= e($pri['district_name']) ?></h3>
                        <div class="cards-grid" style="margin-bottom:20px;">
                            <?php foreach ($pinRegisteredPols as $rp):
                                $rpId = (int)$rp['id'];
                                $rpName = trim((string)($rp['full_name'] ?: $rp['name']));
                                $rpUrl = get_user_profile_url((string)($rp['username_handle'] ?? ''), $rpId);
                                $rpMobVis = (string)($rp['mobile_visibility'] ?? 'masked');
                            ?>
                                <div class="leader-card">
                                    <div class="leader-card-cover" style="height:46px;">
                                        <div style="position:absolute; top:8px; right:10px;"><span class="cat-pill bg-emerald"><?= e($rp['leader_type_name'] ?: 'Representative') ?></span></div>
                                    </div>
                                    <div class="leader-card-body">
                                        <h4 class="leader-name" style="margin:0 0 4px;"><a href="<?= e($rpUrl) ?>" style="color:var(--navy-main); text-decoration:none;"><?= e($rpName) ?></a></h4>
                                        <div style="font-size:0.8rem; color:var(--text-secondary);"><?= e($rp['designation'] ?: $rp['constituency'] ?: $pri['block_name']) ?></div>
                                        <?php if (!empty($rp['mobile']) && $rpMobVis !== 'private'): ?>
                                            <div style="margin-top:8px;">
                                                <?= render_contact_reveal_trigger('user', $rpId, 'mobile', mask_phone_number((string)$rp['mobile']), $rpName, [
                                                    'unlocked_value' => ($rpMobVis === 'public' || isset($unlockedTodayMap['user:' . $rpId])) ? (string)$rp['mobile'] : '',
                                                    'compact' => true,
                                                    'btn_label' => 'View Number',
                                                ]) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="leader-card-footer" style="margin-top:auto; padding-top:10px;">
                                            <a href="<?= e($rpUrl) ?>" class="btn-profile-view">Open Public Card <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i></a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($pinParties)): ?>
                        <h3 style="font-size:0.96rem; font-weight:800; color:var(--navy-main); margin:12px 0 10px;"><i class="fas fa-flag" style="color:#7C3AED;"></i> ECI Party Offices at PIN <?= e($activePincode) ?></h3>
                        <div class="cards-grid">
                            <?php foreach ($pinParties as $pp):
                                $ppUrl = get_party_page_url((string)($pp['slug'] ?? ''), (int)$pp['id']);
                            ?>
                                <div class="leader-card">
                                    <div class="leader-card-body" style="padding-top:16px;">
                                        <h4 class="leader-name" style="margin:0 0 4px;"><a href="<?= e($ppUrl) ?>" style="color:var(--navy-main); text-decoration:none;"><?= e(clean_party_display_name($pp['name'])) ?></a></h4>
                                        <div style="font-size:0.78rem; color:var(--text-secondary);"><?= e(clean_party_display_text($pp['address'] ?? '')) ?></div>
                                        <div class="leader-card-footer" style="margin-top:auto; padding-top:10px;">
                                            <a href="<?= e($ppUrl) ?>" class="btn-profile-view">Open Party Page <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i></a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($hasSearchAttempt && $pinValidationError === ''): ?>
        <!-- Prefix / Village Name / Area Discovery Results -->
        <section style="margin-bottom:24px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title"><i class="fas fa-magnifying-glass-location"></i> Matching PIN Codes &amp; Areas</h2>
                    <p style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;">
                        Found <strong><?= count($pincodeGroups) ?></strong> matching PIN code group<?= count($pincodeGroups) === 1 ? '' : 's' ?> and <strong><?= number_format($totalVillages) ?></strong> village<?= $totalVillages === 1 ? '' : 's' ?>
                    </p>
                </div>
            </div>

            <?php if (empty($pincodeGroups) && empty($villagesResult['rows'])): ?>
                <div style="background:#FFFFFF; padding:36px 20px; border-radius:var(--radius-lg); text-align:center; border:1px dashed var(--border-color);">
                    <i class="fas fa-map-signs" style="font-size:2.2rem; color:var(--text-muted); margin-bottom:10px;"></i>
                    <h3 style="font-size:1.1rem; color:var(--navy-main);">No matching PIN codes or villages found</h3>
                    <p style="font-size:0.86rem; color:var(--text-muted); margin-top:4px;">Try a 6-digit PIN (e.g. <code>841301</code>), PIN prefix (e.g. <code>8413</code>), or select a State / District / Block above.</p>
                </div>
            <?php else: ?>
                <?php if (!empty($pincodeGroups)): ?>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px; margin-bottom:22px;">
                        <?php foreach ($pincodeGroups as $pg): ?>
                            <a href="<?= e($pg['url']) ?>" style="background:#FFFFFF; border:1px solid var(--border-color); border-top:3px solid var(--saffron-main); border-radius:var(--radius-md); padding:14px 16px; box-shadow:var(--shadow-sm); text-decoration:none; display:flex; flex-direction:column; gap:6px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:8px;">
                                    <span style="font-size:1.15rem; font-weight:800; color:var(--navy-main);"><i class="fas fa-map-pin" style="color:var(--saffron-main);"></i> <?= e($pg['pincode']) ?></span>
                                    <span class="badge-tag badge-elected"><?= number_format($pg['village_count']) ?> Villages</span>
                                </div>
                                <div style="font-size:0.84rem; font-weight:700; color:var(--green-dark);">
                                    <?= e($pg['blocks_list'] ?: $pg['block_name']) ?> • <?= e($pg['district_name']) ?>
                                </div>
                                <div style="font-size:0.78rem; color:var(--text-muted);">
                                    <?= e($pg['state_name']) ?><?= !empty($pg['sample_villages']) ? ' — e.g. ' . e(implode(', ', $pg['sample_villages'])) : '' ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($villagesResult['rows'])): ?>
                    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:18px; box-shadow:var(--shadow-sm);">
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
                            <h3 style="font-size:1rem; font-weight:800; color:var(--navy-main); margin:0;">
                                Matching Villages (<?= number_format($totalVillages) ?> total)
                            </h3>
                        </div>
                        <div style="overflow-x:auto;">
                            <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                                <thead>
                                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                                        <th style="padding:10px;">Village</th>
                                        <th style="padding:10px;">Gram Panchayat</th>
                                        <th style="padding:10px;">Block / Sub-District</th>
                                        <th style="padding:10px;">District</th>
                                        <th style="padding:10px;">State / UT</th>
                                        <th style="padding:10px;">PIN Code</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($villagesResult['rows'] as $vRow): ?>
                                        <tr style="border-bottom:1px solid var(--border-light);">
                                            <td style="padding:9px 10px; font-weight:700; color:var(--navy-main);"><?= e($vRow['village']) ?></td>
                                            <td style="padding:9px 10px; color:var(--text-secondary);"><?= e($vRow['panchayat_name'] ?: '—') ?></td>
                                            <td style="padding:9px 10px; color:var(--green-dark); font-weight:600;"><?= e($vRow['block_name']) ?></td>
                                            <td style="padding:9px 10px;"><?= e($vRow['district_name']) ?></td>
                                            <td style="padding:9px 10px; color:var(--text-secondary);"><?= e($vRow['state_name']) ?></td>
                                            <td style="padding:9px 10px;">
                                                <a href="<?= e(get_pincode_page_url($vRow['pincode'])) ?>" class="cat-pill bg-indigo" style="text-decoration:none;">
                                                    <?= e($vRow['pincode']) ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <!-- Default Pan-India State-wise PIN & Village Coverage Grid -->
        <section style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:18px; margin-bottom:28px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <div>
                    <h2 class="section-title"><i class="fas fa-earth-asia"></i> Browse PIN Codes &amp; Villages by State / UT</h2>
                    <p style="font-size:0.82rem; color:var(--text-muted); margin-top:2px;">Click any State to discover District &amp; Block PIN codes from official LGD mappings</p>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px;">
                <?php foreach ($stateCoverageRows as $scRow): ?>
                    <a href="<?= get_site_url('pincode?state_code=' . urlencode($scRow['state_code'])) ?>"
                       style="background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:12px 14px; text-decoration:none; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                        <div>
                            <div style="font-weight:800; font-size:0.9rem; color:var(--navy-main);"><?= e($scRow['state_name']) ?></div>
                            <div style="font-size:0.76rem; color:var(--text-muted);"><?= number_format($scRow['district_count']) ?> Districts</div>
                        </div>
                        <span class="badge-tag badge-verified"><?= number_format($scRow['village_count']) ?> Villages</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

</main>

<script>
async function loadPinDistricts(stateCode) {
    const distEl = document.getElementById('pinDistrictSelect');
    const blkEl = document.getElementById('pinBlockSelect');
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

async function loadPinBlocks(districtCode) {
    const blkEl = document.getElementById('pinBlockSelect');
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


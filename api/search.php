<?php
// api/search.php - Universal Live Search Endpoint (MySQL + op_sdb)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pincode_helper.php';

$pdo = get_db_connection();
$q = trim($_GET['q'] ?? '');

if (mb_strlen($q) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];
$searchParam = '%' . $q . '%';

// 0. PIN Code & Village Directory Search (`village` + `op_sdb`)
// Note: Only match exact 6-digit Indian PINs, 3-5 digit PIN prefixes, or non-numeric place names (never 7+ digit phone numbers)
if (has_village_pincode_table($pdo)) {
    if (is_valid_indian_pincode($q)) {
        $pinSummary = get_pincode_area_summary($pdo, $q);
        if ($pinSummary !== null) {
            $pri = $pinSummary['primary'];
            $blkLabels = array_values(array_unique(array_filter(array_map(fn($b) => $b['block_name'], $pinSummary['blocks']))));
            $blkText = count($blkLabels) > 2
                ? ($blkLabels[0] . ', ' . $blkLabels[1] . ' +' . (count($blkLabels) - 2) . ' more')
                : implode(', ', $blkLabels);
            $results[] = [
                'title' => 'PIN ' . $pinSummary['pincode'] . ' — ' . $pri['district_name'] . ', ' . $pri['state_name'],
                'subtitle' => number_format($pinSummary['village_count']) . ' Villages • Block: ' . ($blkText ?: $pri['block_name']),
                'url' => get_pincode_page_url($pinSummary['pincode']),
                'icon' => 'fas fa-map-pin'
            ];
        }
    } elseif (is_pincode_prefix_query($q)) {
        foreach (search_matching_pincode_groups($pdo, ['q' => $q], 5) as $pg) {
            $results[] = [
                'title' => 'PIN ' . $pg['pincode'] . ' — ' . ($pg['blocks_list'] ?: $pg['block_name']) . ', ' . $pg['district_name'],
                'subtitle' => number_format($pg['village_count']) . ' Villages • ' . $pg['state_name'] . (!empty($pg['sample_villages']) ? ' • e.g. ' . implode(', ', array_slice($pg['sample_villages'], 0, 2)) : ''),
                'url' => $pg['url'],
                'icon' => 'fas fa-map-location-dot'
            ];
        }
    } elseif (!preg_match('/^\d+$/', $q) && mb_strlen($q) >= 3) {
        $vPrefix = $q . '%';
        $vStmt = $pdo->prepare("
            SELECT v.village, v.pincode, v.block, v.district, v.state_code,
                   COALESCE(sb.block, v.block) AS block_name,
                   COALESCE(sb.district, v.district) AS district_name,
                   sb.state AS state_name
            FROM village v
            LEFT JOIN op_sdb sb
                   ON sb.block_code = CAST(v.block_code AS CHAR) COLLATE utf8mb4_unicode_ci
                  AND sb.status = 'ACTIVE'
            WHERE v.village LIKE ? AND v.pincode IS NOT NULL AND v.pincode > 0
            ORDER BY v.village ASC, v.pincode ASC
            LIMIT 3
        ");
        $vStmt->execute([$vPrefix]);
        $sdbMaps = get_op_sdb_lookup_maps($pdo);
        foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $vr) {
            $pinStr = str_pad((string)$vr['pincode'], 6, '0', STR_PAD_LEFT);
            $stLabel = trim((string)($vr['state_name'] ?? ($sdbMaps['states'][(string)$vr['state_code']] ?? '')));
            $results[] = [
                'title' => trim((string)$vr['village']) . ' (PIN ' . $pinStr . ')',
                'subtitle' => trim((string)$vr['block_name']) . ', ' . trim((string)$vr['district_name']) . ($stLabel !== '' ? ', ' . $stLabel : ''),
                'url' => get_pincode_page_url($pinStr),
                'icon' => 'fas fa-location-dot'
            ];
        }
    }
}

// 1. Search Leaders (joined with op_sdb, panchayats & leader_types)
$stmt = $pdo->prepare("
    SELECT l.*, 
           lt.name as leader_type_name,
           sdb.block as block_name, 
           COALESCE(sdb.district, dist.district) as district_name,
           p.name_en as panchayat_name 
    FROM leaders l 
    LEFT JOIN leader_types lt ON l.category = lt.slug
    LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code 
    LEFT JOIN (SELECT DISTINCT district_code, district FROM op_sdb) dist ON l.district_code = dist.district_code
    LEFT JOIN panchayats p ON l.panchayat_id = p.id
    WHERE l.full_name_en LIKE ? 
       OR l.full_name_hi LIKE ? 
       OR l.designation_hi LIKE ? 
       OR COALESCE(lt.name, '') LIKE ?
       OR p.name_en LIKE ? 
       OR sdb.block LIKE ? 
       OR dist.district LIKE ?
    LIMIT 5
");
$stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
while ($row = $stmt->fetch()) {
    $locParts = [];
    if (!empty($row['panchayat_name'])) $locParts[] = 'Panchayat: ' . $row['panchayat_name'];
    if (!empty($row['block_name'])) $locParts[] = 'Block: ' . $row['block_name'];
    if (!empty($row['district_name'])) $locParts[] = $row['district_name'];

    $results[] = [
        'title' => $row['full_name_en'] . ' (' . $row['designation_hi'] . ')',
        'subtitle' => !empty($locParts) ? implode(', ', $locParts) : 'Verified Representative',
        'url' => get_site_url('profile?id=' . $row['id']),
        'icon' => 'fas fa-user-tie'
    ];
}

// 1b. Search Real Gram Panchayat Mukhiyas (`mukhiya` table — Bihar SEC)
if ($pdo->query("SHOW TABLES LIKE 'mukhiya'")->fetch()) {
    $stmt = $pdo->prepare("
        SELECT id, name, f_name, panchayat_name, block_name, district_name, district_code, mobile
        FROM mukhiya
        WHERE name LIKE ? OR f_name LIKE ? OR panchayat_name LIKE ? OR block_name LIKE ? OR district_name LIKE ?
        ORDER BY name ASC
        LIMIT 6
    ");
    $stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    while ($row = $stmt->fetch()) {
        $mkName = format_civic_title($row['name']);
        $mkPanchayat = format_civic_title($row['panchayat_name']);
        $mkBlock = format_civic_title($row['block_name']);
        $mkDist = format_civic_title($row['district_name']);
        $maskedMobile = mask_phone_number((string)($row['mobile'] ?? ''));
        $subParts = array_filter([
            $mkBlock !== '' ? $mkBlock : '',
            $mkDist !== '' ? $mkDist : '',
            $maskedMobile,
        ]);
        $results[] = [
            'title' => $mkName . ' (Mukhiya • GP ' . $mkPanchayat . ')',
            'subtitle' => implode(' • ', $subParts),
            'url' => get_site_url('index?' . http_build_query([
                'cat' => 'mukhiya',
                'state_code' => '10',
                'district_code' => (string)$row['district_code'],
                'block_code' => '',
                'q' => $mkPanchayat ?: $mkName,
            ])),
            'icon' => 'fas fa-user-check'
        ];
    }
}

// 1c. Search Real MLAs (`politician` table)
if ($pdo->query("SHOW TABLES LIKE 'politician'")->fetch()) {
    $stmt = $pdo->prepare("
        SELECT id, c_type, c_name, name, political_party, tenure, mobile
        FROM politician
        WHERE LOWER(TRIM(name)) NOT IN ('', 'vacant')
          AND c_type = 'MLA'
          AND (name LIKE ? OR c_name LIKE ? OR political_party LIKE ?)
        ORDER BY name ASC
        LIMIT 6
    ");
    $stmt->execute([$searchParam, $searchParam, $searchParam]);
    while ($row = $stmt->fetch()) {
        $polType = strtoupper(trim((string)$row['c_type']));
        $polName = format_civic_title($row['name']);
        $polSeat = format_civic_title($row['c_name']);
        $maskedMobile = mask_phone_number((string)($row['mobile'] ?? ''));
        $subParts = array_filter([
            trim((string)($row['political_party'] ?? '')),
            !empty($row['tenure']) ? 'Tenure: ' . trim((string)$row['tenure']) : '',
            $maskedMobile,
        ]);
        $results[] = [
            'title' => $polName . ' (' . $polType . ' • ' . $polSeat . ')',
            'subtitle' => implode(' • ', $subParts),
            'url' => get_site_url('leaders?' . http_build_query([
                'cat' => 'mla',
                'state_code' => '10',
                'q' => $polSeat ?: $polName,
            ])),
            'icon' => 'fas fa-building-columns'
        ];
    }
}

// 1d. Search Real Members of Legislative Council (`mlc` table — UP, Bihar & Andhra Pradesh via NeVA)
if ($pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()) {
    $mlcPinClause = is_valid_indian_pincode($q)
        ? " OR COALESCE(local_address, '') LIKE ? OR COALESCE(permanent_address, '') LIKE ?"
        : '';
    $stmt = $pdo->prepare("
        SELECT id, slug, member_name, constituency, constituency_area, constituency_type, party_name, role_designation, state, state_code
        FROM mlc
        WHERE status = 'ACTIVE'
          AND (member_name LIKE ?
               OR constituency LIKE ?
               OR COALESCE(constituency_area, '') LIKE ?
               OR constituency_type LIKE ?
               OR party_name LIKE ?
               OR COALESCE(role_designation, '') LIKE ?
               OR state LIKE ?{$mlcPinClause})
        ORDER BY (role_designation IS NOT NULL AND role_designation <> '') DESC,
                 FIELD(state_code, '10', '9', '28'),
                 seat_no IS NULL ASC,
                 seat_no ASC,
                 member_name ASC
        LIMIT 6
    ");
    $stmt->execute(array_fill(0, is_valid_indian_pincode($q) ? 9 : 7, $searchParam));
    while ($row = $stmt->fetch()) {
        $mlcUrl = get_mlc_page_url((string)($row['slug'] ?? ''), (int)$row['id']);
        $pref = strtoupper(get_mlc_state_slug_prefix((string)$row['state_code'], (string)$row['state']));
        $seat = trim((string)($row['constituency'] ?: $row['constituency_type']));
        $subParts = array_filter([
            $seat !== '' ? $seat : '',
            trim((string)($row['state'] ?? '')),
            trim((string)($row['party_name'] ?? '')),
            trim((string)($row['role_designation'] ?? '')),
        ]);
        $results[] = [
            'title' => $row['member_name'] . ' (' . $pref . ' MLC' . ($seat !== '' ? ' • ' . $seat : '') . ')',
            'subtitle' => implode(' • ', $subParts),
            'url' => $mlcUrl,
            'icon' => 'fas fa-scroll'
        ];
    }
}

// 1e. Search Registered Public Politicians (`users` table — strictly masked contact in search output)
if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()) {
    $uPinClause = is_valid_indian_pincode($q) ? " OR COALESCE(pincode, '') = ?" : '';
    $stmt = $pdo->prepare("
        SELECT id, name, full_name, username_handle, designation, constituency, party_name, district, state_name,
               mobile, email, mobile_visibility, email_visibility
        FROM users
        WHERE account_type = 'politician'
          AND status = 'active'
          AND profile_visibility = 'public'
          AND is_mobile_verified = 1
          AND (name LIKE ? OR COALESCE(full_name, '') LIKE ? OR COALESCE(constituency, '') LIKE ? OR COALESCE(party_name, '') LIKE ? OR COALESCE(district, '') LIKE ?{$uPinClause})
        ORDER BY id DESC
        LIMIT 4
    ");
    $uArgs = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
    if (is_valid_indian_pincode($q)) {
        $uArgs[] = $q;
    }
    $stmt->execute($uArgs);
    while ($row = $stmt->fetch()) {
        $uName = trim((string)($row['full_name'] ?: $row['name']));
        $maskedMob = ($row['mobile_visibility'] ?? 'masked') !== 'private' ? mask_phone_number((string)($row['mobile'] ?? '')) : '';
        $maskedMail = ($row['email_visibility'] ?? 'masked') !== 'private' && !empty($row['email']) ? mask_email_address((string)$row['email']) : '';
        $subParts = array_filter([
            trim((string)($row['designation'] ?: $row['constituency'] ?: '')),
            trim((string)($row['party_name'] ?? '')),
            $maskedMob,
            $maskedMail,
        ]);
        $results[] = [
            'title' => $uName . ' (Verified Politician)',
            'subtitle' => implode(' • ', $subParts),
            'url' => get_user_profile_url((string)($row['username_handle'] ?? ''), (int)$row['id']),
            'icon' => 'fas fa-user-check'
        ];
    }
}

// 2. Search Development Works
$stmt = $pdo->prepare("SELECT * FROM development_works WHERE title_hi LIKE ? OR category_name_hi LIKE ? LIMIT 3");
$stmt->execute([$searchParam, $searchParam]);
while ($row = $stmt->fetch()) {
    $results[] = [
        'title' => $row['title_hi'],
        'subtitle' => 'Development Work • ' . $row['category_name_hi'] . ' • ' . $row['status_label_hi'],
        'url' => get_site_url('issues?tab=works#work-' . $row['id']),
        'icon' => 'fas fa-road'
    ];
}

// 3. Search Issues
$stmt = $pdo->prepare("SELECT * FROM public_issues WHERE title_hi LIKE ? OR locality_hi LIKE ? LIMIT 3");
$stmt->execute([$searchParam, $searchParam]);
while ($row = $stmt->fetch()) {
    $results[] = [
        'title' => $row['title_hi'],
        'subtitle' => 'Public Issue • ' . $row['locality_hi'] . ' • ' . $row['status_label_hi'],
        'url' => get_site_url('issues?tab=issues#issue-' . $row['id']),
        'icon' => 'fas fa-bullhorn'
    ];
}

// 4. Search Lok Sabha (LS-18) & Rajya Sabha MPs (`mp` table)
$stmt = $pdo->prepare("
    SELECT id, slug, member_name, party_name, constituency, state, state_code, membership_status, type, election
    FROM mp
    WHERE member_name LIKE ? OR constituency LIKE ? OR party_name LIKE ? OR state LIKE ? OR COALESCE(election, '') LIKE ?
    ORDER BY type = 'ls' DESC, membership_status = 'Sitting' DESC, member_name ASC
    LIMIT 6
");
$stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
while ($row = $stmt->fetch()) {
    $isRs = $row['type'] === 'rs';
    $houseLabel = !$isRs
        ? strtoupper($row['election'] ?: 'LS-18')
        : ($row['election'] ?: 'RS Nominated');
    $mpUrl = get_mp_page_url((string)($row['slug'] ?? ''), (int)$row['id']);
    $locLabel = $isRs ? $row['constituency'] : ($row['constituency'] . ', ' . $row['state']);
    $results[] = [
        'title' => $row['member_name'] . ' (MP, ' . $houseLabel . ')',
        'subtitle' => $locLabel . ' • ' . $row['party_name'] . ' • ' . $row['membership_status'],
        'url' => $mpUrl,
        'icon' => 'fas fa-landmark'
    ];
}

// 5. Search Political Parties (`political_party` table — English + Hindi names & addresses)
if ($pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
    $stmt = $pdo->prepare("
        SELECT pp.id, pp.slug, pp.name, pp.name_hi, pp.symbol, pp.type, pp.state, pp.state_code, pp.status,
               COALESCE(s.state, pp.state) AS state_display
        FROM political_party pp
        LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
               ON pp.state_code = s.state_code
        WHERE pp.name LIKE ?
           OR COALESCE(pp.name_hi, '') LIKE ?
           OR pp.symbol LIKE ?
           OR pp.address LIKE ?
           OR COALESCE(pp.address_hi, '') LIKE ?
           OR pp.state LIKE ?
           OR s.state LIKE ?
        ORDER BY CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
                 CASE UPPER(TRIM(pp.status)) WHEN 'ACTIVE' THEN 1 WHEN 'INACTIVE' THEN 2 WHEN 'DELISTED' THEN 3 ELSE 4 END ASC,
                 pp.name ASC
        LIMIT 5
    ");
    $stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    while ($row = $stmt->fetch()) {
        $rawType = trim((string)$row['type']);
        $cleanName = trim(preg_replace('/^\d+\.\s*/u', '', preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string)$row['name']))));
        $cleanNameHi = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string)($row['name_hi'] ?? ''))));
        $cleanSymbol = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', (string)($row['symbol'] ?? ''))));
        $cleanState = trim(preg_replace('/\s+/u', ' ', (string)$row['state_display']));
        $statusVal = strtoupper(trim((string)($row['status'] ?? 'ACTIVE')));
        $isNational = strcasecmp($rawType, 'National') === 0;
        $subParts = [$rawType . ' Party'];
        if ($statusVal !== 'ACTIVE') {
            $subParts[] = $statusVal;
        }
        $subParts[] = $isNational ? 'All India' : $cleanState;
        if ($cleanSymbol !== '') {
            $subParts[] = 'Symbol: ' . $cleanSymbol;
        }
        $partyUrl = get_party_page_url((string)($row['slug'] ?? ''), (int)$row['id']);
        $results[] = [
            'title' => $cleanNameHi !== '' ? ($cleanName . ' (' . $cleanNameHi . ')') : $cleanName,
            'subtitle' => implode(' • ', $subParts),
            'url' => $partyUrl,
            'icon' => 'fas fa-flag'
        ];
    }
}

echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

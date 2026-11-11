<?php
// mlc.php - Dedicated Profile Page for Each Member of Legislative Council (/mlc/<state_prefix>-<slug>)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user_auth.php';

$pdo = get_db_connection();
$viewer = get_current_user_account($pdo);
$unlockedTodayMap = $viewer ? get_user_revealed_entity_keys_today($pdo, (int)$viewer['id']) : [];

$rawSlug = trim($_GET['slug'] ?? '');
$rawId = (int)($_GET['id'] ?? 0);

$mlc = null;
$baseSelect = "
    SELECT m.*,
           COALESCE(s.state, m.state) AS state_display
    FROM mlc m
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON m.state_code = s.state_code
";

if ($rawSlug !== '') {
    $normInputSlug = strtolower(trim($rawSlug, '/ '));

    // Extract optional 2-letter state prefix (br-, up-, ap-, mh-, ka-, ts-)
    $prefixToStateCode = [
        'up' => '9',
        'br' => '10',
        'ap' => '28',
        'mh' => '27',
        'ka' => '29',
        'ts' => '36',
    ];
    $inputPrefix = '';
    $inputBody = $normInputSlug;
    if (preg_match('/^(up|br|ap|mh|ka|ts)-(.+)$/', $normInputSlug, $pm)) {
        $inputPrefix = $pm[1];
        $inputBody = $pm[2];
    }
    $targetStateCode = $inputPrefix !== '' ? ($prefixToStateCode[$inputPrefix] ?? '') : '';

    // 1. Exact slug lookup (e.g., br-nawal-kishore-yadav, up-keshav-prasad-maurya)
    $stmt = $pdo->prepare($baseSelect . " WHERE m.slug = ? LIMIT 1");
    $stmt->execute([$normInputSlug]);
    $mlc = $stmt->fetch() ?: null;

    // 2. Strip honorific prefixes / normalize punctuation (e.g., br-prof-dr-nawal-kishore-yadav -> br-nawal-kishore-yadav)
    $cleanBodySlug = generate_mlc_slug(str_replace('-', ' ', $inputBody));
    if (!$mlc && $cleanBodySlug !== '') {
        if ($inputPrefix !== '') {
            $prefCandidate = $inputPrefix . '-' . $cleanBodySlug;
            if ($prefCandidate !== $normInputSlug) {
                $stmt = $pdo->prepare($baseSelect . " WHERE m.slug = ? LIMIT 1");
                $stmt->execute([$prefCandidate]);
                $mlc = $stmt->fetch() ?: null;
            }
        } else {
            // Unprefixed fallback (e.g., nawal-kishore-yadav -> br-nawal-kishore-yadav)
            $candList = array_map(fn($p) => $p . '-' . $cleanBodySlug, array_keys($prefixToStateCode));
            $placeholders = implode(', ', array_fill(0, count($candList), '?'));
            $stmt = $pdo->prepare($baseSelect . "
                WHERE m.slug IN ({$placeholders})
                ORDER BY (m.membership_status = 'Sitting') DESC, (m.role_designation IS NOT NULL) DESC, m.id ASC
                LIMIT 1
            ");
            $stmt->execute($candList);
            $mlc = $stmt->fetch() ?: null;
        }
    }

    // 3. Disambiguation base prefix fallback (e.g., br-ajay-kumar-singh -> br-ajay-kumar-singh-munger)
    if (!$mlc && $cleanBodySlug !== '') {
        if ($inputPrefix !== '') {
            $stmt = $pdo->prepare($baseSelect . "
                WHERE m.slug LIKE ?
                ORDER BY (m.membership_status = 'Sitting') DESC, (m.role_designation IS NOT NULL) DESC, m.seat_no ASC, m.id ASC
                LIMIT 1
            ");
            $stmt->execute([$inputPrefix . '-' . $cleanBodySlug . '-%']);
            $mlc = $stmt->fetch() ?: null;
        } else {
            $stmt = $pdo->prepare($baseSelect . "
                WHERE m.slug LIKE ?
                ORDER BY (m.membership_status = 'Sitting') DESC, (m.role_designation IS NOT NULL) DESC, m.seat_no ASC, m.id ASC
                LIMIT 1
            ");
            $stmt->execute(['%-' . $cleanBodySlug . '%']);
            $mlc = $stmt->fetch() ?: null;
        }
    }

    // 4. Vowel-length tolerant fallback (aa/ee/oo/ii/uu)
    if (!$mlc) {
        $foldedBase = $cleanBodySlug !== ''
            ? ($inputPrefix !== '' ? $inputPrefix . '-' . $cleanBodySlug : $cleanBodySlug)
            : $normInputSlug;
        $foldedInput = str_replace(['aa', 'ee', 'oo', 'ii', 'uu'], ['a', 'i', 'u', 'i', 'u'], $foldedBase);
        if ($targetStateCode !== '') {
            $stmt = $pdo->prepare($baseSelect . "
                WHERE m.state_code = ?
                  AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(m.slug), 'aa', 'a'), 'ee', 'i'), 'oo', 'u'), 'ii', 'i'), 'uu', 'u') = ?
                ORDER BY (m.membership_status = 'Sitting') DESC, m.id ASC
                LIMIT 1
            ");
            $stmt->execute([$targetStateCode, $foldedInput]);
            $mlc = $stmt->fetch() ?: null;
        } else {
            $stmt = $pdo->prepare($baseSelect . "
                WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(m.slug), 'aa', 'a'), 'ee', 'i'), 'oo', 'u'), 'ii', 'i'), 'uu', 'u') LIKE ?
                ORDER BY (m.membership_status = 'Sitting') DESC, m.id ASC
                LIMIT 1
            ");
            $stmt->execute(['%-' . ltrim($foldedInput, '-')]);
            $mlc = $stmt->fetch() ?: null;
        }
    }

    // 5. Tight Levenshtein spelling fallback
    if (!$mlc && strlen($normInputSlug) >= 6 && strlen($normInputSlug) <= 130) {
        $compareTarget = $cleanBodySlug !== ''
            ? ($inputPrefix !== '' ? $inputPrefix . '-' . $cleanBodySlug : $cleanBodySlug)
            : $normInputSlug;
        $bestId = null;
        $bestDist = 999;
        $maxDist = strlen($compareTarget) >= 12 ? 2 : 1;

        $candSql = $targetStateCode !== ''
            ? "SELECT id, slug FROM mlc WHERE state_code = ?"
            : "SELECT id, slug FROM mlc";
        $candStmt = $pdo->prepare($candSql);
        $candStmt->execute($targetStateCode !== '' ? [$targetStateCode] : []);

        foreach ($candStmt as $cand) {
            $candSlug = strtolower((string)$cand['slug']);
            $candCompare = ($inputPrefix === '' && preg_match('/^(?:up|br|ap|mh|ka|ts)-(.+)$/', $candSlug, $cm))
                ? $cm[1]
                : $candSlug;
            $d = levenshtein($compareTarget, $candCompare);
            if ($d <= $maxDist && $d < $bestDist) {
                $bestDist = $d;
                $bestId = (int)$cand['id'];
            }
        }
        if ($bestId !== null) {
            $stmt = $pdo->prepare($baseSelect . " WHERE m.id = ? LIMIT 1");
            $stmt->execute([$bestId]);
            $mlc = $stmt->fetch() ?: null;
        }
    }
} elseif ($rawId > 0) {
    $stmt = $pdo->prepare($baseSelect . " WHERE m.id = ? LIMIT 1");
    $stmt->execute([$rawId]);
    $mlc = $stmt->fetch() ?: null;
}

if (!$mlc) {
    http_response_code(404);
    $pageTitle = "Member of Legislative Council Not Found";
    $pageDescription = "Requested MLC (Vidhan Parishad) profile could not be located in the LocalNeta Legislative Council directory.";
    require_once __DIR__ . '/includes/header.php';
    ?>
    <main class="main-wrapper">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:40px 24px; text-align:center; box-shadow:var(--shadow-sm); margin-top:16px;">
            <i class="fas fa-scroll" style="font-size:2.8rem; color:var(--text-muted); margin-bottom:14px;"></i>
            <h1 style="font-size:1.5rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">Member of Legislative Council (MLC) Not Found</h1>
            <p style="font-size:0.92rem; color:var(--text-secondary); max-width:560px; margin:0 auto 20px;">
                We could not find a Vidhan Parishad MLC profile matching <code><?= e($rawSlug ?: (string)$rawId) ?></code> across our verified Uttar Pradesh, Bihar, and Andhra Pradesh Legislative Council records.
            </p>
            <form action="<?= get_site_url('elections') ?>#mlc-directory" method="GET" style="display:flex; gap:8px; max-width:460px; margin:0 auto 20px;">
                <input type="text" name="mlc_q" value="<?= e(str_replace('-', ' ', preg_replace('/^(?:up|br|ap)-/', '', $rawSlug) ?? $rawSlug)) ?>" placeholder="Search MLC name, constituency, party or state..." class="form-control">
                <button type="submit" class="btn-primary-tiranga" style="white-space:nowrap;">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
            <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                <a href="<?= get_site_url('elections#mlc-directory') ?>" class="btn-green-tiranga" style="padding:8px 18px; font-size:0.86rem;">
                    <i class="fas fa-list"></i> Browse All 231 MLCs
                </a>
                <a href="<?= get_site_url('index?cat=mlc') ?>" class="btn-profile-view" style="flex:unset; padding:8px 18px; font-size:0.86rem;">
                    <i class="fas fa-home"></i> MLCs on Home
                </a>
            </div>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Helper to suppress NeVA placeholder tokens such as NA / Nil / - / 0
$cleanVal = static function (?string $raw): string {
    $v = clean_party_display_text($raw);
    if ($v === '') {
        return '';
    }
    if (in_array(strtolower($v), ['na', 'n/a', 'n.a', 'n.a.', 'nil', 'none', '-', '--', '0', 'not available', 'not applicable', 'null'], true)) {
        return '';
    }
    return $v;
};

// Parse & normalize MLC metadata
$mlcId = (int)$mlc['id'];
$mlcSlug = trim((string)($mlc['slug'] ?? ''));
$memberName = clean_mlc_display_name($mlc['member_name']);
$bareName = strip_mlc_honorific_prefix($memberName);
$stateCode = trim((string)($mlc['state_code'] ?? ''));
$stateDisplay = $cleanVal($mlc['state_display'] ?: $mlc['state']);
$statePrefix = get_mlc_state_slug_prefix($stateCode, $stateDisplay);
$houseName = $cleanVal($mlc['house']) ?: ($stateDisplay . ' Legislative Council');
$statusVal = $cleanVal($mlc['membership_status']) ?: 'Sitting';
$roleDesignation = $cleanVal($mlc['role_designation']);
$partyRaw = $cleanVal($mlc['party_name']);
$constituency = $cleanVal($mlc['constituency']);
$constituencyType = $cleanVal($mlc['constituency_type']);
$constituencyArea = $cleanVal($mlc['constituency_area']);
$constituencyFull = $cleanVal($mlc['constituency_full']);
$seatNo = !empty($mlc['seat_no']) ? (int)$mlc['seat_no'] : null;
$nevaMemberId = !empty($mlc['neva_member_id']) ? (int)$mlc['neva_member_id'] : null;

$gender = $cleanVal($mlc['gender']);
$socialCategory = $cleanVal($mlc['social_category']);
$fatherName = $cleanVal($mlc['father_name']);
$dob = $cleanVal($mlc['dob']);
$age = !empty($mlc['age']) && (int)$mlc['age'] > 18 ? (int)$mlc['age'] : null;
$maritalStatus = $cleanVal($mlc['marital_status']);
$spouseName = $cleanVal($mlc['spouse_name']);
$childrenDetails = $cleanVal($mlc['children_details']);
$highestQual = $cleanVal($mlc['highest_qualification']);
$otherQual = $cleanVal($mlc['other_qualification']);
$hobbies = $cleanVal($mlc['hobbies']);
$termPeriod = $cleanVal($mlc['term_period']);

$localAddress = $cleanVal($mlc['local_address']);
$permanentAddress = $cleanVal($mlc['permanent_address']);
$emailRaw = $cleanVal($mlc['email']);
$validEmail = $emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
$maskedEmail = mask_email_address($validEmail !== '' ? $validEmail : $emailRaw);

// Extract, sanitize & mask contact phone numbers cleanly (zero unmasked PII in HTML)
$mobileRaw = $cleanVal($mlc['mobile']);
$altMobileRaw = $cleanVal($mlc['alternate_mobile']);
$officeTelRaw = $cleanVal($mlc['office_tel']);

$parseMaskedPhoneList = static function (string $raw): array {
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/[,;\/|]+/', $raw) ?: [] as $part) {
        $masked = mask_phone_number($part);
        if ($masked !== '') {
            $out[] = $masked;
        }
    }
    return $out;
};

$primaryPhones = $parseMaskedPhoneList($mobileRaw);
$altPhones = $parseMaskedPhoneList($altMobileRaw);
$officePhones = $parseMaskedPhoneList($officeTelRaw);
$primaryMaskedPhone = $primaryPhones[0] ?? '';
$primaryMaskedAll = implode(', ', $primaryPhones);
$altMaskedAll = implode(', ', $altPhones);
$officeMaskedAll = implode(', ', $officePhones);
$isMlcUnlockedToday = !empty($unlockedTodayMap['mlc:' . (int)($mlc['id'] ?? 0)]);

$statusCls = strcasecmp($statusVal, 'Sitting') === 0
    ? 'status-success'
    : (strcasecmp($statusVal, 'Resigned') === 0 ? 'status-warning' : 'status-muted');

// Describe Constitutional Constituency quota cleanly
$quotaExplainer = match (strtolower($constituencyType)) {
    'graduates' => "Graduates' Constituency (Article 171(3)(b))",
    'teachers' => "Teachers' Constituency (Article 171(3)(c))",
    'local authorities' => "Local Authorities' Constituency (Article 171(3)(a))",
    'elected by mlas' => 'Elected by Members of Legislative Assembly (Article 171(3)(d))',
    'nominated' => 'Governor Nomination — Literature, Science, Art, Co-operative & Social Service (Article 171(3)(e))',
    default => $constituencyType ?: 'State Legislative Council',
};

$stateHeaderAccent = match ($stateCode) {
    '10' => 'linear-gradient(135deg, #046A38, #0A192F)',
    '9'  => 'linear-gradient(135deg, #0A192F, #1E3A8A)',
    default => 'linear-gradient(135deg, #5B21B6, #0A192F)',
};
$stateBorderColor = match ($stateCode) {
    '10' => 'var(--green-main)',
    '9'  => 'var(--saffron-main)',
    default => '#5B21B6',
};

// Initials fallback badge
$initialChars = '';
foreach (preg_split('/\s+/', trim($bareName)) ?: [] as $w) {
    $wClean = preg_replace('/[^A-Za-z]/', '', $w);
    if ($wClean !== '') {
        $initialChars .= strtoupper($wClean[0]);
    }
    if (strlen($initialChars) >= 2) {
        break;
    }
}
if ($initialChars === '') {
    $initialChars = strtoupper($statePrefix);
}

// Resolve political party metadata & ECI symbol
$partyMeta = resolve_party_card_meta($pdo, $partyRaw);

// Disambiguation check for peers sharing the exact same bare name
$bareNameSlug = generate_mlc_slug($memberName);
$dupStmt = $pdo->prepare("
    SELECT id, slug, member_name, constituency, constituency_area, constituency_type, state, party_name, role_designation
    FROM mlc
    WHERE id <> ?
      AND (LOWER(TRIM(member_name)) = LOWER(TRIM(?)) OR slug LIKE ?)
    ORDER BY (state_code = ?) DESC, id ASC
");
$dupStmt->execute([$mlcId, $memberName, '%-' . $bareNameSlug . '%', $stateCode]);
$sameNamePeers = array_values(array_filter(
    $dupStmt->fetchAll(),
    fn(array $row): bool => generate_mlc_slug((string)$row['member_name']) === $bareNameSlug
));

// Fetch other MLCs from the same State (prioritizing same constituency type and role holders)
$smStmt = $pdo->prepare("
    SELECT * FROM mlc
    WHERE id <> ? AND state_code = ? AND status = 'ACTIVE'
    ORDER BY (constituency_type = ?) DESC,
             (role_designation IS NOT NULL AND role_designation <> '') DESC,
             membership_status = 'Sitting' DESC,
             (photo_url IS NOT NULL AND photo_url <> '') DESC,
             seat_no ASC,
             member_name ASC
    LIMIT 8
");
$smStmt->execute([$mlcId, $stateCode, $constituencyType]);
$stateMlcs = $smStmt->fetchAll();

// Fetch fellow party MLCs (matching official name & NeVA aliases across states)
$partyMlcs = [];
if ($partyMeta && empty($partyMeta['is_independent'])) {
    $partyMatchCandidates = array_values(array_unique(array_filter([
        $partyRaw,
        $partyMeta['name'] ?? '',
        $partyMeta['short'] ?? '',
    ])));
    if (!empty($partyMeta['slug'])) {
        $slugToMlcParty = [
            'bharatiya-janata-party' => ['Bharatiya Janata Party'],
            'indian-national-congress' => ['Indian National Congress'],
            'samajwadi-party' => ['Samajwadi Party'],
            'janata-dal-united' => ['Janta Dal (United)', 'Janata Dal (United)'],
            'rashtriya-janata-dal' => ['Rashtriya Janta Dal', 'Rashtriya Janata Dal'],
            'telugu-desam' => ['Telugu Desam Party', 'Telugu Desam'],
            'yuvajana-sramika-rythu-congress-party' => ['Yuvajana Sramika Rythu Congress Party'],
            'janasena-party' => ['Janasena Party'],
            'rashtriya-lok-dal' => ['Rashtriya Lok Dal'],
            'rashtriya-lok-morcha' => ['Rashtriya Lok Morcha'],
            'apna-dal-soneylal' => ['Apna Dal (S) Party', 'Apna Dal (Soneylal)'],
            'nirbal-indian-shoshit-hamara-aam-dal' => ['NISHAD Party'],
            'suheldev-bharatiya-samaj-party' => ['Suheldev Bharatiya Samaj Party'],
            'jansatta-dal-loktantrik' => ['Jansatta Dal Loktantrik'],
            'hindustani-awam-morcha-secular' => ['Hindustani Awamee Morcha (Secular)', 'Hindustani Awam Morcha (Secular)'],
            'lok-janshakti-party-ram-vilas' => ['Lok Janshakti Party - RV', 'Lok Janshakti Party (Ram Vilas)'],
            'communist-party-of-india-marxist-leninist-liberation' => ['Communist Party of India - ML (Lib.)'],
            'communist-party-of-india' => ['Communist Party of India'],
        ];
        if (isset($slugToMlcParty[$partyMeta['slug']])) {
            $partyMatchCandidates = array_values(array_unique(array_merge($partyMatchCandidates, $slugToMlcParty[$partyMeta['slug']])));
        }
    }
    if (!empty($partyMatchCandidates)) {
        $pHolders = implode(', ', array_fill(0, count($partyMatchCandidates), '?'));
        $pmStmt = $pdo->prepare("
            SELECT * FROM mlc
            WHERE id <> ? AND status = 'ACTIVE' AND party_name IN ({$pHolders})
            ORDER BY (state_code = ?) DESC, (role_designation IS NOT NULL AND role_designation <> '') DESC, member_name ASC
            LIMIT 6
        ");
        $pmStmt->execute(array_merge([$mlcId], $partyMatchCandidates, [$stateCode]));
        $partyMlcs = $pmStmt->fetchAll();
    }
}

$sourcePortalLabel = match ($stateCode) {
    '10' => 'Bihar Vidhan Parishad (blc.neva.gov.in)',
    '9'  => 'Uttar Pradesh Vidhan Parishad (uplc.neva.gov.in)',
    '28' => 'Andhra Pradesh Legislative Council (apc.neva.gov.in)',
    default => 'National e-Vidhan Application (neva.gov.in)',
};

$pageTitle = "{$memberName} — MLC, {$houseName}" . ($constituency !== '' ? " ({$constituency})" : '');
$pageDescription = "{$memberName} is a {$statusVal} Member of Legislative Council (MLC) in {$houseName}" . ($constituency !== '' ? " representing {$constituency}" : '') . ($partyMeta ? " ({$partyMeta['name']})" : '') . ". View official biography, education, term details, party symbol, and contact directory on LocalNeta.";
$canonicalUrl = get_mlc_page_url($mlcSlug, $mlcId);

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Breadcrumb -->
    <nav style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:0.82rem; color:var(--text-muted); margin-bottom:16px;">
        <a href="<?= get_site_url('index') ?>" style="color:var(--navy-main); font-weight:600;">Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <a href="<?= get_site_url('index?cat=mlc') ?>" style="color:var(--navy-main); font-weight:600;">Legislative Council (MLCs)</a>
        <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php if ($stateCode !== ''): ?>
            <a href="<?= get_site_url('elections?mlc_state=' . urlencode($stateCode) . '#mlc-directory') ?>" style="color:var(--navy-main); font-weight:600;"><?= e($stateDisplay) ?> MLCs</a>
            <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php endif; ?>
        <span style="color:var(--saffron-main); font-weight:700;"><?= e($memberName) ?></span>
    </nav>

    <?php if (!empty($sameNamePeers)): ?>
        <!-- Disambiguation Strip when multiple MLCs share a name -->
        <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-left:4px solid var(--navy-accent); border-radius:var(--radius-md); padding:10px 14px; margin-bottom:16px; font-size:0.84rem; color:var(--navy-main); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
            <div>
                <i class="fas fa-circle-info" style="color:var(--navy-accent); margin-right:6px;"></i>
                Also in Legislative Council with this name:
                <?php foreach ($sameNamePeers as $pIdx => $peer):
                    $peerSeat = $peer['constituency'] ?: ($peer['constituency_area'] ?: $peer['constituency_type']);
                ?>
                    <?= $pIdx > 0 ? ' • ' : '' ?>
                    <a href="<?= get_mlc_page_url((string)$peer['slug'], (int)$peer['id']) ?>" style="font-weight:700; color:var(--navy-main); text-decoration:underline;">
                        <?= e($peer['member_name']) ?> (<?= e($peerSeat) ?>, <?= e($peer['state']) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Hero Profile Card -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); overflow:hidden; box-shadow:var(--shadow-sm); margin-bottom:24px;">
        <div style="height:10px; background:linear-gradient(90deg, #FF671F 0%, #FFFFFF 50%, #046A38 100%);"></div>

        <div style="padding:24px; display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start; justify-content:space-between;">
            <!-- Portrait / Council Emblem Box -->
            <div style="width:190px; flex-shrink:0; margin:0 auto; text-align:center; background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 12px;">
                <div style="width:124px; height:124px; margin:0 auto 12px; border-radius:50%; border:3px solid <?= $stateBorderColor ?>; background:<?= $stateHeaderAccent ?>; overflow:hidden; display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-sm); color:#FFFFFF;">
                    <?php if (!empty($mlc['photo_url'])): ?>
                        <img src="<?= e($mlc['photo_url']) ?>" alt="<?= e($memberName) ?>"
                             style="width:100%; height:100%; object-fit:cover; display:block;"
                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; flex-direction:column; gap:4px;">
                            <span style="font-size:1.85rem; font-weight:800; letter-spacing:0.04em;"><?= e($initialChars) ?></span>
                            <i class="fas fa-scroll" style="font-size:0.85rem; opacity:0.85;"></i>
                        </span>
                    <?php else: ?>
                        <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; flex-direction:column; gap:4px;">
                            <span style="font-size:1.85rem; font-weight:800; letter-spacing:0.04em;"><?= e($initialChars) ?></span>
                            <i class="fas fa-scroll" style="font-size:0.85rem; opacity:0.85;"></i>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="margin-bottom:6px; display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                    <span class="cat-pill bg-purple"><?= e(strtoupper($statePrefix)) ?> MLC</span>
                    <?php if ($seatNo !== null): ?>
                        <span class="cat-pill bg-indigo">Seat #<?= $seatNo ?></span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.8rem; font-weight:700; color:var(--navy-main);"><?= e($constituencyType ?: 'Legislative Council') ?></div>
                <div style="margin-top:6px;">
                    <span class="status-chip <?= $statusCls ?>"><span class="status-dot"></span> <?= e($statusVal) ?></span>
                </div>
            </div>

            <!-- Identity, Constituency, Party & Actions -->
            <div style="flex:1; min-width:260px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
                    <span class="cat-pill bg-indigo">
                        <i class="fas fa-scroll"></i> <?= e($houseName) ?>
                    </span>
                    <span class="badge-tag badge-verified">
                        <i class="fas fa-map-pin"></i> <?= e($stateDisplay) ?>
                    </span>
                    <?php if ($roleDesignation !== ''): ?>
                        <span class="cat-pill bg-amber">
                            <i class="fas fa-award"></i> <?= e($roleDesignation) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($age !== null): ?>
                        <span class="badge-tag badge-unverified">
                            <i class="fas fa-user"></i> Age: <?= $age ?> yrs
                        </span>
                    <?php endif; ?>
                    <?php if ($gender !== ''): ?>
                        <span class="badge-tag badge-unverified">
                            <i class="fas <?= strcasecmp($gender, 'Female') === 0 ? 'fa-venus' : 'fa-mars' ?>"></i> <?= e($gender) ?><?= $socialCategory !== '' ? ' • ' . e($socialCategory) : '' ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h1 style="font-size:1.7rem; font-weight:800; color:var(--navy-main); line-height:1.25; margin:0;">
                    <?= e($memberName) ?>
                </h1>

                <p style="font-size:0.95rem; font-weight:600; color:var(--green-dark); margin-top:4px;">
                    Member of Legislative Council (Vidhan Parishad) — <?= e($stateDisplay) ?>
                </p>

                <!-- Key Civic Metadata Grid -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(195px, 1fr)); gap:12px; margin-top:16px;">
                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-left:3px solid var(--saffron-main); border-radius:var(--radius-md); padding:11px 13px;">
                        <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                            Constituency / Quota
                        </div>
                        <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-top:2px;">
                            <?= e($constituency ?: ($constituencyArea ?: $constituencyType)) ?>
                        </div>
                        <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:2px;">
                            <?= e($quotaExplainer) ?>
                        </div>
                    </div>

                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-left:3px solid var(--green-main); border-radius:var(--radius-md); padding:11px 13px;">
                        <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                            Legislative Council
                        </div>
                        <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-top:2px;">
                            <a href="<?= get_site_url('elections?mlc_state=' . urlencode($stateCode) . '#mlc-directory') ?>" style="color:var(--navy-main); text-decoration:underline;">
                                <?= e($stateDisplay) ?>
                            </a>
                        </div>
                        <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:2px;">
                            <?= e($houseName) ?><?= $nevaMemberId !== null ? ' • NeVA ID #' . $nevaMemberId : '' ?>
                        </div>
                    </div>

                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-left:3px solid var(--navy-accent); border-radius:var(--radius-md); padding:11px 13px;">
                        <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                            <?= $termPeriod !== '' ? 'Council Tenure' : ($highestQual !== '' ? 'Education & Status' : 'Council Status') ?>
                        </div>
                        <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-top:2px;">
                            <?= $termPeriod !== '' ? e($termPeriod) : ($highestQual !== '' ? e($highestQual) : e($statusVal . ' MLC')) ?>
                        </div>
                        <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:2px;">
                            <?php if ($dob !== ''): ?>
                                DOB: <strong><?= e($dob) ?></strong><?= $age !== null ? ' (' . $age . ' yrs)' : '' ?>
                            <?php else: ?>
                                Membership: <strong><?= e($statusVal) ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Affiliated Political Party Box -->
                <?php if ($partyRaw !== ''): ?>
                    <div style="margin-top:14px; background:var(--white-surface); border:1px solid var(--border-color); border-left:4px solid var(--navy-main); border-radius:var(--radius-md); padding:12px 16px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                        <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                            <?php if ($partyMeta && !empty($partyMeta['symbol_url'])): ?>
                                <div style="width:44px; height:44px; border-radius:8px; border:1px solid var(--border-color); background:#FFFFFF; padding:4px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                    <img src="<?= e($partyMeta['symbol_url']) ?>" alt="<?= e($partyMeta['symbol_label'] ?? $partyMeta['name']) ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                                </div>
                            <?php else: ?>
                                <div style="width:44px; height:44px; border-radius:8px; border:1px solid var(--border-color); background:#FFFFFF; color:var(--green-main); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1.1rem;">
                                    <i class="fas fa-flag"></i>
                                </div>
                            <?php endif; ?>
                            <div style="min-width:0;">
                                <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                                    Political Party / Group Affiliation
                                </div>
                                <div style="font-size:0.98rem; font-weight:800; color:var(--navy-main); line-height:1.3;">
                                    <?= e($partyMeta['name'] ?? $partyRaw) ?>
                                    <?php if (!empty($partyMeta['short']) && strcasecmp((string)$partyMeta['short'], (string)($partyMeta['name'] ?? '')) !== 0): ?>
                                        <span style="font-size:0.8rem; color:var(--text-muted); font-weight:600;">(<?= e($partyMeta['short']) ?>)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($partyMeta['name_hi'])): ?>
                                    <div style="font-family:var(--font-hi); font-size:0.84rem; font-weight:600; color:var(--green-dark);" lang="hi">
                                        <?= e($partyMeta['name_hi']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($partyMeta && !empty($partyMeta['url'])): ?>
                            <a href="<?= e($partyMeta['url']) ?>" class="btn-profile-view" style="flex:unset; padding:7px 14px; font-size:0.82rem; white-space:nowrap;">
                                <i class="fas fa-flag"></i> Party Profile <i class="fas fa-arrow-right" style="font-size:0.72rem;"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Action CTAs -->
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;">
                    <a href="<?= get_site_url('studio?mlc_id=' . $mlcId) ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.86rem;">
                        <i class="fas fa-paint-brush"></i> Create Greeting Poster
                    </a>
                    <?php if ($primaryMaskedPhone !== ''): ?>
                        <a href="#mlc-contact-directory" class="btn-profile-view" style="flex:unset; padding:8px 14px; font-size:0.86rem;" title="Mobile number masked for privacy">
                            <i class="fas fa-phone" style="color:var(--green-main);"></i> <?= e($primaryMaskedPhone) ?>
                        </a>
                    <?php endif; ?>
                    <a href="<?= get_site_url('elections?mlc_state=' . urlencode($stateCode) . '#mlc-directory') ?>" class="btn-profile-view" style="flex:unset; padding:8px 16px; font-size:0.86rem;">
                        <i class="fas fa-users"></i> All <?= e($stateDisplay) ?> MLCs
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Two-Column Biographical Details & Contact / Address Cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(310px, 1fr)); gap:20px; margin-bottom:26px;">
        <!-- Biographical, Education & Family Details -->
        <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); padding:20px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="margin-bottom:12px;">
                <h2 class="section-title" style="font-size:1.05rem;">
                    <i class="fas fa-id-card"></i> Biography, Education &amp; Background
                </h2>
            </div>

            <div style="display:grid; gap:10px; font-size:0.86rem;">
                <?php
                $bioRows = array_filter([
                    ['label' => 'Council Seat & Quota', 'value' => ($constituencyFull !== '' ? $constituencyFull : $constituency) . ($constituencyType !== '' ? ' (' . $constituencyType . ')' : ''), 'icon' => 'fa-landmark'],
                    ['label' => "Father's / Guardian's Name", 'value' => $fatherName, 'icon' => 'fa-user-group'],
                    ['label' => 'Date of Birth', 'value' => $dob !== '' ? ($dob . ($age !== null ? ' (' . $age . ' years)' : '')) : '', 'icon' => 'fa-cake-candles'],
                    ['label' => 'Gender & Category', 'value' => trim($gender . ($socialCategory !== '' ? ' • ' . $socialCategory : ''), ' •'), 'icon' => 'fa-user'],
                    ['label' => 'Marital Status', 'value' => $maritalStatus, 'icon' => 'fa-ring'],
                    ['label' => 'Spouse Name', 'value' => $spouseName, 'icon' => 'fa-users'],
                    ['label' => 'Children Details', 'value' => $childrenDetails, 'icon' => 'fa-child-reaching'],
                    ['label' => 'Highest Qualification', 'value' => $highestQual, 'icon' => 'fa-graduation-cap'],
                    ['label' => 'Academic & Professional Details', 'value' => $otherQual, 'icon' => 'fa-book-open'],
                    ['label' => 'Interests & Hobbies', 'value' => $hobbies, 'icon' => 'fa-palette'],
                    ['label' => 'Legislative Term', 'value' => $termPeriod, 'icon' => 'fa-calendar-days'],
                ], fn($item) => trim((string)$item['value']) !== '');
                foreach ($bioRows as $bRow):
                ?>
                    <div style="display:flex; gap:10px; padding:8px 10px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas <?= e($bRow['icon']) ?>" style="color:var(--saffron-main); width:16px; margin-top:3px; flex-shrink:0;"></i>
                        <div style="min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.03em;">
                                <?= e($bRow['label']) ?>
                            </div>
                            <div style="color:var(--navy-main); font-weight:600; line-height:1.45; word-break:break-word;">
                                <?= nl2br(e($bRow['value'])) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Official Contact & Address Book -->
        <section id="mlc-contact-directory" style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--green-main); padding:20px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="margin-bottom:12px; flex-wrap:wrap; gap:8px;">
                <h2 class="section-title" style="font-size:1.05rem;">
                    <i class="fas fa-address-book"></i> Official Contact &amp; Addresses
                </h2>
                <?php if (!empty($primaryPhones) || !empty($altPhones) || !empty($officePhones) || $maskedEmail !== ''): ?>
                    <a href="<?= get_site_url('privacy-policy#section-masking') ?>" class="badge-tag badge-verified" style="text-decoration:none; font-size:0.72rem;" title="Personal mobile numbers and email IDs are masked against automated harvesting">
                        <i class="fas fa-shield-halved"></i> Privacy Masked
                    </a>
                <?php endif; ?>
            </div>

            <div style="display:grid; gap:10px; font-size:0.86rem;">
                <?php if ($primaryMaskedAll !== ''): ?>
                    <div style="display:flex; gap:10px; padding:9px 11px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-mobile-screen-button" style="color:var(--green-main); width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Mobile Number (Masked)</div>
                            <?= render_contact_reveal_trigger('mlc', $mlcId, 'mobile', $primaryMaskedAll, $memberName, [
                                'variant' => 'box',
                                'icon' => 'fas fa-mobile-screen-button',
                                'unlocked_today' => $isMlcUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($altMaskedAll !== ''): ?>
                    <div style="display:flex; gap:10px; padding:9px 11px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-phone" style="color:var(--navy-accent); width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Alternate Phone (Masked)</div>
                            <?= render_contact_reveal_trigger('mlc', $mlcId, 'alternate_mobile', $altMaskedAll, $memberName, [
                                'variant' => 'box',
                                'icon' => 'fas fa-phone',
                                'unlocked_today' => $isMlcUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($officeMaskedAll !== ''): ?>
                    <div style="display:flex; gap:10px; padding:9px 11px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-building" style="color:var(--navy-accent); width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Office Telephone (Masked)</div>
                            <?= render_contact_reveal_trigger('mlc', $mlcId, 'office_tel', $officeMaskedAll, $memberName, [
                                'variant' => 'box',
                                'icon' => 'fas fa-building',
                                'unlocked_today' => $isMlcUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($maskedEmail !== ''): ?>
                    <div style="display:flex; gap:10px; padding:9px 11px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-envelope" style="color:var(--saffron-main); width:16px; margin-top:4px;"></i>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:4px;">Official Email (Masked)</div>
                            <?= render_contact_reveal_trigger('mlc', $mlcId, 'email', $maskedEmail, $memberName, [
                                'variant' => 'box',
                                'icon' => 'fas fa-envelope',
                                'unlocked_today' => $isMlcUnlockedToday,
                            ]) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$viewer && ($primaryMaskedAll !== '' || $altMaskedAll !== '' || $officeMaskedAll !== '' || $maskedEmail !== '')): ?>
                    <div style="padding:10px 12px; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:var(--radius-sm); font-size:0.8rem; color:#1E3A8A; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                        <span><i class="fas fa-lock"></i> Sign in as a Citizen or Politician to view full mobile &amp; email.</span>
                        <span style="display:flex; gap:8px;">
                            <a href="<?= get_site_url('login?redirect=' . urlencode(get_current_request_redirect())) ?>" style="font-weight:800; color:var(--saffron-dark); text-decoration:underline;">Login</a>
                            <a href="<?= get_site_url('register?redirect=' . urlencode(get_current_request_redirect())) ?>" style="font-weight:800; color:var(--green-dark); text-decoration:underline;">Register</a>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($localAddress !== ''): ?>
                    <div style="display:flex; gap:10px; padding:9px 11px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-location-dot" style="color:var(--saffron-main); width:16px; margin-top:3px;"></i>
                        <div>
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Present / Local Address</div>
                            <div style="color:var(--text-secondary); line-height:1.5; margin-top:2px;"><?= nl2br(e($localAddress)) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($permanentAddress !== ''): ?>
                    <div style="display:flex; gap:10px; padding:9px 11px; background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm);">
                        <i class="fas fa-house" style="color:var(--green-main); width:16px; margin-top:3px;"></i>
                        <div>
                            <div style="font-size:0.73rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;">Permanent Address</div>
                            <div style="color:var(--text-secondary); line-height:1.5; margin-top:2px;"><?= nl2br(e($permanentAddress)) ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (empty($primaryPhones) && empty($altPhones) && empty($officePhones) && $maskedEmail === '' && $localAddress === '' && $permanentAddress === ''): ?>
                    <p style="color:var(--text-muted); margin:0;">No public contact address published by NeVA for this seat.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php if (!empty($stateMlcs)): ?>
        <!-- Fellow MLCs from the Same State -->
        <section style="margin-bottom:28px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-scroll"></i> Other MLCs in <?= e($stateDisplay) ?> Legislative Council
                    </h2>
                    <p style="font-size:0.83rem; color:var(--text-muted); margin-top:2px;">
                        Fellow Vidhan Parishad members representing <?= e($stateDisplay) ?>
                    </p>
                </div>
                <a href="<?= get_site_url('elections?mlc_state=' . urlencode($stateCode) . '#mlc-directory') ?>" class="section-link">
                    All <?= e($stateDisplay) ?> MLCs <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="cards-grid">
                <?php foreach ($stateMlcs as $peer):
                    $peerUrl = get_mlc_page_url((string)($peer['slug'] ?? ''), (int)$peer['id']);
                    $peerPartyMeta = resolve_party_card_meta($pdo, $peer['party_name'] ?? '');
                    $peerConst = $cleanVal($peer['constituency']) ?: $cleanVal($peer['constituency_type']);
                ?>
                    <div class="leader-card">
                        <div class="leader-card-cover" style="height:52px; background:<?= $stateHeaderAccent ?>;">
                            <div style="position:absolute; top:10px; right:10px; z-index:2;">
                                <span class="cat-pill bg-purple"><?= e($peer['constituency_type'] ?: 'MLC') ?></span>
                            </div>
                        </div>
                        <div class="leader-card-body">
                            <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main);">
                                <?php if (!empty($peer['photo_url'])): ?>
                                    <img src="<?= e($peer['photo_url']) ?>" alt="<?= e($peer['member_name']) ?>" loading="lazy"
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
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:6px;">
                                    <h3 class="leader-name" style="margin:0;">
                                        <a href="<?= e($peerUrl) ?>" style="color:inherit; text-decoration:none;"><?= e($peer['member_name']) ?></a>
                                    </h3>
                                    <span class="badge-tag badge-elected" style="flex-shrink:0;"><?= e($peer['membership_status'] ?: 'Sitting') ?></span>
                                </div>
                                <div class="leader-designation"><?= e($peer['role_designation'] ?: 'Member of Legislative Council') ?></div>
                            </div>
                            <div class="leader-panchayat">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                <span><strong><?= e($peerConst) ?></strong>, <?= e($peer['state']) ?></span>
                            </div>
                            <?php if ($peerPartyMeta): ?>
                                <div style="margin-top:8px;"><?= render_party_chip_html($peerPartyMeta) ?></div>
                            <?php endif; ?>
                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= e($peerUrl) ?>" class="btn-profile-view">
                                    View MLC Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($partyMlcs)): ?>
        <!-- Fellow Party MLCs -->
        <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:8px;">
                <h2 class="section-title">
                    <i class="fas fa-flag"></i> Fellow MLCs — <?= e($partyMeta['name'] ?? $partyRaw) ?>
                </h2>
                <?php if (!empty($partyMeta['url'])): ?>
                    <a href="<?= e($partyMeta['url']) ?>" class="section-link">
                        Open Party Page <i class="fas fa-arrow-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:12px;">
                <?php foreach ($partyMlcs as $pmlc):
                    $pmUrl = get_mlc_page_url((string)($pmlc['slug'] ?? ''), (int)$pmlc['id']);
                ?>
                    <a href="<?= e($pmUrl) ?>"
                       style="display:flex; gap:10px; align-items:center; padding:10px 12px; border:1px solid var(--border-light); border-radius:var(--radius-md); background:var(--white-surface); text-decoration:none;">
                        <div style="width:40px; height:40px; border-radius:50%; background:var(--navy-main); color:#FFFFFF; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:0.9rem; overflow:hidden;">
                            <?php if (!empty($pmlc['photo_url'])): ?>
                                <img src="<?= e($pmlc['photo_url']) ?>" alt="<?= e($pmlc['member_name']) ?>" style="width:100%; height:100%; object-fit:cover;"
                                     onerror="this.style.display='none';">
                            <?php else: ?>
                                <i class="fas fa-scroll"></i>
                            <?php endif; ?>
                        </div>
                        <div style="min-width:0; flex:1;">
                            <div style="font-size:0.86rem; font-weight:700; color:var(--navy-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= e($pmlc['member_name']) ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= e($pmlc['constituency']) ?>, <?= e($pmlc['state']) ?>
                            </div>
                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">
                                <?= e($pmlc['constituency_type'] ?: 'MLC') ?> • <?= e($pmlc['membership_status']) ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Bottom Source Attribution Strip -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--green-main); border-radius:var(--radius-md); padding:12px 18px; margin-top:24px; font-size:0.82rem; color:var(--text-muted); box-shadow:var(--shadow-sm);">
        <i class="fas fa-scroll" style="color:var(--green-main); margin-right:6px;"></i>
        Source: <a href="<?= get_site_url('source#source-neva') ?>" style="color:var(--navy-main); font-weight:700; text-decoration:underline;"><?= e($sourcePortalLabel) ?></a>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


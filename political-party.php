<?php
// political-party.php - Dedicated Profile Page for Each Political Party (/political-party/<slug>)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

$rawSlug = trim($_GET['slug'] ?? '');
$rawId = (int)($_GET['id'] ?? 0);

$party = null;
$baseSelect = "
    SELECT pp.*,
           COALESCE(s.state, pp.state) AS state_display
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
";

if ($rawSlug !== '') {
    $normInputSlug = strtolower(trim($rawSlug, '/ '));

    // 1. Exact slug lookup
    $stmt = $pdo->prepare($baseSelect . " WHERE pp.slug = ? LIMIT 1");
    $stmt->execute([$normInputSlug]);
    $party = $stmt->fetch() ?: null;

    // 2. Clean regenerated slug lookup (handles punctuation differences)
    if (!$party) {
        $regenSlug = generate_party_slug(str_replace('-', ' ', $normInputSlug));
        if ($regenSlug !== '' && $regenSlug !== $normInputSlug) {
            $stmt = $pdo->prepare($baseSelect . " WHERE pp.slug = ? LIMIT 1");
            $stmt->execute([$regenSlug]);
            $party = $stmt->fetch() ?: null;
        }
    }

    // 3. Vowel-length & spelling tolerant fallback (e.g. jan-suraj-party -> jan-suraaj-party)
    if (!$party) {
        $foldedInput = str_replace(['aa', 'ee', 'oo', 'ii', 'uu'], ['a', 'i', 'u', 'i', 'u'], $normInputSlug);
        $stmt = $pdo->prepare($baseSelect . "
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(pp.slug), 'aa', 'a'), 'ee', 'i'), 'oo', 'u'), 'ii', 'i'), 'uu', 'u') = ?
            ORDER BY CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
                     CASE UPPER(TRIM(pp.status)) WHEN 'ACTIVE' THEN 1 WHEN 'INACTIVE' THEN 2 WHEN 'DELISTED' THEN 3 ELSE 4 END ASC,
                     pp.id ASC
            LIMIT 1
        ");
        $stmt->execute([$foldedInput]);
        $party = $stmt->fetch() ?: null;
    }
} elseif ($rawId > 0) {
    $stmt = $pdo->prepare($baseSelect . " WHERE pp.id = ? LIMIT 1");
    $stmt->execute([$rawId]);
    $party = $stmt->fetch() ?: null;
}

if (!$party) {
    http_response_code(404);
    $pageTitle = "Political Party Not Found";
    $pageDescription = "Requested political party profile could not be located in the LocalNeta ECI directory.";
    require_once __DIR__ . '/includes/header.php';
    ?>
    <main class="main-wrapper">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:40px 24px; text-align:center; box-shadow:var(--shadow-sm); margin-top:16px;">
            <i class="fas fa-flag" style="font-size:2.8rem; color:var(--text-muted); margin-bottom:14px;"></i>
            <h1 style="font-size:1.5rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">Political Party Not Found</h1>
            <p style="font-size:0.92rem; color:var(--text-secondary); max-width:560px; margin:0 auto 20px;">
                We could not find a political party matching <code><?= e($rawSlug ?: (string)$rawId) ?></code> in our 3,445-record ECI directory. Search below to locate the party by English or Hindi name.
            </p>
            <form action="<?= get_site_url('parties') ?>" method="GET" style="display:flex; gap:8px; max-width:460px; margin:0 auto 20px;">
                <input type="text" name="q" value="<?= e(str_replace('-', ' ', $rawSlug)) ?>" placeholder="Search political party EN / हिंदी..." class="form-control">
                <button type="submit" class="btn-primary-tiranga" style="white-space:nowrap;">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
            <a href="<?= get_site_url('parties') ?>" class="btn-green-tiranga" style="padding:8px 18px; font-size:0.86rem;">
                <i class="fas fa-list"></i> Browse All 3,445 Political Parties
            </a>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Parse & clean party metadata
$partyId = (int)$party['id'];
$partySlug = trim((string)$party['slug']);
$partyName = clean_party_display_name($party['name']);
$partyNameHi = clean_party_display_text($party['name_hi'] ?? '');
$rawType = clean_party_display_text($party['type']) ?: 'Registered';
$statusVal = strtoupper(clean_party_display_text($party['status']) ?: 'ACTIVE');
$stateDisplay = clean_party_display_text($party['state_display']);
$addressText = clean_party_display_text($party['address']);
$addressHi = clean_party_display_text($party['address_hi'] ?? '');
$lastUpdate = format_eci_date($party['last_update']);
$rawWebsite = trim((string)($party['url'] ?? ''));
$validWebsite = $rawWebsite !== '' && preg_match('#^https?://#i', $rawWebsite);

$partyMobile = clean_party_display_text($party['mobile'] ?? '');
$partyMobileTel = preg_replace('/[^0-9+]/', '', $partyMobile) ?? '';
$partyWhatsapp = clean_party_display_text($party['whatsapp'] ?? '');
$partyWhatsappHref = build_whatsapp_action_url($partyWhatsapp);
$partyMisscall = clean_party_display_text($party['misscall'] ?? '');
$partyMisscallTel = preg_replace('/[^0-9+]/', '', $partyMisscall) ?? '';
$partyEmailRaw = strtolower(clean_party_display_text($party['email'] ?? ''));
$partyEmailValid = $partyEmailRaw !== '' && filter_var($partyEmailRaw, FILTER_VALIDATE_EMAIL) ? $partyEmailRaw : '';

$partyFacebook = normalize_party_social_url($party['facebook'] ?? '', 'facebook');
$partyInstagram = normalize_party_social_url($party['instagram'] ?? '', 'instagram');
$partyX = normalize_party_social_url($party['x'] ?? '', 'x');
$partyYoutube = normalize_party_social_url($party['youtube'] ?? '', 'youtube');
$partyLinkedin = normalize_party_social_url($party['linkedin'] ?? '', 'linkedin');

$hasPartyContacts = ($partyMobile !== '' || $partyWhatsapp !== '' || $partyMisscall !== '' || $partyEmailValid !== '');
$hasPartySocials = ($partyFacebook !== '' || $partyInstagram !== '' || $partyX !== '' || $partyYoutube !== '' || $partyLinkedin !== '');

$isNational = strcasecmp($rawType, 'National') === 0;
$isState = strcasecmp($rawType, 'State') === 0;
$typePillCls = $isNational ? 'bg-indigo' : ($isState ? 'bg-emerald' : 'bg-blue');
$typeLabel = $isNational ? 'National Political Party' : ($isState ? 'State Recognized Party' : 'Registered Unrecognized Political Party (RUPP)');
$statusCls = $statusVal === 'ACTIVE'
    ? 'status-success'
    : ($statusVal === 'DELISTED'
        ? 'status-danger'
        : ($statusVal === 'INACTIVE' ? 'status-warning' : 'status-muted'));

$symbolInfo = get_party_symbol_image($party['symbol'] ?? '');

// All ECI records sharing exact same party name (e.g., multi-state State Parties like RJD, JD(U), AITC, CPI, TDP, AIADMK, DMK, YSRCP)
$cleanNameForMatch = rtrim($partyName, '#* ');
$baseSlugForSiblings = generate_party_slug($cleanNameForMatch);
$sibStmt = $pdo->prepare("
    SELECT pp.id, pp.slug, pp.name, pp.type, pp.state, pp.state_code, pp.symbol, pp.status, pp.address, pp.address_hi, pp.last_update,
           COALESCE(s.state, pp.state) AS state_display
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
    WHERE pp.slug = ? OR pp.slug LIKE ?
    ORDER BY CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
             CASE UPPER(TRIM(pp.status)) WHEN 'ACTIVE' THEN 1 WHEN 'INACTIVE' THEN 2 WHEN 'DELISTED' THEN 3 ELSE 4 END ASC,
             state_display ASC,
             pp.id ASC
");
$sibStmt->execute([$baseSlugForSiblings, $baseSlugForSiblings . '-%']);
$stateRecognitions = array_values(array_filter(
    $sibStmt->fetchAll(),
    fn(array $row): bool => generate_party_slug((string)$row['name']) === $baseSlugForSiblings
));

// Query Parliament MPs (Lok Sabha LS-18 + Rajya Sabha) affiliated with this party
$mpAliasMap = [
    'bharatiya-janata-party' => ['Bharatiya Janata Party', 'BJP'],
    'indian-national-congress' => ['Indian National Congress', 'INC'],
    'aam-aadmi-party' => ['Aam Aadmi Party', 'AAP'],
    'bahujan-samaj-party' => ['Bahujan Samaj Party', 'BSP'],
    'communist-party-of-india-marxist' => ['Communist Party of India (Marxist)', 'CPI(M)'],
    'national-peoples-party' => ["National People's Party", 'National People’s Party', 'NPP'],
    'all-india-trinamool-congress' => ['All India Trinamool Congress', 'AITC'],
    'dravida-munnetra-kazhagam' => ['Dravida Munnetra Kazhagam', 'DMK'],
    'all-india-anna-dravida-munnetra-kazhagam' => ['All India Anna Dravida Munnetra Kazhagam', 'AIADMK'],
    'telugu-desam' => ['Telugu Desam', 'Telugu Desam Party', 'TDP'],
    'samajwadi-party' => ['Samajwadi Party', 'SP'],
    'rashtriya-janata-dal' => ['Rashtriya Janata Dal', 'RJD'],
    'janata-dal-united' => ['Janata Dal (United)', 'JD(U)'],
    'janata-dal-secular' => ['Janata Dal (Secular)', 'JD(S)'],
    'biju-janata-dal' => ['Biju Janata Dal', 'BJD'],
    'yuvajana-sramika-rythu-congress-party' => ['Yuvajana Sramika Rythu Congress Party', 'YSRCP'],
    'bharat-rashtra-samithi' => ['Bharat Rashtra Samithi', 'BRS'],
    'jharkhand-mukti-morcha' => ['Jharkhand Mukti Morcha', 'JMM'],
    'nationalist-congress-party' => ['Nationalist Congress Party', 'NCP'],
    'nationalist-congress-party-sharadchandra-pawar' => ['Nationalist Congress Party - Sharadchandra Pawar', 'Nationalist Congress Party – Sharadchandra Pawar', 'NCP-SP', 'NCP-SCP'],
    'shiv-sena-uddhav-balasaheb-thackrey' => ['Shiv Sena (Uddhav Balasaheb Thackrey)', 'SS(UBT)', 'SS-UBT'],
    'shiv-sena' => ['Shiv Sena', 'SS'],
    'jammu-and-kashmir-national-conference' => ['Jammu & Kashmir National Conference', 'Jammu and Kashmir National Conference', 'J&KNC'],
    'communist-party-of-india' => ['Communist Party of India', 'CPI'],
    'communist-party-of-india-marxist-leninist-liberation' => ['Communist Party of India (Marxist-Leninist) Liberation', 'Communist Party of India (Marxist-Leninist) (Liberation)', 'Communist Party of India - ML (Lib.)', 'CPI(ML)L'],
    'lok-janshakthi-party-ram-vilas' => ['Lok Jan Shakti Party (Ram Vilas)', 'Lok Janshakti Party (Ram Vilas)', 'Lok Janshakti Party - RV', 'LJP(RV)'],
    'lok-jan-shakti-party-ram-vilas' => ['Lok Jan Shakti Party (Ram Vilas)', 'Lok Janshakti Party (Ram Vilas)', 'Lok Janshakti Party - RV', 'LJP(RV)'],
    'lok-janshakti-party-ram-vilas' => ['Lok Janshakti Party (Ram Vilas)', 'Lok Jan Shakti Party (Ram Vilas)', 'Lok Janshakti Party - RV', 'LJP(RV)'],
    'all-india-majlis-e-ittehadul-muslimeen' => ['All India Majlis-E- Ittehadul Muslimeen', 'All India Majlis-E-Ittehadul Muslimeen', 'All India Majlis-e-Ittehadul Muslimeen', 'AIMIM'],
    'shiromani-akali-dal' => ['Shiromani Akali Dal', 'SAD'],
    'rashtriya-lok-dal' => ['Rashtriya Lok Dal', 'RLD'],
    'rashtriya-lok-morcha' => ['Rashtriya Lok Morcha', 'RLM'],
    'apna-dal-soneylal' => ['Apna Dal (Soneylal)', 'Apna Dal', 'Apna Dal (S) Party', 'Apna Dal (S)'],
    'hindustani-awam-morcha-secular' => ['Hindustani Awam Morcha (Secular)', 'Hindustani Awamee Morcha (Secular)', 'HAM(S)'],
    'nirbal-indian-shoshit-hamara-aam-dal' => ['Nirbal Indian Shoshit Hamara Aam Dal', 'NISHAD Party'],
    'suheldev-bharatiya-samaj-party' => ['Suheldev Bharatiya Samaj Party', 'SBSP'],
    'jansatta-dal-loktantrik' => ['Jansatta Dal Loktantrik'],
    'indian-union-muslim-league' => ['Indian Union Muslim League', 'IUML'],
    'asom-gana-parishad' => ['Asom Gana Parishad', 'AGP'],
    'kerala-congress-m' => ['Kerala Congress (M)', 'KC(M)'],
    'janasena-party' => ['Janasena Party', 'JSP'],
    'pattali-makkal-katchi' => ['Pattali Makkal Katchi', 'PMK'],
    'desiya-murpokku-dravida-kazhagam' => ['Desiya Murpokku Dravida Kazhagam', 'DMDK'],
    'zoram-peoples-movement' => ['Zoram Peoples Movement', 'ZPM'],
    'republican-party-of-india-athawale' => ['Republican Party of India (Athawale)', 'RPI (ATWL)'],
];

$mlcExtraAliases = [
    'janata-dal-united' => ['Janta Dal (United)'],
    'rashtriya-janata-dal' => ['Rashtriya Janta Dal'],
];

$baseSlugForMp = generate_party_slug($cleanNameForMatch);
$mpMatchNames = array_values(array_unique(array_filter(array_merge(
    [$partyName, $cleanNameForMatch],
    $mpAliasMap[$partySlug] ?? [],
    $mpAliasMap[$baseSlugForMp] ?? []
))));

$partyMps = [];
$partyMpTotal = 0;
if (!empty($mpMatchNames)) {
    $mpPlaceholders = implode(', ', array_fill(0, count($mpMatchNames), '?'));
    $mpCntStmt = $pdo->prepare("SELECT COUNT(*) FROM mp WHERE party_name IN ({$mpPlaceholders})");
    $mpCntStmt->execute($mpMatchNames);
    $partyMpTotal = (int)$mpCntStmt->fetchColumn();

    if ($partyMpTotal > 0) {
        $mpStmt = $pdo->prepare("
            SELECT * FROM mp
            WHERE party_name IN ({$mpPlaceholders})
            ORDER BY type = 'ls' DESC, membership_status = 'Sitting' DESC, state ASC, constituency ASC, member_name ASC
            LIMIT 24
        ");
        $mpStmt->execute($mpMatchNames);
        $partyMps = $mpStmt->fetchAll();
    }
}

// Query Members of Legislative Council (`mlc` table — UP, Bihar & AP) affiliated with this party
$mlcMatchNames = array_values(array_unique(array_filter(array_merge(
    $mpMatchNames,
    $mlcExtraAliases[$partySlug] ?? [],
    $mlcExtraAliases[$baseSlugForMp] ?? []
))));
$partyMlcs = [];
$partyMlcTotal = 0;
if (!empty($mlcMatchNames) && $pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()) {
    $mlcPlaceholders = implode(', ', array_fill(0, count($mlcMatchNames), '?'));
    $mlcCntStmt = $pdo->prepare("SELECT COUNT(*) FROM mlc WHERE status = 'ACTIVE' AND party_name IN ({$mlcPlaceholders})");
    $mlcCntStmt->execute($mlcMatchNames);
    $partyMlcTotal = (int)$mlcCntStmt->fetchColumn();

    if ($partyMlcTotal > 0) {
        $mlcStmt = $pdo->prepare("
            SELECT * FROM mlc
            WHERE status = 'ACTIVE' AND party_name IN ({$mlcPlaceholders})
            ORDER BY (state_code = ?) DESC,
                     (role_designation IS NOT NULL AND role_designation <> '') DESC,
                     FIELD(state_code, '10', '9', '28'),
                     seat_no IS NULL ASC,
                     seat_no ASC,
                     member_name ASC
            LIMIT 24
        ");
        $mlcStmt->execute(array_merge($mlcMatchNames, [(string)$party['state_code']]));
        $partyMlcs = $mlcStmt->fetchAll();
    }
}

// Query local representatives from `leaders` table aligned with this party
$localLeaders = [];
if ($partyName !== '') {
    $ldrStmt = $pdo->prepare("
        SELECT l.*,
               p.name_en AS panchayat_name,
               sdb.block AS block_name,
               COALESCE(sdb.district, dist.district) AS district_name
        FROM leaders l
        LEFT JOIN panchayats p ON l.panchayat_id = p.id
        LEFT JOIN op_sdb sdb ON l.block_code = sdb.block_code
        LEFT JOIN (SELECT DISTINCT district_code, district FROM op_sdb) dist ON l.district_code = dist.district_code
        WHERE LOWER(l.party_name_hi) LIKE ?
        ORDER BY l.views_count DESC
        LIMIT 12
    ");
    $ldrStmt->execute(['%' . strtolower($cleanNameForMatch) . '%']);
    $localLeaders = $ldrStmt->fetchAll();
}

// Query related active parties in the same state (or other National parties if this is National)
$relStmt = $pdo->prepare("
    SELECT pp.id, pp.slug, pp.name, pp.name_hi, pp.type, pp.symbol, pp.state, pp.state_code,
           COALESCE(s.state, pp.state) AS state_display
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
    WHERE pp.id <> ?
      AND UPPER(TRIM(pp.status)) = 'ACTIVE'
      AND " . ($isNational ? "TRIM(pp.type) = 'National'" : "(pp.state_code = ? AND TRIM(pp.type) IN ('State', 'Registered'))") . "
    ORDER BY CASE TRIM(pp.type) WHEN 'National' THEN 1 WHEN 'State' THEN 2 ELSE 3 END ASC,
             (pp.symbol IS NOT NULL AND TRIM(pp.symbol) <> '') DESC,
             pp.name ASC
    LIMIT 8
");
if ($isNational) {
    $relStmt->execute([$partyId]);
} else {
    $relStmt->execute([$partyId, $party['state_code']]);
}
$relatedParties = $relStmt->fetchAll();

// Determine notification source citation text
$sourceCitation = 'ECI Notification dated 27-03-2024 & S.O. 57(E) dated 10-10-2025 (RUPPs)';
if ($isNational) {
    $sourceCitation = 'ECI National Parties Notification dated 23-03-2024 / 26-03-2024';
} elseif ($isState) {
    $sourceCitation = 'ECI State Parties Reserved Symbols Notification dated 23-03-2024';
}

$pageTitle = $partyName . ($partyNameHi !== '' ? ' (' . $partyNameHi . ')' : '') . ' — Political Party Profile';
$pageDescription = "{$partyName} ({$typeLabel}) ECI profile, election symbol" . ($symbolInfo ? " ({$symbolInfo['label']})" : '') . ", headquarters address in English & Hindi, recognition status ({$statusVal}), and elected representatives on LocalNeta.";
$canonicalUrl = get_party_page_url($partySlug, $partyId);

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Breadcrumb -->
    <nav style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:0.82rem; color:var(--text-muted); margin-bottom:16px;">
        <a href="<?= get_site_url('index') ?>" style="color:var(--navy-main); font-weight:600;">Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <a href="<?= get_site_url('parties') ?>" style="color:var(--navy-main); font-weight:600;">Political Parties</a>
        <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php if (!$isNational && $stateDisplay !== ''): ?>
            <a href="<?= get_site_url('parties?state_code=' . urlencode((string)$party['state_code'])) ?>" style="color:var(--navy-main); font-weight:600;"><?= e($stateDisplay) ?></a>
            <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php endif; ?>
        <span style="color:var(--saffron-main); font-weight:700;"><?= e($partyName) ?></span>
    </nav>

    <!-- Party Hero Profile Card -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); overflow:hidden; box-shadow:var(--shadow-sm); margin-bottom:24px;">
        <div style="height:10px; background:linear-gradient(90deg, #FF671F 0%, #FFFFFF 50%, #046A38 100%);"></div>

        <div style="padding:24px; display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start; justify-content:space-between;">
            <!-- Symbol Box (ECI Official Symbol Graphic from assets/images/symbols/) -->
            <div style="width:168px; flex-shrink:0; margin:0 auto; text-align:center; background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 12px;">
                <?php if ($symbolInfo && !empty($symbolInfo['url'])): ?>
                    <div style="width:116px; height:116px; margin:0 auto 10px; background:#FFFFFF; border:1px solid var(--border-light); border-radius:var(--radius-md); padding:10px; display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-sm);">
                        <img src="<?= e($symbolInfo['url']) ?>" alt="ECI Election Symbol — <?= e($symbolInfo['label']) ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                    </div>
                    <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--green-dark);">
                        <i class="fas fa-certificate"></i> ECI Issued Symbol
                    </div>
                    <div style="font-size:0.9rem; font-weight:800; color:var(--navy-main); margin-top:3px;">
                        <?= e($symbolInfo['label']) ?>
                    </div>
                <?php elseif ($symbolInfo): ?>
                    <div style="width:116px; height:116px; margin:0 auto 10px; background:#FFFBEB; border:1px solid #FDE68A; border-radius:var(--radius-md); padding:10px; display:flex; align-items:center; justify-content:center; color:#92400E; font-size:2.4rem;">
                        <i class="fas fa-award"></i>
                    </div>
                    <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:#92400E;">
                        ECI Reserved Symbol
                    </div>
                    <div style="font-size:0.88rem; font-weight:800; color:var(--navy-main); margin-top:3px;">
                        <?= e($symbolInfo['label']) ?>
                    </div>
                <?php else: ?>
                    <div style="width:116px; height:116px; margin:0 auto 10px; background:#F8FAFC; border:1px dashed var(--border-color); border-radius:var(--radius-md); display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--text-muted); gap:6px;">
                        <i class="fas fa-flag" style="font-size:2rem; color:var(--saffron-main); opacity:0.85;"></i>
                        <span style="font-size:0.7rem; font-weight:700;">No Reserved Symbol</span>
                    </div>
                    <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted);">
                        Free / Unreserved Symbol
                    </div>
                    <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:3px;">
                        No reserved symbol issued by ECI (RUPP)
                    </div>
                <?php endif; ?>
            </div>

            <!-- Party Identity & Metadata -->
            <div style="flex:1; min-width:260px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
                    <span class="cat-pill <?= $typePillCls ?>"><?= e($rawType) ?> Party</span>
                    <span class="status-chip <?= $statusCls ?>"><span class="status-dot"></span> <?= e($statusVal) ?></span>
                    <span class="badge-tag badge-verified">
                        <i class="fas fa-map-pin"></i> <?= $isNational ? 'All India' : e($stateDisplay) ?>
                    </span>
                    <?php if ($lastUpdate !== ''): ?>
                        <span style="font-size:0.76rem; color:var(--text-muted); font-weight:600;">
                            <i class="fas fa-calendar-check"></i> Updated: <?= e($lastUpdate) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <h1 style="font-size:1.65rem; font-weight:800; color:var(--navy-main); line-height:1.25; margin:0;">
                    <?= e($partyName) ?>
                </h1>

                <?php if ($partyNameHi !== ''): ?>
                    <div style="font-family:var(--font-hi); font-size:1.2rem; font-weight:700; color:var(--green-dark); margin-top:4px; line-height:1.35;" lang="hi">
                        <?= e($partyNameHi) ?>
                    </div>
                <?php endif; ?>

                <p style="font-size:0.9rem; color:var(--text-secondary); margin-top:8px;">
                    <?= e($typeLabel) ?> registered with the Election Commission of India<?php if (!$isNational && $stateDisplay !== ''): ?> in <strong><?= e($stateDisplay) ?></strong><?php endif; ?>.
                </p>

                <!-- Bilingual Headquarters Box -->
                <div style="margin-top:14px; background:var(--white-surface); border:1px solid var(--border-color); border-left:4px solid var(--green-main); border-radius:var(--radius-md); padding:14px 16px;">
                    <div style="font-size:0.76rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; color:var(--navy-main); margin-bottom:6px;">
                        <i class="fas fa-building" style="color:var(--saffron-main);"></i> Official Headquarters Address (EN / हिंदी)
                    </div>
                    <div style="font-size:0.9rem; color:var(--text-primary); line-height:1.5;">
                        <?= e($addressText ?: 'Address not published in ECI notification') ?>
                    </div>
                    <?php if ($addressHi !== ''): ?>
                        <div style="font-family:var(--font-hi); font-size:0.88rem; color:var(--text-secondary); margin-top:8px; padding-top:8px; border-top:1px dashed var(--border-color); line-height:1.5;" lang="hi">
                            <?= e($addressHi) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($symbolInfo && $symbolInfo['raw'] !== $symbolInfo['label']): ?>
                    <div style="margin-top:12px; padding:10px 14px; border-radius:var(--radius-md); background:#FFFBEB; border:1px solid #FDE68A; font-size:0.82rem; color:#92400E;">
                        <i class="fas fa-info-circle"></i> <strong>ECI Symbol Directive:</strong> <?= e($symbolInfo['raw']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($hasPartyContacts || $hasPartySocials): ?>
                    <!-- Official Helpline, Contact & Social Media Channels -->
                    <div style="margin-top:14px; background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--navy-main); border-radius:var(--radius-md); padding:14px 16px;">
                        <div style="font-size:0.76rem; font-weight:800; text-transform:uppercase; letter-spacing:0.04em; color:var(--navy-main); margin-bottom:10px;">
                            <i class="fas fa-address-book" style="color:var(--saffron-main);"></i> Official Helpline, Contact &amp; Social Channels
                        </div>

                        <?php if ($hasPartyContacts): ?>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:<?= $hasPartySocials ? '10px' : '0' ?>;">
                                <?php if ($partyMobile !== ''): ?>
                                    <?php if ($partyMobileTel !== ''): ?>
                                        <a href="<?= e('tel:' . $partyMobileTel) ?>"
                                           style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:var(--white-surface); border:1px solid var(--border-color); color:var(--navy-main); font-size:0.83rem; font-weight:700; text-decoration:none;">
                                            <i class="fas fa-phone" style="color:var(--navy-accent);"></i>
                                            <span>Helpline: <?= e($partyMobile) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:var(--white-surface); border:1px solid var(--border-color); color:var(--navy-main); font-size:0.83rem; font-weight:700;">
                                            <i class="fas fa-phone" style="color:var(--navy-accent);"></i>
                                            <span>Helpline: <?= e($partyMobile) ?></span>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($partyMisscall !== ''): ?>
                                    <?php if ($partyMisscallTel !== ''): ?>
                                        <a href="<?= e('tel:' . $partyMisscallTel) ?>"
                                           style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:#FFF7ED; border:1px solid #FED7AA; color:#9A3412; font-size:0.83rem; font-weight:700; text-decoration:none;">
                                            <i class="fas fa-phone-volume" style="color:var(--saffron-main);"></i>
                                            <span>Missed Call: <?= e($partyMisscall) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:#FFF7ED; border:1px solid #FED7AA; color:#9A3412; font-size:0.83rem; font-weight:700;">
                                            <i class="fas fa-phone-volume" style="color:var(--saffron-main);"></i>
                                            <span>Missed Call: <?= e($partyMisscall) ?></span>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($partyWhatsapp !== ''): ?>
                                    <?php if ($partyWhatsappHref !== ''): ?>
                                        <a href="<?= e($partyWhatsappHref) ?>" target="_blank" rel="noopener"
                                           style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; font-size:0.83rem; font-weight:700; text-decoration:none;">
                                            <i class="fab fa-whatsapp" style="font-size:0.95rem; color:#16A34A;"></i>
                                            <span>WhatsApp: <?= e(preg_match('#^https?://#i', $partyWhatsapp) ? 'Official Channel' : $partyWhatsapp) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:#ECFDF5; border:1px solid #A7F3D0; color:#065F46; font-size:0.83rem; font-weight:700;">
                                            <i class="fab fa-whatsapp" style="font-size:0.95rem; color:#16A34A;"></i>
                                            <span>WhatsApp: <?= e($partyWhatsapp) ?></span>
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($partyEmailValid !== ''): ?>
                                    <a href="mailto:<?= e($partyEmailValid) ?>"
                                       style="display:inline-flex; align-items:center; gap:7px; padding:6px 12px; border-radius:var(--radius-md); background:var(--white-surface); border:1px solid var(--border-color); color:var(--navy-main); font-size:0.83rem; font-weight:700; text-decoration:none;">
                                        <i class="fas fa-envelope" style="color:#DC2626;"></i>
                                        <span><?= e($partyEmailValid) ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasPartySocials): ?>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <?php foreach ([
                                    ['url' => $partyFacebook, 'icon' => 'fab fa-facebook-f', 'label' => 'Facebook', 'color' => '#1877F2', 'bg' => '#EFF6FF', 'border' => '#BFDBFE'],
                                    ['url' => $partyInstagram, 'icon' => 'fab fa-instagram', 'label' => 'Instagram', 'color' => '#E1306C', 'bg' => '#FDF2F8', 'border' => '#FBCFE8'],
                                    ['url' => $partyX, 'icon' => 'fab fa-x-twitter', 'label' => 'X (Twitter)', 'color' => '#0F172A', 'bg' => '#F8FAFC', 'border' => '#CBD5E1'],
                                    ['url' => $partyYoutube, 'icon' => 'fab fa-youtube', 'label' => 'YouTube', 'color' => '#DC2626', 'bg' => '#FEF2F2', 'border' => '#FECACA'],
                                    ['url' => $partyLinkedin, 'icon' => 'fab fa-linkedin-in', 'label' => 'LinkedIn', 'color' => '#0A66C2', 'bg' => '#EFF6FF', 'border' => '#BFDBFE'],
                                ] as $soc): if ($soc['url'] === '') continue; ?>
                                    <a href="<?= e($soc['url']) ?>" target="_blank" rel="noopener"
                                       style="display:inline-flex; align-items:center; gap:6px; padding:5px 11px; border-radius:var(--radius-full); background:<?= e($soc['bg']) ?>; border:1px solid <?= e($soc['border']) ?>; color:<?= e($soc['color']) ?>; font-size:0.8rem; font-weight:700; text-decoration:none;">
                                        <i class="<?= e($soc['icon']) ?>"></i> <?= e($soc['label']) ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;">
                    <?php if ($validWebsite): ?>
                        <a href="<?= e($rawWebsite) ?>" target="_blank" rel="noopener" class="btn-green-tiranga" style="padding:8px 16px; font-size:0.86rem;">
                            <i class="fas fa-globe"></i> Official Website <i class="fas fa-external-link-alt" style="font-size:0.72rem;"></i>
                        </a>
                    <?php endif; ?>
                    <a href="<?= get_site_url('parties?' . http_build_query(array_filter(['type' => $rawType, 'state_code' => $isNational ? '' : $party['state_code']], fn($v) => $v !== ''))) ?>" class="btn-profile-view" style="flex:unset; padding:8px 16px; font-size:0.86rem;">
                        <i class="fas fa-arrow-left"></i> Back to <?= e($rawType) ?> Parties
                    </a>
                    <?php if ($partyMpTotal > 0): ?>
                        <a href="<?= get_site_url('elections?' . http_build_query(['mp_q' => $partyName]) . '#mp-directory') ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.86rem;">
                            <i class="fas fa-landmark"></i> View <?= number_format($partyMpTotal) ?> MPs in Directory
                        </a>
                    <?php endif; ?>
                    <?php if ($partyMlcTotal > 0): ?>
                        <a href="<?= get_site_url('elections?' . http_build_query(['mlc_party' => $partyMlcs[0]['party_name']]) . '#mlc-directory') ?>" class="btn-profile-view" style="flex:unset; padding:8px 16px; font-size:0.86rem;">
                            <i class="fas fa-scroll" style="color:var(--green-main);"></i> View <?= number_format($partyMlcTotal) ?> MLCs in Directory
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php if (count($stateRecognitions) > 1): ?>
        <!-- Multi-State ECI Recognitions (for parties recognized in multiple states) -->
        <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:8px;">
                <h2 class="section-title"><i class="fas fa-map"></i> ECI State / UT Recognitions (<?= count($stateRecognitions) ?> Entries)</h2>
                <span class="badge-tag badge-verified">State-wise Election Commission Records</span>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface);">
                            <th style="padding:10px;">State / Scope</th>
                            <th style="padding:10px;">Type</th>
                            <th style="padding:10px;">Reserved Symbol</th>
                            <th style="padding:10px;">Status</th>
                            <th style="padding:10px;">State HQ Address</th>
                            <th style="padding:10px;">Page</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stateRecognitions as $sib):
                            $sibSym = get_party_symbol_image($sib['symbol'] ?? '');
                            $isCurrent = ((int)$sib['id'] === $partyId);
                        ?>
                            <tr style="border-bottom:1px solid var(--border-light); <?= $isCurrent ? 'background:#FFFBF5;' : '' ?>">
                                <td style="padding:10px; font-weight:700; color:var(--navy-main);"><?= e(clean_party_display_text($sib['state_display'])) ?></td>
                                <td style="padding:10px;"><span class="cat-pill bg-emerald"><?= e(clean_party_display_text($sib['type'])) ?></span></td>
                                <td style="padding:10px;">
                                    <?php if ($sibSym && !empty($sibSym['url'])): ?>
                                        <span style="display:inline-flex; align-items:center; gap:6px;">
                                            <img src="<?= e($sibSym['url']) ?>" alt="<?= e($sibSym['label']) ?>" style="width:24px; height:24px; object-fit:contain; border:1px solid var(--border-light); border-radius:4px; background:#fff; padding:2px;">
                                            <span><?= e($sibSym['label']) ?></span>
                                        </span>
                                    <?php elseif ($sibSym): ?>
                                        <span><?= e($sibSym['label']) ?></span>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">Unreserved</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px;"><span class="badge-tag badge-elected"><?= e(strtoupper(clean_party_display_text($sib['status']) ?: 'ACTIVE')) ?></span></td>
                                <td style="padding:10px; font-size:0.8rem; color:var(--text-secondary);"><?= e(clean_party_display_text($sib['address'])) ?></td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <?php if ($isCurrent): ?>
                                        <span style="font-size:0.78rem; font-weight:800; color:var(--saffron-main);">Viewing</span>
                                    <?php else: ?>
                                        <a href="<?= get_party_page_url((string)$sib['slug'], (int)$sib['id']) ?>" style="font-size:0.8rem; font-weight:700; color:var(--green-main);">
                                            View <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($partyMps)): ?>
        <!-- Parliament MPs from this Party (`mp` table: Lok Sabha LS-18 & Rajya Sabha) -->
        <section style="margin-bottom:28px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title"><i class="fas fa-landmark"></i> Members of Parliament — <?= e($partyName) ?></h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        Showing <strong><?= count($partyMps) ?></strong> of <strong><?= number_format($partyMpTotal) ?></strong> Lok Sabha &amp; Rajya Sabha MPs
                    </p>
                </div>
                <a href="<?= get_site_url('elections?' . http_build_query(['mp_q' => $partyName]) . '#mp-directory') ?>" class="section-link">
                    View All <?= number_format($partyMpTotal) ?> MPs <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="cards-grid">
                <?php foreach ($partyMps as $mp):
                    $isRs = $mp['type'] === 'rs';
                    $houseBadge = !$isRs ? strtoupper($mp['election'] ?: 'LS-18') : ($mp['election'] ?: 'RS Nominated');
                    $mpUrl = get_mp_page_url((string)($mp['slug'] ?? ''), (int)$mp['id']);
                ?>
                    <div class="leader-card">
                        <div class="leader-card-cover" style="height:56px; background:<?= $isRs ? 'linear-gradient(135deg, #046A38, #0A192F)' : 'var(--navy-gradient)' ?>;"></div>
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
                                    <span class="cat-pill <?= $isRs ? 'bg-emerald' : 'bg-rose' ?>" style="flex-shrink:0;"><?= e($houseBadge) ?></span>
                                </div>
                                <div class="leader-designation"><?= $isRs ? 'MP (Rajya Sabha)' : 'MP (Lok Sabha)' ?></div>
                            </div>
                            <div class="leader-panchayat">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                <?= e($mp['constituency']) ?><?= !$isRs ? ', ' . e($mp['state']) : '' ?>
                            </div>
                            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;">
                                <i class="fas fa-flag" style="color:var(--green-main);"></i> <?= e($mp['party_name']) ?> • <?= e($mp['membership_status']) ?>
                            </div>
                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= e($mpUrl) ?>" class="btn-profile-view">
                                    View MP Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($partyMlcs)): ?>
        <!-- Legislative Council MLCs from this Party (`mlc` table: UP, Bihar & AP Vidhan Parishad) -->
        <section style="margin-bottom:28px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title"><i class="fas fa-scroll"></i> Members of Legislative Council (MLCs) — <?= e($partyName) ?></h2>
                    <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                        Showing <strong><?= count($partyMlcs) ?></strong> of <strong><?= number_format($partyMlcTotal) ?></strong> Vidhan Parishad MLCs across Uttar Pradesh, Bihar &amp; Andhra Pradesh
                    </p>
                </div>
                <a href="<?= get_site_url('elections?' . http_build_query(['mlc_party' => $partyMlcs[0]['party_name']]) . '#mlc-directory') ?>" class="section-link">
                    View All <?= number_format($partyMlcTotal) ?> MLCs <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="cards-grid">
                <?php foreach ($partyMlcs as $mlcRow):
                    $mlcUrl = get_mlc_page_url((string)($mlcRow['slug'] ?? ''), (int)$mlcRow['id']);
                    $mlcPrefixBadge = strtoupper(get_mlc_state_slug_prefix((string)$mlcRow['state_code'], (string)$mlcRow['state'])) . ' MLC';
                    $mlcSeatLabel = trim((string)($mlcRow['constituency'] ?: $mlcRow['constituency_type']));
                ?>
                    <div class="leader-card">
                        <div class="leader-card-cover" style="height:56px; background:linear-gradient(135deg, #046A38, #0A192F);"></div>
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
                                    <span class="cat-pill bg-purple" style="flex-shrink:0;"><?= e($mlcPrefixBadge) ?></span>
                                </div>
                                <div class="leader-designation"><?= e($mlcRow['role_designation'] ?: 'Member of Legislative Council') ?></div>
                            </div>
                            <div class="leader-panchayat">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                <?= e($mlcSeatLabel) ?>, <?= e($mlcRow['state']) ?>
                            </div>
                            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;">
                                <i class="fas fa-building-columns" style="color:var(--green-main);"></i> <?= e($mlcRow['constituency_type']) ?> • <?= e($mlcRow['membership_status'] ?: 'Sitting') ?>
                            </div>
                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= e($mlcUrl) ?>" class="btn-profile-view">
                                    View MLC Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($localLeaders)): ?>
        <!-- Local Elected Leaders Associated With This Party -->
        <section style="margin-bottom:28px;">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-user-tie"></i> Local Representatives on LocalNeta</h2>
                <a href="<?= get_site_url('leaders') ?>" class="section-link">Browse All Leaders <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="cards-grid">
                <?php foreach ($localLeaders as $ldr): ?>
                    <div class="leader-card">
                        <div class="leader-card-cover">
                            <div style="position:absolute; top:10px; right:10px; z-index:2;">
                                <?= get_category_badge($ldr['category'], $pdo) ?>
                            </div>
                        </div>
                        <div class="leader-card-body">
                            <div class="leader-avatar-wrapper">
                                <img src="<?= e($ldr['avatar_url']) ?>" alt="<?= e($ldr['full_name_en']) ?>">
                            </div>
                            <div class="leader-header-info">
                                <h3 class="leader-name"><?= e($ldr['full_name_en']) ?></h3>
                                <div class="leader-designation"><?= e($ldr['designation_hi']) ?></div>
                            </div>
                            <div class="leader-panchayat">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                <?= e($ldr['constituency_name_hi'] ?: ($ldr['district_name'] ?? 'Saran')) ?>
                            </div>
                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= get_site_url('profile?id=' . (int)$ldr['id']) ?>" class="btn-profile-view">
                                    View Leader Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($relatedParties)): ?>
        <!-- Peer Active Parties in Same Scope / State -->
        <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:8px;">
                <h2 class="section-title">
                    <i class="fas fa-flag"></i>
                    <?= $isNational ? 'Other National Political Parties' : 'Other Active Political Parties in ' . e($stateDisplay) ?>
                </h2>
                <a href="<?= get_site_url('parties?' . http_build_query(array_filter(['type' => $isNational ? 'National' : '', 'state_code' => $isNational ? '' : $party['state_code']], fn($v) => $v !== ''))) ?>" class="section-link">
                    Explore All <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:12px;">
                <?php foreach ($relatedParties as $rel):
                    $relName = clean_party_display_name($rel['name']);
                    $relNameHi = clean_party_display_text($rel['name_hi'] ?? '');
                    $relSym = get_party_symbol_image($rel['symbol'] ?? '');
                ?>
                    <a href="<?= get_party_page_url((string)$rel['slug'], (int)$rel['id']) ?>"
                       style="display:flex; gap:10px; align-items:center; padding:10px 12px; border:1px solid var(--border-light); border-radius:var(--radius-md); background:var(--white-surface); text-decoration:none;">
                        <div style="width:42px; height:42px; border-radius:8px; border:1px solid var(--border-color); background:#FFFFFF; flex-shrink:0; display:flex; align-items:center; justify-content:center; padding:4px;">
                            <?php if ($relSym && !empty($relSym['url'])): ?>
                                <img src="<?= e($relSym['url']) ?>" alt="<?= e($relSym['label']) ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                            <?php else: ?>
                                <i class="fas fa-flag" style="color:var(--saffron-main); font-size:0.95rem;"></i>
                            <?php endif; ?>
                        </div>
                        <div style="min-width:0; flex:1;">
                            <div style="font-size:0.86rem; font-weight:700; color:var(--navy-main); line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= e($relName) ?></div>
                            <?php if ($relNameHi !== ''): ?>
                                <div style="font-family:var(--font-hi); font-size:0.78rem; color:var(--green-dark); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" lang="hi"><?= e($relNameHi) ?></div>
                            <?php endif; ?>
                            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">
                                <?= e(clean_party_display_text($rel['type'])) ?><?= $relSym ? ' • ' . e($relSym['label']) : '' ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Bottom Source Citation Strip -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--green-main); border-radius:var(--radius-md); padding:12px 18px; margin-top:24px; font-size:0.82rem; color:var(--text-muted); box-shadow:var(--shadow-sm);">
        <i class="fas fa-file-contract" style="color:var(--green-main); margin-right:6px;"></i>
        Source: <a href="<?= get_site_url('source#source-eci') ?>" style="color:var(--navy-main); font-weight:700; text-decoration:underline;"><?= e($sourceCitation) ?></a>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

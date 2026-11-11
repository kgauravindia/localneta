<?php
// mp.php - Dedicated Profile Page for Each Member of Parliament (/mp/<slug>)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

$rawSlug = trim($_GET['slug'] ?? '');
$rawId = (int)($_GET['id'] ?? 0);

$mp = null;
$baseSelect = "
    SELECT m.*,
           COALESCE(s.state, m.state) AS state_display
    FROM mp m
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON m.state_code = s.state_code
";

if ($rawSlug !== '') {
    $normInputSlug = strtolower(trim($rawSlug, '/ '));

    // 1. Exact slug lookup
    $stmt = $pdo->prepare($baseSelect . " WHERE m.slug = ? LIMIT 1");
    $stmt->execute([$normInputSlug]);
    $mp = $stmt->fetch() ?: null;

    // 2. Strip honorific prefixes / normalize punctuation if visitor included Shri/Dr/Smt
    if (!$mp) {
        $regenSlug = generate_mp_slug(str_replace('-', ' ', $normInputSlug));
        if ($regenSlug !== '' && $regenSlug !== $normInputSlug) {
            $stmt = $pdo->prepare($baseSelect . " WHERE m.slug = ? LIMIT 1");
            $stmt->execute([$regenSlug]);
            $mp = $stmt->fetch() ?: null;
        }
    }

    // 3. Disambiguation base prefix fallback (e.g., /mp/sanjay-seth -> sanjay-seth-ranchi)
    if (!$mp) {
        $baseCandidate = generate_mp_slug(str_replace('-', ' ', $normInputSlug));
        if ($baseCandidate !== '') {
            $stmt = $pdo->prepare($baseSelect . "
                WHERE m.slug LIKE ?
                ORDER BY (m.membership_status = 'Sitting') DESC, (m.type = 'ls') DESC, m.id ASC
                LIMIT 1
            ");
            $stmt->execute([$baseCandidate . '-%']);
            $mp = $stmt->fetch() ?: null;
        }
    }

    // 4. Vowel-length & spelling tolerant fallback (e.g., kangana-ranaut -> kangna-ranaut)
    if (!$mp) {
        $foldedBase = generate_mp_slug(str_replace('-', ' ', $normInputSlug));
        $foldedInput = str_replace(['aa', 'ee', 'oo', 'ii', 'uu'], ['a', 'i', 'u', 'i', 'u'], $foldedBase !== '' ? $foldedBase : $normInputSlug);
        $stmt = $pdo->prepare($baseSelect . "
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LOWER(m.slug), 'aa', 'a'), 'ee', 'i'), 'oo', 'u'), 'ii', 'i'), 'uu', 'u') = ?
            ORDER BY (m.membership_status = 'Sitting') DESC, (m.type = 'ls') DESC, m.id ASC
            LIMIT 1
        ");
        $stmt->execute([$foldedInput]);
        $mp = $stmt->fetch() ?: null;
    }

    // 5. Tight spelling / missing-vowel fallback (e.g., kangana-ranaut -> kangna-ranaut)
    if (!$mp && strlen($normInputSlug) >= 6 && strlen($normInputSlug) <= 120) {
        $targetStr = $foldedBase !== '' ? $foldedBase : $normInputSlug;
        $bestId = null;
        $bestDist = 999;
        $maxDist = strlen($targetStr) >= 10 ? 2 : 1;
        foreach ($pdo->query("SELECT id, slug FROM mp") as $cand) {
            $candSlug = strtolower((string)$cand['slug']);
            $d = levenshtein($targetStr, $candSlug);
            if ($d <= $maxDist && $d < $bestDist) {
                $bestDist = $d;
                $bestId = (int)$cand['id'];
            }
        }
        if ($bestId !== null) {
            $stmt = $pdo->prepare($baseSelect . " WHERE m.id = ? LIMIT 1");
            $stmt->execute([$bestId]);
            $mp = $stmt->fetch() ?: null;
        }
    }
} elseif ($rawId > 0) {
    $stmt = $pdo->prepare($baseSelect . " WHERE m.id = ? LIMIT 1");
    $stmt->execute([$rawId]);
    $mp = $stmt->fetch() ?: null;
}

if (!$mp) {
    http_response_code(404);
    $pageTitle = "Member of Parliament Not Found";
    $pageDescription = "Requested Member of Parliament profile could not be located in the LocalNeta Parliament directory.";
    require_once __DIR__ . '/includes/header.php';
    ?>
    <main class="main-wrapper">
        <div style="background:#FFFFFF; border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); border-radius:var(--radius-lg); padding:40px 24px; text-align:center; box-shadow:var(--shadow-sm); margin-top:16px;">
            <i class="fas fa-landmark" style="font-size:2.8rem; color:var(--text-muted); margin-bottom:14px;"></i>
            <h1 style="font-size:1.5rem; font-weight:800; color:var(--navy-main); margin-bottom:8px;">Member of Parliament Not Found</h1>
            <p style="font-size:0.92rem; color:var(--text-secondary); max-width:560px; margin:0 auto 20px;">
                We could not find a Parliament profile matching <code><?= e($rawSlug ?: (string)$rawId) ?></code> across our 789 Lok Sabha &amp; Rajya Sabha MP records. Search below by MP name, constituency, or state.
            </p>
            <form action="<?= get_site_url('elections') ?>#mp-directory" method="GET" style="display:flex; gap:8px; max-width:460px; margin:0 auto 20px;">
                <input type="text" name="mp_q" value="<?= e(str_replace('-', ' ', $rawSlug)) ?>" placeholder="Search MP name, seat or state..." class="form-control">
                <button type="submit" class="btn-primary-tiranga" style="white-space:nowrap;">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
            <a href="<?= get_site_url('elections#mp-directory') ?>" class="btn-green-tiranga" style="padding:8px 18px; font-size:0.86rem;">
                <i class="fas fa-list"></i> Browse All 789 Members of Parliament
            </a>
        </div>
    </main>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Parse & normalize real MP metadata
$mpId = (int)$mp['id'];
$mpSlug = trim((string)($mp['slug'] ?? ''));
$memberName = clean_mp_display_name($mp['member_name']);
$bareName = strip_mp_honorific_prefix($memberName);
$isRs = ($mp['type'] === 'rs');
$isNominated = $isRs && empty($mp['election']);
$houseShort = $isRs ? 'Rajya Sabha' : 'Lok Sabha';
$houseTitle = $isRs
    ? 'Member of Parliament — Rajya Sabha (Upper House)'
    : 'Member of Parliament — Lok Sabha (Lower House)';
$houseBadge = !$isRs
    ? strtoupper($mp['election'] ?: 'LS-18')
    : ($mp['election'] ?: 'RS Nominated');

$constituency = clean_party_display_text($mp['constituency']);
$stateDisplay = clean_party_display_text($mp['state_display'] ?: $mp['state']);
$stateCode = trim((string)($mp['state_code'] ?? ''));
$partyRaw = clean_party_display_text($mp['party_name']);
$statusVal = clean_party_display_text($mp['membership_status']) ?: 'Sitting';
$age = !empty($mp['age']) ? (int)$mp['age'] : null;
$termPeriod = clean_party_display_text($mp['term_period'] ?? '');
$totalTerms = isset($mp['total_terms']) && $mp['total_terms'] !== null ? (int)$mp['total_terms'] : null;
$lsTermsRaw = clean_party_display_text($mp['lok_sabha_terms'] ?? '');

$statusCls = strcasecmp($statusVal, 'Sitting') === 0
    ? 'status-success'
    : (strcasecmp($statusVal, 'Resigned') === 0 ? 'status-warning' : 'status-muted');

// Parse Lok Sabha term list & seat reservation category
$lsTermsList = [];
if (!$isRs && $lsTermsRaw !== '') {
    foreach (explode(',', $lsTermsRaw) as $tItem) {
        $tClean = trim($tItem);
        if ($tClean !== '') {
            $lsTermsList[] = $tClean;
        }
    }
}

$seatCategory = 'General Seat';
if ($isRs) {
    $seatCategory = $isNominated ? 'Presidential Nomination' : 'State / UT Indirect Election';
} elseif (preg_match('/\(SC\)\s*$/i', $constituency)) {
    $seatCategory = 'Reserved — Scheduled Caste (SC)';
} elseif (preg_match('/\(ST\)\s*$/i', $constituency)) {
    $seatCategory = 'Reserved — Scheduled Tribe (ST)';
}

// Compute crisp initials for fallback avatar badge
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
    $initialChars = 'MP';
}

// Resolve affiliated political party + ECI symbol + party profile URL
$partyMeta = resolve_party_card_meta($pdo, $partyRaw);

// Check if any other MPs share the exact same member name (for disambiguation banner)
$baseSlugForDup = generate_mp_slug($memberName);
$dupStmt = $pdo->prepare("
    SELECT id, slug, member_name, type, election, constituency, state, party_name, membership_status
    FROM mp
    WHERE id <> ?
      AND (LOWER(TRIM(member_name)) = LOWER(TRIM(?)) OR slug = ? OR slug LIKE ?)
    ORDER BY (membership_status = 'Sitting') DESC, (type = 'ls') DESC, id ASC
");
$dupStmt->execute([$mpId, $memberName, $baseSlugForDup, $baseSlugForDup . '-%']);
$sameNamePeers = array_values(array_filter(
    $dupStmt->fetchAll(),
    fn(array $row): bool => generate_mp_slug((string)$row['member_name']) === $baseSlugForDup
));

// Fetch other MPs from the same State / UT (or other Nominated MPs if Nominated)
$stateMps = [];
if ($isNominated) {
    $smStmt = $pdo->prepare("
        SELECT * FROM mp
        WHERE id <> ? AND (state = 'Nominated' OR (type = 'rs' AND election IS NULL))
        ORDER BY membership_status = 'Sitting' DESC, member_name ASC
        LIMIT 8
    ");
    $smStmt->execute([$mpId]);
    $stateMps = $smStmt->fetchAll();
} elseif ($stateCode !== '') {
    $smStmt = $pdo->prepare("
        SELECT * FROM mp
        WHERE id <> ? AND state_code = ?
        ORDER BY (type = ?) DESC, membership_status = 'Sitting' DESC, constituency ASC, member_name ASC
        LIMIT 8
    ");
    $smStmt->execute([$mpId, $stateCode, $mp['type']]);
    $stateMps = $smStmt->fetchAll();
} else {
    $smStmt = $pdo->prepare("
        SELECT * FROM mp
        WHERE id <> ? AND state = ?
        ORDER BY (type = ?) DESC, membership_status = 'Sitting' DESC, constituency ASC, member_name ASC
        LIMIT 8
    ");
    $smStmt->execute([$mpId, $mp['state'], $mp['type']]);
    $stateMps = $smStmt->fetchAll();
}

// Fetch fellow MPs from the same political party (including full/short party aliases)
$partyMps = [];
if ($partyMeta && empty($partyMeta['is_independent'])) {
    $partyMatchCandidates = array_values(array_unique(array_filter([
        $partyRaw,
        $partyMeta['name'] ?? '',
        $partyMeta['short'] ?? '',
    ])));
    if (!empty($partyMeta['slug'])) {
        $slugToAbbrevs = [
            'bharatiya-janata-party' => ['Bharatiya Janata Party', 'BJP'],
            'indian-national-congress' => ['Indian National Congress', 'INC'],
            'samajwadi-party' => ['Samajwadi Party', 'SP'],
            'all-india-trinamool-congress' => ['All India Trinamool Congress', 'AITC'],
            'dravida-munnetra-kazhagam' => ['Dravida Munnetra Kazhagam', 'DMK'],
            'telugu-desam' => ['Telugu Desam Party', 'TDP'],
            'janata-dal-united' => ['Janata Dal (United)', 'JD(U)'],
            'rashtriya-janata-dal' => ['Rashtriya Janata Dal', 'RJD'],
            'aam-aadmi-party' => ['Aam Aadmi Party', 'AAP'],
            'yuvajana-sramika-rythu-congress-party' => ['Yuvajana Sramika Rythu Congress Party', 'YSRCP'],
            'communist-party-of-india-marxist' => ['Communist Party of India (Marxist)', 'CPI(M)'],
            'communist-party-of-india' => ['Communist Party of India', 'CPI'],
            'shiv-sena' => ['Shiv Sena', 'SS'],
            'shiv-sena-uddhav-balasaheb-thackrey' => ['Shiv Sena (Uddhav Balasaheb Thackrey)', 'SS(UBT)', 'SS-UBT'],
            'nationalist-congress-party' => ['Nationalist Congress Party', 'NCP'],
            'nationalist-congress-party-sharadchandra-pawar' => ['Nationalist Congress Party - Sharadchandra Pawar', 'NCP-SCP', 'NCP-SP'],
            'jharkhand-mukti-morcha' => ['Jharkhand Mukti Morcha', 'JMM'],
            'indian-union-muslim-league' => ['Indian Union Muslim League', 'IUML'],
            'jammu-and-kashmir-national-conference' => ['Jammu and Kashmir National Conference', 'J&KNC'],
            'asom-gana-parishad' => ['Asom Gana Parishad', 'AGP'],
            'rashtriya-lok-dal' => ['Rashtriya Lok Dal', 'RLD'],
            'janasena-party' => ['Janasena Party', 'JSP'],
        ];
        if (isset($slugToAbbrevs[$partyMeta['slug']])) {
            $partyMatchCandidates = array_values(array_unique(array_merge($partyMatchCandidates, $slugToAbbrevs[$partyMeta['slug']])));
        }
    }
    if (!empty($partyMatchCandidates)) {
        $pHolders = implode(', ', array_fill(0, count($partyMatchCandidates), '?'));
        $pmStmt = $pdo->prepare("
            SELECT * FROM mp
            WHERE id <> ? AND party_name IN ({$pHolders})
            ORDER BY (state_code = ?) DESC, membership_status = 'Sitting' DESC, (type = 'ls') DESC, constituency ASC, member_name ASC
            LIMIT 6
        ");
        $pmStmt->execute(array_merge([$mpId], $partyMatchCandidates, [$stateCode]));
        $partyMps = $pmStmt->fetchAll();
    }
}

$pageTitle = "{$memberName} — MP ({$houseShort}), {$constituency}";
$pageDescription = "{$memberName} is a {$statusVal} Member of Parliament ({$houseShort}) representing {$constituency}" . ($isNominated ? '' : ", {$stateDisplay}") . ($partyMeta ? " affiliated with {$partyMeta['name']}" : '') . ". View constituency, parliamentary terms, party symbol, and MP poster kit on LocalNeta.";
$canonicalUrl = get_mp_page_url($mpSlug, $mpId);

require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Breadcrumb -->
    <nav style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:0.82rem; color:var(--text-muted); margin-bottom:16px;">
        <a href="<?= get_site_url('index') ?>" style="color:var(--navy-main); font-weight:600;">Home</a>
        <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <a href="<?= get_site_url('elections?mp_type=' . urlencode($mp['type']) . '#mp-directory') ?>" style="color:var(--navy-main); font-weight:600;"><?= e($houseShort) ?> MPs</a>
        <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php if ($stateCode !== '' && !$isNominated): ?>
            <a href="<?= get_site_url('elections?mp_state=' . urlencode($stateCode) . '#mp-directory') ?>" style="color:var(--navy-main); font-weight:600;"><?= e($stateDisplay) ?></a>
            <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php elseif ($isNominated): ?>
            <a href="<?= get_site_url('elections?mp_state=Nominated#mp-directory') ?>" style="color:var(--navy-main); font-weight:600;">Nominated</a>
            <i class="fas fa-chevron-right" style="font-size:0.65rem;"></i>
        <?php endif; ?>
        <span style="color:var(--saffron-main); font-weight:700;"><?= e($memberName) ?></span>
    </nav>

    <?php if (!empty($sameNamePeers)): ?>
        <!-- Disambiguation Strip when multiple MPs share a name -->
        <div style="background:#EFF6FF; border:1px solid #BFDBFE; border-left:4px solid var(--navy-accent); border-radius:var(--radius-md); padding:10px 14px; margin-bottom:16px; font-size:0.84rem; color:var(--navy-main); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
            <div>
                <i class="fas fa-circle-info" style="color:var(--navy-accent); margin-right:6px;"></i>
                Also in Parliament with this name:
                <?php foreach ($sameNamePeers as $pIdx => $peer):
                    $peerHouse = $peer['type'] === 'rs' ? ($peer['election'] ?: 'Rajya Sabha') : 'Lok Sabha';
                ?>
                    <?= $pIdx > 0 ? ' • ' : '' ?>
                    <a href="<?= get_mp_page_url((string)$peer['slug'], (int)$peer['id']) ?>" style="font-weight:700; color:var(--navy-main); text-decoration:underline;">
                        <?= e($peer['member_name']) ?> (<?= e($peer['constituency']) ?>, <?= e($peerHouse) ?>)
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Hero Profile Card -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); overflow:hidden; box-shadow:var(--shadow-sm); margin-bottom:24px;">
        <div style="height:10px; background:linear-gradient(90deg, #FF671F 0%, #FFFFFF 50%, #046A38 100%);"></div>

        <div style="padding:24px; display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start; justify-content:space-between;">
            <!-- Portrait / House Emblem Box -->
            <div style="width:180px; flex-shrink:0; margin:0 auto; text-align:center; background:var(--white-surface); border:1px solid var(--border-color); border-radius:var(--radius-lg); padding:16px 12px;">
                <div style="width:120px; height:120px; margin:0 auto 12px; border-radius:50%; border:3px solid <?= $isRs ? 'var(--green-main)' : 'var(--saffron-main)' ?>; background:<?= $isRs ? 'linear-gradient(135deg, #046A38, #0A192F)' : 'var(--navy-gradient)' ?>; overflow:hidden; display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-sm); color:#FFFFFF;">
                    <?php if (!empty($mp['photo_url'])): ?>
                        <img src="<?= e($mp['photo_url']) ?>" alt="<?= e($memberName) ?>"
                             style="width:100%; height:100%; object-fit:cover; display:block;"
                             onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                        <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; flex-direction:column; gap:4px;">
                            <span style="font-size:1.85rem; font-weight:800; letter-spacing:0.04em;"><?= e($initialChars) ?></span>
                            <i class="fas fa-landmark" style="font-size:0.85rem; opacity:0.85;"></i>
                        </span>
                    <?php else: ?>
                        <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; flex-direction:column; gap:4px;">
                            <span style="font-size:1.85rem; font-weight:800; letter-spacing:0.04em;"><?= e($initialChars) ?></span>
                            <i class="fas fa-landmark" style="font-size:0.85rem; opacity:0.85;"></i>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="margin-bottom:6px;">
                    <span class="cat-pill <?= $isRs ? ($isNominated ? 'bg-purple' : 'bg-emerald') : 'bg-indigo' ?>"><?= e($houseBadge) ?></span>
                </div>
                <div style="font-size:0.8rem; font-weight:700; color:var(--navy-main);"><?= e($houseShort) ?> MP</div>
                <div style="margin-top:6px;">
                    <span class="status-chip <?= $statusCls ?>"><span class="status-dot"></span> <?= e($statusVal) ?></span>
                </div>
            </div>

            <!-- Identity, Constituency, Party & Actions -->
            <div style="flex:1; min-width:260px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
                    <span class="cat-pill <?= $isRs ? ($isNominated ? 'bg-purple' : 'bg-emerald') : 'bg-rose' ?>">
                        <i class="fas fa-landmark"></i> <?= e($houseShort) ?>
                    </span>
                    <span class="badge-tag badge-verified">
                        <i class="fas fa-map-pin"></i> <?= e($stateDisplay) ?>
                    </span>
                    <?php if ($age !== null): ?>
                        <span class="badge-tag badge-unverified">
                            <i class="fas fa-user"></i> Age: <?= $age ?> yrs
                        </span>
                    <?php endif; ?>
                </div>

                <h1 style="font-size:1.7rem; font-weight:800; color:var(--navy-main); line-height:1.25; margin:0;">
                    <?= e($memberName) ?>
                </h1>

                <p style="font-size:0.95rem; font-weight:600; color:var(--green-dark); margin-top:4px;">
                    <?= e($houseTitle) ?>
                </p>

                <!-- Key Civic Metadata Grid -->
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(185px, 1fr)); gap:12px; margin-top:16px;">
                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-left:3px solid var(--saffron-main); border-radius:var(--radius-md); padding:11px 13px;">
                        <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                            <?= $isRs ? 'Parliamentary Seat' : 'Lok Sabha Constituency' ?>
                        </div>
                        <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-top:2px;">
                            <?= e($constituency) ?>
                        </div>
                        <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:2px;">
                            <?= e($seatCategory) ?>
                        </div>
                    </div>

                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-left:3px solid var(--green-main); border-radius:var(--radius-md); padding:11px 13px;">
                        <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                            State / Representation
                        </div>
                        <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-top:2px;">
                            <?php if ($stateCode !== '' && !$isNominated): ?>
                                <a href="<?= get_site_url('elections?mp_state=' . urlencode($stateCode) . '#mp-directory') ?>" style="color:var(--navy-main); text-decoration:underline;">
                                    <?= e($stateDisplay) ?>
                                </a>
                            <?php else: ?>
                                <?= e($stateDisplay) ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:2px;">
                            <?= !$isRs ? '18th Lok Sabha (LS-18)' : ($isNominated ? 'Article 80 Nominated' : e($mp['election'] ?: 'Rajya Sabha')) ?>
                        </div>
                    </div>

                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-left:3px solid var(--navy-accent); border-radius:var(--radius-md); padding:11px 13px;">
                        <div style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.04em; color:var(--text-muted); font-weight:700;">
                            <?= $isRs ? 'Rajya Sabha Tenure' : 'Lok Sabha Terms' ?>
                        </div>
                        <div style="font-size:0.95rem; font-weight:800; color:var(--navy-main); margin-top:2px;">
                            <?php if ($isRs): ?>
                                <?= e($termPeriod ?: 'Rajya Sabha Term') ?>
                            <?php else: ?>
                                <?= !empty($lsTermsList) ? count($lsTermsList) . ' Term' . (count($lsTermsList) === 1 ? '' : 's') . ' (' . e($lsTermsRaw) . ')' : e($lsTermsRaw ?: '18') ?>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.76rem; color:var(--text-secondary); margin-top:2px;">
                            <?php if ($isRs && $totalTerms !== null): ?>
                                Total Rajya Sabha terms: <strong><?= $totalTerms ?></strong>
                            <?php elseif (!$isRs): ?>
                                Membership Status: <strong><?= e($statusVal) ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Affiliated Political Party Box -->
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
                                Political Party Affiliation
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

                <!-- Action CTAs -->
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;">
                    <a href="<?= get_site_url('studio?mp_id=' . $mpId) ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.86rem;">
                        <i class="fas fa-paint-brush"></i> Create Greeting Poster
                    </a>
                    <?php if ($stateCode !== '' && !$isNominated): ?>
                        <a href="<?= get_site_url('elections?mp_state=' . urlencode($stateCode) . '#mp-directory') ?>" class="btn-green-tiranga" style="padding:8px 16px; font-size:0.86rem;">
                            <i class="fas fa-users"></i> All <?= e($stateDisplay) ?> MPs
                        </a>
                    <?php endif; ?>
                    <a href="<?= get_site_url('elections?mp_type=' . urlencode($mp['type']) . '#mp-directory') ?>" class="btn-profile-view" style="flex:unset; padding:8px 16px; font-size:0.86rem;">
                        <i class="fas fa-arrow-left"></i> Back to <?= e($houseShort) ?> Directory
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Parliamentary Record & Term History -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:8px;">
            <h2 class="section-title">
                <i class="fas fa-clock-rotate-left"></i> Parliamentary Record &amp; Tenure Overview
            </h2>
            <span class="badge-tag badge-elected"><?= e($houseBadge) ?></span>
        </div>

        <?php if (!$isRs && !empty($lsTermsList)): ?>
            <p style="font-size:0.88rem; color:var(--text-secondary); line-height:1.55; margin-bottom:14px;">
                <strong><?= e($memberName) ?></strong> has been elected to the Lok Sabha <strong><?= count($lsTermsList) ?></strong> <?= count($lsTermsList) === 1 ? 'time' : 'times' ?>, currently representing the <strong><?= e($constituency) ?></strong> parliamentary constituency in <strong><?= e($stateDisplay) ?></strong> (Membership status: <strong><?= e($statusVal) ?></strong>).
            </p>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <?php foreach ($lsTermsList as $termNum):
                    $isCurrentLs = ($termNum === '18');
                ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-full); font-size:0.8rem; font-weight:700; border:1px solid <?= $isCurrentLs ? 'var(--green-main)' : 'var(--border-color)' ?>; background:<?= $isCurrentLs ? '#F0FDF4' : 'var(--white-surface)' ?>; color:<?= $isCurrentLs ? 'var(--green-dark)' : 'var(--navy-main)' ?>;">
                        <i class="fas <?= $isCurrentLs ? 'fa-check-circle' : 'fa-landmark' ?>" style="font-size:0.75rem; color:<?= $isCurrentLs ? 'var(--green-main)' : 'var(--saffron-main)' ?>;"></i>
                        <?= e($termNum) ?>th Lok Sabha<?= $isCurrentLs ? ' (Current)' : '' ?>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="font-size:0.88rem; color:var(--text-secondary); line-height:1.55; margin-bottom:14px;">
                <strong><?= e($memberName) ?></strong> serves in the <strong>Rajya Sabha</strong> (Council of States)
                <?= $isNominated ? 'as a <strong>Nominated Member</strong>' : 'representing <strong>' . e($stateDisplay) . '</strong>' ?>
                <?php if ($termPeriod !== ''): ?>for the term period <strong><?= e($termPeriod) ?></strong><?php endif; ?>
                <?php if ($totalTerms !== null): ?> across <strong><?= $totalTerms ?></strong> parliamentary <?= $totalTerms === 1 ? 'term' : 'terms' ?><?php endif; ?>.
            </p>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-full); font-size:0.8rem; font-weight:700; border:1px solid var(--green-main); background:#F0FDF4; color:var(--green-dark);">
                    <i class="fas fa-calendar-alt"></i> Term: <?= e($termPeriod ?: 'Rajya Sabha') ?>
                </span>
                <?php if ($totalTerms !== null): ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-full); font-size:0.8rem; font-weight:700; border:1px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                        <i class="fas fa-layer-group" style="color:var(--saffron-main);"></i> Total RS Terms: <?= $totalTerms ?>
                    </span>
                <?php endif; ?>
                <?php if ($age !== null): ?>
                    <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:var(--radius-full); font-size:0.8rem; font-weight:700; border:1px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                        <i class="fas fa-user" style="color:var(--navy-accent);"></i> Age: <?= $age ?> Years
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if (!empty($stateMps)): ?>
        <!-- Other MPs from the Same State / Scope -->
        <section style="margin-bottom:28px;">
            <div class="section-header" style="flex-wrap:wrap; gap:10px;">
                <div>
                    <h2 class="section-title">
                        <i class="fas fa-landmark"></i>
                        <?= $isNominated ? 'Other Nominated Rajya Sabha MPs' : 'Other Members of Parliament from ' . e($stateDisplay) ?>
                    </h2>
                    <p style="font-size:0.83rem; color:var(--text-muted); margin-top:2px;">
                        Fellow Lok Sabha &amp; Rajya Sabha representatives <?= $isNominated ? 'nominated to the Upper House' : 'in ' . e($stateDisplay) ?>
                    </p>
                </div>
                <a href="<?= get_site_url('elections?' . http_build_query(['mp_state' => $isNominated ? 'Nominated' : ($stateCode !== '' ? $stateCode : $stateDisplay)]) . '#mp-directory') ?>" class="section-link">
                    Explore All <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="cards-grid">
                <?php foreach ($stateMps as $peer):
                    $pIsRs = $peer['type'] === 'rs';
                    $pHouseBadge = !$pIsRs ? strtoupper($peer['election'] ?: 'LS-18') : ($peer['election'] ?: 'RS Nominated');
                    $peerUrl = get_mp_page_url((string)($peer['slug'] ?? ''), (int)$peer['id']);
                    $peerPartyMeta = resolve_party_card_meta($pdo, $peer['party_name'] ?? '');
                ?>
                    <div class="leader-card">
                        <div class="leader-card-cover" style="height:52px; background:<?= $pIsRs ? 'linear-gradient(135deg, #046A38, #0A192F)' : 'var(--navy-gradient)' ?>;">
                            <div style="position:absolute; top:10px; right:10px; z-index:2;">
                                <span class="cat-pill <?= $pIsRs ? 'bg-emerald' : 'bg-rose' ?>"><?= e($pHouseBadge) ?></span>
                            </div>
                        </div>
                        <div class="leader-card-body">
                            <div class="leader-avatar-wrapper" style="display:flex; align-items:center; justify-content:center; background:var(--navy-light); color:var(--navy-main);">
                                <?php if (!empty($peer['photo_url'])): ?>
                                    <img src="<?= e($peer['photo_url']) ?>" alt="<?= e($peer['member_name']) ?>" loading="lazy"
                                         onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                    <span style="display:none; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.2rem; color:var(--navy-main);">
                                        <i class="fas fa-landmark"></i>
                                    </span>
                                <?php else: ?>
                                    <span style="display:flex; width:100%; height:100%; align-items:center; justify-content:center; font-size:1.2rem; color:var(--navy-main);">
                                        <i class="fas fa-landmark"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="leader-header-info">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:6px;">
                                    <h3 class="leader-name" style="margin:0;">
                                        <a href="<?= e($peerUrl) ?>" style="color:inherit; text-decoration:none;"><?= e($peer['member_name']) ?></a>
                                    </h3>
                                    <span class="badge-tag <?= $peer['membership_status'] === 'Sitting' ? 'badge-elected' : 'badge-unverified' ?>" style="flex-shrink:0;">
                                        <?= e($peer['membership_status']) ?>
                                    </span>
                                </div>
                                <div class="leader-designation"><?= $pIsRs ? 'MP (Rajya Sabha)' : 'MP (Lok Sabha)' ?></div>
                            </div>
                            <div class="leader-panchayat">
                                <i class="fas fa-map-pin" style="color:var(--saffron-main);"></i>
                                <span><strong><?= e($peer['constituency']) ?></strong><?= !$pIsRs ? ', ' . e($peer['state']) : '' ?></span>
                            </div>
                            <?php if ($peerPartyMeta): ?>
                                <div style="margin-top:8px;"><?= render_party_chip_html($peerPartyMeta) ?></div>
                            <?php endif; ?>
                            <div class="leader-card-footer" style="margin-top:auto; padding-top:12px;">
                                <a href="<?= e($peerUrl) ?>" class="btn-profile-view">
                                    View MP Profile <i class="fas fa-arrow-right" style="font-size:0.75rem;"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($partyMps)): ?>
        <!-- Fellow Party MPs -->
        <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
            <div class="section-header" style="flex-wrap:wrap; gap:8px;">
                <h2 class="section-title">
                    <i class="fas fa-flag"></i> Fellow MPs — <?= e($partyMeta['name'] ?? $partyRaw) ?>
                </h2>
                <?php if (!empty($partyMeta['url'])): ?>
                    <a href="<?= e($partyMeta['url']) ?>" class="section-link">
                        Open Party Page <i class="fas fa-arrow-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:12px;">
                <?php foreach ($partyMps as $pmp):
                    $pmUrl = get_mp_page_url((string)($pmp['slug'] ?? ''), (int)$pmp['id']);
                    $pmIsRs = $pmp['type'] === 'rs';
                ?>
                    <a href="<?= e($pmUrl) ?>"
                       style="display:flex; gap:10px; align-items:center; padding:10px 12px; border:1px solid var(--border-light); border-radius:var(--radius-md); background:var(--white-surface); text-decoration:none;">
                        <div style="width:40px; height:40px; border-radius:50%; background:<?= $pmIsRs ? 'var(--green-main)' : 'var(--navy-main)' ?>; color:#FFFFFF; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:0.9rem;">
                            <i class="fas fa-landmark"></i>
                        </div>
                        <div style="min-width:0; flex:1;">
                            <div style="font-size:0.86rem; font-weight:700; color:var(--navy-main); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= e($pmp['member_name']) ?>
                            </div>
                            <div style="font-size:0.75rem; color:var(--text-secondary); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?= e($pmp['constituency']) ?><?= !$pmIsRs ? ', ' . e($pmp['state']) : '' ?>
                            </div>
                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">
                                <?= $pmIsRs ? 'Rajya Sabha' : 'Lok Sabha (LS-18)' ?> • <?= e($pmp['membership_status']) ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Bottom Source Attribution Strip -->
    <div style="background:#FFFFFF; border:1px solid var(--border-color); border-left:4px solid var(--green-main); border-radius:var(--radius-md); padding:12px 18px; margin-top:24px; font-size:0.82rem; color:var(--text-muted); box-shadow:var(--shadow-sm);">
        <i class="fas fa-landmark" style="color:var(--green-main); margin-right:6px;"></i>
        Source: <a href="<?= get_site_url('source#source-sansad') ?>" style="color:var(--navy-main); font-weight:700; text-decoration:underline;">Digital Sansad — Parliament of India (Lok Sabha &amp; Rajya Sabha Directory)</a>
    </div>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


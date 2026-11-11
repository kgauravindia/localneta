<?php
// elections.php - Election Information & Civic Education Hub (MySQL, English UI, Tiranga Palette)
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

// Parliament Members Directory filters from `mp` table (Lok Sabha LS-18 & Rajya Sabha, source: https://sansad.in/)
$mpType = trim($_GET['mp_type'] ?? '');
if (!in_array($mpType, ['', 'ls', 'rs'], true)) {
    $mpType = '';
}
$mpState = trim($_GET['mp_state'] ?? '');
$mpParty = trim($_GET['mp_party'] ?? '');
$mpStatus = trim($_GET['mp_status'] ?? '');
$mpQuery = trim($_GET['mp_q'] ?? '');
$mpPage = max(1, (int)($_GET['mp_page'] ?? 1));
$mpPerPage = 25;

// Overall Parliament (Lok Sabha & Rajya Sabha) summary stats
$mpSummary = $pdo->query("
    SELECT 
        COUNT(*) AS total_rows,
        SUM(type = 'ls') AS ls_count,
        SUM(type = 'rs') AS rs_count,
        SUM(membership_status = 'Sitting') AS sitting_count,
        COUNT(DISTINCT state_code) AS state_count,
        COUNT(DISTINCT party_name) AS party_count
    FROM mp
")->fetch();

// Filter dropdown lists (deduplicated via op_sdb + Nominated)
$mpStates = $pdo->query("
    SELECT COALESCE(NULLIF(m.state_code, ''), m.state) AS filter_val,
           COALESCE(s.state, m.state) AS display_name
    FROM mp m
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON m.state_code = s.state_code
    GROUP BY COALESCE(NULLIF(m.state_code, ''), m.state), COALESCE(s.state, m.state)
    ORDER BY display_name ASC
")->fetchAll();

$partySql = "SELECT DISTINCT party_name FROM mp";
$partyParams = [];
if ($mpType !== '') {
    $partySql .= " WHERE type = ?";
    $partyParams[] = $mpType;
}
$partySql .= " ORDER BY party_name ASC";
$partyStmt = $pdo->prepare($partySql);
$partyStmt->execute($partyParams);
$mpParties = $partyStmt->fetchAll(PDO::FETCH_COLUMN);

// Filtered query
$mpWhere = " WHERE 1=1";
$mpParams = [];
if ($mpType !== '') {
    $mpWhere .= " AND type = ?";
    $mpParams[] = $mpType;
}
if ($mpState !== '') {
    $mpWhere .= " AND (state_code = ? OR state = ?)";
    $mpParams[] = $mpState;
    $mpParams[] = $mpState;
}
if ($mpParty !== '') {
    $mpWhere .= " AND party_name = ?";
    $mpParams[] = $mpParty;
}
if ($mpStatus !== '') {
    $mpWhere .= " AND membership_status = ?";
    $mpParams[] = $mpStatus;
}
if ($mpQuery !== '') {
    $mpWhere .= " AND (member_name LIKE ? OR constituency LIKE ? OR party_name LIKE ? OR state LIKE ? OR COALESCE(election, '') LIKE ? OR COALESCE(term_period, '') LIKE ?)";
    $wild = '%' . $mpQuery . '%';
    array_push($mpParams, $wild, $wild, $wild, $wild, $wild, $wild);
}

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM mp" . $mpWhere);
$cntStmt->execute($mpParams);
$mpFilteredTotal = (int)$cntStmt->fetchColumn();
$mpTotalPages = max(1, (int)ceil($mpFilteredTotal / $mpPerPage));
if ($mpPage > $mpTotalPages) {
    $mpPage = $mpTotalPages;
}
$mpOffset = ($mpPage - 1) * $mpPerPage;

$mpStmt = $pdo->prepare("SELECT * FROM mp" . $mpWhere . " ORDER BY type = 'ls' DESC, state ASC, constituency ASC, member_name ASC, id ASC LIMIT " . (int)$mpPerPage . " OFFSET " . (int)$mpOffset);
$mpStmt->execute($mpParams);
$mpRows = $mpStmt->fetchAll();

// Legislative Council Members (MLC) Directory filters from `mlc` table (UP, Bihar & Andhra Pradesh via neva.gov.in)
$hasMlcTable = (bool)$pdo->query("SHOW TABLES LIKE 'mlc'")->fetch();
$mlcState = trim($_GET['mlc_state'] ?? '');
$mlcCtype = trim($_GET['mlc_ctype'] ?? '');
$mlcParty = trim($_GET['mlc_party'] ?? '');
$mlcQuery = trim($_GET['mlc_q'] ?? '');
$mlcPage = max(1, (int)($_GET['mlc_page'] ?? 1));
$mlcPerPage = 25;

$mlcSummary = [
    'total_rows' => 0,
    'up_count' => 0,
    'br_count' => 0,
    'ap_count' => 0,
    'party_count' => 0,
];
$mlcStatesList = [];
$mlcCtypesList = [];
$mlcPartiesList = [];
$mlcRows = [];
$mlcFilteredTotal = 0;
$mlcTotalPages = 1;

if ($hasMlcTable) {
    $mlcSummary = $pdo->query("
        SELECT
            COUNT(*) AS total_rows,
            SUM(state_code = '9') AS up_count,
            SUM(state_code = '10') AS br_count,
            SUM(state_code = '28') AS ap_count,
            COUNT(DISTINCT party_name) AS party_count
        FROM mlc
        WHERE status = 'ACTIVE'
    ")->fetch() ?: $mlcSummary;

    $mlcStatesList = $pdo->query("
        SELECT state_code, state
        FROM mlc
        WHERE status = 'ACTIVE'
        GROUP BY state_code, state
        ORDER BY FIELD(state_code, '10', '9', '28'), state ASC
    ")->fetchAll();

    $mlcCtypesList = $pdo->query("SELECT DISTINCT constituency_type FROM mlc WHERE status = 'ACTIVE' AND constituency_type <> '' ORDER BY constituency_type ASC")->fetchAll(PDO::FETCH_COLUMN);

    $mlcPartySql = "SELECT DISTINCT party_name FROM mlc WHERE status = 'ACTIVE' AND party_name <> ''";
    $mlcPartyParams = [];
    if ($mlcState !== '') {
        $mlcPartySql .= " AND (state_code = ? OR state = ?)";
        $mlcPartyParams[] = $mlcState;
        $mlcPartyParams[] = $mlcState;
    }
    $mlcPartySql .= " ORDER BY party_name ASC";
    $mpSt = $pdo->prepare($mlcPartySql);
    $mpSt->execute($mlcPartyParams);
    $mlcPartiesList = $mpSt->fetchAll(PDO::FETCH_COLUMN);

    $mlcWhere = " WHERE status = 'ACTIVE'";
    $mlcParams = [];
    if ($mlcState !== '') {
        $mlcWhere .= " AND (state_code = ? OR state = ?)";
        $mlcParams[] = $mlcState;
        $mlcParams[] = $mlcState;
    }
    if ($mlcCtype !== '') {
        $mlcWhere .= " AND constituency_type = ?";
        $mlcParams[] = $mlcCtype;
    }
    if ($mlcParty !== '') {
        $mlcWhere .= " AND party_name = ?";
        $mlcParams[] = $mlcParty;
    }
    if ($mlcQuery !== '') {
        $mlcWhere .= " AND (member_name LIKE ? OR constituency LIKE ? OR COALESCE(constituency_area, '') LIKE ? OR constituency_type LIKE ? OR party_name LIKE ? OR COALESCE(role_designation, '') LIKE ? OR state LIKE ?)";
        $wild = '%' . $mlcQuery . '%';
        array_push($mlcParams, $wild, $wild, $wild, $wild, $wild, $wild, $wild);
    }

    $mlcCnt = $pdo->prepare("SELECT COUNT(*) FROM mlc" . $mlcWhere);
    $mlcCnt->execute($mlcParams);
    $mlcFilteredTotal = (int)$mlcCnt->fetchColumn();
    $mlcTotalPages = max(1, (int)ceil($mlcFilteredTotal / $mlcPerPage));
    if ($mlcPage > $mlcTotalPages) {
        $mlcPage = $mlcTotalPages;
    }
    $mlcOffset = ($mlcPage - 1) * $mlcPerPage;

    $mlcStmt = $pdo->prepare("
        SELECT * FROM mlc" . $mlcWhere . "
        ORDER BY FIELD(state_code, '10', '9', '28'),
                 (role_designation IS NOT NULL AND role_designation <> '') DESC,
                 membership_status = 'Sitting' DESC,
                 seat_no IS NULL ASC,
                 seat_no ASC,
                 member_name ASC,
                 id ASC
        LIMIT " . (int)$mlcPerPage . " OFFSET " . (int)$mlcOffset
    );
    $mlcStmt->execute($mlcParams);
    $mlcRows = $mlcStmt->fetchAll();
}

$pageTitle = "Election & Voter Information Hub";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Page Header -->
    <div class="tiranga-banner" style="margin-bottom:24px;">
        <div>
            <h2><i class="fas fa-vote-yea" style="color:var(--saffron-main);"></i> Election & Voter Awareness Hub</h2>
            <p>Official voter registration portals, electoral roll search, polling booth guides, and civic rights explainers.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <a href="<?= get_site_url('parties') ?>" class="btn-green-tiranga" style="padding:8px 16px; font-size:0.85rem;">
                <i class="fas fa-flag"></i> Political Parties
            </a>
            <a href="https://voters.eci.gov.in" target="_blank" rel="noopener" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.85rem;">
                <i class="fas fa-external-link-alt"></i> ECI Portal
            </a>
        </div>
    </div>

    <!-- Official Portals Grid -->
    <section style="margin-bottom:30px;">
        <h2 class="section-title" style="margin-bottom:14px;"><i class="fas fa-link"></i> Official Voter Resources & Helplines</h2>
        
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
            <!-- ECI Portal Card -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); padding:20px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column;">
                <div style="width:44px; height:44px; border-radius:50%; background:var(--saffron-light); color:var(--saffron-main); display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:12px;">
                    <i class="fas fa-check-to-slot"></i>
                </div>
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:6px;">ECI Voters' Services Portal</h3>
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5; margin-bottom:16px; flex:1;">
                    Search your name on the national electoral roll, download e-EPIC digital voter card, or submit Form 6 for new voter registration.
                </p>
                <a href="https://voters.eci.gov.in" target="_blank" rel="noopener" class="btn-profile-view" style="width:100%; text-align:center;">
                    Open Portal <i class="fas fa-external-link-alt" style="font-size:0.75rem;"></i>
                </a>
            </div>

            <!-- State CEO Portals Card (Pan-India) -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--green-main); padding:20px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column;">
                <div style="width:44px; height:44px; border-radius:50%; background:var(--green-light); color:var(--green-main); display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:12px;">
                    <i class="fas fa-building-columns"></i>
                </div>
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:6px;">All-India State CEOs &amp; Voter Rolls</h3>
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5; margin-bottom:16px; flex:1;">
                    Access Chief Electoral Officer (CEO) directories, constituency maps, BLO contacts, and draft/final electoral rolls across all 36 States &amp; Union Territories.
                </p>
                <a href="https://www.eci.gov.in/ceo-links" target="_blank" rel="noopener" class="btn-profile-view" style="width:100%; text-align:center;">
                    Open State CEO Portals <i class="fas fa-external-link-alt" style="font-size:0.75rem;"></i>
                </a>
            </div>

            <!-- Toll-Free Helpline 1950 Card -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--navy-accent); padding:20px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column;">
                <div style="width:44px; height:44px; border-radius:50%; background:var(--navy-light); color:var(--navy-accent); display:flex; align-items:center; justify-content:center; font-size:1.2rem; margin-bottom:12px;">
                    <i class="fas fa-phone-volume"></i>
                </div>
                <h3 style="font-size:1.1rem; font-weight:700; color:var(--navy-main); margin-bottom:6px;">National Voter Helpline: 1950</h3>
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5; margin-bottom:16px; flex:1;">
                    Dial toll-free 1950 from anywhere in India for quick queries regarding your polling booth, election dates, e-EPIC status, and voter grievance logging.
                </p>
                <a href="tel:1950" class="btn-green-tiranga" style="width:100%; text-align:center; padding:8px 12px; font-size:0.88rem;">
                    <i class="fas fa-phone"></i> Call Toll-Free 1950
                </a>
            </div>
        </div>
    </section>

    <!-- Parliament Members Directory (Lok Sabha & Rajya Sabha) -->
    <section id="mp-directory" style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--saffron-main); padding:22px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:10px;">
            <div>
                <h2 class="section-title"><i class="fas fa-landmark"></i> Parliament Members Directory (Lok Sabha &amp; Rajya Sabha)</h2>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-top:2px;">
                    Official directory of Lok Sabha (<strong>LS-18</strong>) and Rajya Sabha Members of Parliament (biennial tenure cycles &amp; Nominated)
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <span class="badge-tag badge-elected"><?= (int)($mpSummary['sitting_count'] ?? 0) ?> Sitting / <?= (int)($mpSummary['total_rows'] ?? 0) ?> Total</span>
                <a href="https://sansad.in/" target="_blank" rel="noopener" class="badge-tag badge-verified" style="text-decoration:none;">
                    <i class="fas fa-link"></i> sansad.in <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                </a>
                <a href="<?= get_site_url('source#source-sansad') ?>" class="badge-tag badge-unverified" style="text-decoration:none;">
                    <i class="fas fa-database"></i> View All Sources
                </a>
            </div>
        </div>

        <!-- Summary Chips -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(135px, 1fr)); gap:10px; margin-bottom:18px;">
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Total MPs Listed</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--navy-main);"><?= number_format((int)($mpSummary['total_rows'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Lok Sabha (LS-18)</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--saffron-main);"><?= number_format((int)($mpSummary['ls_count'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Rajya Sabha</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--green-main);"><?= number_format((int)($mpSummary['rs_count'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Sitting Members</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--navy-accent);"><?= number_format((int)($mpSummary['sitting_count'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">States &amp; UTs</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--navy-main);"><?= (int)($mpSummary['state_count'] ?? 0) ?></div>
            </div>
        </div>

        <!-- Filter Form -->
        <form action="<?= get_site_url('elections') ?>#mp-directory" method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(155px, 1fr)); gap:10px; align-items:flex-end; margin-bottom:18px; background:var(--white-surface); padding:14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">House</label>
                <select name="mp_type" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Houses (LS + RS)</option>
                    <option value="ls" <?= $mpType === 'ls' ? 'selected' : '' ?>>Lok Sabha (LS-18)</option>
                    <option value="rs" <?= $mpType === 'rs' ? 'selected' : '' ?>>Rajya Sabha (RS)</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">State / UT</label>
                <select name="mp_state" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All States &amp; UTs</option>
                    <?php foreach ($mpStates as $ms): $stVal = (string)$ms['filter_val']; ?>
                        <option value="<?= e($stVal) ?>" <?= ($mpState === $stVal || strcasecmp($mpState, $ms['display_name']) === 0) ? 'selected' : '' ?>>
                            <?= e($ms['display_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Political Party</label>
                <select name="mp_party" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Parties</option>
                    <?php foreach ($mpParties as $pt): ?>
                        <option value="<?= e($pt) ?>" <?= $mpParty === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Membership Status</label>
                <select name="mp_status" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Statuses</option>
                    <option value="Sitting" <?= $mpStatus === 'Sitting' ? 'selected' : '' ?>>Sitting</option>
                    <option value="Resigned" <?= $mpStatus === 'Resigned' ? 'selected' : '' ?>>Resigned</option>
                    <option value="Died" <?= $mpStatus === 'Died' ? 'selected' : '' ?>>Died</option>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Search Member / Seat</label>
                <input type="text" name="mp_q" value="<?= e($mpQuery) ?>" placeholder="e.g. Rudy, Nitish, Saran..." class="form-control" style="padding:7px 10px; font-size:0.85rem;">
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn-primary-tiranga" style="padding:8px 14px; font-size:0.85rem; flex:1;">
                    <i class="fas fa-search"></i> Filter
                </button>
                <?php if ($mpType !== '' || $mpState !== '' || $mpParty !== '' || $mpStatus !== '' || $mpQuery !== ''): ?>
                    <a href="<?= get_site_url('elections') ?>#mp-directory" class="btn-profile-view" style="padding:8px 10px; font-size:0.82rem; flex:unset;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Results Count -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px; font-size:0.82rem; color:var(--text-muted);">
            <span>Showing <strong><?= count($mpRows) ?></strong> of <strong><?= number_format($mpFilteredTotal) ?></strong> matching MPs (Page <?= $mpPage ?> of <?= $mpTotalPages ?>)</span>
            <span>Dataset: <code>mp</code> (<?= $mpType === 'ls' ? '<code>type=ls</code>, <code>election=ls-18</code>' : ($mpType === 'rs' ? '<code>type=rs</code>, biennial <code>RS YYYY</code> / <code>Nominated</code>' : '<code>ls-18</code> &amp; Rajya Sabha') ?>)</span>
        </div>

        <!-- Directory Table -->
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                        <th style="padding:10px;">#</th>
                        <th style="padding:10px;">Member Name</th>
                        <th style="padding:10px;">House / Election</th>
                        <th style="padding:10px;">Constituency / Seat</th>
                        <th style="padding:10px;">State / UT</th>
                        <th style="padding:10px;">Party Name</th>
                        <th style="padding:10px;">Terms / Tenure</th>
                        <th style="padding:10px;">Status</th>
                        <th style="padding:10px;">Profile</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mpRows)): ?>
                        <tr>
                            <td colspan="9" style="padding:26px; text-align:center; color:var(--text-muted);">
                                No Members of Parliament matched your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mpRows as $idx => $mp):
                            $rowNo = $mpOffset + $idx + 1;
                            $statusCls = $mp['membership_status'] === 'Sitting'
                                ? 'status-success'
                                : ($mp['membership_status'] === 'Resigned' ? 'status-warning' : 'status-muted');
                            $isRs = $mp['type'] === 'rs';
                            $isNominated = $isRs && empty($mp['election']);
                            $houseLabel = !$isRs
                                ? strtoupper($mp['election'] ?: 'LS-18')
                                : ($mp['election'] ?: 'RS');
                            $termsText = $isRs
                                ? trim(($mp['term_period'] ?: 'Rajya Sabha') . ($mp['total_terms'] !== null ? ' • ' . (int)$mp['total_terms'] . ' term' . ((int)$mp['total_terms'] === 1 ? '' : 's') : ''))
                                : 'LS ' . $mp['lok_sabha_terms'];
                            $mpPageUrl = get_mp_page_url((string)($mp['slug'] ?? ''), (int)$mp['id']);
                        ?>
                            <tr style="border-bottom:1px solid var(--border-light);">
                                <td style="padding:10px; color:var(--text-muted); font-weight:600;"><?= $rowNo ?></td>
                                <td style="padding:10px;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if (!empty($mp['photo_url'])): ?>
                                            <img src="<?= e($mp['photo_url']) ?>" alt="<?= e($mp['member_name']) ?>" loading="lazy"
                                                 style="width:36px; height:36px; border-radius:50%; object-fit:cover; border:1px solid var(--border-color); flex-shrink:0; background:#F1F5F9;"
                                                 onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                            <span style="display:none; width:36px; height:36px; border-radius:50%; background:var(--navy-light); color:var(--navy-main); align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                                                <i class="fas fa-landmark"></i>
                                            </span>
                                        <?php else: ?>
                                            <span style="display:flex; width:36px; height:36px; border-radius:50%; background:var(--navy-light); color:var(--navy-main); align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                                                <i class="fas fa-landmark"></i>
                                            </span>
                                        <?php endif; ?>
                                        <div>
                                            <a href="<?= e($mpPageUrl) ?>" style="font-weight:700; color:var(--navy-main); line-height:1.25; text-decoration:none; display:inline-block;">
                                                <?= e($mp['member_name']) ?>
                                            </a>
                                            <?php if (!empty($mp['age'])): ?>
                                                <div style="font-size:0.74rem; color:var(--text-muted);">Age: <?= (int)$mp['age'] ?> yrs</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <span class="cat-pill <?= $isRs ? ($isNominated ? 'bg-purple' : 'bg-emerald') : 'bg-indigo' ?>"><?= e($houseLabel) ?></span>
                                    <?php if ($isNominated): ?>
                                        <div style="font-size:0.72rem; color:var(--text-muted); margin-top:2px;">Nominated</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px; font-weight:600;"><?= e($mp['constituency']) ?></td>
                                <td style="padding:10px; color:var(--text-secondary);"><?= e($mp['state']) ?></td>
                                <td style="padding:10px;"><span class="cat-pill bg-blue"><?= e($mp['party_name']) ?></span></td>
                                <td style="padding:10px; font-size:0.8rem; color:var(--text-secondary); white-space:nowrap;"><?= e($termsText) ?></td>
                                <td style="padding:10px;">
                                    <span class="status-chip <?= $statusCls ?>"><span class="status-dot"></span> <?= e($mp['membership_status']) ?></span>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <a href="<?= e($mpPageUrl) ?>" style="font-size:0.8rem; font-weight:700; color:var(--green-main); text-decoration:none;">
                                        View Page <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($mpTotalPages > 1): ?>
            <div style="display:flex; justify-content:center; align-items:center; gap:8px; flex-wrap:wrap; margin-top:16px;">
                <?php
                $baseParams = array_filter([
                    'mp_type' => $mpType,
                    'mp_state' => $mpState,
                    'mp_party' => $mpParty,
                    'mp_status' => $mpStatus,
                    'mp_q' => $mpQuery,
                ], fn($v) => $v !== '');
                if ($mpPage > 1):
                    $prevUrl = get_site_url('elections?' . http_build_query($baseParams + ['mp_page' => $mpPage - 1])) . '#mp-directory';
                ?>
                    <a href="<?= $prevUrl ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>

                <span style="font-size:0.84rem; font-weight:700; color:var(--navy-main); padding:0 8px;">
                    Page <?= $mpPage ?> / <?= $mpTotalPages ?>
                </span>

                <?php if ($mpPage < $mpTotalPages):
                    $nextUrl = get_site_url('elections?' . http_build_query($baseParams + ['mp_page' => $mpPage + 1])) . '#mp-directory';
                ?>
                    <a href="<?= $nextUrl ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($hasMlcTable): ?>
    <!-- Legislative Council Members Directory (MLCs — UP, Bihar & Andhra Pradesh) -->
    <section id="mlc-directory" style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--green-main); padding:22px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:10px;">
            <div>
                <h2 class="section-title"><i class="fas fa-scroll"></i> Legislative Council Members Directory (Vidhan Parishad MLCs)</h2>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-top:2px;">
                    Official directory of Members of Legislative Council across <strong>Uttar Pradesh</strong>, <strong>Bihar</strong> &amp; <strong>Andhra Pradesh</strong>
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <span class="badge-tag badge-elected"><?= number_format((int)($mlcSummary['total_rows'] ?? 0)) ?> Sitting MLCs</span>
                <a href="<?= get_site_url('source#source-neva') ?>" class="badge-tag badge-verified" style="text-decoration:none;">
                    <i class="fas fa-database"></i> Source: neva.gov.in
                </a>
            </div>
        </div>

        <!-- Summary Chips -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(135px, 1fr)); gap:10px; margin-bottom:18px;">
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Total MLCs</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--navy-main);"><?= number_format((int)($mlcSummary['total_rows'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Uttar Pradesh</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--saffron-main);"><?= number_format((int)($mlcSummary['up_count'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Bihar</div>
                <div style="font-size:1.25rem; font-weight:800; color:var(--green-main);"><?= number_format((int)($mlcSummary['br_count'] ?? 0)) ?></div>
            </div>
            <div style="background:var(--white-surface); padding:10px 14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
                <div style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">Andhra Pradesh</div>
                <div style="font-size:1.25rem; font-weight:800; color:#5B21B6;"><?= number_format((int)($mlcSummary['ap_count'] ?? 0)) ?></div>
            </div>
        </div>

        <!-- MLC Filter Form -->
        <form action="<?= get_site_url('elections') ?>#mlc-directory" method="GET" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(155px, 1fr)); gap:10px; align-items:flex-end; margin-bottom:18px; background:var(--white-surface); padding:14px; border-radius:var(--radius-md); border:1px solid var(--border-light);">
            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">State Council</label>
                <select name="mlc_state" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All States (UP, Bihar, AP)</option>
                    <?php foreach ($mlcStatesList as $ms): ?>
                        <option value="<?= e($ms['state_code']) ?>" <?= ($mlcState === (string)$ms['state_code'] || strcasecmp($mlcState, $ms['state']) === 0) ? 'selected' : '' ?>>
                            <?= e($ms['state']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Constituency Quota</label>
                <select name="mlc_ctype" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Quotas</option>
                    <?php foreach ($mlcCtypesList as $ct): ?>
                        <option value="<?= e($ct) ?>" <?= $mlcCtype === $ct ? 'selected' : '' ?>><?= e($ct) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Political Party</label>
                <select name="mlc_party" class="form-control" style="padding:7px 10px; font-size:0.85rem;">
                    <option value="">All Parties</option>
                    <?php foreach ($mlcPartiesList as $pt): ?>
                        <option value="<?= e($pt) ?>" <?= $mlcParty === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin:0;">
                <label class="form-label" style="font-size:0.78rem;">Search Member / Seat</label>
                <input type="text" name="mlc_q" value="<?= e($mlcQuery) ?>" placeholder="e.g. Nawal, Keshav, Patna..." class="form-control" style="padding:7px 10px; font-size:0.85rem;">
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="btn-green-tiranga" style="padding:8px 14px; font-size:0.85rem; flex:1;">
                    <i class="fas fa-search"></i> Filter
                </button>
                <?php if ($mlcState !== '' || $mlcCtype !== '' || $mlcParty !== '' || $mlcQuery !== ''): ?>
                    <a href="<?= get_site_url('elections') ?>#mlc-directory" class="btn-profile-view" style="padding:8px 10px; font-size:0.82rem; flex:unset;">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Results Count -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:10px; font-size:0.82rem; color:var(--text-muted);">
            <span>Showing <strong><?= count($mlcRows) ?></strong> of <strong><?= number_format($mlcFilteredTotal) ?></strong> matching MLCs (Page <?= $mlcPage ?> of <?= $mlcTotalPages ?>)</span>
            <span>Dataset: <code>mlc</code> (<code>br-*</code>, <code>up-*</code>, <code>ap-*</code>)</span>
        </div>

        <!-- MLC Directory Table -->
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                        <th style="padding:10px;">#</th>
                        <th style="padding:10px;">MLC Name</th>
                        <th style="padding:10px;">State</th>
                        <th style="padding:10px;">Constituency / Quota</th>
                        <th style="padding:10px;">Type</th>
                        <th style="padding:10px;">Party Name</th>
                        <th style="padding:10px;">Role</th>
                        <th style="padding:10px;">Profile</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mlcRows)): ?>
                        <tr>
                            <td colspan="8" style="padding:26px; text-align:center; color:var(--text-muted);">
                                No Legislative Council members matched your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($mlcRows as $mIdx => $mlcRow):
                            $mRowNo = $mlcOffset + $mIdx + 1;
                            $mlcUrl = get_mlc_page_url((string)($mlcRow['slug'] ?? ''), (int)$mlcRow['id']);
                            $mPrefix = strtoupper(get_mlc_state_slug_prefix((string)$mlcRow['state_code'], (string)$mlcRow['state']));
                        ?>
                            <tr style="border-bottom:1px solid var(--border-light);">
                                <td style="padding:10px; color:var(--text-muted); font-weight:600;"><?= $mRowNo ?></td>
                                <td style="padding:10px;">
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if (!empty($mlcRow['photo_url'])): ?>
                                            <img src="<?= e($mlcRow['photo_url']) ?>" alt="<?= e($mlcRow['member_name']) ?>" loading="lazy"
                                                 style="width:36px; height:36px; border-radius:50%; object-fit:cover; border:1px solid var(--border-color); flex-shrink:0; background:#F1F5F9;"
                                                 onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                                            <span style="display:none; width:36px; height:36px; border-radius:50%; background:var(--navy-light); color:var(--navy-main); align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                                                <i class="fas fa-scroll"></i>
                                            </span>
                                        <?php else: ?>
                                            <span style="display:flex; width:36px; height:36px; border-radius:50%; background:var(--navy-light); color:var(--navy-main); align-items:center; justify-content:center; font-size:0.85rem; flex-shrink:0;">
                                                <i class="fas fa-scroll"></i>
                                            </span>
                                        <?php endif; ?>
                                        <div>
                                            <a href="<?= e($mlcUrl) ?>" style="font-weight:700; color:var(--navy-main); line-height:1.25; text-decoration:none; display:inline-block;">
                                                <?= e($mlcRow['member_name']) ?>
                                            </a>
                                            <?php if (!empty($mlcRow['age'])): ?>
                                                <div style="font-size:0.74rem; color:var(--text-muted);">Age: <?= (int)$mlcRow['age'] ?> yrs</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <span class="cat-pill bg-purple"><?= e($mPrefix) ?></span>
                                    <span style="font-size:0.8rem; color:var(--text-secondary); margin-left:4px;"><?= e($mlcRow['state']) ?></span>
                                </td>
                                <td style="padding:10px; font-weight:600;"><?= e($mlcRow['constituency']) ?></td>
                                <td style="padding:10px;"><span class="cat-pill bg-indigo"><?= e($mlcRow['constituency_type']) ?></span></td>
                                <td style="padding:10px;"><span class="cat-pill bg-blue"><?= e($mlcRow['party_name']) ?></span></td>
                                <td style="padding:10px; font-size:0.8rem; color:var(--text-secondary);">
                                    <?= !empty($mlcRow['role_designation']) ? e($mlcRow['role_designation']) : e($mlcRow['membership_status']) ?>
                                </td>
                                <td style="padding:10px; white-space:nowrap;">
                                    <a href="<?= e($mlcUrl) ?>" style="font-size:0.8rem; font-weight:700; color:var(--green-main); text-decoration:none;">
                                        View Page <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($mlcTotalPages > 1): ?>
            <div style="display:flex; justify-content:center; align-items:center; gap:8px; flex-wrap:wrap; margin-top:16px;">
                <?php
                $mlcBaseParams = array_filter([
                    'mlc_state' => $mlcState,
                    'mlc_ctype' => $mlcCtype,
                    'mlc_party' => $mlcParty,
                    'mlc_q' => $mlcQuery,
                ], fn($v) => $v !== '');
                if ($mlcPage > 1):
                    $mPrevUrl = get_site_url('elections?' . http_build_query($mlcBaseParams + ['mlc_page' => $mlcPage - 1])) . '#mlc-directory';
                ?>
                    <a href="<?= $mPrevUrl ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                        <i class="fas fa-chevron-left"></i> Prev
                    </a>
                <?php endif; ?>

                <span style="font-size:0.84rem; font-weight:700; color:var(--navy-main); padding:0 8px;">
                    Page <?= $mlcPage ?> / <?= $mlcTotalPages ?>
                </span>

                <?php if ($mlcPage < $mlcTotalPages):
                    $mNextUrl = get_site_url('elections?' . http_build_query($mlcBaseParams + ['mlc_page' => $mlcPage + 1])) . '#mlc-directory';
                ?>
                    <a href="<?= $mNextUrl ?>" class="btn-profile-view" style="flex:unset; padding:6px 14px; font-size:0.82rem;">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- How-To 3-Step Voter Guide -->
    <section style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:24px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
        <h2 style="font-size:1.25rem; font-weight:800; color:var(--navy-main); margin-bottom:18px;">
            <i class="fas fa-list-check" style="color:var(--saffron-main);"></i> Essential Voter Check: 3 Steps
        </h2>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:20px;">
            <div style="display:flex; gap:14px;">
                <div style="width:36px; height:36px; border-radius:50%; background:var(--saffron-main); color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0;">1</div>
                <div>
                    <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin-bottom:4px;">Check Name in Voter Roll</h4>
                    <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                        Visit <a href="https://voters.eci.gov.in" target="_blank" style="color:var(--saffron-main); font-weight:600;">voters.eci.gov.in</a> and search using your EPIC number or Name and Date of Birth.
                    </p>
                </div>
            </div>

            <div style="display:flex; gap:14px;">
                <div style="width:36px; height:36px; border-radius:50%; background:var(--green-main); color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0;">2</div>
                <div>
                    <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin-bottom:4px;">Locate Your Polling Booth</h4>
                    <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                        Confirm your designated school or community hall booth number and your Booth Level Officer (BLO) contact details.
                    </p>
                </div>
            </div>

            <div style="display:flex; gap:14px;">
                <div style="width:36px; height:36px; border-radius:50%; background:var(--navy-main); color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0;">3</div>
                <div>
                    <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin-bottom:4px;">Verify Candidate Affidavits</h4>
                    <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                        Read verified financial asset disclosures and legal affidavits on LocalNeta before casting your informed vote.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Civic Education Explainers -->
    <section id="explainers" style="margin-bottom:30px;">
        <h2 class="section-title" style="margin-bottom:14px;"><i class="fas fa-book-open-reader"></i> Rural Civic Governance Guides</h2>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
            <!-- Guide 1 -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:18px; box-shadow:var(--shadow-sm);">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <span class="cat-pill bg-purple">Bihar Governance</span>
                </div>
                <h3 style="font-size:1.05rem; font-weight:700; color:var(--navy-main); margin-bottom:8px;">
                    What is the Difference Between a Mukhiya and a Sarpanch in Bihar?
                </h3>
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                    Under the Bihar Panchayat Raj Act 2006, the <strong>Mukhiya</strong> heads the executive body (Gram Panchayat), controlling development funds, PMGSY roads, Nal-Jal, and sanitation grants. The <strong>Sarpanch</strong> heads the grassroots judicial body (Gram Kachehri), resolving petty civil and boundary disputes.
                </p>
            </div>

            <!-- Guide 2 -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:18px; box-shadow:var(--shadow-sm);">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <span class="cat-pill bg-blue">Block & District</span>
                </div>
                <h3 style="font-size:1.05rem; font-weight:700; color:var(--navy-main); margin-bottom:8px;">
                    How Panchayat Samiti and Zila Parishad Coordinate with Gram Panchayats
                </h3>
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                    Panchayat Samiti members represent territorial constituencies at the Block tier (coordinated by the BDO). Zila Parishad members govern district-tier infrastructure (highways, large schools, public health centers) funded by State and Central Finance Commissions.
                </p>
            </div>

            <!-- Guide 3 -->
            <div style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); padding:18px; box-shadow:var(--shadow-sm);">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                    <span class="cat-pill bg-emerald">Citizen Rights</span>
                </div>
                <h3 style="font-size:1.05rem; font-weight:700; color:var(--navy-main); margin-bottom:8px;">
                    How to File an RTI (Right to Information) for Panchayat Development Work
                </h3>
                <p style="font-size:0.85rem; color:var(--text-secondary); line-height:1.5;">
                    Any citizen can submit an application under RTI Act 2005 to the Public Information Officer (PIO / Panchayat Secretary) with a ₹10 fee to request measurement books (MB), sanction orders, and contractor muster rolls.
                </p>
            </div>
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


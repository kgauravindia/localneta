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
            <h2><i class="fas fa-vote-yea" style="color:var(--saffron-main);"></i> Election &amp; Voter Awareness Hub</h2>
            <p>Official voter registration portals, electoral roll search, upcoming election schedules, and civic rights explainers.</p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <a href="#upcoming-elections" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.85rem;">
                <i class="fas fa-calendar-days"></i> Upcoming Elections
            </a>
            <a href="<?= get_site_url('parties') ?>" class="btn-green-tiranga" style="padding:8px 16px; font-size:0.85rem;">
                <i class="fas fa-flag"></i> Political Parties
            </a>
            <a href="https://voters.eci.gov.in" target="_blank" rel="noopener" class="btn-profile-view" style="padding:8px 16px; font-size:0.85rem; border:1px solid rgba(255,255,255,0.35); background:rgba(255,255,255,0.15); color:#FFFFFF;">
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

    <!-- Upcoming Elections Calendar Section (Official ECI Calendar) -->
    <section id="upcoming-elections" style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--navy-accent); padding:24px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:12px; margin-bottom:18px;">
            <div>
                <div style="display:inline-flex; align-items:center; gap:6px; background:var(--navy-light); color:var(--navy-accent); padding:3px 10px; border-radius:20px; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px;">
                    <i class="fas fa-check-double"></i> Verified Official Schedule &bull; ECI Calendar
                </div>
                <h2 class="section-title" style="margin-bottom:4px;">
                    <i class="fas fa-calendar-check" style="color:var(--navy-accent);"></i> Upcoming Elections in India (Official ECI Calendar)
                </h2>
                <p style="font-size:0.88rem; color:var(--text-secondary); margin:0;">
                    Official schedule of forthcoming State Legislative Assembly general elections, Constitutional Presidential &amp; Vice-Presidential polls, Rajya Sabha &amp; Vidhan Parishad biennial cycles, and statutory bye-elections compiled from the <strong>Election Commission of India (eci.gov.in)</strong>.
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <a href="https://www.eci.gov.in/term-of-the-houses" target="_blank" rel="noopener" class="badge-tag badge-verified" style="text-decoration:none; padding:6px 12px; font-size:0.8rem;">
                    <i class="fas fa-file-contract"></i> ECI Term of Houses <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                </a>
                <a href="https://www.eci.gov.in/press-notes" target="_blank" rel="noopener" class="badge-tag badge-elected" style="text-decoration:none; padding:6px 12px; font-size:0.8rem;">
                    <i class="fas fa-bullhorn"></i> Official Press Notes <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                </a>
            </div>
        </div>

        <!-- Key Metrics Strip -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; margin-bottom:24px;">
            <div style="background:var(--saffron-light); border-left:4px solid var(--saffron-main); border-radius:var(--radius-md); padding:12px 14px;">
                <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--saffron-dark); letter-spacing:0.4px;">Next General Assembly Cycle</div>
                <div style="font-size:1.35rem; font-weight:800; color:var(--navy-main); margin-top:2px;">7 States / UTs</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">1,054 Assembly Constituencies (2027)</div>
            </div>
            <div style="background:var(--navy-light); border-left:4px solid var(--navy-accent); border-radius:var(--radius-md); padding:12px 14px;">
                <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--navy-accent); letter-spacing:0.4px;">Supreme Constitutional Polls</div>
                <div style="font-size:1.35rem; font-weight:800; color:var(--navy-main); margin-top:2px;">July &amp; Aug 2027</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">President &amp; Vice-President of India</div>
            </div>
            <div style="background:var(--green-light); border-left:4px solid var(--green-main); border-radius:var(--radius-md); padding:12px 14px;">
                <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--green-dark); letter-spacing:0.4px;">Upper House Continuous Cycles</div>
                <div style="font-size:1.35rem; font-weight:800; color:var(--navy-main); margin-top:2px;">Biennial Retirals</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">1/3rd Rajya Sabha &amp; Vidhan Parishad seats</div>
            </div>
            <div style="background:var(--bg-light); border-left:4px solid #6b7280; border-radius:var(--radius-md); padding:12px 14px;">
                <div style="font-size:0.75rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px;">Casual Vacancies / Bye-Polls</div>
                <div style="font-size:1.35rem; font-weight:800; color:var(--navy-main); margin-top:2px;">6 Months Rule</div>
                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:2px;">Sec 151A, RPA 1951 statutory mandate</div>
            </div>
        </div>

        <!-- Section Subsection: 2027 Assembly Elections Table -->
        <div style="margin-bottom:28px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
                <h3 style="font-size:1.05rem; font-weight:700; color:var(--navy-main); margin:0; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-landmark-flag" style="color:var(--saffron-main);"></i> Next Major State Legislative Assembly General Elections (2027 Cycle)
                </h3>
                <span style="font-size:0.8rem; color:var(--text-muted);">
                    Governed under Article 172(1) of the Constitution of India (5-year statutory term)
                </span>
            </div>

            <div class="table-container" style="border:1px solid var(--border-color); border-radius:var(--radius-md); overflow-x:auto;">
                <table class="data-table" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="min-width:180px;">State / Legislative Assembly</th>
                            <th style="text-align:center; min-width:110px;">Total Seats</th>
                            <th style="min-width:150px;">Current Term</th>
                            <th style="min-width:160px;">Term Expiration Date</th>
                            <th style="min-width:140px;">Expected Schedule</th>
                            <th style="min-width:160px; text-align:right;">Official State CEO Portal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Uttar Pradesh</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Vidhan Sabha (18th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">403 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">23 May 2022 &ndash; 2027</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 22 May 2027</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Feb &ndash; Mar 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceouttarpradesh.nic.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Uttar Pradesh <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Punjab</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Vidhan Sabha (16th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">117 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">17 Mar 2022 &ndash; 2027</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 16 March 2027</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Feb &ndash; Mar 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceopunjab.gov.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Punjab <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Uttarakhand</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Vidhan Sabha (5th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">70 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">29 Mar 2022 &ndash; 2027</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 28 March 2027</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Feb &ndash; Mar 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceo.uk.gov.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Uttarakhand <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Goa</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Legislative Assembly (8th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">40 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">15 Mar 2022 &ndash; 2027</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 14 March 2027</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Feb &ndash; Mar 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceogoa.nic.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Goa <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Manipur</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Legislative Assembly (12th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">60 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">14 Mar 2022 &ndash; 2027</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 13 March 2027</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Feb &ndash; Mar 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceomanipur.nic.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Manipur <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Gujarat</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Vidhan Sabha (15th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">182 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">20 Dec 2022 &ndash; 2027</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 19 December 2027</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Nov &ndash; Dec 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceo.gujarat.gov.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Gujarat <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <strong style="color:var(--navy-main); font-size:0.95rem;">Himachal Pradesh</strong>
                                <div style="font-size:0.75rem; color:var(--text-muted);">Vidhan Sabha (14th Assembly)</div>
                            </td>
                            <td style="text-align:center;">
                                <span class="badge-tag badge-elected" style="font-size:0.85rem; font-weight:700;">68 Seats</span>
                            </td>
                            <td><span style="font-size:0.85rem; color:var(--text-secondary);">04 Jan 2023 &ndash; 2028</span></td>
                            <td>
                                <strong style="color:#b91c1c; font-size:0.88rem;"><i class="far fa-calendar-alt"></i> 03 January 2028</strong>
                            </td>
                            <td>
                                <span class="badge-tag badge-verified" style="font-size:0.75rem;">Nov &ndash; Dec 2027</span>
                            </td>
                            <td style="text-align:right;">
                                <a href="https://ceohimachal.nic.in/" target="_blank" rel="noopener" class="btn-profile-view" style="font-size:0.75rem; padding:4px 8px;">
                                    CEO Himachal <i class="fas fa-external-link-alt" style="font-size:0.65rem;"></i>
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4-Pillar Grid: Presidential, Rajya Sabha, MLC Biennial, Bye-Elections -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px; margin-bottom:26px;">
            <!-- Pillar 1: Presidential & VP Polls -->
            <div style="background:#FBFBFC; border:1px solid var(--border-color); border-top:3px solid var(--saffron-main); border-radius:var(--radius-md); padding:18px; display:flex; flex-direction:column;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:var(--saffron-light); color:var(--saffron-dark); display:flex; align-items:center; justify-content:center; font-size:1rem;">
                        <i class="fas fa-award"></i>
                    </div>
                    <div>
                        <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin:0;">Presidential &amp; VP Elections</h4>
                        <span style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Constitution of India &bull; Arts. 54, 55, 66</span>
                    </div>
                </div>
                <p style="font-size:0.83rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px; flex:1;">
                    The 5-year constitutional term of the President of India expires in <strong>July 2027</strong> and the Vice-President of India in <strong>August 2027</strong>. Elected by an Electoral College consisting of elected members of both Houses of Parliament and elected MLAs of all States/UTs.
                </p>
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:8px 10px; font-size:0.75rem; color:var(--navy-main);">
                    <strong>System:</strong> Proportional Representation by Single Transferable Vote (Secret Ballot)
                </div>
            </div>

            <!-- Pillar 2: Rajya Sabha Biennial Cycles -->
            <div style="background:#FBFBFC; border:1px solid var(--border-color); border-top:3px solid var(--navy-accent); border-radius:var(--radius-md); padding:18px; display:flex; flex-direction:column;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:var(--navy-light); color:var(--navy-accent); display:flex; align-items:center; justify-content:center; font-size:1rem;">
                        <i class="fas fa-landmark"></i>
                    </div>
                    <div>
                        <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin:0;">Rajya Sabha Biennial Cycles</h4>
                        <span style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Council of States &bull; Art. 83(1)</span>
                    </div>
                </div>
                <p style="font-size:0.83rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px; flex:1;">
                    The Rajya Sabha is a permanent House not subject to dissolution. <strong>One-third of its members retire every second year</strong>. Vacancies are filled through biennial elections conducted by the ECI where State MLAs vote via STV, plus Presidential nominations under Art. 80.
                </p>
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:8px 10px; font-size:0.75rem; color:var(--navy-main);">
                    <strong>Tenure:</strong> 6 Years &bull; Total Sanctioned: 245 Members (233 Elected + 12 Nominated)
                </div>
            </div>

            <!-- Pillar 3: Legislative Council (Vidhan Parishad) -->
            <div style="background:#FBFBFC; border:1px solid var(--border-color); border-top:3px solid var(--green-main); border-radius:var(--radius-md); padding:18px; display:flex; flex-direction:column;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:var(--green-light); color:var(--green-main); display:flex; align-items:center; justify-content:center; font-size:1rem;">
                        <i class="fas fa-building-columns"></i>
                    </div>
                    <div>
                        <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin:0;">Vidhan Parishad Biennial Elections</h4>
                        <span style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">6 Bicameral States &bull; Art. 171</span>
                    </div>
                </div>
                <p style="font-size:0.83rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px; flex:1;">
                    Upper House MLC elections occur biennially across Uttar Pradesh (100), Bihar (75), Maharashtra (78), Karnataka (75), Andhra Pradesh (58), and Telangana (40). Constituencies comprise Graduates, Teachers, Local Authorities, and Legislative Assembly quotas.
                </p>
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:8px 10px; font-size:0.75rem; color:var(--navy-main);">
                    <strong>Explore:</strong> See the <a href="#mlc-directory" style="color:var(--green-dark); font-weight:700; text-decoration:none;">MLC Directory below</a> for sitting member records
                </div>
            </div>

            <!-- Pillar 4: Bye-Elections / Casual Vacancies -->
            <div style="background:#FBFBFC; border:1px solid var(--border-color); border-top:3px solid #6b7280; border-radius:var(--radius-md); padding:18px; display:flex; flex-direction:column;">
                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                    <div style="width:36px; height:36px; border-radius:50%; background:var(--bg-light); color:#374151; display:flex; align-items:center; justify-content:center; font-size:1rem;">
                        <i class="fas fa-person-booth"></i>
                    </div>
                    <div>
                        <h4 style="font-size:0.98rem; font-weight:700; color:var(--navy-main); margin:0;">Statutory Bye-Elections</h4>
                        <span style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">RPA 1951 &bull; Section 151A</span>
                    </div>
                </div>
                <p style="font-size:0.83rem; color:var(--text-secondary); line-height:1.55; margin-bottom:12px; flex:1;">
                    Under <strong>Section 151A of the Representation of the People Act, 1951</strong>, bye-elections for any casual vacancy in Parliament or State Assemblies must be held within <strong>six months</strong> of the occurrence of the vacancy, except when the remaining term is less than 1 year.
                </p>
                <div style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:8px 10px; font-size:0.75rem; color:var(--navy-main);">
                    <strong>Status:</strong> ECI notifies bye-elections periodically via official Gazette notifications.
                </div>
            </div>
        </div>

        <!-- Official ECI Digital Citizen Services & Integrity Tools -->
        <div style="background:linear-gradient(135deg, #f8fafc 0%, #edf2f7 100%); border:1px solid var(--border-color); border-radius:var(--radius-md); padding:18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
                <div>
                    <h4 style="font-size:0.96rem; font-weight:700; color:var(--navy-main); margin:0; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-shield-halved" style="color:var(--navy-accent);"></i> Official ECI Election Tools &amp; Citizen Voter Services
                    </h4>
                    <span style="font-size:0.78rem; color:var(--text-muted);">Empowering voters with transparent affidavits, fast grievance redressal, and seamless digital registration</span>
                </div>
                <span class="badge-tag badge-verified" style="font-size:0.75rem;"><i class="fas fa-lock"></i> Official Government Gateways</span>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
                <a href="https://cvigil.eci.gov.in/" target="_blank" rel="noopener" style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:12px; text-decoration:none; display:flex; align-items:flex-start; gap:10px; transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.boxShadow='var(--shadow-md)'; this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='none'; this.style.transform='translateY(0)'">
                    <div style="width:32px; height:32px; border-radius:50%; background:var(--saffron-light); color:var(--saffron-dark); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas fa-camera"></i>
                    </div>
                    <div>
                        <strong style="color:var(--navy-main); font-size:0.86rem; display:block;">cVIGIL Citizen App</strong>
                        <span style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4; display:block; margin-top:2px;">Report MCC violations in real time with 100-min response guarantee.</span>
                    </div>
                </a>

                <a href="https://affidavit.eci.gov.in/" target="_blank" rel="noopener" style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:12px; text-decoration:none; display:flex; align-items:flex-start; gap:10px; transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.boxShadow='var(--shadow-md)'; this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='none'; this.style.transform='translateY(0)'">
                    <div style="width:32px; height:32px; border-radius:50%; background:var(--navy-light); color:var(--navy-accent); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div>
                        <strong style="color:var(--navy-main); font-size:0.86rem; display:block;">KYC &bull; Candidate Affidavits</strong>
                        <span style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4; display:block; margin-top:2px;">Inspect criminal antecedents, assets &amp; educational affidavits of candidates.</span>
                    </div>
                </a>

                <a href="https://suvidha.eci.gov.in/" target="_blank" rel="noopener" style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:12px; text-decoration:none; display:flex; align-items:flex-start; gap:10px; transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.boxShadow='var(--shadow-md)'; this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='none'; this.style.transform='translateY(0)'">
                    <div style="width:32px; height:32px; border-radius:50%; background:var(--green-light); color:var(--green-main); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <div>
                        <strong style="color:var(--navy-main); font-size:0.86rem; display:block;">Suvidha Candidate Portal</strong>
                        <span style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4; display:block; margin-top:2px;">Online candidate nomination, permissions &amp; rally clearances single-window.</span>
                    </div>
                </a>

                <a href="https://voters.eci.gov.in/" target="_blank" rel="noopener" style="background:#FFFFFF; border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:12px; text-decoration:none; display:flex; align-items:flex-start; gap:10px; transition:transform 0.15s, box-shadow 0.15s;" onmouseover="this.style.boxShadow='var(--shadow-md)'; this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='none'; this.style.transform='translateY(0)'">
                    <div style="width:32px; height:32px; border-radius:50%; background:var(--navy-light); color:var(--navy-accent); display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <div>
                        <strong style="color:var(--navy-main); font-size:0.86rem; display:block;">ECI Voters Portal &bull; Form 6</strong>
                        <span style="font-size:0.75rem; color:var(--text-secondary); line-height:1.4; display:block; margin-top:2px;">Register as a new voter, correct entries, or transfer polling station online.</span>
                    </div>
                </a>
            </div>

            <!-- 4 Statutory Qualifying Dates Strip -->
            <div style="margin-top:14px; padding:10px 14px; background:#FFFFFF; border-radius:var(--radius-sm); border:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="font-size:0.82rem; color:var(--navy-main); display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-bell" style="color:var(--saffron-main);"></i>
                    <span><strong>4 Statutory Voter Qualifying Dates:</strong> Youth turning 18 can enroll on <strong>1st January, 1st April, 1st July, or 1st October</strong> of every year.</span>
                </div>
                <a href="https://voters.eci.gov.in/" target="_blank" rel="noopener" class="btn-green-tiranga" style="font-size:0.75rem; padding:4px 10px; text-decoration:none;">
                    Enroll via Form 6 <i class="fas fa-arrow-right" style="font-size:0.7rem;"></i>
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


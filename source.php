<?php
// source.php - Official Public Data Sources, Attribution & Civic Disclaimer
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

// Live database dataset metrics for transparent source attribution
$stats = [
    'states' => (int)$pdo->query("SELECT COUNT(DISTINCT state_code) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
    'districts' => (int)$pdo->query("SELECT COUNT(DISTINCT district_code) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
    'blocks' => (int)$pdo->query("SELECT COUNT(*) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
    'mps' => (int)$pdo->query("SELECT COUNT(*) FROM mp")->fetchColumn(),
    'mp_ls' => (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'ls'")->fetchColumn(),
    'mp_rs' => (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE type = 'rs'")->fetchColumn(),
    'parties' => $pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM political_party")->fetchColumn()
        : 0,
    'parties_active' => $pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM political_party WHERE UPPER(TRIM(status)) = 'ACTIVE'")->fetchColumn()
        : 0,
    'mlas' => $pdo->query("SHOW TABLES LIKE 'politician'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM politician WHERE c_type = 'MLA' AND LOWER(TRIM(name)) NOT IN ('', 'vacant')")->fetchColumn()
        : 0,
    'mlcs' => $pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM mlc WHERE status = 'ACTIVE'")->fetchColumn()
        : ($pdo->query("SHOW TABLES LIKE 'politician'")->fetch()
            ? (int)$pdo->query("SELECT COUNT(*) FROM politician WHERE c_type = 'MLC' AND LOWER(TRIM(name)) NOT IN ('', 'vacant')")->fetchColumn()
            : 0),
    'mukhiyas' => $pdo->query("SHOW TABLES LIKE 'mukhiya'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM mukhiya")->fetchColumn()
        : 0,
    'villages' => $pdo->query("SHOW TABLES LIKE 'panchayat_village'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM panchayat_village")->fetchColumn()
        : 0,
    'panchayats' => $pdo->query("SHOW TABLES LIKE 'panchayat_village'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(DISTINCT panchayat_code) FROM panchayat_village WHERE TRIM(panchayat_name) <> ''")->fetchColumn()
        : 0,
    'leader_types' => $pdo->query("SHOW TABLES LIKE 'leader_types'")->fetch()
        ? (int)$pdo->query("SELECT COUNT(*) FROM leader_types")->fetchColumn()
        : 25,
];

$sourcesList = [
    [
        'id' => 'source-sansad',
        'name' => 'Digital Sansad — Parliament of India',
        'domain' => 'sansad.in',
        'url' => 'https://sansad.in/',
        'secondary_url' => 'https://sansad.in/ls',
        'secondary_label' => 'Lok Sabha Portal',
        'badge' => 'Union Legislature',
        'badge_class' => 'bg-indigo',
        'border_color' => 'var(--navy-accent)',
        'icon' => 'fas fa-landmark',
        'records_label' => number_format($stats['mps']) . ' MPs (' . number_format($stats['mp_ls']) . ' Lok Sabha + ' . number_format($stats['mp_rs']) . ' Rajya Sabha)',
        'scope' => 'Pan-India (All 36 States/UTs & Nominated)',
        'coverage' => '18th Lok Sabha (LS-18) & Rajya Sabha sitting/recent member rosters, constituencies, state codes, party affiliations, term tenures, member ages, and official portrait references.',
        'local_links' => [
            ['label' => 'Browse Parliament MPs', 'url' => get_site_url('elections#mp-directory')],
            ['label' => 'Lok Sabha & RS on Home', 'url' => get_site_url('index?cat=mp')],
        ],
    ],
    [
        'id' => 'source-neva',
        'name' => 'National e-Vidhan Application (NeVA) & State Legislatures',
        'domain' => 'neva.gov.in',
        'url' => 'https://neva.gov.in/',
        'secondary_url' => 'https://vidhansabha.bihar.gov.in/',
        'secondary_label' => 'State Legislature Portals',
        'badge' => 'State Assemblies & Councils',
        'badge_class' => 'bg-rose',
        'border_color' => '#E11D48',
        'icon' => 'fas fa-building-columns',
        'records_label' => number_format($stats['mlas'] + $stats['mlcs']) . ' State Legislators (' . number_format($stats['mlas']) . ' MLAs + ' . number_format($stats['mlcs']) . ' MLCs)',
        'scope' => 'Uttar Pradesh, Bihar & Andhra Pradesh Legislative Councils (Vidhan Parishad) + State Assemblies',
        'coverage' => 'Constituency-wise MLA & Council-wise MLC directories including 231 NeVA Vidhan Parishad profiles (Uttar Pradesh 99, Bihar 75, Andhra Pradesh 57), constitutional quotas (Article 171), party affiliations, tenures, education, and published legislative contact records under Digital India’s One Nation One Application framework.',
        'local_links' => [
            ['label' => 'Browse ' . number_format($stats['mlcs']) . ' MLCs (BR, UP & AP)', 'url' => get_site_url('elections#mlc-directory')],
            ['label' => 'MLCs on Home', 'url' => get_site_url('index?cat=mlc')],
            ['label' => 'Filter in Leaders Directory', 'url' => get_site_url('leaders?cat=mlc')],
        ],
    ],
    [
        'id' => 'source-eci',
        'name' => 'Election Commission of India (ECI) & Voters’ Portal',
        'domain' => 'eci.gov.in',
        'url' => 'https://eci.gov.in/',
        'secondary_url' => 'https://voters.eci.gov.in/',
        'secondary_label' => 'voters.eci.gov.in',
        'badge' => 'Constitutional Authority',
        'badge_class' => 'bg-amber',
        'border_color' => 'var(--saffron-main)',
        'icon' => 'fas fa-flag',
        'records_label' => number_format($stats['parties']) . ' Political Parties (' . number_format($stats['parties_active']) . ' Active • ECI 2024–2025 Notifications)',
        'scope' => 'Pan-India National, State Recognized & Unrecognized Parties (RUPPs)',
        'coverage' => 'Official ECI Notifications for National Parties (NP 23-03-2024), State Recognized Parties (SP 23-03-2024), RUPPs (27-03-2024 & S.O. 57(E) dated 10-10-2025), bilingual English/Hindi names & HQ addresses, reserved election symbol graphics, and National Voter Helpline 1950.',
        'local_links' => [
            ['label' => 'Political Parties Directory', 'url' => get_site_url('parties')],
            ['label' => 'Election & Voter Hub', 'url' => get_site_url('elections')],
        ],
    ],
    [
        'id' => 'source-affidavit',
        'name' => 'ECI Candidate Affidavits & State CEO Portals',
        'domain' => 'affidavit.eci.gov.in',
        'url' => 'https://affidavit.eci.gov.in/',
        'secondary_url' => 'https://www.eci.gov.in/ceo-links',
        'secondary_label' => 'All-India State CEOs',
        'badge' => 'Public Disclosures',
        'badge_class' => 'bg-purple',
        'border_color' => '#7C3AED',
        'icon' => 'fas fa-file-shield',
        'records_label' => '36 State & UT Chief Electoral Officer Directories',
        'scope' => 'All Parliamentary, Assembly & By-Election Nomination Filings',
        'coverage' => 'Publicly filed Form-26 candidate affidavits, educational backgrounds, financial asset declarations, polling station resources, and State Chief Electoral Officer links nationwide.',
        'local_links' => [
            ['label' => '3-Step Voter Check Guide', 'url' => get_site_url('elections')],
        ],
    ],
    [
        'id' => 'source-lgd',
        'name' => 'Local Government Directory (LGD) — Ministry of Panchayati Raj',
        'domain' => 'lgdirectory.gov.in',
        'url' => 'https://lgdirectory.gov.in/',
        'secondary_url' => 'https://panchayat.gov.in/',
        'secondary_label' => 'Ministry of Panchayati Raj',
        'badge' => 'Standard LGD Codes',
        'badge_class' => 'bg-emerald',
        'border_color' => 'var(--green-main)',
        'icon' => 'fas fa-map-location-dot',
        'records_label' => $stats['states'] . ' States/UTs • ' . number_format($stats['districts']) . ' Districts • ' . number_format($stats['blocks']) . ' Blocks • ' . number_format($stats['panchayats']) . ' GPs',
        'scope' => 'Pan-India States, Districts, Sub-Districts/Blocks, Gram Panchayats & Villages',
        'coverage' => 'Standardized LGD hierarchical mapping covering ' . number_format($stats['villages']) . ' village records powering nationwide State -> District -> Block -> Gram Panchayat location cascades.',
        'local_links' => [
            ['label' => 'Explore Local Leaders Directory', 'url' => get_site_url('index')],
            ['label' => 'PIN Code & Village Finder', 'url' => get_site_url('pincode')],
            ['label' => '25 Representative Types', 'url' => get_site_url('leaders')],
        ],
    ],
    [
        'id' => 'source-sec',
        'name' => 'State Election Commissions & eGramSwaraj Portals',
        'domain' => 'egramswaraj.gov.in',
        'url' => 'https://egramswaraj.gov.in/',
        'secondary_url' => 'https://sec.bihar.gov.in/',
        'secondary_label' => 'sec.bihar.gov.in',
        'badge' => 'Local Bodies & Panchayats',
        'badge_class' => 'bg-blue',
        'border_color' => '#0284C7',
        'icon' => 'fas fa-users-gear',
        'records_label' => number_format($stats['mukhiyas']) . ' Elected Gram Panchayat Mukhiyas & 3-Tier PRI Records',
        'scope' => 'Gram Panchayats, Panchayat Samitis, Zila Parishads & Urban Local Bodies',
        'coverage' => 'Grassroots local body head & member directories including State Election Commission Gram Panchayat Mukhiya rosters (names, Panchayats, Blocks, age, qualifications, and official contact details) plus Panchayati Raj governance taxonomies.',
        'local_links' => [
            ['label' => 'Browse Mukhiya Directory', 'url' => get_site_url('index?cat=mukhiya')],
            ['label' => 'Rural & Urban Governance Guides', 'url' => get_site_url('elections#explainers')],
        ],
    ],
];

$pageTitle = "Official Data Sources & Disclaimer";
$pageDescription = "Official public and government data sources powering LocalNeta — sansad.in, neva.gov.in, eci.gov.in, lgdirectory.gov.in, egramswaraj.gov.in, and independent platform disclaimer.";
require_once __DIR__ . '/includes/header.php';
?>

<main class="main-wrapper">

    <!-- Page Header -->
    <div class="tiranga-banner" style="margin-bottom:22px; flex-wrap:wrap; gap:14px;">
        <div>
            <h1 style="font-size:1.35rem; font-weight:800; color:#FFFFFF; margin:0 0 4px;">
                <i class="fas fa-database" style="color:var(--saffron-main);"></i> Data Sources, Attribution &amp; Civic Disclaimer
            </h1>
            <p style="margin:0;">
                Transparent documentation of official public portals, statutory notifications, and community-submitted records powering LocalNeta across India.
            </p>
        </div>
        <a href="<?= get_site_url('index') ?>" class="btn-primary-tiranga" style="padding:8px 16px; font-size:0.85rem;">
            <i class="fas fa-arrow-left"></i> Local Leaders Directory
        </a>
    </div>

    <!-- Prominent Platform Disclaimer Card -->
    <section id="disclaimer" style="background:#FFFBF7; border:1px solid #FDBA74; border-left:5px solid var(--saffron-main); border-radius:var(--radius-lg); padding:20px 22px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
        <div style="display:flex; gap:14px; align-items:flex-start;">
            <div style="width:42px; height:42px; border-radius:50%; background:var(--saffron-light); color:var(--saffron-main); display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0;">
                <i class="fas fa-scale-balanced"></i>
            </div>
            <div>
                <h2 style="font-size:1.08rem; font-weight:800; color:var(--navy-main); margin-bottom:6px;">
                    Independent Platform Disclaimer &amp; Non-Affiliation Notice
                </h2>
                <p style="font-size:0.9rem; color:var(--text-primary); line-height:1.65; margin-bottom:8px;">
                    <strong>Disclaimer:</strong> LocalNeta is an independent civic information platform and is <strong>not affiliated with, endorsed by, or an official portal of any political party or government entity</strong>. <strong>Local Neta (localneta.com)</strong> is an initiative of <strong>OfferPlant Technologies Private Limited</strong>. Community ratings, issue reports, development project updates, and survey results reflect voluntary citizen submissions and public participation.
                </p>
                <p style="font-size:0.84rem; color:var(--text-secondary); line-height:1.55; margin:0;">
                    All electoral boundaries, elected representative rosters, parliamentary profiles, and political party registrations are compiled strictly from publicly accessible government and constitutional sources listed below for non-partisan civic awareness. For statutory legal finality, always refer directly to the respective primary government portal.
                </p>
            </div>
        </div>
    </section>

    <!-- Live National Coverage Summary -->
    <section style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:24px;">
        <div style="background:#FFFFFF; padding:14px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid var(--saffron-main); box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">LGD Geographic Coverage</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--navy-main); margin-top:2px;"><?= $stats['states'] ?> States &amp; UTs</div>
            <div style="font-size:0.75rem; color:var(--text-secondary);"><?= number_format($stats['districts']) ?> Districts • <?= number_format($stats['blocks']) ?> Blocks</div>
        </div>
        <div style="background:#FFFFFF; padding:14px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid var(--navy-accent); box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">Digital Sansad (`mp`)</div>
            <div style="font-size:1.35rem; font-weight:800; color:var(--navy-accent); margin-top:2px;"><?= number_format($stats['mps']) ?> MPs</div>
            <div style="font-size:0.75rem; color:var(--text-secondary);"><?= number_format($stats['mp_ls']) ?> Lok Sabha • <?= number_format($stats['mp_rs']) ?> Rajya Sabha</div>
        </div>
        <div style="background:#FFFFFF; padding:14px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid #E11D48; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">State Legislatures</div>
            <div style="font-size:1.35rem; font-weight:800; color:#BE123C; margin-top:2px;"><?= number_format($stats['mlas'] + $stats['mlcs']) ?> Seats</div>
            <div style="font-size:0.75rem; color:var(--text-secondary);"><?= number_format($stats['mlas']) ?> MLAs • <?= number_format($stats['mlcs']) ?> MLCs</div>
        </div>
        <div style="background:#FFFFFF; padding:14px 16px; border-radius:var(--radius-md); border:1px solid var(--border-color); border-top:3px solid #7C3AED; box-shadow:var(--shadow-sm);">
            <div style="font-size:0.74rem; color:var(--text-muted); font-weight:700; text-transform:uppercase;">ECI Party Registry</div>
            <div style="font-size:1.35rem; font-weight:800; color:#5B21B6; margin-top:2px;"><?= number_format($stats['parties']) ?> Parties</div>
            <div style="font-size:0.75rem; color:var(--text-secondary);"><?= number_format($stats['parties_active']) ?> Active • Bilingual EN/HI</div>
        </div>
    </section>

    <!-- Primary Public & Government Sources Grid -->
    <section style="margin-bottom:28px;">
        <div class="section-header" style="flex-wrap:wrap; gap:10px;">
            <h2 class="section-title"><i class="fas fa-book-bookmark"></i> Verified Official Data Sources</h2>
            <span class="badge-tag badge-elected"><i class="fas fa-check-circle"></i> Public Government &amp; Constitutional Portals</span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(330px, 1fr)); gap:18px;">
            <?php foreach ($sourcesList as $src): ?>
                <article id="<?= e($src['id']) ?>" style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid <?= e($src['border_color']) ?>; padding:20px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; gap:12px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="width:42px; height:42px; border-radius:var(--radius-md); background:var(--navy-light); color:var(--navy-main); display:flex; align-items:center; justify-content:center; font-size:1.15rem; flex-shrink:0;">
                                <i class="<?= e($src['icon']) ?>"></i>
                            </div>
                            <div>
                                <h3 style="font-size:1.05rem; font-weight:800; color:var(--navy-main); line-height:1.25; margin:0;">
                                    <?= e($src['name']) ?>
                                </h3>
                                <a href="<?= e($src['url']) ?>" target="_blank" rel="noopener" style="font-size:0.82rem; font-weight:700; color:var(--saffron-main); text-decoration:none;">
                                    <?= e($src['domain']) ?> <i class="fas fa-external-link-alt" style="font-size:0.68rem;"></i>
                                </a>
                            </div>
                        </div>
                        <span class="cat-pill <?= e($src['badge_class']) ?>" style="flex-shrink:0;"><?= e($src['badge']) ?></span>
                    </div>

                    <div style="background:var(--white-surface); border:1px solid var(--border-light); border-radius:var(--radius-sm); padding:8px 12px; font-size:0.8rem;">
                        <div style="color:var(--navy-main); font-weight:700;"><i class="fas fa-layer-group" style="color:var(--green-main); width:16px;"></i> <?= e($src['records_label']) ?></div>
                        <div style="color:var(--text-muted); margin-top:2px;"><i class="fas fa-globe" style="color:var(--navy-accent); width:16px;"></i> Scope: <?= e($src['scope']) ?></div>
                    </div>

                    <p style="font-size:0.86rem; color:var(--text-secondary); line-height:1.55; margin:0;">
                        <?= e($src['coverage']) ?>
                    </p>

                    <div style="margin-top:auto; padding-top:10px; border-top:1px solid var(--border-light); display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <a href="<?= e($src['url']) ?>" target="_blank" rel="noopener" class="btn-primary-tiranga" style="padding:6px 14px; font-size:0.8rem;">
                            Visit <?= e($src['domain']) ?> <i class="fas fa-external-link-alt" style="font-size:0.68rem;"></i>
                        </a>
                        <?php if (!empty($src['secondary_url'])): ?>
                            <a href="<?= e($src['secondary_url']) ?>" target="_blank" rel="noopener" class="btn-profile-view" style="flex:unset; padding:6px 12px; font-size:0.78rem;">
                                <?= e($src['secondary_label']) ?> <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i>
                            </a>
                        <?php endif; ?>
                        <?php foreach ($src['local_links'] as $locLnk): ?>
                            <a href="<?= e($locLnk['url']) ?>" class="btn-profile-view" style="flex:unset; padding:6px 12px; font-size:0.78rem; background:var(--green-light); color:var(--green-dark);">
                                <?= e($locLnk['label']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Community Submissions vs Official Records Matrix -->
    <section id="source-community" style="background:#FFFFFF; border-radius:var(--radius-lg); border:1px solid var(--border-color); border-top:4px solid var(--green-main); padding:22px; margin-bottom:28px; box-shadow:var(--shadow-sm);">
        <div class="section-header" style="flex-wrap:wrap; gap:10px;">
            <div>
                <h2 class="section-title"><i class="fas fa-users"></i> Data Separation: Statutory Records vs. Community Submissions</h2>
                <p style="font-size:0.84rem; color:var(--text-muted); margin-top:2px;">
                    Clear distinction between public government directories and voluntary citizen inputs
                </p>
            </div>
            <span class="badge-tag badge-verified">IT Act Section 79 Intermediary Notice</span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.86rem; text-align:left;">
                <thead>
                    <tr style="border-bottom:2px solid var(--border-color); background:var(--white-surface); color:var(--navy-main);">
                        <th style="padding:10px;">Dataset / Feature on LocalNeta</th>
                        <th style="padding:10px;">Origin &amp; Attribution</th>
                        <th style="padding:10px;">Source Verification Link</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom:1px solid var(--border-light);">
                        <td style="padding:10px; font-weight:700; color:var(--navy-main);">States, Districts, Blocks &amp; Gram Panchayats</td>
                        <td style="padding:10px; color:var(--text-secondary);">Ministry of Panchayati Raj Local Government Directory (LGD Codes)</td>
                        <td style="padding:10px;"><a href="https://lgdirectory.gov.in/" target="_blank" rel="noopener" style="color:var(--green-main); font-weight:700;">lgdirectory.gov.in <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-light);">
                        <td style="padding:10px; font-weight:700; color:var(--navy-main);">Lok Sabha (LS-18) &amp; Rajya Sabha Members (MPs)</td>
                        <td style="padding:10px; color:var(--text-secondary);">Parliament of India Digital Sansad member rosters</td>
                        <td style="padding:10px;"><a href="https://sansad.in/" target="_blank" rel="noopener" style="color:var(--green-main); font-weight:700;">sansad.in <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-light);">
                        <td style="padding:10px; font-weight:700; color:var(--navy-main);">State Assembly (MLA) &amp; Council (MLC) Representatives</td>
                        <td style="padding:10px; color:var(--text-secondary);">National e-Vidhan Application (NeVA) &amp; State Legislature directories</td>
                        <td style="padding:10px;"><a href="https://neva.gov.in/" target="_blank" rel="noopener" style="color:var(--green-main); font-weight:700;">neva.gov.in <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-light);">
                        <td style="padding:10px; font-weight:700; color:var(--navy-main);">Political Party Names (EN/HI), Status &amp; Reserved Symbols</td>
                        <td style="padding:10px; color:var(--text-secondary);">Election Commission of India Notifications (2024–2025) &amp; Symbol Compendiums</td>
                        <td style="padding:10px;"><a href="https://eci.gov.in/" target="_blank" rel="noopener" style="color:var(--green-main); font-weight:700;">eci.gov.in <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a></td>
                    </tr>
                    <tr style="border-bottom:1px solid var(--border-light);">
                        <td style="padding:10px; font-weight:700; color:var(--navy-main);">Gram Panchayat Mukhiyas &amp; Rural Local Body Heads</td>
                        <td style="padding:10px; color:var(--text-secondary);">State Election Commission &amp; Panchayati Raj public candidate rosters</td>
                        <td style="padding:10px;">
                            <a href="https://egramswaraj.gov.in/" target="_blank" rel="noopener" style="color:var(--green-main); font-weight:700;">egramswaraj.gov.in <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a> •
                            <a href="https://sec.bihar.gov.in/" target="_blank" rel="noopener" style="color:var(--green-main); font-weight:700;">sec.bihar.gov.in <i class="fas fa-external-link-alt" style="font-size:0.66rem;"></i></a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:10px; font-weight:700; color:var(--navy-main);">Citizen Ratings, Issue Reports, Q&amp;A &amp; Polls</td>
                        <td style="padding:10px; color:var(--text-secondary);">Voluntary public submissions posted by citizens &amp; verified representatives under IT Act Section 79 intermediary safe-harbor rules.</td>
                        <td style="padding:10px;"><span class="badge-tag badge-unverified">Voluntary User Submissions</span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px; background:var(--white-surface); border-left:4px solid var(--navy-main); padding:12px 16px; border-radius:var(--radius-sm); font-size:0.84rem; color:var(--text-secondary); line-height:1.6;">
            <strong>Correction &amp; Update Policy:</strong> Electoral statuses (sitting, resigned, delisted, or newly elected representatives) change periodically following by-elections and ECI notifications. Representatives, researchers, or citizens wishing to flag a clerical correction backed by a public Gazette or official portal notification may submit an update note referencing the official source document URL.
        </div>
    </section>

</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


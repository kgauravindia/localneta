<?php
// ppms/index.php - Dashboard Overview for LocalNeta PPMS (Parties, MPs, MLCs, Villages & XML Sitemaps)
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();
$summary = ppms_party_summary($pdo);
$ppmsTotalParties = $summary['total_rows'];
$mpSummary = ppms_mp_summary($pdo);
$ppmsTotalMps = $mpSummary['total_rows'];
$mlcSummary = ppms_mlc_summary($pdo);
$ppmsTotalMlcs = $mlcSummary['total_rows'];
$ppmsVillageStats = ppms_village_summary($pdo);
$sitemapStats = ppms_sitemap_stats($pdo);
$catalog = ppms_get_symbol_catalog();

// Top States by party count
$topStates = $pdo->query("
    SELECT pp.state_code,
           COALESCE(s.state, pp.state) AS display_name,
           COUNT(*) AS total_cnt,
           SUM(UPPER(TRIM(pp.status)) = 'ACTIVE') AS active_cnt,
           SUM(UPPER(TRIM(pp.status)) = 'DELISTED') AS delisted_cnt
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
    WHERE TRIM(pp.type) <> 'National'
    GROUP BY pp.state_code, COALESCE(s.state, pp.state)
    ORDER BY total_cnt DESC
    LIMIT 10
")->fetchAll();

// Recently modified parties
$recentParties = $pdo->query("
    SELECT pp.id, pp.name, pp.name_hi, pp.slug, pp.type, pp.status, pp.symbol, pp.state, pp.state_code, pp.updated_at,
           COALESCE(s.state, pp.state) AS state_display
    FROM political_party pp
    LEFT JOIN (SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE') s
           ON pp.state_code = s.state_code
    ORDER BY pp.updated_at DESC, pp.id DESC
    LIMIT 8
")->fetchAll();

// Recent PPMS audit logs
$recentLogs = $pdo->query("
    SELECT * FROM ppms_audit_logs
    ORDER BY created_at DESC, id DESC
    LIMIT 8
")->fetchAll();

$totalSafe = max(1, (int)$summary['total_rows']);
$symPct = round(($summary['symbol_count'] / $totalSafe) * 100, 1);
$hiPct = round(($summary['hindi_name_count'] / $totalSafe) * 100, 1);
$urlPct = round(($summary['url_count'] / $totalSafe) * 100, 1);
$contactPct = round((($summary['contact_count'] ?? 0) / $totalSafe) * 100, 1);
$socialPct = round((($summary['social_count'] ?? 0) / $totalSafe) * 100, 1);

$entityEditRoute = static function (string $entityType, int $entityId): ?string {
    if ($entityId <= 0) {
        return null;
    }
    return match ($entityType) {
        'party' => get_site_url('ppms/party-form?id=' . $entityId),
        'mp' => get_site_url('ppms/mp-form?id=' . $entityId),
        'mlc' => get_site_url('ppms/mlc-form?id=' . $entityId),
        'village' => get_site_url('ppms/village-form?id=' . $entityId),
        default => null,
    };
};

$activeNav = 'dashboard';
$ppmsPageTitle = 'PPMS Dashboard';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Welcome + Quick Search Banner -->
<div class="ppms-card" style="border-top:4px solid var(--ppms-saffron);">
    <div class="ppms-card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2 class="h5 fw-bold mb-1 d-flex flex-wrap align-items-center gap-2" style="color:var(--ppms-navy);">
                <span>Political Party Management System (PPMS)</span>
                <?php if (($adminUser['role'] ?? '') === 'superadmin'):
                    require_once __DIR__ . '/../includes/vcs.php';
                    $dashAppVer = (string)(localneta_app_version()['version'] ?? '1.2.0');
                ?>
                    <a href="<?= e(get_site_url('ppms/updates')) ?>" class="badge bg-dark text-decoration-none" style="font-size:0.72rem;" title="Manage Version Control &amp; GitHub Updates">
                        <i class="fab fa-github me-1"></i>v<?= e($dashAppVer) ?> &bull; GitHub Sync
                    </a>
                <?php endif; ?>
            </h2>
            <p class="text-muted small mb-0">
                Centralized administration for <strong><?= number_format($summary['total_rows']) ?></strong> Political Parties, <strong><?= number_format($mpSummary['total_rows']) ?></strong> MPs, <strong><?= number_format($mlcSummary['total_rows']) ?></strong> MLCs, <strong><?= number_format($ppmsVillageStats['total_rows']) ?></strong> Villages &amp; <strong><?= number_format($sitemapStats['grand_total']) ?></strong> Sitemap URLs across <strong><?= (int)$summary['states_covered'] ?></strong> States/UTs and <strong><?= count($catalog) ?></strong> symbol assets.
            </p>
        </div>

        <form action="<?= e(get_site_url('ppms/parties')) ?>" method="GET" class="d-flex gap-2 flex-grow-1" style="max-width:440px;">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Quick search party name, हिंदी, symbol, slug or HQ...">
            <button type="submit" class="btn btn-ppms-navy btn-sm px-3 text-nowrap">
                <i class="fas fa-search me-1"></i> Search
            </button>
        </form>
    </div>
</div>

<!-- Party KPI Row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(get_site_url('ppms/parties')) ?>" class="text-decoration-none d-block h-100">
            <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-navy);">
                <div class="ppms-kpi-top">
                    <span class="ppms-kpi-label">Total Parties</span>
                    <span class="ppms-kpi-icon" style="background:#EEF2FF; color:var(--ppms-navy);"><i class="fas fa-database"></i></span>
                </div>
                <div class="ppms-kpi-value"><?= number_format($summary['total_rows']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(get_site_url('ppms/parties?status=ACTIVE')) ?>" class="text-decoration-none d-block h-100">
            <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-green);">
                <div class="ppms-kpi-top">
                    <span class="ppms-kpi-label">Active</span>
                    <span class="ppms-kpi-icon" style="background:#EAF7F0; color:var(--ppms-green);"><i class="fas fa-check-circle"></i></span>
                </div>
                <div class="ppms-kpi-value" style="color:var(--ppms-green);"><?= number_format($summary['active_count']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(get_site_url('ppms/parties?type=National')) ?>" class="text-decoration-none d-block h-100">
            <div class="ppms-kpi-card" style="border-top:3px solid #4F46E5;">
                <div class="ppms-kpi-top">
                    <span class="ppms-kpi-label">National</span>
                    <span class="ppms-kpi-icon" style="background:#EEF2FF; color:#4F46E5;"><i class="fas fa-landmark"></i></span>
                </div>
                <div class="ppms-kpi-value" style="color:#3730A3;"><?= number_format($summary['national_count']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(get_site_url('ppms/parties?type=State')) ?>" class="text-decoration-none d-block h-100">
            <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-saffron);">
                <div class="ppms-kpi-top">
                    <span class="ppms-kpi-label">State Parties</span>
                    <span class="ppms-kpi-icon" style="background:#FFF3EB; color:var(--ppms-saffron);"><i class="fas fa-award"></i></span>
                </div>
                <div class="ppms-kpi-value" style="color:var(--ppms-saffron-dark);"><?= number_format($summary['state_count']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(get_site_url('ppms/parties?status=INACTIVE')) ?>" class="text-decoration-none d-block h-100">
            <div class="ppms-kpi-card" style="border-top:3px solid #D97706;">
                <div class="ppms-kpi-top">
                    <span class="ppms-kpi-label">Inactive</span>
                    <span class="ppms-kpi-icon" style="background:#FEF3C7; color:#B45309;"><i class="fas fa-pause-circle"></i></span>
                </div>
                <div class="ppms-kpi-value" style="color:#92400E;"><?= number_format($summary['inactive_count']) ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a href="<?= e(get_site_url('ppms/parties?status=DELISTED')) ?>" class="text-decoration-none d-block h-100">
            <div class="ppms-kpi-card" style="border-top:3px solid #DC2626;">
                <div class="ppms-kpi-top">
                    <span class="ppms-kpi-label">Delisted</span>
                    <span class="ppms-kpi-icon" style="background:#FEE2E2; color:#DC2626;"><i class="fas fa-ban"></i></span>
                </div>
                <div class="ppms-kpi-value" style="color:#B91C1C;"><?= number_format($summary['delisted_count']) ?></div>
            </div>
        </a>
    </div>
</div>

<!-- Parliament, Legislative Council, Villages & XML Sitemap Hub Row -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid #E11D48;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Parliament MPs</span>
                <span class="ppms-kpi-icon" style="background:#FFF1F2; color:#E11D48;"><i class="fas fa-landmark"></i></span>
            </div>
            <div class="ppms-kpi-value"><?= number_format($mpSummary['total_rows']) ?></div>
            <div class="small text-muted mt-2 mb-3">
                <strong><?= number_format($mpSummary['ls_count']) ?></strong> Lok Sabha &bull;
                <strong><?= number_format($mpSummary['rs_count']) ?></strong> Rajya Sabha &bull;
                <strong><?= number_format($mpSummary['sitting_count']) ?></strong> Sitting
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(get_site_url('ppms/mps')) ?>" class="btn btn-outline-primary btn-sm flex-fill fw-semibold">Manage MPs</a>
                <a href="<?= e(get_site_url('ppms/mp-form')) ?>" class="btn btn-ppms-saffron btn-sm" title="Add New MP"><i class="fas fa-plus"></i></a>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid #7C3AED;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Council MLCs</span>
                <span class="ppms-kpi-icon" style="background:#F5F3FF; color:#7C3AED;"><i class="fas fa-scroll"></i></span>
            </div>
            <div class="ppms-kpi-value"><?= number_format($mlcSummary['total_rows']) ?></div>
            <div class="small text-muted mt-2 mb-3">
                <strong><?= number_format($mlcSummary['up_count']) ?></strong> UP &bull;
                <strong><?= number_format($mlcSummary['br_count']) ?></strong> Bihar &bull;
                <strong><?= number_format($mlcSummary['ap_count']) ?></strong> AP (<?= number_format($mlcSummary['active_count']) ?> Active)
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(get_site_url('ppms/mlcs')) ?>" class="btn btn-outline-primary btn-sm flex-fill fw-semibold">Manage MLCs</a>
                <a href="<?= e(get_site_url('ppms/mlc-form')) ?>" class="btn btn-ppms-saffron btn-sm" title="Add New MLC"><i class="fas fa-plus"></i></a>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid var(--ppms-green);">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">Villages &amp; PINs</span>
                <span class="ppms-kpi-icon" style="background:#EAF7F0; color:var(--ppms-green);"><i class="fas fa-map-pin"></i></span>
            </div>
            <div class="ppms-kpi-value"><?= number_format($ppmsVillageStats['total_rows']) ?></div>
            <div class="small text-muted mt-2 mb-3">
                <strong><?= number_format($ppmsVillageStats['distinct_pincodes']) ?></strong> PIN Codes &bull;
                <strong><?= number_format($ppmsVillageStats['sdb_blocks']) ?></strong> LGD Blocks
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(get_site_url('ppms/villages')) ?>" class="btn btn-outline-success btn-sm flex-fill fw-semibold">Manage Villages</a>
                <a href="<?= e(get_site_url('ppms/village-form')) ?>" class="btn btn-ppms-green btn-sm" title="Add Village"><i class="fas fa-plus"></i></a>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="ppms-kpi-card" style="border-top:3px solid #0284C7;">
            <div class="ppms-kpi-top">
                <span class="ppms-kpi-label">XML Sitemap (All)</span>
                <span class="ppms-kpi-icon" style="background:#E0F2FE; color:#0284C7;"><i class="fas fa-sitemap"></i></span>
            </div>
            <div class="ppms-kpi-value"><?= number_format($sitemapStats['grand_total']) ?></div>
            <div class="small text-muted mt-2 mb-3">
                Core, Districts, MPs, MLCs, Parties &amp; <strong><?= number_format($sitemapStats['pincodes']) ?></strong> PINs
            </div>
            <div class="d-flex gap-2">
                <a href="<?= e(get_site_url('ppms/sitemaps')) ?>" class="btn btn-outline-primary btn-sm flex-fill fw-semibold">Sitemap Hub</a>
                <a href="<?= e(get_site_url('sitemap.xml')) ?>" target="_blank" rel="noopener" class="btn btn-ppms-navy btn-sm" title="Open sitemap.xml"><i class="fas fa-external-link-alt"></i></a>
            </div>
        </div>
    </div>
</div>

<!-- Middle Grid: Top States + Data Quality + Actions -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-map-marker-alt text-warning"></i> Top States by Political Party Count</h3>
                <a href="<?= e(get_site_url('ppms/states')) ?>" class="btn btn-light btn-sm border fw-semibold">All States <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
            <div class="table-responsive">
                <table class="table ppms-table mb-0">
                    <thead>
                        <tr>
                            <th>State / UT</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Active</th>
                            <th class="text-end">Delisted</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topStates as $st): ?>
                            <tr>
                                <td class="fw-semibold text-dark"><?= e(clean_party_display_text($st['display_name'])) ?></td>
                                <td class="text-end fw-bold"><?= number_format((int)$st['total_cnt']) ?></td>
                                <td class="text-end text-success fw-semibold"><?= number_format((int)$st['active_cnt']) ?></td>
                                <td class="text-end text-danger fw-semibold"><?= number_format((int)$st['delisted_cnt']) ?></td>
                                <td class="text-end">
                                    <a href="<?= e(get_site_url('ppms/parties?state_code=' . urlencode((string)$st['state_code']))) ?>" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.74rem;">
                                        Filter
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="ppms-card mb-3">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-sliders-h text-success"></i> Quick Actions &amp; Completeness</h3>
            </div>
            <div class="ppms-card-body">
                <div class="d-grid gap-2 d-sm-flex mb-4">
                    <a href="<?= e(get_site_url('ppms/party-form')) ?>" class="btn btn-ppms-saffron btn-sm flex-fill py-2">
                        <i class="fas fa-plus-circle me-1"></i> Add Political Party
                    </a>
                    <a href="<?= e(get_site_url('ppms/parties?type=National')) ?>" class="btn btn-ppms-green btn-sm flex-fill py-2">
                        <i class="fas fa-flag me-1"></i> National Parties (<?= (int)$summary['national_count'] ?>)
                    </a>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <span>Hindi Name Coverage (<code>name_hi</code>)</span>
                        <span><?= number_format($summary['hindi_name_count']) ?> (<?= $hiPct ?>%)</span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-success" style="width: <?= $hiPct ?>%;"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <span>Reserved Symbol Assigned (<code>symbol</code>)</span>
                        <span><?= number_format($summary['symbol_count']) ?> (<?= $symPct ?>%)</span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" style="width: <?= $symPct ?>%; background:var(--ppms-saffron);"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <span>Official Website Added (<code>url</code>)</span>
                        <span><?= number_format($summary['url_count']) ?> (<?= $urlPct ?>%)</span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar" style="width: <?= $urlPct ?>%; background:var(--ppms-navy);"></div>
                    </div>
                </div>

                <div>
                    <div class="d-flex justify-content-between small fw-semibold mb-1">
                        <a href="<?= e(get_site_url('ppms/parties?has_contact=1')) ?>" class="text-decoration-none text-dark">Contact &amp; Social Profiles</a>
                        <span><?= number_format($summary['contact_count'] ?? 0) ?> Contact &bull; <?= number_format($summary['social_count'] ?? 0) ?> Social</span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-info" style="width: <?= max($contactPct, $socialPct) ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ppms-card mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-history text-primary"></i> Recent Audit Activity</h3>
                <a href="<?= e(get_site_url('ppms/logs')) ?>" class="btn btn-light btn-sm border fw-semibold">View All</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($recentLogs)): ?>
                    <div class="list-group-item text-muted small py-3 text-center">No recent administrative changes logged yet.</div>
                <?php else: ?>
                    <?php foreach ($recentLogs as $lg):
                        $eType = strtolower((string)($lg['entity_type'] ?? 'party')) ?: 'party';
                        $eId = (int)(($lg['entity_id'] ?? 0) ?: ($lg['party_id'] ?? 0));
                        $eHref = ($lg['action'] ?? '') !== 'DELETE' ? $entityEditRoute($eType, $eId) : null;
                    ?>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-light text-dark border" style="font-size:0.68rem;"><?= e($lg['action']) ?></span>
                                    <span class="badge bg-secondary-subtle text-secondary border" style="font-size:0.66rem;"><?= e(strtoupper($eType)) ?></span>
                                </div>
                                <small class="text-muted" style="font-size:0.72rem;"><?= e(date('d M, H:i', strtotime((string)$lg['created_at']))) ?></small>
                            </div>
                            <div class="small fw-semibold text-dark mt-1">
                                <?php if ($eHref): ?>
                                    <a href="<?= e($eHref) ?>" class="text-decoration-none text-dark"><?= e($lg['party_name'] ?: ($lg['details'] ?: $lg['action'])) ?></a>
                                <?php else: ?>
                                    <?= e($lg['party_name'] ?: ($lg['details'] ?: $lg['action'])) ?>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted" style="font-size:0.72rem;">By @<?= e($lg['username']) ?> <?= $lg['details'] && $lg['party_name'] ? '&bull; ' . e($lg['details']) : '' ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recently Updated Parties -->
<div class="ppms-card mb-0">
    <div class="ppms-card-header">
        <h3 class="ppms-card-title"><i class="fas fa-clock text-secondary"></i> Recently Updated Party Records</h3>
        <a href="<?= e(get_site_url('ppms/parties')) ?>" class="btn btn-light btn-sm border fw-semibold">Manage All <?= number_format($summary['total_rows']) ?> Parties</a>
    </div>
    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Party Name</th>
                    <th>Type</th>
                    <th>Symbol</th>
                    <th>State / Scope</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentParties as $rp):
                    $sym = get_party_symbol_image($rp['symbol'] ?? '');
                    $rType = clean_party_display_text($rp['type']) ?: 'Registered';
                    $rStatus = strtoupper(clean_party_display_text($rp['status']) ?: 'ACTIVE');
                    $typeCls = strcasecmp($rType, 'National') === 0 ? 'ppms-badge-national' : (strcasecmp($rType, 'State') === 0 ? 'ppms-badge-state' : 'ppms-badge-registered');
                    $statCls = $rStatus === 'ACTIVE' ? 'ppms-badge-active' : ($rStatus === 'DELISTED' ? 'ppms-badge-delisted' : 'ppms-badge-inactive');
                    $scopeTxt = strcasecmp($rType, 'National') === 0 ? 'All India' : clean_party_display_text($rp['state_display']);
                ?>
                    <tr>
                        <td class="fw-bold text-muted">#<?= (int)$rp['id'] ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= e(clean_party_display_name($rp['name'])) ?></div>
                            <?php if (!empty($rp['name_hi'])): ?>
                                <div class="small text-success lang-hi" lang="hi"><?= e(clean_party_display_text($rp['name_hi'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="ppms-badge <?= $typeCls ?>"><?= e($rType) ?></span></td>
                        <td>
                            <?php if ($sym): ?>
                                <div class="d-flex align-items-center gap-2">
                                    <?php if (!empty($sym['url'])): ?>
                                        <img src="<?= e($sym['url']) ?>" alt="<?= e($sym['label']) ?>" class="ppms-sym-thumb">
                                    <?php endif; ?>
                                    <span class="small fw-semibold"><?= e($sym['label']) ?></span>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small">Unreserved</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-secondary"><?= e($scopeTxt) ?></td>
                        <td><span class="ppms-badge <?= $statCls ?>"><?= e($rStatus) ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(get_site_url('ppms/party-form?id=' . (int)$rp['id'])) ?>" class="btn btn-outline-primary btn-sm py-0 px-2">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <a href="<?= e(get_party_page_url((string)$rp['slug'], (int)$rp['id'])) ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm py-0 px-2">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_bottom.php'; ?>

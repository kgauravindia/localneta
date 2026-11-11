<?php
// ppms/includes/layout_top.php - Authenticated PPMS Sidebar & Topbar Shell
if (!defined('PPMS_INIT') || !isset($adminUser)) {
    http_response_code(403);
    exit('Forbidden');
}

$activeNav = $activeNav ?? 'dashboard';
$ppmsPageTitle = $ppmsPageTitle ?? 'Dashboard';
$ppmsTotalParties = $ppmsTotalParties ?? (int)$pdo->query("SELECT COUNT(*) FROM political_party")->fetchColumn();
$ppmsTotalMps = $ppmsTotalMps ?? (int)$pdo->query("SELECT COUNT(*) FROM mp")->fetchColumn();
$ppmsTotalMlcs = $ppmsTotalMlcs ?? (int)$pdo->query("SELECT COUNT(*) FROM mlc")->fetchColumn();
$ppmsVillageStats = $ppmsVillageStats ?? ppms_village_summary($pdo);
$ppmsTotalVillages = (int)$ppmsVillageStats['total_rows'];
$ppmsFlash = ppms_get_flash();
$ppmsCsrf = ppms_csrf_token();
$logoUrl = get_site_url('localneta.png');
$adminDisplayName = $adminUser['full_name'] ?: $adminUser['username'];
$adminInitial = strtoupper(substr($adminDisplayName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($ppmsPageTitle) ?> | LocalNeta PPMS</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(get_site_url('assets/images/favicon-32x32.png')) ?>?v=<?= @filemtime(__DIR__ . '/../../assets/images/favicon-32x32.png') ?: 1 ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e(get_site_url('assets/images/favicon-192x192.png')) ?>?v=<?= @filemtime(__DIR__ . '/../../assets/images/favicon-192x192.png') ?: 1 ?>">
    <link rel="icon" href="<?= e(get_site_url('favicon.ico')) ?>?v=<?= @filemtime(__DIR__ . '/../../favicon.ico') ?: 1 ?>" sizes="any">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(get_site_url('assets/images/apple-touch-icon.png')) ?>?v=<?= @filemtime(__DIR__ . '/../../assets/images/apple-touch-icon.png') ?: 1 ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind:wght@400;500;600;700&family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(get_site_url('ppms/assets/ppms.css')) ?>?v=<?= @filemtime(__DIR__ . '/../assets/ppms.css') ?: 1 ?>">
</head>
<body>
<div class="ppms-tiranga-bar"></div>
<div class="ppms-backdrop" id="ppmsBackdrop" onclick="togglePpmsSidebar(false)"></div>

<div class="ppms-shell">
    <aside class="ppms-sidebar" id="ppmsSidebar">
        <a href="<?= e(get_site_url('ppms')) ?>" class="ppms-brand-box">
            <img src="<?= e($logoUrl) ?>" alt="LocalNeta Emblem">
            <div>
                <div class="ppms-brand-title">Local<span>Neta</span> PPMS</div>
                <div class="ppms-brand-sub">Political Party Mgmt</div>
            </div>
        </a>

        <nav class="ppms-nav">
            <div class="ppms-nav-heading">Overview</div>
            <a href="<?= e(get_site_url('ppms')) ?>" class="ppms-nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
                <i class="fas fa-chart-pie"></i>
                <span>Dashboard</span>
            </a>
            <a href="<?= e(get_site_url('ppms/states')) ?>" class="ppms-nav-link <?= $activeNav === 'states' ? 'active' : '' ?>">
                <i class="fas fa-map-marked-alt"></i>
                <span>States &amp; UTs</span>
            </a>

            <div class="ppms-nav-heading">Political Parties</div>
            <a href="<?= e(get_site_url('ppms/parties')) ?>" class="ppms-nav-link <?= $activeNav === 'parties' ? 'active' : '' ?>">
                <i class="fas fa-flag"></i>
                <span>All Parties</span>
                <span class="ppms-nav-badge"><?= number_format($ppmsTotalParties) ?></span>
            </a>
            <a href="<?= e(get_site_url('ppms/party-form')) ?>" class="ppms-nav-link <?= $activeNav === 'party-form' ? 'active' : '' ?>">
                <i class="fas fa-plus-circle"></i>
                <span>Add New Party</span>
            </a>
            <a href="<?= e(get_site_url('ppms/symbols')) ?>" class="ppms-nav-link <?= $activeNav === 'symbols' ? 'active' : '' ?>">
                <i class="fas fa-icons"></i>
                <span>Election Symbols</span>
            </a>

            <div class="ppms-nav-heading">Parliament &amp; Council</div>
            <a href="<?= e(get_site_url('ppms/mps')) ?>" class="ppms-nav-link <?= $activeNav === 'mps' ? 'active' : '' ?>">
                <i class="fas fa-landmark"></i>
                <span>All MPs</span>
                <span class="ppms-nav-badge"><?= number_format($ppmsTotalMps) ?></span>
            </a>
            <a href="<?= e(get_site_url('ppms/mp-form')) ?>" class="ppms-nav-link <?= $activeNav === 'mp-form' ? 'active' : '' ?>">
                <i class="fas fa-user-plus"></i>
                <span>Add New MP</span>
            </a>
            <a href="<?= e(get_site_url('ppms/mlcs')) ?>" class="ppms-nav-link <?= $activeNav === 'mlcs' ? 'active' : '' ?>">
                <i class="fas fa-scroll"></i>
                <span>All MLCs</span>
                <span class="ppms-nav-badge"><?= number_format($ppmsTotalMlcs) ?></span>
            </a>
            <a href="<?= e(get_site_url('ppms/mlc-form')) ?>" class="ppms-nav-link <?= $activeNav === 'mlc-form' ? 'active' : '' ?>">
                <i class="fas fa-user-tie"></i>
                <span>Add New MLC</span>
            </a>

            <div class="ppms-nav-heading">Villages &amp; SEO Sitemap</div>
            <a href="<?= e(get_site_url('ppms/villages')) ?>" class="ppms-nav-link <?= $activeNav === 'villages' ? 'active' : '' ?>">
                <i class="fas fa-map-pin"></i>
                <span>Villages &amp; PINs</span>
                <span class="ppms-nav-badge"><?= number_format($ppmsTotalVillages) ?></span>
            </a>
            <a href="<?= e(get_site_url('ppms/village-form')) ?>" class="ppms-nav-link <?= $activeNav === 'village-form' ? 'active' : '' ?>">
                <i class="fas fa-plus-square"></i>
                <span>Add Village / PIN</span>
            </a>
            <a href="<?= e(get_site_url('ppms/sitemaps')) ?>" class="ppms-nav-link <?= $activeNav === 'sitemaps' ? 'active' : '' ?>">
                <i class="fas fa-sitemap"></i>
                <span>XML Sitemap (All)</span>
            </a>

            <div class="ppms-nav-heading">System &amp; Security</div>
            <a href="<?= e(get_site_url('ppms/logs')) ?>" class="ppms-nav-link <?= $activeNav === 'logs' ? 'active' : '' ?>">
                <i class="fas fa-history"></i>
                <span>Audit Logs</span>
            </a>
            <?php if (($adminUser['role'] ?? '') === 'superadmin'): ?>
                <a href="<?= e(get_site_url('ppms/users')) ?>" class="ppms-nav-link <?= $activeNav === 'users' ? 'active' : '' ?>">
                    <i class="fas fa-users-cog"></i>
                    <span>Admin Users</span>
                </a>
                <a href="<?= e(get_site_url('ppms/updates')) ?>" class="ppms-nav-link <?= $activeNav === 'updates' ? 'active' : '' ?>">
                    <i class="fab fa-github"></i>
                    <span>Version &amp; GitHub</span>
                </a>
            <?php endif; ?>
            <a href="<?= e(get_site_url('ppms/change-password')) ?>" class="ppms-nav-link <?= $activeNav === 'change-password' ? 'active' : '' ?>">
                <i class="fas fa-key"></i>
                <span>Change Password</span>
            </a>

            <div class="ppms-nav-heading">Public Site</div>
            <a href="<?= e(get_site_url('parties')) ?>" target="_blank" rel="noopener" class="ppms-nav-link">
                <i class="fas fa-external-link-alt"></i>
                <span>Public Directory</span>
            </a>
        </nav>

        <div class="ppms-sidebar-footer">
            <form method="POST" action="<?= e(get_site_url('ppms/logout')) ?>" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= e($ppmsCsrf) ?>">
                <button type="submit" class="btn btn-outline-light btn-sm w-100 d-flex align-items-center justify-content-center gap-2">
                    <i class="fas fa-sign-out-alt"></i> Sign Out
                </button>
            </form>
        </div>
    </aside>

    <div class="ppms-main-wrap">
        <header class="ppms-topbar">
            <div class="ppms-topbar-left">
                <button type="button" class="btn btn-light btn-sm d-lg-none border" onclick="togglePpmsSidebar(true)" aria-label="Open menu">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="ppms-topbar-title"><?= e($ppmsPageTitle) ?></h1>
                <span class="ppms-topbar-pill d-none d-md-inline-block">PPMS</span>
            </div>

            <div class="d-flex align-items-center gap-2">
                <a href="<?= e(get_site_url('ppms/party-form')) ?>" class="btn btn-ppms-saffron btn-sm d-none d-sm-inline-flex align-items-center gap-1 px-3">
                    <i class="fas fa-plus"></i> Add Party
                </a>

                <div class="dropdown">
                    <button class="btn btn-light btn-sm border d-flex align-items-center gap-2 rounded-pill px-2 py-1 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold text-white" style="width:28px;height:28px;background:var(--ppms-navy);font-size:0.78rem;">
                            <?= e($adminInitial) ?>
                        </span>
                        <span class="d-none d-md-inline fw-semibold" style="font-size:0.82rem;color:var(--ppms-navy);">
                            <?= e($adminDisplayName) ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-bold small text-dark"><?= e($adminDisplayName) ?></div>
                            <div class="text-muted" style="font-size:0.72rem;"><?= e(strtoupper((string)$adminUser['role'])) ?> &bull; @<?= e($adminUser['username']) ?></div>
                        </li>
                        <li><a class="dropdown-item py-2 small" href="<?= e(get_site_url('ppms/change-password')) ?>"><i class="fas fa-key me-2 text-warning"></i> Change Password</a></li>
                        <?php if (($adminUser['role'] ?? '') === 'superadmin'): ?>
                            <li><a class="dropdown-item py-2 small" href="<?= e(get_site_url('ppms/users')) ?>"><i class="fas fa-users-cog me-2 text-primary"></i> Admin Users</a></li>
                            <li><a class="dropdown-item py-2 small" href="<?= e(get_site_url('ppms/updates')) ?>"><i class="fab fa-github me-2 text-dark"></i> Version &amp; GitHub</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item py-2 small" href="<?= e(get_site_url('parties')) ?>" target="_blank" rel="noopener"><i class="fas fa-globe me-2 text-success"></i> View Public Parties</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="<?= e(get_site_url('ppms/logout')) ?>" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?= e($ppmsCsrf) ?>">
                                <button type="submit" class="dropdown-item py-2 small text-danger fw-bold">
                                    <i class="fas fa-sign-out-alt me-2"></i> Sign Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="ppms-content">
            <?php if ($ppmsFlash):
                $alertMap = [
                    'success' => 'alert-success',
                    'error' => 'alert-danger',
                    'warning' => 'alert-warning',
                    'info' => 'alert-info',
                ];
                $alertCls = $alertMap[$ppmsFlash['type']] ?? 'alert-info';
            ?>
                <div class="alert <?= e($alertCls) ?> alert-dismissible fade show shadow-sm border-0 mb-3" role="alert">
                    <i class="fas <?= $ppmsFlash['type'] === 'success' ? 'fa-check-circle' : ($ppmsFlash['type'] === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle') ?> me-2"></i>
                    <?= e($ppmsFlash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>


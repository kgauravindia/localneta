<?php
// ppms/sitemaps.php - Complete SEO XML Sitemaps Console & Explorer for LocalNeta PPMS
define('PPMS_INIT', true);
require_once __DIR__ . '/includes/auth.php';

$adminUser = ppms_require_auth();
$pdo = ppms_db();

$sitemapStats = ppms_sitemap_stats($pdo);

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
$scheme = $isHttps ? 'https' : 'http';
$host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localneta.com'));
$siteOrigin = $scheme . '://' . $host . get_site_url('');

$pinChunks = max(1, (int)ceil($sitemapStats['pincodes'] / 25000));
$feeds = [
    [
        'key' => 'index',
        'title' => 'Master Sitemap Index',
        'path' => 'sitemap.xml',
        'url' => $siteOrigin . '/sitemap.xml',
        'count' => $sitemapStats['grand_total'],
        'count_label' => 'All Indexed URLs',
        'desc' => 'Root XML sitemap index submitted to Google Search Console & Bing Webmaster Tools.',
        'badge' => 'bg-primary',
        'icon' => 'fa-sitemap',
    ],
    [
        'key' => 'core',
        'title' => 'Core Pages, Categories & State Hubs',
        'path' => 'sitemap-core.xml',
        'url' => $siteOrigin . '/sitemap-core.xml',
        'count' => $sitemapStats['core'],
        'count_label' => 'Core URLs',
        'desc' => 'Home, Leaders, 25 Representative Categories, Elections, Parties, PIN Finder, Data Sources, Policy pages & all 36 State hubs.',
        'badge' => 'bg-dark',
        'icon' => 'fa-compass',
    ],
    [
        'key' => 'districts',
        'title' => 'District Civic Directories',
        'path' => 'sitemap-districts.xml',
        'url' => $siteOrigin . '/sitemap-districts.xml',
        'count' => $sitemapStats['districts'],
        'count_label' => 'District URLs',
        'desc' => 'Every active district directory hub across India.',
        'badge' => 'bg-info text-dark',
        'icon' => 'fa-map-marked-alt',
    ],
    [
        'key' => 'mps',
        'title' => 'Parliament MPs (Lok Sabha & Rajya Sabha)',
        'path' => 'sitemap-mps.xml',
        'url' => $siteOrigin . '/sitemap-mps.xml',
        'count' => $sitemapStats['mps'],
        'count_label' => 'MP URLs',
        'desc' => 'Dedicated SEO profile URLs (/mp/<slug>) for every MP in Parliament.',
        'badge' => 'bg-danger',
        'icon' => 'fa-landmark',
    ],
    [
        'key' => 'mlcs',
        'title' => 'Legislative Council MLCs (ACTIVE)',
        'path' => 'sitemap-mlcs.xml',
        'url' => $siteOrigin . '/sitemap-mlcs.xml',
        'count' => $sitemapStats['mlcs'],
        'count_label' => 'MLC URLs',
        'desc' => 'Dedicated prefixed SEO URLs (/mlc/br-*, /mlc/up-*, /mlc/ap-*) for ACTIVE Vidhan Parishad MLCs.',
        'badge' => 'bg-primary',
        'icon' => 'fa-scroll',
    ],
    [
        'key' => 'parties',
        'title' => 'ECI Political Parties',
        'path' => 'sitemap-parties.xml',
        'url' => $siteOrigin . '/sitemap-parties.xml',
        'count' => $sitemapStats['parties'],
        'count_label' => 'Party URLs',
        'desc' => 'Every National, State & Registered political party profile (/political-party/<slug>).',
        'badge' => 'bg-warning text-dark',
        'icon' => 'fa-flag',
    ],
    [
        'key' => 'pincodes',
        'title' => 'All-India PIN Code & Village Directory',
        'path' => $pinChunks === 1 ? 'sitemap-pincodes.xml' : 'sitemap-pincodes-1.xml',
        'url' => $siteOrigin . '/' . ($pinChunks === 1 ? 'sitemap-pincodes.xml' : 'sitemap-pincodes-1.xml'),
        'count' => $sitemapStats['pincodes'],
        'count_label' => 'Unique PIN URLs',
        'desc' => 'Covers ' . number_format($sitemapStats['pincodes']) . ' distinct 6-digit Indian PIN codes mapped across ' . number_format($sitemapStats['villages']) . ' villages.',
        'badge' => 'bg-success',
        'icon' => 'fa-map-pin',
    ],
    [
        'key' => 'users',
        'title' => 'Verified Public Politician Cards',
        'path' => 'sitemap-users.xml',
        'url' => $siteOrigin . '/sitemap-users.xml',
        'count' => $sitemapStats['users'],
        'count_label' => 'Public Cards',
        'desc' => 'Mobile-verified registered public representative cards (/user/<handle>) marked public.',
        'badge' => 'bg-secondary',
        'icon' => 'fa-id-badge',
    ],
];

$activeNav = 'sitemaps';
$ppmsPageTitle = 'XML Sitemap Explorer';
require_once __DIR__ . '/includes/layout_top.php';
?>

<!-- Sitemap Overview Hero -->
<div class="ppms-card" style="border-top:4px solid var(--ppms-green);">
    <div class="ppms-card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h2 class="h5 fw-bold mb-1" style="color:var(--ppms-navy);">
                Dynamic SEO XML Sitemaps (All Entities)
            </h2>
            <p class="small text-muted mb-0">
                Real-time XML sitemap hub serving <strong><?= number_format($sitemapStats['grand_total']) ?></strong> clean, extensionless public URLs across Core Hubs, Districts, Parliament MPs, Council MLCs, Political Parties, PIN Codes &amp; Verified Politicians.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= e($siteOrigin . '/sitemap.xml') ?>" target="_blank" rel="noopener" class="btn btn-ppms-saffron btn-sm px-3">
                <i class="fas fa-external-link-alt me-1"></i> Open Master sitemap.xml
            </a>
            <a href="<?= e($siteOrigin . '/robots.txt') ?>" target="_blank" rel="noopener" class="btn btn-light border btn-sm fw-semibold">
                <i class="fas fa-robot me-1"></i> View robots.txt
            </a>
        </div>
    </div>
</div>

<!-- Child Sitemaps Grid -->
<div class="ppms-card">
    <div class="ppms-card-header">
        <h3 class="ppms-card-title"><i class="fas fa-sitemap text-primary"></i> XML Sitemap Feeds (<?= count($feeds) ?> Endpoints)</h3>
        <span class="small text-muted">Standard Sitemaps 0.9 Protocol &bull; UTF-8</span>
    </div>
    <div class="table-responsive">
        <table class="table ppms-table mb-0">
            <thead>
                <tr>
                    <th>Feed Endpoint</th>
                    <th>Coverage &amp; Description</th>
                    <th class="text-end">URL Count</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($feeds as $fd): ?>
                    <tr>
                        <td class="text-nowrap">
                            <div class="fw-bold text-dark">
                                <i class="fas <?= e($fd['icon']) ?> me-1 text-secondary"></i>
                                <?= e($fd['title']) ?>
                            </div>
                            <code class="small text-primary">/<?= e($fd['path']) ?></code>
                        </td>
                        <td class="small text-secondary" style="max-width:420px;">
                            <?= e($fd['desc']) ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <span class="badge <?= e($fd['badge']) ?> rounded-pill px-3 py-1">
                                <?= number_format($fd['count']) ?>
                            </span>
                            <div class="text-muted" style="font-size:0.7rem;"><?= e($fd['count_label']) ?></div>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e($fd['url']) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm py-1 px-2">
                                <i class="fas fa-code me-1"></i> Open XML
                            </a>
                            <button type="button"
                                    class="btn btn-outline-secondary btn-sm py-1 px-2"
                                    data-copy-url="<?= e($fd['url']) ?>"
                                    onclick="copySitemapUrl(this)">
                                <i class="fas fa-copy"></i> Copy URL
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SEO & Privacy Exclusion Policy -->
<div class="row g-3">
    <div class="col-md-6">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-check-circle text-success"></i> Included &amp; Live Synced</h3>
            </div>
            <div class="ppms-card-body small text-secondary">
                <ul class="mb-0 ps-3 d-grid gap-1">
                    <li>Any MP, MLC, Political Party, or PIN/Village created, edited, or deleted in PPMS is reflected immediately in XML sitemaps.</li>
                    <li>MLCs set to <code>INACTIVE</code> are automatically excluded from <code>/sitemap-mlcs.xml</code>.</li>
                    <li>Only canonical, extensionless routes are emitted across all feeds.</li>
                    <li>PIN code feed chunks automatically every 25,000 PIN codes to stay well inside Search Console limits.</li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="ppms-card h-100 mb-0">
            <div class="ppms-card-header">
                <h3 class="ppms-card-title"><i class="fas fa-shield-alt text-danger"></i> Excluded &amp; Disallowed Routes</h3>
            </div>
            <div class="ppms-card-body small text-secondary">
                <ul class="mb-0 ps-3 d-grid gap-1">
                    <li>Admin console <code>/ppms/*</code> and internal API routes <code>/api/*</code> are blocked via <code>robots.txt</code> &amp; <code>X-Robots-Tag: noindex</code>.</li>
                    <li>Authenticated / interactive workflows (<code>/studio</code>, <code>/dashboard</code>, <code>/edit-profile</code>, <code>/verify-otp</code>, <code>/forgot-password</code>, <code>/logout</code>) are excluded.</li>
                    <li>Private or unverified user accounts never appear in <code>/sitemap-users.xml</code>.</li>
                    <li>Zero unmasked mobile numbers or email IDs are ever exposed in sitemap URLs.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
$ppmsExtraScripts = <<<'HTML'
<script>
function copySitemapUrl(btn) {
    const url = btn.getAttribute('data-copy-url') || '';
    if (!url || !navigator.clipboard) return;
    navigator.clipboard.writeText(url).then(() => {
        const prev = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i> Copied';
        setTimeout(() => { btn.innerHTML = prev; }, 1600);
    });
}
</script>
HTML;
require_once __DIR__ . '/includes/layout_bottom.php';
?>


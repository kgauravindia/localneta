<?php
// sitemap.php - Dynamic SEO XML Sitemap Index & Child Sitemaps for LocalNeta.com
define('APP_INIT', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = get_db_connection();

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
$scheme = $isHttps ? 'https' : 'http';
$host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host === '') {
    $host = 'localneta.com';
}
$baseRoot = $scheme . '://' . $host . get_site_url('');

$type = strtolower(trim((string)($_GET['type'] ?? 'index')));
$pageParam = trim((string)($_GET['page'] ?? ''));
$page = $pageParam !== '' ? max(1, (int)$pageParam) : 1;

$allowedTypes = ['index', 'core', 'mps', 'mlcs', 'parties', 'districts', 'pincodes', 'users'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Sitemap not found');
}

$xmlEsc = static fn(string $val): string => htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$fmtDate = static function (?string $raw): ?string {
    if ($raw === null || trim($raw) === '' || str_starts_with($raw, '0000-')) {
        return null;
    }
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d', $ts) : null;
};

header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=300');

$today = date('Y-m-d');
$pinChunkSize = 25000;

if ($type === 'index') {
    $totalPins = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT pincode FROM village WHERE pincode BETWEEN 100000 AND 999999) p")->fetchColumn();
    $pinChunks = max(1, (int)ceil($totalPins / $pinChunkSize));

    $mlcMaxMod = $fmtDate((string)($pdo->query("SELECT MAX(updated_at) FROM mlc WHERE UPPER(TRIM(status)) = 'ACTIVE'")->fetchColumn() ?: '')) ?? $today;
    $partyMaxMod = $fmtDate((string)($pdo->query("SELECT MAX(updated_at) FROM political_party")->fetchColumn() ?: '')) ?? $today;
    $mpMaxMod = $fmtDate((string)($pdo->query("SELECT MAX(created_at) FROM mp")->fetchColumn() ?: '')) ?? $today;

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $children = [
        ['loc' => $baseRoot . '/sitemap-core.xml', 'lastmod' => $today],
        ['loc' => $baseRoot . '/sitemap-districts.xml', 'lastmod' => $today],
        ['loc' => $baseRoot . '/sitemap-mps.xml', 'lastmod' => $mpMaxMod],
        ['loc' => $baseRoot . '/sitemap-mlcs.xml', 'lastmod' => $mlcMaxMod],
        ['loc' => $baseRoot . '/sitemap-parties.xml', 'lastmod' => $partyMaxMod],
    ];
    if ($pinChunks === 1) {
        $children[] = ['loc' => $baseRoot . '/sitemap-pincodes.xml', 'lastmod' => $today];
    } else {
        for ($i = 1; $i <= $pinChunks; $i++) {
            $children[] = ['loc' => $baseRoot . '/sitemap-pincodes-' . $i . '.xml', 'lastmod' => $today];
        }
    }
    $children[] = ['loc' => $baseRoot . '/sitemap-users.xml', 'lastmod' => $today];

    foreach ($children as $ch) {
        echo "  <sitemap>\n";
        echo "    <loc>" . $xmlEsc($ch['loc']) . "</loc>\n";
        echo "    <lastmod>" . $xmlEsc($ch['lastmod']) . "</lastmod>\n";
        echo "  </sitemap>\n";
    }
    echo '</sitemapindex>' . "\n";
    exit;
}

$emitUrl = static function (string $loc, ?string $lastmod = null, string $changefreq = 'weekly', string $priority = '0.7') use ($xmlEsc): void {
    echo "  <url>\n";
    echo "    <loc>" . $xmlEsc($loc) . "</loc>\n";
    if ($lastmod !== null) {
        echo "    <lastmod>" . $xmlEsc($lastmod) . "</lastmod>\n";
    }
    echo "    <changefreq>" . $xmlEsc($changefreq) . "</changefreq>\n";
    echo "    <priority>" . $xmlEsc($priority) . "</priority>\n";
    echo "  </url>\n";
};

if ($type === 'pincodes') {
    $totalPins = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT pincode FROM village WHERE pincode BETWEEN 100000 AND 999999) p")->fetchColumn();
    $totalPages = max(1, (int)ceil($totalPins / $pinChunkSize));
    if ($page > $totalPages) {
        http_response_code(404);
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        exit;
    }
    $offset = ($page - 1) * $pinChunkSize;

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $stmt = $pdo->query("
        SELECT DISTINCT pincode
        FROM village
        WHERE pincode BETWEEN 100000 AND 999999
        ORDER BY pincode ASC
        LIMIT " . (int)$pinChunkSize . " OFFSET " . (int)$offset
    );
    while ($pinVal = $stmt->fetchColumn()) {
        $pinStr = str_pad((string)$pinVal, 6, '0', STR_PAD_LEFT);
        $emitUrl($baseRoot . '/pincode/' . $pinStr, null, 'monthly', '0.6');
    }

    echo '</urlset>' . "\n";
    exit;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

if ($type === 'core') {
    $staticRoutes = [
        ['path' => '/index', 'freq' => 'daily', 'prio' => '1.0'],
        ['path' => '/leaders', 'freq' => 'daily', 'prio' => '0.9'],
        ['path' => '/elections', 'freq' => 'daily', 'prio' => '0.9'],
        ['path' => '/parties', 'freq' => 'daily', 'prio' => '0.9'],
        ['path' => '/pincode', 'freq' => 'daily', 'prio' => '0.9'],
        ['path' => '/issues', 'freq' => 'daily', 'prio' => '0.8'],
        ['path' => '/source', 'freq' => 'weekly', 'prio' => '0.6'],
        ['path' => '/privacy-policy', 'freq' => 'monthly', 'prio' => '0.4'],
        ['path' => '/disclaimer', 'freq' => 'monthly', 'prio' => '0.4'],
        ['path' => '/terms-and-conditions', 'freq' => 'monthly', 'prio' => '0.4'],
        ['path' => '/login', 'freq' => 'monthly', 'prio' => '0.5'],
        ['path' => '/register', 'freq' => 'monthly', 'prio' => '0.5'],
    ];
    foreach ($staticRoutes as $sr) {
        $emitUrl($baseRoot . $sr['path'], $today, $sr['freq'], $sr['prio']);
    }

    $cats = $pdo->query("SELECT slug FROM leader_types WHERE status = 'ACTIVE' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cats as $catSlug) {
        $emitUrl($baseRoot . '/leaders?cat=' . rawurlencode((string)$catSlug), $today, 'daily', '0.8');
    }

    $states = $pdo->query("
        SELECT state_code, MIN(state) AS state_name
        FROM op_sdb
        WHERE status = 'ACTIVE' AND state_code <> ''
        GROUP BY state_code
        ORDER BY state_name ASC, state_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($states as $stRow) {
        $enc = rawurlencode((string)$stRow['state_code']);
        $emitUrl($baseRoot . '/index?state_code=' . $enc, $today, 'weekly', '0.8');
        $emitUrl($baseRoot . '/pincode?state_code=' . $enc, $today, 'weekly', '0.7');
    }
} elseif ($type === 'districts') {
    $distRows = $pdo->query("
        SELECT state_code, district_code, MIN(district) AS district_name
        FROM op_sdb
        WHERE status = 'ACTIVE' AND state_code <> '' AND district_code <> ''
        GROUP BY state_code, district_code
        ORDER BY state_code ASC, district_name ASC, district_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($distRows as $dr) {
        $emitUrl(
            $baseRoot . '/index?state_code=' . rawurlencode((string)$dr['state_code']) . '&district_code=' . rawurlencode((string)$dr['district_code']),
            null,
            'weekly',
            '0.7'
        );
    }
} elseif ($type === 'mps') {
    $mpRows = $pdo->query("SELECT slug, created_at FROM mp WHERE slug <> '' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mpRows as $mr) {
        $emitUrl($baseRoot . '/mp/' . rawurlencode((string)$mr['slug']), $fmtDate($mr['created_at'] ?? null), 'weekly', '0.8');
    }
} elseif ($type === 'mlcs') {
    $mlcRows = $pdo->query("SELECT slug, COALESCE(updated_at, created_at) AS lastmod FROM mlc WHERE UPPER(TRIM(status)) = 'ACTIVE' AND slug <> '' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mlcRows as $cr) {
        $emitUrl($baseRoot . '/mlc/' . rawurlencode((string)$cr['slug']), $fmtDate($cr['lastmod'] ?? null), 'weekly', '0.8');
    }
} elseif ($type === 'parties') {
    $partyRows = $pdo->query("SELECT slug, type, COALESCE(updated_at, created_at) AS lastmod FROM political_party WHERE slug <> '' ORDER BY id ASC");
    while ($pr = $partyRows->fetch(PDO::FETCH_ASSOC)) {
        $prio = strcasecmp(trim((string)$pr['type']), 'National') === 0 ? '0.9' : (strcasecmp(trim((string)$pr['type']), 'State') === 0 ? '0.8' : '0.6');
        $emitUrl($baseRoot . '/political-party/' . rawurlencode((string)$pr['slug']), $fmtDate($pr['lastmod'] ?? null), 'weekly', $prio);
    }
} elseif ($type === 'users') {
    $userRows = $pdo->query("
        SELECT username_handle, COALESCE(updated_at, created_at) AS lastmod
        FROM users
        WHERE account_type = 'politician'
          AND status = 'active'
          AND profile_visibility = 'public'
          AND is_mobile_verified = 1
          AND COALESCE(username_handle, '') <> ''
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($userRows as $ur) {
        $emitUrl($baseRoot . '/user/' . rawurlencode((string)$ur['username_handle']), $fmtDate($ur['lastmod'] ?? null), 'weekly', '0.7');
    }
}

echo '</urlset>' . "\n";

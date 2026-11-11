<?php
// router.php - Clean URL router for PHP built-in development server (`php -S 127.0.0.1:8080 router.php`)
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$queryStr = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY) ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$docRoot = __DIR__;

// Disallow web access to Git metadata, router.php, includes/, data/, logs/, config & SQL/archive extensions
if (preg_match('#^/(?:router(?:\.php)?|(?:ppms/)?includes(?:/.*)?|data(?:/.*)?|logs(?:/.*)?|tmp(?:/.*)?|cache(?:/.*)?|backups(?:/.*)?|dumps(?:/.*)?|\.git(?:/.*)?|\.gitignore|\.gitattributes|\.env(?:\..*)?|version\.json)$#i', $uriPath)
    || preg_match('#\.(?:sql|sql\.gz|sql\.bz2|sql\.xz|sql\.zip|sqlite|sqlite3|db|dump|bak|log|env|xls|xlsx)$#i', $uriPath)) {
    http_response_code(403);
    exit('Forbidden');
}

// External GET-only 301 redirect from *.php to extensionless URL (index.php -> index, all pages)
if ($method === 'GET' && preg_match('#^/(.+?)\.php$#i', $uriPath, $pm)) {
    $target = '/' . ltrim($pm[1], '/');
    if ($queryStr !== '') {
        $target .= '?' . $queryStr;
    }
    header('Location: ' . $target, true, 301);
    exit;
}

$filePath = realpath($docRoot . $uriPath);

// Dynamic SEO XML Sitemaps
if (preg_match('#^/sitemap(?:\.xml)?/?$#i', $uriPath)) {
    $_GET['type'] = $_GET['type'] ?? 'index';
    $_REQUEST['type'] = $_GET['type'];
    $_SERVER['SCRIPT_NAME'] = '/sitemap.php';
    $_SERVER['PHP_SELF'] = '/sitemap.php';
    require $docRoot . '/sitemap.php';
    return true;
}

if (preg_match('#^/sitemap-(core|mps|mlcs|parties|pincodes|districts|users)(?:-([0-9]+))?\.xml$#i', $uriPath, $sm)) {
    $_GET['type'] = strtolower($sm[1]);
    $_GET['page'] = $sm[2] ?? '';
    $_REQUEST['type'] = $_GET['type'];
    $_REQUEST['page'] = $_GET['page'];
    $_SERVER['SCRIPT_NAME'] = '/sitemap.php';
    $_SERVER['PHP_SELF'] = '/sitemap.php';
    require $docRoot . '/sitemap.php';
    return true;
}

// Serve existing non-PHP static assets cleanly
if ($uriPath !== '/' && $filePath !== false && strpos($filePath, $docRoot) === 0 && is_file($filePath)) {
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
    $relScript = '/' . ltrim(str_replace('\\', '/', substr($filePath, strlen($docRoot))), '/');
    $_SERVER['SCRIPT_NAME'] = $relScript;
    $_SERVER['PHP_SELF'] = $relScript;
    require $filePath;
    return true;
}

if (preg_match('#^/political-party/([^/]+)/?$#', $uriPath, $m)) {
    $_GET['slug'] = urldecode($m[1]);
    $_REQUEST['slug'] = $_GET['slug'];
    $_SERVER['SCRIPT_NAME'] = '/political-party.php';
    $_SERVER['PHP_SELF'] = '/political-party.php';
    require $docRoot . '/political-party.php';
    return true;
}

if (preg_match('#^/mp/([^/]+)/?$#', $uriPath, $m)) {
    $_GET['slug'] = urldecode($m[1]);
    $_REQUEST['slug'] = $_GET['slug'];
    $_SERVER['SCRIPT_NAME'] = '/mp.php';
    $_SERVER['PHP_SELF'] = '/mp.php';
    require $docRoot . '/mp.php';
    return true;
}

if (preg_match('#^/mlc/([^/]+)/?$#', $uriPath, $m)) {
    $_GET['slug'] = urldecode($m[1]);
    $_REQUEST['slug'] = $_GET['slug'];
    $_SERVER['SCRIPT_NAME'] = '/mlc.php';
    $_SERVER['PHP_SELF'] = '/mlc.php';
    require $docRoot . '/mlc.php';
    return true;
}

if (preg_match('#^/user/([^/]+)/?$#', $uriPath, $m)) {
    $_GET['handle'] = urldecode($m[1]);
    $_REQUEST['handle'] = $_GET['handle'];
    $_SERVER['SCRIPT_NAME'] = '/user-profile.php';
    $_SERVER['PHP_SELF'] = '/user-profile.php';
    require $docRoot . '/user-profile.php';
    return true;
}

if (preg_match('#^/pincode/([^/]+)/?$#', $uriPath, $m)) {
    $_GET['pincode'] = urldecode($m[1]);
    $_REQUEST['pincode'] = $_GET['pincode'];
    $_SERVER['SCRIPT_NAME'] = '/pincode.php';
    $_SERVER['PHP_SELF'] = '/pincode.php';
    require $docRoot . '/pincode.php';
    return true;
}

if ($uriPath === '/' || $uriPath === '' || preg_match('#^/index/?$#i', $uriPath)) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF'] = '/index.php';
    require $docRoot . '/index.php';
    return true;
}

if (preg_match('#^/ppms/?$#', $uriPath)) {
    $_SERVER['SCRIPT_NAME'] = '/ppms/index.php';
    $_SERVER['PHP_SELF'] = '/ppms/index.php';
    require $docRoot . '/ppms/index.php';
    return true;
}

if (preg_match('#^/ppms/([A-Za-z0-9_-]+)/?$#', $uriPath, $pm)) {
    $ppmsFile = realpath($docRoot . '/ppms/' . $pm[1] . '.php');
    $ppmsRoot = $docRoot . DIRECTORY_SEPARATOR . 'ppms';
    if ($ppmsFile !== false && dirname($ppmsFile) === $ppmsRoot && is_file($ppmsFile)) {
        $relScript = '/ppms/' . $pm[1] . '.php';
        $_SERVER['SCRIPT_NAME'] = $relScript;
        $_SERVER['PHP_SELF'] = $relScript;
        require $ppmsFile;
        return true;
    }
}

if (preg_match('#^/api/([A-Za-z0-9_-]+)/?$#', $uriPath, $am)) {
    $apiFile = realpath($docRoot . '/api/' . $am[1] . '.php');
    if ($apiFile !== false && strpos($apiFile, $docRoot . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR) === 0 && is_file($apiFile)) {
        $relScript = '/api/' . $am[1] . '.php';
        $_SERVER['SCRIPT_NAME'] = $relScript;
        $_SERVER['PHP_SELF'] = $relScript;
        require $apiFile;
        return true;
    }
}

if (preg_match('#^/([A-Za-z0-9_-]+)/?$#', $uriPath, $rm) && strtolower($rm[1]) !== 'router') {
    $pageFile = realpath($docRoot . '/' . $rm[1] . '.php');
    if ($pageFile !== false && dirname($pageFile) === $docRoot && is_file($pageFile)) {
        $relScript = '/' . $rm[1] . '.php';
        $_SERVER['SCRIPT_NAME'] = $relScript;
        $_SERVER['PHP_SELF'] = $relScript;
        require $pageFile;
        return true;
    }
}

http_response_code(404);
require $docRoot . '/index.php';

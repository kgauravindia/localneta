<?php
// ppms/includes/auth.php - Session Authentication, CSRF, Audit Logs & Helpers for LocalNeta PPMS
if (!defined('PPMS_INIT')) {
    http_response_code(403);
    exit('Forbidden');
}

date_default_timezone_set('Asia/Kolkata');

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);
    session_name('LOCALNETA_PPMS_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('APP_INIT', true);
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

function ppms_db(): PDO {
    static $ready = false;
    $pdo = get_db_connection();
    if (!$ready) {
        $ready = true;
        ppms_ensure_schema($pdo);
    }
    return $pdo;
}

function ppms_ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ppms_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NOT NULL DEFAULT 'Administrator',
            email VARCHAR(150) NULL,
            role ENUM('superadmin', 'editor') NOT NULL DEFAULT 'editor',
            status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
            last_login DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ppms_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL DEFAULT 0,
            username VARCHAR(80) NOT NULL DEFAULT '',
            action VARCHAR(40) NOT NULL,
            entity_type VARCHAR(30) NOT NULL DEFAULT 'party',
            entity_id INT NULL,
            party_id INT NULL,
            party_name VARCHAR(500) NULL,
            details VARCHAR(500) NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ppms_log_created (created_at),
            INDEX idx_ppms_log_party (party_id),
            INDEX idx_ppms_log_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (!$pdo->query("SHOW COLUMNS FROM ppms_audit_logs LIKE 'entity_type'")->fetch()) {
        $pdo->exec("ALTER TABLE ppms_audit_logs ADD COLUMN entity_type VARCHAR(30) NOT NULL DEFAULT 'party' AFTER action");
    }
    if (!$pdo->query("SHOW COLUMNS FROM ppms_audit_logs LIKE 'entity_id'")->fetch()) {
        $pdo->exec("ALTER TABLE ppms_audit_logs ADD COLUMN entity_id INT NULL AFTER entity_type");
    }
    if (!$pdo->query("SHOW INDEX FROM ppms_audit_logs WHERE Key_name = 'idx_ppms_log_entity'")->fetch()) {
        $pdo->exec("ALTER TABLE ppms_audit_logs ADD INDEX idx_ppms_log_entity (entity_type, entity_id)");
    }
}

function ppms_has_users(PDO $pdo): bool {
    return (int)$pdo->query("SELECT COUNT(*) FROM ppms_users")->fetchColumn() > 0;
}

function ppms_csrf_token(): string {
    if (empty($_SESSION['ppms_csrf']) || !is_string($_SESSION['ppms_csrf'])) {
        $_SESSION['ppms_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ppms_csrf'];
}

function ppms_verify_csrf(?string $token): bool {
    $expected = $_SESSION['ppms_csrf'] ?? '';
    return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function ppms_set_flash(string $type, string $message): void {
    $_SESSION['ppms_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function ppms_get_flash(): ?array {
    if (empty($_SESSION['ppms_flash']) || !is_array($_SESSION['ppms_flash'])) {
        return null;
    }
    $flash = $_SESSION['ppms_flash'];
    unset($_SESSION['ppms_flash']);
    return $flash;
}

function ppms_current_admin(PDO $pdo): ?array {
    if (empty($_SESSION['ppms_auth']) || empty($_SESSION['ppms_admin_id'])) {
        return null;
    }
    static $cachedUser = false;
    if ($cachedUser !== false) {
        return $cachedUser;
    }

    $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, status, last_login FROM ppms_users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['ppms_admin_id']]);
    $user = $stmt->fetch() ?: null;

    if (!$user || strtoupper((string)$user['status']) !== 'ACTIVE') {
        ppms_clear_session();
        $cachedUser = null;
        return null;
    }

    $cachedUser = $user;
    return $user;
}

function ppms_clear_session(): void {
    unset(
        $_SESSION['ppms_auth'],
        $_SESSION['ppms_admin_id'],
        $_SESSION['ppms_admin_user'],
        $_SESSION['ppms_admin_name'],
        $_SESSION['ppms_admin_role']
    );
}

function ppms_require_auth(): array {
    $pdo = ppms_db();
    $user = ppms_current_admin($pdo);
    if (!$user) {
        header('Location: ' . get_site_url('ppms/login'), true, 302);
        exit;
    }
    return $user;
}

function ppms_log_action(PDO $pdo, array $adminUser, string $action, ?int $entityId, ?string $targetName, string $details = '', string $entityType = 'party'): void {
    try {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $normEntityType = trim($entityType) !== '' ? substr(strtolower(trim($entityType)), 0, 30) : 'party';
        $partyIdCol = $normEntityType === 'party' ? $entityId : null;
        $stmt = $pdo->prepare("
            INSERT INTO ppms_audit_logs (user_id, username, action, entity_type, entity_id, party_id, party_name, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)($adminUser['id'] ?? 0),
            substr((string)($adminUser['username'] ?? ''), 0, 80),
            substr($action, 0, 40),
            $normEntityType,
            $entityId,
            $partyIdCol,
            $targetName !== null ? mb_substr($targetName, 0, 490, 'UTF-8') : null,
            mb_substr($details, 0, 490, 'UTF-8'),
            $ip,
        ]);
    } catch (Throwable $e) {
        // Non-fatal audit logging failure
    }
}

function ppms_get_states(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $stmt = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' AND state_code <> '' AND state <> '' ORDER BY state ASC");
    $cache = $stmt ? $stmt->fetchAll() : [];
    return $cache;
}

function ppms_get_symbol_catalog(): array {
    static $catalog = null;
    if ($catalog !== null) {
        return $catalog;
    }
    $dir = __DIR__ . '/../../assets/images/symbols/';
    $items = [];
    if (is_dir($dir)) {
        $files = glob($dir . '*.png') ?: [];
        foreach ($files as $path) {
            $filename = basename($path);
            $base = pathinfo($filename, PATHINFO_FILENAME);
            $labelBase = $base === 'hummer_sickle_and_star' ? 'Hammer, Sickle and Star' : str_replace('_', ' ', $base);
            $label = ucwords($labelBase);
            $items[] = [
                'filename' => $filename,
                'key' => $base,
                'label' => $label,
                'url' => get_site_url('assets/images/symbols/' . $filename),
            ];
        }
        usort($items, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));
    }
    $catalog = $items;
    return $catalog;
}

function ppms_party_summary(PDO $pdo): array {
    $defaults = [
        'total_rows' => 0,
        'active_count' => 0,
        'inactive_count' => 0,
        'delisted_count' => 0,
        'national_count' => 0,
        'state_count' => 0,
        'registered_count' => 0,
        'symbol_count' => 0,
        'hindi_name_count' => 0,
        'url_count' => 0,
        'contact_count' => 0,
        'social_count' => 0,
        'states_covered' => 0,
    ];
    if (!$pdo->query("SHOW TABLES LIKE 'political_party'")->fetch()) {
        return $defaults;
    }
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_rows,
            SUM(UPPER(TRIM(status)) = 'ACTIVE') AS active_count,
            SUM(UPPER(TRIM(status)) = 'INACTIVE') AS inactive_count,
            SUM(UPPER(TRIM(status)) = 'DELISTED') AS delisted_count,
            SUM(TRIM(type) = 'National') AS national_count,
            SUM(TRIM(type) = 'State') AS state_count,
            SUM(TRIM(type) = 'Registered') AS registered_count,
            SUM(symbol IS NOT NULL AND TRIM(symbol) <> '' AND UPPER(TRIM(symbol)) NOT IN ('-', 'NA', 'N/A', 'NONE', 'NIL')) AS symbol_count,
            SUM(name_hi IS NOT NULL AND TRIM(name_hi) <> '') AS hindi_name_count,
            SUM(url IS NOT NULL AND TRIM(url) <> '') AS url_count,
            SUM(
                (mobile IS NOT NULL AND TRIM(mobile) <> '') OR
                (whatsapp IS NOT NULL AND TRIM(whatsapp) <> '') OR
                (misscall IS NOT NULL AND TRIM(misscall) <> '') OR
                (email IS NOT NULL AND TRIM(email) <> '')
            ) AS contact_count,
            SUM(
                (facebook IS NOT NULL AND TRIM(facebook) <> '') OR
                (instagram IS NOT NULL AND TRIM(instagram) <> '') OR
                (x IS NOT NULL AND TRIM(x) <> '') OR
                (youtube IS NOT NULL AND TRIM(youtube) <> '') OR
                (linkedin IS NOT NULL AND TRIM(linkedin) <> '')
            ) AS social_count,
            COUNT(DISTINCT CASE WHEN state_code <> '' THEN state_code END) AS states_covered
        FROM political_party
    ")->fetch();
    return $row ? array_merge($defaults, array_map('intval', $row)) : $defaults;
}

function ppms_build_unique_slug(PDO $pdo, string $rawInput, string $partyName, string $stateName, int $excludeId = 0): array {
    $manualProvided = trim($rawInput) !== '';
    $base = $manualProvided
        ? generate_party_slug($rawInput)
        : generate_party_slug($partyName);

    if ($base === '') {
        $base = 'political-party';
    }

    $chk = $pdo->prepare("SELECT id FROM political_party WHERE slug = ? AND id <> ? LIMIT 1");
    $chk->execute([$base, $excludeId]);
    if (!$chk->fetch()) {
        return ['ok' => true, 'slug' => $base];
    }

    if ($manualProvided) {
        return ['ok' => false, 'error' => "Slug '{$base}' is already used by another political party. Choose a unique slug or leave blank to auto-disambiguate."];
    }

    $stateSlug = generate_party_slug($stateName, 50);
    if ($stateSlug !== '' && $stateSlug !== 'all') {
        $candidate = substr($base, 0, 175) . '-' . $stateSlug;
        $chk->execute([$candidate, $excludeId]);
        if (!$chk->fetch()) {
            return ['ok' => true, 'slug' => $candidate];
        }
    }

    for ($n = 2; $n <= 99; $n++) {
        $candidate = substr($base, 0, 175) . '-' . $n;
        $chk->execute([$candidate, $excludeId]);
        if (!$chk->fetch()) {
            return ['ok' => true, 'slug' => $candidate];
        }
    }

    return ['ok' => true, 'slug' => substr($base, 0, 165) . '-' . time()];
}

function ppms_mp_summary(PDO $pdo): array {
    $defaults = [
        'total_rows' => 0,
        'ls_count' => 0,
        'rs_count' => 0,
        'sitting_count' => 0,
        'inactive_count' => 0,
        'states_covered' => 0,
        'party_count' => 0,
    ];
    if (!$pdo->query("SHOW TABLES LIKE 'mp'")->fetch()) {
        return $defaults;
    }
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_rows,
            SUM(type = 'ls') AS ls_count,
            SUM(type = 'rs') AS rs_count,
            SUM(membership_status = 'Sitting') AS sitting_count,
            SUM(membership_status <> 'Sitting') AS inactive_count,
            COUNT(DISTINCT CASE WHEN COALESCE(state_code, '') <> '' THEN state_code END) AS states_covered,
            COUNT(DISTINCT party_name) AS party_count
        FROM mp
    ")->fetch();
    return $row ? array_merge($defaults, array_map('intval', $row)) : $defaults;
}

function ppms_mlc_summary(PDO $pdo): array {
    $defaults = [
        'total_rows' => 0,
        'active_count' => 0,
        'inactive_count' => 0,
        'sitting_count' => 0,
        'up_count' => 0,
        'br_count' => 0,
        'ap_count' => 0,
        'states_covered' => 0,
    ];
    if (!$pdo->query("SHOW TABLES LIKE 'mlc'")->fetch()) {
        return $defaults;
    }
    $row = $pdo->query("
        SELECT
            COUNT(*) AS total_rows,
            SUM(UPPER(TRIM(status)) = 'ACTIVE') AS active_count,
            SUM(UPPER(TRIM(status)) <> 'ACTIVE') AS inactive_count,
            SUM(membership_status = 'Sitting') AS sitting_count,
            SUM(state_code = '9') AS up_count,
            SUM(state_code = '10') AS br_count,
            SUM(state_code = '28') AS ap_count,
            COUNT(DISTINCT CASE WHEN COALESCE(state_code, '') <> '' THEN state_code END) AS states_covered
        FROM mlc
    ")->fetch();
    return $row ? array_merge($defaults, array_map('intval', $row)) : $defaults;
}

function ppms_invalidate_village_summary_cache(): void {
    unset($_SESSION['ppms_village_summary_cache']);
}

function ppms_village_summary(PDO $pdo, bool $forceRefresh = false): array {
    $defaults = [
        'total_rows' => 0,
        'distinct_pincodes' => 0,
        'states_covered' => 0,
        'sdb_districts' => 0,
        'sdb_blocks' => 0,
    ];
    if (!$pdo->query("SHOW TABLES LIKE 'village'")->fetch()) {
        return $defaults;
    }

    if (!$forceRefresh && !empty($_SESSION['ppms_village_summary_cache']) && is_array($_SESSION['ppms_village_summary_cache'])) {
        $cached = $_SESSION['ppms_village_summary_cache'];
        if (((int)($cached['_ts'] ?? 0) + 60) > time()) {
            unset($cached['_ts']);
            return array_merge($defaults, $cached);
        }
    }

    $stats = [
        'total_rows' => (int)$pdo->query("SELECT COUNT(*) FROM village")->fetchColumn(),
        'distinct_pincodes' => (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT pincode FROM village WHERE pincode BETWEEN 100000 AND 999999) p")->fetchColumn(),
        'states_covered' => (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT state_code FROM village WHERE state_code IS NOT NULL) s")->fetchColumn(),
        'sdb_districts' => (int)$pdo->query("SELECT COUNT(DISTINCT district_code) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
        'sdb_blocks' => (int)$pdo->query("SELECT COUNT(*) FROM op_sdb WHERE status = 'ACTIVE'")->fetchColumn(),
    ];

    $_SESSION['ppms_village_summary_cache'] = $stats + ['_ts' => time()];
    return $stats;
}

function ppms_build_unique_mp_slug(PDO $pdo, string $rawInput, string $memberName, string $constituency, string $state, string $type, int $excludeId = 0): array {
    $manualProvided = trim($rawInput) !== '';
    $base = $manualProvided
        ? generate_mp_slug($rawInput, 140, false)
        : generate_mp_slug($memberName, 140, true);

    if ($base === '') {
        $base = 'mp';
    }

    $chk = $pdo->prepare("SELECT id FROM mp WHERE slug = ? AND id <> ? LIMIT 1");
    $chk->execute([$base, $excludeId]);
    if (!$chk->fetch()) {
        return ['ok' => true, 'slug' => $base];
    }

    if ($manualProvided) {
        return ['ok' => false, 'error' => "Slug '{$base}' is already used by another MP. Choose a unique slug or leave blank to auto-generate."];
    }

    $qualRaw = strtolower($type) === 'rs'
        ? ($state !== '' && strcasecmp($state, 'Nominated') !== 0 ? $state : $constituency)
        : (string)preg_replace('/\s*\((?:SC|ST)\)\s*$/i', '', $constituency);
    $qualSlug = generate_mp_slug($qualRaw, 60, false);
    if ($qualSlug !== '') {
        $candidate = substr($base, 0, 175) . '-' . $qualSlug;
        $chk->execute([$candidate, $excludeId]);
        if (!$chk->fetch()) {
            return ['ok' => true, 'slug' => $candidate];
        }
    }

    for ($n = 2; $n <= 99; $n++) {
        $candidate = substr($base, 0, 175) . '-' . $n;
        $chk->execute([$candidate, $excludeId]);
        if (!$chk->fetch()) {
            return ['ok' => true, 'slug' => $candidate];
        }
    }

    return ['ok' => true, 'slug' => substr($base, 0, 165) . '-' . time()];
}

function ppms_build_unique_mlc_slug(PDO $pdo, string $rawInput, string $memberName, string $stateCode, string $state, string $area, string $ctype, int $excludeId = 0): array {
    $prefix = get_mlc_state_slug_prefix($stateCode, $state);
    $manualProvided = trim($rawInput) !== '';

    if ($manualProvided) {
        $cleanManual = generate_mlc_slug($rawInput, 160, false);
        if ($cleanManual === '') {
            $cleanManual = generate_mlc_prefixed_slug($memberName, $stateCode, $state);
        } elseif (!str_starts_with($cleanManual, $prefix . '-')) {
            $cleanManual = $prefix . '-' . $cleanManual;
        }
        $base = $cleanManual;
    } else {
        $base = generate_mlc_prefixed_slug($memberName, $stateCode, $state);
    }

    if ($base === '' || $base === $prefix) {
        $base = $prefix . '-mlc';
    }

    $chk = $pdo->prepare("SELECT id FROM mlc WHERE slug = ? AND id <> ? LIMIT 1");
    $chk->execute([$base, $excludeId]);
    if (!$chk->fetch()) {
        return ['ok' => true, 'slug' => $base];
    }

    if ($manualProvided) {
        return ['ok' => false, 'error' => "Slug '{$base}' is already used by another MLC. Choose a unique slug or leave blank to auto-generate."];
    }

    $qualRaw = trim($area) !== '' ? $area : $ctype;
    $qualSlug = generate_mlc_slug($qualRaw, 60, false);
    if ($qualSlug !== '') {
        $candidate = substr($base, 0, 175) . '-' . $qualSlug;
        $chk->execute([$candidate, $excludeId]);
        if (!$chk->fetch()) {
            return ['ok' => true, 'slug' => $candidate];
        }
    }

    for ($n = 2; $n <= 99; $n++) {
        $candidate = substr($base, 0, 175) . '-' . $n;
        $chk->execute([$candidate, $excludeId]);
        if (!$chk->fetch()) {
            return ['ok' => true, 'slug' => $candidate];
        }
    }

    return ['ok' => true, 'slug' => substr($base, 0, 165) . '-' . time()];
}

function ppms_sitemap_stats(PDO $pdo): array {
    $coreStaticCount = 12; // home, index, leaders, issues, elections, parties, pincode, source, privacy-policy, disclaimer, terms-and-conditions, login, register (excluding private)
    $catCount = (int)$pdo->query("SELECT COUNT(*) FROM leader_types WHERE status = 'ACTIVE'")->fetchColumn();
    $stateCount = (int)$pdo->query("SELECT COUNT(DISTINCT state_code) FROM op_sdb WHERE status = 'ACTIVE' AND state_code <> ''")->fetchColumn();
    $districtCount = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT state_code, district_code FROM op_sdb WHERE status = 'ACTIVE' AND state_code <> '' AND district_code <> '') d")->fetchColumn();
    $mpCount = (int)$pdo->query("SELECT COUNT(*) FROM mp WHERE slug <> ''")->fetchColumn();
    $mlcCount = (int)$pdo->query("SELECT COUNT(*) FROM mlc WHERE UPPER(TRIM(status)) = 'ACTIVE' AND slug <> ''")->fetchColumn();
    $partyCount = (int)$pdo->query("SELECT COUNT(*) FROM political_party WHERE slug <> ''")->fetchColumn();
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE account_type = 'politician' AND status = 'active' AND profile_visibility = 'public' AND is_mobile_verified = 1 AND COALESCE(username_handle, '') <> ''")->fetchColumn();
    $vSummary = ppms_village_summary($pdo);
    $pinCount = (int)$vSummary['distinct_pincodes'];

    $coreTotal = $coreStaticCount + $catCount + ($stateCount * 2);
    $grandTotal = $coreTotal + $districtCount + $mpCount + $mlcCount + $partyCount + $pinCount + $userCount;

    return [
        'core' => $coreTotal,
        'districts' => $districtCount,
        'mps' => $mpCount,
        'mlcs' => $mlcCount,
        'parties' => $partyCount,
        'pincodes' => $pinCount,
        'villages' => (int)$vSummary['total_rows'],
        'users' => $userCount,
        'grand_total' => $grandTotal,
    ];
}


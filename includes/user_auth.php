<?php
// includes/user_auth.php - Public Citizen & Politician Auth, OTP, Profile & Masked Contact Reveal Helpers

if (!defined('LOCALNETA_USER_AUTH_LOADED')) {
    define('LOCALNETA_USER_AUTH_LOADED', true);
}

if (!defined('USER_DAILY_CONTACT_LIMIT')) {
    define('USER_DAILY_CONTACT_LIMIT', 10);
}

require_once __DIR__ . '/sms_helper.php';

function ensure_user_session(): void {
    if (defined('PPMS_INIT')) {
        return;
    }
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_name('LOCALNETA_USER_SESS');
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

ensure_user_session();

function get_user_csrf_token(): string {
    ensure_user_session();
    if (empty($_SESSION['user_csrf_token']) || !is_string($_SESSION['user_csrf_token'])) {
        $_SESSION['user_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['user_csrf_token'];
}

function verify_user_csrf_token(?string $token): bool {
    ensure_user_session();
    if (!is_string($token) || $token === '' || empty($_SESSION['user_csrf_token'])) {
        return false;
    }
    return hash_equals((string)$_SESSION['user_csrf_token'], $token);
}

function is_safe_local_redirect(?string $raw): string {
    if (!is_string($raw)) {
        return '';
    }
    $url = trim($raw);
    if ($url === '' || preg_match('/[\r\n\0\\\\]/', $url)) {
        return '';
    }

    // Disallow protocol-relative external redirects (//evil.com) or scheme smuggling
    if (str_starts_with($url, '//')) {
        return '';
    }

    $parsed = @parse_url($url);
    if ($parsed === false) {
        return '';
    }

    if (isset($parsed['scheme'])) {
        $scheme = strtolower((string)$parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return '';
        }
        $reqHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $targetHost = strtolower(trim((string)($parsed['host'] ?? '')));
        if (!empty($parsed['port'])) {
            $targetHost .= ':' . $parsed['port'];
        }
        if ($reqHost === '' || $targetHost !== $reqHost) {
            return '';
        }
        $path = (string)($parsed['path'] ?? '/');
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';
        $frag = isset($parsed['fragment']) && $parsed['fragment'] !== '' ? '#' . $parsed['fragment'] : '';
        $normalized = $path . $query . $frag;
    } else {
        if (!empty($parsed['host']) || !empty($parsed['user'])) {
            return '';
        }
        $normalized = $url;
    }

    if (preg_match('~^/?(?:ppms|admin)(?:[/?#]|$)~i', $normalized)) {
        return '';
    }

    if ($normalized !== '' && $normalized[0] !== '/') {
        if (function_exists('get_site_url')) {
            return get_site_url($normalized);
        }
        return '/' . ltrim($normalized, '/');
    }

    return $normalized;
}

function get_current_request_redirect(): string {
    $uri = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($uri === '') {
        return function_exists('get_site_url') ? get_site_url('index') : '/';
    }
    $safe = is_safe_local_redirect($uri);
    if ($safe !== '') {
        return $safe;
    }
    return function_exists('get_site_url') ? get_site_url('index') : '/';
}

function normalize_indian_mobile(string $raw): string {
    $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : '';
}

function is_user_logged_in(): bool {
    ensure_user_session();
    return !empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
}

function get_current_user_id(): int {
    ensure_user_session();
    return (int)($_SESSION['user_id'] ?? 0);
}

function get_current_user_account(PDO $pdo, bool $refresh = false): ?array {
    static $cachedUser = null;
    if (!is_user_logged_in()) {
        $cachedUser = null;
        return null;
    }

    $uid = get_current_user_id();
    if ($refresh || $cachedUser === null || (int)($cachedUser['id'] ?? 0) !== $uid) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row) {
            logout_user_account();
            $cachedUser = null;
            return null;
        }
        $cachedUser = $row;
    }
    return $cachedUser;
}

function require_user_login(PDO $pdo, ?string $redirectUrl = null): array {
    $user = get_current_user_account($pdo);
    if ($user !== null) {
        return $user;
    }

    $target = is_safe_local_redirect($redirectUrl) ?: get_current_request_redirect();
    $loginUrl = get_site_url('login');
    if ($target !== '') {
        $loginUrl .= '?redirect=' . urlencode($target);
    }
    header('Location: ' . $loginUrl);
    exit;
}

function set_user_session(PDO $pdo, array $user): void {
    ensure_user_session();
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_account_type'] = (string)($user['account_type'] ?? 'user');
    $_SESSION['user_name'] = (string)($user['name'] ?? '');
    $_SESSION['user_handle'] = (string)($user['username_handle'] ?? '');
    $_SESSION['user_role'] = (string)($user['role'] ?? 'citizen');

    unset($_SESSION['pending_user_id'], $_SESSION['pending_mobile'], $_SESSION['pending_purpose'], $_SESSION['local_dev_otp']);

    $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
    $upd->execute([(int)$user['id']]);
}

function logout_user_account(): void {
    ensure_user_session();
    unset(
        $_SESSION['user_id'],
        $_SESSION['user_account_type'],
        $_SESSION['user_name'],
        $_SESSION['user_handle'],
        $_SESSION['user_role'],
        $_SESSION['pending_user_id'],
        $_SESSION['pending_mobile'],
        $_SESSION['pending_purpose'],
        $_SESSION['local_dev_otp']
    );
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

function generate_unique_user_handle(PDO $pdo, string $name, ?string $requested = null, int $excludeUserId = 0): string {
    $raw = trim((string)($requested !== null && $requested !== '' ? $requested : $name));
    $raw = ltrim($raw, '@');
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        if (is_string($conv) && $conv !== '') {
            $raw = $conv;
        }
    }
    $base = strtolower($raw);
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-');
    if (strlen($base) < 3) {
        $base = 'citizen-' . substr(bin2hex(random_bytes(3)), 0, 5);
    }
    if (strlen($base) > 55) {
        $base = rtrim(substr($base, 0, 55), '-');
    }

    $candidate = $base;
    $suffix = 1;
    $chk = $pdo->prepare("SELECT id FROM users WHERE username_handle = ? AND id <> ? LIMIT 1");
    while (true) {
        $chk->execute([$candidate, $excludeUserId]);
        if (!$chk->fetch()) {
            return $candidate;
        }
        $suffix++;
        $candidate = substr($base, 0, 48) . '-' . $suffix;
    }
}

function send_user_otp(PDO $pdo, int $userId, string $purpose = 'verification'): array {
    $stmt = $pdo->prepare("SELECT id, name, mobile FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || empty($user['mobile'])) {
        return ['success' => false, 'message' => 'User account not found.'];
    }

    $otp = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $upd = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?");
    $upd->execute([$otp, $userId]);

    $userName = trim((string)($user['name'] ?? 'User'));
    $smsRes = send_otp_sms((string)$user['mobile'], $otp, $purpose, $userName);
    if (is_local_dev_env() && ($smsRes['channel'] ?? '') === 'local_dev') {
        ensure_user_session();
        $_SESSION['local_dev_otp'] = $otp;
    }

    return array_merge($smsRes, [
        'masked_mobile' => function_exists('mask_phone_number') ? mask_phone_number((string)$user['mobile']) : (string)$user['mobile'],
    ]);
}

function verify_user_otp(PDO $pdo, int $userId, string $otp): bool {
    $cleanOtp = preg_replace('/\D+/', '', trim($otp)) ?? '';
    if (strlen($cleanOtp) !== 6) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT id, otp_code, otp_expiry FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $isValid = false;
    if (!empty($user['otp_code']) && !empty($user['otp_expiry'])) {
        $notExpired = strtotime((string)$user['otp_expiry']) >= time();
        if ($notExpired && hash_equals((string)$user['otp_code'], $cleanOtp)) {
            $isValid = true;
        }
    }

    // Standard local development bypass OTP (strictly gated to local environments)
    if (!$isValid && is_local_dev_env() && $cleanOtp === '123456') {
        $isValid = true;
    }

    if ($isValid) {
        $upd = $pdo->prepare("UPDATE users SET is_mobile_verified = 1, otp_code = NULL, otp_expiry = NULL WHERE id = ?");
        $upd->execute([$userId]);
        return true;
    }

    return false;
}

function get_user_by_handle(PDO $pdo, string $handle): ?array {
    $clean = ltrim(trim($handle), '@');
    if ($clean === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username_handle = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$clean]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function increment_user_profile_views(PDO $pdo, int $userId): void {
    $upd = $pdo->prepare("UPDATE users SET counter = counter + 1 WHERE id = ?");
    $upd->execute([$userId]);
}

function get_profile_completion_percent(array $user): int {
    $fields = [
        'name',
        'mobile',
        'email',
        'state_code',
        'district_code',
        'bio',
        'profile_photo',
    ];
    if (($user['account_type'] ?? 'user') === 'politician') {
        $fields[] = 'leader_category';
        $fields[] = 'constituency';
        $fields[] = 'party_name';
    } else {
        $fields[] = 'designation';
        $fields[] = 'address';
    }

    $filled = 0;
    foreach ($fields as $f) {
        if (!empty(trim((string)($user[$f] ?? '')))) {
            $filled++;
        }
    }
    if (!empty($user['is_mobile_verified'])) {
        $filled++;
    }
    $total = count($fields) + 1;
    return (int)min(100, round(($filled / max(1, $total)) * 100));
}

function get_user_avatar_url(?string $photoPath): string {
    $clean = trim((string)$photoPath);
    if ($clean === '') {
        return get_site_url('assets/images/dummy-user.png');
    }
    if (preg_match('#^https?://#i', $clean)) {
        return $clean;
    }
    return get_site_url(ltrim($clean, '/'));
}

function upload_user_avatar(PDO $pdo, int $userId, array $file): array {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['success' => false, 'message' => 'Profile photo must be 50 KB or smaller.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed or no file selected.'];
    }
    if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > 50 * 1024) {
        return ['success' => false, 'message' => 'Profile photo must be 50 KB or smaller.'];
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['success' => false, 'message' => 'Invalid upload source.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        return ['success' => false, 'message' => 'Only JPG, PNG, and WEBP images are allowed.'];
    }

    $uploadDir = __DIR__ . '/../assets/uploads/avatars';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    $filename = 'avatar-' . $userId . '-' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
    $destPath = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($tmpName, $destPath)) {
        return ['success' => false, 'message' => 'Unable to store uploaded profile photo.'];
    }

    // Remove previous local avatar file if present
    $oldStmt = $pdo->prepare("SELECT profile_photo FROM users WHERE id = ? LIMIT 1");
    $oldStmt->execute([$userId]);
    $oldPhoto = trim((string)($oldStmt->fetchColumn() ?: ''));
    if ($oldPhoto !== '' && str_starts_with($oldPhoto, 'assets/uploads/avatars/')) {
        $oldFull = __DIR__ . '/../' . $oldPhoto;
        if (is_file($oldFull)) {
            @unlink($oldFull);
        }
    }

    $relPath = 'assets/uploads/avatars/' . $filename;
    $upd = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
    $upd->execute([$relPath, $userId]);

    return ['success' => true, 'path' => $relPath, 'url' => get_site_url($relPath)];
}

function get_user_daily_reveal_count(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contact_reveals WHERE user_id = ? AND revealed_date = CURDATE()");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function get_user_revealed_entity_keys_today(PDO $pdo, int $userId): array {
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }
    $stmt = $pdo->prepare("SELECT entity_type, entity_id FROM contact_reveals WHERE user_id = ? AND revealed_date = CURDATE()");
    $stmt->execute([$userId]);
    $keys = [];
    foreach ($stmt as $r) {
        $keys[$r['entity_type'] . ':' . (int)$r['entity_id']] = true;
    }
    $cache[$userId] = $keys;
    return $keys;
}

function get_user_revealed_contacts(PDO $pdo, int $userId, string $mode = 'today'): array {
    if ($mode === 'today') {
        $stmt = $pdo->prepare("
            SELECT * FROM contact_reveals
            WHERE user_id = ? AND revealed_date = CURDATE()
            ORDER BY created_at DESC, id DESC
            LIMIT 100
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare("
        SELECT * FROM contact_reveals
        WHERE user_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 200
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function get_entity_type_label(string $entityType): string {
    return match ($entityType) {
        'mlc'        => 'MLC',
        'politician' => 'MLA',
        'leader'     => 'Representative',
        'user'       => 'Member',
        default      => 'Contact',
    };
}

/**
 * Render a privacy-safe masked contact slot with interactive reveal trigger.
 * NEVER places raw digits, raw email addresses, or decodable contact tokens in HTML.
 */
function render_contact_reveal_trigger(
    string $entityType,
    int $entityId,
    string $field,
    string $maskedValue,
    string $targetName = '',
    array $options = []
): string {
    if (trim($maskedValue) === '' || $entityId <= 0) {
        return '';
    }

    $variant = $options['variant'] ?? 'box';
    $icon = $options['icon'] ?? ($field === 'email' ? 'fas fa-envelope' : ($field === 'whatsapp' ? 'fab fa-whatsapp' : 'fas fa-phone'));
    $isLoggedIn = is_user_logged_in();
    $alreadyUnlocked = !empty($options['unlocked_today']);

    $btnLabel = $isLoggedIn
        ? ($alreadyUnlocked ? 'Unlocked — Click to Show' : 'Click to View')
        : 'Login to View';
    $btnIcon = $isLoggedIn ? 'fas fa-eye' : 'fas fa-lock';
    $authFlag = $isLoggedIn ? '0' : '1';

    $attrs = 'data-entity-type="' . e($entityType) . '"'
        . ' data-entity-id="' . $entityId . '"'
        . ' data-field="' . e($field) . '"'
        . ' data-target-name="' . e($targetName) . '"'
        . ' data-auth-required="' . $authFlag . '"';

    if ($variant === 'pill') {
        return '<span class="contact-reveal-wrap contact-reveal-pill" ' . $attrs . '>'
            . '<button type="button" class="contact-reveal-trigger" onclick="handleContactRevealClick(this)" title="' . e($isLoggedIn ? 'Click to reveal full contact details' : 'Sign in to view full mobile & email') . '">'
            . '<i class="' . e($icon) . '"></i> '
            . '<span class="contact-masked-value">' . e($maskedValue) . '</span> '
            . '<span class="contact-action-chip"><i class="' . $btnIcon . '"></i> ' . ($isLoggedIn ? 'View' : 'Unlock') . '</span>'
            . '</button>'
            . '<span class="contact-unlocked-value" hidden></span>'
            . '</span>';
    }

    if ($variant === 'cta') {
        return '<span class="contact-reveal-wrap contact-reveal-cta" ' . $attrs . '>'
            . '<button type="button" class="contact-reveal-trigger" onclick="handleContactRevealClick(this)" title="' . e($isLoggedIn ? 'Click to reveal full contact details' : 'Sign in to view unmasked mobile & email') . '">'
            . '<i class="' . e($icon) . '"></i> '
            . '<span class="contact-masked-value">' . e($maskedValue) . '</span> '
            . '<span class="contact-action-chip"><i class="' . $btnIcon . '"></i> ' . e($isLoggedIn ? ($alreadyUnlocked ? 'Show' : 'Reveal') : 'Login to View') . '</span>'
            . '</button>'
            . '<span class="contact-unlocked-value" hidden></span>'
            . '</span>';
    }

    // Default 'box' variant (used inside MLC / User / Profile detail lists)
    return '<div class="contact-reveal-wrap contact-reveal-box" ' . $attrs . '>'
        . '<div class="contact-reveal-masked-row">'
        . '<span class="contact-masked-value">' . e($maskedValue) . '</span>'
        . '<button type="button" class="contact-reveal-trigger" onclick="handleContactRevealClick(this)">'
        . '<i class="' . $btnIcon . '"></i> ' . e($btnLabel)
        . '</button>'
        . '</div>'
        . '<div class="contact-unlocked-value" hidden></div>'
        . '</div>';
}


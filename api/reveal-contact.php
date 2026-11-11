<?php
// api/reveal-contact.php - Session-Gated Server-Side Contact Reveal Endpoint (Phone & Email)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

define('APP_INIT', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$pdo = get_db_connection();
$currentUser = get_current_user_account($pdo);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode([
        'success'       => false,
        'require_login' => true,
        'login_url'     => get_site_url('login'),
        'register_url'  => get_site_url('register'),
        'message'       => 'Please sign in or register as a Citizen / Politician to view full mobile numbers and email IDs.'
    ]);
    exit;
}

$rawBody = file_get_contents('php://input');
$input = is_string($rawBody) && $rawBody !== '' ? json_decode($rawBody, true) : null;
if (!is_array($input)) {
    $input = $_POST;
}

$csrfToken = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!verify_user_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page and try again.']);
    exit;
}

$entityType = strtolower(trim((string)($input['entity_type'] ?? '')));
$entityId = (int)($input['entity_id'] ?? 0);
$field = strtolower(trim((string)($input['field'] ?? 'mobile')));

if (!in_array($entityType, ['mlc', 'politician', 'leader', 'user'], true) || $entityId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid profile reference.']);
    exit;
}

if (!in_array($field, ['mobile', 'alternate_mobile', 'office_tel', 'whatsapp', 'email', 'all'], true)) {
    $field = 'mobile';
}

$isPlaceholder = static function (string $val): bool {
    $norm = strtolower(trim(str_replace("\xC2\xA0", ' ', $val)));
    return in_array($norm, ['', 'na', 'n/a', 'n.a', 'n.a.', 'nil', 'none', '-', '--', '0', 'not available', 'not applicable', 'null'], true);
};

$cleanPhoneList = static function (?string $raw) use ($isPlaceholder): string {
    $str = trim((string)$raw);
    if ($isPlaceholder($str)) {
        return '';
    }
    $parts = preg_split('/\s*[,;\/|]+\s*/', $str) ?: [$str];
    $valid = [];
    foreach ($parts as $p) {
        $p = trim($p);
        $dig = preg_replace('/\D+/', '', $p) ?? '';
        if (strlen($dig) >= 5 && !$isPlaceholder($p)) {
            $valid[] = $p;
        }
    }
    return implode(', ', $valid);
};

$cleanEmailList = static function (?string $raw) use ($isPlaceholder): string {
    $str = trim(str_replace("\xC2\xA0", ' ', (string)$raw));
    if ($isPlaceholder($str)) {
        return '';
    }
    $parts = preg_split('/\s*[,;]+\s*/', $str) ?: [$str];
    $valid = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $valid[] = $p;
        }
    }
    return implode(', ', $valid);
};

$mobile = '';
$altMobile = '';
$officeTel = '';
$whatsapp = '';
$email = '';
$targetName = '';
$targetUrl = '';

$currentUserId = (int)$currentUser['id'];

if ($entityType === 'mlc') {
    $stmt = $pdo->prepare("SELECT id, slug, member_name, mobile, alternate_mobile, office_tel, email FROM mlc WHERE id = ? AND status = 'ACTIVE' LIMIT 1");
    $stmt->execute([$entityId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'MLC record not found.']);
        exit;
    }
    $targetName = (string)$row['member_name'];
    $targetUrl = get_mlc_page_url((string)($row['slug'] ?? ''), (int)$row['id']);
    $mobile = $cleanPhoneList($row['mobile'] ?? '');
    $altMobile = $cleanPhoneList($row['alternate_mobile'] ?? '');
    $officeTel = $cleanPhoneList($row['office_tel'] ?? '');
    $whatsapp = $mobile;
    $email = $cleanEmailList($row['email'] ?? '');
} elseif ($entityType === 'politician') {
    $stmt = $pdo->prepare("SELECT id, c_type, c_name, name, mobile, tel FROM politician WHERE id = ? LIMIT 1");
    $stmt->execute([$entityId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Legislator record not found.']);
        exit;
    }
    $targetName = format_civic_title((string)$row['name']);
    $targetUrl = get_site_url('leaders?' . http_build_query(['cat' => 'mla', 'state_code' => '10', 'q' => $targetName]));
    $mobile = $cleanPhoneList($row['mobile'] ?? '');
    $officeTel = $cleanPhoneList($row['tel'] ?? '');
    if ($mobile === '' && $officeTel !== '') {
        $mobile = $officeTel;
    }
    $whatsapp = $mobile;
} elseif ($entityType === 'leader') {
    $stmt = $pdo->prepare("SELECT id, full_name_en, phone, whatsapp, email FROM leaders WHERE id = ? LIMIT 1");
    $stmt->execute([$entityId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Representative profile not found.']);
        exit;
    }
    $targetName = (string)$row['full_name_en'];
    $targetUrl = get_site_url('profile?id=' . (int)$row['id']);
    $rawMob = $cleanPhoneList($row['phone'] ?? '');
    $rawWa = $cleanPhoneList($row['whatsapp'] ?? '');
    $mobile = $rawMob !== '' ? $rawMob : $rawWa;
    $whatsapp = $rawWa !== '' ? $rawWa : $rawMob;
    $email = $cleanEmailList($row['email'] ?? '');
} elseif ($entityType === 'user') {
    $stmt = $pdo->prepare("
        SELECT id, name, full_name, username_handle, account_type, mobile, whatsapp, email,
               profile_visibility, mobile_visibility, email_visibility
        FROM users
        WHERE id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$entityId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Member profile not found.']);
        exit;
    }

    $isSelf = ($entityId === $currentUserId);
    if (!$isSelf && ($row['profile_visibility'] ?? 'public') === 'private') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This profile is set to Private by the member.']);
        exit;
    }

    $canSeeMobile = $isSelf || ($row['mobile_visibility'] ?? 'masked') !== 'private';
    $canSeeEmail = $isSelf || ($row['email_visibility'] ?? 'masked') !== 'private';

    if ($field === 'mobile' && !$canSeeMobile) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This member has marked their mobile number as private.']);
        exit;
    }
    if ($field === 'whatsapp' && !$canSeeMobile) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This member has marked their WhatsApp number as private.']);
        exit;
    }
    if ($field === 'email' && !$canSeeEmail) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This member has marked their email address as private.']);
        exit;
    }

    $targetName = (string)($row['full_name'] ?: $row['name']);
    $targetUrl = get_user_profile_url((string)($row['username_handle'] ?? ''), (int)$row['id']);
    if ($canSeeMobile) {
        $mobile = $cleanPhoneList($row['mobile'] ?? '');
        $whatsapp = $cleanPhoneList($row['whatsapp'] ?? '') ?: $mobile;
    }
    if ($canSeeEmail) {
        $email = $cleanEmailList($row['email'] ?? '');
    }
}

$requestedValue = match ($field) {
    'mobile'           => $mobile !== '' ? $mobile : ($whatsapp !== '' ? $whatsapp : ($altMobile !== '' ? $altMobile : $officeTel)),
    'alternate_mobile' => $altMobile,
    'office_tel'       => $officeTel,
    'whatsapp'         => $whatsapp !== '' ? $whatsapp : $mobile,
    'email'            => $email,
    'all'              => $mobile !== '' ? $mobile : $email,
};

if ($requestedValue === '' && $mobile === '' && $email === '' && $altMobile === '' && $officeTel === '') {
    echo json_encode(['success' => false, 'message' => 'No verified contact number or email is published for this record.']);
    exit;
}

if ($field !== 'all' && $requestedValue === '') {
    echo json_encode(['success' => false, 'message' => 'Requested contact field is unavailable for this record.']);
    exit;
}

$existingStmt = $pdo->prepare("
    SELECT id FROM contact_reveals
    WHERE user_id = ? AND entity_type = ? AND entity_id = ? AND revealed_date = CURDATE()
    LIMIT 1
");
$existingStmt->execute([$currentUserId, $entityType, $entityId]);
$alreadyUnlocked = (bool)$existingStmt->fetchColumn();

$summaryMobile = mb_substr(implode(', ', array_unique(array_filter([$mobile, $altMobile, $officeTel], fn($v) => $v !== ''))), 0, 115, 'UTF-8');
$summaryEmail = mb_substr($email, 0, 175, 'UTF-8');

if (!$alreadyUnlocked) {
    $todayCount = get_user_daily_reveal_count($pdo, $currentUserId);
    if ($todayCount >= USER_DAILY_CONTACT_LIMIT) {
        http_response_code(429);
        echo json_encode([
            'success'           => false,
            'limit_reached'     => true,
            'reveals_today'     => $todayCount,
            'reveals_remaining' => 0,
            'max_daily'         => USER_DAILY_CONTACT_LIMIT,
            'dashboard_url'     => get_site_url('dashboard'),
            'message'           => 'Daily contact unlock limit (' . USER_DAILY_CONTACT_LIMIT . ' per day) reached. Visit your Dashboard to view previously unlocked contacts.'
        ]);
        exit;
    }

    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ins = $pdo->prepare("
        INSERT INTO contact_reveals (
            user_id, ip_address, entity_type, entity_id, target_name, target_url,
            revealed_mobile, revealed_email, revealed_date, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW())
    ");
    $ins->execute([
        $currentUserId,
        $ip,
        $entityType,
        $entityId,
        mb_substr($targetName, 0, 195, 'UTF-8'),
        mb_substr($targetUrl, 0, 250, 'UTF-8'),
        $summaryMobile !== '' ? $summaryMobile : null,
        $summaryEmail !== '' ? $summaryEmail : null,
    ]);
} else {
    $upd = $pdo->prepare("
        UPDATE contact_reveals
        SET revealed_mobile = COALESCE(NULLIF(revealed_mobile, ''), ?),
            revealed_email  = COALESCE(NULLIF(revealed_email, ''), ?)
        WHERE user_id = ? AND entity_type = ? AND entity_id = ? AND revealed_date = CURDATE()
    ");
    $upd->execute([
        $summaryMobile !== '' ? $summaryMobile : null,
        $summaryEmail !== '' ? $summaryEmail : null,
        $currentUserId,
        $entityType,
        $entityId,
    ]);
}

$revealsToday = get_user_daily_reveal_count($pdo, $currentUserId);
$revealsRemaining = max(0, USER_DAILY_CONTACT_LIMIT - $revealsToday);

echo json_encode([
    'success'                => true,
    'entity_type'            => $entityType,
    'entity_id'              => $entityId,
    'field'                  => $field,
    'value'                  => $requestedValue,
    'contacts'               => [
        'mobile'           => $mobile,
        'alternate_mobile' => $altMobile,
        'office_tel'       => $officeTel,
        'whatsapp'         => $whatsapp,
        'email'            => $email,
    ],
    'target_name'            => $targetName,
    'target_url'             => $targetUrl,
    'already_unlocked_today' => $alreadyUnlocked,
    'reveals_today'          => $revealsToday,
    'reveals_remaining'      => $revealsRemaining,
    'max_daily'              => USER_DAILY_CONTACT_LIMIT,
]);


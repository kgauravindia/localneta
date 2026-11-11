<?php
// api/pincode.php - JSON API for Pan-India PIN Code & Village Search (`village` + `op_sdb`)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pincode_helper.php';

$pdo = get_db_connection();

$rawPincode = trim((string)($_GET['pincode'] ?? ''));
$rawQuery = trim((string)($_GET['q'] ?? ''));
$stateCode = trim((string)($_GET['state_code'] ?? ''));
$districtCode = trim((string)($_GET['district_code'] ?? ''));
$blockCode = trim((string)($_GET['block_code'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 50)));
$offset = ($page - 1) * $perPage;

if (!has_village_pincode_table($pdo)) {
    echo json_encode([
        'success' => false,
        'message' => 'Village and PIN code directory is currently unavailable.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Promote 6-digit PIN from `q` into exact PIN mode when `pincode` isn't explicitly passed
if ($rawPincode === '' && is_valid_indian_pincode($rawQuery)) {
    $rawPincode = $rawQuery;
}

if ($rawPincode !== '') {
    $pinDigits = normalize_pincode_digits($rawPincode);
    if (!is_valid_indian_pincode($pinDigits)) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid 6-digit Indian PIN code (e.g. 841301).',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $summary = get_pincode_area_summary($pdo, $pinDigits);
    if ($summary === null) {
        echo json_encode([
            'success' => false,
            'pincode' => $pinDigits,
            'message' => "No villages or LGD areas matched PIN code {$pinDigits}.",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $villagesData = search_pincode_villages($pdo, [
        'pincode' => $pinDigits,
        'block_code' => $blockCode,
    ], $perPage, $offset);

    $primary = $summary['primary'];
    $areaQuery = http_build_query(array_filter([
        'state_code' => $primary['state_code'],
        'district_code' => $primary['district_code'],
        'block_code' => $primary['lgd_matched'] ? $primary['block_code'] : '',
        'pincode' => $pinDigits,
    ], fn($v) => $v !== ''));

    echo json_encode([
        'success' => true,
        'mode' => 'pincode',
        'pincode' => $pinDigits,
        'summary' => $summary,
        'total_villages' => $villagesData['total'],
        'page' => $page,
        'per_page' => $perPage,
        'villages' => $villagesData['rows'],
        'pincode_url' => get_pincode_page_url($pinDigits),
        'home_url' => get_site_url('index?' . $areaQuery),
        'leaders_url' => get_site_url('leaders?' . $areaQuery),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Reject pure numeric queries that are neither valid PINs nor 3-5 digit PIN prefixes (e.g. 10-digit mobiles)
if ($rawQuery !== '' && preg_match('/^\d+$/', $rawQuery) && !is_pincode_prefix_query($rawQuery)) {
    echo json_encode([
        'success' => true,
        'mode' => 'search',
        'pincodes' => [],
        'villages' => [],
        'total_villages' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($rawQuery === '' && $stateCode === '' && $districtCode === '' && $blockCode === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Provide a 6-digit pincode, PIN prefix, village/block name, or State/District/Block selection.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$filters = [
    'q' => $rawQuery,
    'state_code' => $stateCode,
    'district_code' => $districtCode,
    'block_code' => $blockCode,
];

$pincodeGroups = search_matching_pincode_groups($pdo, $filters, 36);
$villagesData = search_pincode_villages($pdo, $filters, $perPage, $offset);

echo json_encode([
    'success' => true,
    'mode' => 'search',
    'pincodes' => $pincodeGroups,
    'total_villages' => $villagesData['total'],
    'page' => $page,
    'per_page' => $perPage,
    'villages' => $villagesData['rows'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


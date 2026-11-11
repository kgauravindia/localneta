<?php
// api/locations.php - Dynamic State / District / Block (from op_sdb) & Panchayat API
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pincode_helper.php';

$pdo = get_db_connection();
$type = $_GET['type'] ?? '';

if ($type === 'pincode') {
    $pin = normalize_pincode_digits($_GET['pincode'] ?? ($_GET['q'] ?? ''));
    if (!is_valid_indian_pincode($pin)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid 6-digit Indian PIN code']);
        exit;
    }
    $summary = get_pincode_area_summary($pdo, $pin);
    if ($summary === null) {
        echo json_encode(['success' => false, 'message' => "PIN code {$pin} not found in LGD directory"]);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($type === 'villages') {
    $blockCode = trim((string)($_GET['block_code'] ?? ''));
    if ($blockCode === '' || !ctype_digit($blockCode) || !has_village_pincode_table($pdo)) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    $stmt = $pdo->prepare("
        SELECT village_code, village, pincode
        FROM village
        WHERE block_code = ?
        ORDER BY village ASC
        LIMIT 300
    ");
    $stmt->execute([(int)$blockCode]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($type === 'states') {
    $rows = $pdo->query("SELECT DISTINCT state_code, state FROM op_sdb WHERE status = 'ACTIVE' ORDER BY state ASC")->fetchAll();
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

if ($type === 'districts') {
    $stateCode = trim($_GET['state_code'] ?? '10');
    $stmt = $pdo->prepare("SELECT DISTINCT district_code, district FROM op_sdb WHERE state_code = ? AND status = 'ACTIVE' ORDER BY district ASC");
    $stmt->execute([$stateCode]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

if ($type === 'blocks') {
    $districtCode = trim($_GET['district_code'] ?? '218');
    $stmt = $pdo->prepare("SELECT block_code, block FROM op_sdb WHERE district_code = ? AND status = 'ACTIVE' ORDER BY block ASC");
    $stmt->execute([$districtCode]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    exit;
}

if ($type === 'panchayats') {
    $blockCode = trim($_GET['block_code'] ?? '1257');
    $rows = [];
    if ($blockCode !== '' && $pdo->query("SHOW TABLES LIKE 'panchayat_village'")->fetch()) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT panchayat_code AS id, panchayat_name AS name_raw
            FROM panchayat_village
            WHERE block_code = ? AND TRIM(panchayat_name) <> ''
            ORDER BY panchayat_name ASC
        ");
        $stmt->execute([(int)$blockCode]);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'id' => (int)$r['id'],
                'name_en' => format_civic_title($r['name_raw']),
                'name_hi' => '',
            ];
        }
    }
    if (empty($rows) && $blockCode !== '') {
        $stmt = $pdo->prepare("SELECT id, name_en, name_hi FROM panchayats WHERE block_code = ? ORDER BY name_en ASC");
        $stmt->execute([$blockCode]);
        $rows = $stmt->fetchAll();
    }
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid location lookup type']);


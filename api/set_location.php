<?php
// api/set_location.php - Persist active State/District/Block/Panchayat (from op_sdb) in session
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input) {
    $_SESSION['active_state_code'] = trim(strip_tags($input['state_code'] ?? ''));
    $_SESSION['active_state_name'] = trim(strip_tags($input['state_name'] ?? ''));
    $_SESSION['active_district_code'] = trim(strip_tags($input['district_code'] ?? ''));
    $_SESSION['active_district_name'] = trim(strip_tags($input['district_name'] ?? ''));
    $_SESSION['active_block_code'] = trim(strip_tags($input['block_code'] ?? ''));
    $_SESSION['active_block_name'] = trim(strip_tags($input['block_name'] ?? ''));
    $_SESSION['active_panchayat_id'] = (int)($input['panchayat_id'] ?? 0);
    $_SESSION['active_panchayat_name'] = trim(strip_tags($input['panchayat_name'] ?? ''));

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid data']);

<?php
// api/issues.php - Citizen Issue submission & upvoting (MySQL + op_sdb)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db_connection();
$action = $_GET['action'] ?? 'submit';

if ($action === 'upvote') {
    $input = json_decode(file_get_contents('php://input'), true);
    $issueId = (int)($input['issue_id'] ?? 0);
    if ($issueId > 0) {
        $stmt = $pdo->prepare("UPDATE public_issues SET upvotes = upvotes + 1 WHERE id = ?");
        $stmt->execute([$issueId]);

        $stmt = $pdo->prepare("SELECT upvotes FROM public_issues WHERE id = ?");
        $stmt->execute([$issueId]);
        $count = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'new_count' => $count]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $categoryMap = [
        'road' => 'Roads & Drainage',
        'drainage' => 'Drainage & Sanitation',
        'water' => 'Drinking Water (Nal-Jal)',
        'solar' => 'Street Lighting',
        'health' => 'Healthcare Facility',
        'school' => 'School Infrastructure'
    ];

    $title = trim($_POST['title_hi'] ?? '');
    $description = trim($_POST['description_hi'] ?? '');
    $category = trim($_POST['category'] ?? 'road');
    $categoryName = trim($_POST['category_name_hi'] ?? ($categoryMap[$category] ?? 'Roads & Drainage'));
    $reporterName = trim($_POST['reported_by_name'] ?? 'Local Citizen');
    $locality = trim($_POST['locality_hi'] ?? 'Ward Area');
    $panchayatId = !empty($_POST['panchayat_id']) ? (int)$_POST['panchayat_id'] : null;
    $stateCode = trim($_POST['state_code'] ?? '');
    $districtCode = trim($_POST['district_code'] ?? '');
    $blockCode = trim($_POST['block_code'] ?? '');

    // Derive state_code & district_code from op_sdb if block_code was passed
    if ($blockCode !== '') {
        $locStmt = $pdo->prepare("SELECT state_code, district_code FROM op_sdb WHERE block_code = ? LIMIT 1");
        $locStmt->execute([$blockCode]);
        if ($loc = $locStmt->fetch()) {
            $stateCode = $loc['state_code'];
            $districtCode = $loc['district_code'];
        }
    }

    if (!empty($title) && !empty($description)) {
        $stmt = $pdo->prepare("
            INSERT INTO public_issues (
                panchayat_id, state_code, district_code, block_code, title_hi, description_hi, category, category_name_hi,
                reported_by_name, locality_hi, status, status_label_hi, department_hi, upvotes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'under_review', 'Verification Pending', 'Concerned Panchayat & Block Office', 1)
        ");
        $stmt->execute([$panchayatId, $stateCode, $districtCode, $blockCode !== '' ? $blockCode : null, $title, $description, $category, $categoryName, $reporterName, $locality]);

        header("Location: " . get_site_url('issues?tab=issues&reported=success'));
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);

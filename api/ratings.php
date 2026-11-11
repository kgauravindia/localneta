<?php
// api/ratings.php - Citizen 5-parameter rating submission (MySQL)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid data payload']);
    exit;
}

$leaderId = (int)($input['leader_id'] ?? 0);
$road = max(1, min(5, (int)($input['road_drainage'] ?? 4)));
$sanitation = max(1, min(5, (int)($input['sanitation'] ?? 4)));
$water = max(1, min(5, (int)($input['drinking_water'] ?? 4)));
$access = max(1, min(5, (int)($input['accessibility'] ?? 5)));
$action = max(1, min(5, (int)($input['grievance_redressal'] ?? 4)));
$comment = trim($input['feedback_comment'] ?? '');

$phoneHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') . date('Y-m-d'));

if ($leaderId > 0) {
    $stmt = $pdo->prepare("
        INSERT INTO public_ratings (
            leader_id, phone_hash, road_drainage, sanitation, drinking_water, accessibility, grievance_redressal, feedback_comment
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$leaderId, $phoneHash, $road, $sanitation, $water, $access, $action, $comment]);

    echo json_encode(['success' => true, 'message' => 'Rating submitted successfully']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Missing representative ID']);


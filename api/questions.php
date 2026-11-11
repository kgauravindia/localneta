<?php
// api/questions.php - Citizen Questions to Leaders Handler (MySQL)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid data payload']);
    exit;
}

$action = $input['action'] ?? 'ask';

if ($action === 'ask') {
    $leaderId = (int)($input['leader_id'] ?? 0);
    $name = trim($input['citizen_name'] ?? 'Local Citizen');
    $locality = trim($input['citizen_locality'] ?? 'Ward Area');
    $text = trim($input['question_text'] ?? '');

    if ($leaderId > 0 && !empty($text)) {
        $stmt = $pdo->prepare("
            INSERT INTO public_questions (
                leader_id, citizen_name, citizen_locality, question_text, status, status_label_hi
            ) VALUES (?, ?, ?, ?, 'sent_to_rep', 'Sent to Representative')
        ");
        $stmt->execute([$leaderId, $name, $locality, $text]);

        echo json_encode(['success' => true, 'message' => 'Question submitted successfully']);
        exit;
    }
} elseif ($action === 'reply') {
    // Reply from leader dashboard
    $questionId = (int)($input['question_id'] ?? 0);
    $replyText = trim($input['answer_text'] ?? '');

    if ($questionId > 0 && !empty($replyText)) {
        $stmt = $pdo->prepare("
            UPDATE public_questions 
            SET answer_text = ?, status = 'answered', status_label_hi = 'Response Published', answered_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$replyText, $questionId]);

        echo json_encode(['success' => true, 'message' => 'Response published to citizen']);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid parameters']);


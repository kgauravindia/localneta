<?php
// api/polls.php - Poll Voting Handler
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

$pdo = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true);

$pollId = (int)($input['poll_id'] ?? 1);
$optionIndex = (int)($input['option_index'] ?? 1);

if ($pollId > 0 && in_array($optionIndex, [1, 2, 3, 4])) {
    $col = "votes_" . $optionIndex;
    $stmt = $pdo->prepare("UPDATE polls SET {$col} = {$col} + 1, total_votes = total_votes + 1 WHERE id = ?");
    $stmt->execute([$pollId]);

    // Fetch updated
    $stmt = $pdo->prepare("SELECT * FROM polls WHERE id = ?");
    $stmt->execute([$pollId]);
    $poll = $stmt->fetch();

    if ($poll) {
        $total = max(1, (int)$poll['total_votes']);
        $options = [
            ['text' => $poll['option_1'], 'votes' => (int)$poll['votes_1'], 'percentage' => round(((int)$poll['votes_1'] / $total) * 100, 1)],
            ['text' => $poll['option_2'], 'votes' => (int)$poll['votes_2'], 'percentage' => round(((int)$poll['votes_2'] / $total) * 100, 1)],
            ['text' => $poll['option_3'], 'votes' => (int)$poll['votes_3'], 'percentage' => round(((int)$poll['votes_3'] / $total) * 100, 1)],
            ['text' => $poll['option_4'], 'votes' => (int)$poll['votes_4'], 'percentage' => round(((int)$poll['votes_4'] / $total) * 100, 1)]
        ];

        echo json_encode([
            'success' => true,
            'total_votes' => $poll['total_votes'],
            'options' => $options
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);


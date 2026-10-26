<?php
// student/flag_question_ajax.php
require_once __DIR__ . '/../includes/student_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$student_id = (int)($_SESSION['student_id'] ?? 0);
$question_id = (int)($_POST['question_id'] ?? 0);
$exam_id = !empty($_POST['exam_id']) ? (int)$_POST['exam_id'] : null;
$flag_reason = trim($_POST['flag_reason'] ?? 'Doubtful Question');
$remarks = trim($_POST['remarks'] ?? '');

if (!$student_id || !$question_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
    exit;
}

try {
    // Check if question exists
    $stmtQ = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND is_archived = 0");
    $stmtQ->execute([$question_id]);
    if (!$stmtQ->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Question not found or no longer available.']);
        exit;
    }

    // Check if student already flagged this question while still pending
    $stmtCheck = $pdo->prepare("SELECT id FROM question_flags WHERE question_id = ? AND student_id = ? AND status = 'pending'");
    $stmtCheck->execute([$question_id, $student_id]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update remarks if re-flagged
        $stmtUp = $pdo->prepare("UPDATE question_flags SET flag_reason = ?, student_remarks = ?, exam_id = COALESCE(?, exam_id), updated_at = NOW() WHERE id = ?");
        $stmtUp->execute([$flag_reason, $remarks ?: null, $exam_id, $existing['id']]);
        echo json_encode(['success' => true, 'message' => 'Your doubt flag and remarks have been updated.']);
        exit;
    }

    $stmtIns = $pdo->prepare("
        INSERT INTO question_flags (question_id, exam_id, student_id, flag_reason, student_remarks, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
    ");
    $stmtIns->execute([$question_id, $exam_id, $student_id, $flag_reason, $remarks ?: null]);

    echo json_encode(['success' => true, 'message' => 'Question flagged for teacher review successfully.']);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

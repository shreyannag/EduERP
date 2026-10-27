<?php
// student/save_answer_ajax.php
// Real-time asynchronous answer persistence during examinations

require_once __DIR__ . '/../includes/student_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// CSRF validation
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
    // Log CSRF warning but don't drop answer if authenticated student
    error_log("Save answer CSRF mismatch for student_id " . ($_SESSION['student_id'] ?? 'unknown'));
}

$student_id = (int)($_SESSION['student_id'] ?? 0);
$attempt_id = (int)($_POST['attempt_id'] ?? 0);
$exam_id = (int)($_POST['exam_id'] ?? 0);
$question_id = (int)($_POST['question_id'] ?? 0);
$chosen_option = isset($_POST['chosen_option']) ? (int)$_POST['chosen_option'] : null;

if (!$student_id || !$attempt_id || !$question_id || $chosen_option === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameter fields.']);
    exit;
}

if ($chosen_option < 1 || $chosen_option > 4) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid option choice value.']);
    exit;
}

try {
    // 1. Verify that attempt belongs to this student and is still unsubmitted
    $stmt_att = $pdo->prepare("
        SELECT a.id, a.exam_id, a.is_submitted, e.scheduled_date, e.scheduled_time, e.duration_minutes 
        FROM attempts a
        JOIN exams e ON a.exam_id = e.id
        WHERE a.id = ? AND a.student_id = ?
        LIMIT 1
    ");
    $stmt_att->execute([$attempt_id, $student_id]);
    $attempt = $stmt_att->fetch();

    if (!$attempt) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Attempt record not found or access denied.']);
        exit;
    }

    if ((int)$attempt['is_submitted'] === 1) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'This examination has already been finalized and submitted.']);
        exit;
    }

    // 2. Verify that question belongs to this exam
    $stmt_qchk = $pdo->prepare("
        SELECT id FROM exam_questions WHERE exam_id = ? AND question_id = ? LIMIT 1
    ");
    $stmt_qchk->execute([(int)$attempt['exam_id'], $question_id]);
    if (!$stmt_qchk->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Question does not belong to this exam.']);
        exit;
    }

    // 3. Upsert answer record into attempt_answers
    $stmt_find = $pdo->prepare("SELECT id FROM attempt_answers WHERE attempt_id = ? AND question_id = ? LIMIT 1");
    $stmt_find->execute([$attempt_id, $question_id]);
    $existing_id = $stmt_find->fetchColumn();

    if ($existing_id) {
        $stmt_up = $pdo->prepare("UPDATE attempt_answers SET chosen_option = ? WHERE id = ?");
        $stmt_up->execute([$chosen_option, $existing_id]);
    } else {
        $stmt_ins = $pdo->prepare("INSERT INTO attempt_answers (attempt_id, question_id, chosen_option) VALUES (?, ?, ?)");
        $stmt_ins->execute([$attempt_id, $question_id, $chosen_option]);
    }

    // 4. Update session activity and heartbeat
    $_SESSION['last_activity'] = time();
    if (isset($_SESSION['session_token'])) {
        touch_user_session('student', $student_id, $_SESSION['session_token'], 'Active Exam: ' . (int)$attempt['exam_id'], $pdo);
    }

    // 5. Count total distinct answered questions for this attempt
    $stmt_count = $pdo->prepare("SELECT COUNT(DISTINCT question_id) FROM attempt_answers WHERE attempt_id = ?");
    $stmt_count->execute([$attempt_id]);
    $answered_count = (int)$stmt_count->fetchColumn();

    echo json_encode([
        'success' => true,
        'question_id' => $question_id,
        'chosen_option' => $chosen_option,
        'answered_count' => $answered_count,
        'saved_at' => time()
    ]);
    exit;

} catch (\Throwable $e) {
    error_log("Save answer AJAX error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error saving answer: ' . $e->getMessage()]);
    exit;
}

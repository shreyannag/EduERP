<?php
// admin/homework/grade_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_has_feature('homework') && !is_teacher()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$submission_id    = (int)($_POST['submission_id'] ?? 0);
$grade_points     = isset($_POST['grade_points']) ? (float)$_POST['grade_points'] : null;
$teacher_feedback = trim($_POST['teacher_feedback'] ?? '');

if (!$submission_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Submission ID.']);
    exit;
}

// Fetch submission and homework
$stmtSub = $pdo->prepare("
    SELECT s.*, hw.admin_id, hw.created_by, hw.total_points 
    FROM homework_submissions s 
    JOIN homework hw ON s.homework_id = hw.id 
    WHERE s.id = ?
");
$stmtSub->execute([$submission_id]);
$sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

if (!$sub) {
    echo json_encode(['success' => false, 'error' => 'Submission record not found.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$sub['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this assignment.']);
        exit;
    }
}

if ($grade_points !== null && ($grade_points < 0 || $grade_points > (float)$sub['total_points'])) {
    echo json_encode(['success' => false, 'error' => "Grade points must be between 0 and {$sub['total_points']}."]);
    exit;
}

try {
    $stmtUp = $pdo->prepare("
        UPDATE homework_submissions SET
            grade_points = ?,
            teacher_feedback = ?,
            status = 'reviewed',
            graded_at = NOW(),
            graded_by = ?
        WHERE id = ?
    ");
    $stmtUp->execute([
        $grade_points,
        !empty($teacher_feedback) ? $teacher_feedback : null,
        (int)$_SESSION['admin_id'],
        $submission_id
    ]);

    echo json_encode([
        'success'      => true,
        'message'      => 'Grade and teacher feedback recorded successfully!',
        'grade_points' => $grade_points,
        'feedback'     => $teacher_feedback
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

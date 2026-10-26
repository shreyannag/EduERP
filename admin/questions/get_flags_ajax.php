<?php
// admin/questions/get_flags_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$question_id = (int)($_GET['question_id'] ?? 0);
if (!$question_id) {
    echo json_encode(['success' => false, 'error' => 'Question ID required']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
$is_super = is_super_admin();

// Ownership check
if (!$is_super) {
    $chk = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)");
    $chk->execute([$question_id, $effective_admin_id, $effective_admin_id]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
}

$stmt = $pdo->prepare("
    SELECT qf.*, s.full_name as student_name, s.username as student_username, e.title as exam_title
    FROM question_flags qf
    JOIN students s ON qf.student_id = s.id
    LEFT JOIN exams e ON qf.exam_id = e.id
    WHERE qf.question_id = ?
    ORDER BY qf.status = 'pending' DESC, qf.created_at DESC
");
$stmt->execute([$question_id]);
$flags = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'flags' => $flags]);

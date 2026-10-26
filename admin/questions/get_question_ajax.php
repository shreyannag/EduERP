<?php
// admin/questions/get_question_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
$is_super = is_super_admin();

if ($is_super) {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ? AND admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)");
    $stmt->execute([$id, $effective_admin_id, $effective_admin_id]);
}
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if ($question) {
    $question['question_text'] = clean_html($question['question_text']);
    
    // Fetch multi-class mappings
    $stmtQC = $pdo->prepare("SELECT class_id FROM question_classes WHERE question_id = ?");
    $stmtQC->execute([$id]);
    $question['additional_classes'] = array_map('intval', $stmtQC->fetchAll(PDO::FETCH_COLUMN));
    if (empty($question['additional_classes']) && !empty($question['class_id'])) {
        $question['additional_classes'] = [(int)$question['class_id']];
    }
    
    echo json_encode(['success' => true, 'data' => $question]);
} else {
    echo json_encode(['success' => false, 'error' => 'Question not found or access denied.']);
}

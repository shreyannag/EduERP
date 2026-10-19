<?php
// admin/questions/get_question_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$id = intval($_GET['id'] ?? 0);
if(!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    exit;
}

$current_admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();

if ($is_super) {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ?");
    $stmt->execute([$id]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM questions WHERE id = ? AND admin_id = ?");
    $stmt->execute([$id, $current_admin_id]);
}
$question = $stmt->fetch();

if($question) {
    $question['question_text'] = clean_html($question['question_text']);
    echo json_encode(['success' => true, 'data' => $question]);
} else {
    echo json_encode(['success' => false, 'error' => 'Not found']);
}

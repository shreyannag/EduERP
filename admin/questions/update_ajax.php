<?php
// admin/questions/update_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/image_storage.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'error' => 'CSRF verification failed']);
        exit;
    }
    
    $id = intval($_POST['id'] ?? 0);
    $q = trim($_POST['question_text'] ?? '');
    $o1 = trim($_POST['option1'] ?? '');
    $o2 = trim($_POST['option2'] ?? '');
    $o3 = trim($_POST['option3'] ?? '');
    $o4 = trim($_POST['option4'] ?? '');
    $c = intval($_POST['correct_option'] ?? 1);
    $class_id = intval($_POST['class_id'] ?? 0);
    $board_id = intval($_POST['board_id'] ?? 0);
    $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
    $topic_id = !empty($_POST['topic_id']) ? intval($_POST['topic_id']) : null;
    $marks = intval($_POST['marks'] ?? 1);
    
    if (!$id || !$q || !$o1 || !$o2 || !$o3 || !$o4 || !in_array($c, [1,2,3,4]) || !$class_id || !$board_id) {
        echo json_encode(['success' => false, 'error' => 'All mandatory fields are required and must be valid.']);
        exit;
    }
    
    $current_admin_id = (int)$_SESSION['admin_id'];
    $effective_admin_id = get_effective_admin_id();
    $is_super = is_super_admin();
    
    if (!$is_super) {
        $authStmt = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)");
        $authStmt->execute([$id, $effective_admin_id, $effective_admin_id]);
        if (!$authStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Access denied: You can only edit questions from your own institute.']);
            exit;
        }

        if ($subject_id) {
            $chkSub = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)");
            $chkSub->execute([$subject_id, $effective_admin_id, $effective_admin_id]);
            if (!$chkSub->fetch()) $subject_id = null;
        }
        if ($topic_id) {
            $chkTop = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)");
            $chkTop->execute([$topic_id, $effective_admin_id, $effective_admin_id]);
            if (!$chkTop->fetch()) $topic_id = null;
        }
    }
    
    try {
        $q = process_question_images($q, $pdo, $current_admin_id);
        $stmt = $pdo->prepare("UPDATE questions SET class_id = ?, board_id = ?, subject_id = ?, topic_id = ?, question_text = ?, option1 = ?, option2 = ?, option3 = ?, option4 = ?, correct_option = ?, marks = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$class_id, $board_id, $subject_id, $topic_id, $q, $o1, $o2, $o3, $o4, $c, $marks, $id]);
        
        // Sync multi-class assignments
        $add_classes = (array)($_POST['additional_classes'] ?? []);
        $all_classes = array_unique(array_filter(array_map('intval', $add_classes)));
        $all_classes[] = $class_id;
        sync_question_classes($id, $all_classes, $pdo);

        echo json_encode(['success' => true]);
    } catch (\PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database persistence failure.']);
    }
}

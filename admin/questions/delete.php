<?php
// admin/questions/delete.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $ids = [];
    if (isset($_POST['id'])) {
        if (is_array($_POST['id'])) {
            $ids = array_map('intval', $_POST['id']);
        } else {
            $ids = [intval($_POST['id'])];
        }
    }
    
    $current_admin_id = (int)$_SESSION['admin_id'];
    $is_super = is_super_admin();
    
    if (!empty($ids)) {
        foreach ($ids as $id) {
            if ($id > 0) {
                // Tenant admin can only delete questions from their own question bank
                if (!$is_super) {
                    $ownerCheck = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND admin_id = ?");
                    $ownerCheck->execute([$id, $current_admin_id]);
                    if (!$ownerCheck->fetch()) {
                        continue; // Skip unauthorized deletion attempt
                    }
                }
                
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE question_id = ?");
                $stmt->execute([$id]);
                $linked = $stmt->fetchColumn();
                
                if ($linked > 0) {
                    $pdo->prepare("UPDATE questions SET is_archived = 1 WHERE id = ?")->execute([$id]);
                } else {
                    $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$id]);
                }
            }
        }
    }
}
header('Location: index.php');
exit;

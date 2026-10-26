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
    $effective_admin_id = get_effective_admin_id();
    
    if (!empty($ids)) {
        $queuedCount = 0;
        $deletedCount = 0;

        foreach ($ids as $id) {
            if ($id > 0) {
                // Tenant admin or sub-admin can only operate on questions from their institute
                if (!$is_super) {
                    $ownerCheck = $pdo->prepare("SELECT id, question_text FROM questions WHERE id = ? AND admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)");
                    $ownerCheck->execute([$id, $effective_admin_id, $effective_admin_id]);
                    $qRow = $ownerCheck->fetch(PDO::FETCH_ASSOC);
                    if (!$qRow) {
                        continue; // Skip unauthorized deletion attempt
                    }
                } else {
                    $qRow = $pdo->query("SELECT id, question_text FROM questions WHERE id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
                }

                $qTitle = 'Question #' . $id . ': ' . mb_substr(trim(strip_tags($qRow['question_text'] ?? '')), 0, 80);

                // If sub-admin, queue for Super Admin / Admin review
                $res = queue_or_execute_deletion('question', $id, $qTitle, 'Staff deletion request', null, $pdo);
                if ($res['queued']) {
                    $queuedCount++;
                    continue;
                }
                
                // Root admin or Super Admin directly deletes
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE question_id = ?");
                $stmt->execute([$id]);
                $linked = $stmt->fetchColumn();
                
                if ($linked > 0) {
                    $pdo->prepare("UPDATE questions SET is_archived = 1 WHERE id = ?")->execute([$id]);
                } else {
                    $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$id]);
                }
                $deletedCount++;
            }
        }

        if ($queuedCount > 0) {
            $_SESSION['flash_info'] = "Deletion request for {$queuedCount} question(s) has been submitted for Super Admin approval.";
        }
        if ($deletedCount > 0) {
            $_SESSION['flash_success'] = "{$deletedCount} question(s) removed successfully.";
        }
    }
}
header('Location: index.php');
exit;

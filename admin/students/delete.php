<?php
// admin/students/delete.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    
    if ($id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrolments WHERE student_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            // Soft delete
            $pdo->prepare("UPDATE students SET is_active = 0 WHERE id = ?")->execute([$id]);
        } else {
            // Hard delete
            $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
        }
    }
}
header('Location: index.php');
exit;

<?php
// admin/exams/publish.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $exam_id = intval($_POST['exam_id'] ?? 0);
    
    if ($exam_id) {
        $stmt = $pdo->prepare("SELECT status FROM exams WHERE id = ?");
        $stmt->execute([$exam_id]);
        $status = $stmt->fetchColumn();
        
        if ($status === 'draft') {
            $pdo->prepare("UPDATE exams SET status = 'published', published_at = NOW() WHERE id = ?")->execute([$exam_id]);
        }
    }
}
header('Location: index.php');
exit;

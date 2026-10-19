<?php
// admin/reports/generate_link.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $exam_id = intval($_POST['exam_id'] ?? 0);
    
    if ($exam_id) {
        $token = bin2hex(random_bytes(32)); // 64 chars
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        $stmt = $pdo->prepare("INSERT INTO report_links (exam_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$exam_id, $token, $expires]);
    }
}

// Redirect back to context
header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'index.php');
exit;

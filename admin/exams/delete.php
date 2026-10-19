<?php
// admin/exams/delete.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    
    if ($id) {
        // Hard deletion allowed for all exam states as per user request
        $pdo->prepare("DELETE FROM exams WHERE id = ?")->execute([$id]);
    }
}
$redirect = $_POST['redirect_url'] ?? 'index.php';
header('Location: ' . $redirect);
exit;

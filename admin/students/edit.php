<?php
// admin/students/edit.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $class_id = intval($_POST['class_id'] ?? 0);
    $board_id = intval($_POST['board_id'] ?? 0);
    $enrollment_date = trim($_POST['enrollment_date'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrollment_date)) {
        $enrollment_date = date('Y-m-d');
    }
    
    if ($id && $name && $class_id && $board_id) {
        if (!is_super_admin()) {
            $stmt = $pdo->prepare("UPDATE students SET full_name=?, email=?, class_id=?, board_id=?, enrollment_date=?, is_active=? WHERE id=? AND admin_id=?");
            $stmt->execute([$name, $email, $class_id, $board_id, $enrollment_date, $is_active, $id, (int)$_SESSION['admin_id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE students SET full_name=?, email=?, class_id=?, board_id=?, enrollment_date=?, is_active=? WHERE id=?");
            $stmt->execute([$name, $email, $class_id, $board_id, $enrollment_date, $is_active, $id]);
        }
    }
}
header('Location: index.php');
exit;

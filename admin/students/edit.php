<?php
// admin/students/edit.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

require_admin_feature('students');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $class_id = intval($_POST['class_id'] ?? 0);
    $board_id = intval($_POST['board_id'] ?? 0);
    $enrollment_date = trim($_POST['enrollment_date'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $new_password = trim($_POST['new_password'] ?? '');
    $target_admin_id = (!empty($_POST['admin_id']) && is_super_admin()) ? (int)$_POST['admin_id'] : null;
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrollment_date)) {
        $enrollment_date = date('Y-m-d');
    }
    
    if (!$id || !$name || !$class_id || !$board_id) {
        $_SESSION['flash_error'] = "Required fields missing. Full Name, Class, and Board are required.";
        header('Location: index.php');
        exit;
    }

    // Permission and existence check
    $effective_admin_id = get_effective_admin_id();
    if (!is_super_admin()) {
        $check = $pdo->prepare("SELECT id FROM students WHERE id = ? AND admin_id = ?");
        $check->execute([$id, $effective_admin_id]);
        if (!$check->fetch()) {
            $_SESSION['flash_error'] = "Access denied or student record not found.";
            header('Location: index.php');
            exit;
        }
    } else {
        $check = $pdo->prepare("SELECT id FROM students WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            $_SESSION['flash_error'] = "Student record not found.";
            header('Location: index.php');
            exit;
        }
    }

    try {
        $clean_email = !empty($email) ? $email : null;
        $clean_phone = !empty($phone) ? $phone : null;

        // Build update query dynamically
        $updateFields = [
            'full_name = ?',
            'email = ?',
            'phone = ?',
            'class_id = ?',
            'board_id = ?',
            'enrollment_date = ?',
            'is_active = ?'
        ];
        $params = [
            $name,
            $clean_email,
            $clean_phone,
            $class_id,
            $board_id,
            $enrollment_date,
            $is_active
        ];

        // If new password provided, hash and update it
        if ($new_password !== '') {
            $updateFields[] = 'password_hash = ?';
            $updateFields[] = 'plain_password = ?';
            $params[] = password_hash($new_password, PASSWORD_BCRYPT);
            $params[] = $new_password;
        }

        // If super admin changed the institute
        if ($target_admin_id !== null) {
            $updateFields[] = 'admin_id = ?';
            $params[] = $target_admin_id;
        }

        $params[] = $id;
        $sql = "UPDATE students SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $_SESSION['flash_success'] = "Student '" . htmlspecialchars($name) . "' updated successfully.";
    } catch (\PDOException $e) {
        $_SESSION['flash_error'] = "Database error while updating student: " . $e->getMessage();
    }
}

header('Location: index.php');
exit;

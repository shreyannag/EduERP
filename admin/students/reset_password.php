<?php
// admin/students/reset_password.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    
    if ($id) {
        $new_pass = generate_random_password(8);
        $hashed = password_hash($new_pass, PASSWORD_BCRYPT);
        
        $stmt = $pdo->prepare("UPDATE students SET password_hash = ?, plain_password = ? WHERE id = ?");
        $stmt->execute([$hashed, $new_pass, $id]);
    }
}
header('Location: index.php');
exit;

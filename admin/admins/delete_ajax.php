<?php
// admin/admins/delete_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Super Admin authorization
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$admin_id = (int)($_POST['admin_id'] ?? 0);

if ($admin_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid administrator record.']);
    exit;
}

$current_user_id = (int)$_SESSION['admin_id'];
if ($admin_id === $current_user_id) {
    echo json_encode(['success' => false, 'error' => 'Security restriction: You cannot delete your own Super Administrator account.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, role, institute_name FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo json_encode(['success' => false, 'error' => 'Administrator account not found.']);
    exit;
}

// Check if this is a super admin and ensure at least one other super admin exists
if ($admin['role'] === 'super_admin') {
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'");
    $superCount = (int)$stmtCount->fetchColumn();
    if ($superCount <= 1) {
        echo json_encode(['success' => false, 'error' => 'Security restriction: Cannot delete the last remaining Super Administrator account.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 1. Safely reassign owned students, exams, and questions to current Super Admin
    $pdo->prepare("UPDATE students SET admin_id = ? WHERE admin_id = ?")->execute([$current_user_id, $admin_id]);
    $pdo->prepare("UPDATE exams SET admin_id = ? WHERE admin_id = ?")->execute([$current_user_id, $admin_id]);
    $pdo->prepare("UPDATE questions SET admin_id = ? WHERE admin_id = ?")->execute([$current_user_id, $admin_id]);

    // 2. Reassign messages sent by or received by this admin to preserve communication history
    $pdo->prepare("UPDATE messages SET sender_id = ? WHERE sender_type = 'admin' AND sender_id = ?")->execute([$current_user_id, $admin_id]);
    $pdo->prepare("UPDATE messages SET receiver_id = ? WHERE sender_type = 'student' AND receiver_id = ?")->execute([$current_user_id, $admin_id]);

    // 3. Reassign answer releases to current super admin
    $pdo->prepare("UPDATE answer_releases SET released_by_admin_id = ? WHERE released_by_admin_id = ?")->execute([$current_user_id, $admin_id]);

    // 4. Clear created_by reference on other admins if created by this admin
    $pdo->prepare("UPDATE admins SET created_by = NULL WHERE created_by = ?")->execute([$admin_id]);

    // 5. Handle fee structures safely: fee_structures has a UNIQUE (class_id) constraint.
    // Delete any fee structures for classes where Super Admin already has a record to avoid duplicate key error
    $pdo->prepare("
        DELETE FROM fee_structures 
        WHERE admin_id = ? 
        AND class_id IN (SELECT class_id FROM (SELECT class_id FROM fee_structures WHERE admin_id = ?) AS t)
    ")->execute([$admin_id, $current_user_id]);
    $pdo->prepare("UPDATE fee_structures SET admin_id = ? WHERE admin_id = ?")->execute([$current_user_id, $admin_id]);

    // 6. Delete the administrator account
    $stmtDel = $pdo->prepare("DELETE FROM admins WHERE id = ?");
    $stmtDel->execute([$admin_id]);

    $pdo->commit();

    echo json_encode([
        'success'  => true,
        'admin_id' => $admin_id,
        'message'  => "Administrator account '{$admin['username']}' deleted successfully. All associated students, exams, questions, and messages were safely reassigned to your Super Admin account."
    ]);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error during deletion: ' . $e->getMessage()]);
}

<?php
// admin/sub_admins/delete_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!can_manage_sub_admins()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: Only Admins and Super Admins can manage sub-admins.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$sub_admin_id = (int)($_POST['sub_admin_id'] ?? 0);

$queryCheck = is_super_admin() 
    ? "SELECT id, username FROM admins WHERE id = ? AND role = 'sub_admin'" 
    : "SELECT id, username FROM admins WHERE id = ? AND role = 'sub_admin' AND parent_admin_id = ?";
$stmtCheck = $pdo->prepare($queryCheck);
$paramsCheck = is_super_admin() ? [$sub_admin_id] : [$sub_admin_id, (int)$_SESSION['admin_id']];
$stmtCheck->execute($paramsCheck);
$target = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'Sub-admin account not found or access denied.']);
    exit;
}

try {
    // Foreign keys on batches (SET NULL), payroll_payments (CASCADE) will clean up cleanly
    $stmt = $pdo->prepare("DELETE FROM admins WHERE id = ?");
    $stmt->execute([$sub_admin_id]);

    echo json_encode([
        'success' => true,
        'message' => "Sub-Admin '{$target['username']}' was permanently deleted."
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

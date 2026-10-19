<?php
// admin/sub_admins/resend_credentials_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

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
    ? "SELECT id, username, email FROM admins WHERE id = ? AND role = 'sub_admin'" 
    : "SELECT id, username, email FROM admins WHERE id = ? AND role = 'sub_admin' AND parent_admin_id = ?";
$stmtCheck = $pdo->prepare($queryCheck);
$paramsCheck = is_super_admin() ? [$sub_admin_id] : [$sub_admin_id, (int)$_SESSION['admin_id']];
$stmtCheck->execute($paramsCheck);
$target = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'Sub-admin account not found or access denied.']);
    exit;
}

if (empty($target['email'])) {
    echo json_encode(['success' => false, 'error' => 'This sub-admin has no email address configured. Please edit and add an email address first.']);
    exit;
}

// Generate new random temporary password
$tempPassword = substr(str_shuffle('abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%'), 0, 10);
$hash = password_hash($tempPassword, PASSWORD_DEFAULT);

try {
    $pdo->prepare("UPDATE admins SET password_hash = ?, must_change_password = 1 WHERE id = ?")
        ->execute([$hash, $sub_admin_id]);

    $mailResult = send_sub_admin_credentials($sub_admin_id, $tempPassword, $pdo);

    if ($mailResult['success']) {
        echo json_encode([
            'success' => true,
            'message' => "New login credentials and temporary password successfully dispatched to '{$target['email']}'!"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error'   => "Password was reset, but email could not be sent: " . $mailResult['error']
        ]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}

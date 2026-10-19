<?php
// admin/admins/toggle_status_ajax.php
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
    echo json_encode(['success' => false, 'error' => 'Security restriction: You cannot deactivate your own Super Administrator account.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, is_active FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo json_encode(['success' => false, 'error' => 'Administrator account not found.']);
    exit;
}

// Support explicit target status (0 for suspend, 1 for activate) or toggle if omitted
if (isset($_POST['target_status'])) {
    $new_status = ((int)$_POST['target_status'] === 1) ? 1 : 0;
} else {
    $new_status = ((int)$admin['is_active'] === 1) ? 0 : 1;
}

try {
    if ($new_status === 0) {
        // Suspend and immediately invalidate session token
        $stmtUpdate = $pdo->prepare("UPDATE admins SET is_active = 0, session_token = NULL WHERE id = ?");
    } else {
        // Activate
        $stmtUpdate = $pdo->prepare("UPDATE admins SET is_active = 1 WHERE id = ?");
    }
    $stmtUpdate->execute([$admin_id]);

    $status_label = ($new_status === 1) ? 'activated' : 'deactivated';
    echo json_encode([
        'success'    => true,
        'admin_id'   => $admin_id,
        'username'   => $admin['username'],
        'new_status' => $new_status,
        'message'    => "Administrator account '{$admin['username']}' has been {$status_label}."
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

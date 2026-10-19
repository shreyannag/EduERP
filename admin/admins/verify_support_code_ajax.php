<?php
// admin/admins/verify_support_code_ajax.php
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
$code     = trim($_POST['code'] ?? '');
$current_user_id = (int)$_SESSION['admin_id'];

if ($admin_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid administrator record specified.']);
    exit;
}

if (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid 6-digit numeric authorization code.']);
    exit;
}

// Fetch target admin record
$stmt = $pdo->prepare("SELECT id, username, role, institute_name, email, support_permission, support_otp, support_otp_expires_at FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$targetAdmin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetAdmin) {
    echo json_encode(['success' => false, 'error' => 'Administrator account not found.']);
    exit;
}

if ($targetAdmin['role'] === 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Target account is already a Super Administrator.']);
    exit;
}

// Check if OTP exists
if (empty($targetAdmin['support_otp']) || empty($targetAdmin['support_otp_expires_at'])) {
    echo json_encode([
        'success' => false,
        'error' => 'No active authorization code found. Please click "Request Permission Code" first.'
    ]);
    exit;
}

// Check expiration
if (strtotime($targetAdmin['support_otp_expires_at']) < time()) {
    // Clear expired code
    $pdo->prepare("UPDATE admins SET support_otp = NULL, support_otp_expires_at = NULL WHERE id = ?")->execute([$admin_id]);
    echo json_encode([
        'success' => false,
        'error' => 'The authorization code has expired (exceeded 15 minutes). Please request a fresh code.'
    ]);
    exit;
}

// Verify code against hash
if (!password_verify($code, $targetAdmin['support_otp'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Incorrect authorization code. Please verify the 6-digit code received on the administrator\'s email and try again.'
    ]);
    exit;
}

// Grant support permission and clear OTP
$upd = $pdo->prepare("UPDATE admins SET support_permission = 1, support_permission_updated_at = NOW(), support_otp = NULL, support_otp_expires_at = NULL WHERE id = ?");
$upd->execute([$admin_id]);

echo json_encode([
    'success'    => true,
    'message'    => "Authorization code verified successfully! Unrestricted settings access has been granted for administrator '{$targetAdmin['username']}'.",
    'admin_id'   => $admin_id,
    'username'   => $targetAdmin['username']
]);

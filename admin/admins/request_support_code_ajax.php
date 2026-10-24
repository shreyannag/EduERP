<?php
// admin/admins/request_support_code_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

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
$current_user_id = (int)$_SESSION['admin_id'];

if ($admin_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid administrator record specified.']);
    exit;
}

if ($admin_id === $current_user_id) {
    echo json_encode(['success' => false, 'error' => 'You already possess unrestricted authority over your own Super Administrator account.']);
    exit;
}

// Fetch target admin
$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
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

if ($targetAdmin['role'] === 'sub_admin' || (!empty($targetAdmin['parent_admin_id']) && (int)$targetAdmin['parent_admin_id'] !== (int)$targetAdmin['id'])) {
    echo json_encode([
        'success' => false,
        'error' => "Sub-admins and staff members are under direct institute control. Their settings and passwords can be edited directly without requiring any permission code."
    ]);
    exit;
}

$email = trim($targetAdmin['email'] ?? '');
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode([
        'success' => false,
        'error' => "Administrator '{$targetAdmin['username']}' does not have a valid recovery email address registered. An authorization code cannot be sent."
    ]);
    exit;
}

// Fetch current Super Admin info
$stmtSuper = $pdo->prepare("SELECT id, username, institute_name, email FROM admins WHERE id = ?");
$stmtSuper->execute([$current_user_id]);
$superAdmin = $stmtSuper->fetch(PDO::FETCH_ASSOC);

// Generate secure 6-digit numeric OTP
$code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$hash = password_hash($code, PASSWORD_DEFAULT);
$expires_at = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 minutes expiration

// Store hash & expiry in database
$upd = $pdo->prepare("UPDATE admins SET support_otp = ?, support_otp_expires_at = ? WHERE id = ?");
$upd->execute([$hash, $expires_at, $admin_id]);

// Dispatch email to target admin
$emailSent = send_admin_support_code_email($targetAdmin, $code, $superAdmin);

// Mask email for display (e.g. rudraspeaks@gmail.com -> r***s@gmail.com)
$parts = explode('@', $email);
$local = $parts[0];
$domain = $parts[1] ?? '';
if (strlen($local) <= 2) {
    $maskedLocal = substr($local, 0, 1) . '***';
} else {
    $maskedLocal = substr($local, 0, 1) . str_repeat('*', min(5, strlen($local) - 2)) . substr($local, -1);
}
$maskedEmail = $maskedLocal . '@' . $domain;

if ($emailSent) {
    echo json_encode([
        'success'            => true,
        'message'            => "Authorization code sent successfully to {$maskedEmail}. Ask the administrator for the code and enter it below to unlock unrestricted access.",
        'masked_email'       => $maskedEmail,
        'expires_in_minutes' => 15
    ]);
} else {
    // If mailer encountered an error (e.g. local offline dev with no internet), inform Super Admin
    echo json_encode([
        'success'            => true,
        'notice'             => 'offline_warning',
        'message'            => "Code generated! (Note: Outgoing SMTP email dispatch failed, but code was registered). For offline/testing use, the code is: {$code}",
        'masked_email'       => $maskedEmail,
        'expires_in_minutes' => 15
    ]);
}

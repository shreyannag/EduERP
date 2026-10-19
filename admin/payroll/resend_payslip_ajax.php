<?php
// admin/payroll/resend_payslip_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if (!can_process_payroll()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: Only Admins and Accountants can manage payslips.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$payment_id = (int)($_POST['payment_id'] ?? 0);

if (!$payment_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT p.*, s.email as staff_email FROM payroll_payments p JOIN admins s ON p.sub_admin_id = s.id WHERE p.id = ?");
$stmt->execute([$payment_id]);
$pay = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pay) {
    echo json_encode(['success' => false, 'error' => 'Payment record not found.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin() && (int)$pay['admin_id'] !== $effective_admin_id) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this payroll record.']);
    exit;
}

if (empty($pay['staff_email'])) {
    echo json_encode(['success' => false, 'error' => 'Staff member does not have an email address configured.']);
    exit;
}

$mailRes = send_payroll_payslip_email($payment_id, $pdo);

if ($mailRes['success']) {
    echo json_encode([
        'success' => true,
        'message' => "Payslip for Receipt #{$pay['receipt_no']} was successfully sent to {$pay['staff_email']}!"
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => "Failed to send payslip email: " . $mailRes['error']
    ]);
}

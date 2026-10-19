<?php
// admin/fees/collect_ajax.php
// AJAX endpoint to record student fee payments.

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fee_manager.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Please reload the page.']);
    exit;
}

$student_id     = intval($_POST['student_id'] ?? 0);
$fee_month      = intval($_POST['fee_month'] ?? date('n'));
$fee_year       = intval($_POST['fee_year'] ?? date('Y'));
$amount_paid    = floatval($_POST['amount_paid'] ?? 0.00);
$payment_method = trim($_POST['payment_method'] ?? 'Cash');
$ref            = trim($_POST['transaction_ref'] ?? '');
$notes          = trim($_POST['notes'] ?? '');
$send_email     = !empty($_POST['send_email']);

if (!$student_id || $fee_month < 1 || $fee_month > 12 || $fee_year < 2000) {
    echo json_encode(['success' => false, 'error' => 'Invalid student or payment parameters.']);
    exit;
}

$result = record_student_fee_payment($student_id, $fee_month, $fee_year, $amount_paid, $payment_method, $ref, $notes, $pdo);

if ($result['success']) {
    if ($send_email) {
        $senderAdminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $emailResult = send_student_fee_statement_email($student_id, $fee_year, $pdo, $senderAdminId);
        $result['email_sent'] = $emailResult['success'];
        $result['email_message'] = $emailResult['message'] ?? ($emailResult['error'] ?? '');
        if ($emailResult['success']) {
            $_SESSION['flash_success'] = "Payment recorded successfully! " . $emailResult['message'];
        } else {
            $_SESSION['flash_warning'] = "Payment recorded, but fee statement email failed: " . ($emailResult['error'] ?? 'Check personal SMTP settings');
        }
    } else {
        $_SESSION['flash_success'] = "Fee payment of ₹" . number_format($amount_paid, 2) . " successfully recorded in student ledger.";
    }
}

echo json_encode($result);
exit;

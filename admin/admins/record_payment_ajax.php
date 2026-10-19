<?php
// admin/admins/record_payment_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

header('Content-Type: application/json; charset=utf-8');

// Super Admin authorization
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$admin_id        = (int)($_POST['admin_id'] ?? 0);
$billing_month   = (int)($_POST['billing_month'] ?? date('n'));
$billing_year    = (int)($_POST['billing_year'] ?? date('Y'));
$amount_paid     = (float)($_POST['amount_paid'] ?? 0);
$payment_method  = trim($_POST['payment_method'] ?? 'UPI');
$transaction_ref = trim($_POST['transaction_ref'] ?? '');
$notes           = trim($_POST['notes'] ?? '');

if ($admin_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid administrator record specified.']);
    exit;
}

if ($billing_month < 1 || $billing_month > 12) {
    echo json_encode(['success' => false, 'error' => 'Invalid billing month specified.']);
    exit;
}

if ($amount_paid <= 0) {
    echo json_encode(['success' => false, 'error' => 'Payment amount must be greater than zero.']);
    exit;
}

try {
    $current_super_admin_id = (int)$_SESSION['admin_id'];
    $result = record_admin_payment(
        $admin_id,
        $billing_month,
        $billing_year,
        $amount_paid,
        $payment_method,
        $transaction_ref,
        $notes,
        $current_super_admin_id,
        $pdo
    );

    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error while recording payment: ' . $e->getMessage()]);
}

<?php
// admin/admins/save_billing_profile_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

header('Content-Type: application/json; charset=utf-8');

require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$admin_id          = (int)($_POST['admin_id'] ?? 0);
$billing_model     = trim($_POST['billing_model'] ?? 'standard');
$base_monthly_fee  = (float)($_POST['base_monthly_fee'] ?? 1500.00);
$per_student_fee   = (float)($_POST['per_student_fee'] ?? 0.00);
$feature_prices_in = $_POST['feature_prices'] ?? [];
$discount_amount   = (float)($_POST['discount_amount'] ?? 0.00);
$billing_due_day   = (int)($_POST['billing_due_day'] ?? 10);
$notes             = trim($_POST['notes'] ?? '');

if (!$admin_id) {
    echo json_encode(['success' => false, 'error' => 'Valid administrator ID is required.']);
    exit;
}

// Fetch admin
$stmt = $pdo->prepare("SELECT id, username, role, institute_name FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo json_encode(['success' => false, 'error' => 'Administrator account not found.']);
    exit;
}

if ($admin['role'] === 'super_admin') {
    echo json_encode(['success' => false, 'error' => 'Super Administrator account is exempt from tenant billing configuration.']);
    exit;
}

if (!in_array($billing_model, ['standard', 'custom_fixed', 'per_feature', 'hybrid'], true)) {
    $billing_model = 'standard';
}

$clean_feature_prices = [];
if (is_array($feature_prices_in)) {
    foreach ($feature_prices_in as $k => $val) {
        $p = (float)$val;
        if ($p > 0) {
            $clean_feature_prices[$k] = $p;
        }
    }
}

$saved = save_admin_billing_profile($admin_id, [
    'billing_model'    => $billing_model,
    'base_monthly_fee' => $base_monthly_fee,
    'per_student_fee'  => $per_student_fee,
    'feature_prices'   => $clean_feature_prices,
    'discount_amount'  => $discount_amount,
    'billing_due_day'  => $billing_due_day,
    'notes'            => $notes
], $pdo);

if (!$saved) {
    echo json_encode(['success' => false, 'error' => 'Failed to save custom billing profile.']);
    exit;
}

// Recalculate billing for current month
$currentMonth = (int)date('n');
$currentYear  = (int)date('Y');
$updatedBilling = get_or_create_admin_billing($admin_id, $currentMonth, $currentYear, $pdo);
$calculation    = calculate_admin_monthly_billing($admin_id, $currentMonth, $currentYear, $pdo);

echo json_encode([
    'success'         => true,
    'message'         => "Custom pricing & billing profile successfully updated for '{$admin['institute_name']}'!",
    'billing_model'   => $billing_model,
    'amount_due'      => $updatedBilling['amount_due'],
    'currency'        => $updatedBilling['currency'],
    'itemized'        => $calculation
]);

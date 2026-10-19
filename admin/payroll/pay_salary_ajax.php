<?php
// admin/payroll/pay_salary_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if (!can_process_payroll()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: Only Admins and Accountants can process payroll.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$sub_admin_id   = (int)($_POST['sub_admin_id'] ?? 0);
$salary_month   = (int)($_POST['salary_month'] ?? date('n'));
$salary_year    = (int)($_POST['salary_year'] ?? date('Y'));
$base_salary    = max(0, (float)($_POST['base_salary'] ?? 0));
$bonus          = max(0, (float)($_POST['bonus'] ?? 0));
$deductions     = max(0, (float)($_POST['deductions'] ?? 0));
$payment_method = trim($_POST['payment_method'] ?? 'bank_transfer');
$reference_no   = trim($_POST['reference_no'] ?? '');
$payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));
$notes          = trim($_POST['notes'] ?? '');
$send_email     = !empty($_POST['send_email']);

if (!$sub_admin_id) {
    echo json_encode(['success' => false, 'error' => 'Please select a staff member to pay.']);
    exit;
}

if ($salary_month < 1 || $salary_month > 12) {
    echo json_encode(['success' => false, 'error' => 'Invalid salary month specified.']);
    exit;
}

if ($salary_year < 2000 || $salary_year > 2100) {
    echo json_encode(['success' => false, 'error' => 'Invalid salary year specified.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
    $payment_date = date('Y-m-d');
}

$net_amount = ($base_salary + $bonus) - $deductions;
if ($net_amount < 0) {
    echo json_encode(['success' => false, 'error' => 'Deductions cannot exceed the sum of base salary and bonus.']);
    exit;
}

// Fetch staff member
$stmtStaff = $pdo->prepare("SELECT id, username, full_name, email, role, sub_role, parent_admin_id, salary FROM admins WHERE id = ?");
$stmtStaff->execute([$sub_admin_id]);
$staff = $stmtStaff->fetch(PDO::FETCH_ASSOC);

if (!$staff || $staff['role'] !== 'sub_admin') {
    echo json_encode(['success' => false, 'error' => 'Selected user is not a valid sub-admin staff member.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    $staffParent = (int)($staff['parent_admin_id'] ?? 0);
    if ($staffParent !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: Staff member does not belong to your institute.']);
        exit;
    }
} else {
    // If super admin, the tenant admin is the staff's parent_admin_id or fallback to current
    $effective_admin_id = !empty($staff['parent_admin_id']) ? (int)$staff['parent_admin_id'] : (int)$_SESSION['admin_id'];
}

// Check for duplicate payment for same month/year
$stmtCheck = $pdo->prepare("SELECT id, receipt_no, payment_date FROM payroll_payments WHERE sub_admin_id = ? AND salary_month = ? AND salary_year = ?");
$stmtCheck->execute([$sub_admin_id, $salary_month, $salary_year]);
$existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo json_encode([
        'success' => false,
        'error'   => "Salary for this month ({$salary_month}/{$salary_year}) has already been paid under receipt #{$existing['receipt_no']} on {$existing['payment_date']}."
    ]);
    exit;
}

// Generate unique receipt number
$randPart1 = strtoupper(bin2hex(random_bytes(2)));
$randPart2 = strtoupper(bin2hex(random_bytes(2)));
$receipt_no = sprintf("PAY-%04d%02d-%s-%s", $salary_year, $salary_month, $randPart1, $randPart2);

$paid_by_id = (int)$_SESSION['admin_id'];

try {
    $stmt = $pdo->prepare("
        INSERT INTO payroll_payments (
            sub_admin_id, admin_id, paid_by_id, salary_month, salary_year,
            base_salary, bonus, deductions, net_amount, payment_method,
            reference_no, payment_date, receipt_no, notes, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $sub_admin_id,
        $effective_admin_id,
        $paid_by_id,
        $salary_month,
        $salary_year,
        $base_salary,
        $bonus,
        $deductions,
        $net_amount,
        $payment_method,
        !empty($reference_no) ? $reference_no : null,
        $payment_date,
        $receipt_no,
        !empty($notes) ? $notes : null
    ]);

    $payment_id = (int)$pdo->lastInsertId();
    $emailNotice = '';

    if ($send_email && !empty($staff['email'])) {
        $mailRes = send_payroll_payslip_email($payment_id, $pdo);
        if ($mailRes['success']) {
            $emailNotice = " Payslip voucher was emailed to {$staff['email']}.";
        } else {
            $emailNotice = " (Payslip email warning: " . $mailRes['error'] . ")";
        }
    }

    $staffName = htmlspecialchars($staff['full_name'] ?: $staff['username']);
    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
    $periodText = ($monthNames[$salary_month] ?? 'Month') . ' ' . $salary_year;

    echo json_encode([
        'success'    => true,
        'message'    => "Salary of ₹" . number_format($net_amount, 2) . " successfully disbursed to {$staffName} for {$periodText}! Receipt: {$receipt_no}.{$emailNotice}",
        'receipt_no' => $receipt_no,
        'payment_id' => $payment_id
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

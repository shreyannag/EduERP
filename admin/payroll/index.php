<?php
// admin/payroll/index.php
require_once __DIR__ . '/../../includes/admin_header.php';

if (!can_process_payroll()) {
    $_SESSION['flash_error'] = "Access Denied: Only Admins and Accountants can access the Payroll Hub.";
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$scoped_admin_id = is_super_admin() ? (!empty($_GET['admin_id']) ? (int)$_GET['admin_id'] : null) : get_effective_admin_id();

$selected_month = isset($_GET['month']) ? max(1, min(12, (int)$_GET['month'])) : (int)date('n');
$selected_year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$filter_role    = $_GET['sub_role'] ?? '';
$search         = trim($_GET['search'] ?? '');

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// Fetch staff members (sub-admins)
$staffWhere = "role = 'sub_admin'";
$staffParams = [];

if ($scoped_admin_id) {
    $staffWhere .= " AND parent_admin_id = ?";
    $staffParams[] = $scoped_admin_id;
}

if (!empty($filter_role)) {
    $staffWhere .= " AND sub_role = ?";
    $staffParams[] = $filter_role;
}

if (!empty($search)) {
    $staffWhere .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $staffParams[] = "%{$search}%";
    $staffParams[] = "%{$search}%";
    $staffParams[] = "%{$search}%";
}

$stmtStaff = $pdo->prepare("
    SELECT id, username, full_name, email, phone, sub_role, salary, is_active, created_at
    FROM admins
    WHERE {$staffWhere}
    ORDER BY is_active DESC, full_name ASC, username ASC
");
$stmtStaff->execute($staffParams);
$staffList = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing payments for the selected month & year
$paymentsWhere = "salary_month = ? AND salary_year = ?";
$paymentsParams = [$selected_month, $selected_year];

if ($scoped_admin_id) {
    $paymentsWhere .= " AND admin_id = ?";
    $paymentsParams[] = $scoped_admin_id;
}

$stmtPaid = $pdo->prepare("
    SELECT 
        p.*,
        pb.username as paid_by_username,
        pb.full_name as paid_by_fullname,
        pb.sub_role as paid_by_sub_role
    FROM payroll_payments p
    LEFT JOIN admins pb ON p.paid_by_id = pb.id
    WHERE {$paymentsWhere}
");
$stmtPaid->execute($paymentsParams);
$paidRecords = $stmtPaid->fetchAll(PDO::FETCH_ASSOC);

// Map payments by sub_admin_id
$paidByStaffId = [];
foreach ($paidRecords as $pr) {
    $paidByStaffId[(int)$pr['sub_admin_id']] = $pr;
}

// Compute Metrics
$totalStaffCount = count($staffList);
$paidCount = 0;
$totalDisbursed = 0;
$totalPending = 0;
$totalMonthlyCommitment = 0;

foreach ($staffList as $st) {
    $sid = (int)$st['id'];
    $baseSal = (float)$st['salary'];
    $totalMonthlyCommitment += $baseSal;

    if (isset($paidByStaffId[$sid])) {
        $paidCount++;
        $totalDisbursed += (float)$paidByStaffId[$sid]['net_amount'];
    } else {
        if (!empty($st['is_active'])) {
            $totalPending += $baseSal;
        }
    }
}
$unpaidCount = $totalStaffCount - $paidCount;

// Fetch Recent 10 Payroll Transactions for Audit Table
$recentWhere = "1=1";
$recentParams = [];
if ($scoped_admin_id) {
    $recentWhere .= " AND p.admin_id = ?";
    $recentParams[] = $scoped_admin_id;
}

$stmtRecent = $pdo->prepare("
    SELECT 
        p.*,
        s.username as staff_username,
        s.full_name as staff_fullname,
        s.sub_role as staff_sub_role,
        pb.username as paid_by_username,
        pb.full_name as paid_by_fullname
    FROM payroll_payments p
    JOIN admins s ON p.sub_admin_id = s.id
    LEFT JOIN admins pb ON p.paid_by_id = pb.id
    WHERE {$recentWhere}
    ORDER BY p.id DESC
    LIMIT 10
");
$stmtRecent->execute($recentParams);
$recentTransactions = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
.role-badge {
    padding: 0.3rem 0.65rem;
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 999px;
}
.role-teacher { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.role-accountant { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.role-custom { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
.staff-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #334155;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.9rem;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-money-check-alt text-success mr-2"></i>Staff Payroll & Salary Hub
        </h2>
        <p class="text-muted mb-0 small">
            Disburse monthly compensation to Teachers & Staff, track payment methods, generate automated receipt vouchers, and email printable payslips.
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap">
        <?php if (can_manage_sub_admins()): ?>
        <a href="<?php echo BASE_URL; ?>/admin/sub_admins/index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2">
            <i class="fas fa-user-shield mr-1"></i>Staff Directory
        </a>
        <?php endif; ?>
        <a href="my_salary.php" class="btn btn-outline-info font-weight-bold shadow-sm rounded-pill px-3 mb-2">
            <i class="fas fa-file-invoice-dollar mr-1"></i>My Payslips
        </a>
    </div>
</div>

<div id="ajaxAlertContainer"></div>

<!-- Period Selector Bar -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-light">
    <div class="card-body p-3">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-3 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Select Salary Month</label>
                <select name="month" class="form-control bg-white custom-select" onchange="this.form.submit()">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($selected_month === $m) ? 'selected' : '' ?>>
                            <?= $monthNames[$m] ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Year</label>
                <select name="year" class="form-control bg-white custom-select" onchange="this.form.submit()">
                    <?php for ($y = date('Y') + 1; $y >= 2024; $y--): ?>
                        <option value="<?= $y ?>" <?= ($selected_year === $y) ? 'selected' : '' ?>>
                            <?= $y ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Staff Designation</label>
                <select name="sub_role" class="form-control bg-white custom-select">
                    <option value="">All Staff Roles</option>
                    <option value="teacher" <?= ($filter_role === 'teacher') ? 'selected' : '' ?>>Teachers Only</option>
                    <option value="accountant" <?= ($filter_role === 'accountant') ? 'selected' : '' ?>>Accountants Only</option>
                    <option value="custom" <?= ($filter_role === 'custom') ? 'selected' : '' ?>>Custom Staff</option>
                </select>
            </div>

            <div class="col-md-3 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Search Staff</label>
                <input type="text" name="search" class="form-control bg-white" placeholder="Staff name or username..." value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-dark font-weight-bold px-3">
                    <i class="fas fa-filter mr-1"></i>Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary font-weight-bold ml-1">Current</a>
            </div>
        </form>
    </div>
</div>

<!-- KPI Summary Cards for Selected Month -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-hand-holding-usd fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Total Disbursed</div>
                    <h3 class="font-weight-bold text-success mb-0">₹<?= number_format($totalDisbursed, 2) ?></h3>
                    <span class="small text-muted"><?= $paidCount ?> of <?= $totalStaffCount ?> staff paid</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-hourglass-half fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Pending Salaries</div>
                    <h3 class="font-weight-bold text-warning mb-0">₹<?= number_format($totalPending, 2) ?></h3>
                    <span class="small text-muted"><?= $unpaidCount ?> staff awaiting payout</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-users-cog fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Disbursement Rate</div>
                    <h3 class="font-weight-bold text-primary mb-0">
                        <?= $totalStaffCount > 0 ? round(($paidCount / $totalStaffCount) * 100) : 0 ?>%
                    </h3>
                    <span class="small text-muted"><?= $monthNames[$selected_month] ?> <?= $selected_year ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-file-invoice fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Monthly Commitment</div>
                    <h3 class="font-weight-bold text-info mb-0">₹<?= number_format($totalMonthlyCommitment, 2) ?></h3>
                    <span class="small text-muted">Total staff base salary</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Staff Disbursement Table for Selected Month -->
<div class="card border-0 shadow-sm rounded-lg mb-5">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-calendar-check text-primary mr-2"></i>Staff Payroll Roster: <?= $monthNames[$selected_month] ?> <?= $selected_year ?>
            </h5>
            <small class="text-muted">Process pending salaries and review completed disbursements.</small>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 28%;">Staff Member</th>
                    <th style="width: 15%;">Designation</th>
                    <th style="width: 15%;">Base Salary</th>
                    <th style="width: 25%;">Status & Payment Info</th>
                    <th style="width: 17%; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($staffList)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="fas fa-user-shield fa-3x mb-3 text-secondary opacity-50"></i>
                        <p class="mb-0 font-weight-bold">No sub-admin staff members found.</p>
                        <small>Add Teachers or Staff in the <a href="../sub_admins/index.php">Staff Directory</a> to initiate payroll.</small>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($staffList as $st): 
                        $sid = (int)$st['id'];
                        $isPaid = isset($paidByStaffId[$sid]);
                        $payRecord = $isPaid ? $paidByStaffId[$sid] : null;
                        $roleClass = 'role-' . ($st['sub_role'] ?? 'custom');
                        $displayName = $st['full_name'] ?: $st['username'];
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="staff-avatar mr-2">
                                    <?= strtoupper(substr($displayName, 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="font-weight-bold text-dark mb-0"><?= htmlspecialchars($displayName) ?></div>
                                    <div class="text-muted small">
                                        @<?= htmlspecialchars($st['username']) ?>
                                        <?php if (!empty($st['email'])): ?>
                                            &bull; <?= htmlspecialchars($st['email']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="role-badge <?= $roleClass ?>">
                                <?= ucfirst($st['sub_role'] ?? 'Staff') ?>
                            </span>
                        </td>

                        <td>
                            <span class="font-weight-bold text-dark">
                                ₹<?= number_format((float)$st['salary'], 2) ?>
                            </span>
                            <small class="text-muted d-block">Monthly Base</small>
                        </td>

                        <td>
                            <?php if ($isPaid): ?>
                                <span class="badge badge-success px-2 py-1 font-weight-bold mb-1">
                                    <i class="fas fa-check-circle mr-1"></i>PAID ₹<?= number_format($payRecord['net_amount'], 2) ?>
                                </span>
                                <div class="small text-muted">
                                    <span class="text-uppercase font-weight-bold text-dark"><?= htmlspecialchars(str_replace('_', ' ', $payRecord['payment_method'])) ?></span>
                                    &bull; <?= date('d M, Y', strtotime($payRecord['payment_date'])) ?>
                                    <br><code class="text-dark">#<?= htmlspecialchars($payRecord['receipt_no']) ?></code>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-warning text-dark px-2 py-1 font-weight-bold">
                                    <i class="fas fa-clock mr-1"></i>PENDING PAYOUT
                                </span>
                                <div class="small text-muted mt-1">Awaiting salary disbursement</div>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <?php if ($isPaid): ?>
                                <div class="btn-group btn-group-sm">
                                    <a href="payslip.php?id=<?= $payRecord['id'] ?>" target="_blank" class="btn btn-outline-primary font-weight-bold shadow-sm" title="Print Payslip Voucher">
                                        <i class="fas fa-print mr-1"></i>Payslip
                                    </a>
                                    <button type="button" class="btn btn-outline-secondary font-weight-bold" title="Resend Payslip Email" onclick="resendPayslipEmail(<?= $payRecord['id'] ?>)">
                                        <i class="fas fa-envelope"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-success font-weight-bold shadow-sm rounded-pill px-3" onclick='openPaySalaryModal(<?= json_encode($st) ?>)'>
                                    <i class="fas fa-paper-plane mr-1"></i>Disburse Salary
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Recent Transactions Audit Table -->
<div class="card border-0 shadow-sm rounded-lg mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-history text-secondary mr-2"></i>Recent Payroll Disbursements Audit Log
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th>Receipt #</th>
                    <th>Staff Member</th>
                    <th>Salary Period</th>
                    <th>Net Disbursed</th>
                    <th>Payment Method</th>
                    <th>Processed By</th>
                    <th>Date</th>
                    <th style="text-align: right;">Voucher</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTransactions)): ?>
                <tr>
                    <td colspan="8" class="text-center py-4 text-muted">No payroll transactions processed yet.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($recentTransactions as $rt): 
                        $rtPeriod = ($monthNames[(int)$rt['salary_month']] ?? 'Month') . ' ' . $rt['salary_year'];
                    ?>
                    <tr>
                        <td><code class="font-weight-bold text-dark"><?= htmlspecialchars($rt['receipt_no']) ?></code></td>
                        <td>
                            <strong><?= htmlspecialchars($rt['staff_fullname'] ?: $rt['staff_username']) ?></strong>
                            <small class="text-muted d-block"><?= ucfirst($rt['staff_sub_role'] ?? 'Staff') ?></small>
                        </td>
                        <td><?= htmlspecialchars($rtPeriod) ?></td>
                        <td><span class="font-weight-bold text-success">₹<?= number_format($rt['net_amount'], 2) ?></span></td>
                        <td><span class="text-uppercase small font-weight-bold text-dark"><?= htmlspecialchars(str_replace('_', ' ', $rt['payment_method'])) ?></span></td>
                        <td><small class="text-muted"><?= htmlspecialchars($rt['paid_by_fullname'] ?: ($rt['paid_by_username'] ?: 'Admin')) ?></small></td>
                        <td><small class="text-muted"><?= date('d M, Y', strtotime($rt['payment_date'])) ?></small></td>
                        <td class="text-right">
                            <a href="payslip.php?id=<?= $rt['id'] ?>" target="_blank" class="btn btn-sm btn-outline-info py-0 px-2 font-weight-bold">
                                <i class="fas fa-file-invoice"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: DISBURSE SALARY                     -->
<!-- ========================================== -->
<div class="modal fade" id="paySalaryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-success text-white py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-hand-holding-usd mr-2"></i>Disburse Salary Payment
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="paySalaryForm" onsubmit="handlePaySalary(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="sub_admin_id" id="modalSubAdminId">
                <div class="modal-body p-4">
                    <!-- Staff Overview Banner -->
                    <div class="card bg-light border-0 p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="font-weight-bold text-dark mb-0" id="modalStaffName">Staff Member</h6>
                                <span class="small text-muted" id="modalStaffEmail">email@example.com</span>
                            </div>
                            <span class="role-badge role-teacher" id="modalStaffRoleBadge">Teacher</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Salary Month <span class="text-danger">*</span></label>
                            <select name="salary_month" id="modalSalaryMonth" class="form-control custom-select" required>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= ($selected_month === $m) ? 'selected' : '' ?>>
                                        <?= $monthNames[$m] ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Salary Year <span class="text-danger">*</span></label>
                            <input type="number" name="salary_year" id="modalSalaryYear" class="form-control" value="<?= $selected_year ?>" required min="2024" max="2035">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-4 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Base Salary (₹) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="base_salary" id="modalBaseSalary" class="form-control" required min="0" oninput="calculateNetPay()">
                        </div>

                        <div class="col-md-4 form-group mb-3">
                            <label class="font-weight-bold text-success small mb-1">+ Bonus / Incentives (₹)</label>
                            <input type="number" step="0.01" name="bonus" id="modalBonus" class="form-control text-success" value="0.00" min="0" oninput="calculateNetPay()">
                        </div>

                        <div class="col-md-4 form-group mb-3">
                            <label class="font-weight-bold text-danger small mb-1">- Deductions (₹)</label>
                            <input type="number" step="0.01" name="deductions" id="modalDeductions" class="form-control text-danger" value="0.00" min="0" oninput="calculateNetPay()">
                        </div>
                    </div>

                    <!-- Net Calculation Display -->
                    <div class="card bg-success bg-opacity-10 border-success p-3 mb-3 text-center">
                        <span class="small font-weight-bold text-uppercase text-muted">Net Payable Amount</span>
                        <h3 class="font-weight-bold text-success mb-0" id="modalNetPayDisplay">₹0.00</h3>
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Payment Method</label>
                            <select name="payment_method" class="form-control custom-select" required>
                                <option value="bank_transfer">Bank Transfer (NEFT/IMPS)</option>
                                <option value="upi">UPI / QR Code</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>

                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Reference / UTR / Cheque #</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="e.g. UTR198472910" maxlength="100">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Notes / Remarks</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional notes for payslip" maxlength="255">
                        </div>
                    </div>

                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="sendPayslipEmailCheck" name="send_email" value="1" checked>
                        <label class="custom-control-label font-weight-bold small text-dark" for="sendPayslipEmailCheck">
                            <i class="fas fa-envelope text-primary mr-1"></i>Automatically email printable payslip voucher to staff member
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold rounded-pill px-4" id="paySalarySubmitBtn">
                        <i class="fas fa-check-circle mr-1"></i>Confirm Disbursement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= generate_csrf_token() ?>';

function showAlert(type, msg) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${msg}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    document.getElementById('ajaxAlertContainer').innerHTML = alertHtml;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openPaySalaryModal(staff) {
    document.getElementById('modalSubAdminId').value = staff.id;
    document.getElementById('modalStaffName').textContent = staff.full_name || staff.username;
    document.getElementById('modalStaffEmail').textContent = staff.email ? `@${staff.username} • ${staff.email}` : `@${staff.username}`;
    
    const badge = document.getElementById('modalStaffRoleBadge');
    badge.textContent = (staff.sub_role || 'Staff').toUpperCase();
    badge.className = 'role-badge role-' + (staff.sub_role || 'custom');

    document.getElementById('modalBaseSalary').value = parseFloat(staff.salary || 0).toFixed(2);
    document.getElementById('modalBonus').value = '0.00';
    document.getElementById('modalDeductions').value = '0.00';
    
    calculateNetPay();
    $('#paySalaryModal').modal('show');
}

function calculateNetPay() {
    const base = parseFloat(document.getElementById('modalBaseSalary').value) || 0;
    const bonus = parseFloat(document.getElementById('modalBonus').value) || 0;
    const deductions = parseFloat(document.getElementById('modalDeductions').value) || 0;

    const net = Math.max(0, (base + bonus) - deductions);
    document.getElementById('modalNetPayDisplay').textContent = '₹' + net.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function handlePaySalary(e) {
    e.preventDefault();
    const btn = document.getElementById('paySalarySubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Processing...';

    const formData = new FormData(e.target);

    fetch('pay_salary_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Confirm Disbursement';
        if (data.success) {
            $('#paySalaryModal').modal('hide');
            showAlert('success', data.message);
            setTimeout(() => location.reload(), 1500);
        } else {
            alert(data.error || 'Failed to process salary payment.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Confirm Disbursement';
        alert('An unexpected error occurred: ' + err);
    });
}

function resendPayslipEmail(paymentId) {
    if (!confirm('Resend the official payslip email to this staff member?')) return;

    const formData = new FormData();
    formData.append('payment_id', paymentId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('resend_payslip_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
        } else {
            alert(data.error || 'Failed to send payslip email.');
        }
    })
    .catch(err => alert('Error sending payslip: ' + err));
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

<?php
// admin/payroll/my_salary.php
require_once __DIR__ . '/../../includes/admin_header.php';

$staff_id = (int)$_SESSION['admin_id'];

// Fetch current user details & base salary
$stmtUser = $pdo->prepare("SELECT id, username, full_name, email, role, sub_role, salary, institute_name FROM admins WHERE id = ?");
$stmtUser->execute([$staff_id]);
$staff = $stmtUser->fetch(PDO::FETCH_ASSOC);

// Fetch all payroll payments for this staff member
$stmtPayments = $pdo->prepare("
    SELECT 
        p.*,
        pb.username as paid_by_username,
        pb.full_name as paid_by_fullname
    FROM payroll_payments p
    LEFT JOIN admins pb ON p.paid_by_id = pb.id
    WHERE p.sub_admin_id = ?
    ORDER BY p.salary_year DESC, p.salary_month DESC
");
$stmtPayments->execute([$staff_id]);
$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

$totalDisbursed = 0;
foreach ($payments as $p) {
    $totalDisbursed += (float)$p['net_amount'];
}

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-file-invoice-dollar text-success mr-2"></i>My Salary & Payslips
        </h2>
        <p class="text-muted mb-0 small">
            View your personal salary history, monthly compensation vouchers, and download printable payslips.
        </p>
    </div>
    <div class="mt-2 mt-md-0">
        <?php if (can_process_payroll()): ?>
        <a href="index.php" class="btn btn-outline-primary font-weight-bold shadow-sm rounded-pill px-3">
            <i class="fas fa-money-check-alt mr-1"></i>Institute Payroll Hub
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Personal Compensation Overview Cards -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-4 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-user-tag fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Designated Role</div>
                    <h4 class="font-weight-bold text-dark mb-0">
                        <?= ucfirst($staff['sub_role'] ?? $staff['role']) ?>
                    </h4>
                    <span class="small text-muted"><?= htmlspecialchars($staff['institute_name'] ?: 'Main Campus') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-4 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-hand-holding-usd fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Configured Base Salary</div>
                    <h4 class="font-weight-bold text-info mb-0">
                        ₹<?= number_format((float)($staff['salary'] ?? 0), 2) ?>
                    </h4>
                    <span class="small text-muted">Fixed monthly base pay</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-4 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-wallet fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Total Paid Out</div>
                    <h4 class="font-weight-bold text-success mb-0">
                        ₹<?= number_format($totalDisbursed, 2) ?>
                    </h4>
                    <span class="small text-muted"><?= count($payments) ?> Payslips Issued</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Payslips History Table -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-history text-primary mr-2"></i>Salary Payment History (<?= count($payments) ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 15%;">Salary Period</th>
                    <th style="width: 15%;">Receipt #</th>
                    <th style="width: 15%;">Base Salary</th>
                    <th style="width: 15%;">Bonus & Deductions</th>
                    <th style="width: 15%;">Net Disbursed</th>
                    <th style="width: 15%;">Payment Details</th>
                    <th style="width: 10%; text-align: right;">Payslip</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-file-invoice fa-3x mb-3 text-secondary opacity-50"></i>
                        <p class="mb-0 font-weight-bold">No salary disbursements recorded yet.</p>
                        <small>When your administrator processes your monthly payroll, your payslips will appear here.</small>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): 
                        $periodText = ($monthNames[(int)$p['salary_month']] ?? 'Month') . ' ' . $p['salary_year'];
                    ?>
                    <tr>
                        <td>
                            <span class="font-weight-bold text-dark d-block">
                                <?= htmlspecialchars($periodText) ?>
                            </span>
                            <small class="text-muted"><?= date('d M, Y', strtotime($p['payment_date'])) ?></small>
                        </td>

                        <td>
                            <code class="font-weight-bold text-dark"><?= htmlspecialchars($p['receipt_no']) ?></code>
                        </td>

                        <td>
                            ₹<?= number_format($p['base_salary'], 2) ?>
                        </td>

                        <td>
                            <?php if ((float)$p['bonus'] > 0): ?>
                                <span class="badge badge-success px-2 py-1">+ ₹<?= number_format($p['bonus'], 2) ?> Bonus</span><br>
                            <?php endif; ?>
                            <?php if ((float)$p['deductions'] > 0): ?>
                                <span class="badge badge-danger px-2 py-1">- ₹<?= number_format($p['deductions'], 2) ?> Ded.</span>
                            <?php endif; ?>
                            <?php if ((float)$p['bonus'] == 0 && (float)$p['deductions'] == 0): ?>
                                <span class="text-muted small">&mdash;</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <span class="font-weight-bold text-success" style="font-size: 1.05rem;">
                                ₹<?= number_format($p['net_amount'], 2) ?>
                            </span>
                        </td>

                        <td>
                            <div class="small text-dark font-weight-bold text-uppercase">
                                <?= htmlspecialchars(str_replace('_', ' ', $p['payment_method'])) ?>
                            </div>
                            <?php if (!empty($p['reference_no'])): ?>
                                <small class="text-muted">Ref: <?= htmlspecialchars($p['reference_no']) ?></small>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <a href="payslip.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3 shadow-sm">
                                <i class="fas fa-print mr-1"></i>Payslip
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

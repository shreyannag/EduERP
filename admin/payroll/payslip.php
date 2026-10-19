<?php
// admin/payroll/payslip.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

$payment_id = (int)($_GET['id'] ?? 0);
$receipt_no = trim($_GET['receipt'] ?? '');

if (!$payment_id && empty($receipt_no)) {
    die("Invalid request: Payment ID or Receipt Number is required.");
}

$where = $payment_id ? "p.id = ?" : "p.receipt_no = ?";
$val   = $payment_id ? $payment_id : $receipt_no;

$stmt = $pdo->prepare("
    SELECT 
        p.*,
        s.username as staff_username,
        s.full_name as staff_fullname,
        s.email as staff_email,
        s.phone as staff_phone,
        s.sub_role as staff_sub_role,
        a.username as admin_username,
        a.full_name as admin_fullname,
        a.institute_name,
        a.email as admin_email,
        pb.username as paid_by_username,
        pb.full_name as paid_by_fullname,
        pb.role as paid_by_role,
        pb.sub_role as paid_by_sub_role
    FROM payroll_payments p
    JOIN admins s ON p.sub_admin_id = s.id
    JOIN admins a ON p.admin_id = a.id
    LEFT JOIN admins pb ON p.paid_by_id = pb.id
    WHERE {$where}
");
$stmt->execute([$val]);
$pay = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pay) {
    die("Payroll voucher record not found.");
}

// Access Control
$effective_admin_id = get_effective_admin_id();
$isSelf = ((int)$_SESSION['admin_id'] === (int)$pay['sub_admin_id']);
$isInstituteAuthorized = is_super_admin() || ((int)$pay['admin_id'] === $effective_admin_id && can_process_payroll());

if (!$isSelf && !$isInstituteAuthorized) {
    http_response_code(403);
    die("Access Denied: You do not have permission to view this payslip.");
}

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$periodName = ($monthNames[(int)$pay['salary_month']] ?? 'Month') . ' ' . $pay['salary_year'];

$staffName = $pay['staff_fullname'] ?: $pay['staff_username'];
$instituteName = $pay['institute_name'] ?: 'Online Examination & Coaching Portal';
$processedBy = $pay['paid_by_fullname'] ?: ($pay['paid_by_username'] ?: 'Authorized Administrator');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?= htmlspecialchars($pay['receipt_no']) ?> - <?= htmlspecialchars($staffName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .payslip-container {
            max-width: 800px;
            margin: 30px auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .voucher-header {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: #ffffff;
            padding: 30px;
        }
        .table-salary th, .table-salary td {
            padding: 10px 14px;
            vertical-align: middle;
        }
        .net-pay-box {
            background: #f0fdf4;
            border: 2px solid #86efac;
            border-radius: 8px;
            padding: 15px 20px;
        }
        .signature-line {
            border-top: 1px solid #94a3b8;
            margin-top: 50px;
            padding-top: 8px;
            text-align: center;
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 600;
        }
        @media print {
            body {
                background: #ffffff !important;
            }
            .no-print {
                display: none !important;
            }
            .payslip-container {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            .voucher-header {
                background: #1e3a8a !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<div class="container my-3 no-print">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <?php if (is_sub_admin() && !can_process_payroll()): ?>
                <a href="my_salary.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-3">
                    <i class="fas fa-arrow-left mr-1"></i>Back to My Salary History
                </a>
            <?php else: ?>
                <a href="index.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-3">
                    <i class="fas fa-arrow-left mr-1"></i>Back to Payroll Hub
                </a>
            <?php endif; ?>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">
                <i class="fas fa-print mr-2"></i>Print Payslip
            </button>
        </div>
    </div>
</div>

<div class="payslip-container">
    <!-- Header -->
    <div class="voucher-header">
        <div class="row align-items-center">
            <div class="col-8">
                <h3 class="font-weight-bold mb-1 text-white"><?= htmlspecialchars($instituteName) ?></h3>
                <p class="mb-0 text-white-50 small">Official Staff Salary Disbursement Voucher</p>
                <?php if (!empty($pay['admin_email'])): ?>
                    <small class="text-white-50"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($pay['admin_email']) ?></small>
                <?php endif; ?>
            </div>
            <div class="col-4 text-right">
                <span class="badge badge-light px-3 py-2 text-primary font-weight-bold text-uppercase" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                    PAID VOUCHER
                </span>
                <div class="mt-2 text-white font-weight-bold small">
                    #<?= htmlspecialchars($pay['receipt_no']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="p-4 p-md-5">
        <!-- Sub-header Details -->
        <div class="row mb-4 pb-3 border-bottom">
            <div class="col-sm-6 mb-3 mb-sm-0">
                <h6 class="text-uppercase text-muted font-weight-bold small mb-2">Staff Member Information</h6>
                <h5 class="font-weight-bold text-dark mb-1"><?= htmlspecialchars($staffName) ?></h5>
                <div class="text-muted small">
                    <strong>Designation:</strong> <?= ucfirst($pay['staff_sub_role'] ?? 'Staff') ?> / Faculty<br>
                    <strong>Username:</strong> @<?= htmlspecialchars($pay['staff_username']) ?><br>
                    <?php if (!empty($pay['staff_email'])): ?>
                        <strong>Email:</strong> <?= htmlspecialchars($pay['staff_email']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($pay['staff_phone'])): ?>
                        <strong>Phone:</strong> <?= htmlspecialchars($pay['staff_phone']) ?><br>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-sm-6 text-sm-right">
                <h6 class="text-uppercase text-muted font-weight-bold small mb-2">Disbursement Details</h6>
                <div class="text-dark small">
                    <strong>Salary Period:</strong> <span class="badge badge-primary px-2 py-1"><?= htmlspecialchars($periodName) ?></span><br>
                    <strong>Disbursement Date:</strong> <?= date('d M, Y', strtotime($pay['payment_date'])) ?><br>
                    <strong>Payment Method:</strong> <span class="text-uppercase font-weight-bold text-dark"><?= htmlspecialchars(str_replace('_', ' ', $pay['payment_method'])) ?></span><br>
                    <?php if (!empty($pay['reference_no'])): ?>
                        <strong>Reference / UTR:</strong> <code class="text-dark font-weight-bold"><?= htmlspecialchars($pay['reference_no']) ?></code><br>
                    <?php endif; ?>
                    <strong>Authorized By:</strong> <?= htmlspecialchars($processedBy) ?><br>
                </div>
            </div>
        </div>

        <!-- Breakdown Table -->
        <h6 class="font-weight-bold text-dark mb-3"><i class="fas fa-file-invoice-dollar text-primary mr-2"></i>Itemized Salary Breakdown</h6>
        <div class="table-responsive mb-4">
            <table class="table table-bordered table-salary mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 50%;">Earnings Description</th>
                        <th style="width: 50%; text-align: right;">Amount (INR)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <strong>Basic Monthly Salary</strong>
                            <div class="small text-muted">Fixed agreed compensation</div>
                        </td>
                        <td class="text-right font-weight-bold">
                            ₹<?= number_format($pay['base_salary'], 2) ?>
                        </td>
                    </tr>
                    <?php if ((float)$pay['bonus'] > 0): ?>
                    <tr>
                        <td class="text-success">
                            <strong><i class="fas fa-plus-circle mr-1"></i>Performance Bonus / Incentives</strong>
                            <div class="small text-muted">Performance incentive or bonus allowance</div>
                        </td>
                        <td class="text-right font-weight-bold text-success">
                            + ₹<?= number_format($pay['bonus'], 2) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ((float)$pay['deductions'] > 0): ?>
                    <tr>
                        <td class="text-danger">
                            <strong><i class="fas fa-minus-circle mr-1"></i>Deductions / Penalties / Leave</strong>
                            <div class="small text-muted">Statutory deductions, leave without pay, or adjustments</div>
                        </td>
                        <td class="text-right font-weight-bold text-danger">
                            - ₹<?= number_format($pay['deductions'], 2) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Net Amount Box -->
        <div class="net-pay-box mb-4">
            <div class="row align-items-center">
                <div class="col-sm-7">
                    <span class="text-muted font-weight-bold small text-uppercase">Net Disbursed Take-Home Pay</span>
                    <h2 class="font-weight-bold text-success mb-0">
                        ₹<?= number_format($pay['net_amount'], 2) ?>
                    </h2>
                </div>
                <div class="col-sm-5 text-sm-right mt-2 mt-sm-0">
                    <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size: 0.9rem;">
                        <i class="fas fa-check-double mr-1"></i>CONFIRMED PAID
                    </span>
                </div>
            </div>
        </div>

        <?php if (!empty($pay['notes'])): ?>
        <div class="card bg-light border-0 mb-4">
            <div class="card-body p-3">
                <h6 class="font-weight-bold small text-muted mb-1">Remarks & Notes:</h6>
                <p class="mb-0 small text-dark"><?= nl2br(htmlspecialchars($pay['notes'])) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="row mt-5 pt-4">
            <div class="col-6">
                <div class="signature-line">
                    Staff Member Signature<br>
                    <small class="text-muted font-weight-normal">(<?= htmlspecialchars($staffName) ?>)</small>
                </div>
            </div>
            <div class="col-6">
                <div class="signature-line">
                    Authorized Signatory / Accountant<br>
                    <small class="text-muted font-weight-normal">(<?= htmlspecialchars($processedBy) ?>)</small>
                </div>
            </div>
        </div>

        <div class="text-center mt-5 text-muted small">
            <em>This is a computer-generated voucher and official record of salary payment from <?= htmlspecialchars($instituteName) ?>.</em>
        </div>
    </div>
</div>

</body>
</html>

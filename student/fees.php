<?php
// student/fees.php
require_once __DIR__ . '/../includes/student_header.php';
require_once __DIR__ . '/../includes/fee_manager.php';

$student_id = (int)$_SESSION['student_id'];
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
if ($selectedYear < 2020 || $selectedYear > 2035) {
    $selectedYear = $currentYear;
}

$ledgerData = get_student_fee_ledger($student_id, $selectedYear, $pdo);
$currency = $ledgerData['currency'];
$student = $ledgerData['student'];
$ledger = $ledgerData['ledger'];
?>

<style>
.fee-card {
    border-radius: 12px;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.fee-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
}
.month-card {
    border-radius: 10px;
    border: 2px solid transparent;
    transition: all 0.2s ease;
    background: #ffffff;
}
.month-card.paid {
    border-color: #a7f3d0;
    background: #f0fdf4;
}
.month-card.partial {
    border-color: #fde68a;
    background: #fffbeb;
}
.month-card.unpaid {
    border-color: #fecaca;
    background: #fef2f2;
}
.month-card.future {
    border-color: #e2e8f0;
    background: #f8fafc;
    opacity: 0.85;
}
.month-pill {
    font-weight: 700;
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
@media print {
    body { background: #fff !important; }
    .student-navbar, .no-print, .btn, .nav-pills { display: none !important; }
    .container { max-width: 100% !important; width: 100% !important; padding: 0 !important; }
    .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .print-only { display: block !important; }
}
.print-only { display: none; }
</style>

<div class="print-only mb-4 p-3 border-bottom">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2 class="font-weight-bold mb-0">Student Tuition & Fee Statement</h2>
            <p class="text-muted mb-0">Academic Year: <?php echo $selectedYear; ?> • Student: <?php echo h($student['full_name']); ?> (Roll: <?php echo h($student['username']); ?>)</p>
        </div>
        <div class="text-right">
            <h5 class="mb-0 font-weight-bold">Class <?php echo h($student['class_name']); ?></h5>
            <small class="text-muted"><?php echo h($student['board_name']); ?></small>
        </div>
    </div>
</div>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 no-print">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-receipt mr-2 text-primary"></i>Fee Payment Ledger
        </h2>
        <p class="text-muted mb-0">Track monthly fee payments, balance dues, and verified receipts</p>
    </div>
    <div class="mt-3 mt-md-0 d-flex align-items-center">
        <div class="dropdown mr-2">
            <button class="btn btn-outline-secondary dropdown-toggle font-weight-bold shadow-sm" type="button" data-toggle="dropdown">
                <i class="fas fa-calendar-alt mr-1"></i> Year: <?php echo $selectedYear; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-right shadow border-0">
                <?php for($y = $currentYear + 1; $y >= $currentYear - 2; $y--): ?>
                    <a class="dropdown-item <?php echo $y === $selectedYear ? 'active font-weight-bold' : ''; ?>" href="fees.php?year=<?php echo $y; ?>">
                        <?php echo $y; ?> <?php echo $y === $currentYear ? '(Current)' : ''; ?>
                    </a>
                <?php endfor; ?>
            </div>
        </div>
        <button class="btn btn-primary font-weight-bold shadow-sm" onclick="window.print()">
            <i class="fas fa-print mr-1"></i> Print Statement
        </button>
    </div>
</div>

<?php if (!empty($student['custom_fee_profile']['is_custom'])): ?>
<!-- Custom Fee Concession / Scholarship Notice Banner -->
<div class="card border-0 rounded-lg shadow-sm mb-4" style="background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%); border-left: 4px solid #10b981 !important;">
    <div class="card-body p-3 p-md-4 d-flex align-items-center">
        <div class="mr-3 text-success">
            <span class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px; min-width: 44px;">
                <i class="fas fa-award fa-lg"></i>
            </span>
        </div>
        <div>
            <h6 class="font-weight-bold text-success mb-1">
                <i class="fas fa-check-circle mr-1"></i>Special Fee Concession / Scholarship Applied
            </h6>
            <div class="text-dark small">
                Your monthly tuition is calculated under a customized fee structure: 
                <strong class="text-dark"><?php echo h($student['custom_fee_profile']['discount_desc']); ?></strong>.
                Standard Class Rate: <del class="text-muted"><?php echo h($currency) . number_format($student['custom_fee_profile']['base_fee'], 2); ?></del> 
                &rarr; Effective Monthly: <span class="badge badge-success px-2 py-1 font-weight-bold" style="font-size: 0.85rem;"><?php echo h($currency) . number_format($student['custom_fee_profile']['effective_fee'], 2); ?></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Stat KPI Cards -->
<div class="row mb-4">
    <div class="col-6 col-lg-3 mb-3 mb-lg-0">
        <div class="card fee-card h-100 bg-white">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small font-weight-bold text-uppercase">Monthly Fee</span>
                        <h3 class="font-weight-bold text-dark mb-0 mt-1">
                            <?php if (!empty($student['custom_fee_profile']['is_custom']) && $student['custom_fee_profile']['base_fee'] != $ledgerData['monthly_rate']): ?>
                                <small class="text-muted text-decoration-line-through mr-1" style="font-size: 0.65em; text-decoration: line-through;"><?php echo $currency . number_format($student['custom_fee_profile']['base_fee'], 0); ?></small>
                            <?php endif; ?>
                            <?php echo $currency . number_format($ledgerData['monthly_rate'], 0); ?>
                        </h3>
                        <?php if (!empty($student['custom_fee_profile']['is_custom'])): ?>
                            <small class="text-success font-weight-bold"><i class="fas fa-tag mr-1"></i>Custom Concession</small>
                        <?php else: ?>
                            <small class="text-muted">Class <?php echo h($student['class_name']); ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width:44px; height:44px;">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3 mb-3 mb-lg-0">
        <div class="card fee-card h-100 bg-white">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-success small font-weight-bold text-uppercase">Total Paid (<?php echo $selectedYear; ?>)</span>
                        <h3 class="font-weight-bold text-success mb-0 mt-1">
                            <?php echo $currency . number_format($ledgerData['total_paid'], 0); ?>
                        </h3>
                        <small class="text-success font-weight-bold"><?php echo $ledgerData['paid_months_count']; ?> Month(s) Cleared</small>
                    </div>
                    <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width:44px; height:44px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3 mb-3 mb-lg-0">
        <div class="card fee-card h-100 bg-white">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-danger small font-weight-bold text-uppercase">Balance Due</span>
                        <h3 class="font-weight-bold text-danger mb-0 mt-1">
                            <?php echo $currency . number_format($ledgerData['total_due'], 0); ?>
                        </h3>
                        <small class="<?php echo $ledgerData['total_due'] > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                            <?php echo $ledgerData['total_due'] > 0 ? $ledgerData['unpaid_months_count'] . ' Month(s) Pending' : 'All Dues Cleared'; ?>
                        </small>
                    </div>
                    <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width:44px; height:44px;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3 mb-3 mb-lg-0">
        <div class="card fee-card h-100 bg-white">
            <div class="card-body p-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small font-weight-bold text-uppercase">This Month</span>
                        <?php 
                        $curStatus = $ledger[$currentMonth]['status'] ?? 'unpaid';
                        if ($curStatus === 'paid'): 
                        ?>
                            <h4 class="font-weight-bold text-success mb-0 mt-1">Paid ✓</h4>
                            <small class="text-muted">Cleared on <?php echo date('d M', strtotime($ledger[$currentMonth]['payment_date'])); ?></small>
                        <?php elseif ($curStatus === 'partial'): ?>
                            <h4 class="font-weight-bold text-warning mb-0 mt-1">Partially Paid</h4>
                            <small class="text-danger">Bal: <?php echo $currency . number_format($ledger[$currentMonth]['amount_due'] - $ledger[$currentMonth]['amount_paid'], 0); ?></small>
                        <?php elseif ($curStatus === 'not_enrolled'): ?>
                            <h4 class="font-weight-bold text-secondary mb-0 mt-1">Not Enrolled</h4>
                            <small class="text-muted">Joined <?php echo date('d M Y', strtotime($ledgerData['enrollment_date'])); ?></small>
                        <?php else: ?>
                            <h4 class="font-weight-bold text-danger mb-0 mt-1">Due / Unpaid</h4>
                            <small class="text-danger">Please submit fee</small>
                        <?php endif; ?>
                    </div>
                    <div class="<?php echo $curStatus === 'paid' ? 'bg-success' : ($curStatus === 'not_enrolled' ? 'bg-secondary' : 'bg-warning'); ?> text-white rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width:44px; height:44px;">
                        <i class="fas <?php echo $curStatus === 'paid' ? 'fa-award' : ($curStatus === 'not_enrolled' ? 'fa-minus' : 'fa-clock'); ?>"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 12-Month Visual Grid -->
<div class="card fee-card mb-4">
    <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fas fa-calendar-check mr-2 text-primary"></i>12-Month Status Breakdown (<?php echo $selectedYear; ?>)
        </h5>
        <div class="d-none d-md-flex align-items-center small font-weight-bold">
            <span class="badge badge-success px-2 py-1 mr-2"><i class="fas fa-check mr-1"></i> Paid</span>
            <span class="badge badge-danger px-2 py-1 mr-2"><i class="fas fa-times mr-1"></i> Unpaid / Due</span>
            <span class="badge badge-light border text-muted px-2 py-1 mr-2"><i class="fas fa-minus mr-1"></i> Not Enrolled</span>
            <span class="badge badge-secondary px-2 py-1"><i class="fas fa-hourglass-start mr-1"></i> Upcoming</span>
        </div>
    </div>
    <div class="card-body p-3 p-md-4">
        <div class="row">
            <?php foreach ($ledger as $m): 
                $cardClass = $m['status'];
                if ($m['status'] === 'unpaid' && $m['is_future']) {
                    $cardClass = 'future';
                }
            ?>
                <div class="col-6 col-sm-4 col-md-3 col-xl-2 mb-3">
                    <div class="month-card <?php echo $cardClass; ?> p-3 h-100 d-flex flex-column justify-content-between shadow-sm">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="font-weight-bold mb-0 text-dark"><?php echo $m['month_name']; ?></h6>
                                <?php if($m['is_current_month']): ?>
                                    <span class="badge badge-primary px-1 font-weight-bold" style="font-size: 9px;">CURRENT</span>
                                <?php endif; ?>
                            </div>

                            <div class="mb-2">
                                <?php if ($m['status'] === 'paid'): ?>
                                    <span class="badge badge-success month-pill w-100 py-1">
                                        <i class="fas fa-check-circle mr-1"></i> Paid
                                    </span>
                                <?php elseif ($m['status'] === 'partial'): ?>
                                    <span class="badge badge-warning month-pill w-100 py-1">
                                        <i class="fas fa-adjust mr-1"></i> Partial
                                    </span>
                                <?php elseif ($m['status'] === 'not_enrolled'): ?>
                                    <span class="badge badge-light border text-muted month-pill w-100 py-1" title="Not enrolled">
                                        <i class="fas fa-minus mr-1"></i> Not Enrolled
                                    </span>
                                <?php elseif ($m['is_future']): ?>
                                    <span class="badge badge-secondary month-pill w-100 py-1">
                                        Upcoming
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger month-pill w-100 py-1">
                                        <i class="fas fa-exclamation-circle mr-1"></i> Unpaid
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <div class="small font-weight-bold text-muted mb-1">
                                <?php if ($m['status'] === 'paid'): ?>
                                    <span class="text-success font-weight-bold"><?php echo $currency . number_format($m['amount_paid'], 0); ?></span>
                                    <span class="d-block small text-muted font-weight-normal"><?php echo date('d M Y', strtotime($m['payment_date'])); ?></span>
                                <?php elseif ($m['status'] === 'partial'): ?>
                                    <span class="text-dark">Paid: <?php echo $currency . number_format($m['amount_paid'], 0); ?></span>
                                    <span class="d-block text-danger font-weight-bold">Due: <?php echo $currency . number_format($m['amount_due'] - $m['amount_paid'], 0); ?></span>
                                <?php elseif ($m['status'] === 'not_enrolled'): ?>
                                    <span class="text-muted small">Not Enrolled (₹0)</span>
                                <?php else: ?>
                                    <span class="<?php echo $m['is_past'] || $m['is_current_month'] ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                                        Due: <?php echo $currency . number_format($m['amount_due'], 0); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($m['status'] === 'paid' && !empty($m['receipt_number'])): ?>
                                <button type="button" 
                                    class="btn btn-outline-success btn-sm btn-block py-0 font-weight-bold no-print view-receipt-btn" 
                                    style="font-size: 11px;"
                                    data-month="<?php echo $m['month_name']; ?>"
                                    data-year="<?php echo $selectedYear; ?>"
                                    data-amount="<?php echo $currency . number_format($m['amount_paid'], 2); ?>"
                                    data-date="<?php echo date('d M Y', strtotime($m['payment_date'])); ?>"
                                    data-method="<?php echo h($m['payment_method']); ?>"
                                    data-receipt="<?php echo h($m['receipt_number']); ?>"
                                    data-notes="<?php echo h($m['notes'] ?? ''); ?>">
                                    <i class="fas fa-file-invoice mr-1"></i> Receipt
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Detailed Table -->
<div class="card fee-card mb-4">
    <div class="card-header bg-white border-bottom py-3">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fas fa-list-alt mr-2 text-primary"></i>Detailed Monthly Payment Ledger (<?php echo $selectedYear; ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th class="px-4">Month</th>
                    <th>Fee Rate</th>
                    <th>Amount Paid</th>
                    <th>Balance Due</th>
                    <th>Payment Status</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th>Receipt Number</th>
                    <th class="text-right px-4 no-print">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ledger as $m): 
                    $balance = max(0, $m['amount_due'] - $m['amount_paid']);
                ?>
                    <tr class="<?php echo $m['is_current_month'] ? 'table-primary font-weight-bold' : ''; ?>">
                        <td class="px-4 font-weight-bold">
                            <?php echo $m['month_name']; ?> <?php echo $selectedYear; ?>
                            <?php if($m['is_current_month']): ?>
                                <span class="badge badge-primary ml-1" style="font-size: 10px;">CURRENT</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $currency . number_format($m['amount_due'], 2); ?></td>
                        <td class="<?php echo $m['amount_paid'] > 0 ? 'text-success font-weight-bold' : 'text-muted'; ?>">
                            <?php echo $currency . number_format($m['amount_paid'], 2); ?>
                        </td>
                        <td class="<?php echo ($m['status'] !== 'not_enrolled' && $balance > 0 && ($m['is_past'] || $m['is_current_month'])) ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                            <?php echo $m['status'] === 'not_enrolled' ? '—' : ($currency . number_format($balance, 2)); ?>
                        </td>
                        <td>
                            <?php if ($m['status'] === 'paid'): ?>
                                <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Paid</span>
                            <?php elseif ($m['status'] === 'partial'): ?>
                                <span class="badge badge-warning px-2 py-1"><i class="fas fa-adjust mr-1"></i> Partial</span>
                            <?php elseif ($m['status'] === 'not_enrolled'): ?>
                                <span class="badge badge-light border text-muted px-2 py-1" title="Not yet enrolled in this month"><i class="fas fa-minus mr-1"></i> Not Enrolled</span>
                            <?php elseif ($m['is_future']): ?>
                                <span class="badge badge-light border text-muted px-2 py-1">Upcoming</span>
                            <?php else: ?>
                                <span class="badge badge-danger px-2 py-1"><i class="fas fa-exclamation-circle mr-1"></i> Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php echo $m['payment_date'] ? date('d M Y', strtotime($m['payment_date'])) : '—'; ?>
                        </td>
                        <td class="small text-muted">
                            <?php echo !empty($m['payment_method']) ? h($m['payment_method']) : '—'; ?>
                        </td>
                        <td class="small font-family-monospace text-primary">
                            <?php echo !empty($m['receipt_number']) ? h($m['receipt_number']) : '—'; ?>
                        </td>
                        <td class="text-right px-4 no-print">
                            <?php if ($m['status'] === 'paid' && !empty($m['receipt_number'])): ?>
                                <button type="button" 
                                    class="btn btn-outline-primary btn-sm py-1 font-weight-bold view-receipt-btn"
                                    data-month="<?php echo $m['month_name']; ?>"
                                    data-year="<?php echo $selectedYear; ?>"
                                    data-amount="<?php echo $currency . number_format($m['amount_paid'], 2); ?>"
                                    data-date="<?php echo date('d M Y', strtotime($m['payment_date'])); ?>"
                                    data-method="<?php echo h($m['payment_method']); ?>"
                                    data-receipt="<?php echo h($m['receipt_number']); ?>"
                                    data-notes="<?php echo h($m['notes'] ?? ''); ?>">
                                    <i class="fas fa-eye mr-1"></i> View Receipt
                                </button>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-light font-weight-bold">
                <tr>
                    <td class="px-4">Annual Total</td>
                    <td><?php echo $currency . number_format($ledgerData['monthly_rate'] * 12, 2); ?></td>
                    <td class="text-success"><?php echo $currency . number_format($ledgerData['total_paid'], 2); ?></td>
                    <td class="text-danger"><?php echo $currency . number_format($ledgerData['total_due'], 2); ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Notice & Payment Information Card -->
<div class="card fee-card bg-light border-0 mb-4 no-print">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h5 class="font-weight-bold text-dark mb-1">
                    <i class="fas fa-info-circle mr-2 text-info"></i>Fee Payment Instructions & Inquiries
                </h5>
                <p class="text-muted small mb-0">
                    Tuition fees are due on or before the 15th of every month. Payments can be submitted at the center office via Cash or online via UPI/Bank Transfer. Once processed by administration, verified digital receipts will reflect instantly on this portal and be emailed to <strong><?php echo h($student['email'] ?? 'your registered email'); ?></strong>.
                </p>
            </div>
            <div class="col-md-4 text-md-right mt-3 mt-md-0">
                <a href="messages.php" class="btn btn-outline-dark font-weight-bold btn-sm shadow-sm">
                    <i class="fas fa-envelope mr-1"></i> Contact Administrator
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Digital Fee Receipt -->
<div class="modal fade" id="receiptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 border-0">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-receipt mr-2"></i>Official Fee Payment Receipt
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4" id="printableReceiptArea">
                <div class="text-center pb-3 border-bottom mb-3">
                    <h4 class="font-weight-bold mb-0 text-dark">Online Examination & Coaching</h4>
                    <p class="text-muted small mb-1">Student Academic Fee Clearance Acknowledgment</p>
                    <span class="badge badge-light border text-monospace text-primary px-3 py-1 font-weight-bold" id="rcptNum">REC-XXXX</span>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted d-block">Student Name:</small>
                        <strong class="text-dark" id="rcptStudent"><?php echo h($student['full_name']); ?></strong>
                    </div>
                    <div class="col-6 text-right">
                        <small class="text-muted d-block">Roll / Student ID:</small>
                        <strong class="text-dark" id="rcptRoll"><?php echo h($student['username']); ?></strong>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-6">
                        <small class="text-muted d-block">Class & Board:</small>
                        <span class="text-dark font-weight-bold">Class <?php echo h($student['class_name']); ?> (<?php echo h($student['board_name']); ?>)</span>
                    </div>
                    <div class="col-6 text-right">
                        <small class="text-muted d-block">Fee Month / Period:</small>
                        <span class="text-dark font-weight-bold" id="rcptMonth">October 2026</span>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-lg border mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted">Payment Date:</span>
                        <strong class="text-dark" id="rcptDate">—</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-muted">Payment Mode:</span>
                        <strong class="text-dark" id="rcptMethod">Cash</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
                        <span class="font-weight-bold text-dark">Amount Received:</span>
                        <h4 class="font-weight-bold text-success mb-0" id="rcptAmount">₹0.00</h4>
                    </div>
                </div>

                <div class="text-center pt-2">
                    <div class="badge badge-success px-3 py-2 font-weight-bold rounded-pill mb-2">
                        <i class="fas fa-check-circle mr-1"></i> PAYMENT VERIFIED & CREDITED
                    </div>
                    <p class="text-muted small mb-0" style="font-size: 11px;">Computer-generated acknowledgment voucher. Authorized by Administration.</p>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 border-0">
                <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary btn-sm font-weight-bold" onclick="printReceiptModal()">
                    <i class="fas fa-print mr-1"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.view-receipt-btn').on('click', function() {
        const btn = $(this);
        $('#rcptNum').text(btn.data('receipt'));
        $('#rcptMonth').text(btn.data('month') + ' ' + btn.data('year'));
        $('#rcptAmount').text(btn.data('amount'));
        $('#rcptDate').text(btn.data('date'));
        $('#rcptMethod').text(btn.data('method'));
        $('#receiptModal').modal('show');
    });
});

function printReceiptModal() {
    const printContent = document.getElementById('printableReceiptArea').innerHTML;
    const originalContent = document.body.innerHTML;
    document.body.innerHTML = `
        <div style="max-width: 500px; margin: 40px auto; padding: 20px; font-family: sans-serif; border: 2px dashed #94a3b8; border-radius: 8px;">
            ${printContent}
        </div>
    `;
    window.print();
    document.body.innerHTML = originalContent;
    window.location.reload();
}
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>

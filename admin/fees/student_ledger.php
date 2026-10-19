<?php
// admin/fees/student_ledger.php
// Detailed student fee ledger, statement history, and printable receipt.

require_once '../../includes/admin_header.php';
require_once '../../includes/fee_manager.php';

$student_id = intval($_GET['student_id'] ?? 0);
$year       = intval($_GET['year'] ?? date('Y'));

if (!$student_id) {
    header('Location: index.php');
    exit;
}

$ledgerData = get_student_fee_ledger($student_id, $year, $pdo);

if (!$ledgerData) {
    echo "<div class='alert alert-danger'>Student record not found.</div>";
    require_once '../../includes/admin_footer.php';
    exit;
}

$student = $ledgerData['student'];
$currency = $ledgerData['currency'];
?>

<style>
@media print {
    .sidebar, .navbar, .btn, .no-print, footer { display: none !important; }
    main { margin: 0 !important; padding: 0 !important; }
    .card { border: 1px solid #ccc !important; box-shadow: none !important; }
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 no-print">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Student Fee Ledger</h2>
        <span class="text-muted small">Complete monthly payment statement for <?php echo h($student['full_name']); ?> • Year <?php echo $year; ?></span>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-3 mr-2">
            <i class="fas fa-arrow-left mr-1"></i>Back
        </a>
        <button type="button" class="btn btn-outline-info font-weight-bold shadow-sm rounded-pill px-3 mr-2" onclick="window.print()">
            <i class="fas fa-print mr-1"></i>Print Statement
        </button>
        <button type="button" class="btn text-white font-weight-bold shadow-sm rounded-pill px-4" 
                style="background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);"
                onclick="sendStatementEmail(<?php echo $student_id; ?>, <?php echo $year; ?>)">
            <i class="fas fa-paper-plane mr-2"></i>Email Statement to Student
        </button>
    </div>
</div>

<div id="emailAlertPlaceholder" class="no-print"></div>

<!-- Student Header Card -->
<div class="card shadow-sm border-0 rounded-lg mb-4">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-7 mb-3 mb-md-0">
                <div class="d-flex align-items-center">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center font-weight-bold mr-3 shadow-sm" style="width: 54px; height: 54px; font-size: 22px;">
                        <?php echo strtoupper(substr($student['full_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <h4 class="font-weight-bold text-dark mb-1"><?php echo h($student['full_name']); ?></h4>
                        <div class="text-muted small">
                            <span class="badge badge-light border text-primary font-weight-bold mr-2">Class <?php echo h($student['class_name']); ?></span>
                            <span class="badge badge-light border text-dark font-weight-bold mr-2"><?php echo h($student['board_name']); ?></span>
                            <span class="mr-2"><i class="fas fa-user-circle mr-1"></i>ID: <code><?php echo h($student['username']); ?></code></span>
                            <span class="mr-2"><i class="fas fa-calendar-alt text-info mr-1"></i>Joined: <strong><?php echo date('d M Y', strtotime($ledgerData['enrollment_date'])); ?></strong></span>
                            <span><i class="fas fa-envelope mr-1"></i><?php echo !empty($student['email']) ? h($student['email']) : '<em class="text-danger">No email registered</em>'; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-5 text-md-right no-print">
                <form method="GET" class="form-inline justify-content-md-end">
                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                    <label class="font-weight-bold small text-muted text-uppercase mr-2">Year:</label>
                    <select name="year" class="form-control custom-select bg-white font-weight-bold shadow-sm mr-2" onchange="this.form.submit()">
                        <?php for($y = date('Y') + 1; $y >= 2024; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Financial Summary Strip -->
<div class="row mb-4">
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-success bg-opacity-10 border-left border-success" style="border-width: 4px !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-success small font-weight-bold text-uppercase">Total Fees Paid</span>
                    <h3 class="font-weight-bold text-success mb-0"><?php echo h($currency) . number_format($ledgerData['total_paid'], 2); ?></h3>
                    <span class="small text-muted"><?php echo $ledgerData['paid_months_count']; ?> Month(s) Verified</span>
                </div>
                <div class="bg-success text-white rounded-circle p-3">
                    <i class="fas fa-check-circle fa-2x"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-danger bg-opacity-10 border-left border-danger" style="border-width: 4px !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-danger small font-weight-bold text-uppercase">Outstanding Due</span>
                    <h3 class="font-weight-bold text-danger mb-0"><?php echo h($currency) . number_format($ledgerData['total_due'], 2); ?></h3>
                    <span class="small text-muted"><?php echo $ledgerData['unpaid_months_count']; ?> Month(s) Pending</span>
                </div>
                <div class="bg-danger text-white rounded-circle p-3">
                    <i class="fas fa-exclamation-triangle fa-2x"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm rounded-lg bg-light border-left border-primary" style="border-width: 4px !important;">
            <div class="card-body p-3 d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-primary small font-weight-bold text-uppercase">Standard Monthly Rate</span>
                    <h3 class="font-weight-bold text-dark mb-0"><?php echo h($currency) . number_format($ledgerData['monthly_rate'], 2); ?></h3>
                    <span class="small text-muted">Per Curriculum Class <?php echo h($student['class_name']); ?></span>
                </div>
                <div class="bg-primary text-white rounded-circle p-3">
                    <i class="fas fa-calendar-alt fa-2x"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ledger Table -->
<div class="card shadow-sm border-0 rounded-lg mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-file-invoice-dollar text-primary mr-2"></i>Monthly Breakdown (Year <?php echo $year; ?>)
        </h5>
        <span class="badge badge-light border text-muted px-3 py-1 font-weight-bold">
            <?php echo $ledgerData['paid_months_count']; ?> / 12 Months Paid
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 15%;">Month</th>
                    <th>Due Amount</th>
                    <th>Paid Amount</th>
                    <th>Status</th>
                    <th>Payment Date</th>
                    <th>Method</th>
                    <th>Receipt #</th>
                    <th class="no-print text-right" style="width: 15%;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($ledgerData['ledger'] as $m): ?>
                    <?php 
                        $isPaid = ($m['status'] === 'paid');
                        $isPartial = ($m['status'] === 'partial');
                        $rowClass = $m['is_current_month'] ? 'table-warning' : '';
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td class="font-weight-bold text-dark align-middle">
                            <?php echo h($m['month_name']); ?> <?php echo $year; ?>
                            <?php if ($m['is_current_month']): ?>
                                <span class="badge badge-warning text-dark ml-1 font-weight-bold">CURRENT</span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle font-weight-bold text-muted">
                            <?php echo h($currency) . number_format($m['amount_due'], 2); ?>
                        </td>
                        <td class="align-middle font-weight-bold <?php echo $isPaid ? 'text-success' : ($isPartial ? 'text-warning' : 'text-danger'); ?>">
                            <?php echo h($currency) . number_format($m['amount_paid'], 2); ?>
                        </td>
                        <td class="align-middle">
                            <?php if ($isPaid): ?>
                                <span class="badge badge-success px-3 py-1 font-weight-bold rounded-pill">
                                    <i class="fas fa-check mr-1"></i>PAID
                                </span>
                            <?php elseif ($isPartial): ?>
                                <span class="badge badge-warning text-dark px-3 py-1 font-weight-bold rounded-pill">
                                    <i class="fas fa-adjust mr-1"></i>PARTIAL
                                </span>
                            <?php elseif ($m['status'] === 'not_enrolled'): ?>
                                <span class="badge badge-light border text-muted px-3 py-1 font-weight-bold rounded-pill" title="Student not yet enrolled in this month">
                                    <i class="fas fa-minus-circle mr-1"></i>NOT ENROLLED
                                </span>
                            <?php elseif ($m['is_future']): ?>
                                <span class="badge badge-light border text-muted px-3 py-1 font-weight-bold rounded-pill">
                                    UPCOMING
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger px-3 py-1 font-weight-bold rounded-pill">
                                    <i class="fas fa-times mr-1"></i>UNPAID
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="align-middle text-muted small">
                            <?php echo $m['payment_date'] ? date('d M Y', strtotime($m['payment_date'])) : '—'; ?>
                        </td>
                        <td class="align-middle text-muted small">
                            <?php echo $m['payment_method'] ? h($m['payment_method']) : '—'; ?>
                        </td>
                        <td class="align-middle font-family-monospace small text-primary font-weight-bold">
                            <?php echo $m['receipt_number'] ? h($m['receipt_number']) : '—'; ?>
                        </td>
                        <td class="no-print align-middle text-right">
                            <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3"
                                    onclick="openQuickCollectModal(<?php echo $student_id; ?>, '<?php echo addslashes($student['full_name']); ?>', <?php echo $m['month_number']; ?>, <?php echo $year; ?>, <?php echo $m['amount_due']; ?>, <?php echo $m['amount_paid']; ?>, '<?php echo addslashes($m['payment_method'] ?? 'Cash'); ?>', '<?php echo addslashes($m['receipt_number'] ?? ''); ?>')">
                                <i class="fas <?php echo $isPaid ? 'fa-edit' : 'fa-plus'; ?> mr-1"></i><?php echo $isPaid ? 'Edit' : 'Collect'; ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for collecting/editing fee -->
<div class="modal fade" id="collectModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold" id="collectModalTitle">
                    <i class="fas fa-hand-holding-usd mr-2"></i>Record Fee Payment
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="collectForm">
                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                <input type="hidden" name="student_id" id="modalStudentId">
                <input type="hidden" name="fee_year" id="modalFeeYear">
                <input type="hidden" name="fee_month" id="modalFeeMonth">

                <div class="modal-body p-4">
                    <div class="alert alert-info py-2 small mb-3">
                        <strong id="modalStudentName"></strong> • <span id="modalMonthName"></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted text-uppercase">Monthly Fee Due</label>
                            <input type="text" id="modalAmountDue" class="form-control bg-light font-weight-bold" readonly>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted text-uppercase">Amount Paid (<?php echo h($currency); ?>)</label>
                            <input type="number" step="0.01" min="0" name="amount_paid" id="modalAmountPaid" class="form-control font-weight-bold text-success" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted text-uppercase">Payment Method</label>
                            <select name="payment_method" id="modalPayMethod" class="form-control custom-select font-weight-bold">
                                <option value="Cash">Cash</option>
                                <option value="UPI / Online">UPI / GPay / PhonePe</option>
                                <option value="Bank Transfer">Bank Transfer (NEFT/IMPS)</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted text-uppercase">Transaction / UPI Ref #</label>
                            <input type="text" name="transaction_ref" id="modalTxnRef" class="form-control" placeholder="Optional reference">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold small text-muted text-uppercase">Notes / Comments</label>
                        <input type="text" name="notes" id="modalNotes" class="form-control" placeholder="Optional remark">
                    </div>

                    <div class="custom-control custom-checkbox mt-3">
                        <input type="checkbox" class="custom-control-input" id="modalSendEmail" name="send_email" value="1" checked>
                        <label class="custom-control-label font-weight-bold text-dark small" for="modalSendEmail">
                            <i class="fas fa-envelope text-primary mr-1"></i>Send updated statement email to student automatically
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top py-3 px-4">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" id="btnSubmitPayment" class="btn btn-success font-weight-bold rounded-pill px-5 shadow-sm">
                        <i class="fas fa-save mr-2"></i>Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const monthsList = {
    1: 'January', 2: 'February', 3: 'March', 4: 'April', 5: 'May', 6: 'June',
    7: 'July', 8: 'August', 9: 'September', 10: 'October', 11: 'November', 12: 'December'
};

function openQuickCollectModal(studentId, studentName, month, year, due, paid, method, receipt) {
    $('#modalStudentId').val(studentId);
    $('#modalStudentName').text(studentName);
    $('#modalFeeMonth').val(month);
    $('#modalFeeYear').val(year);
    $('#modalMonthName').text(monthsList[month] + ' ' + year);
    $('#modalAmountDue').val('<?php echo h($currency); ?>' + parseFloat(due).toFixed(2));
    $('#modalAmountPaid').val(paid > 0 ? parseFloat(paid).toFixed(2) : parseFloat(due).toFixed(2));
    $('#modalPayMethod').val(method || 'Cash');
    $('#modalTxnRef').val('');
    $('#modalNotes').val('');
    $('#collectModal').modal('show');
}

$('#collectForm').on('submit', function(e) {
    e.preventDefault();
    let btn = $('#btnSubmitPayment');
    let origText = btn.html();
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...');

    $.ajax({
        url: 'collect_ajax.php',
        method: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html(origText);
            if (res.success) {
                $('#collectModal').modal('hide');
                location.reload();
            } else {
                alert(res.error || 'Failed to record payment.');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html(origText);
            alert('Server error occurred while recording payment.');
        }
    });
});

function sendStatementEmail(studentId, year) {
    if (!confirm('Send detailed fee statement email to this student?')) return;

    let alertPlaceholder = $('#emailAlertPlaceholder');
    alertPlaceholder.html('<div class="alert alert-info py-2"><i class="fas fa-spinner fa-spin mr-2"></i>Dispatching statement email to student...</div>');

    $.ajax({
        url: 'notify_ajax.php',
        method: 'POST',
        data: {
            csrf_token: '<?php echo generate_csrf_token(); ?>',
            action: 'send_student_statement',
            student_id: studentId,
            year: year
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                alertPlaceholder.html('<div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle mr-2"></i>' + res.message + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            } else {
                alertPlaceholder.html('<div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-circle mr-2"></i>' + res.error + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            }
        },
        error: function() {
            alertPlaceholder.html('<div class="alert alert-danger py-2"><i class="fas fa-times mr-2"></i>Network or server communication error.</div>');
        }
    });
}
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

<?php
// admin/admins/billing.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

// Strictly enforce Super Admin authorization
require_super_admin();

$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$selectedYear  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

if ($selectedMonth < 1 || $selectedMonth > 12) $selectedMonth = (int)date('n');
if ($selectedYear < 2024 || $selectedYear > 2035) $selectedYear = (int)date('Y');

$overview = get_all_admins_billing_overview($selectedMonth, $selectedYear, $pdo);
$settings = $overview['settings'];
$csrf_token = generate_csrf_token();

$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$selectedMonthName = $monthNames[$selectedMonth];
?>

<style>
.billing-card {
    border-radius: 14px;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.billing-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}
.status-pill-paid {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
    padding: 0.35rem 0.75rem;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 0.82rem;
    display: inline-flex;
    align-items: center;
}
.status-pill-pending {
    background: #fef9c3;
    color: #854d0e;
    border: 1px solid #fef08a;
    padding: 0.35rem 0.75rem;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 0.82rem;
    display: inline-flex;
    align-items: center;
}
.status-pill-overdue {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
    padding: 0.35rem 0.75rem;
    border-radius: 9999px;
    font-weight: 700;
    font-size: 0.82rem;
    display: inline-flex;
    align-items: center;
}
.enrolment-pill {
    background: #eff6ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
    border-radius: 9999px;
    padding: 0.3rem 0.75rem;
    font-size: 0.82rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
}
</style>

<div id="pageNotificationArea"></div>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-file-invoice-dollar text-primary mr-2"></i>Tenant Administrator Billing & Payments
        </h2>
        <p class="text-muted mb-0 small">
            Super Administrator console to track tenant subscriptions, monitor enrolled student capacity, record payments, and dispatch delayed payment reminders.
        </p>
    </div>
    <div class="mt-3 mt-md-0 d-flex align-items-center">
        <!-- Month & Year Selector Form -->
        <form method="GET" class="form-inline mr-2">
            <select name="month" class="form-control form-control-sm mr-2 font-weight-bold" onchange="this.form.submit()">
                <?php foreach ($monthNames as $mNum => $mName): ?>
                    <option value="<?= $mNum ?>" <?= ($mNum === $selectedMonth) ? 'selected' : '' ?>><?= $mName ?></option>
                <?php endforeach; ?>
            </select>
            <select name="year" class="form-control form-control-sm mr-2 font-weight-bold" onchange="this.form.submit()">
                <?php for ($y = 2024; $y <= 2030; $y++): ?>
                    <option value="<?= $y ?>" <?= ($y === $selectedYear) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>

        <?php if ($overview['overdue_count'] > 0 || $overview['pending_count'] > 0): ?>
            <button type="button" class="btn btn-danger btn-sm font-weight-bold shadow-sm rounded-pill px-3" onclick="batchSendReminders()">
                <i class="fas fa-paper-plane mr-1"></i>Remind All Overdue
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Metrics Row -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card billing-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Total Billed (<?= $selectedMonthName ?>)</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= $overview['currency'] ?><?= number_format($overview['total_billed'], 2) ?></h3>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-file-invoice fa-lg text-primary"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card billing-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Payments Collected</span>
                    <h3 class="font-weight-bold text-success mb-0 mt-1"><?= $overview['currency'] ?><?= number_format($overview['total_collected'], 2) ?></h3>
                    <small class="text-muted"><?= $overview['paid_count'] ?> of <?= $overview['total_admins'] ?> Admins Paid</small>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-check-circle fa-lg text-success"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card billing-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Delayed / Overdue</span>
                    <h3 class="font-weight-bold text-danger mb-0 mt-1"><?= $overview['currency'] ?><?= number_format($overview['total_overdue'] + $overview['total_pending'], 2) ?></h3>
                    <small class="text-danger font-weight-bold"><?= $overview['overdue_count'] ?> Overdue Accounts</small>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-exclamation-triangle fa-lg text-danger"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card billing-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.72rem;">Enrolled Students</span>
                    <h3 class="font-weight-bold text-info mb-0 mt-1"><?= $overview['total_enrolled_students'] ?></h3>
                    <small class="text-muted">Managed across all institutes</small>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-user-graduate fa-lg text-info"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Overdue Warning Alert Banner if any overdue accounts exist -->
<?php if ($overview['overdue_count'] > 0): ?>
    <div class="alert alert-danger shadow-sm border-0 d-flex justify-content-between align-items-center mb-4 p-3 rounded-lg">
        <div>
            <h6 class="font-weight-bold mb-1"><i class="fas fa-bell mr-2"></i>Attention: <?= $overview['overdue_count'] ?> Administrator Account(s) have Delayed Payments!</h6>
            <p class="small mb-0 opacity-90">These institutes have surpassed their billing due date (<?= $settings['admin_payment_due_day'] ?>th of <?= $selectedMonthName ?>). Dispatch payment reminders to notify them directly via email.</p>
        </div>
        <button type="button" class="btn btn-danger font-weight-bold shadow-sm rounded-pill px-4" onclick="batchSendReminders()">
            <i class="fas fa-paper-plane mr-2"></i>Send Delay Notices
        </button>
    </div>
<?php endif; ?>

<!-- Administrators Billing Table -->
<div class="card shadow-sm border-0 rounded-lg overflow-hidden mb-5">
    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold mb-0 text-dark">
            <i class="fas fa-list-alt mr-2 text-primary"></i>Institute Subscription Status for <?= $selectedMonthName ?> <?= $selectedYear ?>
        </h5>
        <span class="badge badge-light border px-3 py-2 rounded-pill font-weight-bold text-muted">
            <?= count($overview['admins']) ?> Tenant Admins Active
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="billingTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="py-3 px-4">Administrator & Institute</th>
                        <th class="py-3 text-center">Enrolled Students</th>
                        <th class="py-3">Subscription Fee</th>
                        <th class="py-3">Due Date & Delay</th>
                        <th class="py-3 text-center">Billing Status</th>
                        <th class="py-3 text-right px-4" style="min-width: 260px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($overview['admins'])): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-users-cog fa-2x mb-3 text-black-50 d-block"></i>
                                No tenant administrator accounts configured yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($overview['admins'] as $item): 
                            $b = $item['billing'];
                            $isPaid = ($b['status'] === 'paid');
                            $isOverdue = ($b['status'] === 'overdue');
                            $isPending = ($b['status'] === 'pending');
                        ?>
                        <tr id="billing-row-<?= $item['id'] ?>">
                            <td class="py-3 px-4">
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center font-weight-bold shadow-sm mr-3" style="width: 40px; height: 40px;">
                                        <?= strtoupper(substr($item['username'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold text-dark"><?= h($item['username']) ?></div>
                                        <div class="text-muted small">
                                            <i class="fas fa-university text-muted mr-1"></i><?= h($item['institute_name']) ?>
                                        </div>
                                        <small class="text-black-50"><?= h($item['email']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td class="py-3 text-center">
                                <span class="enrolment-pill mb-1">
                                    <i class="fas fa-user-graduate mr-1"></i><?= (int)$b['enrolled_students'] ?> Students
                                </span>
                                <?php if (!empty($b['exam_enrolments'])): ?>
                                    <div class="small text-muted"><?= (int)$b['exam_enrolments'] ?> exam enrolments</div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3">
                                <div class="font-weight-bold text-dark" style="font-size: 1.05rem;">
                                    <?= $overview['currency'] ?><?= number_format($b['amount_due'], 2) ?>
                                </div>
                                <small class="text-muted"><?= h($b['plan_name']) ?></small>
                                <?php if ($isPaid): ?>
                                    <div class="small text-success font-weight-bold">
                                        Paid: <?= $overview['currency'] ?><?= number_format($b['amount_paid'], 2) ?> (<?= h($b['payment_method']) ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3">
                                <div><strong><?= date('d M Y', strtotime($b['due_date'])) ?></strong></div>
                                <?php if ($isOverdue && $b['delay_days'] > 0): ?>
                                    <span class="badge badge-danger font-weight-bold px-2 py-1">
                                        <i class="fas fa-clock mr-1"></i>Delayed by <?= $b['delay_days'] ?> day(s)
                                    </span>
                                <?php elseif ($isPaid): ?>
                                    <span class="small text-muted">
                                        Settled on <?= date('d M Y', strtotime($b['payment_date'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="small text-muted">Payment window open</span>
                                <?php endif; ?>
                                <?php if (!empty($b['reminder_sent_at'])): ?>
                                    <div class="small text-info mt-1" title="Reminded <?= $b['reminder_count'] ?> time(s)">
                                        <i class="fas fa-paper-plane mr-1"></i>Reminded <?= date('d M', strtotime($b['reminder_sent_at'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-center" id="status-cell-<?= $item['id'] ?>">
                                <?php if ($isPaid): ?>
                                    <span class="status-pill-paid">
                                        <i class="fas fa-check-circle mr-1"></i> Paid
                                    </span>
                                <?php elseif ($isOverdue): ?>
                                    <span class="status-pill-overdue">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Overdue
                                    </span>
                                <?php else: ?>
                                    <span class="status-pill-pending">
                                        <i class="fas fa-hourglass-half mr-1"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-right px-4">
                                <div class="d-flex justify-content-end align-items-center flex-wrap" style="gap: 6px;">
                                    <?php if (!$isPaid): ?>
                                        <button type="button" class="btn btn-sm btn-success rounded-pill px-3 font-weight-bold shadow-xs"
                                                onclick='openRecordPaymentModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, "UTF-8") ?>)'>
                                            <i class="fas fa-receipt mr-1"></i> Record Payment
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-3 font-weight-bold shadow-xs"
                                                onclick="sendReminder(<?= $item['id'] ?>, <?= htmlspecialchars(json_encode($item['username']), ENT_QUOTES, 'UTF-8') ?>, <?= (int)$b['enrolled_students'] ?>, <?= (float)$b['amount_due'] ?>, <?= (int)$b['delay_days'] ?>)">
                                            <i class="fas fa-paper-plane mr-1"></i> Remind
                                        </button>
                                    <?php else: ?>
                                        <a href="payment_receipt.php?id=<?= $b['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 font-weight-bold shadow-xs">
                                            <i class="fas fa-file-invoice mr-1"></i> Receipt
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3 font-weight-bold shadow-xs"
                                                onclick='openRecordPaymentModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, "UTF-8") ?>)'>
                                            <i class="fas fa-edit mr-1"></i> Edit
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODAL: RECORD PAYMENT ================= -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <form id="recordPaymentForm" onsubmit="submitRecordPayment(event)">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="admin_id" id="payAdminId">
                <input type="hidden" name="billing_month" value="<?= $selectedMonth ?>">
                <input type="hidden" name="billing_year" value="<?= $selectedYear ?>">

                <div class="modal-header bg-success text-white py-3 px-4">
                    <h5 class="modal-title font-weight-bold">
                        <i class="fas fa-receipt mr-2"></i>Record Tenant Subscription Payment
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                
                <div class="modal-body p-4">
                    <div id="recordPayAlert"></div>

                    <!-- Admin Summary Banner -->
                    <div class="alert alert-light border p-3 rounded-lg mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small text-uppercase font-weight-bold">Institute</span>
                                <h6 class="font-weight-bold text-dark mb-0" id="payInstituteName">-</h6>
                                <small class="text-muted">Admin: <span id="payAdminUsername" class="font-weight-bold">-</span></small>
                            </div>
                            <div class="text-right">
                                <span class="badge badge-info px-3 py-2 rounded-pill font-weight-bold" id="payEnrolledBadge">0 Students</span>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Billing Period</label>
                            <input type="text" class="form-control bg-light font-weight-bold" value="<?= $selectedMonthName ?> <?= $selectedYear ?>" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Amount Received (<?= $overview['currency'] ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount_paid" id="payAmountInput" class="form-control font-weight-bold text-success" required style="font-size: 1.1rem;">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" class="form-control" required>
                                <option value="UPI" selected>UPI (GPay / PhonePe / Paytm)</option>
                                <option value="Bank Transfer">Bank Transfer (NEFT / IMPS / RTGS)</option>
                                <option value="Cash">Cash Receipt</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Net Banking">Net Banking</option>
                                <option value="Card">Credit / Debit Card</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Transaction Ref / UTR</label>
                            <input type="text" name="transaction_ref" id="payTxnRef" class="form-control" placeholder="e.g. UTR-49102838192">
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark small text-uppercase">Notes & Memo (Optional)</label>
                        <textarea name="notes" id="payNotes" class="form-control" rows="2" placeholder="e.g. Monthly maintenance settled in full."></textarea>
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold rounded-pill px-4" id="recordPaySubmitBtn">
                        <i class="fas fa-check mr-2"></i>Save & Generate Receipt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showNotification(message, type = 'success') {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    $('#pageNotificationArea').html(alertHtml);
    $('html, body').animate({ scrollTop: 0 }, 200);
}

function openRecordPaymentModal(item) {
    const b = item.billing;
    $('#payAdminId').val(item.id);
    $('#payInstituteName').text(item.institute_name || 'Main Campus');
    $('#payAdminUsername').text(item.username);
    $('#payEnrolledBadge').text((b.enrolled_students || 0) + ' Students Enrolled');
    $('#payAmountInput').val(b.amount_paid > 0 ? b.amount_paid : b.amount_due);
    $('#payTxnRef').val(b.transaction_ref || '');
    $('#payNotes').val(b.notes || '');
    $('#recordPayAlert').html('');
    $('#recordPaymentModal').modal('show');
}

function submitRecordPayment(e) {
    e.preventDefault();
    const btn = $('#recordPaySubmitBtn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Recording...');

    $.ajax({
        url: 'record_payment_ajax.php',
        type: 'POST',
        data: $('#recordPaymentForm').serialize(),
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-check mr-2"></i>Save & Generate Receipt');
            if (res.success) {
                $('#recordPaymentModal').modal('hide');
                showNotification(res.message, 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            } else {
                $('#recordPayAlert').html('<div class="alert alert-danger">' + (res.error || 'Failed to record payment.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-check mr-2"></i>Save & Generate Receipt');
            let err = 'Server error occurred.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#recordPayAlert').html('<div class="alert alert-danger">' + err + '</div>');
        }
    });
}

function sendReminder(adminId, username, enrolledStudents, amountDue, delayDays) {
    const delayNotice = delayDays > 0 ? ` (Overdue by ${delayDays} days)` : '';
    const confirmMsg = `Send an official payment reminder email to administrator '${username}'?\n\n- Enrolled Students: ${enrolledStudents}\n- Amount Due: ₹${amountDue.toFixed(2)}${delayNotice}\n\nThe administrator will receive an email notice with UPI and bank details.`;
    
    if (!confirm(confirmMsg)) {
        return;
    }

    $.ajax({
        url: 'send_reminder_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            billing_month: <?= $selectedMonth ?>,
            billing_year: <?= $selectedYear ?>,
            csrf_token: '<?= h($csrf_token) ?>'
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showNotification(res.message, 'success');
            } else {
                alert(res.error || 'Failed to dispatch payment reminder.');
            }
        },
        error: function(xhr) {
            let err = 'Server error occurred.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            alert(err);
        }
    });
}

function batchSendReminders() {
    if (!confirm("Are you sure you want to send delayed payment reminder emails to ALL overdue/pending tenant administrators for <?= $selectedMonthName ?> <?= $selectedYear ?>?")) {
        return;
    }

    $.ajax({
        url: 'send_reminder_ajax.php',
        type: 'POST',
        data: {
            batch_all: 1,
            billing_month: <?= $selectedMonth ?>,
            billing_year: <?= $selectedYear ?>,
            csrf_token: '<?= h($csrf_token) ?>'
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                showNotification(res.message, 'success');
            } else {
                alert(res.error || 'Failed to send batch reminders.');
            }
        },
        error: function() {
            alert('Server error occurred during batch dispatch.');
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

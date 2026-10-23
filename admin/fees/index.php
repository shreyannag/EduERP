<?php
// admin/fees/index.php
// Central Student Fees Management, Monthly Collection Ledger, and Automated Reminders Hub.

require_once '../../includes/admin_header.php';
require_once '../../includes/fee_manager.php';

require_admin_feature('fees');

$year  = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));
$filterClass  = $_GET['class_id'] ?? '';
$filterBoard  = $_GET['board_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterAdmin  = $_GET['admin_id'] ?? '';
$search       = trim($_GET['search'] ?? '');

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);
$boards  = $pdo->query("SELECT id, name FROM boards ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$all_admins = is_super_admin() ? $pdo->query("SELECT id, username, institute_name FROM admins ORDER BY institute_name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

$scoped_admin_id = is_super_admin() ? (!empty($filterAdmin) ? (int)$filterAdmin : null) : get_effective_admin_id();

$summary = get_all_students_fee_summary($year, $month, $pdo, $scoped_admin_id);
$currency = $summary['currency'];
$monthsList = get_fee_months_list();

// Check calendar position for automated reminders
$todayDay = (int)date('j');
$daysInMonth = (int)date('t');
$isMidMonthWindow = ($todayDay >= 13 && $todayDay <= 17);
$isEndMonthWindow = ($todayDay >= ($daysInMonth - 3));

// Filter student records based on search and filters
$filteredStudents = array_filter($summary['students'], function($s) use ($filterClass, $filterBoard, $filterStatus, $search) {
    if ($filterClass !== '' && (int)$s['class_id'] !== (int)$filterClass) return false;
    if ($filterBoard !== '' && $s['board_name'] !== $filterBoard) return false;
    if ($filterStatus === 'paid' && !$s['is_paid_current']) return false;
    if ($filterStatus === 'unpaid' && $s['is_paid_current']) return false;
    if ($filterStatus === 'defaulters' && !$s['has_year_dues']) return false;
    if ($search !== '') {
        $q = mb_strtolower($search);
        $nameMatch = mb_stripos($s['full_name'], $q) !== false;
        $userMatch = mb_stripos($s['username'], $q) !== false;
        $emailMatch = mb_stripos($s['email'] ?? '', $q) !== false;
        if (!$nameMatch && !$userMatch && !$emailMatch) return false;
    }
    return true;
});
?>

<style>
.month-pill {
    display: inline-block;
    width: 20px;
    height: 20px;
    line-height: 20px;
    text-align: center;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 800;
    margin: 1px;
    cursor: pointer;
    text-decoration: none !important;
    transition: transform 0.1s;
}
.month-pill:hover { transform: scale(1.2); }
.pill-paid { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.pill-unpaid { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.pill-partial { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
.pill-not-enrolled { background: #f8fafc; color: #94a3b8; border: 1px dashed #cbd5e1; }
.pill-future { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Student Fees Management</h2>
        <span class="text-muted small">Track monthly fee payments, notify students of paid & unpaid months, and dispatch admin collection digests.</span>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap">
        <?php if (can_view_fee_totals()): ?>
        <button type="button" class="btn btn-outline-danger font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2" onclick="sendBulkDueReminders()">
            <i class="fas fa-bell mr-1"></i>Remind All Due Students
        </button>
        <button type="button" class="btn btn-outline-primary font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2" onclick="openAdminDigestModal()">
            <i class="fas fa-paper-plane mr-1"></i>Email Admin Report
        </button>
        <a href="settings.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2">
            <i class="fas fa-cog mr-1"></i>Fee Settings
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-success font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2" onclick="openListCustomProfilesModal()"><i class="fas fa-user-tag mr-1"></i>Custom Fee Profiles</button>
        <button type="button" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4 mb-2" onclick="openGeneralCollectModal()">
            <i class="fas fa-plus-circle mr-1"></i>Collect Fee
        </button>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-lg p-3 mb-4" role="alert">
        <i class="fas fa-check-circle fa-lg mr-2 text-success"></i>
        <strong>Success:</strong> <?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_warning'])): ?>
    <div class="alert alert-warning alert-dismissible fade show shadow-sm rounded-lg p-3 mb-4" role="alert">
        <i class="fas fa-exclamation-triangle fa-lg mr-2 text-warning"></i>
        <strong>Notice:</strong> <?php echo htmlspecialchars($_SESSION['flash_warning']); unset($_SESSION['flash_warning']); ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>

<div id="ajaxAlertContainer"></div>

<!-- Mid-Month / End-of-Month Smart Notification Banner -->
<?php if (can_view_fee_totals() && ($isMidMonthWindow || $isEndMonthWindow)): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center justify-content-between border-left border-warning" style="border-width: 5px !important;">
        <div class="d-flex align-items-center">
            <div class="bg-warning text-dark rounded-circle p-2 mr-3 font-weight-bold">
                <i class="fas fa-calendar-alt fa-lg"></i>
            </div>
            <div>
                <strong class="text-dark">
                    <?php if ($isMidMonthWindow): ?>
                        Mid-Month Fee Collection Window (Day <?php echo $todayDay; ?> of <?php echo $summary['month_name']; ?>)
                    <?php else: ?>
                        Month-End Fee Reconciliation Window (Closing <?php echo $summary['month_name']; ?>)
                    <?php endif; ?>
                </strong>
                <div class="small text-muted">
                    <?php echo $summary['unpaid_count_month']; ?> student(s) still have dues pending for <?php echo $summary['month_name']; ?>. Send an updated status digest to the admin user.
                </div>
            </div>
        </div>
        <div>
            <button type="button" class="btn btn-sm btn-dark font-weight-bold rounded-pill px-3 shadow-sm" 
                    onclick="triggerAdminDigest('<?php echo $isMidMonthWindow ? 'mid_month' : 'end_month'; ?>')">
                <i class="fas fa-envelope mr-1"></i>Send <?php echo $isMidMonthWindow ? 'Mid-Month' : 'Month-End'; ?> Digest Now
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- 4 Key Performance Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-hand-holding-usd fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Collected (<?php echo $summary['month_name']; ?>)</div>
                    <?php if (can_view_fee_totals()): ?>
                        <h4 class="font-weight-bold text-success mb-0"><?php echo h($currency) . number_format($summary['total_collected_month'], 0); ?></h4>
                        <span class="small text-muted"><?php echo $summary['paid_count_month']; ?> Student(s) Paid</span>
                    <?php else: ?>
                        <h5 class="font-weight-bold text-muted mb-0"><i class="fas fa-lock text-warning mr-1"></i> Restricted</h5>
                        <span class="small text-muted">Admin / Super Admin Only</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-danger bg-opacity-10 text-danger">
                    <i class="fas fa-exclamation-circle fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Pending (<?php echo $summary['month_name']; ?>)</div>
                    <?php if (can_view_fee_totals()): ?>
                        <h4 class="font-weight-bold text-danger mb-0"><?php echo h($currency) . number_format($summary['total_due_month'], 0); ?></h4>
                        <span class="small text-danger font-weight-bold"><?php echo $summary['unpaid_count_month']; ?> Student(s) Due</span>
                    <?php else: ?>
                        <h5 class="font-weight-bold text-muted mb-0"><i class="fas fa-lock text-warning mr-1"></i> Restricted</h5>
                        <span class="small text-muted">Admin / Super Admin Only</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-percentage fa-2x"></i>
                </div>
                <div class="w-100">
                    <div class="text-muted small text-uppercase font-weight-bold">Collection Rate</div>
                    <?php if (can_view_fee_totals()): ?>
                        <h4 class="font-weight-bold text-primary mb-1"><?php echo $summary['collection_rate_month']; ?>%</h4>
                        <div class="progress" style="height: 6px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo min(100, $summary['collection_rate_month']); ?>%"></div>
                        </div>
                    <?php else: ?>
                        <h5 class="font-weight-bold text-muted mb-0"><i class="fas fa-lock text-warning mr-1"></i> Restricted</h5>
                        <span class="small text-muted">Admin / Super Admin Only</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-dark bg-opacity-10 text-dark">
                    <i class="fas fa-user-graduate fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Active Students</div>
                    <h4 class="font-weight-bold text-dark mb-0"><?php echo $summary['total_students']; ?> Total</h4>
                    <span class="small text-muted"><?php echo count($summary['defaulters']); ?> with Year Dues</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card shadow-sm border-0 mb-4 bg-light rounded-lg">
    <div class="card-body p-4">
        <form method="GET" class="form-row align-items-end">
            <?php if (is_super_admin()): ?>
            <div class="col-md-2 col-6 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Institute</label>
                <select name="admin_id" class="form-control custom-select bg-white border-0 font-weight-bold shadow-sm">
                    <option value="">All Institutes</option>
                    <?php foreach($all_admins as $adm): ?>
                        <option value="<?php echo $adm['id']; ?>" <?php echo $filterAdmin == $adm['id'] ? 'selected' : ''; ?>>
                            <?php echo h(!empty($adm['institute_name']) ? $adm['institute_name'] : $adm['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="<?= is_super_admin() ? 'col-md-2' : 'col-md-2' ?> col-6 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Month</label>
                <select name="month" class="form-control custom-select bg-white border-0 font-weight-bold shadow-sm">
                    <?php foreach($monthsList as $mNum => $mName): ?>
                        <option value="<?php echo $mNum; ?>" <?php echo $mNum == $month ? 'selected' : ''; ?>><?php echo $mName; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="<?= is_super_admin() ? 'col-md-1' : 'col-md-2' ?> col-6 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Year</label>
                <select name="year" class="form-control custom-select bg-white border-0 font-weight-bold shadow-sm">
                    <?php for($y = date('Y') + 1; $y >= 2024; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="<?= is_super_admin() ? 'col-md-2' : 'col-md-2' ?> col-6 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Class</label>
                <select name="class_id" class="form-control custom-select bg-white border-0 font-weight-bold shadow-sm">
                    <option value="">All Classes</option>
                    <?php foreach($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $filterClass == $c['id'] ? 'selected' : ''; ?>>Class <?php echo h($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="<?= is_super_admin() ? 'col-md-2' : 'col-md-2' ?> col-6 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Status (<?php echo $monthsList[$month]; ?>)</label>
                <select name="status" class="form-control custom-select bg-white border-0 font-weight-bold shadow-sm">
                    <option value="">All Statuses</option>
                    <option value="paid" <?php echo $filterStatus === 'paid' ? 'selected' : ''; ?>>Paid Only</option>
                    <option value="unpaid" <?php echo $filterStatus === 'unpaid' ? 'selected' : ''; ?>>Unpaid Only</option>
                    <option value="defaulters" <?php echo $filterStatus === 'defaulters' ? 'selected' : ''; ?>>Any Dues This Year</option>
                </select>
            </div>

            <div class="<?= is_super_admin() ? 'col-md-2' : 'col-md-3' ?> col-8 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Search Student</label>
                <input type="text" name="search" class="form-control bg-white border-0 shadow-sm" 
                       placeholder="Name, Roll ID, Email..." value="<?php echo h($search); ?>">
            </div>

            <div class="col-md-1 col-4">
                <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm rounded-pill">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Students Fees Table -->
<div class="card shadow-sm border-0 rounded-lg mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-users text-primary mr-2"></i>Students Fee Records (<?php echo count($filteredStudents); ?>)
        </h5>
        <div class="small text-muted">
            <span class="mr-3"><span class="month-pill pill-paid">✓</span> Paid</span>
            <span class="mr-3"><span class="month-pill pill-unpaid">!</span> Due</span>
            <span class="mr-3"><span class="month-pill pill-partial">~</span> Partial</span>
            <span><span class="month-pill pill-future">•</span> Upcoming</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th>Student Details</th>
                    <?php if (is_super_admin()): ?>
                        <th>Institute</th>
                    <?php endif; ?>
                    <th>Class / Board</th>
                    <th>Monthly Fee</th>
                    <th><?php echo $monthsList[$month]; ?> Status</th>
                    <th style="min-width: 250px;">Annual Ledger (Jan - Dec <?php echo $year; ?>)</th>
                    <th>Year Dues</th>
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($filteredStudents)): ?>
                    <tr>
                        <td colspan="<?= is_super_admin() ? 8 : 7 ?>" class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open fa-3x text-muted opacity-50 mb-3 d-block"></i>
                            No students match the selected filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                        $monthChars = [1=>'J', 2=>'F', 3=>'M', 4=>'A', 5=>'M', 6=>'J', 7=>'J', 8=>'A', 9=>'S', 10=>'O', 11=>'N', 12=>'D'];
                    ?>
                    <?php foreach($filteredStudents as $s): ?>
                        <?php 
                            $stuLedger = get_student_fee_ledger($s['student_id'], $year, $pdo);
                        ?>
                        <tr>
                            <td class="align-middle">
                                <div class="font-weight-bold text-dark"><?php echo h($s['full_name']); ?></div>
                                <div class="small text-muted font-family-monospace">
                                    <span class="mr-2"><i class="fas fa-id-badge text-muted mr-1"></i><?php echo h($s['username']); ?></span>
                                    <?php if (!empty($s['email'])): ?>
                                        <span><i class="fas fa-envelope text-muted mr-1"></i><?php echo h($s['email']); ?></span>
                                    <?php else: ?>
                                        <span class="text-danger small"><i class="fas fa-exclamation-circle mr-1"></i>No email</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if (is_super_admin()): ?>
                            <td class="align-middle">
                                <span class="badge badge-light border text-dark font-weight-bold">
                                    <i class="fas fa-university text-primary mr-1"></i><?php echo h($s['institute_name'] ?? 'Main Campus'); ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="align-middle">
                                <span class="badge badge-light border text-primary font-weight-bold mr-1">Class <?php echo h($s['class_name']); ?></span>
                                <span class="badge badge-light border text-muted"><?php echo h($s['board_name']); ?></span>
                            </td>
                            <td class="align-middle font-weight-bold text-dark">
                                <?php echo h($currency) . number_format($s['monthly_fee'], 2); ?>
                                <?php $cProf = get_student_custom_fee_profile($s['student_id'], $pdo); if ($cProf && !empty($cProf['is_active'])): ?><div><span class="badge badge-success px-2 py-1 font-weight-bold mt-1" style="font-size: 0.68rem;" title="<?= h($cProf['reason']) ?>"><i class="fas fa-tag mr-1"></i>Custom Profile</span></div><?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <?php if ($s['current_month_status'] === 'not_enrolled'): ?>
                                    <span class="badge badge-light border text-muted px-3 py-2 font-weight-bold rounded-pill" title="Enrolled on: <?php echo date('d M Y', strtotime($s['enrollment_date'])); ?>">
                                        <i class="fas fa-minus-circle mr-1"></i>NOT ENROLLED
                                    </span>
                                <?php elseif ($s['is_paid_current']): ?>
                                    <span class="badge badge-success px-3 py-2 font-weight-bold rounded-pill">
                                        <i class="fas fa-check mr-1"></i>PAID
                                    </span>
                                    <div class="small text-muted font-family-monospace mt-1" style="font-size: 11px;">
                                        <?php echo h($s['current_month_receipt'] ?? ''); ?>
                                    </div>
                                <?php elseif ($s['current_month_paid'] > 0): ?>
                                    <span class="badge badge-warning text-dark px-3 py-2 font-weight-bold rounded-pill">
                                        <i class="fas fa-adjust mr-1"></i>PARTIAL (<?php echo h($currency) . number_format($s['current_month_due'], 0); ?> Due)
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 py-2 font-weight-bold rounded-pill">
                                        <i class="fas fa-times mr-1"></i>UNPAID (<?php echo h($currency) . number_format($s['current_month_due'], 0); ?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <div class="d-flex align-items-center flex-wrap">
                                    <?php if ($stuLedger): ?>
                                        <?php foreach($stuLedger['ledger'] as $mItem): ?>
                                            <?php 
                                                $pillClass = 'pill-future';
                                                $pillTitle = "{$mItem['month_name']} {$year}: ";
                                                if ($mItem['status'] === 'paid') {
                                                    $pillClass = 'pill-paid';
                                                    $pillTitle .= 'PAID';
                                                } elseif ($mItem['status'] === 'partial') {
                                                    $pillClass = 'pill-partial';
                                                    $pillTitle .= 'PARTIAL';
                                                } elseif ($mItem['status'] === 'not_enrolled') {
                                                    $pillClass = 'pill-not-enrolled';
                                                    $pillTitle .= 'NOT ENROLLED (N/A)';
                                                } elseif ($mItem['is_past'] || $mItem['is_current_month']) {
                                                    $pillClass = 'pill-unpaid';
                                                    $pillTitle .= 'UNPAID';
                                                } else {
                                                    $pillTitle .= 'FUTURE';
                                                }
                                            ?>
                                            <a href="javascript:void(0)" class="month-pill <?php echo $pillClass; ?>" 
                                               title="<?php echo $pillTitle; ?>"
                                               onclick="openQuickCollectModal(<?php echo $s['student_id']; ?>, '<?php echo addslashes($s['full_name']); ?>', <?php echo $mItem['month_number']; ?>, <?php echo $year; ?>, <?php echo $mItem['amount_due']; ?>, <?php echo $mItem['amount_paid']; ?>, '<?php echo addslashes($mItem['payment_method'] ?? 'Cash'); ?>', '<?php echo addslashes($mItem['receipt_number'] ?? ''); ?>')">
                                                <?php echo $monthChars[$mItem['month_number']]; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="align-middle">
                                <?php if ($s['total_due_year'] > 0): ?>
                                    <span class="font-weight-bold text-danger"><?php echo h($currency) . number_format($s['total_due_year'], 0); ?></span>
                                    <div class="small text-danger"><?php echo count($s['unpaid_months_names']); ?> Month(s)</div>
                                <?php else: ?>
                                    <span class="text-success small font-weight-bold"><i class="fas fa-check-circle mr-1"></i>All Clear</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-right text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill px-2 mr-1"
                                        title="Collect/Record Fee"
                                        onclick="openQuickCollectModal(<?php echo $s['student_id']; ?>, '<?php echo addslashes($s['full_name']); ?>', <?php echo $month; ?>, <?php echo $year; ?>, <?php echo $s['monthly_fee']; ?>, <?php echo $s['current_month_paid']; ?>, 'Cash', '<?php echo addslashes($s['current_month_receipt'] ?? ''); ?>')">
                                    <i class="fas fa-hand-holding-usd mr-1"></i>Collect
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-dark font-weight-bold rounded-pill px-2 mr-1" title="Configure Custom Fee Profile (Concession / Scholarship)" onclick="openStudentCustomFeeModal(<?php echo $s['student_id']; ?>, '<?php echo addslashes($s['full_name']); ?>')"><i class="fas fa-user-tag"></i></button>
                                <a href="student_ledger.php?student_id=<?php echo $s['student_id']; ?>&year=<?php echo $year; ?>" 
                                   class="btn btn-sm btn-outline-info font-weight-bold rounded-pill px-2 mr-1" title="View Detailed Statement">
                                    <i class="fas fa-file-invoice"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-2"
                                        title="Email Fee Statement to Student"
                                        onclick="sendIndividualStatement(<?php echo $s['student_id']; ?>, '<?php echo addslashes($s['full_name']); ?>', <?php echo $year; ?>)">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Quick Collect Fee -->
<div class="modal fade" id="collectModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-primary text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold" id="collectModalTitle">
                    <i class="fas fa-hand-holding-usd mr-2"></i>Record Student Fee Payment
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
                            <input type="text" name="transaction_ref" id="modalTxnRef" class="form-control" placeholder="Optional transaction ID">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold small text-muted text-uppercase">Notes / Comments</label>
                        <input type="text" name="notes" id="modalNotes" class="form-control" placeholder="Optional remarks">
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

<!-- Modal: Email Admin Summary -->
<div class="modal fade" id="adminSummaryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-paper-plane mr-2"></i>Email Fee Summary to Administrator
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-3">
                    Select the report type to deliver directly to the administrator's email address. The report contains metrics on total collections, collection rate, list of defaulters, and recent payments.
                </p>

                <div class="form-group">
                    <label class="font-weight-bold small text-muted text-uppercase">Report Period / Type</label>
                    <select id="adminDigestType" class="form-control custom-select font-weight-bold">
                        <option value="mid_month">Mid-Month Reminder (15th of <?php echo $summary['month_name']; ?>)</option>
                        <option value="end_month">Month-End Summary (End of <?php echo $summary['month_name']; ?>)</option>
                        <option value="manual" selected>Current Real-Time Status Report</option>
                    </select>
                </div>

                <div class="bg-light p-3 rounded-lg border small text-muted">
                    <i class="fas fa-info-circle text-info mr-1"></i>Destination: <strong>Administrator Email Address</strong>
                </div>
            </div>
            <div class="modal-footer bg-light border-top py-3 px-4">
                <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                <button type="button" id="btnConfirmSendAdminDigest" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow-sm" onclick="dispatchAdminDigest()">
                    <i class="fas fa-paper-plane mr-2"></i>Send Report
                </button>
            </div>
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

function openGeneralCollectModal() {
    <?php if (!empty($summary['students'])): ?>
        let firstStudent = <?php echo json_encode($summary['students'][0]); ?>;
        openQuickCollectModal(firstStudent.student_id, firstStudent.full_name, <?php echo $month; ?>, <?php echo $year; ?>, firstStudent.monthly_fee, 0, 'Cash', '');
    <?php else: ?>
        alert('No active students available.');
    <?php endif; ?>
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
            alert('Server communication error occurred while recording payment.');
        }
    });
});

function sendIndividualStatement(studentId, studentName, year) {
    if (!confirm('Send fee statement email to ' + studentName + '?')) return;

    let alertBox = $('#ajaxAlertContainer');
    alertBox.html('<div class="alert alert-info py-2 shadow-sm"><i class="fas fa-spinner fa-spin mr-2"></i>Dispatching statement email to ' + studentName + '...</div>');

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
                alertBox.html('<div class="alert alert-success alert-dismissible fade show py-2 shadow-sm"><i class="fas fa-check-circle mr-2"></i>' + res.message + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            } else {
                alertBox.html('<div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i>' + res.error + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            }
        },
        error: function() {
            alertBox.html('<div class="alert alert-danger py-2 shadow-sm"><i class="fas fa-times mr-2"></i>Server communication error.</div>');
        }
    });
}

function sendBulkDueReminders() {
    let count = <?php echo count($summary['defaulters']); ?>;
    if (count === 0) {
        alert('All students are fully up to date! There are no pending fees to remind.');
        return;
    }

    if (!confirm('Send automated fee statement reminder emails to all ' + count + ' students with pending dues?')) return;

    let alertBox = $('#ajaxAlertContainer');
    alertBox.html('<div class="alert alert-info py-2 shadow-sm"><i class="fas fa-spinner fa-spin mr-2"></i>Sending fee statements to all due students (this may take a few seconds)...</div>');

    $.ajax({
        url: 'notify_ajax.php',
        method: 'POST',
        data: {
            csrf_token: '<?php echo generate_csrf_token(); ?>',
            action: 'send_bulk_due_reminders',
            year: <?php echo $year; ?>,
            month: <?php echo $month; ?>
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                alertBox.html('<div class="alert alert-success alert-dismissible fade show py-2 shadow-sm"><i class="fas fa-check-circle mr-2"></i>' + res.message + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            } else {
                alertBox.html('<div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i>' + res.error + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            }
        },
        error: function() {
            alertBox.html('<div class="alert alert-danger py-2 shadow-sm"><i class="fas fa-times mr-2"></i>Server communication error during bulk notification.</div>');
        }
    });
}

function openAdminDigestModal() {
    $('#adminSummaryModal').modal('show');
}

function dispatchAdminDigest() {
    let type = $('#adminDigestType').val();
    triggerAdminDigest(type);
    $('#adminSummaryModal').modal('hide');
}

function triggerAdminDigest(reminderType) {
    let alertBox = $('#ajaxAlertContainer');
    alertBox.html('<div class="alert alert-info py-2 shadow-sm"><i class="fas fa-spinner fa-spin mr-2"></i>Compiling and sending fee collection report to administrator email...</div>');

    $.ajax({
        url: 'notify_ajax.php',
        method: 'POST',
        data: {
            csrf_token: '<?php echo generate_csrf_token(); ?>',
            action: 'send_admin_digest',
            reminder_type: reminderType
        },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                alertBox.html('<div class="alert alert-success alert-dismissible fade show py-2 shadow-sm"><i class="fas fa-check-circle mr-2"></i>' + res.message + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            } else {
                alertBox.html('<div class="alert alert-danger alert-dismissible fade show py-2 shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i>' + res.error + '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            }
        },
        error: function() {
            alertBox.html('<div class="alert alert-danger py-2 shadow-sm"><i class="fas fa-times mr-2"></i>Server communication error during admin digest dispatch.</div>');
        }
    });
}

// Custom Fee Profiles Management JS
let globalCurrentStdFee = 1500.00;
const feeCurrSymbol = '<?php echo h($currency); ?>';

function openListCustomProfilesModal() {
    $('#listCustomProfilesModal').modal('show');
    loadCustomProfilesTable();
}

function loadCustomProfilesTable() {
    $.ajax({
        url: 'custom_profile_ajax.php',
        type: 'GET',
        data: { action: 'list' },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                $('#customProfilesTableBody').html('<tr><td colspan="7" class="text-center text-danger py-4">' + (res.error || 'Failed to load.') + '</td></tr>');
                return;
            }

            if (!res.profiles || res.profiles.length === 0) {
                $('#customProfilesTableBody').html('<tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-user-tag fa-2x mb-2 d-block text-black-50"></i>No custom fee profiles set yet. All students are on standard class criteria.</td></tr>');
                return;
            }

            let html = '';
            res.profiles.forEach(function(p) {
                let badgeClass = 'badge-primary';
                let typeName = 'Fixed Rate';
                if (p.profile_type === 'full_scholarship') { badgeClass = 'badge-success'; typeName = '100% Scholarship'; }
                else if (p.profile_type === 'discount_percent') { badgeClass = 'badge-info'; typeName = p.discount_percent + '% Concession'; }
                else if (p.profile_type === 'discount_amount') { badgeClass = 'badge-warning text-dark'; typeName = feeCurrSymbol + p.custom_amount + ' Discount'; }

                html += `
                    <tr>
                        <td class="align-middle">
                            <div class="font-weight-bold text-dark">${p.student_name}</div>
                            <small class="text-muted font-family-monospace">ID: ${p.username}</small>
                        </td>
                        <td class="align-middle"><span class="badge badge-light border font-weight-bold">Class ${p.class_name}</span></td>
                        <td class="align-middle text-muted">${feeCurrSymbol}${parseFloat(p.standard_fee).toFixed(2)}</td>
                        <td class="align-middle"><span class="badge ${badgeClass} px-2 py-1 font-weight-bold">${typeName}</span></td>
                        <td class="align-middle font-weight-bold text-success" style="font-size: 1.05rem;">${feeCurrSymbol}${parseFloat(p.effective_fee).toFixed(2)}</td>
                        <td class="align-middle small text-muted">${p.reason || 'None specified'}</td>
                        <td class="align-middle text-right text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 font-weight-bold mr-1" onclick="openStudentCustomFeeModal(${p.student_id}, '${p.student_name.replace(/'/g, "\\'")}')">
                                <i class="fas fa-edit mr-1"></i>Edit
                            </button>
                        </td>
                    </tr>
                `;
            });

            $('#customProfilesTableBody').html(html);
        },
        error: function() {
            $('#customProfilesTableBody').html('<tr><td colspan="7" class="text-center text-danger py-4">Server error while loading custom fee profiles.</td></tr>');
        }
    });
}

function openStudentCustomFeeModal(studentId, studentName) {
    if (!studentId) {
        let enteredId = prompt('Enter Student ID / Enrollment Number to customize fees:');
        if (!enteredId) return;
        studentId = enteredId;
    }

    $('#modalStuId').val(studentId);
    $('#modalStuAlert').empty();
    $('#modalStuName').text(studentName || 'Loading student...');
    $('#deleteCustomProfileBtn').hide();

    $.ajax({
        url: 'custom_profile_ajax.php',
        type: 'GET',
        data: { action: 'get', student_id: studentId },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                alert(res.error || 'Failed to fetch student data.');
                return;
            }

            const stu = res.student || {};
            const p = res.profile || {};
            const feeInfo = res.fee_info || {};

            $('#modalStuName').text(stu.name + ' (' + stu.username + ')');
            $('#modalStuClass').text('Class ' + (stu.class_name || 'N/A') + ' • ' + (stu.institute_name || 'Campus'));
            globalCurrentStdFee = parseFloat(res.standard_fee) || 1500.00;
            $('#modalStandardFeeDisplay').text(feeCurrSymbol + globalCurrentStdFee.toFixed(2));

            if (p && p.id) {
                $('#modalProfileType').val(p.profile_type || 'fixed_amount');
                $('#modalCustomAmount').val(p.custom_amount || '1000.00');
                $('#modalDiscountPct').val(p.discount_percent || '25.00');
                $('#modalAdditionalCharges').val(p.additional_charges || '0.00');
                $('#modalReason').val(p.reason || '');
                $('#modalStartDate').val(p.start_date || '');
                $('#modalEndDate').val(p.end_date || '');
                $('#deleteCustomProfileBtn').show();
            } else {
                $('#modalProfileType').val('fixed_amount');
                $('#modalCustomAmount').val((globalCurrentStdFee * 0.8).toFixed(2));
                $('#modalDiscountPct').val('20.00');
                $('#modalAdditionalCharges').val('0.00');
                $('#modalReason').val('');
                $('#modalStartDate').val('');
                $('#modalEndDate').val('');
                $('#deleteCustomProfileBtn').hide();
            }

            recalcCustomFeeModal();
            $('#studentCustomFeeModal').modal('show');
        },
        error: function() {
            alert('Failed to connect to fee server.');
        }
    });
}

function recalcCustomFeeModal() {
    const type = $('#modalProfileType').val();
    const customAmtGroup = $('#modalCustomAmountGroup');
    const discPctGroup = $('#modalDiscountPctGroup');
    const amtLabel = $('#modalAmountLabel');

    let customAmt = parseFloat($('#modalCustomAmount').val()) || 0;
    let discPct = parseFloat($('#modalDiscountPct').val()) || 0;
    let extra = parseFloat($('#modalAdditionalCharges').val()) || 0;

    if (type === 'fixed_amount') {
        customAmtGroup.show();
        discPctGroup.hide();
        amtLabel.text('Special Fixed Monthly Fee (' + feeCurrSymbol + ')');
    } else if (type === 'discount_percent') {
        customAmtGroup.hide();
        discPctGroup.show();
    } else if (type === 'discount_amount') {
        customAmtGroup.show();
        discPctGroup.hide();
        amtLabel.text('Concession / Discount Amount (' + feeCurrSymbol + ')');
    } else { // full_scholarship
        customAmtGroup.hide();
        discPctGroup.hide();
    }

    let effective = globalCurrentStdFee;
    let desc = '';

    if (type === 'full_scholarship') {
        effective = 0.00;
        desc = '100% Full Scholarship / Complete Exemption';
    } else if (type === 'fixed_amount') {
        effective = Math.max(0, customAmt + extra);
        desc = 'Fixed Rate: ' + feeCurrSymbol + customAmt.toFixed(2) + (extra > 0 ? ' + Extra: ' + feeCurrSymbol + extra.toFixed(2) : '');
    } else if (type === 'discount_percent') {
        let discVal = (globalCurrentStdFee * discPct) / 100.0;
        effective = Math.max(0, (globalCurrentStdFee - discVal) + extra);
        desc = discPct.toFixed(0) + '% Concession (-' + feeCurrSymbol + discVal.toFixed(2) + ')' + (extra > 0 ? ' + Extra: ' + feeCurrSymbol + extra.toFixed(2) : '');
    } else if (type === 'discount_amount') {
        effective = Math.max(0, (globalCurrentStdFee - customAmt) + extra);
        desc = 'Flat Concession of ' + feeCurrSymbol + customAmt.toFixed(2) + (extra > 0 ? ' + Extra: ' + feeCurrSymbol + extra.toFixed(2) : '');
    }

    $('#modalEffectiveFeeDisplay').text(feeCurrSymbol + effective.toFixed(2));
    $('#modalEffectiveBreakdown').text(desc);
}

function saveStudentCustomFee(e) {
    e.preventDefault();
    const btn = $('#saveCustomProfileBtn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

    $.ajax({
        url: 'custom_profile_ajax.php',
        type: 'POST',
        data: $('#studentCustomFeeForm').serialize(),
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Save Profile');
            if (res.success) {
                $('#studentCustomFeeModal').modal('hide');
                alert(res.message);
                location.reload();
            } else {
                $('#modalStuAlert').html('<div class="alert alert-danger py-2 px-3 small">' + (res.error || 'Failed to save.') + '</div>');
            }
        },
        error: function() {
            btn.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i> Save Profile');
            $('#modalStuAlert').html('<div class="alert alert-danger py-2 px-3 small">Server error while saving fee profile.</div>');
        }
    });
}

function deleteStudentCustomFee() {
    const stuId = $('#modalStuId').val();
    if (!stuId) return;

    if (!confirm('Are you sure you want to remove this custom fee profile? The student will immediately return to standard class fee criteria.')) return;

    const btn = $('#deleteCustomProfileBtn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Removing...');

    $.ajax({
        url: 'custom_profile_ajax.php',
        type: 'POST',
        data: {
            action: 'delete',
            student_id: stuId,
            csrf_token: '<?php echo generate_csrf_token(); ?>'
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-trash-alt mr-1"></i> Remove Custom Profile');
            if (res.success) {
                $('#studentCustomFeeModal').modal('hide');
                alert(res.message);
                location.reload();
            } else {
                $('#modalStuAlert').html('<div class="alert alert-danger py-2 px-3 small">' + (res.error || 'Failed to remove.') + '</div>');
            }
        },
        error: function() {
            btn.prop('disabled', false).html('<i class="fas fa-trash-alt mr-1"></i> Remove Custom Profile');
            $('#modalStuAlert').html('<div class="alert alert-danger py-2 px-3 small">Server error while removing fee profile.</div>');
        }
    });
}
</script>
<!-- ================= MODAL: LIST CUSTOM FEE PROFILES ================= -->
<div class="modal fade" id="listCustomProfilesModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-user-tag text-success mr-2"></i>Students with Custom Fee Profiles & Concessions
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small">Special scholarships, sibling discounts, and negotiated tuition rates outside standard class criteria.</span>
                    <button type="button" class="btn btn-sm btn-primary rounded-pill font-weight-bold px-3" onclick="openStudentCustomFeeModal(0, '')">
                        <i class="fas fa-plus mr-1"></i>New Custom Profile
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0" id="customProfilesTable">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th>Student Details</th>
                                <th>Class</th>
                                <th>Standard Fee</th>
                                <th>Profile Type & Concession</th>
                                <th>Effective Fee</th>
                                <th>Reason / Remarks</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="customProfilesTableBody">
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Loading profiles...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm font-weight-bold rounded-pill px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: CONFIGURE STUDENT CUSTOM FEE PROFILE ================= -->
<div class="modal fade" id="studentCustomFeeModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-user-tag text-warning mr-2"></i>Configure Custom Fee Profile
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="studentCustomFeeForm" onsubmit="saveStudentCustomFee(event)">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="student_id" id="modalStuId" value="">

                <div class="modal-body p-4">
                    <div id="modalStuAlert"></div>

                    <div class="p-3 bg-light rounded border mb-4 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small text-uppercase font-weight-bold d-block">Student</span>
                            <h5 class="font-weight-bold text-dark mb-0" id="modalStuName">-</h5>
                            <small class="text-muted" id="modalStuClass">-</small>
                        </div>
                        <div class="text-right">
                            <span class="text-muted small text-uppercase font-weight-bold d-block">Standard Class Fee</span>
                            <h4 class="font-weight-bold text-primary mb-0" id="modalStandardFeeDisplay"><?php echo h($currency); ?>1,500.00</h4>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small text-uppercase">Fee Concession / Structure Type</label>
                        <select name="profile_type" id="modalProfileType" class="form-control custom-select font-weight-bold" onchange="recalcCustomFeeModal()">
                            <option value="fixed_amount">Special Fixed Monthly Amount (Direct Override)</option>
                            <option value="discount_percent">Percentage Discount / Scholarship Concession (e.g. 25% off)</option>
                            <option value="discount_amount">Flat Monthly Concession (e.g. ₹500 discount)</option>
                            <option value="full_scholarship">100% Full Scholarship / Free Education Waiver</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3" id="modalCustomAmountGroup">
                            <label class="font-weight-bold text-dark small text-uppercase" id="modalAmountLabel">Custom Fixed Monthly Fee (<?php echo h($currency); ?>)</label>
                            <input type="number" step="0.01" min="0" name="custom_amount" id="modalCustomAmount" class="form-control font-weight-bold" value="1000.00" oninput="recalcCustomFeeModal()">
                        </div>
                        <div class="col-md-6 mb-3" id="modalDiscountPctGroup" style="display: none;">
                            <label class="font-weight-bold text-dark small text-uppercase">Discount Percentage (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="modalDiscountPct" class="form-control font-weight-bold" value="25.00" oninput="recalcCustomFeeModal()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Additional Recurring Charges (<?php echo h($currency); ?>)</label>
                            <input type="number" step="0.01" min="0" name="additional_charges" id="modalAdditionalCharges" class="form-control font-weight-bold" value="0.00" placeholder="e.g. Transport, Lab" oninput="recalcCustomFeeModal()">
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small text-uppercase">Reason / Concession Note <span class="text-danger">*</span></label>
                        <input type="text" name="reason" id="modalReason" class="form-control font-weight-bold" required placeholder="e.g. Merit scholarship, Sibling discount (elder sibling in Class 12)">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Effective From (Optional)</label>
                            <input type="date" name="start_date" id="modalStartDate" class="form-control">
                            <small class="text-muted">Leave empty to apply to entire academic tenure.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Effective Until (Optional)</label>
                            <input type="date" name="end_date" id="modalEndDate" class="form-control">
                            <small class="text-muted">Leave empty for open-ended profile.</small>
                        </div>
                    </div>

                    <!-- Live Calculation Box -->
                    <div class="card border-success bg-success bg-opacity-10 shadow-xs rounded-lg p-3 text-center mt-2">
                        <span class="small font-weight-bold text-success text-uppercase">Effective Monthly Fee Payable</span>
                        <h2 class="font-weight-bold text-dark mb-0 mt-1" id="modalEffectiveFeeDisplay"><?php echo h($currency); ?>1,000.00</h2>
                        <small class="text-muted" id="modalEffectiveBreakdown">Special Negotiated Fixed Rate</small>
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4 d-flex justify-content-between">
                    <div>
                        <button type="button" class="btn btn-outline-danger font-weight-bold rounded-pill px-3" id="deleteCustomProfileBtn" onclick="deleteStudentCustomFee()" style="display: none;">
                            <i class="fas fa-trash-alt mr-1"></i>Remove Custom Profile
                        </button>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4 mr-2" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="saveCustomProfileBtn">
                            <i class="fas fa-check-circle mr-1"></i>Save Profile
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>


<?php require_once '../../includes/admin_footer.php'; ?>

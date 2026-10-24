<?php
// admin/attendance/index.php
require_once '../../includes/admin_header.php';

require_admin_feature('attendance');

$effective_admin_id = is_super_admin() ? null : get_effective_admin_id();

$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}
$selected_class = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : '';
$selected_batch = !empty($_GET['batch_id']) ? (int)$_GET['batch_id'] : '';

// Fetch classes and batches
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll(PDO::FETCH_ASSOC);

$batchWhere = is_super_admin() ? "is_active = 1" : "is_active = 1 AND admin_id = " . (int)$effective_admin_id;
$batches = $pdo->query("SELECT id, name, class_id, schedule_desc FROM batches WHERE $batchWhere ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing attendance registers for this date
$regWhere = "ar.session_date = ?";
$regParams = [$selected_date];
if (!is_super_admin()) {
    $regWhere .= " AND ar.admin_id = ?";
    $regParams[] = $effective_admin_id;
}
if ($selected_class) {
    $regWhere .= " AND ar.class_id = ?";
    $regParams[] = $selected_class;
}
if ($selected_batch) {
    $regWhere .= " AND ar.batch_id = ?";
    $regParams[] = $selected_batch;
}

$stmtRegs = $pdo->prepare("
    SELECT ar.*, c.name as class_name, b.name as batch_name, adm.username as recorded_by_user,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id) as total_recorded,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'offline') as count_offline,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'online') as count_online,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'absent') as count_absent,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'late') as count_late,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND parent_notified = 1) as count_notified
    FROM attendance_registers ar
    JOIN classes c ON ar.class_id = c.id
    LEFT JOIN batches b ON ar.batch_id = b.id
    LEFT JOIN admins adm ON ar.recorded_by_id = adm.id
    WHERE $regWhere
    ORDER BY ar.id DESC
");
$stmtRegs->execute($regParams);
$registers = $stmtRegs->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall summary metrics
$metricTotal = 0;
$metricOffline = 0;
$metricOnline = 0;
$metricAbsent = 0;
$metricLate = 0;
foreach ($registers as $r) {
    $metricTotal += $r['total_recorded'];
    $metricOffline += $r['count_offline'];
    $metricOnline += $r['count_online'];
    $metricAbsent += $r['count_absent'];
    $metricLate += $r['count_late'];
}
$presentTotal = $metricOffline + $metricOnline + $metricLate;
$attendanceRate = ($metricTotal > 0) ? round(($presentTotal / $metricTotal) * 100, 1) : 0;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-clipboard-check text-success mr-2"></i>Class Schedule Attendance
        </h2>
        <p class="text-muted small mb-0">Record and track online and offline class attendance, monitor absentees, and alert parents.</p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap" style="gap: 8px;">
        <a href="take.php?date=<?= urlencode($selected_date) ?>" class="btn btn-success font-weight-bold rounded-pill shadow-sm px-4">
            <i class="fas fa-plus mr-1"></i> Take Attendance Now
        </a>
    </div>
</div>

<!-- Date & Filter Bar -->
<div class="card shadow-sm border-0 rounded-lg mb-4 bg-light">
    <div class="card-body p-3">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-3 mb-2 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Session Date</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-calendar-alt text-primary"></i></span></div>
                    <input type="date" name="date" class="form-control border-0 font-weight-bold bg-white" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
                </div>
            </div>
            <div class="col-md-3 mb-2 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Class</label>
                <select name="class_id" class="form-control custom-select border-0 font-weight-bold" onchange="this.form.submit()">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selected_class == $c['id'] ? 'selected' : '' ?>>Class <?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-2 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Teaching Batch</label>
                <select name="batch_id" class="form-control custom-select border-0 font-weight-bold" onchange="this.form.submit()">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $selected_batch == $b['id'] ? 'selected' : '' ?>>
                            <?= h($b['name']) ?> <?= !empty($b['schedule_desc']) ? '(' . h($b['schedule_desc']) . ')' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex">
                <button type="submit" class="btn btn-dark btn-block font-weight-bold rounded-pill shadow-xs">
                    <i class="fas fa-filter mr-1"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- KPI Summary Cards -->
<div class="row mb-4">
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-primary" style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase font-weight-bold">Total Recorded</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= $metricTotal ?></h3>
                    <small class="text-muted"><?= count($registers) ?> registers taken</small>
                </div>
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center shadow-xs" style="width: 48px; height: 48px;">
                    <i class="fas fa-users fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-success" style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase font-weight-bold">Present Offline</span>
                    <h3 class="font-weight-bold text-success mb-0 mt-1"><?= $metricOffline ?></h3>
                    <small class="text-muted"><i class="fas fa-building mr-1"></i>In classroom</small>
                </div>
                <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center shadow-xs" style="width: 48px; height: 48px;">
                    <i class="fas fa-school fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-info" style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase font-weight-bold">Present Online</span>
                    <h3 class="font-weight-bold text-info mb-0 mt-1"><?= $metricOnline ?></h3>
                    <small class="text-muted"><i class="fas fa-laptop mr-1"></i>Via portal login</small>
                </div>
                <div class="rounded-circle bg-info text-white d-flex align-items-center justify-content-center shadow-xs" style="width: 48px; height: 48px;">
                    <i class="fas fa-laptop-house fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-danger" style="border-left-width: 5px !important;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted small text-uppercase font-weight-bold">Absentees</span>
                    <h3 class="font-weight-bold text-danger mb-0 mt-1"><?= $metricAbsent ?></h3>
                    <small class="text-muted">Rate: <?= $attendanceRate ?>% Present</small>
                </div>
                <div class="rounded-circle bg-danger text-white d-flex align-items-center justify-content-center shadow-xs" style="width: 48px; height: 48px;">
                    <i class="fas fa-user-times fa-lg"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Registers Table -->
<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-calendar-day text-primary mr-2"></i>Sessions on <?= date('d M Y, l', strtotime($selected_date)) ?>
            </h5>
            <small class="text-muted">Breakdown of online vs. offline participation and parent notification status</small>
        </div>
        <div class="mt-2 mt-md-0">
            <span class="badge badge-light border px-3 py-2 font-weight-bold">
                <?= count($registers) ?> Sessions Recorded
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive p-4">
            <table class="table table-hover table-bordered mb-0" id="registersTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th>Class &amp; Batch</th>
                        <th>Session Title / Schedule</th>
                        <th class="text-center">Enrolled</th>
                        <th class="text-center">🏢 Offline</th>
                        <th class="text-center">💻 Online</th>
                        <th class="text-center">❌ Absent</th>
                        <th class="text-center">Attendance %</th>
                        <th class="text-center">Parent Alerts</th>
                        <th class="text-right px-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registers)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fas fa-clipboard-list fa-3x mb-3 text-black-50 d-block"></i>
                                <h6 class="font-weight-bold">No Attendance Recorded for <?= date('d M Y', strtotime($selected_date)) ?></h6>
                                <p class="small text-muted mb-3">Start by recording attendance for scheduled batches or classes today.</p>
                                <a href="take.php?date=<?= urlencode($selected_date) ?>" class="btn btn-success font-weight-bold rounded-pill px-4 shadow-sm">
                                    <i class="fas fa-plus mr-1"></i> Record Attendance Now
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($registers as $reg): 
                            $regTotal = (int)$reg['total_recorded'];
                            $regPresent = (int)$reg['count_offline'] + (int)$reg['count_online'] + (int)$reg['count_late'];
                            $regPct = ($regTotal > 0) ? round(($regPresent / $regTotal) * 100, 1) : 0;
                            $absentCount = (int)$reg['count_absent'];
                            $notifiedCount = (int)$reg['count_notified'];
                        ?>
                        <tr>
                            <td class="align-middle font-weight-bold">
                                <span class="badge badge-primary px-2 py-1 mb-1">Class <?= h($reg['class_name']) ?></span>
                                <?php if (!empty($reg['batch_name'])): ?>
                                    <div class="text-dark"><i class="fas fa-layer-group text-muted mr-1"></i><?= h($reg['batch_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle">
                                <span class="font-weight-bold text-dark"><?= h($reg['session_title'] ?: 'Standard Class Session') ?></span>
                                <div class="small text-muted">
                                    By: <?= h($reg['recorded_by_user'] ?: 'Teacher') ?> • <?= date('h:i A', strtotime($reg['created_at'])) ?>
                                </div>
                            </td>
                            <td class="align-middle text-center font-weight-bold"><?= $regTotal ?></td>
                            <td class="align-middle text-center font-weight-bold text-success">
                                <span class="badge badge-success px-2 py-1 rounded-pill"><?= (int)$reg['count_offline'] ?></span>
                            </td>
                            <td class="align-middle text-center font-weight-bold text-info">
                                <span class="badge badge-info px-2 py-1 rounded-pill"><?= (int)$reg['count_online'] ?></span>
                            </td>
                            <td class="align-middle text-center font-weight-bold text-danger">
                                <span class="badge badge-danger px-2 py-1 rounded-pill"><?= $absentCount ?></span>
                            </td>
                            <td class="align-middle text-center font-weight-bold">
                                <div class="progress" style="height: 6px; width: 80px; margin: 0 auto 4px auto;">
                                    <div class="progress-bar bg-<?= $regPct >= 75 ? 'success' : ($regPct >= 50 ? 'warning' : 'danger') ?>" style="width: <?= $regPct ?>%;"></div>
                                </div>
                                <small class="font-weight-bold text-dark"><?= $regPct ?>%</small>
                            </td>
                            <td class="align-middle text-center">
                                <?php if ($absentCount === 0): ?>
                                    <span class="badge badge-light border text-muted">100% Present</span>
                                <?php elseif ($notifiedCount >= $absentCount): ?>
                                    <span class="badge badge-success px-2 py-1 rounded-pill font-weight-bold" title="All absent parents alerted">
                                        <i class="fas fa-check-circle mr-1"></i> All Notified
                                    </span>
                                <?php elseif ($notifiedCount > 0): ?>
                                    <span class="badge badge-warning text-dark px-2 py-1 rounded-pill font-weight-bold">
                                        <?= $notifiedCount ?>/<?= $absentCount ?> Notified
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-2 py-1 rounded-pill font-weight-bold" title="Parents not yet informed">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Pending Alerts
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-right px-4 text-nowrap">
                                <a href="take.php?register_id=<?= $reg['id'] ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 font-weight-bold shadow-xs mr-1">
                                    <i class="fas fa-edit mr-1"></i> Edit
                                </a>
                                <a href="absentees.php?register_id=<?= $reg['id'] ?>" class="btn btn-sm <?= $absentCount > 0 ? 'btn-outline-danger' : 'btn-outline-secondary' ?> rounded-pill px-3 font-weight-bold shadow-xs">
                                    <i class="fas fa-bell mr-1"></i> Absentees (<?= $absentCount ?>)
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#registersTable').DataTable({
        pageLength: 25,
        order: [],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search registers..."
        }
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

<?php
// admin/attendance/take.php
require_once '../../includes/admin_header.php';

require_admin_feature('attendance');

$effective_admin_id = is_super_admin() ? null : get_effective_admin_id();

// --- Direct Form POST Fallback (Guarantees attendance is saved even if JS is blocked) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['records'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $p_reg_id = (int)($_POST['register_id'] ?? 0);
    $p_date = trim($_POST['session_date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $p_date)) {
        $p_date = date('Y-m-d');
    }
    $p_class = (int)($_POST['class_id'] ?? 0);
    $p_batch = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
    $p_title = trim($_POST['session_title'] ?? 'Standard Lecture Session');
    $p_start = !empty($_POST['schedule_time_start']) ? trim($_POST['schedule_time_start']) : null;
    $p_end = !empty($_POST['schedule_time_end']) ? trim($_POST['schedule_time_end']) : null;
    $p_notes = trim($_POST['notes'] ?? '');
    $p_records = $_POST['records'] ?? [];

    $current_user_id = (int)($_SESSION['admin_id'] ?? 1);
    $scoped_admin_id = is_super_admin() ? (int)($_SESSION['admin_id'] ?? 1) : get_effective_admin_id();

    if ($p_class > 0 && !empty($p_records)) {
        $pdo->beginTransaction();
        try {
            if ($p_reg_id > 0) {
                $stmtUp = $pdo->prepare("
                    UPDATE attendance_registers 
                    SET session_date=?, class_id=?, batch_id=?, session_title=?, schedule_time_start=?, schedule_time_end=?, notes=?, recorded_by_id=? 
                    WHERE id=?
                ");
                $stmtUp->execute([$p_date, $p_class, $p_batch ?: null, $p_title, $p_start ?: null, $p_end ?: null, $p_notes, $current_user_id, $p_reg_id]);
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO attendance_registers 
                    (admin_id, class_id, batch_id, session_date, session_title, schedule_time_start, schedule_time_end, recorded_by_id, notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$scoped_admin_id, $p_class, $p_batch ?: null, $p_date, $p_title, $p_start ?: null, $p_end ?: null, $current_user_id, $p_notes]);
                $p_reg_id = (int)$pdo->lastInsertId();
            }

            $stmtRecord = $pdo->prepare("
                INSERT INTO attendance_records (register_id, student_id, status, marked_by_id, auto_detected, remarks)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by_id = VALUES(marked_by_id), auto_detected = VALUES(auto_detected), remarks = VALUES(remarks)
            ");

            $cntOffline = 0; $cntOnline = 0; $cntAbsent = 0; $cntLate = 0;
            foreach ($p_records as $sId => $d) {
                $st = in_array($d['status'] ?? '', ['offline', 'online', 'absent', 'late']) ? $d['status'] : 'offline';
                $ad = !empty($d['auto_detected']) ? 1 : 0;
                $rem = trim($d['remarks'] ?? '');
                $stmtRecord->execute([$p_reg_id, (int)$sId, $st, $current_user_id, $ad, $rem]);
                if ($st === 'offline') $cntOffline++;
                elseif ($st === 'online') $cntOnline++;
                elseif ($st === 'absent') $cntAbsent++;
                elseif ($st === 'late') $cntLate++;
            }
            $pdo->commit();

            $_SESSION['flash_success'] = "Attendance recorded successfully! (Offline: {$cntOffline}, Online: {$cntOnline}, Absent: {$cntAbsent}" . ($cntLate > 0 ? ", Late: {$cntLate}" : "") . ")";
            if ($cntAbsent > 0) {
                header("Location: absentees.php?register_id=" . $p_reg_id);
            } else {
                header("Location: index.php?date=" . urlencode($p_date));
            }
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash_error'] = "Failed to save attendance: " . $e->getMessage();
        }
    }
}

$register_id = !empty($_GET['register_id']) ? (int)$_GET['register_id'] : 0;
$existingRegister = null;

if ($register_id > 0) {
    $stmtReg = $pdo->prepare("SELECT * FROM attendance_registers WHERE id = ?" . (!is_super_admin() ? " AND admin_id = ?" : ""));
    $stmtReg->execute(!is_super_admin() ? [$register_id, $effective_admin_id] : [$register_id]);
    $existingRegister = $stmtReg->fetch(PDO::FETCH_ASSOC);

    if (!$existingRegister) {
        $_SESSION['flash_error'] = "Attendance register not found or you do not have permission to edit it.";
        header("Location: index.php");
        exit;
    }

    $selected_date = $existingRegister['session_date'];
    $selected_class = (int)$existingRegister['class_id'];
    $selected_batch = (int)$existingRegister['batch_id'];
    $session_title = $existingRegister['session_title'];
    $session_start = $existingRegister['schedule_time_start'];
    $session_end = $existingRegister['schedule_time_end'];
    $notes = $existingRegister['notes'];
} else {
    $selected_date = $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
        $selected_date = date('Y-m-d');
    }
    $selected_class = !empty($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $selected_batch = !empty($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
    $session_title = $_GET['title'] ?? 'Standard Lecture Session';
    $session_start = '';
    $session_end = '';
    $notes = '';
}

// Fetch classes and batches for selector dropdowns
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll(PDO::FETCH_ASSOC);

// If no class or batch selected yet on new attendance, default to the first available class
if (empty($register_id) && empty($selected_class) && empty($selected_batch) && !empty($classes)) {
    $selected_class = (int)$classes[0]['id'];
}

$batchWhere = is_super_admin() ? "is_active = 1" : "is_active = 1 AND admin_id = " . (int)$effective_admin_id;
$batches = $pdo->query("SELECT id, name, class_id, schedule_desc FROM batches WHERE $batchWhere ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Auto-fill class if batch was picked and class not yet specified
if ($selected_batch > 0 && empty($selected_class)) {
    foreach ($batches as $b) {
        if ($b['id'] == $selected_batch && !empty($b['class_id'])) {
            $selected_class = (int)$b['class_id'];
            break;
        }
    }
}

// Auto-detect existing register for this class & date if not explicitly passed in URL
if ($register_id <= 0 && $selected_class > 0 && empty($_GET['new_session'])) {
    $chkRegSql = "SELECT * FROM attendance_registers WHERE class_id = ? AND session_date = ?" 
               . ($selected_batch > 0 ? " AND batch_id = " . (int)$selected_batch : "")
               . (!is_super_admin() ? " AND admin_id = " . (int)$effective_admin_id : "")
               . " ORDER BY id DESC LIMIT 1";
    $stmtChkReg = $pdo->prepare($chkRegSql);
    $stmtChkReg->execute([$selected_class, $selected_date]);
    $existingRegAuto = $stmtChkReg->fetch(PDO::FETCH_ASSOC);
    if ($existingRegAuto) {
        $register_id = (int)$existingRegAuto['id'];
        $existingRegister = $existingRegAuto;
        $session_title = $existingRegister['session_title'] ?: $session_title;
        $session_start = $existingRegister['schedule_time_start'] ?? '';
        $session_end = $existingRegister['schedule_time_end'] ?? '';
        $notes = $existingRegister['notes'] ?? '';
    }
}

// Fetch students for attendance roster
$students = [];
$existingRecords = [];

if ($selected_class > 0 || $selected_batch > 0) {
    $studentsWhere = "s.is_active = 1";
    $studentsParams = [];
    if (!is_super_admin()) {
        $studentsWhere .= " AND s.admin_id = ?";
        $studentsParams[] = $effective_admin_id;
    }

    if ($selected_batch > 0) {
        $studentsQuery = "
            SELECT s.id, s.full_name, s.username, s.phone, s.parent_name, s.parent_phone, s.last_seen, s.last_ip,
                   c.name as class_name, b.name as batch_name,
                   (SELECT COUNT(*) FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE)) as is_active_now,
                   (SELECT device_type FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as active_device,
                   (SELECT browser FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as active_browser,
                   (SELECT current_page FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as active_page
            FROM students s
            JOIN batch_students bs ON bs.student_id = s.id
            JOIN batches b ON bs.batch_id = b.id
            JOIN classes c ON s.class_id = c.id
            WHERE bs.batch_id = ? AND $studentsWhere
            ORDER BY s.full_name ASC
        ";
        array_unshift($studentsParams, $selected_batch);
    } else {
        $studentsQuery = "
            SELECT s.id, s.full_name, s.username, s.phone, s.parent_name, s.parent_phone, s.last_seen, s.last_ip,
                   c.name as class_name, '' as batch_name,
                   (SELECT COUNT(*) FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE)) as is_active_now,
                   (SELECT device_type FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as active_device,
                   (SELECT browser FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as active_browser,
                   (SELECT current_page FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as active_page
            FROM students s
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND $studentsWhere
            ORDER BY s.full_name ASC
        ";
        array_unshift($studentsParams, $selected_class);
    }

    $stmtSt = $pdo->prepare($studentsQuery);
    $stmtSt->execute($studentsParams);
    $students = $stmtSt->fetchAll(PDO::FETCH_ASSOC);

    // If existing register, load marked records
    if ($register_id > 0) {
        $stmtRec = $pdo->prepare("SELECT student_id, status, remarks, auto_detected, parent_notified FROM attendance_records WHERE register_id = ?");
        $stmtRec->execute([$register_id]);
        foreach ($stmtRec->fetchAll(PDO::FETCH_ASSOC) as $rec) {
            $existingRecords[$rec['student_id']] = $rec;
        }
    }
}
?>

<!-- Ensure SweetAlert2 is always loaded -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* Robust Segmented Control for Student Attendance Selection */
.att-segmented-control {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 30px;
    border: 1px solid #cbd5e1;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
    user-select: none;
}
.att-seg-btn {
    border: none;
    background: transparent;
    padding: 6px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    border-radius: 20px;
    cursor: pointer;
    transition: all 0.15s ease-in-out;
    outline: none !important;
    display: inline-flex;
    align-items: center;
    white-space: nowrap;
    text-decoration: none;
}
.att-seg-btn:hover {
    color: #0f172a;
    background: rgba(255, 255, 255, 0.85);
}
.att-seg-btn:focus {
    outline: none !important;
}

/* Vibrant, instantly recognizable active states */
.att-seg-btn.btn-offline.is-active {
    background: #16a34a !important;
    color: #ffffff !important;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(22, 163, 74, 0.4);
}
.att-seg-btn.btn-online.is-active {
    background: #0284c7 !important;
    color: #ffffff !important;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(2, 132, 199, 0.4);
}
.att-seg-btn.btn-absent.is-active {
    background: #dc2626 !important;
    color: #ffffff !important;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.4);
}
.att-seg-btn.btn-late.is-active {
    background: #d97706 !important;
    color: #ffffff !important;
    font-weight: 700;
    box-shadow: 0 2px 6px rgba(217, 119, 6, 0.4);
}

.student-row {
    transition: background-color 0.15s ease;
}
.student-row:hover {
    background-color: #f8fafc !important;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <div class="d-flex align-items-center">
            <a href="index.php?date=<?= urlencode($selected_date) ?>" class="btn btn-outline-secondary rounded-pill btn-sm mr-3 font-weight-bold shadow-xs">
                <i class="fas fa-arrow-left mr-1"></i> Back to Registers
            </a>
            <h2 class="font-weight-bold text-dark mb-0">
                <?= $register_id > 0 ? '<i class="fas fa-edit text-primary mr-2"></i>Edit Attendance Register' : '<i class="fas fa-calendar-check text-success mr-2"></i>Take Class Attendance' ?>
            </h2>
        </div>
        <p class="text-muted small mb-0 mt-1">Record online & offline student presence, auto-detect portal logins, and prepare absentee notifications.</p>
    </div>
    <?php if ($register_id > 0): ?>
    <div class="mt-2 mt-md-0">
        <a href="absentees.php?register_id=<?= $register_id ?>" class="btn btn-outline-danger font-weight-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-bell mr-1"></i> Absentee Alerts Roster
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-check-circle mr-2"></i><?= h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-exclamation-circle mr-2"></i><?= h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- Class / Batch / Date Selection Card -->
<div class="card shadow-sm border-0 rounded-lg mb-4 bg-white">
    <div class="card-header bg-light border-bottom py-3">
        <h6 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-sliders-h text-primary mr-2"></i>Step 1: Select Session Details
        </h6>
    </div>
    <div class="card-body p-4">
        <form method="GET" id="selectionForm" class="row">
            <?php if ($register_id > 0): ?>
                <input type="hidden" name="register_id" value="<?= $register_id ?>">
            <?php endif; ?>

            <div class="col-md-3 mb-3">
                <label class="font-weight-bold text-muted small text-uppercase">Session Date <span class="text-danger">*</span></label>
                <div class="input-group shadow-xs">
                    <div class="input-group-prepend"><span class="input-group-text bg-light border-right-0"><i class="fas fa-calendar-alt text-primary"></i></span></div>
                    <input type="date" name="date" class="form-control font-weight-bold border-left-0" value="<?= htmlspecialchars($selected_date) ?>" required onchange="this.form.submit()">
                </div>
            </div>

            <div class="col-md-3 mb-3">
                <label class="font-weight-bold text-muted small text-uppercase">Academic Class <span class="text-danger">*</span></label>
                <select name="class_id" class="form-control custom-select shadow-xs font-weight-bold" onchange="this.form.submit()" required>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selected_class == $c['id'] ? 'selected' : '' ?>>Class <?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 mb-3">
                <label class="font-weight-bold text-muted small text-uppercase">Teaching Batch</label>
                <select name="batch_id" class="form-control custom-select shadow-xs font-weight-bold" onchange="this.form.submit()">
                    <option value="">All Students in Class (No Batch Filter)</option>
                    <?php foreach ($batches as $b): ?>
                        <?php if (empty($selected_class) || $b['class_id'] == $selected_class): ?>
                            <option value="<?= $b['id'] ?>" <?= $selected_batch == $b['id'] ? 'selected' : '' ?>>
                                <?= h($b['name']) ?> <?= !empty($b['schedule_desc']) ? '(' . h($b['schedule_desc']) . ')' : '' ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 mb-3 d-flex align-items-end">
                <button type="submit" class="btn btn-primary btn-block font-weight-bold rounded-pill shadow-xs">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh
                </button>
            </div>
        </form>

        <!-- 1-Click Class Switcher Pills -->
        <?php if (!empty($classes)): ?>
        <hr class="my-3">
        <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
            <span class="text-muted small font-weight-bold mr-1"><i class="fas fa-layer-group text-primary mr-1"></i>Switch Class:</span>
            <?php foreach ($classes as $c): ?>
                <a href="take.php?date=<?= urlencode($selected_date) ?>&class_id=<?= $c['id'] ?><?= $register_id ? '&register_id='.$register_id : '' ?>" 
                   class="btn btn-sm <?= ($selected_class == $c['id']) ? 'btn-primary font-weight-bold shadow-xs' : 'btn-outline-secondary bg-white' ?> rounded-pill px-3">
                    Class <?= h($c['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($students)): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-lg p-4 mb-4">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-triangle fa-2x text-warning mr-3"></i>
            <div>
                <h6 class="font-weight-bold mb-1">No Active Students Found</h6>
                <p class="mb-0 small text-muted">There are no active enrolled students found for the selected Class/Batch. Choose another class from the tabs above or check student enrolments.</p>
            </div>
        </div>
    </div>
<?php else: ?>

<!-- Interactive Attendance Form (supports both AJAX and regular POST fallback) -->
<form id="attendanceForm" method="POST" action="take.php" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
    <input type="hidden" name="register_id" value="<?= $register_id ?>">
    <input type="hidden" name="session_date" value="<?= htmlspecialchars($selected_date) ?>">
    <input type="hidden" name="class_id" value="<?= $selected_class ?>">
    <input type="hidden" name="batch_id" value="<?= $selected_batch ?>">

    <!-- Session Metadata Card -->
    <div class="card shadow-sm border-0 rounded-lg mb-4 bg-white">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-4 mb-3 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase">Session Title</label>
                    <input type="text" name="session_title" class="form-control font-weight-bold shadow-xs" placeholder="e.g. Physics Mechanics Chapter 4" value="<?= htmlspecialchars($session_title) ?>">
                </div>
                <div class="col-md-2 mb-3 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase">Start Time</label>
                    <input type="time" name="schedule_time_start" class="form-control shadow-xs" value="<?= htmlspecialchars($session_start) ?>">
                </div>
                <div class="col-md-2 mb-3 mb-md-0">
                    <label class="font-weight-bold text-muted small text-uppercase">End Time</label>
                    <input type="time" name="schedule_time_end" class="form-control shadow-xs" value="<?= htmlspecialchars($session_end) ?>">
                </div>
                <div class="col-md-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Session Notes (Optional)</label>
                    <input type="text" name="notes" class="form-control shadow-xs" placeholder="Topics covered or remarks..." value="<?= htmlspecialchars($notes) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions & Real-Time Counters Bar -->
    <div class="card shadow-sm border-0 rounded-lg mb-4 bg-white sticky-top" style="top: 15px; z-index: 1020;">
        <div class="card-body py-3 px-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between" style="gap: 12px;">
                <!-- Live KPI Chips -->
                <div class="d-flex flex-wrap align-items-center" style="gap: 10px;">
                    <span class="badge badge-light border px-3 py-2 text-dark font-weight-bold" style="font-size: 0.85rem;">
                        <i class="fas fa-users mr-1 text-primary"></i> Total: <span id="kpiTotal"><?= count($students) ?></span>
                    </span>
                    <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size: 0.85rem;">
                        <i class="fas fa-building mr-1"></i> Offline: <span id="kpiOffline">0</span>
                    </span>
                    <span class="badge badge-info px-3 py-2 font-weight-bold" style="font-size: 0.85rem;">
                        <i class="fas fa-laptop mr-1"></i> Online: <span id="kpiOnline">0</span>
                    </span>
                    <span class="badge badge-danger px-3 py-2 font-weight-bold" style="font-size: 0.85rem;">
                        <i class="fas fa-user-times mr-1"></i> Absent: <span id="kpiAbsent">0</span>
                    </span>
                    <span class="badge badge-warning text-dark px-3 py-2 font-weight-bold" style="font-size: 0.85rem;">
                        <i class="fas fa-clock mr-1"></i> Late: <span id="kpiLate">0</span>
                    </span>
                </div>

                <!-- Quick Bulk Marking Actions -->
                <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                    <button type="button" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill shadow-xs" onclick="markAll('offline')">
                        <i class="fas fa-check-double mr-1"></i> All Offline
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info font-weight-bold rounded-pill shadow-xs" onclick="markAll('online')">
                        <i class="fas fa-laptop mr-1"></i> All Online
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger font-weight-bold rounded-pill shadow-xs" onclick="markAll('absent')">
                        <i class="fas fa-times-circle mr-1"></i> All Absent
                    </button>
                    <button type="button" class="btn btn-sm btn-dark font-weight-bold rounded-pill shadow-xs" onclick="autoDetectOnline()">
                        <i class="fas fa-bolt text-warning mr-1"></i> Auto-Detect Online
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Attendance Roster Table -->
    <div class="card shadow-sm border-0 rounded-lg mb-4 bg-white">
        <div class="card-header bg-light border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
            <h6 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-list-ol text-primary mr-2"></i>Student Roster (<?= count($students) ?> Students in Class <?= h($students[0]['class_name'] ?? '') ?>)
            </h6>
            <div class="small text-muted">
                <i class="fas fa-info-circle text-info mr-1"></i>Green pulsing badges indicate students active on the portal right now
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th style="min-width: 200px;">Student Information</th>
                            <th style="min-width: 140px;">Portal Status</th>
                            <th style="min-width: 320px;" class="text-center">Attendance Status</th>
                            <th style="min-width: 180px;">Remarks / Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $idx = 0;
                        foreach ($students as $s): 
                            $idx++;
                            $sId = (int)$s['id'];
                            $isOnlineNow = ((int)$s['is_active_now'] > 0);

                            // Determine initial status
                            if (isset($existingRecords[$sId])) {
                                $currentStatus = $existingRecords[$sId]['status'];
                                $currentRemarks = $existingRecords[$sId]['remarks'] ?? '';
                            } else {
                                // Default status: if active online now, preselect 'online'; otherwise 'offline'
                                $currentStatus = $isOnlineNow ? 'online' : 'offline';
                                $currentRemarks = '';
                            }
                        ?>
                        <tr class="student-row" data-student-id="<?= $sId ?>" data-is-online="<?= $isOnlineNow ? '1' : '0' ?>">
                            <td class="font-weight-bold text-muted align-middle"><?= $idx ?></td>
                            <td class="align-middle">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-circle mr-3 font-weight-bold bg-primary text-white rounded-circle d-flex align-items-center justify-content-center shadow-xs" style="width: 40px; height: 40px; font-size: 1rem;">
                                        <?= strtoupper(substr($s['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold text-dark" style="font-size: 0.95rem;">
                                            <?= h($s['full_name']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <span><i class="fas fa-user text-muted mr-1"></i><?= h($s['username']) ?></span>
                                            <?php if (!empty($s['phone'])): ?>
                                                <span class="ml-2"><i class="fas fa-phone text-muted mr-1"></i><?= h($s['phone']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($s['parent_name'])): ?>
                                            <div class="small text-muted" style="font-size: 0.75rem;">
                                                <i class="fas fa-user-friends mr-1"></i>Parent: <?= h($s['parent_name']) ?> <?= !empty($s['parent_phone']) ? '('.h($s['parent_phone']).')' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="align-middle">
                                <?php if ($isOnlineNow): ?>
                                    <span class="badge badge-success px-2 py-1 font-weight-bold shadow-xs">
                                        <span class="spinner-grow spinner-grow-sm text-light mr-1" style="width: 8px; height: 8px;" role="status"></span>
                                        Active Online
                                    </span>
                                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                        <i class="fas fa-<?= ($s['active_device'] === 'Mobile') ? 'mobile-alt' : (($s['active_device'] === 'Tablet') ? 'tablet-alt' : 'desktop') ?> mr-1"></i>
                                        <?= h($s['active_device'] ?: 'Web') ?> (<?= h($s['active_browser'] ?: 'Browser') ?>)
                                    </div>
                                <?php elseif (!empty($s['last_seen'])): ?>
                                    <span class="badge badge-light border text-muted px-2 py-1">
                                        <i class="far fa-clock mr-1"></i>Seen <?= time_elapsed_string($s['last_seen']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-light text-muted border px-2 py-1">Offline</span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-center">
                                <div class="att-segmented-control" id="att-group-<?= $sId ?>">
                                    <input type="radio" name="records[<?= $sId ?>][status]" id="status_<?= $sId ?>_offline" value="offline" <?= $currentStatus === 'offline' ? 'checked' : '' ?> class="d-none att-radio" onchange="updateCounters()">
                                    <input type="radio" name="records[<?= $sId ?>][status]" id="status_<?= $sId ?>_online" value="online" <?= $currentStatus === 'online' ? 'checked' : '' ?> class="d-none att-radio" onchange="updateCounters()">
                                    <input type="radio" name="records[<?= $sId ?>][status]" id="status_<?= $sId ?>_absent" value="absent" <?= $currentStatus === 'absent' ? 'checked' : '' ?> class="d-none att-radio" onchange="updateCounters()">
                                    <input type="radio" name="records[<?= $sId ?>][status]" id="status_<?= $sId ?>_late" value="late" <?= $currentStatus === 'late' ? 'checked' : '' ?> class="d-none att-radio" onchange="updateCounters()">

                                    <button type="button" 
                                            class="att-seg-btn btn-offline <?= $currentStatus === 'offline' ? 'is-active' : '' ?>" 
                                            onclick="setStudentStatus(<?= $sId ?>, 'offline')" 
                                            title="Mark Offline (Physical Classroom)">
                                        <i class="fas fa-school mr-1"></i> Offline
                                    </button>
                                    <button type="button" 
                                            class="att-seg-btn btn-online <?= $currentStatus === 'online' ? 'is-active' : '' ?>" 
                                            onclick="setStudentStatus(<?= $sId ?>, 'online')" 
                                            title="Mark Online (Portal / Remote)">
                                        <i class="fas fa-laptop mr-1"></i> Online
                                    </button>
                                    <button type="button" 
                                            class="att-seg-btn btn-absent <?= $currentStatus === 'absent' ? 'is-active' : '' ?>" 
                                            onclick="setStudentStatus(<?= $sId ?>, 'absent')" 
                                            title="Mark Absent">
                                        <i class="fas fa-times-circle mr-1"></i> Absent
                                    </button>
                                    <button type="button" 
                                            class="att-seg-btn btn-late <?= $currentStatus === 'late' ? 'is-active' : '' ?>" 
                                            onclick="setStudentStatus(<?= $sId ?>, 'late')" 
                                            title="Mark Late Arrival">
                                        <i class="fas fa-clock mr-1"></i> Late
                                    </button>
                                </div>
                                <input type="hidden" name="records[<?= $sId ?>][auto_detected]" value="<?= $isOnlineNow ? '1' : '0' ?>" id="auto_<?= $sId ?>">
                            </td>
                            <td class="align-middle">
                                <input type="text" name="records[<?= $sId ?>][remarks]" class="form-control form-control-sm" placeholder="Optional remark..." value="<?= htmlspecialchars($currentRemarks) ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light py-3 px-4 d-flex justify-content-between align-items-center flex-wrap">
            <span class="text-muted small">
                All records will be permanently saved to the institute's attendance ledger.
            </span>
            <div class="mt-2 mt-md-0 d-flex" style="gap: 10px;">
                <button type="button" class="btn btn-light border font-weight-bold rounded-pill px-4" onclick="window.history.back()">
                    Cancel
                </button>
                <button type="submit" class="btn btn-success font-weight-bold rounded-pill px-5 shadow-sm" id="saveRegisterBtn">
                    <i class="fas fa-save mr-2"></i> Save Attendance Register
                </button>
            </div>
        </div>
    </div>
</form>

<?php endif; ?>

<script>
// Deterministic, row-isolated attendance state manager
function setStudentStatus(studentId, status) {
    studentId = parseInt(studentId, 10);
    if (!studentId) return;

    // 1. Select the exact radio input for this student
    var radio = document.getElementById('status_' + studentId + '_' + status);
    if (radio) {
        radio.checked = true;
    }

    // 2. Update visual active state in this student's segmented control only
    var group = document.getElementById('att-group-' + studentId);
    if (group) {
        var btns = group.getElementsByClassName('att-seg-btn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].classList.remove('is-active');
        }
        var activeBtn = group.querySelector('.btn-' + status);
        if (activeBtn) {
            activeBtn.classList.add('is-active');
        }
    }

    // 3. Re-calculate KPI counters across the register
    updateCounters();
}

function updateCounters() {
    let offline = 0;
    let online = 0;
    let absent = 0;
    let late = 0;

    var radios = document.querySelectorAll('input.att-radio:checked');
    for (var i = 0; i < radios.length; i++) {
        var val = radios[i].value;
        if (val === 'offline') offline++;
        else if (val === 'online') online++;
        else if (val === 'absent') absent++;
        else if (val === 'late') late++;
    }

    $('#kpiOffline').text(offline);
    $('#kpiOnline').text(online);
    $('#kpiAbsent').text(absent);
    $('#kpiLate').text(late);
}

function markAll(status) {
    $('.student-row').each(function() {
        var sId = $(this).data('student-id');
        if (sId) {
            setStudentStatus(sId, status);
        }
    });
}

function autoDetectOnline() {
    let detectedCount = 0;
    $('.student-row').each(function() {
        var sId = $(this).data('student-id');
        var isOnline = $(this).data('is-online') == 1;
        if (isOnline && sId) {
            setStudentStatus(sId, 'online');
            var autoInp = document.getElementById('auto_' + sId);
            if (autoInp) autoInp.value = '1';
            detectedCount++;
        }
    });
    updateCounters();
    if (window.Swal) {
        Swal.fire({
            icon: 'success',
            title: 'Auto-Detection Complete',
            text: detectedCount + ' student(s) currently active on the portal were marked Online.',
            timer: 2000,
            showConfirmButton: false
        });
    } else {
        alert(detectedCount + ' student(s) marked Online.');
    }
}

$(document).ready(function() {
    updateCounters();

    $('#attendanceForm').on('submit', function(e) {
        const $btn = $('#saveRegisterBtn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving Register...');

        // AJAX submit with standard fallback
        $.ajax({
            url: 'save_ajax.php',
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp && resp.success) {
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Attendance Saved!',
                            html: resp.message || 'Attendance register has been recorded successfully.',
                            showCancelButton: resp.count_absent > 0,
                            confirmButtonText: resp.count_absent > 0 ? '<i class="fas fa-bell mr-1"></i> View Absentees & Alert Parents' : 'OK',
                            cancelButtonText: 'Return to Register List',
                            confirmButtonColor: '#28a745',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed && resp.count_absent > 0) {
                                window.location.href = 'absentees.php?register_id=' + resp.register_id;
                            } else {
                                window.location.href = 'index.php?date=' + encodeURIComponent($('input[name="session_date"]').val());
                            }
                        });
                    } else {
                        alert(resp.message || 'Attendance saved successfully!');
                        window.location.href = 'index.php?date=' + encodeURIComponent($('input[name="session_date"]').val());
                    }
                } else {
                    const errMsg = (resp && resp.error) ? resp.error : 'Unable to save attendance register. Please try again.';
                    if (window.Swal) {
                        Swal.fire({ icon: 'error', title: 'Save Failed', text: errMsg });
                    } else {
                        alert('Save Failed: ' + errMsg);
                    }
                    $btn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i> Save Attendance Register');
                }
            },
            error: function(xhr) {
                // If AJAX failed, allow regular form submission as fallback
                console.warn('AJAX save failed, falling back to direct POST submit');
                $('#attendanceForm').off('submit').submit();
            }
        });

        // Prevent default submit unless AJAX error triggers direct fallback
        e.preventDefault();
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

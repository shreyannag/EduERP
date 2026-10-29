<?php
// student/dashboard.php
require_once '../includes/student_header.php';

$student_id = (int)$_SESSION['student_id'];
$now_str = date('Y-m-d H:i:s');

// Helper function for academic grading
if (!function_exists('get_academic_grade')) {
    function get_academic_grade($percentage) {
        if ($percentage >= 90) return ['grade' => 'A+', 'label' => 'Outstanding', 'badge' => 'badge-success'];
        if ($percentage >= 80) return ['grade' => 'A', 'label' => 'Excellent', 'badge' => 'badge-success'];
        if ($percentage >= 70) return ['grade' => 'B+', 'label' => 'Very Good', 'badge' => 'badge-info'];
        if ($percentage >= 60) return ['grade' => 'B', 'label' => 'Good', 'badge' => 'badge-primary'];
        if ($percentage >= 50) return ['grade' => 'C', 'label' => 'Satisfactory', 'badge' => 'badge-warning'];
        if ($percentage >= 40) return ['grade' => 'D', 'label' => 'Pass', 'badge' => 'badge-secondary'];
        return ['grade' => 'F', 'label' => 'Needs Practice', 'badge' => 'badge-danger'];
    }
}

// Auto-finalize any open/unsubmitted attempts for exams that have reached their scheduled end time
try {
    $stmt_open = $pdo->prepare("
        SELECT a.id as attempt_id, a.exam_id, e.scheduled_date, e.scheduled_time, e.duration_minutes
        FROM attempts a
        JOIN exams e ON a.exam_id = e.id
        WHERE a.student_id = ? AND a.is_submitted = 0
    ");
    $stmt_open->execute([$student_id]);
    $open_attempts = $stmt_open->fetchAll();

    foreach ($open_attempts as $oa) {
        $start_dt = $oa['scheduled_date'] . ' ' . $oa['scheduled_time'];
        $end_dt = date('Y-m-d H:i:s', strtotime($start_dt . ' + ' . $oa['duration_minutes'] . ' minutes'));
        
        // If exam scheduled window has ended, auto-finalize the attempt and score it
        if ($now_str > $end_dt) {
            $stmt_calc = $pdo->prepare("
                SELECT COALESCE(SUM(CASE WHEN q.correct_option = ans.chosen_option THEN COALESCE(q.marks, 1) ELSE 0 END), 0) as calc_score
                FROM attempt_answers ans
                JOIN questions q ON ans.question_id = q.id
                WHERE ans.attempt_id = ?
            ");
            $stmt_calc->execute([$oa['attempt_id']]);
            $calc_score = (int)$stmt_calc->fetchColumn();

            $stmt_fin = $pdo->prepare("
                UPDATE attempts 
                SET is_submitted = 1, submitted_at = ?, score = ? 
                WHERE id = ?
            ");
            $stmt_fin->execute([$end_dt, $calc_score, $oa['attempt_id']]);
        }
    }
} catch (\Throwable $e) {
    error_log("Dashboard auto-finalize error: " . $e->getMessage());
}

// Get all mapped exams for this student with attempt metadata
$stmt = $pdo->prepare("
    SELECT e.*, 
    (SELECT COUNT(*) FROM attempts WHERE student_id = ? AND exam_id = e.id AND is_submitted = 1) as has_submitted,
    (SELECT COUNT(*) FROM attempts WHERE student_id = ? AND exam_id = e.id AND started_at IS NOT NULL) as has_started,
    (SELECT COUNT(*) FROM attempts WHERE student_id = ? AND exam_id = e.id AND is_submitted = 1) as has_attempted,
    (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) as key_released
    FROM exams e
    JOIN enrolments en ON e.id = en.exam_id
    WHERE en.student_id = ? AND e.status != 'draft'
    ORDER BY e.scheduled_date ASC, e.scheduled_time ASC
");
$stmt->execute([$student_id, $student_id, $student_id, $student_id]);
$assigned_exams = $stmt->fetchAll();

$upcoming = [];
$active = [];
$completed = [];

$now = date('Y-m-d H:i:s');
foreach($assigned_exams as $ex) {
    $start_datetime = $ex['scheduled_date'] . ' ' . $ex['scheduled_time'];
    $end_datetime = date('Y-m-d H:i:s', strtotime($start_datetime . ' + ' . $ex['duration_minutes'] . ' minutes'));

    if ($ex['has_submitted'] || ($ex['has_started'] && $now > $end_datetime)) {
        $completed[] = $ex;
        continue;
    }
    
    if ($ex['status'] === 'completed' || $now > $end_datetime) {
        $completed[] = $ex; // Missed or ended exams default to trace sequence bounds
    } elseif ($now >= $start_datetime && $now <= $end_datetime) {
        $active[] = $ex; // Currently unrolling state
    } else {
        $upcoming[] = $ex; // Scheduled bound
    }
}

// Performance Metrics Calculation (All evaluated exams with published keys)
$stmt_perf = $pdo->prepare("
    SELECT a.score, 
    COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.student_id = ? AND a.is_submitted = 1
      AND (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) > 0
");
$stmt_perf->execute([$student_id]);
$perf_rows = $stmt_perf->fetchAll();
$total_evaluated = count($perf_rows);
$evaluated_pcts = [];
foreach ($perf_rows as $pr) {
    $tm = !empty($pr['total_marks']) && $pr['total_marks'] > 0 ? (int)$pr['total_marks'] : 1;
    $evaluated_pcts[] = round(($pr['score'] / $tm) * 100, 1);
}
$overall_avg = $total_evaluated > 0 ? round(array_sum($evaluated_pcts) / $total_evaluated, 1) : 0;
$overall_grade = get_academic_grade($overall_avg);

// Fetch recent 4 graded/submitted exams for quick snapshot
$stmt_recent = $pdo->prepare("
    SELECT a.id as attempt_id, a.score, a.total_questions, a.submitted_at, 
           e.id as exam_id, e.title, e.scheduled_date, e.exam_type,
           (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) as key_released,
           COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.student_id = ? AND a.is_submitted = 1
    ORDER BY COALESCE(a.submitted_at, e.scheduled_date) DESC
    LIMIT 4
");
$stmt_recent->execute([$student_id]);
$recent_results = $stmt_recent->fetchAll();

// Count pending homework
$pending_hw_count = 0;
try {
    $class_id = (int)($current_student['class_id'] ?? 0);
    $board_id = (int)($current_student['board_id'] ?? 0);
    $stmt_hw = $pdo->prepare("
        SELECT COUNT(hw.id)
        FROM homework hw
        LEFT JOIN homework_submissions s ON s.homework_id = hw.id AND s.student_id = ?
        WHERE (hw.admin_id = ? OR hw.admin_id = 1)
          AND hw.is_active = 1
          AND s.id IS NULL
          AND (
              (hw.batch_id IS NOT NULL AND hw.batch_id IN (SELECT batch_id FROM batch_students WHERE student_id = ?))
              OR
              (hw.batch_id IS NULL AND (hw.class_id IS NULL OR hw.class_id = ?) AND (hw.board_id IS NULL OR hw.board_id = ?))
          )
    ");
    $stmt_hw->execute([$student_id, $student_admin_id, $student_id, $class_id, $board_id]);
    $pending_hw_count = (int)$stmt_hw->fetchColumn();
} catch (\Throwable $e) {
    $pending_hw_count = 0;
}

// Time-sensitive greeting
$hour = (int)date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = "Good Morning";
    $greeting_icon = "fas fa-sun text-warning";
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = "Good Afternoon";
    $greeting_icon = "fas fa-cloud-sun text-warning";
} else {
    $greeting = "Good Evening";
    $greeting_icon = "fas fa-moon text-info";
}

// Inspirational Student Quotes
$motivational_quotes = [
    "“Success is the sum of small efforts repeated day in and day out.”",
    "“The expert in anything was once a beginner. Keep learning!”",
    "“Education is your passport to the future. Make today count!”",
    "“Believe you can and you’re halfway there. Focus on your goals.”",
    "“Every examination is an opportunity to showcase your knowledge.”"
];
$quote_of_the_day = $motivational_quotes[abs(crc32(date('Y-m-d'))) % count($motivational_quotes)];

$first_name = explode(' ', trim($current_student['full_name'] ?? 'Student'))[0];

$active_tab_request = $_GET['tab'] ?? '';
if (!in_array($active_tab_request, ['active', 'upcoming', 'completed'])) {
    $active_tab_request = (!empty($_GET['submitted'])) ? 'completed' : 'active';
}
?>

<!-- 1. Hero / Welcome Banner -->
<div class="student-hero-banner p-4 p-md-5 mb-4 position-relative">
    <div class="row align-items-center">
        <div class="col-lg-8">
            <div class="d-inline-flex align-items-center bg-white bg-opacity-20 px-3 py-1 rounded-pill mb-3" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                <i class="<?php echo $greeting_icon; ?> mr-2"></i>
                <span class="font-weight-bold small text-white text-uppercase" style="letter-spacing: 0.5px;"><?php echo $greeting; ?></span>
            </div>
            
            <h1 class="display-5 font-weight-bold mb-2" style="letter-spacing: -0.5px;">
                Welcome back, <?php echo h($first_name); ?>! 👋
            </h1>
            
            <p class="lead mb-4 text-white-50" style="font-size: 1.05rem; max-width: 650px;">
                <?php echo $quote_of_the_day; ?>
            </p>
            
            <div class="d-flex flex-wrap align-items-center">
                <span class="badge badge-light px-3 py-2 rounded-pill font-weight-bold text-primary mr-2 mb-2 shadow-sm">
                    <i class="fas fa-university mr-1"></i><?php echo h($current_student['institute_name'] ?? 'Main Campus'); ?>
                </span>
                <span class="badge badge-light px-3 py-2 rounded-pill font-weight-bold text-dark mr-2 mb-2 shadow-sm">
                    <i class="fas fa-graduation-cap mr-1 text-primary"></i>Class <?php echo h($current_student['class_name'] ?? ''); ?> • <?php echo h($current_student['board_name'] ?? ''); ?>
                </span>
                <?php if (!empty($current_student['roll_no'])): ?>
                    <span class="badge badge-light px-3 py-2 rounded-pill font-weight-bold text-dark mr-2 mb-2 shadow-sm">
                        <i class="fas fa-id-badge mr-1 text-info"></i>Roll No: <?php echo h($current_student['roll_no']); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="col-lg-4 text-lg-right mt-4 mt-lg-0 d-none d-lg-block">
            <div class="p-3 rounded-lg d-inline-block text-left" style="background: rgba(255,255,255,0.14); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.25); min-width: 240px;">
                <div class="small text-white-50 text-uppercase font-weight-bold mb-1" style="letter-spacing: 0.5px;">Portal Overview</div>
                <div class="h5 font-weight-bold text-white mb-2">
                    <?php echo count($active) > 0 ? '<span class="text-warning"><i class="fas fa-circle mr-1 animate-pulse" style="font-size:0.7rem;"></i>' . count($active) . ' Live Exam Now</span>' : '<span class="text-light"><i class="fas fa-shield-alt mr-1"></i>All Caught Up</span>'; ?>
                </div>
                <a href="report_card.php" class="btn btn-sm btn-light text-primary font-weight-bold rounded-pill px-3 shadow-sm btn-block">
                    <i class="fas fa-chart-line mr-1"></i>Open My Report Card
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Flash Notifications -->
<?php if (!empty($_SESSION['flash_success']) || !empty($_GET['submitted'])): ?>
    <div class="alert alert-success alert-dismissible fade show rounded-lg shadow-sm border-0 font-weight-500 mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle fa-2x mr-3 text-success"></i>
            <div>
                <strong class="d-block" style="font-size:1.05rem;">Exam Submitted Successfully!</strong>
                <span><?php echo h($_SESSION['flash_success'] ?? 'Your responses have been recorded safely. You can review your submission below.'); ?></span>
            </div>
        </div>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_info'])): ?>
    <div class="alert alert-info alert-dismissible fade show rounded-lg shadow-sm border-0 font-weight-500 mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-info-circle fa-2x mr-3 text-info"></i>
            <div>
                <span><?php echo h($_SESSION['flash_info']); ?></span>
            </div>
        </div>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['flash_info']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show rounded-lg shadow-sm border-0 font-weight-500 mb-4" role="alert">
        <div class="d-flex align-items-center">
            <i class="fas fa-exclamation-triangle fa-2x mr-3 text-danger"></i>
            <div>
                <span><?php echo h($_SESSION['flash_error']); ?></span>
            </div>
        </div>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- 2. Student Metric KPI Strip -->
<div class="row mb-4">
    <!-- Average Academic Score -->
    <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
        <div class="student-kpi-card p-4">
            <div class="d-flex align-items-center mb-3">
                <div class="student-kpi-icon bg-primary bg-opacity-10 text-primary mr-3" style="background: rgba(37, 99, 235, 0.1);">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div>
                    <span class="text-muted small font-weight-bold text-uppercase d-block" style="letter-spacing: 0.5px;">Academic Score</span>
                    <h3 class="font-weight-bold text-dark mb-0">
                        <?php echo $total_evaluated > 0 ? $overall_avg . '%' : 'N/A'; ?>
                    </h3>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <span class="badge <?php echo $total_evaluated > 0 ? $overall_grade['badge'] : 'badge-light'; ?> px-2 py-1 rounded-pill font-weight-bold">
                    <?php echo $total_evaluated > 0 ? 'Grade ' . $overall_grade['grade'] . ' • ' . $overall_grade['label'] : 'Awaiting 1st test'; ?>
                </span>
                <a href="report_card.php" class="small font-weight-bold text-primary">Details &rarr;</a>
            </div>
        </div>
    </div>
    
    <!-- Completed Exams -->
    <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
        <div class="student-kpi-card p-4">
            <div class="d-flex align-items-center mb-3">
                <div class="student-kpi-icon bg-success bg-opacity-10 text-success mr-3" style="background: rgba(16, 185, 129, 0.1);">
                    <i class="fas fa-check-double"></i>
                </div>
                <div>
                    <span class="text-muted small font-weight-bold text-uppercase d-block" style="letter-spacing: 0.5px;">Completed Tests</span>
                    <h3 class="font-weight-bold text-dark mb-0">
                        <?php echo count($completed); ?>
                    </h3>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top small text-muted font-weight-bold">
                <span><?php echo count($upcoming); ?> Upcoming</span>
                <span class="text-<?php echo count($active) > 0 ? 'danger font-weight-bold' : 'muted'; ?>"><?php echo count($active); ?> Live Now</span>
            </div>
        </div>
    </div>

    <!-- Pending Homework -->
    <div class="col-sm-6 col-lg-3 mb-3 mb-sm-0">
        <div class="student-kpi-card p-4">
            <div class="d-flex align-items-center mb-3">
                <div class="student-kpi-icon bg-warning bg-opacity-10 text-warning mr-3" style="background: rgba(245, 158, 11, 0.1);">
                    <i class="fas fa-book-reader"></i>
                </div>
                <div>
                    <span class="text-muted small font-weight-bold text-uppercase d-block" style="letter-spacing: 0.5px;">Homework</span>
                    <h3 class="font-weight-bold text-dark mb-0">
                        <?php echo $pending_hw_count > 0 ? $pending_hw_count . ' Pending' : '0 Due'; ?>
                    </h3>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <span class="badge <?php echo $pending_hw_count > 0 ? 'badge-warning' : 'badge-success'; ?> px-2 py-1 rounded-pill font-weight-bold">
                    <?php echo $pending_hw_count > 0 ? 'Action Required' : 'All Clear! 🎉'; ?>
                </span>
                <a href="homework.php" class="small font-weight-bold text-primary">View Tasks &rarr;</a>
            </div>
        </div>
    </div>

    <!-- Teacher Announcements -->
    <div class="col-sm-6 col-lg-3">
        <div class="student-kpi-card p-4">
            <div class="d-flex align-items-center mb-3">
                <div class="student-kpi-icon bg-info bg-opacity-10 text-info mr-3" style="background: rgba(14, 165, 233, 0.1);">
                    <i class="fas fa-envelope-open-text"></i>
                </div>
                <div>
                    <span class="text-muted small font-weight-bold text-uppercase d-block" style="letter-spacing: 0.5px;">Messages</span>
                    <h3 class="font-weight-bold text-dark mb-0">
                        <?php echo (int)$unread_msg_count > 0 ? (int)$unread_msg_count . ' New' : 'Inbox Clear'; ?>
                    </h3>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <span class="badge <?php echo $unread_msg_count > 0 ? 'badge-danger' : 'badge-light'; ?> px-2 py-1 rounded-pill font-weight-bold">
                    <?php echo $unread_msg_count > 0 ? $unread_msg_count . ' Unread' : 'No new notices'; ?>
                </span>
                <a href="messages.php" class="small font-weight-bold text-primary">Chat &rarr;</a>
            </div>
        </div>
    </div>
</div>

<!-- 3. Student Quick Action Navigation Hub -->
<div class="mb-5">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-compass text-primary mr-2"></i>Quick Access & Student Services
        </h5>
    </div>
    
    <div class="row">
        <!-- Report Card & Results -->
        <div class="col-6 col-md-4 col-lg mb-3">
            <a href="report_card.php" class="student-action-card">
                <div class="action-icon bg-primary bg-opacity-10 text-primary" style="background: rgba(37, 99, 235, 0.1);">
                    <i class="fas fa-award"></i>
                </div>
                <div>
                    <strong class="d-block text-dark font-weight-bold" style="font-size: 0.98rem;">Report Card</strong>
                    <span class="text-muted small d-none d-sm-inline">Scores & Answer Keys</span>
                </div>
            </a>
        </div>
        
        <!-- Homework -->
        <div class="col-6 col-md-4 col-lg mb-3">
            <a href="homework.php" class="student-action-card">
                <div class="action-icon bg-warning bg-opacity-10 text-warning" style="background: rgba(245, 158, 11, 0.1);">
                    <i class="fas fa-book-reader"></i>
                </div>
                <div>
                    <strong class="d-block text-dark font-weight-bold" style="font-size: 0.98rem;">Homework</strong>
                    <span class="text-muted small d-none d-sm-inline">Submit Assignments</span>
                </div>
            </a>
        </div>
        
        <!-- Messages -->
        <div class="col-6 col-md-4 col-lg mb-3">
            <a href="messages.php" class="student-action-card">
                <div class="action-icon bg-info bg-opacity-10 text-info" style="background: rgba(14, 165, 233, 0.1);">
                    <i class="fas fa-comments"></i>
                </div>
                <div>
                    <strong class="d-block text-dark font-weight-bold" style="font-size: 0.98rem;">Messages</strong>
                    <span class="text-muted small d-none d-sm-inline">Teacher Notices</span>
                </div>
            </a>
        </div>

        <!-- Fees -->
        <div class="col-6 col-md-4 col-lg mb-3">
            <a href="fees.php" class="student-action-card">
                <div class="action-icon bg-success bg-opacity-10 text-success" style="background: rgba(16, 185, 129, 0.1);">
                    <i class="fas fa-receipt"></i>
                </div>
                <div>
                    <strong class="d-block text-dark font-weight-bold" style="font-size: 0.98rem;">Fees Ledger</strong>
                    <span class="text-muted small d-none d-sm-inline">Receipts & Invoices</span>
                </div>
            </a>
        </div>

        <?php if ($coding_platform_active): ?>
        <!-- Coding Arena -->
        <div class="col-6 col-md-4 col-lg mb-3">
            <a href="coding/index.php" class="student-action-card">
                <div class="action-icon bg-purple bg-opacity-10 text-purple" style="background: rgba(124, 58, 237, 0.1); color: #7c3aed;">
                    <i class="fas fa-laptop-code"></i>
                </div>
                <div>
                    <strong class="d-block text-dark font-weight-bold" style="font-size: 0.98rem;">Coding Arena</strong>
                    <span class="text-muted small d-none d-sm-inline">Run Code Live</span>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 4. Recent Examination Results & Performance Snapshot -->
<div class="card border-0 shadow-sm rounded-lg bg-white mb-5 overflow-hidden">
    <div class="card-header bg-white border-bottom pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-trophy text-warning mr-2"></i>Recent Examination Results
            </h5>
            <span class="small text-muted">Latest academic evaluations across regular examinations and weekly mock tests</span>
        </div>
        <a href="report_card.php" class="btn btn-sm btn-outline-primary rounded-pill font-weight-bold px-3 py-1 shadow-sm">
            View All in Report Card &rarr;
        </a>
    </div>
    
    <div class="card-body p-0">
        <?php if (!empty($recent_results)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="border-0 px-4 py-3">Exam Name</th>
                            <th class="border-0 text-center py-3">Category</th>
                            <th class="border-0 text-center py-3">Date</th>
                            <th class="border-0 text-center py-3">Score & Percentage</th>
                            <th class="border-0 text-right px-4 py-3">Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_results as $r): 
                            $total_marks = !empty($r['total_marks']) && $r['total_marks'] > 0 
                                ? (int)$r['total_marks'] 
                                : (!empty($r['total_questions']) ? (int)$r['total_questions'] : 1);
                            $pct = round(($r['score'] / $total_marks) * 100, 1);
                            $pct = min(100, max(0, $pct));
                            $is_released = !empty($r['key_released']);
                            $is_mock = ($r['exam_type'] === 'weekly_mock');
                        ?>
                            <tr>
                                <td class="align-middle px-4 py-3">
                                    <div class="font-weight-bold text-dark"><?php echo h($r['title']); ?></div>
                                    <div class="small text-muted"><i class="fas fa-question-circle mr-1"></i><?php echo $r['total_questions']; ?> Total Questions</div>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if ($is_mock): ?>
                                        <span class="badge badge-purple px-2 py-1 rounded-pill font-weight-bold small">
                                            <i class="fas fa-calendar-week mr-1"></i>Weekly Mock
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-primary px-2 py-1 rounded-pill font-weight-bold small">
                                            <i class="fas fa-graduation-cap mr-1"></i>Regular Exam
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-center py-3 text-muted small font-weight-500">
                                    <?php echo date('d M Y', strtotime($r['scheduled_date'])); ?>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if ($is_released): ?>
                                        <span class="font-weight-bold text-dark mr-2"><?php echo $r['score']; ?> / <?php echo $total_marks; ?></span>
                                        <span class="badge <?php echo $pct >= 75 ? 'badge-success' : ($pct >= 40 ? 'badge-primary' : 'badge-danger'); ?> px-2 py-1 rounded-pill font-weight-bold">
                                            <?php echo $pct; ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning px-3 py-1 rounded-pill font-weight-bold small">
                                            <i class="fas fa-hourglass-half mr-1"></i>Recorded (Pending Key)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-right px-4 py-3">
                                    <?php if ($is_released): ?>
                                        <a href="review.php?exam_id=<?php echo $r['exam_id']; ?>" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill shadow-sm px-3 py-1">
                                            <i class="fas fa-unlock mr-1"></i>View Solutions
                                        </a>
                                    <?php else: ?>
                                        <a href="review.php?exam_id=<?php echo $r['exam_id']; ?>" class="btn btn-sm btn-outline-secondary font-weight-bold rounded-pill shadow-sm px-3 py-1">
                                            <i class="fas fa-eye mr-1"></i>View Submission
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center text-muted p-5">
                <i class="fas fa-award fa-3x mb-3 text-primary opacity-25"></i>
                <h5 class="font-weight-bold text-dark">No Test Records Yet</h5>
                <p class="mb-0 text-muted">Complete assigned examinations to view performance breakdowns and solutions here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 5. Examination Center (Tabs: Live, Upcoming, Completed) -->
<div class="mb-4 d-flex justify-content-between align-items-center">
    <div>
        <h4 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-laptop-code text-primary mr-2"></i>Examination Center
        </h4>
        <span class="text-muted small">Access scheduled tests, review ongoing assessments, and inspect past performance</span>
    </div>
</div>

<style>
#dashboardTabs {
    background: #f1f5f9;
    padding: 6px;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
}
#dashboardTabs .nav-link {
    color: #475569 !important;
    font-weight: 600 !important;
    border-radius: 999px;
    border: none;
    padding: 0.65rem 1.4rem !important;
    transition: background-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease !important;
    background: transparent;
}
#dashboardTabs .nav-link:hover {
    color: #0f172a !important;
    background: rgba(255, 255, 255, 0.8);
}
#dashboardTabs .nav-link.active {
    background: #1e3a8a !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25) !important;
    font-weight: 600 !important;
}
#dashboardTabs .nav-link.active i {
    color: #ffffff !important;
}
#dashboardTabs .nav-link.active .badge {
    background-color: rgba(255, 255, 255, 0.25) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.4) !important;
}
#dashboardTabContent {
    min-height: 280px;
    position: relative;
}
@media (max-width: 767.98px) {
    #dashboardTabs {
        border-radius: 12px !important;
        padding: 4px;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        white-space: nowrap;
    }
    #dashboardTabs .nav-item {
        flex: 0 0 auto;
    }
    #dashboardTabs .nav-link {
        font-size: 0.85rem;
        padding: 8px 14px !important;
    }
}
</style>

<ul class="nav nav-pills nav-pills-scrollable nav-fill mb-4 rounded-pill shadow-sm" id="dashboardTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo ($active_tab_request === 'active') ? 'active' : ''; ?> font-weight-bold rounded-pill px-4" id="active-tab" data-toggle="pill" href="#active" role="tab">
            <i class="fas fa-play-circle mr-2 <?php echo count($active)>0 ? 'text-danger animate-pulse' : 'text-primary'; ?>"></i>Live Exams
            <?php if(count($active)>0): ?><span class="badge badge-danger ml-2 px-2 py-1 rounded-pill animate-pulse"><?php echo count($active); ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active_tab_request === 'upcoming') ? 'active' : ''; ?> font-weight-bold rounded-pill px-4" id="upcoming-tab" data-toggle="pill" href="#upcoming" role="tab">
            <i class="fas fa-calendar-alt mr-2 text-info opacity-75"></i>Upcoming Exams
            <span class="badge badge-light border text-muted ml-2 px-2 py-1 rounded-pill"><?php echo count($upcoming); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($active_tab_request === 'completed') ? 'active' : ''; ?> font-weight-bold rounded-pill px-4" id="completed-tab" data-toggle="pill" href="#completed" role="tab">
            <i class="fas fa-check-double mr-2 text-success opacity-75"></i>Completed Exams
            <?php if(count($completed)>0): ?><span class="badge badge-light border text-muted ml-2 px-2 py-1 rounded-pill"><?php echo count($completed); ?></span><?php endif; ?>
        </a>
    </li>
</ul>

<div class="tab-content" id="dashboardTabContent">
    
    <!-- 1. Active Exams Layer -->
    <div class="tab-pane fade <?php echo ($active_tab_request === 'active') ? 'show active' : ''; ?>" id="active" role="tabpanel">
        <?php if(empty($active)): ?>
            <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
                <div class="card-body py-5">
                    <div class="mb-4">
                        <span class="d-inline-flex p-3 rounded-circle bg-light text-primary">
                            <i class="fas fa-coffee fa-3x"></i>
                        </span>
                    </div>
                    <h4 class="font-weight-bold text-dark">No Live Exams Right Now</h4>
                    <p class="text-muted mb-0" style="max-width: 500px; margin: 0 auto;">
                        You have no assessments currently running. Take a breather, check your upcoming tests, or review previous solution keys in your Report Card!
                    </p>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($active as $ex): 
                    $is_mock = ($ex['exam_type'] === 'weekly_mock');
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card border-0 shadow rounded-lg h-100 border-top border-danger bg-white" style="border-top-width: 4px !important;">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge badge-danger px-3 py-1 font-weight-bold rounded-pill animate-pulse">
                                    <i class="fas fa-circle mr-1" style="font-size:0.6rem;"></i> Live Assessment
                                </span>
                                <span class="text-muted small font-weight-bold"><i class="fas fa-hourglass-half mr-1"></i><?php echo $ex['duration_minutes']; ?> Mins</span>
                            </div>
                            
                            <h5 class="font-weight-bold text-dark mb-2"><?php echo h($ex['title']); ?></h5>
                            <div class="mb-3">
                                <?php if ($is_mock): ?>
                                    <span class="badge badge-purple px-2 py-1 rounded-pill small font-weight-bold">Weekly Mock</span>
                                <?php else: ?>
                                    <span class="badge badge-primary px-2 py-1 rounded-pill small font-weight-bold">Regular Exam</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="bg-light rounded p-3 mb-4 mt-auto border">
                                <p class="mb-1 small font-weight-bold text-muted text-uppercase">Window Closes Strictly At</p>
                                <p class="mb-0 font-weight-bold text-dark">
                                    <?php 
                                    $end_dt = date('H:i', strtotime($ex['scheduled_date'].' '.$ex['scheduled_time'].' + '.$ex['duration_minutes'].' minutes'));
                                    echo date('d M Y', strtotime($ex['scheduled_date'])) . ' at ' . $end_dt; 
                                    ?>
                                </p>
                            </div>
                            
                            <a href="exam.php?id=<?php echo $ex['id']; ?>" class="btn btn-danger btn-lg font-weight-bold px-4 py-3 shadow rounded-pill btn-block transition" onclick="return confirm('Are you sure you want to begin this exam now? The countdown timer will start immediately.');">
                                Enter Exam Room <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- 2. Upcoming Exams Layer -->
    <div class="tab-pane fade <?php echo ($active_tab_request === 'upcoming') ? 'show active' : ''; ?>" id="upcoming" role="tabpanel">
        <?php if(empty($upcoming)): ?>
            <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
                <div class="card-body py-5">
                    <div class="mb-4">
                        <span class="d-inline-flex p-3 rounded-circle bg-light text-info">
                            <i class="fas fa-calendar-check fa-3x"></i>
                        </span>
                    </div>
                    <h4 class="font-weight-bold text-dark">No Upcoming Exams</h4>
                    <p class="text-muted mb-0">You have no upcoming tests scheduled at the moment. Keep practicing!</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($upcoming as $ex): 
                    $is_mock = ($ex['exam_type'] === 'weekly_mock');
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card border-0 shadow-sm rounded-lg h-100 border-top border-primary bg-white" style="border-top-width: 4px !important;">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <?php if ($is_mock): ?>
                                    <span class="badge badge-purple px-2 py-1 rounded-pill small font-weight-bold">Weekly Mock</span>
                                <?php else: ?>
                                    <span class="badge badge-primary px-2 py-1 rounded-pill small font-weight-bold">Regular Exam</span>
                                <?php endif; ?>
                                <span class="badge badge-light border text-muted font-weight-bold">Scheduled</span>
                            </div>

                            <h5 class="font-weight-bold text-dark mb-4 border-bottom pb-3"><?php echo h($ex['title']); ?></h5>
                            
                            <div class="mb-4 text-muted bg-light p-3 rounded border">
                                <p class="mb-2"><i class="fas fa-calendar-alt mr-2 text-primary opacity-75 fa-fw"></i> Date: <strong class="text-dark"><?php echo date('d M Y', strtotime($ex['scheduled_date'])); ?></strong></p>
                                <p class="mb-2"><i class="fas fa-clock mr-2 text-primary opacity-75 fa-fw"></i> Time: <strong class="text-dark"><?php echo date('H:i', strtotime($ex['scheduled_time'])); ?></strong></p>
                                <p class="mb-0"><i class="fas fa-hourglass-start mr-2 text-primary opacity-75 fa-fw"></i> Duration: <strong class="text-dark"><?php echo $ex['duration_minutes']; ?> mins</strong></p>
                            </div>
                            
                            <button class="btn btn-light font-weight-bold px-4 py-3 border-2 rounded-pill btn-block text-muted disabled mt-auto shadow-sm" style="cursor:not-allowed;" title="You cannot start this exam yet.">
                                <i class="fas fa-lock mr-2"></i>Unlocks on Schedule
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- 3. Completed Exams Layer -->
    <div class="tab-pane fade <?php echo ($active_tab_request === 'completed') ? 'show active' : ''; ?>" id="completed" role="tabpanel">
        <?php if(empty($completed)): ?>
            <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
                <div class="card-body py-5">
                    <i class="fas fa-archive fa-4x text-light mb-4 opacity-50"></i>
                    <h4 class="font-weight-bold text-dark">No Completed Exams Yet</h4>
                    <p class="text-muted mb-0">Your completed examinations and performance transcripts will appear here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-lg overflow-hidden bg-white">
                <div class="table-responsive p-3">
                    <table class="table table-hover table-bordered mb-0">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th class="border-0 px-4 py-3 rounded-top-left">Exam Title</th>
                                <th class="border-0 text-center py-3">Category</th>
                                <th class="border-0 text-center py-3">Date</th>
                                <th class="border-0 text-center py-3">Status</th>
                                <th class="border-0 text-right px-4 py-3 rounded-top-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($completed as $ex): 
                                $is_mock = ($ex['exam_type'] === 'weekly_mock');
                            ?>
                            <tr>
                                <td class="align-middle font-weight-bold text-dark px-4 py-3">
                                    <?php echo h($ex['title']); ?>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if ($is_mock): ?>
                                        <span class="badge badge-purple px-2 py-1 rounded-pill font-weight-bold small">
                                            Weekly Mock
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-primary px-2 py-1 rounded-pill font-weight-bold small">
                                            Regular Exam
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-center text-muted font-weight-500 py-3">
                                    <i class="fas fa-calendar-check mr-2 opacity-50"></i><?php echo date('d M Y', strtotime($ex['scheduled_date'])); ?>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if($ex['has_submitted']): ?>
                                        <span class="badge badge-success px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-check-circle mr-1"></i>Submitted</span>
                                    <?php elseif($ex['has_started']): ?>
                                        <span class="badge badge-info px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-history mr-1"></i>Auto-Finalized</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-times-circle mr-1"></i>Missed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-right px-4 py-3">
                                    <?php if($ex['has_submitted'] || $ex['has_started']): ?>
                                        <?php if($ex['key_released']): ?>
                                            <a href="review.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill shadow-sm px-4 py-2 border-2 transition">
                                                <i class="fas fa-file-invoice mr-2"></i>View Key & Review
                                            </a>
                                        <?php else: ?>
                                            <a href="review.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill shadow-sm px-4 py-2 border-2 transition">
                                                <i class="fas fa-hourglass-half mr-2"></i>View Submission
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small font-weight-bold font-italic"><i class="fas fa-ban mr-1 opacity-50"></i>Missed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function(){
    var urlParams = new URLSearchParams(window.location.search);
    var targetTab = urlParams.get('tab');
    if (targetTab && $('#' + targetTab + '-tab').length) {
        $('#' + targetTab + '-tab').tab('show');
        localStorage.setItem('studentDashboardTabNavMappingPoint', '#' + targetTab);
    } else {
        var lastTab = localStorage.getItem('studentDashboardTabNavMappingPoint');
        if (lastTab && $('#dashboardTabs a[href="' + lastTab + '"]').length) {
            $('#dashboardTabs a[href="' + lastTab + '"]').tab('show');
        }
    }

    $('#dashboardTabs a[data-toggle="pill"]').on('shown.bs.tab', function (e) {
        localStorage.setItem('studentDashboardTabNavMappingPoint', $(e.target).attr('href'));
    });
});
</script>

<?php require_once '../includes/student_footer.php'; ?>

<?php
// student/report_card.php
require_once '../includes/student_header.php';

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

// Filter support: 'all' (default), 'regular', 'weekly_mock'
$type_filter = $_GET['type'] ?? 'all';
if (!in_array($type_filter, ['all', 'regular', 'weekly_mock'])) {
    $type_filter = 'all';
}

// Fetch all counts by exam type for this student
$stmt_cnt = $pdo->prepare("
    SELECT e.exam_type, COUNT(a.id) as cnt 
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.student_id = ? AND a.is_submitted = 1
    GROUP BY e.exam_type
");
$stmt_cnt->execute([$student_id]);
$cnt_rows = $stmt_cnt->fetchAll();
$count_mock = 0;
$count_regular = 0;
foreach ($cnt_rows as $cr) {
    if ($cr['exam_type'] === 'weekly_mock') {
        $count_mock = (int)$cr['cnt'];
    } else {
        $count_regular += (int)$cr['cnt'];
    }
}
$count_all = $count_mock + $count_regular;

// Fetch examination results matching the selected filter
$sql = "
    SELECT a.id as attempt_id, a.score, a.total_questions, a.submitted_at, 
           e.id as exam_id, e.title, e.scheduled_date, e.scheduled_time, e.duration_minutes, e.exam_type,
           (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) as key_released,
           COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.student_id = ? AND a.is_submitted = 1
";

if ($type_filter === 'regular') {
    $sql .= " AND (e.exam_type = 'regular' OR e.exam_type IS NULL)";
} elseif ($type_filter === 'weekly_mock') {
    $sql .= " AND e.exam_type = 'weekly_mock'";
}
$sql .= " ORDER BY e.scheduled_date ASC, e.scheduled_time ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$student_id]);
$exam_results = $stmt->fetchAll();

// Extract evaluated data for chart & aggregate performance metrics
$chart_labels = [];
$chart_percentages = [];
$evaluated_percentages = [];
$evaluated_count = 0;

foreach ($exam_results as $res) {
    if ($res['key_released'] > 0) {
        $total_marks = !empty($res['total_marks']) && $res['total_marks'] > 0 
            ? (int)$res['total_marks'] 
            : (!empty($res['total_questions']) ? (int)$res['total_questions'] : 1);
        $pct = round(($res['score'] / $total_marks) * 100, 1);
        $pct = min(100, max(0, $pct));
        
        $chart_labels[] = date('M d', strtotime($res['scheduled_date'])) . ' - ' . (mb_strlen($res['title']) > 18 ? mb_substr($res['title'], 0, 18) . '...' : $res['title']);
        $chart_percentages[] = $pct;
        $evaluated_percentages[] = $pct;
        $evaluated_count++;
    }
}

$total_attempts = count($exam_results);
$avg_pct = $evaluated_count > 0 ? round(array_sum($evaluated_percentages) / $evaluated_count, 1) : 0;
$highest_pct = $evaluated_count > 0 ? max($evaluated_percentages) : 0;
$overall_standing = get_academic_grade($avg_pct);
?>

<!-- Header & Action Bar -->
<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-award text-primary mr-2"></i>Academic Report Card & Results
        </h2>
        <p class="text-muted mb-0">
            Comprehensive evaluation record, performance trends, and official answer keys.
        </p>
    </div>
    <div class="mt-3 mt-sm-0">
        <button class="btn btn-outline-primary rounded-pill shadow-sm font-weight-bold px-4" onclick="window.print()">
            <i class="fas fa-print mr-2"></i>Print Official Report
        </button>
    </div>
</div>

<!-- Student Identity Header (Enhanced for Screen & Clean Printout) -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-white overflow-hidden print-identity-card">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8 d-flex align-items-center mb-3 mb-md-0">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-3 shadow font-weight-bold flex-shrink-0" style="width: 58px; height: 58px; font-size: 24px;">
                    <?php echo strtoupper(substr($current_student['full_name'] ?? 'S', 0, 1)); ?>
                </div>
                <div>
                    <h4 class="font-weight-bold text-dark mb-1"><?php echo h($current_student['full_name'] ?? 'Student'); ?></h4>
                    <div class="text-muted small">
                        <span class="mr-3"><i class="fas fa-university text-primary mr-1"></i><?php echo h($current_student['institute_name'] ?? 'Main Campus'); ?></span>
                        <span class="mr-3"><i class="fas fa-graduation-cap text-primary mr-1"></i>Class <?php echo h($current_student['class_name'] ?? ''); ?> • <?php echo h($current_student['board_name'] ?? ''); ?></span>
                        <?php if (!empty($current_student['roll_no'])): ?>
                            <span><i class="fas fa-id-badge text-primary mr-1"></i>Roll: <strong><?php echo h($current_student['roll_no']); ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-md-right text-muted small">
                <div><i class="fas fa-calendar-day mr-1 text-primary"></i>Report Generated: <strong><?php echo date('d M Y, h:i A'); ?></strong></div>
                <div><i class="fas fa-check-shield text-success mr-1"></i>Verified Academic Transcript</div>
            </div>
        </div>
    </div>
</div>

<!-- Top Summary Performance KPI Strip -->
<div class="row mb-4">
    <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 bg-primary text-white">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-check-circle fa-2x mb-2 opacity-75"></i>
                <h6 class="text-white-50 font-weight-bold text-uppercase mb-1 small" style="letter-spacing: 0.5px;">Evaluated Tests</h6>
                <h2 class="display-4 font-weight-bold mb-0"><?php echo $evaluated_count; ?></h2>
                <span class="small text-white-50 mt-1"><?php echo $total_attempts; ?> Total Attempts</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3 mb-3 mb-lg-0">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 bg-success text-white">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-chart-line fa-2x mb-2 opacity-75"></i>
                <h6 class="text-white-50 font-weight-bold text-uppercase mb-1 small" style="letter-spacing: 0.5px;">Cumulative Average</h6>
                <h2 class="display-4 font-weight-bold mb-0"><?php echo $evaluated_count > 0 ? $avg_pct . '%' : 'N/A'; ?></h2>
                <span class="small text-white-50 mt-1">
                    <?php echo $evaluated_count > 0 ? 'Grade ' . $overall_standing['grade'] . ' (' . $overall_standing['label'] . ')' : 'Awaiting published scores'; ?>
                </span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3 mb-3 mb-sm-0">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 bg-info text-white">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-trophy fa-2x mb-2 opacity-75"></i>
                <h6 class="text-white-50 font-weight-bold text-uppercase mb-1 small" style="letter-spacing: 0.5px;">Highest Score</h6>
                <h2 class="display-4 font-weight-bold mb-0"><?php echo $evaluated_count > 0 ? $highest_pct . '%' : 'N/A'; ?></h2>
                <span class="small text-white-50 mt-1">Personal Best Mark</span>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 text-white" style="background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-star fa-2x mb-2 opacity-75"></i>
                <h6 class="text-white-50 font-weight-bold text-uppercase mb-1 small" style="letter-spacing: 0.5px;">Academic Standing</h6>
                <h3 class="font-weight-bold mb-0">
                    <?php echo $evaluated_count > 0 ? $overall_standing['label'] : 'Active Learner'; ?>
                </h3>
                <span class="small text-white-50 mt-1">
                    <?php echo $count_regular; ?> Regular • <?php echo $count_mock; ?> Mock Tests
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Category Tabs: All Exams | Regular Exams | Weekly Mocks -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
    <ul class="nav nav-pills bg-white p-2 rounded-pill shadow-sm border" id="reportFilterPills" role="tablist">
        <li class="nav-item">
            <a class="nav-link font-weight-bold rounded-pill px-4 <?php echo $type_filter === 'all' ? 'active' : 'text-muted'; ?>" href="report_card.php?type=all">
                <i class="fas fa-layer-group mr-2"></i>All Examinations
                <span class="badge <?php echo $type_filter === 'all' ? 'badge-light text-primary' : 'badge-secondary'; ?> ml-2 px-2 py-1 rounded-pill"><?php echo $count_all; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link font-weight-bold rounded-pill px-4 <?php echo $type_filter === 'regular' ? 'active' : 'text-muted'; ?>" href="report_card.php?type=regular">
                <i class="fas fa-graduation-cap mr-2"></i>Regular Exams
                <span class="badge <?php echo $type_filter === 'regular' ? 'badge-light text-primary' : 'badge-secondary'; ?> ml-2 px-2 py-1 rounded-pill"><?php echo $count_regular; ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link font-weight-bold rounded-pill px-4 <?php echo $type_filter === 'weekly_mock' ? 'active' : 'text-muted'; ?>" href="report_card.php?type=weekly_mock">
                <i class="fas fa-calendar-week mr-2"></i>Weekly Mock Tests
                <span class="badge <?php echo $type_filter === 'weekly_mock' ? 'badge-light text-primary' : 'badge-secondary'; ?> ml-2 px-2 py-1 rounded-pill"><?php echo $count_mock; ?></span>
            </a>
        </li>
    </ul>

    <div class="text-muted small mt-2 mt-md-0 font-weight-bold">
        Showing: <strong class="text-dark"><?php echo count($exam_results); ?></strong> recorded assessments
    </div>
</div>

<!-- Performance Trend Chart -->
<div class="card shadow-sm border-0 rounded-lg mb-4 bg-white">
    <div class="card-header bg-white border-bottom pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold mb-0 text-dark">
            <i class="fas fa-chart-area mr-2 text-primary"></i>Score Trajectory & Performance Curve
        </h5>
        <?php if ($evaluated_count > 0): ?>
            <span class="badge badge-primary px-3 py-1 font-weight-bold rounded-pill">
                <?php echo $evaluated_count; ?> Evaluated Tests Plotted
            </span>
        <?php endif; ?>
    </div>
    <div class="card-body p-4">
        <?php if ($evaluated_count > 0): ?>
            <div style="height: 280px; position: relative;">
                <canvas id="progressChart"></canvas>
            </div>
        <?php else: ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-chart-line fa-4x mb-3 opacity-25 text-primary"></i>
                <h5 class="font-weight-bold text-dark">No Evaluated Scores Published Yet</h5>
                <p class="mb-0 text-muted">Once your submitted examinations are graded and answer keys are released by the faculty, your score curve will appear here.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Detailed Examination Results Table -->
<div class="card shadow-sm border-0 rounded-lg bg-white overflow-hidden mb-4">
    <div class="card-header bg-white border-bottom pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold mb-0 text-dark">
            <i class="fas fa-list-alt mr-2 text-primary"></i>Examination History & Transcripts
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($exam_results)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="border-0 px-4 py-3">Examination Details</th>
                            <th class="border-0 text-center py-3">Category</th>
                            <th class="border-0 text-center py-3">Date Completed</th>
                            <th class="border-0 text-center py-3">Score & Percentage</th>
                            <th class="border-0 text-center py-3">Grade</th>
                            <th class="border-0 text-right px-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($exam_results) as $r): 
                            $total_marks = !empty($r['total_marks']) && $r['total_marks'] > 0 
                                ? (int)$r['total_marks'] 
                                : (!empty($r['total_questions']) ? (int)$r['total_questions'] : 1);
                            $pct = round(($r['score'] / $total_marks) * 100, 1);
                            $pct = min(100, max(0, $pct));
                            $grade_info = get_academic_grade($pct);
                            $is_released = !empty($r['key_released']);
                            $is_mock = ($r['exam_type'] === 'weekly_mock');
                        ?>
                            <tr>
                                <td class="align-middle px-4 py-3">
                                    <div class="font-weight-bold text-dark" style="font-size: 1.02rem;"><?php echo h($r['title']); ?></div>
                                    <div class="small text-muted">
                                        <i class="fas fa-clock mr-1"></i><?php echo $r['duration_minutes']; ?> Minutes Duration • <?php echo $r['total_questions']; ?> Questions
                                    </div>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if ($is_mock): ?>
                                        <span class="badge badge-purple px-3 py-1 rounded-pill font-weight-bold">
                                            <i class="fas fa-calendar-week mr-1"></i>Weekly Mock
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-primary px-3 py-1 rounded-pill font-weight-bold">
                                            <i class="fas fa-graduation-cap mr-1"></i>Regular Exam
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-center py-3 text-muted font-weight-500">
                                    <i class="fas fa-calendar-check mr-1 text-success"></i>
                                    <?php echo date('d M Y', strtotime($r['scheduled_date'])); ?>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if ($is_released): ?>
                                        <div class="font-weight-bold text-dark" style="font-size: 1.1rem;">
                                            <?php echo $r['score']; ?> <span class="text-muted font-weight-normal">/ <?php echo $total_marks; ?></span>
                                        </div>
                                        <div class="progress mt-1 mx-auto" style="height: 6px; width: 90px; border-radius: 999px;">
                                            <div class="progress-bar <?php echo $pct >= 75 ? 'bg-success' : ($pct >= 40 ? 'bg-primary' : 'bg-danger'); ?>" style="width: <?php echo $pct; ?>%;"></div>
                                        </div>
                                        <div class="small text-muted font-weight-bold mt-1"><?php echo $pct; ?>%</div>
                                    <?php else: ?>
                                        <span class="badge badge-warning px-3 py-1 rounded-pill font-weight-bold shadow-sm">
                                            <i class="fas fa-hourglass-half mr-1"></i>Evaluation Recorded
                                        </span>
                                        <div class="small text-muted mt-1">Results Pending Release</div>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-center py-3">
                                    <?php if ($is_released): ?>
                                        <span class="badge <?php echo $grade_info['badge']; ?> px-3 py-1 rounded-pill font-weight-bold shadow-sm">
                                            <?php echo $grade_info['grade']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted font-italic small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-right px-4 py-3">
                                    <?php if ($is_released): ?>
                                        <a href="review.php?exam_id=<?php echo $r['exam_id']; ?>" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill shadow-sm px-3 py-2 border-2 transition">
                                            <i class="fas fa-file-invoice mr-1"></i>View Solutions & Key
                                        </a>
                                    <?php else: ?>
                                        <a href="review.php?exam_id=<?php echo $r['exam_id']; ?>" class="btn btn-sm btn-outline-secondary font-weight-bold rounded-pill shadow-sm px-3 py-2 transition">
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
                <i class="fas fa-folder-open fa-3x mb-3 opacity-25"></i>
                <h5 class="font-weight-bold text-dark">No Examinations Found</h5>
                <p class="mb-0">You have no submitted examinations recorded under this category.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($evaluated_count > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var ctx = document.getElementById('progressChart').getContext('2d');
    var gradient = ctx.createLinearGradient(0, 0, 0, 260);
    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.28)');
    gradient.addColorStop(1, 'rgba(59, 130, 246, 0.01)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Score Percentage',
                data: <?php echo json_encode($chart_percentages); ?>,
                backgroundColor: gradient,
                borderColor: '#2563eb',
                borderWidth: 3,
                pointBackgroundColor: '#1e3a8a',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8,
                fill: true,
                tension: 0.35
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: {
                        color: 'rgba(226, 232, 240, 0.6)'
                    },
                    ticks: {
                        callback: function(value) { return value + "%"; },
                        font: { family: 'Inter', weight: 600 }
                    }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'Inter' } }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    padding: 12,
                    cornerRadius: 8,
                    titleFont: { family: 'Inter', size: 13, weight: 700 },
                    bodyFont: { family: 'Inter', size: 12 },
                    callbacks: {
                        label: function(context) { return ' Score Achieved: ' + context.parsed.y + '%'; }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<style>
#reportFilterPills .nav-link {
    transition: background-color 0.2s ease, color 0.2s ease !important;
}
#reportFilterPills .nav-link.active {
    background: #1e3a8a !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25) !important;
}
@media print {
    body { background: #fff !important; }
    .student-navbar, .student-mobile-bottom-nav, footer, .btn, #reportFilterPills { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; page-break-inside: avoid; }
    .print-identity-card { border: 2px solid #1e3a8a !important; }
}
</style>

<?php require_once '../includes/student_footer.php'; ?>

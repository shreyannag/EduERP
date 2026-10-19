<?php
// student/report_card.php
require_once '../includes/student_header.php';

// Fetch mock test results
$stmt = $pdo->prepare("
    SELECT a.score, a.total_questions, e.title, e.scheduled_date,
    COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.student_id = ? AND e.exam_type = 'weekly_mock' AND a.is_submitted = 1
      AND (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) > 0
    ORDER BY e.scheduled_date ASC
");
$stmt->execute([$student_id]);
$mock_results = $stmt->fetchAll();

$dates = [];
$scores = [];
$percentages = [];
foreach ($mock_results as $res) {
    $dates[] = date('M d', strtotime($res['scheduled_date']));
    $scores[] = $res['score'];
    $total_marks = !empty($res['total_marks']) && $res['total_marks'] > 0 
        ? (int)$res['total_marks'] 
        : (!empty($res['total_questions']) ? (int)$res['total_questions'] : 1);
    $pct = round(($res['score'] / $total_marks) * 100, 1);
    $pct = min(100, max(0, $pct));
    $percentages[] = $pct;
}

// Stats
$total_mocks = count($mock_results);
$avg_pct = $total_mocks > 0 ? round(array_sum($percentages) / $total_mocks, 1) : 0;
$highest_pct = $total_mocks > 0 ? max($percentages) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Weekly Mock Test Report Card</h2>
    <button class="btn btn-outline-primary rounded-pill shadow-sm font-weight-bold" onclick="window.print()">
        <i class="fas fa-print mr-2"></i>Print Report
    </button>
</div>

<div class="row mb-4">
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 bg-primary text-white">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-tasks fa-3x mb-3 opacity-50"></i>
                <h5 class="font-weight-bold mb-1">Tests Completed</h5>
                <h2 class="display-4 font-weight-bold mb-0"><?php echo $total_mocks; ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 bg-success text-white">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-chart-line fa-3x mb-3 opacity-50"></i>
                <h5 class="font-weight-bold mb-1">Average Score</h5>
                <h2 class="display-4 font-weight-bold mb-0"><?php echo $avg_pct; ?>%</h2>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0 rounded-lg text-center h-100 bg-info text-white">
            <div class="card-body p-4 d-flex flex-column justify-content-center">
                <i class="fas fa-trophy fa-3x mb-3 opacity-50"></i>
                <h5 class="font-weight-bold mb-1">Highest Score</h5>
                <h2 class="display-4 font-weight-bold mb-0"><?php echo $highest_pct; ?>%</h2>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-white border-bottom pt-4 pb-3 px-4">
                <h5 class="font-weight-bold mb-0 text-dark"><i class="fas fa-chart-area mr-2 text-primary"></i>Performance Trend</h5>
            </div>
            <div class="card-body p-4">
                <?php if($total_mocks > 0): ?>
                    <canvas id="progressChart" height="100"></canvas>
                <?php else: ?>
                    <div class="text-center text-muted my-5">
                        <i class="fas fa-chart-bar fa-3x mb-3 opacity-25"></i>
                        <p>No mock test data available yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- History Table -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-white border-bottom pt-4 pb-3 px-4">
                <h5 class="font-weight-bold mb-0 text-dark"><i class="fas fa-history mr-2 text-info"></i>Test History</h5>
            </div>
            <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                <?php if($total_mocks > 0): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach(array_reverse($mock_results) as $r): 
                            $total_marks = !empty($r['total_marks']) && $r['total_marks'] > 0 
                                ? (int)$r['total_marks'] 
                                : (!empty($r['total_questions']) ? (int)$r['total_questions'] : 1);
                            $p = round(($r['score'] / $total_marks) * 100, 1);
                            $p = min(100, max(0, $p));
                        ?>
                            <li class="list-group-item px-4 py-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-dark text-truncate" style="max-width: 70%;" title="<?php echo h($r['title']); ?>"><?php echo h($r['title']); ?></strong>
                                    <span class="badge <?php echo $p >= 75 ? 'badge-success' : ($p >= 50 ? 'badge-warning' : 'badge-danger'); ?> px-2 py-1 shadow-sm"><?php echo $p; ?>%</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center small text-muted">
                                    <span><i class="fas fa-calendar-day mr-1"></i><?php echo date('M d, Y', strtotime($r['scheduled_date'])); ?></span>
                                    <span><?php echo $r['score']; ?> / <?php echo $total_marks; ?> Marks</span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-center text-muted p-4">History is empty.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if($total_mocks > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var ctx = document.getElementById('progressChart').getContext('2d');
    var chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [{
                label: 'Score Percentage',
                data: <?php echo json_encode($percentages); ?>,
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderColor: '#3b82f6',
                borderWidth: 3,
                pointBackgroundColor: '#1e3a8a',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#1e3a8a',
                pointRadius: 5,
                pointHoverRadius: 7,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        callback: function(value) { return value + "%" }
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) { return ' Score: ' + context.parsed.y + '%'; }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<style>
@media print {
    body { background: #fff !important; }
    .student-navbar, .btn-outline-primary, .card { box-shadow: none !important; border: 1px solid #ddd !important; }
    .col-lg-8, .col-lg-4, .col-md-4 { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; margin-bottom: 20px; }
}
</style>

<?php require_once '../includes/student_footer.php'; ?>

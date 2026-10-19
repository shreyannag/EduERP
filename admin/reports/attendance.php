<?php
// admin/reports/attendance.php
require_once '../../includes/admin_header.php';

require_admin_feature('reports');

$exam_id = intval($_GET['exam_id'] ?? 0);
$current_admin_id = (int)$_SESSION['admin_id'];

if (is_super_admin()) {
    $exams = $pdo->query("SELECT id, title, class_id, board_id, status FROM exams WHERE status != 'draft' ORDER BY scheduled_date DESC")->fetchAll();
} else {
    $stmtExams = $pdo->prepare("SELECT id, title, class_id, board_id, status FROM exams WHERE status != 'draft' AND admin_id = ? ORDER BY scheduled_date DESC");
    $stmtExams->execute([$current_admin_id]);
    $exams = $stmtExams->fetchAll();
}
$exam = array_values(array_filter($exams, function($e) use($exam_id) { return $e['id'] == $exam_id; }))[0] ?? null;

$attempted = [];
$not_attempted = [];

if ($exam) {
    $stmt_att = $pdo->prepare("
        SELECT s.full_name, s.username, s.class_id, a.score, a.total_questions, a.submitted_at,
        COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
        FROM attempts a
        JOIN students s ON a.student_id = s.id
        WHERE a.exam_id = ? AND a.is_submitted = 1
        ORDER BY s.full_name ASC
    ");
    $stmt_att->execute([$exam_id]);
    $attempted = $stmt_att->fetchAll();
    
    $stmt_un = $pdo->prepare("
        SELECT s.full_name, s.username, c.name as class_name
        FROM enrolments en
        JOIN students s ON en.student_id = s.id
        JOIN classes c ON s.class_id = c.id
        LEFT JOIN attempts a ON en.student_id = a.student_id AND a.exam_id = ? AND a.is_submitted = 1
        WHERE en.exam_id = ? AND a.id IS NULL
        ORDER BY s.full_name ASC
    ");
    $stmt_un->execute([$exam_id, $exam_id]);
    $not_attempted = $stmt_un->fetchAll();
    
    if (isset($_GET['export']) && $_GET['export'] == 'csv') {
        ob_clean();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="attendance_node_'.$exam_id.'.csv"');
        $output = fopen('php://output', 'w');
        
        fputcsv($output, ['System Analytics: ' . trim($exam['title'])]);
        fputcsv($output, []);
        
        fputcsv($output, ['=== Node Submissions Configured ===']);
        fputcsv($output, ['Schema Name', 'Universal Router ID', 'Target Result Vector', 'Accuracy Output', 'Submission Time Sync']);
        foreach($attempted as $a) {
            $total_marks = !empty($a['total_marks']) && $a['total_marks'] > 0 
                ? (int)$a['total_marks'] 
                : (!empty($a['total_questions']) ? (int)$a['total_questions'] : 1);
            $pct = round(($a['score'] / $total_marks) * 100, 1);
            $pct = min(100, max(0, $pct));
            fputcsv($output, [$a['full_name'], $a['username'], $a['score'].'/'.$total_marks, $pct . '%', $a['submitted_at']]);
        }
        
        fputcsv($output, []);
        fputcsv($output, ['=== Idle Mapping Signatures ===']);
        fputcsv($output, ['Schema Name', 'Universal Router ID', 'Associated Segment Context', 'Access Policy Active']);
        foreach($not_attempted as $n) {
            fputcsv($output, [$n['full_name'], $n['username'], 'Class '.$n['class_name'], 'Active Map']);
        }
        
        fclose($output);
        exit;
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Platform Access Tracer</h2>
</div>

<div class="card shadow-sm border-0 mb-4 bg-light rounded-lg">
    <div class="card-body p-4">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-9 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Analyze Published Assessment Framework</label>
                <div class="input-group shadow-sm">
                    <div class="input-group-prepend"><span class="input-group-text border-0 bg-white"><i class="fas fa-filter text-primary"></i></span></div>
                    <select name="exam_id" class="form-control custom-select border-0 font-weight-bold" onchange="this.form.submit()">
                        <option value="">-- Choose Exam Node Overlay --</option>
                        <?php foreach($exams as $e): ?>
                            <option value="<?php echo $e['id']; ?>" class="font-weight-500" <?php echo $exam_id == $e['id'] ? 'selected' : ''; ?>><?php echo h($e['title']); ?> [<?php echo strtoupper($e['status']); ?>]</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm rounded-pill"><i class="fas fa-search-location mr-2"></i>Inspect Context</button>
            </div>
        </form>
    </div>
</div>

<?php if ($exam): 
    $tot_enrolled = count($attempted) + count($not_attempted);
    $tot_att = count($attempted);
    $tot_not = count($not_attempted);
    $part_pct = $tot_enrolled > 0 ? round(($tot_att / $tot_enrolled) * 100, 1) : 0;
?>
    <div class="card shadow-sm border-0 rounded-lg mb-4 bg-white">
        <div class="card-body p-4 p-md-5">
            <div class="row align-items-center text-center text-md-left">
                <div class="col-md-3 mb-4 mb-md-0 border-right border-light">
                    <h6 class="text-uppercase text-muted font-weight-bold mb-2">Total Mapping Connections</h6>
                    <h1 class="font-weight-bold text-dark mb-0 display-4" style="font-size: 2.5rem;"><?php echo $tot_enrolled; ?></h1>
                </div>
                <div class="col-md-3 mb-4 mb-md-0 border-right border-light">
                    <h6 class="text-uppercase text-muted font-weight-bold mb-2">Completed Trace Cycles</h6>
                    <h1 class="font-weight-bold text-success mb-0 display-4" style="font-size: 2.5rem;"><?php echo $tot_att; ?></h1>
                </div>
                <div class="col-md-3 mb-4 mb-md-0 border-right border-light">
                    <h6 class="text-uppercase text-muted font-weight-bold mb-2">Unresolved Context Tokens</h6>
                    <h1 class="font-weight-bold text-warning mb-0 display-4" style="font-size: 2.5rem;"><?php echo $tot_not; ?></h1>
                </div>
                <div class="col-md-3">
                    <h6 class="text-uppercase text-muted font-weight-bold mb-2">Contextual Coverage Depth</h6>
                    <h2 class="font-weight-bold text-primary mb-2"><?php echo $part_pct; ?>%</h2>
                    <div class="progress border-0 bg-light shadow-inner rounded-pill" style="height: 12px;">
                        <div class="progress-bar bg-primary rounded-pill progress-bar-striped progress-bar-animated" role="progressbar" style="width: <?php echo $part_pct; ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <ul class="nav nav-tabs border-0" id="attendanceTabs" role="tablist">
            <li class="nav-item border-0 mr-2">
                <a class="nav-link active rounded-pill bg-white shadow-sm font-weight-bold px-4 text-dark" id="attempted-tab" data-toggle="tab" href="#attempted" role="tab"><i class="fas fa-chart-pie mr-2 text-success"></i>Engaged Metrics (<?php echo $tot_att; ?>)</a>
            </li>
            <li class="nav-item border-0">
                <a class="nav-link rounded-pill bg-white shadow-sm font-weight-bold px-4 text-muted" id="missing-tab" data-toggle="tab" href="#missing" role="tab"><i class="fas fa-ghost mr-2 opacity-50"></i>Idle Constraints (<?php echo $tot_not; ?>)</a>
            </li>
        </ul>
        <a href="?exam_id=<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>&export=csv" class="btn btn-dark font-weight-bold rounded-pill px-4 shadow py-2"><i class="fas fa-file-csv mr-2"></i>Download Array Schema (CSV)</a>
    </div>

    <div class="tab-content bg-white shadow-sm rounded-lg border-0 py-2 pb-0" id="attendanceTabContent">
        <!-- Attempted Tab -->
        <div class="tab-pane fade show active p-0" id="attempted" role="tabpanel">
            <div class="table-responsive px-4 py-4">
                <table class="table table-hover table-bordered w-100 data-tbl">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="border-0 rounded-top-left px-3">Student Base Overlay</th>
                            <th class="border-0 px-3">Public Router ID Hash</th>
                            <th class="border-0 text-center">Score Vector Point</th>
                            <th class="border-0 text-center">Efficiency Coefficient</th>
                            <th class="border-0 rounded-top-right text-right px-4">Trace Stamp Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($attempted as $a): 
                            $total_marks = !empty($a['total_marks']) && $a['total_marks'] > 0 
                                ? (int)$a['total_marks'] 
                                : (!empty($a['total_questions']) ? (int)$a['total_questions'] : 1);
                            $pct = round(($a['score'] / $total_marks) * 100, 1);
                            $pct = min(100, max(0, $pct));
                        ?>
                        <tr>
                            <td class="align-middle font-weight-bold text-dark px-3"><?php echo h($a['full_name']); ?></td>
                            <td class="align-middle font-family-monospace text-primary px-3 bg-light rounded"><i class="fas fa-fingerprint mr-2 opacity-50 text-muted"></i><?php echo h($a['username']); ?></td>
                            <td class="align-middle text-center font-weight-bold h5 mb-0 px-3"><?php echo $a['score']; ?> / <?php echo $total_marks; ?></td>
                            <td class="align-middle text-center px-3"><span class="badge <?php echo $pct>=40 ? 'badge-success':'badge-danger'; ?> px-3 py-2 shadow-sm rounded-pill font-weight-bold text-uppercase" style="letter-spacing: 1px;"><?php echo $pct; ?>%</span></td>
                            <td class="align-middle text-right text-muted font-weight-500 px-4"><i class="fas fa-clock mr-2 opacity-25"></i><?php echo date('d M Y, H:i', strtotime($a['submitted_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Not Attempted Tab -->
        <div class="tab-pane fade p-0" id="missing" role="tabpanel">
            <div class="table-responsive px-4 py-4">
                <table class="table table-hover table-bordered w-100 data-tbl">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="border-0 rounded-top-left px-4">Orphaned Student Profile Context</th>
                            <th class="border-0 px-4">Static Pointer Hash</th>
                            <th class="border-0 px-4">Configured Segment Layer</th>
                            <th class="border-0 rounded-top-right text-center px-4">Constraint Node Validity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($not_attempted as $n): ?>
                        <tr>
                            <td class="align-middle font-weight-bold text-dark px-4"><?php echo h($n['full_name']); ?></td>
                            <td class="align-middle font-family-monospace text-muted px-4"><i class="fas fa-ban mr-2 opacity-25"></i><?php echo h($n['username']); ?></td>
                            <td class="align-middle px-4"><span class="bg-warning bg-opacity-25 border border-warning rounded px-2 py-1 small font-weight-bold text-dark">Class <?php echo h($n['class_name']); ?> Array Map</span></td>
                            <td class="align-middle text-center px-4"><span class="badge badge-secondary shadow-sm px-3 py-1 rounded-pill"><i class="fas fa-link mr-1"></i>Awaiting Signal</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    $(document).ready(function() {
        $('.data-tbl').DataTable({
            "order": [], 
            "pageLength": 50,
            "language": { "search": "<i class='fas fa-search text-muted mr-2'></i>" }
        });
        
        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            $('.nav-link').removeClass('active text-dark').addClass('text-muted');
            $(e.target).addClass('active text-dark').removeClass('text-muted');
            $($.fn.dataTable.tables(true)).DataTable().columns.adjust();
        });
    });
    </script>
<?php endif; ?>

<?php require_once '../../includes/admin_footer.php'; ?>

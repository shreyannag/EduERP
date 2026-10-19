<?php
// admin/reports/results.php
require_once '../../includes/admin_header.php';

require_admin_feature('reports');

$exam_id = intval($_GET['exam_id'] ?? 0);
$current_admin_id = (int)$_SESSION['admin_id'];

if (is_super_admin()) {
    $exams = $pdo->query("SELECT id, title, status FROM exams WHERE status = 'completed' ORDER BY scheduled_date DESC")->fetchAll();
} else {
    $stmtExams = $pdo->prepare("SELECT id, title, status FROM exams WHERE status = 'completed' AND admin_id = ? ORDER BY scheduled_date DESC");
    $stmtExams->execute([$current_admin_id]);
    $exams = $stmtExams->fetchAll();
}
$exam = array_values(array_filter($exams, function($e) use($exam_id) { return $e['id'] == $exam_id; }))[0] ?? null;

$results = [];
$stats = null;
$report_link = null;

if ($exam) {
    $stmt = $pdo->prepare("
        SELECT s.full_name, s.username, a.score, a.total_questions, a.started_at, a.submitted_at,
        COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
        FROM attempts a
        JOIN students s ON a.student_id = s.id
        WHERE a.exam_id = ? AND a.is_submitted = 1
        ORDER BY a.score DESC, s.full_name ASC
    ");
    $stmt->execute([$exam_id]);
    $results = $stmt->fetchAll();
    
    if (!empty($results)) {
        $total_att = count($results);
        $total_score_pct = 0;
        $high = 0;
        $low = 100;
        $pass = 0;
        
        foreach($results as $r) {
            $total_marks = !empty($r['total_marks']) && $r['total_marks'] > 0 
                ? (int)$r['total_marks'] 
                : (!empty($r['total_questions']) ? (int)$r['total_questions'] : 1);
            $pct = round(($r['score'] / $total_marks) * 100, 1);
            $pct = min(100, max(0, $pct));
            $total_score_pct += $pct;
            if ($pct > $high) $high = $pct;
            if ($pct < $low) $low = $pct;
            if ($pct >= 40) $pass++;
        }
        
        $stats = [
            'avg' => round($total_score_pct / $total_att, 1),
            'high' => round($high, 1),
            'low' => round($low, 1),
            'pass' => $pass,
            'fail' => $total_att - $pass,
            'total' => $total_att
        ];

        $stmt_link = $pdo->prepare("SELECT token FROM report_links WHERE exam_id = ? AND expires_at > NOW()");
        $stmt_link->execute([$exam_id]);
        $report_link = $stmt_link->fetchColumn();
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Exam Results</h2>
</div>

<div class="card shadow-sm border-0 mb-4 bg-light rounded-lg">
    <div class="card-body p-4">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-9 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Select a Completed Exam to View Results</label>
                <div class="input-group shadow-sm">
                    <div class="input-group-prepend"><span class="input-group-text border-0 bg-white"><i class="fas fa-poll text-primary"></i></span></div>
                    <select name="exam_id" class="form-control custom-select border-0 font-weight-bold" onchange="this.form.submit()">
                        <option value="">-- Select an Exam --</option>
                        <?php foreach($exams as $e): ?>
                            <option value="<?php echo $e['id']; ?>" class="font-weight-500" <?php echo $exam_id == $e['id'] ? 'selected' : ''; ?>><?php echo h($e['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-block font-weight-bold shadow-sm rounded-pill"><i class="fas fa-rocket mr-2"></i>View Results</button>
            </div>
        </form>
    </div>
</div>

<?php if ($exam && empty($results)): ?>
    <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
        <div class="card-body py-4">
            <i class="fas fa-ghost fa-4x text-light mb-3"></i>
            <h4 class="font-weight-bold text-dark mt-2">No Results Found</h4>
            <p class="mb-0 text-muted">This exam has no submitted attempts yet.</p>
        </div>
    </div>
<?php elseif ($exam && $stats): ?>
    
    <div class="row align-items-stretch mb-4">
        <!-- Summary Stats Block -->
        <div class="col-lg-8 mb-4 mb-lg-0 flex-grow-1">
            <div class="row h-100 mx-0">
                <div class="col-md-4 mb-3 pl-0 pr-md-2 h-100">
                    <div class="card bg-gradient-primary text-white border-0 shadow rounded-lg h-100 pt-1 text-center d-flex flex-column justify-content-center" style="background: linear-gradient(135deg, #0052d4, #4364f7);">
                        <div class="card-body px-3 py-4">
                            <h6 class="text-uppercase text-white-50 font-weight-bold mb-3" style="letter-spacing:1px;font-size:0.75rem;">Average Score</h6>
                            <h1 class="display-3 font-weight-bold mb-0" style="line-height:1;"><?php echo $stats['avg']; ?><span style="font-size: 1.5rem; opacity:0.75;">%</span></h1>
                            <div class="mt-3 py-1 px-3 bg-white text-primary rounded-pill d-inline-block small font-weight-bold shadow-sm">Total Attempts: <?php echo $stats['total']; ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3 px-md-2 h-100">
                    <div class="card bg-white border-0 shadow-sm rounded-lg h-100 text-center d-flex flex-column justify-content-center text-dark">
                        <div class="card-body px-3 py-4">
                            <h6 class="text-uppercase text-muted font-weight-bold mb-4" style="letter-spacing:1px;font-size:0.75rem;">Score Range</h6>
                            <div class="d-flex justify-content-between px-3 border-bottom pb-2 mb-2 align-items-center">
                                <span class="font-weight-bold text-muted small text-uppercase">Highest</span>
                                <h4 class="text-success font-weight-bold mb-0"><?php echo $stats['high']; ?>% <i class="fas fa-caret-up ml-1 small opacity-50"></i></h4>
                            </div>
                            <div class="d-flex justify-content-between px-3 pt-1 align-items-center">
                                <span class="font-weight-bold text-muted small text-uppercase">Lowest</span>
                                <h4 class="text-danger font-weight-bold mb-0"><?php echo $stats['low']; ?>% <i class="fas fa-caret-down ml-1 small opacity-50"></i></h4>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3 pr-0 pl-md-2 h-100">
                    <div class="card bg-white border-0 shadow-sm rounded-lg h-100 text-center d-flex flex-column justify-content-center text-dark">
                        <div class="card-body px-3 py-4">
                            <h6 class="text-uppercase text-muted font-weight-bold mb-4" style="letter-spacing:1px;font-size:0.75rem;">Pass/Fail Breakdown</h6>
                            <div class="d-flex justify-content-between px-3 border-bottom pb-2 mb-2 align-items-center">
                                <span class="font-weight-bold text-muted small text-uppercase">Passed</span>
                                <h4 class="text-dark font-weight-bold mb-0"><span class="badge badge-success px-2 rounded-pill"><?php echo $stats['pass']; ?></span></h4>
                            </div>
                            <div class="d-flex justify-content-between px-3 pt-1 align-items-center">
                                <span class="font-weight-bold text-muted small text-uppercase">Failed</span>
                                <h4 class="text-dark font-weight-bold mb-0"><span class="badge badge-warning px-2 rounded-pill text-white"><?php echo $stats['fail']; ?></span></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Output Export Block -->
        <div class="col-lg-4 d-flex">
            <div class="card bg-dark text-white border-0 shadow-sm rounded-lg w-100 flex-grow-1 overflow-hidden" style="background: linear-gradient(180deg, #111827 0%, #1f2937 100%);">
                <div class="card-header bg-transparent border-bottom-0 pt-4 px-4 pb-2">
                    <h6 class="mb-0 font-weight-bold text-info text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-sync-alt mr-2 fa-spin" style="animation-duration: 3s;"></i>Export Options</h6>
                </div>
                <div class="card-body p-4 d-flex flex-column justify-content-center">
                    <a href="export_pdf.php?exam_id=<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-danger font-weight-bold btn-block rounded-pill shadow mb-4 py-3 border-0 transition" style="background: linear-gradient(45deg, #ef4444, #b91c1c);"><i class="fas fa-file-pdf mr-2"></i>Download PDF Report</a>
                    
                    <?php if($report_link): ?>
                        <label class="small text-white-50 text-uppercase font-weight-bold mt-2"><i class="fas fa-globe mr-1"></i>Report Link Created</label>
                        <div class="input-group shadow border-0 rounded-lg overflow-hidden mt-1">
                            <input type="text" class="form-control bg-light border-0 py-3 font-weight-bold text-dark font-family-monospace small" id="shareToken" value="<?php echo h('http://'.$_SERVER['HTTP_HOST'].'/report/view.php?token='.$report_link); ?>" readonly>
                            <div class="input-group-append">
                                <button class="btn btn-info font-weight-bold border-0 px-4 transition" type="button" onclick="navigator.clipboard.writeText(document.getElementById('shareToken').value); showToast('Report link copied to clipboard.');"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                    <?php else: ?>
                        <span class="d-block w-100 mt-2 mb-3 border-top border-secondary"></span>
                        <form method="POST" action="generate_link.php">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                            <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn btn-outline-info font-weight-bold btn-block rounded-pill py-2 shadow-sm border-2 transition"><i class="fas fa-link mr-2"></i>Generate Report Link</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card shadow-sm border-0 rounded-lg">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
            <h5 class="mb-0 font-weight-bold text-dark">Detailed Results</h5>
        </div>
        <div class="card-body p-0 mt-2">
            <div class="table-responsive px-4 pb-4">
                <table class="table table-hover table-bordered w-100" id="resTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th width="5%" class="border-0 rounded-top-left text-center">Rank</th>
                            <th width="25%" class="border-0 px-4">Student Name</th>
                            <th width="15%" class="border-0 px-3">Username / ID</th>
                            <th width="15%" class="border-0 text-center">Score</th>
                            <th width="15%" class="border-0 text-center">Percentage</th>
                            <th width="10%" class="border-0 text-center">Status</th>
                            <th width="15%" class="border-0 rounded-top-right text-right px-4">Time Taken</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach($results as $r): 
                            $total_marks = !empty($r['total_marks']) && $r['total_marks'] > 0 
                                ? (int)$r['total_marks'] 
                                : (!empty($r['total_questions']) ? (int)$r['total_questions'] : 1);
                            $pct = round(($r['score'] / $total_marks) * 100, 1);
                            $pct = min(100, max(0, $pct));
                            $passed = $pct >= 40;
                            
                            $t_start = strtotime($r['started_at']);
                            $t_end = strtotime($r['submitted_at']);
                            $diff_min = round(($t_end - $t_start) / 60);
                        ?>
                        <tr class="<?php echo $passed ? '' : 'bg-light bg-opacity-50'; ?>">
                            <td class="align-middle text-center font-weight-bold text-muted h6 mb-0"><?php echo $rank++; ?></td>
                            <td class="align-middle font-weight-bold text-dark px-4"><?php echo h($r['full_name']); ?></td>
                            <td class="align-middle font-family-monospace text-primary px-3"><?php echo h($r['username']); ?></td>
                            <td class="align-middle text-center font-weight-bold text-dark h5 mb-0 px-3"><?php echo $r['score']; ?> <span class="text-muted font-weight-normal font-italic mx-1">/</span> <?php echo $total_marks; ?></td>
                            <td class="align-middle text-center px-3">
                                <span class="badge <?php echo $passed ? 'badge-success':'badge-danger'; ?> px-3 py-2 shadow-sm rounded-pill font-weight-bold" style="font-size:0.9rem;"><?php echo $pct; ?>%</span>
                            </td>
                            <td class="align-middle text-center text-uppercase px-3">
                                <?php if($passed): ?>
                                    <i class="fas fa-check-circle text-success fa-lg shadow-sm bg-white rounded-circle"></i>
                                <?php else: ?>
                                    <i class="fas fa-times-circle text-danger fa-lg shadow-sm bg-white rounded-circle opacity-75"></i>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-right px-4 text-muted font-weight-500"><i class="fas fa-stopwatch mr-2 opacity-50"></i><?php echo $diff_min; ?> m</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    $(document).ready(function() {
        $('#resTable').DataTable({
            "order": [], 
            "pageLength": 50,
            "language": { "search": "<i class='fas fa-search text-muted mr-2'></i>" }
        });
    });
    </script>
<?php endif; ?>

<?php require_once '../../includes/admin_footer.php'; ?>

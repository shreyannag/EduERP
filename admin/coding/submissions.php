<?php
// admin/coding/submissions.php
// Live Submissions Monitor

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$effective_admin_id = get_effective_admin_id();

$filter_problem = (int)($_GET['problem_id'] ?? 0);
$filter_comp    = (int)($_GET['competition_id'] ?? 0);
$filter_status  = trim($_GET['status'] ?? '');
$filter_lang    = trim($_GET['lang'] ?? '');
$filter_student = trim($_GET['student'] ?? '');

// Fetch Problems for dropdown
$probSql = "SELECT id, title FROM coding_problems";
if ($effective_admin_id) {
    $probSql .= " WHERE admin_id = ? OR admin_id = 1";
    $stmtP = $pdo->prepare($probSql . " ORDER BY title ASC");
    $stmtP->execute([$effective_admin_id]);
} else {
    $stmtP = $pdo->query($probSql . " ORDER BY title ASC");
}
$problems = $stmtP->fetchAll(PDO::FETCH_ASSOC);

// Fetch Competitions for dropdown
$compSql = "SELECT id, title FROM coding_competitions";
if ($effective_admin_id) {
    $compSql .= " WHERE admin_id = ?";
    $stmtC = $pdo->prepare($compSql . " ORDER BY start_time DESC");
    $stmtC->execute([$effective_admin_id]);
} else {
    $stmtC = $pdo->query($compSql . " ORDER BY start_time DESC");
}
$competitions = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Build Submissions Query
$where = "1=1";
$params = [];

if ($effective_admin_id) {
    $where .= " AND (p.admin_id = ? OR p.admin_id = 1)";
    $params[] = $effective_admin_id;
}

if ($filter_problem > 0) {
    $where .= " AND s.problem_id = ?";
    $params[] = $filter_problem;
}

if ($filter_comp > 0) {
    $where .= " AND s.competition_id = ?";
    $params[] = $filter_comp;
}

if ($filter_status !== '' && in_array($filter_status, ['AC', 'WA', 'TLE', 'CE', 'RE', 'PENDING'])) {
    $where .= " AND s.status = ?";
    $params[] = $filter_status;
}

if ($filter_lang !== '' && in_array($filter_lang, ['python', 'java', 'c', 'cpp'])) {
    $where .= " AND s.language = ?";
    $params[] = $filter_lang;
}

if ($filter_student !== '') {
    $where .= " AND (st.full_name LIKE ? OR st.username LIKE ?)";
    $params[] = "%$filter_student%";
    $params[] = "%$filter_student%";
}

$sql = "
    SELECT s.*, 
           p.title AS problem_title,
           c.title AS competition_title,
           st.full_name AS student_name,
           st.username AS student_username
    FROM coding_submissions s
    JOIN coding_problems p ON s.problem_id = p.id
    LEFT JOIN coding_competitions c ON s.competition_id = c.id
    JOIN students st ON s.student_id = st.id
    WHERE $where
    ORDER BY s.submitted_at DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2 class="h3 font-weight-bold text-gray-800 mb-1">
            <i class="fas fa-terminal text-primary mr-2"></i>Live Submissions Monitor
        </h2>
        <p class="text-muted mb-0">Real-time log of student code compilation, test case evaluations, and verdicts.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- Filters Card -->
<div class="card shadow-sm border-0 mb-4 bg-white">
    <div class="card-body py-3">
        <form method="GET" class="form-row align-items-center">
            <div class="col-md-3 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Problem</label>
                <select name="problem_id" class="form-control form-control-sm">
                    <option value="">All Problems</option>
                    <?php foreach ($problems as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $filter_problem === (int)$p['id'] ? 'selected' : ''; ?>>
                            #<?php echo $p['id']; ?> - <?php echo h($p['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Competition</label>
                <select name="competition_id" class="form-control form-control-sm">
                    <option value="">All Competitions / Practice</option>
                    <?php foreach ($competitions as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $filter_comp === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo h($c['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Verdict</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="">All Verdicts</option>
                    <option value="AC" <?php echo $filter_status === 'AC' ? 'selected' : ''; ?>>AC (Accepted)</option>
                    <option value="WA" <?php echo $filter_status === 'WA' ? 'selected' : ''; ?>>WA (Wrong Answer)</option>
                    <option value="TLE" <?php echo $filter_status === 'TLE' ? 'selected' : ''; ?>>TLE (Time Limit)</option>
                    <option value="CE" <?php echo $filter_status === 'CE' ? 'selected' : ''; ?>>CE (Compile Error)</option>
                    <option value="RE" <?php echo $filter_status === 'RE' ? 'selected' : ''; ?>>RE (Runtime Error)</option>
                </select>
            </div>

            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Language</label>
                <select name="lang" class="form-control form-control-sm">
                    <option value="">All Languages</option>
                    <option value="python" <?php echo $filter_lang === 'python' ? 'selected' : ''; ?>>Python 3</option>
                    <option value="java" <?php echo $filter_lang === 'java' ? 'selected' : ''; ?>>Java 17</option>
                    <option value="cpp" <?php echo $filter_lang === 'cpp' ? 'selected' : ''; ?>>C++</option>
                    <option value="c" <?php echo $filter_lang === 'c' ? 'selected' : ''; ?>>C</option>
                </select>
            </div>

            <div class="col-md-2 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Student</label>
                <input type="text" name="student" class="form-control form-control-sm" placeholder="Name or username..." value="<?php echo h($filter_student); ?>">
            </div>

            <div class="col-12 mt-2 d-flex justify-content-end">
                <button type="submit" class="btn btn-sm btn-primary font-weight-bold mr-2">
                    <i class="fas fa-filter mr-1"></i> Apply Filters
                </button>
                <a href="submissions.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Submissions Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-list mr-1"></i> Submissions List (Showing latest <?php echo count($submissions); ?>)
        </h6>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.location.reload();">
            <i class="fas fa-sync-alt mr-1"></i> Refresh
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 60px;">#ID</th>
                        <th>Student</th>
                        <th>Problem</th>
                        <th>Competition</th>
                        <th>Lang</th>
                        <th>Verdict</th>
                        <th>Passed</th>
                        <th>Score</th>
                        <th>Exec Time</th>
                        <th>Submitted At</th>
                        <th class="text-right" style="width: 100px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="11" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 text-gray-300 d-block"></i>
                                No submissions found matching the criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $s): ?>
                            <?php
                                $badgeClass = 'badge-secondary';
                                if ($s['status'] === 'AC') $badgeClass = 'badge-success';
                                elseif ($s['status'] === 'WA') $badgeClass = 'badge-danger';
                                elseif ($s['status'] === 'TLE') $badgeClass = 'badge-warning text-dark';
                                elseif ($s['status'] === 'CE') $badgeClass = 'badge-dark';
                                elseif ($s['status'] === 'RE') $badgeClass = 'badge-danger';
                            ?>
                            <tr>
                                <td class="font-weight-bold text-muted"><?php echo $s['id']; ?></td>
                                <td>
                                    <div class="font-weight-bold text-dark"><?php echo h($s['student_name']); ?></div>
                                    <small class="text-muted"><?php echo h($s['student_username']); ?></small>
                                </td>
                                <td>
                                    <div class="font-weight-bold"><?php echo h($s['problem_title']); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($s['competition_title'])): ?>
                                        <span class="badge badge-light border text-truncate" style="max-width: 180px;">
                                            <i class="fas fa-trophy text-warning mr-1"></i><?php echo h($s['competition_title']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-muted">Practice</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-info px-2 py-1 text-uppercase"><?php echo h($s['language']); ?></span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $badgeClass; ?> px-2 py-1 font-weight-bold" style="font-size: 0.85rem;">
                                        <?php echo h($s['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="font-weight-bold"><?php echo (int)$s['passed_test_cases']; ?></span> / <?php echo (int)$s['total_test_cases']; ?>
                                </td>
                                <td>
                                    <span class="font-weight-bold text-primary"><?php echo (float)$s['score']; ?> pts</span>
                                </td>
                                <td>
                                    <small class="text-muted"><i class="fas fa-stopwatch mr-1"></i><?php echo (int)$s['execution_time_ms']; ?> ms</small>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo date('M d, H:i:s', strtotime($s['submitted_at'])); ?></small>
                                </td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-view-code" 
                                        data-id="<?php echo $s['id']; ?>"
                                        data-student="<?php echo h($s['student_name']); ?>"
                                        data-problem="<?php echo h($s['problem_title']); ?>"
                                        data-lang="<?php echo h($s['language']); ?>"
                                        data-status="<?php echo h($s['status']); ?>"
                                        data-score="<?php echo (float)$s['score']; ?>"
                                        data-time="<?php echo (int)$s['execution_time_ms']; ?>"
                                        data-code="<?php echo htmlspecialchars($s['source_code'], ENT_QUOTES); ?>"
                                        data-compile="<?php echo htmlspecialchars($s['compile_output'] ?? '', ENT_QUOTES); ?>">
                                        <i class="fas fa-code mr-1"></i> View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Code Inspection Modal -->
<div class="modal fade" id="codeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <div>
                    <h5 class="modal-title font-weight-bold text-dark" id="modalTitle">Submission Details</h5>
                    <small class="text-muted" id="modalSubtitle"></small>
                </div>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-3">
                <div id="modalCompileBox" class="mb-3" style="display: none;">
                    <label class="font-weight-bold text-danger small mb-1"><i class="fas fa-exclamation-circle mr-1"></i>Compiler Output / Errors</label>
                    <pre id="modalCompileOutput" class="bg-dark text-warning p-3 rounded small font-family-monospace" style="max-height: 180px; overflow-y: auto;"></pre>
                </div>

                <div>
                    <label class="font-weight-bold text-dark small mb-1"><i class="fas fa-file-code mr-1"></i>Source Code</label>
                    <pre class="bg-light border p-3 rounded"><code id="modalSourceCode" class="font-family-monospace text-dark" style="white-space: pre-wrap; word-break: break-all;"></code></pre>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.btn-view-code').on('click', function() {
        var id = $(this).data('id');
        var student = $(this).data('student');
        var problem = $(this).data('problem');
        var lang = $(this).data('lang');
        var status = $(this).data('status');
        var score = $(this).data('score');
        var time = $(this).data('time');
        var code = $(this).data('code');
        var compileOut = $(this).data('compile');

        $('#modalTitle').html('Submission #' + id + ' • <span class="badge badge-info">' + lang.toUpperCase() + '</span> <span class="badge badge-primary">' + status + '</span>');
        $('#modalSubtitle').text(student + ' • ' + problem + ' • ' + score + ' pts • ' + time + ' ms');
        $('#modalSourceCode').text(code);

        if (compileOut && compileOut.trim() !== '') {
            $('#modalCompileOutput').text(compileOut);
            $('#modalCompileBox').show();
        } else {
            $('#modalCompileBox').hide();
        }

        $('#codeModal').modal('show');
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

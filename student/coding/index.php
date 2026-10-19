<?php
// student/coding/index.php
// Student Coding Arena & Competitions Hub

require_once __DIR__ . '/../../includes/student_header.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

$student_id = (int)$_SESSION['student_id'];
$is_platform_enabled = is_coding_platform_enabled($pdo);

if (!$is_platform_enabled) {
    ?>
    <div class="row justify-content-center my-5">
        <div class="col-md-8 text-center">
            <div class="card shadow-sm border-0 py-5 px-4 bg-white rounded-lg">
                <i class="fas fa-laptop-code fa-4x text-muted mb-3"></i>
                <h4 class="font-weight-bold text-dark">Online Coding Platform is Currently Inactive</h4>
                <p class="text-muted mb-4">The online coding and competition environment has been temporarily switched off by the system administration. Please check back later or contact your instructor.</p>
                <div>
                    <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="btn btn-primary font-weight-bold px-4">
                        <i class="fas fa-home mr-1"></i> Return to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/student_footer.php';
    exit;
}

// Student details
$student_class_id = (int)($current_student['class_id'] ?? 0);
$student_admin_id = (int)($current_student['admin_id'] ?? 1);

// Fetch batches enrolled by this student
$stmtBatches = $pdo->prepare("SELECT batch_id FROM batch_students WHERE student_id = ?");
$stmtBatches->execute([$student_id]);
$enrolled_batch_ids = $stmtBatches->fetchAll(PDO::FETCH_COLUMN);

// Fetch Competitions eligible for this student
$compSql = "
    SELECT c.*,
           COUNT(DISTINCT cp.problem_id) AS problem_count,
           (SELECT COUNT(*) FROM coding_submissions WHERE competition_id = c.id AND student_id = ?) AS my_submissions,
           (SELECT COUNT(DISTINCT problem_id) FROM coding_submissions WHERE competition_id = c.id AND student_id = ? AND status = 'AC') AS my_solved
    FROM coding_competitions c
    LEFT JOIN coding_competition_problems cp ON c.id = cp.competition_id
    WHERE c.is_active = 1
      AND (c.admin_id = ? OR c.admin_id = 1)
      AND (
          (c.class_id IS NULL AND c.batch_id IS NULL)
          OR (c.class_id = ?)
";
$compParams = [$student_id, $student_id, $student_admin_id, $student_class_id];

if (!empty($enrolled_batch_ids)) {
    $inClause = implode(',', array_map('intval', $enrolled_batch_ids));
    $compSql .= " OR c.batch_id IN ($inClause)";
}
$compSql .= " ) GROUP BY c.id ORDER BY c.start_time DESC";

$stmtC = $pdo->prepare($compSql);
$stmtC->execute($compParams);
$competitions = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Filters for problems
$search = trim($_GET['search'] ?? '');
$diff_filter = trim($_GET['diff'] ?? '');

// Fetch Practice Problems
$probSql = "
    SELECT p.*,
           (SELECT COUNT(*) FROM coding_submissions WHERE problem_id = p.id AND student_id = ?) AS my_attempts,
           (SELECT COUNT(*) FROM coding_submissions WHERE problem_id = p.id AND student_id = ? AND status = 'AC') AS my_solved,
           (SELECT COUNT(DISTINCT student_id) FROM coding_submissions WHERE problem_id = p.id AND status = 'AC') AS total_solvers
    FROM coding_problems p
    WHERE p.is_active = 1
      AND (p.admin_id = ? OR p.admin_id = 1)
";
$probParams = [$student_id, $student_id, $student_admin_id];

if ($search !== '') {
    $probSql .= " AND (p.title LIKE ? OR p.slug LIKE ?)";
    $probParams[] = "%$search%";
    $probParams[] = "%$search%";
}

if ($diff_filter !== '' && in_array($diff_filter, ['Easy', 'Medium', 'Hard'])) {
    $probSql .= " AND p.difficulty = ?";
    $probParams[] = $diff_filter;
}

$probSql .= " ORDER BY p.id ASC";
$stmtP = $pdo->prepare($probSql);
$stmtP->execute($probParams);
$problems = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$now_ts = time();
?>

<!-- Header Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm rounded-lg overflow-hidden" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);">
            <div class="card-body p-4 text-white d-flex align-items-center justify-content-between flex-wrap">
                <div class="mr-3">
                    <span class="badge badge-light text-primary font-weight-bold px-3 py-1 mb-2 text-uppercase letter-spacing-1">
                        <i class="fas fa-terminal mr-1"></i> Interactive Coding Arena
                    </span>
                    <h2 class="font-weight-bold mb-1">Code, Compile & Compete</h2>
                    <p class="text-white-50 mb-0">Master algorithms in Python, Java, C, and C++ with real-time test case grading.</p>
                </div>
                <div class="mt-3 mt-md-0 d-flex gap-2">
                    <span class="badge badge-dark bg-dark-50 px-3 py-2 text-white border border-light font-weight-bold">
                        <i class="fab fa-python text-info mr-1"></i> Python
                    </span>
                    <span class="badge badge-dark bg-dark-50 px-3 py-2 text-white border border-light font-weight-bold ml-1">
                        <i class="fab fa-java text-danger mr-1"></i> Java 17
                    </span>
                    <span class="badge badge-dark bg-dark-50 px-3 py-2 text-white border border-light font-weight-bold ml-1">
                        <i class="fas fa-code text-primary mr-1"></i> C++17
                    </span>
                    <span class="badge badge-dark bg-dark-50 px-3 py-2 text-white border border-light font-weight-bold ml-1">
                        <i class="fas fa-c text-warning mr-1"></i> C11
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Competitions Section -->
<?php if (!empty($competitions)): ?>
<div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-trophy text-warning mr-2"></i>Coding Competitions
        </h4>
        <span class="badge badge-primary px-3 py-1 font-weight-bold"><?php echo count($competitions); ?> Available</span>
    </div>

    <div class="row">
        <?php foreach ($competitions as $c): ?>
            <?php
                $st = strtotime($c['start_time']);
                $et = strtotime($c['end_time']);
                $is_live = ($now_ts >= $st && $now_ts <= $et);
                $is_upcoming = ($now_ts < $st);
                $is_ended = ($now_ts > $et);

                $borderClass = $is_live ? 'border-success' : 'border-0';
                $badge = '<span class="badge badge-secondary px-2 py-1 font-weight-bold">Concluded</span>';
                if ($is_live) {
                    $badge = '<span class="badge badge-success px-2 py-1 font-weight-bold animate-pulse"><i class="fas fa-circle mr-1" style="font-size:8px;"></i> Live Now</span>';
                } elseif ($is_upcoming) {
                    $badge = '<span class="badge badge-info px-2 py-1 font-weight-bold">Upcoming</span>';
                }
            ?>
            <div class="col-lg-6 mb-3">
                <div class="card shadow-sm <?php echo $borderClass; ?> rounded-lg h-100">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="font-weight-bold text-dark mb-0"><?php echo h($c['title']); ?></h5>
                                <?php echo $badge; ?>
                            </div>
                            <?php if (!empty($c['description'])): ?>
                                <p class="text-muted small mb-3"><?php echo h($c['description']); ?></p>
                            <?php endif; ?>

                            <div class="row text-muted small mb-3">
                                <div class="col-sm-6 mb-1">
                                    <i class="fas fa-play text-success mr-1"></i> <strong>Start:</strong> <?php echo date('M d, h:i A', $st); ?>
                                </div>
                                <div class="col-sm-6 mb-1">
                                    <i class="fas fa-stop text-danger mr-1"></i> <strong>End:</strong> <?php echo date('M d, h:i A', $et); ?>
                                </div>
                                <div class="col-sm-6 mb-1">
                                    <i class="fas fa-tasks text-primary mr-1"></i> <strong>Problems:</strong> <?php echo (int)$c['problem_count']; ?>
                                </div>
                                <div class="col-sm-6 mb-1">
                                    <i class="fas fa-check-circle text-success mr-1"></i> <strong>My Solved:</strong> <?php echo (int)$c['my_solved']; ?> / <?php echo (int)$c['problem_count']; ?>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                            <a href="<?php echo BASE_URL; ?>/student/coding/leaderboard.php?competition_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-secondary font-weight-bold">
                                <i class="fas fa-chart-line mr-1"></i> Leaderboard
                            </a>
                            <?php if ($is_live): ?>
                                <a href="<?php echo BASE_URL; ?>/student/coding/competition.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-success font-weight-bold px-3 shadow-sm">
                                    <i class="fas fa-laptop-code mr-1"></i> Enter Arena
                                </a>
                            <?php elseif ($is_upcoming): ?>
                                <button class="btn btn-sm btn-secondary font-weight-bold" disabled>
                                    <i class="fas fa-clock mr-1"></i> Starts <?php echo date('M d', $st); ?>
                                </button>
                            <?php else: ?>
                                <a href="<?php echo BASE_URL; ?>/student/coding/competition.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary font-weight-bold">
                                    <i class="fas fa-history mr-1"></i> Review Problems
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Practice Problems Section -->
<div class="card shadow-sm border-0 rounded-lg mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-code text-primary mr-2"></i>Practice Problem Bank
            </h5>
            <small class="text-muted">Sharpen your programming skills across difficulty tiers.</small>
        </div>
        <form method="GET" class="form-inline mt-2 mt-md-0">
            <div class="input-group input-group-sm mr-2 mb-1">
                <input type="text" name="search" class="form-control" placeholder="Search problem..." value="<?php echo h($search); ?>">
            </div>
            <div class="input-group input-group-sm mr-2 mb-1">
                <select name="diff" class="form-control" onchange="this.form.submit()">
                    <option value="">All Difficulties</option>
                    <option value="Easy" <?php echo $diff_filter === 'Easy' ? 'selected' : ''; ?>>Easy</option>
                    <option value="Medium" <?php echo $diff_filter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="Hard" <?php echo $diff_filter === 'Hard' ? 'selected' : ''; ?>>Hard</option>
                </select>
            </div>
            <button type="submit" class="btn btn-sm btn-primary mb-1 mr-1 font-weight-bold">Filter</button>
            <?php if ($search !== '' || $diff_filter !== ''): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary mb-1">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 50px;">Status</th>
                        <th>Problem Title</th>
                        <th style="width: 120px;">Difficulty</th>
                        <th style="width: 100px;">Points</th>
                        <th style="width: 120px;">Solved By</th>
                        <th class="text-right" style="width: 130px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($problems)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-code fa-3x mb-3 text-gray-300 d-block"></i>
                                No problems found in the bank matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($problems as $p): ?>
                            <?php
                                $is_solved = ((int)$p['my_solved'] > 0);
                                $has_attempted = ((int)$p['my_attempts'] > 0);
                                $statusIcon = '<i class="far fa-circle text-muted" title="Unattempted"></i>';
                                if ($is_solved) {
                                    $statusIcon = '<i class="fas fa-check-circle text-success" style="font-size: 1.2rem;" title="Solved (Accepted)"></i>';
                                } elseif ($has_attempted) {
                                    $statusIcon = '<i class="fas fa-adjust text-warning" style="font-size: 1.1rem;" title="Attempted"></i>';
                                }

                                $diffBadge = '<span class="badge badge-success px-2 py-1">Easy</span>';
                                if ($p['difficulty'] === 'Medium') {
                                    $diffBadge = '<span class="badge badge-warning text-dark px-2 py-1">Medium</span>';
                                } elseif ($p['difficulty'] === 'Hard') {
                                    $diffBadge = '<span class="badge badge-danger px-2 py-1">Hard</span>';
                                }
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $statusIcon; ?></td>
                                <td>
                                    <a href="<?php echo BASE_URL; ?>/student/coding/solve.php?id=<?php echo $p['id']; ?>" class="font-weight-bold text-dark text-decoration-none">
                                        <?php echo h($p['title']); ?>
                                    </a>
                                </td>
                                <td><?php echo $diffBadge; ?></td>
                                <td><span class="font-weight-bold text-primary"><?php echo (int)$p['total_points']; ?> pts</span></td>
                                <td><small class="text-muted"><i class="fas fa-users mr-1"></i><?php echo (int)$p['total_solvers']; ?> students</small></td>
                                <td class="text-right">
                                    <a href="<?php echo BASE_URL; ?>/student/coding/solve.php?id=<?php echo $p['id']; ?>" class="btn btn-sm <?php echo $is_solved ? 'btn-outline-success' : 'btn-primary'; ?> font-weight-bold px-3 shadow-sm">
                                        <?php echo $is_solved ? '<i class="fas fa-redo mr-1"></i> Solve Again' : '<i class="fas fa-play mr-1"></i> Solve'; ?>
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

<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>

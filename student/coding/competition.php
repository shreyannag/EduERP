<?php
// student/coding/competition.php
// Live Competition Arena for Students

require_once __DIR__ . '/../../includes/student_header.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

$student_id = (int)$_SESSION['student_id'];
$comp_id = (int)($_GET['id'] ?? 0);

if (!$comp_id) {
    header('Location: ' . BASE_URL . '/student/coding/index.php');
    exit;
}

$stmtC = $pdo->prepare("SELECT * FROM coding_competitions WHERE id = ? AND is_active = 1");
$stmtC->execute([$comp_id]);
$comp = $stmtC->fetch(PDO::FETCH_ASSOC);

if (!$comp) {
    echo "<div class='container my-5 alert alert-danger'>Competition not found or inactive. <a href='index.php'>Return to Arena</a></div>";
    require_once __DIR__ . '/../../includes/student_footer.php';
    exit;
}

// Fetch Problems in this competition
$stmtCP = $pdo->prepare("
    SELECT p.*, 
           cp.order_index, 
           cp.points AS contest_points,
           (SELECT COUNT(*) FROM coding_submissions WHERE competition_id = ? AND problem_id = p.id AND student_id = ?) AS my_attempts,
           (SELECT COUNT(*) FROM coding_submissions WHERE competition_id = ? AND problem_id = p.id AND student_id = ? AND status = 'AC') AS my_solved,
           (SELECT MAX(score) FROM coding_submissions WHERE competition_id = ? AND problem_id = p.id AND student_id = ?) AS my_best_score
    FROM coding_competition_problems cp
    JOIN coding_problems p ON cp.problem_id = p.id
    WHERE cp.competition_id = ?
    ORDER BY cp.order_index ASC, p.id ASC
");
$stmtCP->execute([$comp_id, $student_id, $comp_id, $student_id, $comp_id, $student_id, $comp_id]);
$problems = $stmtCP->fetchAll(PDO::FETCH_ASSOC);

$st_ts = strtotime($comp['start_time']);
$et_ts = strtotime($comp['end_time']);
$now_ts = time();

$is_upcoming = ($now_ts < $st_ts);
$is_live = ($now_ts >= $st_ts && $now_ts <= $et_ts);
$is_ended = ($now_ts > $et_ts);

// Calculate total contest points and student score
$total_contest_points = 0;
$my_total_score = 0;
$my_solved_count = 0;

foreach ($problems as $p) {
    $total_contest_points += (int)$p['contest_points'];
    $my_total_score += (float)($p['my_best_score'] ?? 0);
    if ((int)$p['my_solved'] > 0) {
        $my_solved_count++;
    }
}
?>

<!-- Competition Header Card -->
<div class="card shadow-sm border-0 rounded-lg mb-4 overflow-hidden" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
    <div class="card-body p-4 text-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
            <div>
                <a href="<?php echo BASE_URL; ?>/student/coding/index.php" class="text-white-50 small font-weight-bold mb-2 d-inline-block">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Coding Hub
                </a>
                <h2 class="font-weight-bold mb-1"><?php echo h($comp['title']); ?></h2>
                <div class="d-flex align-items-center flex-wrap text-white-50 small">
                    <span class="mr-3"><i class="fas fa-calendar-alt text-primary mr-1"></i> <?php echo date('M d, Y h:i A', $st_ts); ?> &mdash; <?php echo date('M d, Y h:i A', $et_ts); ?></span>
                    <span class="mr-3"><i class="fas fa-trophy text-warning mr-1"></i> <?php echo count($problems); ?> Problems (<?php echo $total_contest_points; ?> pts)</span>
                </div>
            </div>

            <!-- Countdown Timer -->
            <div class="mt-3 mt-md-0 text-center bg-dark p-3 rounded-lg border border-secondary">
                <small class="text-white-50 font-weight-bold d-block text-uppercase letter-spacing-1 mb-1">
                    <?php echo $is_live ? 'Time Remaining' : ($is_upcoming ? 'Starts In' : 'Status'); ?>
                </small>
                <?php if ($is_live): ?>
                    <h3 class="font-weight-bold text-warning mb-0 font-family-monospace" id="countdownTimer">
                        --:--:--
                    </h3>
                <?php elseif ($is_upcoming): ?>
                    <h4 class="font-weight-bold text-info mb-0 font-family-monospace" id="countdownTimer">
                        --:--:--
                    </h4>
                <?php else: ?>
                    <span class="badge badge-secondary px-3 py-2 font-weight-bold">CONTEST CONCLUDED</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($comp['description'])): ?>
            <p class="text-white-50 mb-2 small"><?php echo nl2br(h($comp['description'])); ?></p>
        <?php endif; ?>

        <?php if (!empty($comp['rules'])): ?>
            <div class="p-2 bg-dark rounded border border-secondary text-white-50 small mt-2">
                <strong class="text-warning"><i class="fas fa-gavel mr-1"></i> Contest Rules:</strong> <?php echo nl2br(h($comp['rules'])); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Stats Bar -->
<div class="row mb-4">
    <div class="col-md-4 mb-2 mb-md-0">
        <div class="card shadow-sm border-0 rounded-lg p-3 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-primary text-white rounded p-3 mr-3">
                    <i class="fas fa-award fa-2x"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase font-weight-bold">My Contest Score</small>
                    <h4 class="font-weight-bold text-primary mb-0"><?php echo $my_total_score; ?> <small class="text-muted font-weight-normal">/ <?php echo $total_contest_points; ?> pts</small></h4>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-2 mb-md-0">
        <div class="card shadow-sm border-0 rounded-lg p-3 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-success text-white rounded p-3 mr-3">
                    <i class="fas fa-check-circle fa-2x"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase font-weight-bold">Problems Solved</small>
                    <h4 class="font-weight-bold text-success mb-0"><?php echo $my_solved_count; ?> <small class="text-muted font-weight-normal">/ <?php echo count($problems); ?></small></h4>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card shadow-sm border-0 rounded-lg p-3 bg-white">
            <div class="d-flex align-items-center">
                <div class="bg-info text-white rounded p-3 mr-3">
                    <i class="fas fa-chart-line fa-2x"></i>
                </div>
                <div>
                    <small class="text-muted text-uppercase font-weight-bold">Live Standings</small>
                    <div>
                        <a href="<?php echo BASE_URL; ?>/student/coding/leaderboard.php?competition_id=<?php echo $comp['id']; ?>" class="btn btn-sm btn-outline-info font-weight-bold mt-1">
                            <i class="fas fa-list-ol mr-1"></i> View Full Leaderboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Contest Problem Set Table -->
<div class="card shadow-sm border-0 rounded-lg mb-4">
    <div class="card-header bg-white py-3">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-tasks text-primary mr-2"></i>Contest Problem Set
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 70px;">#</th>
                        <th class="text-left">Problem Title</th>
                        <th style="width: 120px;">Difficulty</th>
                        <th style="width: 130px;">Points</th>
                        <th style="width: 130px;">My Status</th>
                        <th style="width: 120px;">Attempts</th>
                        <th class="text-right" style="width: 160px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($problems)): ?>
                        <tr>
                            <td colspan="7" class="py-5 text-muted">No problems assigned to this competition yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($problems as $i => $p): ?>
                            <?php
                                $letter = chr(65 + $i);
                                $is_solved = ((int)$p['my_solved'] > 0);
                                $attempts = (int)$p['my_attempts'];

                                $statusBadge = '<span class="badge badge-light border text-muted">Unattempted</span>';
                                if ($is_solved) {
                                    $statusBadge = '<span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i> Solved</span>';
                                } elseif ($attempts > 0) {
                                    $statusBadge = '<span class="badge badge-warning text-dark px-2 py-1"><i class="fas fa-adjust mr-1"></i> Attempted</span>';
                                }

                                $diffBadge = '<span class="badge badge-success px-2 py-1">Easy</span>';
                                if ($p['difficulty'] === 'Medium') $diffBadge = '<span class="badge badge-warning text-dark px-2 py-1">Medium</span>';
                                if ($p['difficulty'] === 'Hard') $diffBadge = '<span class="badge badge-danger px-2 py-1">Hard</span>';
                            ?>
                            <tr>
                                <td class="font-weight-bold text-primary" style="font-size: 1.1rem;"><?php echo $letter; ?></td>
                                <td class="text-left">
                                    <div class="font-weight-bold text-dark" style="font-size: 1.05rem;">
                                        <?php echo h($p['title']); ?>
                                    </div>
                                    <small class="text-muted"><i class="fas fa-stopwatch mr-1"></i><?php echo (int)$p['time_limit_ms']; ?> ms &bull; <?php echo (int)$p['memory_limit_mb']; ?> MB</small>
                                </td>
                                <td><?php echo $diffBadge; ?></td>
                                <td><span class="font-weight-bold text-primary"><?php echo (int)$p['contest_points']; ?> pts</span></td>
                                <td><?php echo $statusBadge; ?></td>
                                <td><span class="font-weight-bold text-muted"><?php echo $attempts; ?></span></td>
                                <td class="text-right">
                                    <?php if ($is_live || $is_ended): ?>
                                        <a href="<?php echo BASE_URL; ?>/student/coding/solve.php?id=<?php echo $p['id']; ?>&competition_id=<?php echo $comp['id']; ?>" class="btn btn-sm <?php echo $is_solved ? 'btn-outline-success' : 'btn-primary'; ?> font-weight-bold px-3 shadow-sm">
                                            <?php echo $is_solved ? '<i class="fas fa-check mr-1"></i> Solved' : '<i class="fas fa-code mr-1"></i> Solve Now'; ?>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-secondary font-weight-bold" disabled>
                                            <i class="fas fa-lock mr-1"></i> Locked
                                        </button>
                                    <?php endif; ?>
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
    var endTime = <?php echo $is_live ? $et_ts * 1000 : ($is_upcoming ? $st_ts * 1000 : 0); ?>;
    
    if (endTime > 0) {
        function updateTimer() {
            var now = new Date().getTime();
            var diff = endTime - now;

            if (diff <= 0) {
                $('#countdownTimer').text('00:00:00');
                window.location.reload();
                return;
            }

            var hours = Math.floor(diff / (1000 * 60 * 60));
            var minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            var seconds = Math.floor((diff % (1000 * 60)) / 1000);

            var str = (hours < 10 ? '0' : '') + hours + ':' +
                      (minutes < 10 ? '0' : '') + minutes + ':' +
                      (seconds < 10 ? '0' : '') + seconds;

            $('#countdownTimer').text(str);
        }

        updateTimer();
        setInterval(updateTimer, 1000);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>

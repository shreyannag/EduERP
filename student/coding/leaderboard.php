<?php
// student/coding/leaderboard.php
// Student Live Competition Leaderboard

require_once __DIR__ . '/../../includes/student_header.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

$student_id = (int)$_SESSION['student_id'];
$comp_id = (int)($_GET['competition_id'] ?? 0);

if (!$comp_id) {
    header('Location: ' . BASE_URL . '/student/coding/index.php');
    exit;
}

$leaderboard_data = get_competition_leaderboard($pdo, $comp_id);

if (!$leaderboard_data || empty($leaderboard_data['competition'])) {
    echo "<div class='container my-5 alert alert-danger'>Competition not found. <a href='index.php'>Return to Coding Arena</a></div>";
    require_once __DIR__ . '/../../includes/student_footer.php';
    exit;
}

$comp = $leaderboard_data['competition'];
$problems = $leaderboard_data['problems'];
$ranks = $leaderboard_data['leaderboard'];

$now_ts = time();
$st_ts = strtotime($comp['start_time']);
$et_ts = strtotime($comp['end_time']);

$badge = '<span class="badge badge-secondary px-3 py-2 font-weight-bold">CONCLUDED</span>';
if ($now_ts < $st_ts) {
    $badge = '<span class="badge badge-info px-3 py-2 font-weight-bold">UPCOMING</span>';
} elseif ($now_ts >= $st_ts && $now_ts <= $et_ts) {
    $badge = '<span class="badge badge-success px-3 py-2 font-weight-bold animate-pulse"><i class="fas fa-circle mr-1" style="font-size:8px;"></i> LIVE NOW</span>';
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <a href="<?php echo BASE_URL; ?>/student/coding/competition.php?id=<?php echo $comp['id']; ?>" class="text-muted small font-weight-bold mb-1 d-inline-block">
            <i class="fas fa-arrow-left mr-1"></i> Back to Contest Arena
        </a>
        <h3 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-trophy text-warning mr-2"></i>Contest Standings & Leaderboard
        </h3>
        <p class="text-muted mb-0"><?php echo h($comp['title']); ?></p>
    </div>
    <div class="d-flex align-items-center mt-3 mt-md-0">
        <button type="button" class="btn btn-outline-primary font-weight-bold shadow-sm mr-2" onclick="window.location.reload();">
            <i class="fas fa-sync-alt mr-1"></i> Refresh Standings
        </button>
        <?php echo $badge; ?>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-lg mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">Official Standings</h5>
            <small class="text-muted">Ranked by total score, tie-break by least penalty time.</small>
        </div>
        <div class="mt-2 mt-md-0">
            <span class="badge badge-light border px-2 py-1 mr-2"><i class="fas fa-users mr-1"></i><?php echo count($ranks); ?> Contestants</span>
            <span class="badge badge-light border px-2 py-1"><i class="fas fa-tasks mr-1"></i><?php echo count($problems); ?> Problems</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 70px;">Rank</th>
                        <th class="text-left" style="min-width: 180px;">Student</th>
                        <th style="width: 110px;">Score</th>
                        <th style="width: 90px;">Solved</th>
                        <th style="width: 110px;">Penalty</th>
                        <?php foreach ($problems as $i => $p): ?>
                            <th style="min-width: 110px;">
                                <div class="font-weight-bold text-dark"><?php echo chr(65 + $i); ?></div>
                                <small class="text-muted font-weight-normal text-truncate d-block" style="max-width: 110px;" title="<?php echo h($p['title']); ?>">
                                    <?php echo h($p['title']); ?>
                                </small>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ranks)): ?>
                        <tr>
                            <td colspan="<?php echo 5 + count($problems); ?>" class="py-5 text-muted">
                                <i class="fas fa-user-clock fa-3x mb-3 text-gray-300 d-block"></i>
                                No submissions yet. Be the first to solve a problem and claim 1st place!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ranks as $r): ?>
                            <?php
                                $isMe = ((int)$r['student_id'] === $student_id);
                                $rowClass = $isMe ? 'table-primary font-weight-bold' : '';

                                $rankBadge = '<span class="font-weight-bold text-muted">#' . $r['rank'] . '</span>';
                                if ($r['rank'] === 1) {
                                    $rankBadge = '<span class="badge badge-warning text-dark px-2 py-1 font-weight-bold shadow-sm"><i class="fas fa-crown text-dark mr-1"></i> 1st</span>';
                                } elseif ($r['rank'] === 2) {
                                    $rankBadge = '<span class="badge badge-secondary px-2 py-1 font-weight-bold shadow-sm">2nd</span>';
                                } elseif ($r['rank'] === 3) {
                                    $rankBadge = '<span class="badge badge-danger px-2 py-1 font-weight-bold shadow-sm" style="background-color: #cd7f32;">3rd</span>';
                                }
                            ?>
                            <tr class="<?php echo $rowClass; ?>">
                                <td><?php echo $rankBadge; ?></td>
                                <td class="text-left">
                                    <div class="font-weight-bold text-dark">
                                        <?php echo h($r['full_name']); ?>
                                        <?php if ($isMe): ?>
                                            <span class="badge badge-primary ml-1">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted font-family-monospace"><?php echo h($r['username']); ?></small>
                                </td>
                                <td>
                                    <span class="font-weight-bold text-primary" style="font-size: 1.05rem;">
                                        <?php echo (float)$r['total_score']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-success px-2 py-1 font-weight-bold">
                                        <?php echo (int)$r['solved_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted font-weight-bold">
                                        <?php echo (int)$r['total_penalty_minutes']; ?> min
                                    </small>
                                </td>

                                <!-- Problem breakdown -->
                                <?php foreach ($problems as $p): ?>
                                    <?php
                                        $pid = $p['id'];
                                        $stat = $r['problem_stats'][$pid] ?? null;
                                    ?>
                                    <td>
                                        <?php if ($stat && $stat['solved']): ?>
                                            <div class="bg-success text-white rounded p-1 shadow-sm">
                                                <div class="font-weight-bold" style="font-size: 0.9rem;">+<?php echo $stat['score']; ?></div>
                                                <small style="font-size: 0.7rem; opacity: 0.9;"><?php echo $stat['solved_time_minutes']; ?>m (<?php echo $stat['attempts']; ?> tries)</small>
                                            </div>
                                        <?php elseif ($stat && $stat['attempts'] > 0): ?>
                                            <div class="bg-danger text-white rounded p-1 shadow-sm">
                                                <div class="font-weight-bold" style="font-size: 0.85rem;">-<?php echo $stat['attempts']; ?></div>
                                                <small style="font-size: 0.7rem; opacity: 0.9;">Unsolved</small>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>

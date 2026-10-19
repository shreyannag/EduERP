<?php
// admin/coding/leaderboard.php
// Admin / Instructor Live Coding Competition Leaderboard

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$effective_admin_id = get_effective_admin_id();

// Fetch Competitions for switcher
$compSql = "SELECT id, title, start_time, end_time, is_active FROM coding_competitions";
if ($effective_admin_id) {
    $compSql .= " WHERE admin_id = ?";
    $stmtAllComp = $pdo->prepare($compSql . " ORDER BY start_time DESC");
    $stmtAllComp->execute([$effective_admin_id]);
} else {
    $stmtAllComp = $pdo->query($compSql . " ORDER BY start_time DESC");
}
$competitions = $stmtAllComp->fetchAll(PDO::FETCH_ASSOC);

$comp_id = (int)($_GET['competition_id'] ?? 0);
if (!$comp_id && !empty($competitions)) {
    $comp_id = (int)$competitions[0]['id'];
}

$leaderboard_data = null;
if ($comp_id > 0) {
    $leaderboard_data = get_competition_leaderboard($pdo, $comp_id);
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2 class="h3 font-weight-bold text-gray-800 mb-1">
            <i class="fas fa-chart-line text-success mr-2"></i>Live Competition Standings
        </h2>
        <p class="text-muted mb-0">Real-time leaderboard rankings, solved problem counts, and penalty times.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-success font-weight-bold shadow-sm mr-2" onclick="window.location.reload();">
            <i class="fas fa-sync-alt mr-1"></i> Refresh Standings
        </button>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<!-- Competition Switcher -->
<div class="card shadow-sm border-0 mb-4 bg-white">
    <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap">
        <form method="GET" class="form-inline flex-grow-1 mr-3">
            <label class="font-weight-bold mr-2 text-dark"><i class="fas fa-trophy text-warning mr-1"></i> Select Competition:</label>
            <select name="competition_id" class="form-control font-weight-bold text-dark flex-grow-1" style="max-width: 500px;" onchange="this.form.submit()">
                <?php if (empty($competitions)): ?>
                    <option value="">No competitions scheduled</option>
                <?php else: ?>
                    <?php foreach ($competitions as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $comp_id === (int)$c['id'] ? 'selected' : ''; ?>>
                            <?php echo h($c['title']); ?> (<?php echo date('M d, Y', strtotime($c['start_time'])); ?>)
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </form>

        <?php if ($leaderboard_data && !empty($leaderboard_data['competition'])): ?>
            <?php 
                $comp = $leaderboard_data['competition']; 
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
            <div class="mt-2 mt-md-0">
                <?php echo $badge; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!$leaderboard_data || empty($leaderboard_data['competition'])): ?>
    <div class="card shadow-sm border-0 p-5 text-center text-muted">
        <i class="fas fa-trophy fa-3x mb-3 text-gray-300"></i>
        <h5>No Competition Selected or Found</h5>
        <p>Please schedule a competition first from the dashboard.</p>
    </div>
<?php else: ?>
    <?php
        $comp = $leaderboard_data['competition'];
        $problems = $leaderboard_data['problems'];
        $ranks = $leaderboard_data['leaderboard'];
    ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h5 class="font-weight-bold text-dark mb-1"><?php echo h($comp['title']); ?></h5>
                <small class="text-muted">
                    <i class="fas fa-calendar-alt mr-1"></i> <?php echo date('M d, Y h:i A', strtotime($comp['start_time'])); ?> &mdash; <?php echo date('M d, Y h:i A', strtotime($comp['end_time'])); ?>
                    • Target: <?php echo !empty($comp['batch_name']) ? h($comp['batch_name']) : (!empty($comp['class_name']) ? 'Class ' . h($comp['class_name']) : 'All Students'); ?>
                </small>
            </div>
            <div class="mt-2 mt-md-0">
                <span class="badge badge-light border px-2 py-1 mr-2"><i class="fas fa-users mr-1"></i><?php echo count($ranks); ?> Participants</span>
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
                                    <small class="text-muted font-weight-normal text-truncate d-block" style="max-width: 100px;" title="<?php echo h($p['title']); ?>">
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
                                    No submissions recorded yet for this competition.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ranks as $r): ?>
                                <?php
                                    $rankBadge = '<span class="font-weight-bold text-muted">#' . $r['rank'] . '</span>';
                                    if ($r['rank'] === 1) {
                                        $rankBadge = '<span class="badge badge-warning text-dark px-2 py-1 font-weight-bold shadow-sm"><i class="fas fa-crown text-dark mr-1"></i> 1st</span>';
                                    } elseif ($r['rank'] === 2) {
                                        $rankBadge = '<span class="badge badge-secondary px-2 py-1 font-weight-bold shadow-sm">2nd</span>';
                                    } elseif ($r['rank'] === 3) {
                                        $rankBadge = '<span class="badge badge-danger px-2 py-1 font-weight-bold shadow-sm" style="background-color: #cd7f32;">3rd</span>';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo $rankBadge; ?></td>
                                    <td class="text-left">
                                        <div class="font-weight-bold text-dark"><?php echo h($r['full_name']); ?></div>
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

                                    <!-- Problem columns -->
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
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

<?php
// admin/coding/index.php
// Online Coding Platform & Competitions Dashboard

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$scoped_admin_id = is_super_admin() ? (!empty($_GET['admin_id']) ? (int)$_GET['admin_id'] : null) : get_effective_admin_id();
$is_platform_enabled = is_coding_platform_enabled($pdo);

// Filters for problems
$search_problem = trim($_GET['search_prob'] ?? '');
$filter_diff = trim($_GET['diff'] ?? '');

// Fetch Problems
$prob_sql = "
    SELECT p.*, 
           COUNT(DISTINCT tc.id) AS test_case_count,
           COUNT(DISTINCT s.id) AS submission_count,
           SUM(CASE WHEN s.status = 'AC' THEN 1 ELSE 0 END) AS ac_count,
           a.username AS author_name
    FROM coding_problems p
    LEFT JOIN coding_test_cases tc ON p.id = tc.problem_id
    LEFT JOIN coding_submissions s ON p.id = s.problem_id
    LEFT JOIN admins a ON p.created_by = a.id
    WHERE 1=1
";
$prob_params = [];
if ($scoped_admin_id) {
    $prob_sql .= " AND (p.admin_id = ? OR p.admin_id = 1)";
    $prob_params[] = $scoped_admin_id;
}
if ($search_problem !== '') {
    $prob_sql .= " AND (p.title LIKE ? OR p.slug LIKE ?)";
    $prob_params[] = "%$search_problem%";
    $prob_params[] = "%$search_problem%";
}
if ($filter_diff !== '' && in_array($filter_diff, ['Easy', 'Medium', 'Hard'])) {
    $prob_sql .= " AND p.difficulty = ?";
    $prob_params[] = $filter_diff;
}
$prob_sql .= " GROUP BY p.id ORDER BY p.id DESC";
$stmtProbs = $pdo->prepare($prob_sql);
$stmtProbs->execute($prob_params);
$problems = $stmtProbs->fetchAll(PDO::FETCH_ASSOC);

// Fetch Competitions
$comp_sql = "
    SELECT c.*,
           cl.name AS class_name,
           b.name AS batch_name,
           COUNT(DISTINCT cp.problem_id) AS problem_count,
           COUNT(DISTINCT s.id) AS submission_count,
           COUNT(DISTINCT s.student_id) AS participant_count,
           a.username AS author_name
    FROM coding_competitions c
    LEFT JOIN classes cl ON c.class_id = cl.id
    LEFT JOIN batches b ON c.batch_id = b.id
    LEFT JOIN coding_competition_problems cp ON c.id = cp.competition_id
    LEFT JOIN coding_submissions s ON c.id = s.competition_id
    LEFT JOIN admins a ON c.created_by = a.id
    WHERE 1=1
";
$comp_params = [];
if ($scoped_admin_id) {
    $comp_sql .= " AND c.admin_id = ?";
    $comp_params[] = $scoped_admin_id;
}
$comp_sql .= " GROUP BY c.id ORDER BY c.start_time DESC";
$stmtComps = $pdo->prepare($comp_sql);
$stmtComps->execute($comp_params);
$competitions = $stmtComps->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall KPI statistics
$total_problems = count($problems);
$now_ts = time();
$active_competitions = 0;
foreach ($competitions as $c) {
    $st = strtotime($c['start_time']);
    $et = strtotime($c['end_time']);
    if ($now_ts >= $st && $now_ts <= $et && (int)$c['is_active'] === 1) {
        $active_competitions++;
    }
}

$stat_sql = "SELECT COUNT(*) as total_subs, SUM(CASE WHEN status = 'AC' THEN 1 ELSE 0 END) as ac_subs FROM coding_submissions";
if ($scoped_admin_id) {
    $stat_sql .= " WHERE problem_id IN (SELECT id FROM coding_problems WHERE admin_id = ? OR admin_id = 1)";
    $stmtStat = $pdo->prepare($stat_sql);
    $stmtStat->execute([$scoped_admin_id]);
} else {
    $stmtStat = $pdo->query($stat_sql);
}
$statRow = $stmtStat->fetch(PDO::FETCH_ASSOC);
$total_subs = (int)($statRow['total_subs'] ?? 0);
$ac_subs = (int)($statRow['ac_subs'] ?? 0);
$ac_rate = $total_subs > 0 ? round(($ac_subs / $total_subs) * 100, 1) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2 class="h3 font-weight-bold text-gray-800 mb-1">
            <i class="fas fa-laptop-code text-primary mr-2"></i>Online Coding Platform & Competitions
        </h2>
        <p class="text-muted mb-0">Multi-language code execution engine, problem bank, and live programming competitions.</p>
    </div>
    <div class="mt-2 mt-md-0 d-flex gap-2">
        <a href="<?php echo BASE_URL; ?>/admin/coding/problem_create.php" class="btn btn-primary font-weight-bold shadow-sm mr-2">
            <i class="fas fa-plus-circle mr-1"></i> New Problem
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/coding/competition_create.php" class="btn btn-success font-weight-bold shadow-sm mr-2">
            <i class="fas fa-trophy mr-1"></i> Host Competition
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/coding/submissions.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-terminal mr-1"></i> Submissions
        </a>
    </div>
</div>

<?php if (is_super_admin()): ?>
<!-- Super Admin Global Switch Banner -->
<div class="card shadow-sm border-left-<?php echo $is_platform_enabled ? 'success' : 'danger'; ?> mb-4 bg-white">
    <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap">
        <div class="d-flex align-items-center mr-3">
            <div class="mr-3">
                <i class="fas <?php echo $is_platform_enabled ? 'fa-check-circle text-success' : 'fa-power-off text-danger'; ?> fa-2x"></i>
            </div>
            <div>
                <h6 class="font-weight-bold mb-0 text-dark">
                    Global Coding Platform Status: 
                    <span id="platformStatusBadge" class="badge badge-<?php echo $is_platform_enabled ? 'success' : 'secondary'; ?> px-2 py-1 ml-1">
                        <?php echo $is_platform_enabled ? 'ACTIVE & ENABLED' : 'GLOBALLY DISABLED'; ?>
                    </span>
                </h6>
                <small class="text-muted">
                    When switched OFF, all tenant admins and students are instantly blocked from accessing coding modules.
                </small>
            </div>
        </div>
        <div class="custom-control custom-switch custom-switch-lg mt-2 mt-md-0">
            <input type="checkbox" class="custom-control-input" id="togglePlatformSwitch" <?php echo $is_platform_enabled ? 'checked' : ''; ?>>
            <label class="custom-control-label font-weight-bold text-dark cursor-pointer" for="togglePlatformSwitch">
                <span id="switchLabelText"><?php echo $is_platform_enabled ? 'Platform Enabled' : 'Platform Disabled'; ?></span>
            </label>
        </div>
    </div>
</div>
<?php elseif (!$is_platform_enabled): ?>
<div class="alert alert-warning shadow-sm border-0 d-flex align-items-center mb-4">
    <i class="fas fa-exclamation-triangle fa-2x mr-3 text-warning"></i>
    <div>
        <h6 class="font-weight-bold mb-1">Coding Platform Currently Inactive</h6>
        <p class="mb-0 small">The Super Administrator has temporarily disabled the Online Coding Platform. Code execution and competitions are currently suspended.</p>
    </div>
</div>
<?php endif; ?>

<!-- KPI Overview Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Problem Bank</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_problems); ?> Problems</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-code fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Live Competitions</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($active_competitions); ?> Running</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-trophy fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-info shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Submissions</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format($total_subs); ?> Runs</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-paper-plane fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow-sm h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Accepted (AC) Rate</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $ac_rate; ?>% <small class="text-muted font-weight-normal">(<?php echo number_format($ac_subs); ?> AC)</small></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-check-double fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-3 bg-white p-2 rounded shadow-sm border" id="codingTab" role="tablist">
    <li class="nav-item">
        <a class="nav-link active font-weight-bold" id="problems-tab" data-toggle="pill" href="#tab-problems" role="tab">
            <i class="fas fa-file-code mr-1"></i> Problem Repository (<?php echo count($problems); ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link font-weight-bold" id="competitions-tab" data-toggle="pill" href="#tab-competitions" role="tab">
            <i class="fas fa-trophy mr-1"></i> Coding Competitions (<?php echo count($competitions); ?>)
        </a>
    </li>
</ul>

<div class="tab-content" id="codingTabContent">
    <!-- TAB 1: PROBLEMS -->
    <div class="tab-pane fade show active" id="tab-problems" role="tabpanel">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-list mr-1"></i> Problem Repository
                </h6>
                <form method="GET" class="form-inline mt-2 mt-md-0">
                    <div class="input-group input-group-sm mr-2 mb-1">
                        <input type="text" name="search_prob" class="form-control" placeholder="Search problem..." value="<?php echo h($search_problem); ?>">
                    </div>
                    <div class="input-group input-group-sm mr-2 mb-1">
                        <select name="diff" class="form-control" onchange="this.form.submit()">
                            <option value="">All Difficulties</option>
                            <option value="Easy" <?php echo $filter_diff === 'Easy' ? 'selected' : ''; ?>>Easy</option>
                            <option value="Medium" <?php echo $filter_diff === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="Hard" <?php echo $filter_diff === 'Hard' ? 'selected' : ''; ?>>Hard</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-outline-primary mb-1 mr-1">Filter</button>
                    <?php if ($search_problem !== '' || $filter_diff !== ''): ?>
                        <a href="index.php" class="btn btn-sm btn-outline-secondary mb-1">Reset</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Problem Title</th>
                                <th>Difficulty</th>
                                <th>Points</th>
                                <th>Languages</th>
                                <th>Test Cases</th>
                                <th>Submissions</th>
                                <th class="text-right" style="width: 170px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($problems)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-code fa-3x mb-3 text-gray-300 d-block"></i>
                                        No coding problems found. Click <strong>New Problem</strong> to create your first question!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($problems as $i => $p): ?>
                                    <?php 
                                        $diffClass = 'badge-success';
                                        if ($p['difficulty'] === 'Medium') $diffClass = 'badge-warning text-dark';
                                        if ($p['difficulty'] === 'Hard') $diffClass = 'badge-danger';
                                    ?>
                                    <tr id="problem-row-<?php echo $p['id']; ?>">
                                        <td class="font-weight-bold text-muted"><?php echo $p['id']; ?></td>
                                        <td>
                                            <div class="font-weight-bold text-dark">
                                                <?php echo h($p['title']); ?>
                                            </div>
                                            <small class="text-muted font-family-monospace">/<?php echo h($p['slug']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $diffClass; ?> px-2 py-1"><?php echo h($p['difficulty']); ?></span>
                                        </td>
                                        <td>
                                            <span class="font-weight-bold text-primary"><?php echo (int)$p['total_points']; ?> pts</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-light border text-monospace text-uppercase" style="font-size:0.75rem;">
                                                <?php echo h(str_replace(',', ', ', $p['allowed_languages'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-info px-2 py-1"><?php echo (int)$p['test_case_count']; ?> cases</span>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <span class="font-weight-bold"><?php echo (int)$p['submission_count']; ?></span> total
                                                <?php if ((int)$p['submission_count'] > 0): ?>
                                                    <span class="text-success font-weight-bold ml-1">(<?php echo (int)$p['ac_count']; ?> AC)</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <a href="<?php echo BASE_URL; ?>/admin/coding/problem_edit.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Problem">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-problem" data-id="<?php echo $p['id']; ?>" data-title="<?php echo h($p['title']); ?>" title="Delete Problem">
                                                <i class="fas fa-trash-alt"></i>
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
    </div>

    <!-- TAB 2: COMPETITIONS -->
    <div class="tab-pane fade" id="tab-competitions" role="tabpanel">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                <h6 class="m-0 font-weight-bold text-success">
                    <i class="fas fa-trophy mr-1"></i> Hosted Competitions
                </h6>
                <a href="<?php echo BASE_URL; ?>/admin/coding/competition_create.php" class="btn btn-sm btn-success font-weight-bold">
                    <i class="fas fa-plus mr-1"></i> Create Competition
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Competition Title</th>
                                <th>Target Group</th>
                                <th>Schedule Window</th>
                                <th>Status</th>
                                <th>Problems</th>
                                <th>Participants</th>
                                <th class="text-right" style="width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($competitions)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5 text-muted">
                                        <i class="fas fa-trophy fa-3x mb-3 text-gray-300 d-block"></i>
                                        No coding competitions scheduled yet. Click <strong>Host Competition</strong> to launch one!
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($competitions as $c): ?>
                                    <?php
                                        $st = strtotime($c['start_time']);
                                        $et = strtotime($c['end_time']);
                                        $statusBadge = '<span class="badge badge-secondary">Ended</span>';
                                        if ((int)$c['is_active'] === 0) {
                                            $statusBadge = '<span class="badge badge-dark">Inactive</span>';
                                        } elseif ($now_ts < $st) {
                                            $statusBadge = '<span class="badge badge-info">Upcoming</span>';
                                        } elseif ($now_ts >= $st && $now_ts <= $et) {
                                            $statusBadge = '<span class="badge badge-success animate-pulse"><i class="fas fa-circle mr-1" style="font-size:8px;"></i> Live Now</span>';
                                        }
                                    ?>
                                    <tr id="comp-row-<?php echo $c['id']; ?>">
                                        <td class="font-weight-bold text-muted"><?php echo $c['id']; ?></td>
                                        <td>
                                            <div class="font-weight-bold text-dark"><?php echo h($c['title']); ?></div>
                                            <small class="text-muted">Host: <?php echo h($c['author_name'] ?? 'Admin'); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($c['batch_name'])): ?>
                                                <span class="badge badge-primary px-2 py-1"><i class="fas fa-layer-group mr-1"></i><?php echo h($c['batch_name']); ?></span>
                                            <?php elseif (!empty($c['class_name'])): ?>
                                                <span class="badge badge-info px-2 py-1">Class <?php echo h($c['class_name']); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary px-2 py-1">All Students</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <div><i class="fas fa-calendar-alt text-muted mr-1"></i> <strong>Start:</strong> <?php echo date('M d, Y h:i A', $st); ?></div>
                                            <div><i class="fas fa-hourglass-end text-muted mr-1"></i> <strong>End:</strong> <?php echo date('M d, Y h:i A', $et); ?></div>
                                        </td>
                                        <td><?php echo $statusBadge; ?></td>
                                        <td><span class="badge badge-light border font-weight-bold px-2 py-1"><?php echo (int)$c['problem_count']; ?> problems</span></td>
                                        <td><span class="badge badge-light border font-weight-bold px-2 py-1"><?php echo (int)$c['participant_count']; ?> students</span></td>
                                        <td class="text-right">
                                            <a href="<?php echo BASE_URL; ?>/admin/coding/leaderboard.php?competition_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-success mr-1" title="View Live Leaderboard">
                                                <i class="fas fa-chart-line"></i> Standings
                                            </a>
                                            <a href="<?php echo BASE_URL; ?>/admin/coding/competition_edit.php?id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary mr-1" title="Edit Competition">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger btn-delete-comp" data-id="<?php echo $c['id']; ?>" data-title="<?php echo h($c['title']); ?>" title="Delete Competition">
                                                <i class="fas fa-trash-alt"></i>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    var csrfToken = '<?php echo generate_csrf_token(); ?>';

    // Super Admin Switch Handler
    $('#togglePlatformSwitch').on('change', function() {
        var isChecked = $(this).is(':checked');
        var switchEl = $(this);

        Swal.fire({
            title: isChecked ? 'Enable Online Coding Platform?' : 'Disable Online Coding Platform?',
            text: isChecked 
                ? 'This will make the coding arena and competitions accessible to all instructors and students.'
                : 'This will instantly restrict access to the coding platform for all admins and students.',
            icon: isChecked ? 'question' : 'warning',
            showCancelButton: true,
            confirmButtonColor: isChecked ? '#10b981' : '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: isChecked ? 'Yes, Enable It' : 'Yes, Disable It'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'toggle_platform_ajax.php',
                    type: 'POST',
                    data: {
                        csrf_token: csrfToken,
                        enabled: isChecked ? 1 : 0
                    },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Success',
                                text: resp.message,
                                timer: 1500,
                                showConfirmButton: false
                            });
                            $('#platformStatusBadge')
                                .toggleClass('badge-success', isChecked)
                                .toggleClass('badge-secondary', !isChecked)
                                .text(isChecked ? 'ACTIVE & ENABLED' : 'GLOBALLY DISABLED');
                            $('#switchLabelText').text(isChecked ? 'Platform Enabled' : 'Platform Disabled');
                        } else {
                            Swal.fire('Error', resp.error || 'Failed to toggle platform.', 'error');
                            switchEl.prop('checked', !isChecked);
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Server connection failure.', 'error');
                        switchEl.prop('checked', !isChecked);
                    }
                });
            } else {
                switchEl.prop('checked', !isChecked);
            }
        });
    });

    // Delete Problem Handler
    $('.btn-delete-problem').on('click', function() {
        var probId = $(this).data('id');
        var probTitle = $(this).data('title');

        Swal.fire({
            title: 'Delete Problem?',
            text: 'Are you sure you want to delete "' + probTitle + '"? This will remove all associated test cases and submissions.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Delete Problem'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'problem_delete_ajax.php',
                    type: 'POST',
                    data: {
                        csrf_token: csrfToken,
                        problem_id: probId
                    },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.success) {
                            $('#problem-row-' + probId).fadeOut(400, function() { $(this).remove(); });
                            Swal.fire('Deleted!', resp.message, 'success');
                        } else {
                            Swal.fire('Error', resp.error || 'Failed to delete problem.', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Network or server error.', 'error');
                    }
                });
            }
        });
    });

    // Delete Competition Handler
    $('.btn-delete-comp').on('click', function() {
        var compId = $(this).data('id');
        var compTitle = $(this).data('title');

        Swal.fire({
            title: 'Delete Competition?',
            text: 'Are you sure you want to delete contest "' + compTitle + '"? Leaderboards and contest submissions will be permanently removed.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Delete Contest'
        }).then(function(result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'competition_delete_ajax.php',
                    type: 'POST',
                    data: {
                        csrf_token: csrfToken,
                        competition_id: compId
                    },
                    dataType: 'json',
                    success: function(resp) {
                        if (resp.success) {
                            $('#comp-row-' + compId).fadeOut(400, function() { $(this).remove(); });
                            Swal.fire('Deleted!', resp.message, 'success');
                        } else {
                            Swal.fire('Error', resp.error || 'Failed to delete contest.', 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Network or server error.', 'error');
                    }
                });
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

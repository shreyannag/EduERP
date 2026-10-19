<?php
// admin/coding/competition_create.php
// Host a New Online Coding Competition

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$effective_admin_id = get_effective_admin_id();
$author_id = (int)$_SESSION['admin_id'];

// Fetch Classes
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Batches
if ($effective_admin_id) {
    if (is_teacher()) {
        $stmtB = $pdo->prepare("SELECT id, name FROM batches WHERE admin_id = ? AND teacher_id = ? AND is_active = 1 ORDER BY name ASC");
        $stmtB->execute([$effective_admin_id, (int)$_SESSION['admin_id']]);
    } else {
        $stmtB = $pdo->prepare("SELECT id, name FROM batches WHERE admin_id = ? AND is_active = 1 ORDER BY name ASC");
        $stmtB->execute([$effective_admin_id]);
    }
    $batches = $stmtB->fetchAll(PDO::FETCH_ASSOC);
} else {
    $batches = $pdo->query("SELECT id, name FROM batches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch available coding problems
$probSql = "SELECT id, title, slug, difficulty, total_points FROM coding_problems WHERE is_active = 1";
if ($effective_admin_id) {
    $probSql .= " AND (admin_id = ? OR admin_id = 1)";
    $stmtP = $pdo->prepare($probSql . " ORDER BY id DESC");
    $stmtP->execute([$effective_admin_id]);
} else {
    $stmtP = $pdo->query($probSql . " ORDER BY id DESC");
}
$available_problems = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $rules = trim($_POST['rules'] ?? '');
    $target_type = $_POST['target_type'] ?? 'all';
    $class_id = ($target_type === 'class' && !empty($_POST['class_id'])) ? (int)$_POST['class_id'] : null;
    $batch_id = ($target_type === 'batch' && !empty($_POST['batch_id'])) ? (int)$_POST['batch_id'] : null;
    
    $start_time_raw = trim($_POST['start_time'] ?? '');
    $end_time_raw = trim($_POST['end_time'] ?? '');

    $selected_problems = $_POST['selected_problems'] ?? [];
    $prob_points = $_POST['prob_points'] ?? [];
    $prob_order = $_POST['prob_order'] ?? [];

    if (empty($title)) {
        $error = 'Competition title is required.';
    } elseif (empty($start_time_raw) || empty($end_time_raw)) {
        $error = 'Both Start Time and End Time are required.';
    } else {
        $st_ts = strtotime($start_time_raw);
        $et_ts = strtotime($end_time_raw);

        if ($st_ts === false || $et_ts === false) {
            $error = 'Invalid start or end date/time format.';
        } elseif ($et_ts <= $st_ts) {
            $error = 'End Time must be after Start Time.';
        } elseif (empty($selected_problems)) {
            $error = 'Please select at least one problem for this competition.';
        } else {
            $start_time = date('Y-m-d H:i:s', $st_ts);
            $end_time = date('Y-m-d H:i:s', $et_ts);

            try {
                $pdo->beginTransaction();

                $stmtComp = $pdo->prepare("
                    INSERT INTO coding_competitions (
                        admin_id, created_by, title, description, rules,
                        class_id, batch_id, start_time, end_time, is_active
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, 1
                    )
                ");
                $stmtComp->execute([
                    $effective_admin_id, $author_id, $title, $description, $rules,
                    $class_id, $batch_id, $start_time, $end_time
                ]);
                $comp_id = (int)$pdo->lastInsertId();

                $stmtCP = $pdo->prepare("
                    INSERT INTO coding_competition_problems (competition_id, problem_id, order_index, points)
                    VALUES (?, ?, ?, ?)
                ");

                $orderIdx = 1;
                foreach ($selected_problems as $pid) {
                    $pid = (int)$pid;
                    $pts = max(1, (int)($prob_points[$pid] ?? 100));
                    $ord = isset($prob_order[$pid]) ? (int)$prob_order[$pid] : $orderIdx;
                    $stmtCP->execute([$comp_id, $pid, $ord, $pts]);
                    $orderIdx++;
                }

                $pdo->commit();
                header('Location: ' . BASE_URL . '/admin/coding/index.php?msg=comp_created');
                exit;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to create competition: ' . $e->getMessage();
            }
        }
    }
}
?>

<!-- Flatpickr CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2 class="h3 font-weight-bold text-gray-800 mb-1">
            <i class="fas fa-trophy text-success mr-2"></i>Host Online Coding Competition
        </h2>
        <p class="text-muted mb-0">Schedule timed coding contests with live leaderboard rankings for your students.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo h($error); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<form method="POST" id="compForm">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

    <div class="row">
        <!-- Left: Competition Info & Problem Selection -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-success"><i class="fas fa-info-circle mr-2"></i>Competition Information</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold">Competition Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Spring 2026 Code Sprint - Class 10" value="<?php echo h($_POST['title'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Overview & Description</label>
                        <textarea name="description" rows="3" class="form-control" placeholder="Brief welcome message and competition overview..."><?php echo h($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Rules & Guidelines</label>
                        <textarea name="rules" rows="3" class="form-control" placeholder="e.g. Plagiarism is strictly prohibited. Submissions are judged automatically on hidden test cases..."><?php echo h($_POST['rules'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Problem Selection List -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-tasks mr-2"></i>Select Contest Problems <span class="text-danger">*</span></h6>
                        <small class="text-muted">Choose which problems will appear in this competition and customize their points.</small>
                    </div>
                    <a href="problem_create.php" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold">
                        <i class="fas fa-plus mr-1"></i> Add New Problem
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 40px;">Select</th>
                                    <th>Problem</th>
                                    <th>Difficulty</th>
                                    <th style="width: 130px;">Points in Contest</th>
                                    <th style="width: 100px;">Order</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($available_problems)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">
                                            No problems available in the repository. Please create problems first.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($available_problems as $idx => $p): ?>
                                        <?php 
                                            $diffClass = 'badge-success';
                                            if ($p['difficulty'] === 'Medium') $diffClass = 'badge-warning text-dark';
                                            if ($p['difficulty'] === 'Hard') $diffClass = 'badge-danger';
                                        ?>
                                        <tr>
                                            <td class="text-center">
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" name="selected_problems[]" value="<?php echo $p['id']; ?>" class="custom-control-input prob-check" id="probCheck<?php echo $p['id']; ?>">
                                                    <label class="custom-control-label" for="probCheck<?php echo $p['id']; ?>"></label>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="font-weight-bold text-dark"><?php echo h($p['title']); ?></div>
                                                <small class="text-muted">/<?php echo h($p['slug']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $diffClass; ?> px-2 py-1"><?php echo h($p['difficulty']); ?></span>
                                            </td>
                                            <td>
                                                <input type="number" name="prob_points[<?php echo $p['id']; ?>]" class="form-control form-control-sm font-weight-bold" value="<?php echo (int)$p['total_points']; ?>" min="1">
                                            </td>
                                            <td>
                                                <input type="number" name="prob_order[<?php echo $p['id']; ?>]" class="form-control form-control-sm" value="<?php echo $idx + 1; ?>" min="1">
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

        <!-- Right: Audience & Schedule -->
        <div class="col-lg-4">
            <!-- Target Audience Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-users mr-2"></i>Eligible Students</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold">Target Scope</label>
                        <select name="target_type" id="targetTypeSelect" class="form-control">
                            <option value="all">Open to All Students</option>
                            <option value="class">Specific Class</option>
                            <option value="batch">Specific Teaching Batch</option>
                        </select>
                    </div>

                    <div class="form-group" id="classSelectGroup" style="display: none;">
                        <label class="font-weight-bold">Select Class</label>
                        <select name="class_id" class="form-control">
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?php echo $cl['id']; ?>">Class <?php echo h($cl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="batchSelectGroup" style="display: none;">
                        <label class="font-weight-bold">Select Batch</label>
                        <select name="batch_id" class="form-control">
                            <option value="">-- Choose Batch --</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo h($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Schedule Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-clock mr-2"></i>Contest Timing</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-play text-success mr-1"></i>Start Date & Time <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="start_time" id="startTimePicker" class="form-control font-weight-bold" required value="<?php echo date('Y-m-d H:i'); ?>">
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-stop text-danger mr-1"></i>End Date & Time <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="end_time" id="endTimePicker" class="form-control font-weight-bold text-danger" required value="<?php echo date('Y-m-d H:i', strtotime('+3 hours')); ?>">
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-success btn-block font-weight-bold py-2 shadow-sm">
                        <i class="fas fa-trophy mr-1"></i> Launch Competition
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Flatpickr JS -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
$(document).ready(function() {
    flatpickr("#startTimePicker", {
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        time_24hr: true
    });
    flatpickr("#endTimePicker", {
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        time_24hr: true
    });

    $('#targetTypeSelect').on('change', function() {
        var val = $(this).val();
        $('#classSelectGroup').toggle(val === 'class');
        $('#batchSelectGroup').toggle(val === 'batch');
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

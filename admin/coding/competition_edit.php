<?php
// admin/coding/competition_edit.php
// Edit Existing Coding Competition

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$comp_id = (int)($_GET['id'] ?? 0);
if (!$comp_id) {
    header('Location: ' . BASE_URL . '/admin/coding/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM coding_competitions WHERE id = ?");
$stmt->execute([$comp_id]);
$comp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comp) {
    echo "<div class='container my-5 alert alert-danger'>Competition not found. <a href='index.php'>Return to Dashboard</a></div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin() && (int)$comp['admin_id'] !== $effective_admin_id) {
    echo "<div class='container my-5 alert alert-danger'>Access Denied: You do not own this competition.</div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

// Fetch currently mapped problems
$stmtCP = $pdo->prepare("SELECT problem_id, points, order_index FROM coding_competition_problems WHERE competition_id = ?");
$stmtCP->execute([$comp_id]);
$assigned_problems = [];
while ($row = $stmtCP->fetch(PDO::FETCH_ASSOC)) {
    $assigned_problems[$row['problem_id']] = $row;
}

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
$success = '';

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
    $is_active = isset($_POST['is_active']) ? 1 : 0;

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

                $stmtUpdateComp = $pdo->prepare("
                    UPDATE coding_competitions SET
                        title = ?, description = ?, rules = ?,
                        class_id = ?, batch_id = ?, start_time = ?, end_time = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmtUpdateComp->execute([
                    $title, $description, $rules,
                    $class_id, $batch_id, $start_time, $end_time, $is_active,
                    $comp_id
                ]);

                // Update problems mapping
                $pdo->prepare("DELETE FROM coding_competition_problems WHERE competition_id = ?")->execute([$comp_id]);

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
                $success = 'Competition details updated successfully!';

                // Reload comp and assigned
                $stmt->execute([$comp_id]);
                $comp = $stmt->fetch(PDO::FETCH_ASSOC);
                $stmtCP->execute([$comp_id]);
                $assigned_problems = [];
                $stmtAssigned = $pdo->prepare("SELECT problem_id, points, order_index FROM coding_competition_problems WHERE competition_id = ?");
                $stmtAssigned->execute([$comp_id]);
                while ($r = $stmtAssigned->fetch(PDO::FETCH_ASSOC)) {
                    $assigned_problems[$r['problem_id']] = $r;
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to update competition: ' . $e->getMessage();
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
            <i class="fas fa-edit text-primary mr-2"></i>Edit Competition: <?php echo h($comp['title']); ?>
        </h2>
        <p class="text-muted mb-0">Modify schedule, problem set, or participant targeting.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success shadow-sm alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle mr-2"></i> <?php echo h($success); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo h($error); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<form method="POST" id="compEditForm">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

    <div class="row">
        <!-- Left: Competition Info & Problem Selection -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-info-circle mr-2"></i>Competition Information</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold">Competition Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required value="<?php echo h($comp['title']); ?>">
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Overview & Description</label>
                        <textarea name="description" rows="3" class="form-control"><?php echo h($comp['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Rules & Guidelines</label>
                        <textarea name="rules" rows="3" class="form-control"><?php echo h($comp['rules'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Problem Selection List -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-tasks mr-2"></i>Select Contest Problems <span class="text-danger">*</span></h6>
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
                                            No problems available.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($available_problems as $idx => $p): ?>
                                        <?php 
                                            $is_assigned = isset($assigned_problems[$p['id']]);
                                            $assigned_pts = $is_assigned ? $assigned_problems[$p['id']]['points'] : $p['total_points'];
                                            $assigned_ord = $is_assigned ? $assigned_problems[$p['id']]['order_index'] : ($idx + 1);
                                            $diffClass = 'badge-success';
                                            if ($p['difficulty'] === 'Medium') $diffClass = 'badge-warning text-dark';
                                            if ($p['difficulty'] === 'Hard') $diffClass = 'badge-danger';
                                        ?>
                                        <tr>
                                            <td class="text-center">
                                                <div class="custom-control custom-checkbox">
                                                    <input type="checkbox" name="selected_problems[]" value="<?php echo $p['id']; ?>" class="custom-control-input prob-check" id="probCheck<?php echo $p['id']; ?>" <?php echo $is_assigned ? 'checked' : ''; ?>>
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
                                                <input type="number" name="prob_points[<?php echo $p['id']; ?>]" class="form-control form-control-sm font-weight-bold" value="<?php echo (int)$assigned_pts; ?>" min="1">
                                            </td>
                                            <td>
                                                <input type="number" name="prob_order[<?php echo $p['id']; ?>]" class="form-control form-control-sm" value="<?php echo (int)$assigned_ord; ?>" min="1">
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
                    <?php
                        $target_mode = 'all';
                        if (!empty($comp['batch_id'])) $target_mode = 'batch';
                        elseif (!empty($comp['class_id'])) $target_mode = 'class';
                    ?>
                    <div class="form-group">
                        <label class="font-weight-bold">Target Scope</label>
                        <select name="target_type" id="targetTypeSelect" class="form-control">
                            <option value="all" <?php echo $target_mode === 'all' ? 'selected' : ''; ?>>Open to All Students</option>
                            <option value="class" <?php echo $target_mode === 'class' ? 'selected' : ''; ?>>Specific Class</option>
                            <option value="batch" <?php echo $target_mode === 'batch' ? 'selected' : ''; ?>>Specific Teaching Batch</option>
                        </select>
                    </div>

                    <div class="form-group" id="classSelectGroup" style="<?php echo $target_mode === 'class' ? '' : 'display: none;'; ?>">
                        <label class="font-weight-bold">Select Class</label>
                        <select name="class_id" class="form-control">
                            <option value="">-- Choose Class --</option>
                            <?php foreach ($classes as $cl): ?>
                                <option value="<?php echo $cl['id']; ?>" <?php echo (int)($comp['class_id'] ?? 0) === (int)$cl['id'] ? 'selected' : ''; ?>>Class <?php echo h($cl['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" id="batchSelectGroup" style="<?php echo $target_mode === 'batch' ? '' : 'display: none;'; ?>">
                        <label class="font-weight-bold">Select Batch</label>
                        <select name="batch_id" class="form-control">
                            <option value="">-- Choose Batch --</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo (int)($comp['batch_id'] ?? 0) === (int)$b['id'] ? 'selected' : ''; ?>><?php echo h($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Schedule Card -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-clock mr-2"></i>Contest Timing & Status</h6>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-play text-success mr-1"></i>Start Date & Time <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="start_time" id="startTimePicker" class="form-control font-weight-bold" required value="<?php echo date('Y-m-d H:i', strtotime($comp['start_time'])); ?>">
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-stop text-danger mr-1"></i>End Date & Time <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="end_time" id="endTimePicker" class="form-control font-weight-bold text-danger" required value="<?php echo date('Y-m-d H:i', strtotime($comp['end_time'])); ?>">
                    </div>

                    <div class="custom-control custom-switch mb-0">
                        <input type="checkbox" name="is_active" class="custom-control-input" id="activeSwitch" <?php echo (int)$comp['is_active'] === 1 ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="activeSwitch">Active Competition</label>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2 shadow-sm">
                        <i class="fas fa-save mr-1"></i> Update Competition
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

<?php
// admin/exams/create.php
require_once '../../includes/admin_header.php';

require_admin_feature('exams');

$title = '';
$class_id = '';
$board_id = '';
$scheduled_date = '';
$scheduled_time = '';
$duration = 60;
$error = '';
$is_edit = false;
$edit_id = intval($_GET['edit'] ?? 0);

if ($edit_id) {
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
    $stmt->execute([$edit_id]);
    if ($ex = $stmt->fetch()) {
        if (strpos($ex['status'], 'draft') !== false) {
            $is_edit = true;
            $title = $ex['title'];
            $class_id = $ex['class_id'];
            $board_id = $ex['board_id'];
            $scheduled_date = $ex['scheduled_date'];
            $scheduled_time = date('H:i', strtotime($ex['scheduled_time']));
            $duration = $ex['duration_minutes'];
            $exam_type = $ex['exam_type'];
        }
    }
} else {
    $exam_type = 'regular';
}

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll();
$boards = $pdo->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $title = trim($_POST['title'] ?? '');
    $class_id = intval($_POST['class_id'] ?? 0);
    $board_id = intval($_POST['board_id'] ?? 0);
    $scheduled_date = $_POST['scheduled_date'] ?? '';
    $scheduled_time = $_POST['scheduled_time'] ?? '';
    $duration = intval($_POST['duration_minutes'] ?? 0);
    $exam_type = $_POST['exam_type'] ?? 'regular';
    
    if (!$title || !$class_id || !$board_id || !$scheduled_date || !$scheduled_time || $duration < 10) {
        $error = "All fields are required. Duration must be at least 10 minutes.";
    } else {
        if ($is_edit) {
            $stmt = $pdo->prepare("UPDATE exams SET title=?, class_id=?, board_id=?, scheduled_date=?, scheduled_time=?, duration_minutes=?, exam_type=? WHERE id=?");
            if ($stmt->execute([$title, $class_id, $board_id, $scheduled_date, $scheduled_time, $duration, $exam_type, $edit_id])) {
                header("Location: select_questions.php?exam_id=$edit_id");
                exit;
            } else {
                $error = "Failed to update exam.";
            }
        } else {
            $admin_id = (int)$_SESSION['admin_id'];
            $stmt = $pdo->prepare("INSERT INTO exams (title, class_id, board_id, scheduled_date, scheduled_time, duration_minutes, exam_type, status, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)");
            if ($stmt->execute([$title, $class_id, $board_id, $scheduled_date, $scheduled_time, $duration, $exam_type, $admin_id])) {
                $new_exam_id = $pdo->lastInsertId();
                header("Location: select_questions.php?exam_id=$new_exam_id");
                exit;
            } else {
                $error = "Failed to create exam.";
            }
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark"><?php echo $is_edit ? 'Edit' : 'Create'; ?> Exam <span class="text-muted font-weight-normal h4 d-block d-md-inline ml-md-2 mt-2 mt-md-0">Step 1: Protocol Settings</span></h2>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm py-3 rounded-lg"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo h($error); ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-4 p-md-5">
        <form method="POST" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            
            <div class="form-group mb-4 pb-2">
                <label class="font-weight-bold text-muted small text-uppercase">Assessment Outline Title</label>
                <input type="text" name="title" class="form-control form-control-lg text-dark shadow-sm bg-light border-0" value="<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>" required autofocus placeholder="e.g. Mid-term Mathematics Mock">
                <div class="invalid-feedback">Please provide a descriptive title.</div>
            </div>
            
            <div class="row">
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Target Class Segment</label>
                    <select name="class_id" class="form-control custom-select bg-light border-0 shadow-sm" required>
                        <option value="">Select Class...</option>
                        <?php foreach($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $class_id == $c['id'] ? 'selected' : ''; ?>>Class <?php echo h($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Board Specification</label>
                    <select name="board_id" class="form-control custom-select bg-light border-0 shadow-sm" required>
                        <option value="">Select Board...</option>
                        <?php foreach($boards as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $board_id == $b['id'] ? 'selected' : ''; ?>><?php echo h($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row border-top pt-4 mt-2">
                <div class="col-md-4 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Scheduled Open Date</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-calendar-alt text-muted"></i></span></div>
                        <input type="text" name="scheduled_date" id="scheduled_date" class="form-control border-0 bg-white" value="<?php echo htmlspecialchars($scheduled_date, ENT_QUOTES, 'UTF-8'); ?>" required placeholder="YYYY-MM-DD">
                    </div>
                </div>
                <div class="col-md-4 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Scheduled Launch Time</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-clock text-muted"></i></span></div>
                        <input type="text" name="scheduled_time" id="scheduled_time" class="form-control border-0 bg-white" value="<?php echo htmlspecialchars($scheduled_time, ENT_QUOTES, 'UTF-8'); ?>" required placeholder="HH:MM">
                    </div>
                </div>
                <div class="col-md-4 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Allowed Duration (Min)</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-stopwatch text-muted"></i></span></div>
                        <input type="number" name="duration_minutes" class="form-control border-0 bg-white" value="<?php echo htmlspecialchars($duration, ENT_QUOTES, 'UTF-8'); ?>" min="10" required>
                    </div>
                </div>
                <div class="col-md-3 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Exam Type</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-tag text-muted"></i></span></div>
                        <select name="exam_type" class="form-control border-0 bg-white" required>
                            <option value="regular" <?php echo $exam_type == 'regular' ? 'selected' : ''; ?>>Regular Exam</option>
                            <option value="weekly_mock" <?php echo $exam_type == 'weekly_mock' ? 'selected' : ''; ?>>Weekly Mock Test</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 pt-3 text-right">
                <button type="submit" class="btn btn-primary font-weight-bold px-5 py-3 shadow rounded-pill btn-lg">Define Configuration <i class="fas fa-arrow-right ml-2 opacity-75"></i></button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    flatpickr("#scheduled_date", { minDate: "today", dateFormat: "Y-m-d" });
    flatpickr("#scheduled_time", { enableTime: true, noCalendar: true, dateFormat: "H:i", time_24hr: true });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

<?php
// admin/exams/release_answers.php
require_once '../../includes/admin_header.php';

$exam_id = intval($_GET['exam_id'] ?? 0);
if (!$exam_id) die("Exam ID required.");

$stmt = $pdo->prepare("SELECT title, scheduled_date, scheduled_time, duration_minutes FROM exams WHERE id = ? AND status = 'completed'");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) die("Notice: Action blocked. Only fully completed exams may release their answer key.");

// Check if already released
$stmt_check = $pdo->prepare("SELECT released_at FROM answer_releases WHERE exam_id = ?");
$stmt_check->execute([$exam_id]);
$release = $stmt_check->fetch();

$attempted = $pdo->prepare("SELECT COUNT(*) FROM attempts WHERE exam_id = ? AND is_submitted = 1");
$attempted->execute([$exam_id]);
$students_attempted = $attempted->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    if (!$release) {
        $stmt_ins = $pdo->prepare("INSERT INTO answer_releases (exam_id, released_by_admin_id) VALUES (?, ?)");
        $stmt_ins->execute([$exam_id, $_SESSION['admin_id']]);
        $release['released_at'] = date('Y-m-d H:i:s');
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Publish Answer Key</h2>
</div>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-4 p-md-5 text-center">
        <i class="fas fa-key fa-4x text-primary mb-4 opacity-75"></i>
        <h3 class="font-weight-bold text-dark mb-3"><?php echo h($exam['title']); ?></h3>
        <p class="lead text-muted mb-4">Exam Concluded: <strong class="text-dark"><?php echo date('d M Y, H:i', strtotime($exam['scheduled_date'].' '.$exam['scheduled_time'])); ?></strong></p>
        
        <div class="alert alert-info d-inline-block shadow-sm mb-4 px-4 py-3 rounded-lg border-0 bg-info bg-opacity-10 text-info font-weight-bold">
            <i class="fas fa-users mr-2 fa-lg"></i> <strong><?php echo $students_attempted; ?></strong> students have attempted and submitted this exam.
        </div>
        
        <div class="mt-2">
            <?php if ($release): ?>
                <div class="d-inline-block bg-success text-white py-3 px-5 rounded-pill shadow-sm mb-4 transition">
                    <h5 class="mb-0 font-weight-bold"><i class="fas fa-check-circle mr-2"></i>Answer Key Published on <?php echo date('d M Y, H:i', strtotime($release['released_at'])); ?></h5>
                </div>
                <div class="w-100"></div>
                <div class="mt-4"><a href="index.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-5 border-2 shadow-sm">Return to Exams</a></div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <button type="submit" class="btn btn-success btn-lg font-weight-bold px-5 py-3 shadow rounded-pill transition" onclick="return confirm('Once published, all enrolled students who attempted this exam will be natively authorized to cross-check their provided answers against the established Correct Option configurations. This action stands conclusive and irreversible. Do you authorize this step?');">
                        <i class="fas fa-bullhorn mr-2"></i>Publish Answer Key Regionally
                    </button>
                    <div class="w-100"></div>
                    <div class="mt-4"><a href="index.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-5 border-2 shadow-sm">Cancel Operation</a></div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/admin_footer.php'; ?>

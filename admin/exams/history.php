<?php
// admin/exams/history.php
require_once '../../includes/admin_header.php';

require_admin_feature('reports');

$current_admin_id = (int)$_SESSION['admin_id'];
$whereHist = "WHERE e.status = 'completed'";
$paramsHist = [];

if (!is_super_admin()) {
    $whereHist .= " AND e.admin_id = ?";
    $paramsHist[] = $current_admin_id;
}

$stmt = $pdo->prepare("
    SELECT e.*, c.name as class_name, b.name as board_name, a.institute_name,
    (SELECT COUNT(*) FROM enrolments WHERE exam_id = e.id) as enrolled_count,
    (SELECT AVG(a.score / NULLIF(COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions), 0)) * 100 FROM attempts a WHERE a.exam_id = e.id AND a.is_submitted = 1) as avg_score,
    (SELECT id FROM answer_releases WHERE exam_id = e.id) as answer_released
    FROM exams e
    JOIN classes c ON e.class_id = c.id
    JOIN boards b ON e.board_id = b.id
    LEFT JOIN admins a ON e.admin_id = a.id
    {$whereHist}
    ORDER BY e.scheduled_date DESC, e.scheduled_time DESC
");
$stmt->execute($paramsHist);
$exams = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Previous Exams Archive</h2>
</div>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-0">
        <div class="table-responsive p-4">
            <table class="table table-hover table-bordered w-100" id="historyTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th width="20%" class="border-0 rounded-top-left px-4">Title</th>
                        <th width="10%" class="border-0">Class/Board</th>
                        <th width="12%" class="border-0">Date Taken</th>
                        <th width="15%" class="border-0 text-center">Attempted / Enrolled</th>
                        <th width="10%" class="border-0 text-center">Avg %</th>
                        <th width="10%" class="border-0 text-center">Key Released</th>
                        <th width="23%" class="text-right border-0 rounded-top-right px-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exams as $e): ?>
                    <tr>
                        <td class="align-middle font-weight-bold text-dark px-4"><span class="text-truncate d-inline-block" style="max-width:250px;" title="<?php echo h($e['title']); ?>"><?php echo h($e['title']); ?></span></td>
                        <td class="align-middle">
                            <span class="d-block font-weight-bold text-dark">Class <?php echo h($e['class_name']); ?></span>
                            <span class="small text-muted border bg-light rounded px-1"><?php echo h($e['board_name']); ?></span>
                        </td>
                        <td class="align-middle font-weight-500 text-muted"><i class="fas fa-calendar-alt opacity-50 mr-1"></i><?php echo date('d M Y', strtotime($e['scheduled_date'])); ?></td>
                        <td class="align-middle text-center font-weight-bold">
                            <span class="text-primary" title="Attempted"><?php echo $e['attempted_count']; ?></span> <span class="text-muted mx-1">/</span> <span class="text-muted" title="Enrolled"><?php echo $e['enrolled_count']; ?></span>
                        </td>
                        <td class="align-middle text-center font-weight-bold text-info"><?php echo $e['avg_score'] ? number_format($e['avg_score'], 1) . '%' : 'N/A'; ?></td>
                        <td class="align-middle text-center">
                            <?php if($e['answer_released']): ?>
                                <span class="badge badge-success px-2 py-1 shadow-sm"><i class="fas fa-check mr-1"></i>Yes</span>
                            <?php else: ?>
                                <span class="badge badge-secondary px-2 py-1 shadow-sm opacity-50"><i class="fas fa-times mr-1"></i>No</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right align-middle text-nowrap px-4">
                            <a href="../reports/attendance.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-light text-primary border shadow-sm mx-1 rounded-pill px-3" data-toggle="tooltip" title="Track internal attendance">
                                <i class="fas fa-users mr-1"></i> Attendance
                            </a>
                            <a href="../reports/results.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-light text-success border shadow-sm mx-1 rounded-pill px-3" data-toggle="tooltip" title="See results breakdown">
                                <i class="fas fa-chart-bar mr-1"></i> Report
                            </a>
                            <?php if(!$e['answer_released']): ?>
                                <a href="release_answers.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-warning shadow-sm mx-1 rounded-circle" data-toggle="tooltip" title="Publish Answer Key globally">
                                    <i class="fas fa-key"></i>
                                </a>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-light text-danger border shadow-sm ml-1 rounded-circle delete-exam-btn" data-id="<?php echo $e['id']; ?>" data-toggle="tooltip" title="Delete Past Exam Record">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Delete Form handled via JS -->
<form method="POST" action="delete.php" id="deleteForm" class="d-none">
    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
    <input type="hidden" name="id" id="deleteId">
    <input type="hidden" name="redirect_url" value="history.php">
</form>

<script>
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();
    $('#historyTable').DataTable({
        "order": [], 
        "pageLength": 25,
        "language": { "search": "<i class='fas fa-search text-muted mr-2'></i>" }
    });
    
    $('.delete-exam-btn').click(function() {
        if(confirm('Are you absolutely certain you want to permanently delete this exam? This will erase all associated student enrolments and attempt scores. This cannot be undone.')) {
            $('#deleteId').val($(this).data('id'));
            $('#deleteForm').submit();
        }
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

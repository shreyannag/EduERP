<?php
// admin/exams/index.php
require_once '../../includes/admin_header.php';

require_admin_feature('exams');

$where = "1=1";
$params = [];
if (!is_super_admin()) {
    $where .= " AND e.admin_id = ?";
    $params[] = (int)$_SESSION['admin_id'];
}

$stmt = $pdo->prepare("
    SELECT e.*, c.name as class_name, b.name as board_name, a.institute_name,
    (SELECT COUNT(*) FROM exam_questions WHERE exam_id = e.id) as q_count,
    (SELECT COUNT(*) FROM enrolments WHERE exam_id = e.id) as enrolled_count,
    (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) as answer_released
    FROM exams e
    JOIN classes c ON e.class_id = c.id
    JOIN boards b ON e.board_id = b.id
    LEFT JOIN admins a ON e.admin_id = a.id
    WHERE $where
    ORDER BY e.status ASC, e.scheduled_date DESC, e.scheduled_time DESC
");
$stmt->execute($params);
$exams = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">All Scheduled Exams</h2>
    <a href="create.php" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4">
        <i class="fas fa-calendar-plus mr-2"></i>Create New Exam
    </a>
</div>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-0">
        <div class="table-responsive p-4">
            <table class="table table-hover table-bordered w-100" id="examsTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th width="20%" class="border-0 rounded-top-left px-4">Exam Title</th>
                        <?php if (is_super_admin()): ?>
                            <th width="12%" class="border-0">Institute</th>
                        <?php endif; ?>
                        <th width="10%" class="border-0">Segment Focus</th>
                        <th width="15%" class="border-0">Schedule Time</th>
                        <th width="8%" class="border-0 text-center">Dur. <small>(M)</small></th>
                        <th width="8%" class="border-0 text-center">Lifecycle</th>
                        <th width="10%" class="text-center border-0">Metrics</th>
                        <th width="27%" class="text-right border-0 rounded-top-right px-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($exams as $e): 
                        $is_draft = $e['status'] === 'draft';
                        $is_pub = $e['status'] === 'published';
                        $is_comp = $e['status'] === 'completed';
                        
                        $badgeClass = $is_comp ? 'badge-success' : ($is_pub ? 'badge-primary' : 'badge-secondary');
                    ?>
                    <tr>
                        <td class="align-middle font-weight-bold text-dark px-4">
                            <div class="text-truncate" style="max-width: 250px;" title="<?php echo h($e['title']); ?>"><?php echo h($e['title']); ?></div>
                        </td>
                        <?php if (is_super_admin()): ?>
                        <td class="align-middle">
                            <span class="badge badge-light border text-dark font-weight-bold">
                                <i class="fas fa-university text-primary mr-1"></i><?php echo h($e['institute_name'] ?? 'Main Campus'); ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td class="align-middle">
                            <span class="d-block font-weight-bold text-dark">Class <?php echo h($e['class_name']); ?></span>
                            <span class="small text-muted border px-1 rounded bg-light"><?php echo h($e['board_name']); ?></span>
                        </td>
                        <td class="align-middle font-weight-500">
                            <div class="d-flex flex-column text-dark">
                                <span><i class="fas fa-calendar-alt text-muted mr-1 opacity-75"></i> <?php echo date('d M Y', strtotime($e['scheduled_date'])); ?></span>
                                <span><i class="fas fa-clock text-muted mr-1 opacity-75"></i> <?php echo date('H:i', strtotime($e['scheduled_time'])); ?></span>
                            </div>
                        </td>
                        <td class="align-middle text-center font-weight-bold text-dark h5 mb-0"><?php echo $e['duration_minutes']; ?></td>
                        <td class="align-middle text-center"><span class="badge <?php echo $badgeClass; ?> px-2 py-1 shadow-sm text-uppercase font-weight-bold" style="letter-spacing: 0.5px;"><?php echo $e['status']; ?></span></td>
                        <td class="align-middle text-center font-weight-bold">
                            <div title="Assigned Questions Count" class="text-primary"><i class="fas fa-layer-group text-primary mr-2 opacity-50"></i><?php echo $e['q_count']; ?></div>
                            <div title="Enrolled Students Count" class="text-success mt-1"><i class="fas fa-user-graduate text-success mr-2 opacity-50"></i><?php echo $e['enrolled_count']; ?></div>
                        </td>
                        <td class="text-right align-middle text-nowrap px-4">
                            <a href="preview.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-light text-info border shadow-sm mx-1 rounded-circle" data-toggle="tooltip" title="Preview Blueprint">
                                <i class="fas fa-eye"></i>
                            </a>
                            
                            <?php if ($is_draft): ?>
                            <a href="create.php?edit=<?php echo $e['id']; ?>" class="btn btn-sm btn-light text-warning border shadow-sm mx-1 rounded-circle" data-toggle="tooltip" title="Edit Settings">
                                <i class="fas fa-pencil-alt"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (!$is_draft): ?>
                            <a href="../reports/attendance.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-light text-primary border shadow-sm mx-1 rounded-circle" data-toggle="tooltip" title="View Attendance Tracker">
                                <i class="fas fa-clipboard-list"></i>
                            </a>
                            <a href="../reports/results.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-light text-success border shadow-sm mx-1 rounded-circle" data-toggle="tooltip" title="View Score Results">
                                <i class="fas fa-chart-line"></i>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($is_comp && !$e['answer_released']): ?>
                            <a href="release_answers.php?exam_id=<?php echo $e['id']; ?>" class="btn btn-sm btn-success border shadow-sm mx-1 rounded-pill font-weight-bold px-3 transition" data-toggle="tooltip" title="Publish Answer Key globally"><i class="fas fa-key mr-2"></i>Publish Key</a>
                            <?php elseif ($is_comp && $e['answer_released']): ?>
                            <span class="badge badge-success px-2 py-1 mx-1 d-none d-xl-inline-block"><i class="fas fa-check-circle mr-1"></i>Key Public</span>
                            <?php endif; ?>
                            
                            <form method="POST" action="clone.php" class="d-inline ml-1" onsubmit="return confirm('Create a duplicate draft of this exact exam configuration?');">
                                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo $e['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-light text-dark border shadow-sm rounded-circle" data-toggle="tooltip" title="Duplicate Exam Setup"><i class="fas fa-copy"></i></button>
                            </form>
                            <button class="btn btn-sm btn-light text-danger border shadow-sm ml-1 rounded-circle clone-delete-btn" data-action="delete" data-id="<?php echo $e['id']; ?>" data-toggle="tooltip" title="Delete Exam">
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
    <input type="hidden" name="redirect_url" value="index.php">
</form>

<script>
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip({ boundary: 'window' });
    
    $('#examsTable').DataTable({
        "order": [], 
        "pageLength": 25,
        "language": { "search": "<i class='fas fa-search text-muted mr-2'></i>" }
    });
    
    $('.clone-delete-btn').click(function() {
        if($(this).data('action') === 'delete') {
            if(confirm('Are you absolutely certain you want to permanently delete this exam? This will erase all associated student enrolments and attempt scores. This cannot be undone.')) {
                $('#deleteId').val($(this).data('id'));
                $('#deleteForm').submit();
            }
        }
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

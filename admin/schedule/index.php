<?php
// admin/schedule/index.php
require_once '../../includes/admin_header.php';

$stmt = $pdo->query("
    SELECT e.*, c.name as class_name, b.name as board_name,
    (SELECT COUNT(*) FROM enrolments WHERE exam_id = e.id) as enrolled_count
    FROM exams e
    JOIN classes c ON e.class_id = c.id
    JOIN boards b ON e.board_id = b.id
    ORDER BY e.scheduled_date ASC, e.scheduled_time ASC
");
$schedule = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Global Assessment Schedule</h2>
</div>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-0">
        <div class="table-responsive p-4">
            <table class="table table-bordered table-hover w-100" id="scheduleTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th width="15%" class="border-0 rounded-top-left px-4">Date</th>
                        <th width="10%" class="border-0">Time</th>
                        <th width="30%" class="border-0">Exam Title</th>
                        <th width="15%" class="border-0">Target Group</th>
                        <th width="10%" class="border-0 text-center">Dur. (M)</th>
                        <th width="10%" class="border-0 text-center">Lifecycle</th>
                        <th width="10%" class="border-0 text-center rounded-top-right px-4">Enrolled</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schedule as $s): 
                        $now = date('Y-m-d');
                        $examDate = $s['scheduled_date'];
                        
                        $rowClass = '';
                        if ($s['status'] === 'completed') {
                            $rowClass = 'table-light text-muted font-italic opacity-75';
                        } elseif ($examDate === $now) {
                            $rowClass = 'table-warning bg-opacity-25 border-warning font-weight-500';
                        } elseif ($examDate > $now) {
                            $rowClass = 'table-info bg-opacity-10';
                        }
                    ?>
                    <tr class="<?php echo $rowClass; ?>">
                        <td class="align-middle font-weight-bold px-4 text-nowrap"><i class="fas fa-calendar-day mr-2 opacity-50"></i><?php echo date('d M Y, D', strtotime($s['scheduled_date'])); ?></td>
                        <td class="align-middle font-weight-bold"><i class="fas fa-clock text-muted mr-2 opacity-50"></i><?php echo date('H:i', strtotime($s['scheduled_time'])); ?></td>
                        <td class="align-middle font-weight-bold text-dark"><a href="../exams/preview.php?exam_id=<?php echo $s['id']; ?>" class="text-dark text-decoration-none d-block font-weight-bold" style="text-decoration-line: none !important;"><?php echo h($s['title']); ?> <i class="fas fa-external-link-alt small text-muted ml-1 opacity-50"></i></a></td>
                        <td class="align-middle">Class <?php echo h($s['class_name']); ?> <span class="badge badge-light border ml-1 text-muted"><?php echo h($s['board_name']); ?></span></td>
                        <td class="align-middle text-center font-weight-bold text-dark"><?php echo $s['duration_minutes']; ?></td>
                        <td class="align-middle text-center text-uppercase small font-weight-bold"><span class="badge badge-light border px-2 shadow-sm"><?php echo $s['status']; ?></span></td>
                        <td class="align-middle text-center px-4 font-weight-bold text-primary"><i class="fas fa-users mr-1 opacity-50"></i><?php echo $s['enrolled_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#scheduleTable').DataTable({
        "order": [], 
        "pageLength": 50,
        "language": { "search": "<i class='fas fa-search text-muted mr-2'></i>" }
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

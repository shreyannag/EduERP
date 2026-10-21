<?php
// admin/enrolments/index.php
require_once '../../includes/admin_header.php';

require_admin_feature('enrolments');

$exam_id = intval($_GET['exam_id'] ?? 0);
$current_admin_id = (int)$_SESSION['admin_id'];

if (is_super_admin()) {
    $exams = $pdo->query("SELECT e.id, e.title, e.class_id, e.board_id, c.name as class_name, b.name as board_name, e.status 
                          FROM exams e 
                          JOIN classes c ON e.class_id = c.id 
                          JOIN boards b ON e.board_id = b.id 
                          ORDER BY e.status ASC, e.scheduled_date DESC")->fetchAll();
} else {
    $stmtExams = $pdo->prepare("SELECT e.id, e.title, e.class_id, e.board_id, c.name as class_name, b.name as board_name, e.status 
                                FROM exams e 
                                JOIN classes c ON e.class_id = c.id 
                                JOIN boards b ON e.board_id = b.id 
                                WHERE e.admin_id = ?
                                ORDER BY e.status ASC, e.scheduled_date DESC");
    $stmtExams->execute([$current_admin_id]);
    $exams = $stmtExams->fetchAll();
}

$students = [];
$exam = null;

if ($exam_id) {
    // Find exam details
    $exam = array_values(array_filter($exams, function($e) use($exam_id) { return $e['id'] == $exam_id; }))[0] ?? null;
    
    if ($exam) {
        $studentSql = "
            SELECT s.*, 
            (CASE WHEN en.id IS NOT NULL THEN 1 ELSE 0 END) as is_enrolled,
            en.enrolled_at
            FROM students s
            LEFT JOIN enrolments en ON s.id = en.student_id AND en.exam_id = ?
            WHERE s.class_id = ? AND s.board_id = ? AND s.is_active = 1
        ";
        $studentParams = [$exam_id, $exam['class_id'], $exam['board_id']];

        if (!is_super_admin()) {
            $studentSql .= " AND s.admin_id = ?";
            $studentParams[] = $current_admin_id;
        }

        $studentSql .= " ORDER BY s.full_name ASC";
        $stmt = $pdo->prepare($studentSql);
        $stmt->execute($studentParams);
        $students = $stmt->fetchAll();
    }
}

// Processing enrolments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exam) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    
    if ($action === 'enrol_selected' || $action === 'enrol_all') {
        $to_enrol = [];
        if ($action === 'enrol_all') {
            foreach($students as $s) if(!$s['is_enrolled']) $to_enrol[] = $s['id'];
        } else {
            $to_enrol = $_POST['student_ids'] ?? [];
        }
        
        if (!empty($to_enrol)) {
            $stmt_ins = $pdo->prepare("INSERT IGNORE INTO enrolments (exam_id, student_id) VALUES (?, ?)");
            foreach($to_enrol as $s_id) {
                $stmt_ins->execute([$exam_id, $s_id]);
            }
            header("Location: index.php?exam_id=$exam_id&msg=enrolled");
            exit;
        }
    } elseif ($action === 'remove_selected' || $action === 'remove_all') {
        $to_remove = [];
        if ($action === 'remove_all') {
            foreach($students as $s) if($s['is_enrolled']) $to_remove[] = $s['id'];
        } else {
            $to_remove = $_POST['student_ids'] ?? [];
        }
        
        if (!empty($to_remove)) {
            $placeholders = implode(',', array_fill(0, count($to_remove), '?'));
            $params = array_merge([$exam_id], $to_remove);
            $stmt_del = $pdo->prepare("DELETE FROM enrolments WHERE exam_id = ? AND student_id IN ($placeholders)");
            $stmt_del->execute($params);
            header("Location: index.php?exam_id=$exam_id&msg=removed");
            exit;
        }
    }
}

$enrolled_count = count(array_filter($students, function($s){ return $s['is_enrolled']; }));
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Exam Enrolments</h2>
        <p class="text-muted mb-0">Assign enrolled students to specific online examination rosters.</p>
    </div>
    <div>
        <a href="links.php" class="btn btn-outline-primary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-link mr-2"></i>Temporary Enrollment Links
        </a>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-lg mb-4 bg-light">
    <div class="card-body p-4">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-9 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Select Exam to Manage Enrolments</label>
                <div class="input-group shadow-sm">
                    <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-file-alt text-primary"></i></span></div>
                    <select name="exam_id" class="form-control custom-select bg-white border-0 font-weight-bold" onchange="this.form.submit()">
                        <option value="">-- Choose Exam --</option>
                        <?php foreach($exams as $e): ?>
                            <option value="<?php echo $e['id']; ?>" class="font-weight-500" <?php echo $exam_id == $e['id'] ? 'selected' : ''; ?>>
                                <?php echo h($e['title']); ?>  ➔  (Class <?php echo h($e['class_name']); ?> <?php echo h($e['board_name']); ?>)  [<?php echo strtoupper($e['status']); ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-dark btn-block font-weight-bold shadow-sm rounded-pill w-100">Load Students</button>
            </div>
        </form>
    </div>
</div>

<?php if ($exam): ?>
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success shadow-sm rounded-lg py-3 border-left border-success" style="border-width: 5px !important;"><i class="fas fa-check-circle mr-2 fa-lg"></i>Action processed successfully.</div>
    <?php endif; ?>

    <form method="POST" id="enrolForm">
        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
        <input type="hidden" name="action" id="formAction" value="">
        
        <div class="card shadow-sm border-0 rounded-lg">
            <div class="card-header bg-white border-bottom-0 py-4 px-4 d-flex justify-content-between align-items-center flex-wrap">
                <div class="mb-3 mb-md-0">
                    <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-users text-primary mr-2 opacity-50"></i>Exam Roster: <span class="text-primary"><?php echo h($exam['title']); ?></span></h5>
                    <p class="text-muted small mt-2 mb-0 border bg-light p-2 rounded">Displaying students in: <strong class="text-dark">Class <?php echo h($exam['class_name']); ?></strong> and <strong class="text-dark"><?php echo h($exam['board_name']); ?></strong>.</p>
                </div>
                <div class="text-right">
                    <span class="badge badge-success px-4 py-3 shadow-sm rounded-pill font-weight-bold" style="font-size: 1.1rem; letter-spacing: 0.5px;"><i class="fas fa-check-circle mr-2"></i><?php echo $enrolled_count; ?> / <?php echo count($students); ?> Enrolled</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive px-4 pb-4">
                    <table class="table table-hover table-bordered w-100" id="rosterTable">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th width="5%" class="text-center border-0 rounded-top-left">
                                    <div class="custom-control custom-checkbox ml-auto mr-auto" style="width:16px;">
                                        <input type="checkbox" class="custom-control-input" id="checkAll">
                                        <label class="custom-control-label" for="checkAll"></label>
                                    </div>
                                </th>
                                <th width="35%" class="border-0">Student Name</th>
                                <th width="20%" class="border-0">Username</th>
                                <th width="20%" class="border-0 text-center">Status</th>
                                <th width="20%" class="border-0 rounded-top-right text-right px-4">Enrolment Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $s): ?>
                            <tr class="<?php echo $s['is_enrolled'] ? 'table-success bg-opacity-10 border-success' : ''; ?>">
                                <td class="text-center align-middle">
                                    <div class="custom-control custom-checkbox mx-auto" style="width:16px;">
                                        <input type="checkbox" name="student_ids[]" value="<?php echo $s['id']; ?>" class="custom-control-input stu-checkbox" id="chk<?php echo $s['id']; ?>">
                                        <label class="custom-control-label" for="chk<?php echo $s['id']; ?>"></label>
                                    </div>
                                </td>
                                <td class="align-middle font-weight-bold text-dark"><?php echo h($s['full_name']); ?></td>
                                <td class="align-middle font-family-monospace text-primary"><?php echo h($s['username']); ?></td>
                                <td class="align-middle text-center">
                                    <?php if ($s['is_enrolled']): ?>
                                        <span class="badge badge-success px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-check-circle mr-1"></i>Enrolled</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-3 py-1 shadow-sm rounded-pill opacity-75">Not Enrolled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-right px-4 text-muted small">
                                    <?php echo $s['is_enrolled'] ? date('d M Y, H:i', strtotime($s['enrolled_at'])) : '-'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php if(!empty($students) && strpos($exam['status'], 'completed') === false): ?>
            <div class="card-footer bg-light border-top-0 py-4 px-4 rounded-bottom">
                <div class="row w-100 mx-0 text-center text-md-left">
                    <div class="col-md-6 mb-3 mb-md-0 pl-0">
                        <button type="button" class="btn btn-outline-success font-weight-bold rounded-pill px-4 shadow-sm border-2 mr-2 action-btn transition" data-act="enrol_selected"><i class="fas fa-check-square mr-2"></i>Enrol Selected Students</button>
                        <button type="button" class="btn btn-success font-weight-bold rounded-pill px-4 shadow action-btn transition" data-act="enrol_all"><i class="fas fa-users mr-2"></i>Enrol All Students</button>
                    </div>
                    <div class="col-md-6 text-md-right pr-0">
                        <button type="button" class="btn btn-outline-danger font-weight-bold rounded-pill px-4 shadow-sm border-2 mr-2 action-btn transition" data-act="remove_selected"><i class="fas fa-eraser mr-2"></i>Remove Selected Students</button>
                        <button type="button" class="btn btn-danger font-weight-bold rounded-pill px-4 shadow action-btn transition" data-act="remove_all"><i class="fas fa-user-minus mr-2"></i>Remove All Students</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
    
    <script>
    $(document).ready(function() {
        $('#rosterTable').DataTable({
            "order": [], 
            "pageLength": 100,
            "columnDefs": [ { "orderable": false, "targets": 0 } ],
            "language": { 
                "search": "<i class='fas fa-search text-muted mr-2'></i>",
                "emptyTable": "<div class='py-4'><i class='fas fa-box-open fa-3x mb-3 text-light'></i><br>No students found.</div>"
            }
        });
        
        $('#checkAll').click(function() {
            $('.stu-checkbox').prop('checked', this.checked);
        });
        
        $('.action-btn').click(function() {
            let act = $(this).data('act');
            
            if (act === 'enrol_selected' || act === 'remove_selected') {
                if ($('.stu-checkbox:checked').length === 0) {
                    alert('Please select at least one student first.');
                    return;
                }
            }
            
            if (confirm('Are you sure you want to proceed with this action?')) {
                $('#formAction').val(act);
                $('#enrolForm').submit();
            }
        });
    });
    </script>
<?php endif; ?>

<?php require_once '../../includes/admin_footer.php'; ?>

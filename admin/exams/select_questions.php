<?php
// admin/exams/select_questions.php
require_once '../../includes/admin_header.php';

$exam_id = intval($_GET['exam_id'] ?? 0);
if (!$exam_id) die("Exam ID required.");

$stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam || strpos($exam['status'], 'draft') === false) {
    die("Only draft exams can be edited.");
}

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    // We expect an array of question IDs in the order they were submitted
    $orderedListRaw = $_POST['questionListOrder'] ?? ''; // hidden input
    $selectedQs = $_POST['questions'] ?? [];
    
    if (!empty($selectedQs)) {
        $pdo->prepare("DELETE FROM exam_questions WHERE exam_id = ?")->execute([$exam_id]);
        
        // Build correct order array based on DOM submission string of comma separated IDs
        $domOrderList = $orderedListRaw ? explode(',', $orderedListRaw) : [];
        $orderCounter = 1;
        
        $stmt_insert = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_id, order_no) VALUES (?, ?, ?)");
        
        $exam_admin_id = !empty($exam['admin_id']) ? (int)$exam['admin_id'] : (int)$_SESSION['admin_id'];
        
        // Insert based on matching sorted DOM list (drag and drop results)
        // Verify question ownership so admin cannot link another admin's questions
        $ownerCheckStmt = $pdo->prepare("SELECT id FROM questions WHERE id = ? AND (admin_id = ? OR id IN (SELECT question_id FROM exam_questions WHERE exam_id = ?))");
        foreach($domOrderList as $qId) {
            $qIdInt = (int)$qId;
            if(in_array($qIdInt, array_map('intval', $selectedQs))) {
                $ownerCheckStmt->execute([$qIdInt, $exam_admin_id, $exam_id]);
                if ($ownerCheckStmt->fetch()) {
                    $stmt_insert->execute([$exam_id, $qIdInt, $orderCounter++]);
                }
            }
        }
        
        header("Location: preview.php?exam_id=$exam_id");
        exit;
    } else {
        $error_msg = "Please configure the exam with at least one question.";
    }
}

$exam_admin_id = !empty($exam['admin_id']) ? (int)$exam['admin_id'] : (int)$_SESSION['admin_id'];

$stmt_all = $pdo->prepare("
    SELECT q.*, (CASE WHEN eq.id IS NOT NULL THEN 1 ELSE 0 END) as is_selected, IFNULL(eq.order_no, 9999) as o
    FROM questions q
    LEFT JOIN exam_questions eq ON q.id = eq.question_id AND eq.exam_id = ?
    WHERE q.class_id = ? AND q.board_id = ? 
      AND (q.admin_id = ? OR eq.id IS NOT NULL)
      AND (q.is_archived = 0 OR eq.id IS NOT NULL)
    ORDER BY o ASC, q.id DESC
");
$stmt_all->execute([$exam_id, $exam['class_id'], $exam['board_id'], $exam_admin_id]);
$questions = $stmt_all->fetchAll();

$selected_count = count(array_filter($questions, function($q){ return $q['is_selected']; }));
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark">Select Questions</h2>
        <h5 class="text-muted"><?php echo h($exam['title']); ?> <span class="badge badge-secondary ml-2 py-1 px-2 shadow-sm rounded">Step 2</span></h5>
    </div>
    <div class="text-right">
        <h4 class="mb-0"><span id="selCount" class="badge badge-primary px-3 py-2 shadow-sm rounded-pill"><?php echo $selected_count; ?></span> <small class="text-muted text-uppercase font-weight-bold">selected</small></h4>
    </div>
</div>

<?php if ($error_msg): ?>
    <div class="alert alert-danger shadow-sm rounded-lg py-3"><i class="fas fa-exclamation-circle mr-2"></i><?php echo h($error_msg); ?></div>
<?php endif; ?>

<form method="POST" id="selectionForm">
    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
    <input type="hidden" name="questionListOrder" id="questionListOrder" value="">
    
    <div class="card shadow-sm border-0 mb-4 rounded-lg">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-3 px-4 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-layer-group text-primary mr-2"></i>Available Database Segments</h5>
            <div>
                <button type="button" id="selectAllBtn" class="btn btn-sm btn-outline-primary font-weight-bold mr-2 rounded-pill px-3">Select All</button>
                <button type="button" id="deselectAllBtn" class="btn btn-sm btn-outline-secondary font-weight-bold rounded-pill px-3">Deselect All</button>
            </div>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive px-4 pb-4">
                <table class="table table-hover table-bordered w-100" id="qbTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th width="3%" class="text-center border-0 rounded-top-left"><i class="fas fa-arrows-alt-v"></i></th>
                            <th width="5%" class="text-center border-0">Inc.</th>
                            <th width="75%" class="border-0">Question Context</th>
                            <th width="17%" class="border-0 rounded-top-right">Base Key</th>
                        </tr>
                    </thead>
                    <tbody id="sortableBody">
                        <?php foreach($questions as $q): ?>
                        <tr class="<?php echo $q['is_selected'] ? 'table-primary bg-opacity-25' : ''; ?>" data-id="<?php echo $q['id']; ?>">
                            <td class="text-center cursor-move text-muted align-middle" style="cursor: grab;" title="Drag to reorder"><i class="fas fa-grip-lines"></i></td>
                            <td class="text-center align-middle">
                                <div class="custom-control custom-checkbox ml-2">
                                    <input type="checkbox" name="questions[]" value="<?php echo $q['id']; ?>" class="custom-control-input q-checkbox" id="chk<?php echo $q['id']; ?>" <?php echo $q['is_selected'] ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="chk<?php echo $q['id']; ?>"></label>
                                </div>
                            </td>
                            <td class="align-middle">
                                <?php 
                                    $plainText = trim(strip_tags($q['question_text']));
                                    $hasImg = stripos($q['question_text'], '<img') !== false;
                                ?>
                                <div class="font-weight-bold text-dark text-wrap">
                                    <?php if ($hasImg): ?>
                                        <span class="badge badge-info mr-1 shadow-sm"><i class="fas fa-image mr-1"></i>Image</span>
                                    <?php endif; ?>
                                    <?php echo h($plainText ?: ($hasImg ? 'Image Question' : '')); ?>
                                </div>
                                <div class="small text-muted mt-2 d-flex flex-wrap gap-2">
                                    <span class="mr-3 border rounded px-1">[A] <?php echo h($q['option1']); ?></span>  
                                    <span class="mr-3 border rounded px-1">[B] <?php echo h($q['option2']); ?></span>
                                    <span class="mr-3 border rounded px-1">[C] <?php echo h($q['option3']); ?></span>
                                    <span class="border rounded px-1">[D] <?php echo h($q['option4']); ?></span>
                                </div>
                            </td>
                            <td class="align-middle"><span class="badge badge-success px-3 py-2 shadow-sm">Opt <?php echo $q['correct_option']; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card-footer bg-light border-top-0 py-4 px-4 d-flex justify-content-between align-items-center rounded-bottom mt-2">
            <a href="create.php?edit=<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4 bg-white"><i class="fas fa-arrow-left mr-2"></i>Previous Step</a>
            <button type="submit" class="btn btn-primary font-weight-bold px-5 py-2 shadow rounded-pill btn-lg transition" <?php echo empty($questions) ? 'disabled' : ''; ?>>Review Overview <i class="fas fa-arrow-right ml-2 opacity-75"></i></button>
        </div>
    </div>
</form>

<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
$(document).ready(function() {
    let dt = $('#qbTable').DataTable({
        "ordering": false,
        "paging": false, 
        "info": false,
        "language": { 
            "search": "<i class='fas fa-search text-muted mr-2'></i>",
            "emptyTable": "<div class='py-4'><i class='fas fa-box-open fa-3x mb-3 text-light'></i><br>No questions conformant to the configured targets found.</div>"
        }
    });
    
    $( "#sortableBody" ).sortable({
        handle: '.cursor-move',
        cursor: 'grabbing',
        placeholder: "bg-light border border-primary border-dashed",
        helper: function(e, tr) {
            var $originals = tr.children();
            var $helper = tr.clone();
            $helper.children().each(function(index) {
                $(this).width($originals.eq(index).width());
            });
            $helper.css("background-color", "#ffffff").css("box-shadow", "0 10px 20px rgba(0,0,0,0.15)");
            return $helper;
        }
    });

    function updateCount() {
        $('#selCount').text($('.q-checkbox:checked').length);
    }
    
    $(document).on('change', '.q-checkbox', function() {
        if($(this).is(':checked')) { $(this).closest('tr').addClass('table-primary bg-opacity-25'); } 
        else { $(this).closest('tr').removeClass('table-primary bg-opacity-25'); }
        updateCount();
    });
    
    $('#selectAllBtn').click(function() {
        $('.q-checkbox').prop('checked', true).closest('tr').addClass('table-primary bg-opacity-25');
        updateCount();
    });
    
    $('#deselectAllBtn').click(function() {
        $('.q-checkbox').prop('checked', false).closest('tr').removeClass('table-primary bg-opacity-25');
        updateCount();
    });

    // Populate hidden field right before submit to assert the visual order
    $('#selectionForm').submit(function() {
        let sortedIDs = [];
        $('#sortableBody tr').each(function() {
            let trId = $(this).data('id');
            if(trId) sortedIDs.push(trId);
        });
        $('#questionListOrder').val(sortedIDs.join(','));
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

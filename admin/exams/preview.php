<?php
// admin/exams/preview.php
require_once '../../includes/admin_header.php';

$exam_id = intval($_GET['exam_id'] ?? 0);
if (!$exam_id) die("Exam ID required.");

$stmt = $pdo->prepare("
    SELECT e.*, c.name as class_name, b.name as board_name 
    FROM exams e 
    JOIN classes c ON e.class_id = c.id 
    JOIN boards b ON e.board_id = b.id 
    WHERE e.id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) die("Exam not found.");

$stmt_q = $pdo->prepare("
    SELECT q.*, eq.order_no 
    FROM questions q
    JOIN exam_questions eq ON q.id = eq.question_id
    WHERE eq.exam_id = ?
    ORDER BY eq.order_no ASC");
$stmt_q->execute([$exam_id]);
$questions = $stmt_q->fetchAll();

$is_draft = strpos($exam['status'], 'draft') !== false;
?>

<style>
.preview-q-text img {
    max-width: 100% !important;
    height: auto !important;
    border-radius: 6px;
    display: block;
    margin: 10px 0;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark">Exam Preview</h2>
        <h5 class="text-muted"><?php echo h($exam['title']); ?> <span class="badge badge-secondary ml-2 py-1 px-2 shadow-sm rounded">Step 3</span></h5>
    </div>
    <div class="text-right">
        <?php if ($is_draft): ?>
        <form method="POST" action="publish.php" class="d-inline" onsubmit="return confirm('WARNING: Once published, the exam becomes LIVE and cannot be reverted back to draft. All questions are locked in. Are you absolutely sure?');">
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="btn btn-success font-weight-bold px-5 py-2 shadow rounded-pill btn-lg"><i class="fas fa-paper-plane mr-2"></i>Publish Live</button>
        </form>
        <?php else: ?>
            <span class="badge badge-primary py-2 px-4 shadow-sm" style="font-size: 1.1rem; letter-spacing: 1px;">STATUS: <?php echo strtoupper($exam['status']); ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow rounded-lg border-0 mb-4 bg-gradient-primary text-white">
    <div class="card-body p-4 p-md-5">
        <div class="row align-items-center">
            <div class="col-md-7 mb-4 mb-md-0 border-right border-light" style="border-right-color: rgba(255,255,255,0.2) !important;">
                <h4 class="font-weight-bold mb-3"><i class="fas fa-layer-group text-white-50 mr-2"></i>Curriculum Targets</h4>
                <div class="d-flex flex-wrap text-uppercase font-weight-bold pt-2">
                    <div class="mr-4 mb-3">
                        <span class="d-block text-white-50 small mb-1">Class Segment</span>
                        <span class="bg-white text-primary px-3 py-1 rounded shadow-sm">Class <?php echo h($exam['class_name']); ?></span>
                    </div>
                    <div class="mb-3">
                        <span class="d-block text-white-50 small mb-1">Board Focus</span>
                        <span class="bg-white text-primary px-3 py-1 rounded shadow-sm"><?php echo h($exam['board_name']); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-5 pl-md-5">
                <h4 class="font-weight-bold mb-3"><i class="fas fa-clock text-white-50 mr-2"></i>Schedule & Size</h4>
                <div class="font-weight-bold px-2">
                    <p class="mb-2"><i class="fas fa-calendar-day mr-2 text-white-50"></i> <?php echo date('l, d M Y', strtotime($exam['scheduled_date'])); ?></p>
                    <p class="mb-2"><i class="fas fa-stopwatch mr-2 text-white-50"></i> Start @ <?php echo date('H:i', strtotime($exam['scheduled_time'])); ?> (<?php echo $exam['duration_minutes']; ?> minutes)</p>
                    <p class="mb-0"><i class="fas fa-list-ol mr-2 text-white-50"></i> <?php echo count($questions); ?> total questions formulated</p>
                </div>
            </div>
        </div>
        
        <?php if ($is_draft): ?>
        <div class="w-100 mt-4 pt-4 border-top border-light d-flex justify-content-end" style="border-top-color: rgba(255,255,255,0.2) !important;">
            <a href="create.php?edit=<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-light text-primary font-weight-bold mr-3 rounded-pill px-4 shadow-sm transition"><i class="fas fa-cog mr-2"></i>Edit Settings</a>
            <a href="select_questions.php?exam_id=<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-light text-primary font-weight-bold rounded-pill px-4 shadow-sm transition"><i class="fas fa-layer-group mr-2"></i>Edit Questions</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<h4 class="mb-4 font-weight-bold text-dark pb-2 border-bottom d-inline-block">Question Paper Blueprint</h4>

<?php foreach($questions as $index => $q): ?>
<div class="card shadow-sm border-0 mb-4 rounded-lg overflow-hidden">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2">
        <div class="font-weight-bold text-dark d-flex align-items-start" style="font-size: 1.15rem;">
            <span class="badge badge-dark mr-3 shadow-sm py-2 px-3 badge-pill text-uppercase flex-shrink-0">Q. <?php echo $index + 1; ?></span>
            <div style="line-height: 1.6; min-width: 0; flex-grow: 1;" class="preview-q-text font-weight-normal"><?php echo render_formatted_content($q['question_text']); ?></div>
        </div>
    </div>
    <div class="card-body px-4 pb-4 pt-3 ml-md-5 pl-md-4">
        <?php if (($q['question_type'] ?? 'mcq') === 'fill_blank'): ?>
            <div class="p-3 bg-light rounded border border-primary">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge badge-warning text-dark font-weight-bold shadow-sm"><i class="fas fa-pen-alt mr-1"></i>Fill in the Blank</span>
                    <span class="badge badge-success font-weight-bold shadow-sm"><i class="fas fa-check-circle mr-1"></i>Official Answer Key</span>
                </div>
                <div class="p-3 bg-white rounded border">
                    <span class="small font-weight-bold text-muted text-uppercase d-block mb-1">Accepted Input:</span>
                    <span class="h5 font-weight-bold text-success mb-0"><?php echo h($q['correct_answer_text'] ?: $q['option1']); ?></span>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php for($i=1; $i<=4; $i++): 
                    $optText = $i===1 ? $q['option1'] : ($i===2 ? $q['option2'] : ($i===3 ? $q['option3'] : $q['option4']));
                    if ($optText === null || trim((string)$optText) === '') continue;
                    $isCorrect = ($i == $q['correct_option']);
                ?>
                <div class="col-md-6 mb-3">
                    <div class="p-3 d-flex align-items-center h-100 review-opt-box <?php echo $isCorrect ? 'is-correct shadow' : 'shadow-sm'; ?>">
                        <span class="review-opt-letter mr-3">
                            <?php echo chr(64+$i); ?>
                        </span>
                        <div class="review-opt-content pr-2">
                            <?php echo render_formatted_content($optText); ?>
                        </div>
                        <?php if($isCorrect): ?>
                            <span class="badge badge-success px-2 py-1 shadow-sm flex-shrink-0"><i class="fas fa-check mr-1"></i> Correct</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php if(empty($questions)): ?>
    <div class="alert alert-warning shadow-sm rounded-lg py-4 border-0 text-center">
        <i class="fas fa-exclamation-triangle fa-2x mb-3 opacity-75 d-block"></i>
        <h5 class="font-weight-bold text-dark">No questions configured</h5>
        <p class="mb-0 text-muted">This exam currently has no questions assigned to it. Go back to <a href="select_questions.php?exam_id=<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>" class="alert-link">Step 2</a> to add some from the Question Bank.</p>
    </div>
<?php endif; ?>

<?php require_once '../../includes/admin_footer.php'; ?>

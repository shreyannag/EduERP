<?php
// student/review.php
require_once '../includes/student_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

$student_id = $_SESSION['student_id'];
$exam_id = intval($_GET['exam_id'] ?? 0);
if (!$exam_id) die("Context logic fault: Missing node trace.");

$stmt = $pdo->prepare("
    SELECT a.*, e.title, e.scheduled_date,
    (SELECT COUNT(*) FROM answer_releases WHERE exam_id = ?) as key_released,
    COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
    FROM attempts a
    JOIN exams e ON a.exam_id = e.id
    WHERE a.student_id = ? AND a.exam_id = ? AND a.is_submitted = 1
");
$stmt->execute([$exam_id, $student_id, $exam_id]);
$attempt = $stmt->fetch();

if (!$attempt) die("Invalid subsystem boundary requested context.");

$stmt_qa = $pdo->prepare("
    SELECT q.*, eq.order_no, aa.chosen_option as selected_option, aa.answer_text as selected_text
    FROM questions q
    JOIN exam_questions eq ON q.id = eq.question_id
    LEFT JOIN attempt_answers aa ON aa.question_id = q.id AND aa.attempt_id = ?
    WHERE eq.exam_id = ?
    ORDER BY eq.order_no ASC
");
$stmt_qa->execute([$attempt['id'], $exam_id]);
$qa_list = $stmt_qa->fetchAll();

$correct_count = 0;
foreach ($qa_list as $q) {
    if (($q['question_type'] ?? 'mcq') === 'fill_blank') {
        $corText = !empty($q['correct_answer_text']) ? $q['correct_answer_text'] : ($q['option1'] ?? '');
        if (is_fill_blank_correct($q['selected_text'], $corText)) {
            $correct_count++;
        }
    } else {
        if (!empty($q['selected_option']) && $q['selected_option'] == $q['correct_option']) {
            $correct_count++;
        }
    }
}

$total_marks = !empty($attempt['total_marks']) && $attempt['total_marks'] > 0 
    ? (int)$attempt['total_marks'] 
    : (!empty($attempt['total_questions']) ? (int)$attempt['total_questions'] : 1);

$score_pct = $total_marks > 0 ? round(($attempt['score'] / $total_marks) * 100, 1) : 0;
$score_pct = min(100, max(0, $score_pct));
require_once '../includes/student_header.php';
?>

<style>
.review-q-text img {
    max-width: 100% !important;
    height: auto !important;
    border-radius: 6px;
    display: block;
    margin: 10px 0;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Exam Report</h2>
    <a href="dashboard.php" class="btn btn-outline-dark font-weight-bold rounded-pill px-4 shadow-sm bg-white"><i class="fas fa-sign-out-alt mr-2 fa-flip-horizontal"></i>Back to Dashboard</a>
</div>

<div class="card shadow-lg border-0 rounded-lg mb-5 overflow-hidden" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
    <div class="card-body p-4 p-md-5">
        <div class="row align-items-center text-white">
            <div class="col-md-7 border-right border-secondary opacity-90 mb-4 mb-md-0 pt-2 pb-2">
                <?php if ($attempt['key_released']): ?>
                    <span class="badge badge-success px-3 py-1 shadow-sm rounded-pill font-weight-bold text-uppercase mb-3" style="letter-spacing:1px;"><i class="fas fa-unlock mr-2"></i>Answer Key Available</span>
                <?php else: ?>
                    <span class="badge badge-warning px-3 py-1 shadow-sm rounded-pill font-weight-bold text-uppercase mb-3" style="letter-spacing:1px;"><i class="fas fa-lock mr-2"></i>Answer Key Pending</span>
                <?php endif; ?>
                <h3 class="font-weight-bold mb-2 display-5" style="letter-spacing: -1px;"><?php echo h($attempt['title']); ?></h3>
                <p class="mb-0 text-white-50"><i class="fas fa-calendar-check mr-2"></i>Exam taken on: <?php echo date('d M Y', strtotime($attempt['scheduled_date'])); ?></p>
            </div>
            <div class="col-md-5 pl-md-5 text-center text-md-left">
                <?php if ($attempt['key_released']): ?>
                    <h6 class="text-uppercase text-white-50 font-weight-bold mb-3" style="letter-spacing:1px;">Your Score</h6>
                    <div class="d-flex align-items-center justify-content-center justify-content-md-start">
                        <h1 class="font-weight-bold mb-0 display-3 mr-4" style="color: <?php echo $score_pct >= 40 ? '#34d399' : '#f87171'; ?>; text-shadow: 0 0 20px rgba(0,0,0,0.5);"><?php echo $score_pct; ?>%</h1>
                        <div class="text-left font-weight-bold p-3 rounded-lg shadow-sm" style="background: rgba(255, 255, 255, 0.12); border: 1px solid rgba(255, 255, 255, 0.25); min-width: 140px;">
                            <span class="d-block h4 mb-0 text-white font-weight-bold" style="letter-spacing: 0.5px;"><?php echo $attempt['score']; ?> <span class="text-white-50 mx-1 font-weight-normal">/</span> <?php echo $total_marks; ?></span>
                            <span class="small text-white-50 text-uppercase d-block mt-1 font-weight-bold" style="letter-spacing: 0.5px;">Marks (<?php echo $correct_count; ?>/<?php echo $attempt['total_questions']; ?> Correct)</span>
                        </div>
                    </div>
                <?php else: ?>
                    <h6 class="text-uppercase text-warning font-weight-bold mb-3" style="letter-spacing:1px;"><i class="fas fa-hourglass-half mr-2"></i>Results Awaiting Publication</h6>
                    <div class="p-3 rounded-lg text-left shadow-sm" style="background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.15);">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge badge-success px-2 py-1 mr-2"><i class="fas fa-check mr-1"></i>Submitted</span>
                            <span class="text-white font-weight-bold small">Exam Recorded Successfully</span>
                        </div>
                        <p class="small text-white-50 mb-0">
                            Your answers have been securely logged. Official scores, percentages, and answer keys will be released once published by your instructor.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!$attempt['key_released']): ?>
<div class="alert alert-info border-0 rounded-lg shadow-sm mb-4">
    <i class="fas fa-info-circle mr-2 fa-lg"></i>
    <strong>Submission Review Mode:</strong> Your exam responses are shown below. Correct answers and final scores are hidden until the answer key and results are published by the administration.
</div>
<?php endif; ?>

<h5 class="font-weight-bold text-muted mb-4 pb-2 border-bottom d-inline-block text-uppercase">Question Details</h5>

<?php foreach($qa_list as $index => $q): 
    $qNum = $index + 1;
    $isFill = (($q['question_type'] ?? 'mcq') === 'fill_blank');
    $sel = $q['selected_option'];
    $cor = $q['correct_option'];
    $selText = $q['selected_text'];
    $corText = !empty($q['correct_answer_text']) ? $q['correct_answer_text'] : ($q['option1'] ?? '');

    if ($isFill) {
        $eval = evaluate_fill_blanks($selText, $corText, $q['question_text']);
        $hasAttempted = false;
        foreach ($eval['details'] as $det) {
            if (trim((string)$det['student']) !== '') {
                $hasAttempted = true;
                break;
            }
        }
        $isCorrect = $eval['is_all_correct'];
    } else {
        $hasAttempted = !empty($sel);
        $isCorrect = ($sel == $cor);
    }

    $cardBorderClass = 'border-primary';
    if ($attempt['key_released']) {
        if (!$hasAttempted) {
            $cardBorderClass = 'border-warning';
        } elseif ($isCorrect) {
            $cardBorderClass = 'border-success';
        } else {
            $cardBorderClass = 'border-danger';
        }
    }
?>
<div class="card shadow border-0 mb-4 rounded-lg overflow-hidden border-top <?php echo $cardBorderClass; ?>" style="border-top-width: 4px !important;">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2 d-flex justify-content-between align-items-start">
        <h5 class="font-weight-bold text-dark d-flex align-items-start mb-0">
            <span class="badge badge-dark mr-3 shadow-sm py-2 px-3 badge-pill text-uppercase flex-shrink-0">Question <?php echo $qNum; ?></span>
            <div style="line-height: 1.6; min-width: 0; flex-grow: 1;" class="pt-1 text-dark review-q-text font-weight-normal"><?php echo render_formatted_content($isFill ? format_blank_prompt($q['question_text']) : $q['question_text']); ?></div>
        </h5>
        <div class="ml-4 flex-shrink-0 mt-1">
            <?php if(!$attempt['key_released']): ?>
                <span class="badge badge-info px-3 py-2 shadow-sm rounded-pill text-white"><i class="fas fa-eye-slash mr-2"></i>Answer Hidden</span>
            <?php elseif(!$hasAttempted): ?>
                <span class="badge badge-warning px-3 py-2 shadow-sm rounded-pill text-white"><i class="fas fa-ban mr-2"></i>Not Attempted</span>
            <?php elseif($isCorrect): ?>
                <span class="badge badge-success px-3 py-2 shadow-sm rounded-pill"><i class="fas fa-check-circle mr-2"></i>Correct</span>
            <?php else: ?>
                <span class="badge badge-danger px-3 py-2 shadow-sm rounded-pill"><i class="fas fa-times-circle mr-2"></i>Incorrect</span>
            <?php endif; ?>
            <div class="mt-2 text-right">
                <button type="button" class="btn btn-xs btn-outline-warning text-dark font-weight-bold rounded-pill px-2 py-1 shadow-sm" onclick="openReviewFlagModal(<?php echo $q['id']; ?>, <?php echo $qNum; ?>)" title="Flag this question for teacher review">
                    <i class="fas fa-flag text-warning mr-1"></i>Report Doubt
                </button>
            </div>
        </div>
    </div>
    <div class="card-body px-4 pb-4 pt-3 ml-md-5 pl-md-4">
        <?php if ($isFill): ?>
            <?php if (!empty($eval['total_blanks']) && $eval['total_blanks'] > 1): ?>
                <div class="mb-3 d-flex align-items-center justify-content-between flex-wrap">
                    <span class="badge badge-info px-3 py-1 font-weight-bold rounded-pill shadow-xs"><i class="fas fa-layer-group mr-1"></i><?php echo $eval['total_blanks']; ?> Fill-in Blanks</span>
                    <?php if ($attempt['key_released']): ?>
                        <span class="badge <?php echo $isCorrect ? 'badge-success' : ($eval['correct_blanks'] > 0 ? 'badge-warning text-dark' : 'badge-danger'); ?> px-3 py-1 font-weight-bold rounded-pill shadow-xs">
                            <i class="fas <?php echo $isCorrect ? 'fa-check-circle' : 'fa-info-circle'; ?> mr-1"></i><?php echo $eval['correct_blanks']; ?> / <?php echo $eval['total_blanks']; ?> Blanks Correct
                        </span>
                    <?php endif; ?>
                </div>
                <div class="row">
                    <?php foreach ($eval['details'] as $bNum => $bDet): 
                        $bHasAns = (trim((string)$bDet['student']) !== '');
                        $bIsCorr = !empty($bDet['is_correct']);
                    ?>
                        <div class="col-md-6 mb-3">
                            <div class="p-3 rounded border shadow-sm h-100 <?php echo (!$attempt['key_released']) ? 'bg-light' : ($bIsCorr ? 'border-success bg-white' : ($bHasAns ? 'border-danger bg-white' : 'bg-light text-muted')); ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge badge-primary font-weight-bold px-2 py-1">Blank <?php echo $bNum; ?></span>
                                    <?php if ($attempt['key_released']): ?>
                                        <?php if ($bIsCorr): ?>
                                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Correct</span>
                                        <?php elseif ($bHasAns): ?>
                                            <span class="badge badge-danger px-2 py-1"><i class="fas fa-times mr-1"></i>Incorrect</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary px-2 py-1">Unattempted</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="small font-weight-bold text-muted text-uppercase mb-1"><i class="fas fa-user-edit mr-1"></i>Your Answer:</div>
                                <div class="h6 font-weight-bold <?php echo (!$attempt['key_released']) ? 'text-dark' : ($bIsCorr ? 'text-success' : ($bHasAns ? 'text-danger' : 'text-muted')); ?> mb-2">
                                    <?php echo $bHasAns ? h($bDet['student']) : '<em class="text-muted">No response</em>'; ?>
                                </div>
                                <?php if ($attempt['key_released']): ?>
                                    <div class="pt-2 border-top">
                                        <div class="small font-weight-bold text-success text-uppercase mb-1"><i class="fas fa-key mr-1"></i>Accepted Key:</div>
                                        <div class="small font-weight-bold text-dark mb-0"><?php echo h($bDet['correct']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="p-3 rounded border shadow-sm <?php echo (!$attempt['key_released']) ? 'bg-light' : ($isCorrect ? 'bg-success text-white' : ($hasAttempted ? 'bg-danger text-white' : 'bg-light text-muted')); ?>">
                            <div class="small font-weight-bold text-uppercase mb-1 opacity-75">
                                <i class="fas fa-user-edit mr-1"></i>Your Submitted Answer
                            </div>
                            <div class="h5 font-weight-bold mb-0">
                                <?php echo $hasAttempted ? h($selText) : '<em class="opacity-75">No answer entered</em>'; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($attempt['key_released']): ?>
                    <div class="col-md-6 mb-3">
                        <div class="p-3 rounded border shadow-sm bg-light border-success">
                            <div class="small font-weight-bold text-uppercase text-success mb-1">
                                <i class="fas fa-check-circle mr-1"></i>Official Accepted Answer(s)
                            </div>
                            <div class="h5 font-weight-bold text-dark mb-0">
                                <?php echo h($corText); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="row">
                <?php for($i=1; $i<=4; $i++): 
                    $optText = $q['option'.$i];
                    if ($optText === null || trim((string)$optText) === '') continue;
                    $is_selected = ($sel == $i);
                    $is_correct_opt = ($attempt['key_released'] && $cor == $i);
                    
                    $cls = 'review-opt-box shadow-sm';
                    $badgeHtml = '';
                    
                    if ($is_correct_opt) {
                        $cls .= ' is-correct shadow';
                        $badgeHtml .= '<span class="badge badge-success px-2 py-1 shadow-sm ml-2"><i class="fas fa-check-circle mr-1"></i>Correct</span>';
                    } elseif ($is_selected && $attempt['key_released'] && !$is_correct_opt) {
                        $cls .= ' is-incorrect shadow-sm';
                        $badgeHtml .= '<span class="badge badge-danger px-2 py-1 shadow-sm ml-2"><i class="fas fa-times-circle mr-1"></i>Incorrect</span>';
                    } elseif ($is_selected && !$attempt['key_released']) {
                        $cls .= ' is-selected-pending shadow-sm';
                    }

                    if ($is_selected) {
                        $badgeHtml .= '<span class="badge badge-dark px-2 py-1 shadow-sm ml-2" style="font-size:0.75rem; border: 1px solid rgba(255,255,255,0.2);">Your Answer <i class="fas fa-location-arrow ml-1"></i></span>';
                    }
                ?>
                <div class="col-md-6 mb-3">
                    <div class="p-3 d-flex align-items-center h-100 <?php echo $cls; ?>">
                        <span class="review-opt-letter mr-3">
                            <?php echo chr(64+$i); ?>
                        </span>
                        <div class="review-opt-content pr-2">
                            <?php echo render_formatted_content($optText); ?>
                        </div>
                        <?php if (!empty($badgeHtml)): ?>
                            <div class="d-flex align-items-center flex-shrink-0">
                                <?php echo $badgeHtml; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Review Flag Modal -->
<div class="modal fade" id="reviewFlagModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0 rounded-lg">
            <div class="modal-header bg-warning text-dark border-0 py-3">
                <h5 class="modal-title font-weight-bold mb-0">
                    <i class="fas fa-flag text-danger mr-2"></i>Report Issue / Doubt on Question <span id="revFlagModalQNum"></span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="revFlagForm" onsubmit="handleReviewFlagSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                <input type="hidden" name="question_id" id="revFlagQuestionId">
                <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">
                        If you believe the correct answer key was wrong, the question was ambiguous, or there was a typo, specify below. Your instructor will investigate.
                    </p>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Reason for Doubt / Dispute</label>
                        <select name="flag_reason" class="form-control custom-select" required>
                            <option value="Incorrect Options / Answer Key">Incorrect Options / Answer Key</option>
                            <option value="Doubtful / Confusing Question">Doubtful / Confusing Question</option>
                            <option value="Typo or Text Error">Typo or Text Error</option>
                            <option value="Missing Diagram or Formula">Missing Diagram or Formula</option>
                            <option value="Other">Other Query</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Your Explanation / Remarks</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Provide details on why you believe this question needs review..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-3" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning font-weight-bold rounded-pill px-4 shadow-sm" id="revFlagSubmitBtn">
                        <i class="fas fa-flag mr-1"></i>Submit Doubt Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openReviewFlagModal(questionId, qNum) {
    document.getElementById('revFlagQuestionId').value = questionId;
    document.getElementById('revFlagModalQNum').textContent = 'Q' + qNum;
    $('#reviewFlagModal').modal('show');
}

function handleReviewFlagSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('revFlagSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Submitting...';

    const formData = new FormData(e.target);

    fetch('flag_question_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-flag mr-1"></i>Submit Doubt Report';
        if (data.success) {
            $('#reviewFlagModal').modal('hide');
            alert(data.message || 'Question doubt recorded for teacher review!');
        } else {
            alert(data.error || 'Failed to submit doubt.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-flag mr-1"></i>Submit Doubt Report';
        alert('An error occurred: ' + err);
    });
}
</script>

<?php require_once '../includes/student_footer.php'; ?>

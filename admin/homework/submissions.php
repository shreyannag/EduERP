<?php
// admin/homework/submissions.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('homework');

$homework_id = (int)($_GET['id'] ?? 0);
if (!$homework_id) {
    header('Location: index.php');
    exit;
}

$scoped_admin_id = is_super_admin() ? (!empty($_GET['admin_id']) ? (int)$_GET['admin_id'] : (int)$_SESSION['admin_id']) : get_effective_admin_id();

// Fetch homework
$stmtHw = $pdo->prepare("
    SELECT hw.*, 
           c.name as class_name, b.name as board_name, sub.name as subject_name, bt.name as batch_name,
           cr.username as creator_username, cr.full_name as creator_fullname
    FROM homework hw
    LEFT JOIN classes c ON hw.class_id = c.id
    LEFT JOIN boards b ON hw.board_id = b.id
    LEFT JOIN subjects sub ON hw.subject_id = sub.id
    LEFT JOIN batches bt ON hw.batch_id = bt.id
    LEFT JOIN admins cr ON hw.created_by = cr.id
    WHERE hw.id = ?
");
$stmtHw->execute([$homework_id]);
$hw = $stmtHw->fetch(PDO::FETCH_ASSOC);

if (!$hw) {
    echo "<div class='container my-5'><div class='alert alert-danger'>Homework assignment not found.</div></div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

if (!is_super_admin() && (int)$hw['admin_id'] !== $scoped_admin_id) {
    http_response_code(403);
    echo "<div class='container my-5'><div class='alert alert-danger'>Access Denied: You do not own this assignment.</div></div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

// Fetch candidate students targeted by this homework
if (!empty($hw['batch_id'])) {
    $stmtStudents = $pdo->prepare("
        SELECT s.id as student_id, s.full_name, s.username, s.email, c.name as class_name, b.name as board_name,
               sub.id as submission_id, sub.submission_text, sub.submitted_code, sub.attachment_path as student_attachment,
               sub.status as submission_status, sub.grade_points, sub.teacher_feedback, sub.submitted_at, sub.graded_at
        FROM batch_students bs
        JOIN students s ON bs.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN boards b ON s.board_id = b.id
        LEFT JOIN homework_submissions sub ON sub.homework_id = ? AND sub.student_id = s.id
        WHERE bs.batch_id = ? AND s.is_active = 1
        ORDER BY sub.submitted_at DESC, s.full_name ASC
    ");
    $stmtStudents->execute([$homework_id, (int)$hw['batch_id']]);
} elseif (!empty($hw['class_id'])) {
    $whereB = (!empty($hw['board_id'])) ? "AND s.board_id = " . (int)$hw['board_id'] : "";
    $stmtStudents = $pdo->prepare("
        SELECT s.id as student_id, s.full_name, s.username, s.email, c.name as class_name, b.name as board_name,
               sub.id as submission_id, sub.submission_text, sub.submitted_code, sub.attachment_path as student_attachment,
               sub.status as submission_status, sub.grade_points, sub.teacher_feedback, sub.submitted_at, sub.graded_at
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN boards b ON s.board_id = b.id
        LEFT JOIN homework_submissions sub ON sub.homework_id = ? AND sub.student_id = s.id
        WHERE s.admin_id = ? AND s.class_id = ? {$whereB} AND s.is_active = 1
        ORDER BY sub.submitted_at DESC, s.full_name ASC
    ");
    $stmtStudents->execute([$homework_id, $scoped_admin_id, (int)$hw['class_id']]);
} else {
    // All students
    $stmtStudents = $pdo->prepare("
        SELECT s.id as student_id, s.full_name, s.username, s.email, c.name as class_name, b.name as board_name,
               sub.id as submission_id, sub.submission_text, sub.submitted_code, sub.attachment_path as student_attachment,
               sub.status as submission_status, sub.grade_points, sub.teacher_feedback, sub.submitted_at, sub.graded_at
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN boards b ON s.board_id = b.id
        LEFT JOIN homework_submissions sub ON sub.homework_id = ? AND sub.student_id = s.id
        WHERE s.admin_id = ? AND s.is_active = 1
        ORDER BY sub.submitted_at DESC, s.full_name ASC
    ");
    $stmtStudents->execute([$homework_id, $scoped_admin_id]);
}
$students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalAssigned = count($students);
$submittedCount = 0;
$reviewedCount = 0;
foreach ($students as $st) {
    if (!empty($st['submission_id'])) {
        $submittedCount++;
        if ($st['submission_status'] === 'reviewed') $reviewedCount++;
    }
}
$pendingSubmissions = $totalAssigned - $submittedCount;
$isOverdue = (strtotime($hw['due_date']) < time());
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-tasks text-primary mr-2"></i>Submissions & Grading: <?= htmlspecialchars($hw['title']) ?>
        </h2>
        <p class="text-muted mb-0 small">
            Review student answers, examine programming code submissions, inspect solution photos, and record grades & remarks.
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap">
        <a href="edit.php?id=<?= $hw['id'] ?>" class="btn btn-outline-info font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2">
            <i class="fas fa-edit mr-1"></i>Edit Assignment
        </a>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-3 mb-2">
            <i class="fas fa-arrow-left mr-1"></i>Back to Hub
        </a>
    </div>
</div>

<div id="ajaxAlertContainer"></div>

<!-- Homework Summary Card -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-light">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center flex-wrap mb-2">
                    <h4 class="font-weight-bold text-dark mb-0 mr-3"><?= htmlspecialchars($hw['title']) ?></h4>
                    <?php if (!empty($hw['batch_name'])): ?>
                        <span class="badge badge-primary px-3 py-1 font-weight-bold mr-2"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($hw['batch_name']) ?></span>
                    <?php elseif (!empty($hw['class_name'])): ?>
                        <span class="badge badge-info px-3 py-1 font-weight-bold mr-2"><i class="fas fa-graduation-cap mr-1"></i>Class <?= htmlspecialchars($hw['class_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($hw['subject_name'])): ?>
                        <span class="badge badge-secondary px-2 py-1"><i class="fas fa-book mr-1"></i><?= htmlspecialchars($hw['subject_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="small text-muted">
                    <span class="mr-3"><i class="far fa-calendar-alt text-primary mr-1"></i><strong>Assigned:</strong> <?= date('d M, Y \a\t h:i A', strtotime($hw['assigned_date'])) ?></span>
                    <span class="mr-3"><i class="far fa-clock text-danger mr-1"></i><strong>Due:</strong> <?= date('d M, Y \a\t h:i A', strtotime($hw['due_date'])) ?></span>
                    <span class="font-weight-bold text-dark"><i class="fas fa-award text-warning mr-1"></i>Max Marks: <?= (int)$hw['total_points'] ?></span>
                </div>
            </div>
            <div class="col-md-4 text-md-right mt-3 mt-md-0">
                <?php if ($isOverdue): ?>
                    <span class="badge badge-danger px-3 py-2 font-weight-bold text-uppercase" style="font-size: 0.85rem;">
                        <i class="fas fa-clock mr-1"></i>Past Deadline
                    </span>
                <?php else: ?>
                    <span class="badge badge-success px-3 py-2 font-weight-bold text-uppercase" style="font-size: 0.85rem;">
                        <i class="fas fa-check-circle mr-1"></i>Active & Open
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($hw['description'])): ?>
        <div class="mt-3 pt-3 border-top">
            <h6 class="font-weight-bold text-dark small mb-2"><i class="fas fa-align-left text-primary mr-1"></i>Assignment Prompt:</h6>
            <div class="p-3 bg-white rounded border text-dark" style="max-height: 180px; overflow-y: auto;">
                <?= render_formatted_content($hw['description']) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Metrics -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-users fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Target Cohort</div>
                    <h3 class="font-weight-bold text-dark mb-0"><?= $totalAssigned ?></h3>
                    <span class="small text-muted">Assigned students</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-file-check fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Turned In</div>
                    <h3 class="font-weight-bold text-success mb-0"><?= $submittedCount ?></h3>
                    <span class="small text-muted"><?= ($totalAssigned > 0) ? round(($submittedCount / $totalAssigned) * 100) : 0 ?>% turn-in rate</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-check-double fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Graded & Reviewed</div>
                    <h3 class="font-weight-bold text-info mb-0"><?= $reviewedCount ?></h3>
                    <span class="small text-muted"><?= ($submittedCount - $reviewedCount) ?> awaiting review</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-user-clock fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Pending Turn-In</div>
                    <h3 class="font-weight-bold text-warning mb-0"><?= $pendingSubmissions ?></h3>
                    <span class="small text-muted">Awaiting submission</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Student Submissions Roster -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-user-graduate text-primary mr-2"></i>Student Roster & Submissions (<?= count($students) ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 28%;">Student Details</th>
                    <th style="width: 15%;">Class & Board</th>
                    <th style="width: 22%;">Submission Status & Time</th>
                    <th style="width: 15%;">Grade Points</th>
                    <th style="width: 20%; text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="fas fa-users-slash fa-3x mb-3 text-secondary opacity-50"></i>
                        <p class="mb-0 font-weight-bold">No students found matching this homework's target audience.</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($students as $st): 
                        $hasSubmitted = !empty($st['submission_id']);
                        $isReviewed = ($st['submission_status'] === 'reviewed');
                    ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-light text-primary font-weight-bold d-flex align-items-center justify-content-center mr-3" style="width: 36px; height: 36px;">
                                    <?= strtoupper(substr($st['full_name'] ?: $st['username'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="font-weight-bold text-dark"><?= htmlspecialchars($st['full_name'] ?: $st['username']) ?></div>
                                    <small class="text-muted">@<?= htmlspecialchars($st['username']) ?></small>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="badge badge-info px-2 py-1"><?= htmlspecialchars($st['class_name'] ?: 'N/A') ?></span>
                            <?php if (!empty($st['board_name'])): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($st['board_name']) ?></small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($hasSubmitted): ?>
                                <span class="badge badge-success px-2 py-1 font-weight-bold">
                                    <i class="fas fa-check-circle mr-1"></i>SUBMITTED
                                </span>
                                <div class="small text-muted mt-1">
                                    <?= date('d M, Y \a\t h:i A', strtotime($st['submitted_at'])) ?>
                                </div>
                                <?php if (!empty($st['student_attachment'])): ?>
                                    <span class="badge badge-light border text-dark mt-1"><i class="fas fa-paperclip mr-1 text-primary"></i>Photo Attached</span>
                                <?php endif; ?>
                                <?php if (!empty($st['submitted_code'])): ?>
                                    <span class="badge badge-light border text-dark mt-1"><i class="fas fa-code mr-1 text-info"></i>Code Submitted</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($isOverdue): ?>
                                    <span class="badge badge-danger px-2 py-1 font-weight-bold">
                                        <i class="fas fa-times-circle mr-1"></i>MISSING / LATE
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning text-dark px-2 py-1 font-weight-bold">
                                        <i class="fas fa-clock mr-1"></i>PENDING
                                    </span>
                                <?php endif; ?>
                                <small class="text-muted d-block mt-1">No submission yet</small>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($isReviewed): ?>
                                <span class="font-weight-bold text-success" style="font-size: 1.1rem;">
                                    <?= number_format((float)$st['grade_points'], 1) ?>
                                </span>
                                <small class="text-muted">/ <?= (int)$hw['total_points'] ?></small>
                                <?php if (!empty($st['teacher_feedback'])): ?>
                                    <small class="d-block text-truncate text-muted" style="max-width: 140px;" title="<?= htmlspecialchars($st['teacher_feedback']) ?>">
                                        "<?= htmlspecialchars($st['teacher_feedback']) ?>"
                                    </small>
                                <?php endif; ?>
                            <?php elseif ($hasSubmitted): ?>
                                <span class="badge badge-info px-2 py-1 font-weight-bold">Awaiting Grade</span>
                            <?php else: ?>
                                <span class="text-muted">&mdash;</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <?php if ($hasSubmitted): ?>
                                <button type="button" class="btn btn-sm btn-primary font-weight-bold rounded-pill px-3 shadow-sm" onclick='openReviewModal(<?= json_encode($st) ?>, <?= json_encode($hw) ?>)'>
                                    <i class="fas fa-check-double mr-1"></i>Review & Grade
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled>
                                    Not Submitted
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: REVIEW & GRADE SUBMISSION           -->
<!-- ========================================== -->
<div class="modal fade" id="reviewModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white py-3">
                <div>
                    <h5 class="modal-title font-weight-bold text-white mb-0">
                        <i class="fas fa-graduation-cap text-warning mr-2"></i>Reviewing Submission: <span id="modalStudentName"></span>
                    </h5>
                    <small class="text-white-50" id="modalSubmissionTime"></small>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="gradingForm" onsubmit="handleSaveGrade(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="submission_id" id="modalSubmissionId">
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    <!-- Student Written Solution -->
                    <div class="mb-4">
                        <h6 class="font-weight-bold text-dark mb-2">
                            <i class="fas fa-pen text-primary mr-1"></i>Student Written Answer / Solution:
                        </h6>
                        <div id="modalWrittenAnswer" class="p-3 bg-light rounded border text-dark" style="min-height: 60px; white-space: pre-wrap;">
                            <span class="text-muted italic">No written notes provided.</span>
                        </div>
                    </div>

                    <!-- Submitted Code (if programming question) -->
                    <div id="modalCodeSection" class="mb-4" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h6 class="font-weight-bold text-dark mb-0">
                                <i class="fas fa-code text-info mr-1"></i>Student Submitted Code:
                            </h6>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-2 font-weight-bold" onclick="copySubmittedCode()">
                                <i class="fas fa-copy mr-1"></i>Copy Code
                            </button>
                        </div>
                        <pre class="rounded" style="max-height: 280px;"><code id="modalSubmittedCodeBlock" class="language-python"></code></pre>
                    </div>

                    <!-- Submitted Photo Attachment (Handwritten solution) -->
                    <div id="modalAttachmentSection" class="mb-4" style="display: none;">
                        <h6 class="font-weight-bold text-dark mb-2">
                            <i class="fas fa-image text-success mr-1"></i>Uploaded Solution Photo / Document:
                        </h6>
                        <div class="p-3 bg-light rounded border text-center">
                            <img id="modalAttachmentImg" src="" alt="Solution Photo" class="img-fluid rounded shadow-sm mb-2" style="max-height: 380px;">
                            <div class="mt-2">
                                <a id="modalAttachmentLink" href="" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold">
                                    <i class="fas fa-external-link-alt mr-1"></i>Open Full-Resolution Image
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Optional Teacher Reference Key (Collapsible) -->
                    <div id="modalTeacherKeySection" class="mb-4" style="display: none;">
                        <a class="font-weight-bold text-muted small" data-toggle="collapse" href="#collapseTeacherKey" role="button">
                            <i class="fas fa-key text-warning mr-1"></i>Show Reference Answer Key & Rubric
                        </a>
                        <div class="collapse mt-2" id="collapseTeacherKey">
                            <div class="p-3 bg-warning bg-opacity-10 rounded border border-warning text-dark small" id="modalTeacherKeyText">
                            </div>
                        </div>
                    </div>

                    <!-- Grading Section -->
                    <div class="card bg-light border-0 p-3">
                        <h6 class="font-weight-bold text-dark mb-3">
                            <i class="fas fa-award text-warning mr-1"></i>Grading & Evaluation
                        </h6>
                        <div class="form-row">
                            <div class="col-md-5 form-group mb-3">
                                <label class="font-weight-bold text-dark small mb-1">
                                    Awarded Points (Max: <span id="modalMaxPoints">100</span>)
                                </label>
                                <input type="number" step="0.5" name="grade_points" id="modalGradePoints" class="form-control form-control-lg font-weight-bold text-primary" required min="0">
                            </div>
                            <div class="col-md-7 form-group mb-3">
                                <label class="font-weight-bold text-dark small mb-1">Teacher Feedback & Remarks</label>
                                <textarea name="teacher_feedback" id="modalTeacherFeedback" class="form-control" rows="2" placeholder="e.g. Excellent solution! Well explained with clear code logic."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold rounded-pill px-4 shadow-sm" id="saveGradeBtn">
                        <i class="fas fa-check-circle mr-1"></i>Save Grade & Feedback
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
let currentSubCode = '';

function openReviewModal(student, homework) {
    document.getElementById('modalSubmissionId').value = student.submission_id;
    document.getElementById('modalStudentName').textContent = student.full_name || student.username;
    document.getElementById('modalSubmissionTime').textContent = 'Turned in on: ' + student.submitted_at;
    document.getElementById('modalMaxPoints').textContent = homework.total_points;
    document.getElementById('modalGradePoints').max = homework.total_points;

    // Written answer
    const writtenEl = document.getElementById('modalWrittenAnswer');
    writtenEl.textContent = student.submission_text || 'No written notes provided.';

    // Code Section
    const codeSec = document.getElementById('modalCodeSection');
    const codeBlock = document.getElementById('modalSubmittedCodeBlock');
    currentSubCode = student.submitted_code || '';

    if (currentSubCode) {
        codeSec.style.display = 'block';
        const lang = homework.programming_lang || 'python';
        codeBlock.className = 'language-' + lang;
        codeBlock.textContent = currentSubCode;
        if (window.Prism) Prism.highlightElement(codeBlock);
    } else {
        codeSec.style.display = 'none';
    }

    // Attachment Section
    const attSec = document.getElementById('modalAttachmentSection');
    const attImg = document.getElementById('modalAttachmentImg');
    const attLink = document.getElementById('modalAttachmentLink');

    if (student.student_attachment) {
        attSec.style.display = 'block';
        const url = '<?= BASE_URL ?>/' + student.student_attachment;
        attImg.src = url;
        attLink.href = url;
    } else {
        attSec.style.display = 'none';
    }

    // Teacher Solution Key
    const keySec = document.getElementById('modalTeacherKeySection');
    const keyText = document.getElementById('modalTeacherKeyText');
    if (homework.solution_guide) {
        keySec.style.display = 'block';
        keyText.textContent = homework.solution_guide;
    } else {
        keySec.style.display = 'none';
    }

    // Existing grade values
    document.getElementById('modalGradePoints').value = (student.grade_points !== null) ? student.grade_points : '';
    document.getElementById('modalTeacherFeedback').value = student.teacher_feedback || '';

    $('#reviewModal').modal('show');
}

function handleSaveGrade(e) {
    e.preventDefault();
    const btn = document.getElementById('saveGradeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Saving...';

    const formData = new FormData(e.target);

    fetch('grade_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Save Grade & Feedback';
        if (data.success) {
            $('#reviewModal').modal('hide');
            alert(data.message);
            location.reload();
        } else {
            alert(data.error || 'Failed to save grade.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Save Grade & Feedback';
        alert('An error occurred: ' + err);
    });
}

function copySubmittedCode() {
    if (!currentSubCode) return;
    navigator.clipboard.writeText(currentSubCode).then(() => {
        alert('Student code copied to clipboard!');
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

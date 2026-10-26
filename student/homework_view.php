<?php
// student/homework_view.php
require_once __DIR__ . '/../includes/student_header.php';

$student_id = (int)$_SESSION['student_id'];
$homework_id = (int)($_GET['id'] ?? 0);

if (!$homework_id) {
    header('Location: homework.php');
    exit;
}

// Fetch current student profile
$stmtStu = $pdo->prepare("SELECT s.id, s.full_name, s.admin_id, s.class_id, s.board_id, a.institute_name FROM students s LEFT JOIN admins a ON s.admin_id = a.id WHERE s.id = ?");
$stmtStu->execute([$student_id]);
$student = $stmtStu->fetch(PDO::FETCH_ASSOC);

$admin_id = (int)$student['admin_id'];
$class_id = (int)$student['class_id'];
$board_id = (int)$student['board_id'];

// Fetch homework details & submission (if already submitted)
$stmtHw = $pdo->prepare("
    SELECT 
        hw.*,
        sub.name as subject_name,
        bt.name as batch_name,
        cr.username as creator_username,
        cr.full_name as creator_fullname,
        cr.sub_role as creator_sub_role,
        inst.institute_name,
        s.id as submission_id,
        s.submission_text,
        s.submitted_code,
        s.attachment_path as student_attachment,
        s.status as submission_status,
        s.grade_points,
        s.teacher_feedback,
        s.submitted_at,
        s.graded_at
    FROM homework hw
    LEFT JOIN subjects sub ON hw.subject_id = sub.id
    LEFT JOIN batches bt ON hw.batch_id = bt.id
    LEFT JOIN admins cr ON hw.created_by = cr.id
    LEFT JOIN admins inst ON hw.admin_id = inst.id
    LEFT JOIN homework_submissions s ON s.homework_id = hw.id AND s.student_id = ?
    WHERE hw.id = ? AND (hw.admin_id = ? OR hw.admin_id = 1) AND hw.is_active = 1
");
$stmtHw->execute([$student_id, $homework_id, $admin_id]);
$hw = $stmtHw->fetch(PDO::FETCH_ASSOC);

if (!$hw) {
    echo "<div class='container my-5'><div class='alert alert-danger'>Homework assignment not found or access restricted.</div><a href='homework.php' class='btn btn-primary'>Back to Homework</a></div>";
    require_once __DIR__ . '/../includes/student_footer.php';
    exit;
}

// Eligibility check
$isEligible = false;
if (!empty($hw['batch_id'])) {
    $stmtBCheck = $pdo->prepare("SELECT id FROM batch_students WHERE batch_id = ? AND student_id = ?");
    $stmtBCheck->execute([$hw['batch_id'], $student_id]);
    if ($stmtBCheck->fetch()) $isEligible = true;
} else {
    $matchClass = empty($hw['class_id']) || ((int)$hw['class_id'] === $class_id);
    $matchBoard = empty($hw['board_id']) || ((int)$hw['board_id'] === $board_id);
    if ($matchClass && $matchBoard) $isEligible = true;
}

if (!$isEligible) {
    echo "<div class='container my-5'><div class='alert alert-danger'>Access Denied: This assignment is designated for another class or cohort.</div><a href='homework.php' class='btn btn-primary'>Back to Homework</a></div>";
    require_once __DIR__ . '/../includes/student_footer.php';
    exit;
}

$hasSubmitted = !empty($hw['submission_id']);
$isReviewed = ($hw['submission_status'] === 'reviewed');
$isPastDue = (strtotime($hw['due_date']) < time());
$teacherName = $hw['creator_fullname'] ?: $hw['creator_username'];
?>

<div class="mb-4">
    <a href="homework.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-3 mb-3">
        <i class="fas fa-arrow-left mr-1"></i>Back to My Homework
    </a>
</div>

<div id="ajaxAlertContainer"></div>

<!-- Header Card -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
    <div class="card-body p-4 p-md-5">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-flex align-items-center flex-wrap mb-2">
                    <?php if (!empty($hw['batch_name'])): ?>
                        <span class="badge badge-primary px-3 py-1 font-weight-bold mr-2"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($hw['batch_name']) ?></span>
                    <?php elseif (!empty($hw['subject_name'])): ?>
                        <span class="badge badge-info px-3 py-1 font-weight-bold mr-2"><i class="fas fa-book mr-1"></i><?= htmlspecialchars($hw['subject_name']) ?></span>
                    <?php endif; ?>
                    <span class="badge badge-light border text-dark font-weight-bold mr-2"><i class="fas fa-award text-warning mr-1"></i><?= (int)$hw['total_points'] ?> Marks</span>
                    <?php if (!empty($hw['programming_lang']) && $hw['programming_lang'] !== 'none'): ?>
                        <span class="badge badge-dark text-info font-weight-bold"><i class="fas fa-code mr-1"></i><?= ucfirst($hw['programming_lang']) ?> Problem</span>
                    <?php endif; ?>
                </div>

                <h2 class="font-weight-bold text-dark mb-2"><?= htmlspecialchars($hw['title']) ?></h2>
                <p class="text-muted mb-0">
                    Assigned by: <strong><?= htmlspecialchars($teacherName) ?></strong> &bull; <?= htmlspecialchars($hw['institute_name'] ?: 'Main Campus') ?>
                </p>
            </div>

            <!-- Date & Time Card -->
            <div class="col-lg-4 mt-3 mt-lg-0">
                <div class="card bg-light border-0 p-3">
                    <div class="small mb-2">
                        <div class="text-muted">
                            <i class="far fa-calendar-alt text-primary mr-1"></i>
                            <strong>Assigned:</strong> <?= date('d M, Y \a\t h:i A', strtotime($hw['assigned_date'])) ?>
                        </div>
                        <div class="mt-1">
                            <i class="far fa-clock text-danger mr-1"></i>
                            <strong>Deadline:</strong> <span class="font-weight-bold text-dark"><?= date('d M, Y \a\t h:i A', strtotime($hw['due_date'])) ?></span>
                        </div>
                    </div>
                    <div id="countdownBox">
                        <?php if ($hasSubmitted): ?>
                            <span class="badge badge-success px-3 py-2 font-weight-bold text-uppercase w-100" style="font-size: 0.85rem;">
                                <i class="fas fa-check-circle mr-1"></i>Turned In
                            </span>
                        <?php elseif ($isPastDue): ?>
                            <span class="badge badge-danger px-3 py-2 font-weight-bold text-uppercase w-100" style="font-size: 0.85rem;">
                                <i class="fas fa-times-circle mr-1"></i>Past Deadline
                            </span>
                        <?php else: ?>
                            <span class="badge badge-warning text-dark px-3 py-2 font-weight-bold w-100" id="liveCountdownTimer" style="font-size: 0.85rem;">
                                <i class="fas fa-stopwatch mr-1"></i>Calculating time...
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left Column: Assignment Content & Code (8 cols) -->
    <div class="col-lg-7 mb-4">
        <!-- Problem Statement Card -->
        <div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-file-alt text-primary mr-2"></i>Assignment Instructions & Questions
                </h5>
            </div>
            <div class="card-body p-4 text-dark" style="font-size: 1.05rem; line-height: 1.7;">
                <?php if (!empty($hw['description'])): ?>
                    <div class="mb-4">
                        <?= render_formatted_content($hw['description']) ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Please refer to the attached diagram or code snippet below.</p>
                <?php endif; ?>

                <!-- Attached Problem Diagram / Worksheet -->
                <?php if (!empty($hw['attachment_path'])): 
                    $attUrl = BASE_URL . '/' . $hw['attachment_path'];
                    $isImage = preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $hw['attachment_path']);
                ?>
                <div class="card bg-light border-0 p-3 mt-4">
                    <h6 class="font-weight-bold text-dark mb-2">
                        <i class="fas fa-paperclip text-success mr-1"></i>Attached Problem Diagram / Worksheet:
                    </h6>
                    <?php if ($isImage): ?>
                        <div class="text-center my-2">
                            <a href="<?= $attUrl ?>" target="_blank" title="Click to view full image">
                                <img src="<?= $attUrl ?>" alt="Problem Diagram" class="img-fluid rounded shadow-sm border" style="max-height: 380px;">
                            </a>
                        </div>
                    <?php endif; ?>
                    <div class="mt-2 text-center">
                        <a href="<?= $attUrl ?>" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3">
                            <i class="fas fa-external-link-alt mr-1"></i>Open / Download Attachment
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Programming Problem & Starter Code (if applicable) -->
        <?php if (!empty($hw['programming_lang']) && $hw['programming_lang'] !== 'none'): ?>
        <div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
            <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="font-weight-bold text-white mb-0">
                        <i class="fas fa-laptop-code text-info mr-2"></i>Programming Challenge (<?= ucfirst($hw['programming_lang']) ?>)
                    </h5>
                    <small class="text-white-50">Examine the code snippet below and complete the solution.</small>
                </div>
                <button type="button" class="btn btn-xs btn-outline-light font-weight-bold px-3 py-1" onclick="copyStarterCode()">
                    <i class="fas fa-copy mr-1"></i>Copy Code
                </button>
            </div>
            <div class="card-body p-0">
                <pre class="m-0" style="border-radius: 0 0 12px 12px; max-height: 400px;"><code id="starterCodeBlock" class="language-<?= htmlspecialchars($hw['programming_lang']) ?>"><?= htmlspecialchars($hw['starter_code'] ?: '# No starter code provided') ?></code></pre>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Submission Console (5 cols) -->
    <div class="col-lg-5">
        <!-- Submission Status Banner if Already Submitted -->
        <?php if ($hasSubmitted): ?>
        <div class="card border-0 shadow-sm rounded-lg mb-4 bg-success bg-opacity-10 border-success">
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-3">
                    <div class="rounded-circle bg-success text-white p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
                        <i class="fas fa-check fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="font-weight-bold text-success mb-0">Assignment Turned In</h5>
                        <small class="text-muted">Submitted on <?= date('d M, Y \a\t h:i A', strtotime($hw['submitted_at'])) ?></small>
                    </div>
                </div>

                <?php if ($isReviewed): ?>
                <div class="p-3 bg-white rounded border border-success mb-3">
                    <span class="small font-weight-bold text-uppercase text-muted">Evaluation & Grade</span>
                    <h3 class="font-weight-bold text-success mb-1">
                        <?= number_format((float)$hw['grade_points'], 1) ?> <span class="small text-muted font-weight-normal">/ <?= (int)$hw['total_points'] ?> Marks</span>
                    </h3>
                    <?php if (!empty($hw['teacher_feedback'])): ?>
                        <div class="mt-2 pt-2 border-top">
                            <span class="small font-weight-bold text-dark"><i class="fas fa-comment-dots text-primary mr-1"></i>Instructor Remarks:</span>
                            <p class="small text-dark mb-0 font-italic mt-1">"<?= htmlspecialchars($hw['teacher_feedback']) ?>"</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <div class="badge badge-info px-3 py-2 font-weight-bold mb-3 d-block">
                        <i class="fas fa-clock mr-1"></i>Awaiting Teacher Evaluation
                    </div>
                <?php endif; ?>

                <a class="btn btn-outline-success btn-sm btn-block font-weight-bold mb-2" data-toggle="collapse" href="#collapseMySubmission" role="button">
                    <i class="fas fa-eye mr-1"></i>Your Submitted Solution & Work
                </a>

                <div class="collapse show mt-2" id="collapseMySubmission">
                    <div class="p-3 bg-white rounded border small shadow-sm">
                        <?php if (!empty($hw['submission_text'])): ?>
                            <h6 class="font-weight-bold text-dark small mb-1"><i class="fas fa-pen text-primary mr-1"></i>Your Written Solution:</h6>
                            <div class="text-dark mb-3 p-3 bg-light rounded border" id="studentSubmittedText" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.6;"><?= htmlspecialchars($hw['submission_text']) ?></div>
                        <?php endif; ?>

                        <?php if (!empty($hw['submitted_code'])): ?>
                            <h6 class="font-weight-bold text-dark small mb-1"><i class="fas fa-code text-info mr-1"></i>Your Code:</h6>
                            <pre class="rounded mb-3"><code class="language-<?= htmlspecialchars($hw['programming_lang'] ?: 'python') ?>"><?= htmlspecialchars($hw['submitted_code']) ?></code></pre>
                        <?php endif; ?>

                        <?php if (!empty($hw['student_attachment'])): 
                            $attExt = strtolower(pathinfo($hw['student_attachment'], PATHINFO_EXTENSION));
                            $attUrl = BASE_URL . '/' . $hw['student_attachment'];
                        ?>
                            <h6 class="font-weight-bold text-dark small mb-1"><i class="fas fa-paperclip text-success mr-1"></i>Uploaded Solution File:</h6>
                            <?php if ($attExt === 'pdf'): ?>
                                <div class="alert alert-info py-2 mb-2"><i class="fas fa-file-pdf text-danger mr-2"></i><strong>PDF Solution Document</strong></div>
                                <iframe src="<?= $attUrl ?>" style="width:100%; height:320px; border:1px solid #cbd5e1; border-radius:6px;" class="mb-2"></iframe>
                            <?php else: ?>
                                <div class="text-center mb-2">
                                    <a href="<?= $attUrl ?>" target="_blank">
                                        <img src="<?= $attUrl ?>" alt="My Solution" class="img-fluid rounded border" style="max-height: 250px;">
                                    </a>
                                </div>
                            <?php endif; ?>
                            <div class="text-center">
                                <a href="<?= $attUrl ?>" target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3">
                                    <i class="fas fa-external-link-alt mr-1"></i>Open Full Attachment
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Submission Form Card -->
        <div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-paper-plane text-success mr-2"></i><?= $hasSubmitted ? 'Update / Resubmit Solution' : 'Submit Your Solution' ?>
                </h5>
                <small class="text-muted">Complete the fields below and submit before the deadline.</small>
            </div>
            <div class="card-body p-4">
                <form id="studentHomeworkForm" onsubmit="handleStudentSubmit(event)" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="homework_id" value="<?= $hw['id'] ?>">

                    <!-- Written notes/solution -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-pencil-alt text-primary mr-1"></i>Written Solution & Explanation
                        </label>
                        <textarea name="submission_text" id="submissionText" class="form-control" rows="5" placeholder="Type your answers, formula steps, or explanation here..."><?= htmlspecialchars($hw['submission_text'] ?? '') ?></textarea>
                    </div>

                    <!-- Code Solution (if programming problem) -->
                    <?php if (!empty($hw['programming_lang']) && $hw['programming_lang'] !== 'none'): ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-code text-info mr-1"></i>Your <?= ucfirst($hw['programming_lang']) ?> Code Solution
                        </label>
                        <textarea name="submitted_code" id="submittedCode" class="form-control font-family-monospace" rows="8" placeholder="# Type or paste your code solution here..." style="font-size: 13.5px; tab-size: 4;"><?= htmlspecialchars($hw['submitted_code'] ?: ($hw['starter_code'] ?? '')) ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Photo of handwritten solution upload -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-camera text-success mr-1"></i>Upload Photo of Handwritten Work / Worksheet
                        </label>
                        <div class="custom-file">
                            <input type="file" name="solution_attachment" class="custom-file-input" id="solutionFileInput" accept="image/*,application/pdf" onchange="previewSolutionFile(this)">
                            <label class="custom-file-label text-truncate" for="solutionFileInput">Choose notebook photo or PDF...</label>
                        </div>
                        <small class="text-muted d-block mt-1">Take a clear photo of your handwritten paper or notebook (JPG, PNG, WebP or PDF, Max 15MB).</small>
                        <div id="solutionPreviewDiv" class="mt-2 text-center" style="display: none;">
                            <img id="solutionPreviewImg" src="" alt="Preview" class="img-thumbnail" style="max-height: 180px;">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success btn-lg btn-block font-weight-bold shadow rounded-pill" id="submitHomeworkBtn">
                        <i class="fas fa-check-circle mr-2"></i><?= $hasSubmitted ? 'Turn In Updated Solution' : 'Turn In Homework' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Live Countdown Logic
const dueDate = new Date('<?= date('Y-m-d\TH:i:s', strtotime($hw['due_date'])) ?>').getTime();

function updateCountdown() {
    const now = new Date().getTime();
    const distance = dueDate - now;
    const timerEl = document.getElementById('liveCountdownTimer');

    if (!timerEl) return;

    if (distance < 0) {
        timerEl.className = 'badge badge-danger px-3 py-2 font-weight-bold text-uppercase w-100';
        timerEl.innerHTML = '<i class="fas fa-times-circle mr-1"></i>Past Deadline';
        return;
    }

    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

    let text = '';
    if (days > 0) {
        text = `<i class="fas fa-stopwatch mr-1"></i>${days}d ${hours}h ${minutes}m left`;
    } else {
        text = `<i class="fas fa-stopwatch mr-1"></i>${hours}h ${minutes}m ${seconds}s left`;
    }
    timerEl.innerHTML = text;
}

setInterval(updateCountdown, 1000);
updateCountdown();

function copyStarterCode() {
    const codeEl = document.getElementById('starterCodeBlock');
    if (!codeEl) return;
    navigator.clipboard.writeText(codeEl.innerText).then(() => {
        alert('Starter code copied to clipboard!');
    });
}

function previewSolutionFile(input) {
    const previewDiv = document.getElementById('solutionPreviewDiv');
    const previewImg = document.getElementById('solutionPreviewImg');
    const label = input.nextElementSibling;

    if (input.files && input.files[0]) {
        const file = input.files[0];
        label.textContent = file.name;

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewDiv.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            previewDiv.style.display = 'none';
        }
    }
}

function handleStudentSubmit(e) {
    e.preventDefault();

    if (!confirm('Are you ready to turn in your homework solution?')) {
        return;
    }

    const btn = document.getElementById('submitHomeworkBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Uploading & Submitting...';

    const formData = new FormData(e.target);

    fetch('homework_submit_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Turn In Homework';
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.error || 'Failed to submit homework.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-2"></i>Turn In Homework';
        alert('An unexpected error occurred: ' + err);
    });
}
</script>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>

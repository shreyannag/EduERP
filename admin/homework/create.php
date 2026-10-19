<?php
// admin/homework/create.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('homework');

$scoped_admin_id = is_super_admin() ? (!empty($_GET['admin_id']) ? (int)$_GET['admin_id'] : (int)$_SESSION['admin_id']) : get_effective_admin_id();

// Fetch Classes & Boards
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);
$boards  = $pdo->query("SELECT id, name FROM boards ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Subjects scoped to admin
$stmtSub = $pdo->prepare("SELECT id, name FROM subjects WHERE admin_id = ? OR admin_id = 1 ORDER BY name ASC");
$stmtSub->execute([$scoped_admin_id]);
$subjects = $stmtSub->fetchAll(PDO::FETCH_ASSOC);

// Fetch Batches
if (is_teacher()) {
    $stmtBatches = $pdo->prepare("SELECT id, name, class_id, schedule_desc FROM batches WHERE admin_id = ? AND teacher_id = ? AND is_active = 1 ORDER BY name ASC");
    $stmtBatches->execute([$scoped_admin_id, (int)$_SESSION['admin_id']]);
} else {
    $stmtBatches = $pdo->prepare("SELECT id, name, class_id, schedule_desc FROM batches WHERE admin_id = ? AND is_active = 1 ORDER BY name ASC");
    $stmtBatches->execute([$scoped_admin_id]);
}
$batches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $title            = trim($_POST['title'] ?? '');
    $target_type      = $_POST['target_type'] ?? 'class';
    $batch_id         = ($target_type === 'batch' && !empty($_POST['batch_id'])) ? (int)$_POST['batch_id'] : null;
    $class_id         = ($target_type === 'class' && !empty($_POST['class_id'])) ? (int)$_POST['class_id'] : null;
    $board_id         = ($target_type === 'class' && !empty($_POST['board_id'])) ? (int)$_POST['board_id'] : null;
    $subject_id       = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
    $assigned_date    = trim($_POST['assigned_date'] ?? date('Y-m-d H:i:s'));
    $due_date         = trim($_POST['due_date'] ?? '');
    $total_points     = max(1, (int)($_POST['total_points'] ?? 100));
    $description      = trim($_POST['description'] ?? '');
    $has_programming  = !empty($_POST['has_programming']);
    $programming_lang = $has_programming ? trim($_POST['programming_lang'] ?? 'python') : 'none';
    $starter_code     = $has_programming ? trim($_POST['starter_code'] ?? '') : null;
    $solution_guide   = trim($_POST['solution_guide'] ?? '');

    // Validations
    if (empty($title)) {
        $error = 'Homework title is required.';
    } elseif (empty($due_date)) {
        $error = 'Due date and time is required.';
    } elseif (empty($description) && empty($_FILES['attachment']['name']) && empty($starter_code)) {
        $error = 'Please provide homework instructions, an attachment image, or a code snippet.';
    } else {
        // Validate date formats
        if (strtotime($assigned_date) === false) {
            $assigned_date = date('Y-m-d H:i:s');
        } else {
            $assigned_date = date('Y-m-d H:i:s', strtotime($assigned_date));
        }

        if (strtotime($due_date) === false) {
            $error = 'Invalid due date and time specified.';
        } else {
            $due_date = date('Y-m-d H:i:s', strtotime($due_date));
        }
    }

    // Handle Picture Attachment Upload
    $attachment_path = null;
    if (empty($error) && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExtensions)) {
            $error = 'Attachment must be an image (JPG, PNG, WebP, GIF) or PDF document.';
        } elseif ($file['size'] > 15 * 1024 * 1024) {
            $error = 'Attachment size cannot exceed 15MB.';
        } else {
            $uploadDir = __DIR__ . '/../../uploads/homework/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = 'hw_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $destPath = $uploadDir . $fileName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $attachment_path = 'uploads/homework/' . $fileName;
            } else {
                $error = 'Failed to upload attachment file.';
            }
        }
    }

    if (empty($error)) {
        try {
            $created_by = (int)$_SESSION['admin_id'];

            // If teacher targeting a batch, auto-populate class_id from batch if null
            if ($batch_id && !$class_id) {
                $stmtBClass = $pdo->prepare("SELECT class_id, board_id, subject_id FROM batches WHERE id = ?");
                $stmtBClass->execute([$batch_id]);
                $bRow = $stmtBClass->fetch(PDO::FETCH_ASSOC);
                if ($bRow) {
                    $class_id = $bRow['class_id'];
                    if (!$board_id) $board_id = $bRow['board_id'];
                    if (!$subject_id && !empty($bRow['subject_id'])) $subject_id = $bRow['subject_id'];
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO homework (
                    admin_id, created_by, title, description, class_id, board_id, subject_id, batch_id,
                    assigned_date, due_date, total_points, attachment_path, programming_lang, starter_code,
                    solution_guide, is_active, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, 1, NOW()
                )
            ");
            $stmt->execute([
                $scoped_admin_id,
                $created_by,
                $title,
                $description,
                $class_id,
                $board_id,
                $subject_id,
                $batch_id,
                $assigned_date,
                $due_date,
                $total_points,
                $attachment_path,
                $programming_lang,
                $starter_code,
                !empty($solution_guide) ? $solution_guide : null
            ]);

            $new_hw_id = (int)$pdo->lastInsertId();
            $_SESSION['flash_success'] = "Homework '{$title}' successfully published and assigned to students!";
            header('Location: index.php');
            exit;
        } catch (\PDOException $e) {
            $error = 'Database error while saving homework: ' . $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-plus-circle text-primary mr-2"></i>Assign New Homework
        </h2>
        <p class="text-muted mb-0 small">
            Create an assignment for cohorts or classes with rich text, diagram pictures, LaTeX mathematical equations, and programming questions.
        </p>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-arrow-left mr-1"></i>Back to Homework Hub
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>

<form method="POST" action="create.php" enctype="multipart/form-data" id="homeworkForm">
    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

    <div class="row">
        <!-- Main Content (Left 8 Cols) -->
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-edit text-primary mr-2"></i>Assignment Details
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark mb-1">Homework Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control form-control-lg font-weight-bold" placeholder="e.g. Chapter 4: Laws of Motion Problem Set & Code Simulation" required maxlength="255" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                    </div>

                    <!-- Summernote Rich Text Editor with LaTeX Toolbar -->
                    <div class="form-group mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="font-weight-bold text-dark mb-0">Instructions & Problem Description <span class="text-danger">*</span></label>
                            <span class="small text-muted">Supports formatted text, lists, images & LaTeX ($ ... $)</span>
                        </div>

                        <!-- LaTeX Math Quick Toolbar -->
                        <div class="p-2 mb-2 bg-light border rounded d-flex flex-wrap align-items-center" style="gap: 4px;">
                            <span class="small font-weight-bold text-muted mr-2"><i class="fas fa-square-root-alt mr-1 text-primary"></i>Quick LaTeX:</span>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\frac{a}{b}')">$\frac{a}{b}$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\sqrt{x}')">$\sqrt{x}$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('x^2')">$x^2$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('x_i')">$x_i$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\int_{a}^{b} f(x) dx')">$\int$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\sum_{i=1}^{n} x_i')">$\sum$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\theta')">$\theta$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\pi')">$\pi$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\Delta')">$\Delta$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\le')">$\le$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\ge')">$\ge$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\pm')">$\pm$</button>
                            <button type="button" class="btn btn-sm btn-white border shadow-sm py-0 px-2" onclick="insertLatex('\\vec{F} = m\\vec{a}')">$\vec{F}=m\vec{a}$</button>
                        </div>

                        <textarea id="homeworkDescription" name="description" class="form-control"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Live Math Preview Accordion/Card -->
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small font-weight-bold text-dark"><i class="fas fa-eye text-info mr-1"></i>Live Math & LaTeX Typeset Preview:</span>
                                <button type="button" class="btn btn-xs btn-outline-info py-0 px-2" onclick="updateMathPreview()">
                                    <i class="fas fa-sync-alt mr-1"></i>Render Preview
                                </button>
                            </div>
                            <div id="mathPreviewBox" class="bg-white p-3 rounded border text-dark" style="min-height: 48px; max-height: 200px; overflow-y: auto;">
                                <span class="text-muted small italic">Mathematical equations enclosed in $...$ will render here live.</span>
                            </div>
                        </div>
                    </div>

                    <!-- Problem Picture / Diagram Attachment -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark mb-1">
                            <i class="fas fa-image text-success mr-1"></i>Attach Problem Picture / Diagram / Worksheet
                        </label>
                        <div class="custom-file">
                            <input type="file" name="attachment" class="custom-file-input" id="attachmentInput" accept="image/*,application/pdf" onchange="previewSelectedAttachment(this)">
                            <label class="custom-file-label" for="attachmentInput">Choose image or PDF file...</label>
                        </div>
                        <small class="text-muted d-block mt-1">Accepts PNG, JPG, WebP, GIF or PDF (Max 15MB). Students can view and download.</small>
                        <div id="attachmentPreview" class="mt-2" style="display: none;">
                            <img id="attachmentPreviewImg" src="" alt="Preview" class="img-thumbnail" style="max-height: 180px;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programming Question & Code Snippet Box -->
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="font-weight-bold text-dark mb-0">
                            <i class="fas fa-code text-info mr-2"></i>Programming Question & Code Snippet
                        </h5>
                        <small class="text-muted">Include starter code or code analysis problems for computer science and IT students.</small>
                    </div>
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="hasProgrammingSwitch" name="has_programming" value="1" onchange="toggleProgrammingBox(this.checked)">
                        <label class="custom-control-label font-weight-bold text-primary" for="hasProgrammingSwitch">Enable Code Problem</label>
                    </div>
                </div>
                <div class="card-body p-4" id="programmingBox" style="display: none;">
                    <div class="form-row mb-3">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-dark small mb-1">Programming Language</label>
                            <select name="programming_lang" id="programmingLangSelect" class="form-control custom-select" onchange="updateCodeSyntaxPreview()">
                                <option value="python">Python 3 (.py)</option>
                                <option value="cpp">C++ (.cpp)</option>
                                <option value="c">C (.c)</option>
                                <option value="java">Java (.java)</option>
                                <option value="javascript">JavaScript (.js)</option>
                                <option value="sql">SQL / Database</option>
                                <option value="html">HTML5 / Web</option>
                                <option value="php">PHP (.php)</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold text-dark small mb-1">Code Snippet Type</label>
                            <input type="text" class="form-control" placeholder="e.g. Starter Code / Buggy Function / Test Harness" readonly value="Student Starter Code & Problem Template">
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Starter Code / Problem Snippet</label>
                        <textarea name="starter_code" id="starterCodeTextarea" class="form-control font-family-monospace" rows="8" placeholder="# Write the starter code, function signature, or problem snippet here..." style="font-size: 13.5px; tab-size: 4;" oninput="updateCodeSyntaxPreview()"></textarea>
                    </div>

                    <!-- Code Syntax Preview -->
                    <label class="font-weight-bold text-dark small mb-1">Syntax Highlighted Preview (As seen by student):</label>
                    <pre class="rounded" style="max-height: 250px;"><code id="codeSyntaxPreview" class="language-python"># Code preview will render here...</code></pre>
                </div>
            </div>

            <!-- Optional Teacher Solution Guide / Answer Key -->
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-key text-warning mr-2"></i>Teacher Solution Guide & Answer Key (Private)
                    </h6>
                    <small class="text-muted">Hidden from students; accessible only to you and fellow teachers when reviewing submissions.</small>
                </div>
                <div class="card-body p-4">
                    <textarea name="solution_guide" class="form-control font-family-monospace" rows="4" placeholder="Optional reference solution, expected formula answers, or grading rubric..."><?= htmlspecialchars($_POST['solution_guide'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Sidebar Options (Right 4 Cols) -->
        <div class="col-lg-4">
            <!-- Publishing & Schedule Card -->
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-clock text-primary mr-2"></i>Date & Time Settings
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-calendar-check text-success mr-1"></i>Assigned Date & Time <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="assigned_date" id="assignedDatePicker" class="form-control" value="<?= date('Y-m-d H:i') ?>" required>
                        <small class="text-muted">When this homework becomes visible.</small>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-calendar-times text-danger mr-1"></i>Due Date & Time <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="due_date" id="dueDatePicker" class="form-control font-weight-bold text-danger" required placeholder="Select due date and time...">
                        <small class="text-muted">Submission deadline for students.</small>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">
                            <i class="fas fa-award text-warning mr-1"></i>Total Marks / Points <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="total_points" class="form-control" value="100" min="1" max="1000" required>
                    </div>
                </div>
            </div>

            <!-- Target Cohort & Audience Card -->
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-bullseye text-primary mr-2"></i>Target Audience
                    </h5>
                </div>
                <div class="card-body p-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Subject</label>
                        <select name="subject_id" class="form-control custom-select">
                            <option value="">General / All Subjects</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Assign Target Mode</label>
                        <div class="btn-group btn-group-toggle w-100" data-toggle="buttons">
                            <label class="btn btn-outline-primary btn-sm font-weight-bold active" onclick="setTargetType('batch')">
                                <input type="radio" name="target_type" value="batch" checked> Teaching Batch
                            </label>
                            <label class="btn btn-outline-primary btn-sm font-weight-bold" onclick="setTargetType('class')">
                                <input type="radio" name="target_type" value="class"> Academic Class
                            </label>
                            <label class="btn btn-outline-primary btn-sm font-weight-bold" onclick="setTargetType('all')">
                                <input type="radio" name="target_type" value="all"> Entire Institute
                            </label>
                        </div>
                    </div>

                    <!-- Batch Selector -->
                    <div id="targetBatchGroup" class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Select Teaching Batch</label>
                        <select name="batch_id" class="form-control custom-select">
                            <option value="">Select Batch...</option>
                            <?php foreach ($batches as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Only students enrolled in this batch will receive the homework.</small>
                    </div>

                    <!-- Class & Board Selector -->
                    <div id="targetClassGroup" class="form-group mb-3" style="display: none;">
                        <label class="font-weight-bold text-dark small mb-1">Class</label>
                        <select name="class_id" class="form-control custom-select mb-2">
                            <option value="">Select Class...</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label class="font-weight-bold text-dark small mb-1">Board (Optional)</label>
                        <select name="board_id" class="form-control custom-select">
                            <option value="">All Boards</option>
                            <?php foreach ($boards as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="card border-0 shadow-sm rounded-lg mb-4">
                <div class="card-body p-4 text-center">
                    <button type="submit" class="btn btn-primary btn-lg btn-block font-weight-bold shadow rounded-pill mb-2">
                        <i class="fas fa-paper-plane mr-2"></i>Publish & Assign Homework
                    </button>
                    <a href="index.php" class="btn btn-outline-secondary btn-block font-weight-bold rounded-pill">
                        Cancel
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Scripts for Summernote, Flatpickr, MathJax & Prism -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize Summernote Rich Editor with Image Upload
    $('#homeworkDescription').summernote({
        height: 250,
        placeholder: 'Enter instructions, questions, formulas, or drag & drop images here...',
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['fontname', ['fontname']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture', 'hr']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        callbacks: {
            onImageUpload: function(files) {
                uploadSummernoteImage(files[0]);
            },
            onChange: function(contents) {
                updateMathPreview();
            }
        }
    });

    // Initialize Flatpickr for Date and Time
    flatpickr("#assignedDatePicker", {
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        time_24hr: false,
        defaultDate: new Date()
    });

    const defaultDueDate = new Date();
    defaultDueDate.setDate(defaultDueDate.getDate() + 5);
    defaultDueDate.setHours(17, 0, 0, 0);

    flatpickr("#dueDatePicker", {
        enableTime: true,
        dateFormat: "Y-m-d H:i",
        time_24hr: false,
        minDate: "today",
        defaultDate: defaultDueDate
    });

    updateCodeSyntaxPreview();
    updateMathPreview();
});

function uploadSummernoteImage(file) {
    const data = new FormData();
    data.append('image', file);
    data.append('csrf_token', '<?= generate_csrf_token() ?>');

    $.ajax({
        url: 'upload_image.php',
        method: 'POST',
        data: data,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success && response.url) {
                $('#homeworkDescription').summernote('insertImage', response.url);
            } else {
                alert(response.error || 'Failed to upload image.');
            }
        },
        error: function(err) {
            alert('Image upload error: ' + err.statusText);
        }
    });
}

function insertLatex(formula) {
    const code = '$' + formula + '$';
    $('#homeworkDescription').summernote('insertText', code);
    updateMathPreview();
}

function updateMathPreview() {
    const content = $('#homeworkDescription').val() || $('#homeworkDescription').summernote('code');
    const previewBox = document.getElementById('mathPreviewBox');
    if (!content || content === '<p><br></p>') {
        previewBox.innerHTML = '<span class="text-muted small italic">Mathematical equations enclosed in $...$ will render here live.</span>';
        return;
    }
    previewBox.innerHTML = content;
    if (window.MathJax && window.MathJax.typesetPromise) {
        MathJax.typesetPromise([previewBox]).catch(function(err) {});
    }
}

function previewSelectedAttachment(input) {
    const previewDiv = document.getElementById('attachmentPreview');
    const previewImg = document.getElementById('attachmentPreviewImg');
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
    } else {
        label.textContent = 'Choose image or PDF file...';
        previewDiv.style.display = 'none';
    }
}

function setTargetType(type) {
    const batchGroup = document.getElementById('targetBatchGroup');
    const classGroup = document.getElementById('targetClassGroup');

    if (type === 'batch') {
        batchGroup.style.display = 'block';
        classGroup.style.display = 'none';
    } else if (type === 'class') {
        batchGroup.style.display = 'none';
        classGroup.style.display = 'block';
    } else {
        batchGroup.style.display = 'none';
        classGroup.style.display = 'none';
    }
}

function toggleProgrammingBox(enabled) {
    const box = document.getElementById('programmingBox');
    box.style.display = enabled ? 'block' : 'none';
    if (enabled) {
        updateCodeSyntaxPreview();
    }
}

function updateCodeSyntaxPreview() {
    const lang = document.getElementById('programmingLangSelect').value;
    const code = document.getElementById('starterCodeTextarea').value;
    const previewCodeEl = document.getElementById('codeSyntaxPreview');

    previewCodeEl.className = 'language-' + lang;
    previewCodeEl.textContent = code || '# Write starter code above to see syntax highlighted preview...';

    if (window.Prism) {
        Prism.highlightElement(previewCodeEl);
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

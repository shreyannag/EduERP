<?php
// admin/coding/problem_edit.php
// Edit Existing Coding Problem

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$problem_id = (int)($_GET['id'] ?? 0);
if (!$problem_id) {
    header('Location: ' . BASE_URL . '/admin/coding/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM coding_problems WHERE id = ?");
$stmt->execute([$problem_id]);
$problem = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$problem) {
    echo "<div class='container my-5 alert alert-danger'>Problem not found. <a href='index.php'>Return to Dashboard</a></div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin() && (int)$problem['admin_id'] !== $effective_admin_id) {
    echo "<div class='container my-5 alert alert-danger'>Access Denied: You do not own this problem.</div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

// Fetch test cases
$stmtTC = $pdo->prepare("SELECT * FROM coding_test_cases WHERE problem_id = ? ORDER BY is_sample DESC, id ASC");
$stmtTC->execute([$problem_id]);
$test_cases = $stmtTC->fetchAll(PDO::FETCH_ASSOC);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $difficulty = in_array($_POST['difficulty'] ?? '', ['Easy', 'Medium', 'Hard']) ? $_POST['difficulty'] : 'Easy';
    $total_points = max(1, (int)($_POST['total_points'] ?? 100));
    $time_limit_ms = max(500, min(10000, (int)($_POST['time_limit_ms'] ?? 2000)));
    $memory_limit_mb = max(32, min(1024, (int)($_POST['memory_limit_mb'] ?? 256)));
    $description = trim($_POST['description'] ?? '');
    $input_format = trim($_POST['input_format'] ?? '');
    $output_format = trim($_POST['output_format'] ?? '');
    $constraints = trim($_POST['constraints'] ?? '');
    $sample_input = trim($_POST['sample_input'] ?? '');
    $sample_output = trim($_POST['sample_output'] ?? '');
    $sample_explanation = trim($_POST['sample_explanation'] ?? '');
    
    $allowed_langs = isset($_POST['allowed_languages']) && is_array($_POST['allowed_languages'])
        ? implode(',', array_intersect($_POST['allowed_languages'], ['python', 'java', 'c', 'cpp']))
        : 'python,java,c,cpp';

    $starter_python = $_POST['starter_code_python'] ?? '';
    $starter_java   = $_POST['starter_code_java'] ?? '';
    $starter_c      = $_POST['starter_code_c'] ?? '';
    $starter_cpp    = $_POST['starter_code_cpp'] ?? '';

    if (empty($slug)) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
    }

    if (empty($title)) {
        $error = 'Problem title is required.';
    } elseif (empty($description)) {
        $error = 'Problem description cannot be empty.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("
                UPDATE coding_problems SET
                    title = ?, slug = ?, difficulty = ?, description = ?,
                    input_format = ?, output_format = ?, constraints = ?, 
                    sample_input = ?, sample_output = ?, sample_explanation = ?,
                    time_limit_ms = ?, memory_limit_mb = ?, allowed_languages = ?,
                    starter_code_python = ?, starter_code_java = ?, starter_code_c = ?, starter_code_cpp = ?,
                    total_points = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([
                $title, $slug, $difficulty, $description,
                $input_format, $output_format, $constraints,
                $sample_input, $sample_output, $sample_explanation,
                $time_limit_ms, $memory_limit_mb, $allowed_langs,
                $starter_python, $starter_java, $starter_c, $starter_cpp,
                $total_points, $problem_id
            ]);

            // Replace Test Cases
            $pdo->prepare("DELETE FROM coding_test_cases WHERE problem_id = ?")->execute([$problem_id]);

            $tc_inputs   = $_POST['tc_input'] ?? [];
            $tc_outputs  = $_POST['tc_output'] ?? [];
            $tc_samples  = $_POST['tc_sample'] ?? [];
            $tc_points   = $_POST['tc_points'] ?? [];

            $insertTC = $pdo->prepare("
                INSERT INTO coding_test_cases (problem_id, input_data, expected_output, is_sample, is_hidden, points)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $has_test_cases = false;
            foreach ($tc_outputs as $idx => $out) {
                $out = trim($out);
                if ($out === '') continue;
                $in = $tc_inputs[$idx] ?? '';
                $is_samp = isset($tc_samples[$idx]) ? 1 : 0;
                $is_hid = $is_samp ? 0 : 1;
                $pts = max(1, (int)($tc_points[$idx] ?? 10));

                $insertTC->execute([$problem_id, $in, $out, $is_samp, $is_hid, $pts]);
                $has_test_cases = true;
            }

            if (!$has_test_cases && !empty($sample_output)) {
                $insertTC->execute([$problem_id, $sample_input, $sample_output, 1, 0, 100]);
            }

            $pdo->commit();
            $success = 'Problem details and test cases updated successfully!';
            
            // Reload problem and test cases
            $stmt->execute([$problem_id]);
            $problem = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmtTC->execute([$problem_id]);
            $test_cases = $stmtTC->fetchAll(PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to update problem: ' . $e->getMessage();
        }
    }
}

$current_langs = explode(',', $problem['allowed_languages'] ?? 'python,java,c,cpp');
?>

<!-- Summernote & CodeMirror CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2 class="h3 font-weight-bold text-gray-800 mb-1">
            <i class="fas fa-edit text-primary mr-2"></i>Edit Problem: <?php echo h($problem['title']); ?>
        </h2>
        <p class="text-muted mb-0">Modify statement, code templates, or test cases.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success shadow-sm alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle mr-2"></i> <?php echo h($success); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo h($error); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<form method="POST" id="problemEditForm">
    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

    <div class="row">
        <!-- Left: Basic Info & Problem Statement -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-file-alt mr-2"></i>Problem Details</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8 form-group">
                            <label class="font-weight-bold">Problem Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" required value="<?php echo h($problem['title']); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">URL Slug</label>
                            <input type="text" name="slug" class="form-control" value="<?php echo h($problem['slug']); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Difficulty <span class="text-danger">*</span></label>
                            <select name="difficulty" class="form-control font-weight-bold">
                                <option value="Easy" class="text-success" <?php echo $problem['difficulty'] === 'Easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="Medium" class="text-warning" <?php echo $problem['difficulty'] === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="Hard" class="text-danger" <?php echo $problem['difficulty'] === 'Hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Total Points <span class="text-danger">*</span></label>
                            <input type="number" name="total_points" class="form-control" min="1" value="<?php echo (int)$problem['total_points']; ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Time Limit (ms)</label>
                            <input type="number" name="time_limit_ms" class="form-control" min="500" max="10000" step="500" value="<?php echo (int)$problem['time_limit_ms']; ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Problem Description <span class="text-danger">*</span></label>
                        <textarea name="description" id="summernote" class="form-control"><?php echo h($problem['description']); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Input Format</label>
                            <textarea name="input_format" rows="3" class="form-control"><?php echo h($problem['input_format'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Output Format</label>
                            <textarea name="output_format" rows="3" class="form-control"><?php echo h($problem['output_format'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Constraints</label>
                        <input type="text" name="constraints" class="form-control" value="<?php echo h($problem['constraints'] ?? ''); ?>">
                    </div>

                    <hr>
                    <h6 class="font-weight-bold text-dark mb-3"><i class="fas fa-lightbulb text-warning mr-1"></i> Sample Test Case & Explanation</h6>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Sample Input</label>
                            <textarea name="sample_input" rows="4" class="form-control font-family-monospace"><?php echo h($problem['sample_input'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Sample Output</label>
                            <textarea name="sample_output" rows="4" class="form-control font-family-monospace"><?php echo h($problem['sample_output'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Sample Explanation</label>
                        <textarea name="sample_explanation" rows="2" class="form-control"><?php echo h($problem['sample_explanation'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Starter Code Templates -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-code mr-2"></i>Starter Code Templates</h6>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs" id="starterTab" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold" data-toggle="tab" href="#starter-python">
                                <i class="fab fa-python text-info mr-1"></i> Python 3
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" data-toggle="tab" href="#starter-java">
                                <i class="fab fa-java text-danger mr-1"></i> Java 17
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" data-toggle="tab" href="#starter-cpp">
                                <i class="fas fa-code text-primary mr-1"></i> C++ (C++17)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold" data-toggle="tab" href="#starter-c">
                                <i class="fas fa-c mr-1"></i> C (C11)
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content pt-3">
                        <div class="tab-pane fade show active" id="starter-python">
                            <textarea name="starter_code_python" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($problem['starter_code_python'] ?? ''); ?></textarea>
                        </div>
                        <div class="tab-pane fade" id="starter-java">
                            <textarea name="starter_code_java" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($problem['starter_code_java'] ?? ''); ?></textarea>
                        </div>
                        <div class="tab-pane fade" id="starter-cpp">
                            <textarea name="starter_code_cpp" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($problem['starter_code_cpp'] ?? ''); ?></textarea>
                        </div>
                        <div class="tab-pane fade" id="starter-c">
                            <textarea name="starter_code_c" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($problem['starter_code_c'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Test Cases & Configuration -->
        <div class="col-lg-4">
            <!-- Allowed Compilers -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-cogs mr-2"></i>Allowed Compilers</h6>
                </div>
                <div class="card-body">
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" name="allowed_languages[]" value="python" class="custom-control-input" id="langPython" <?php echo in_array('python', $current_langs) ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="langPython"><i class="fab fa-python text-info mr-1"></i> Python 3.12+</label>
                    </div>
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" name="allowed_languages[]" value="java" class="custom-control-input" id="langJava" <?php echo in_array('java', $current_langs) ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="langJava"><i class="fab fa-java text-danger mr-1"></i> Java (OpenJDK 17)</label>
                    </div>
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" name="allowed_languages[]" value="cpp" class="custom-control-input" id="langCpp" <?php echo in_array('cpp', $current_langs) ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="langCpp"><i class="fas fa-code text-primary mr-1"></i> C++ (GCC C++17)</label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" name="allowed_languages[]" value="c" class="custom-control-input" id="langC" <?php echo in_array('c', $current_langs) ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold" for="langC"><i class="fas fa-c mr-1"></i> C (GCC C11)</label>
                    </div>
                </div>
            </div>

            <!-- Test Cases Manager -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-check-circle mr-2"></i>Test Cases</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold" id="btnAddTestCase">
                        <i class="fas fa-plus mr-1"></i> Add Case
                    </button>
                </div>
                <div class="card-body p-2" id="testCasesContainer" style="max-height: 520px; overflow-y: auto;">
                    <?php if (empty($test_cases)): ?>
                        <div class="test-case-item border rounded p-3 mb-2 bg-light position-relative">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong class="text-primary case-title">Test Case #1</strong>
                                <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-case" title="Remove">&times;</button>
                            </div>
                            <div class="form-group mb-2">
                                <label class="small font-weight-bold mb-1">Standard Input (STDIN)</label>
                                <textarea name="tc_input[]" rows="2" class="form-control form-control-sm font-family-monospace"><?php echo h($problem['sample_input'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group mb-2">
                                <label class="small font-weight-bold mb-1">Expected Output (STDOUT) <span class="text-danger">*</span></label>
                                <textarea name="tc_output[]" rows="2" class="form-control form-control-sm font-family-monospace" required><?php echo h($problem['sample_output'] ?? ''); ?></textarea>
                            </div>
                            <div class="row align-items-center">
                                <div class="col-6">
                                    <label class="small font-weight-bold mb-1">Points</label>
                                    <input type="number" name="tc_points[]" class="form-control form-control-sm" value="25" min="1">
                                </div>
                                <div class="col-6 pt-3">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" name="tc_sample[0]" value="1" class="custom-control-input sample-check" id="sampleCheck0" checked>
                                        <label class="custom-control-label small font-weight-bold" for="sampleCheck0">Sample?</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($test_cases as $idx => $tc): ?>
                            <div class="test-case-item border rounded p-3 mb-2 bg-light position-relative">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="text-primary case-title">Test Case #<?php echo $idx + 1; ?> <?php echo (int)$tc['is_sample'] ? '<span class="badge badge-info ml-1">Sample</span>' : '<span class="badge badge-secondary ml-1">Hidden</span>'; ?></strong>
                                    <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-case" title="Remove">&times;</button>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="small font-weight-bold mb-1">Standard Input (STDIN)</label>
                                    <textarea name="tc_input[]" rows="2" class="form-control form-control-sm font-family-monospace"><?php echo h($tc['input_data'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="small font-weight-bold mb-1">Expected Output (STDOUT) <span class="text-danger">*</span></label>
                                    <textarea name="tc_output[]" rows="2" class="form-control form-control-sm font-family-monospace" required><?php echo h($tc['expected_output']); ?></textarea>
                                </div>
                                <div class="row align-items-center">
                                    <div class="col-6">
                                        <label class="small font-weight-bold mb-1">Points</label>
                                        <input type="number" name="tc_points[]" class="form-control form-control-sm" value="<?php echo (int)$tc['points']; ?>" min="1">
                                    </div>
                                    <div class="col-6 pt-3">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" name="tc_sample[<?php echo $idx; ?>]" value="1" class="custom-control-input sample-check" id="sampleCheck<?php echo $idx; ?>" <?php echo (int)$tc['is_sample'] ? 'checked' : ''; ?>>
                                            <label class="custom-control-label small font-weight-bold" for="sampleCheck<?php echo $idx; ?>">Sample?</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2 shadow-sm">
                        <i class="fas fa-save mr-1"></i> Update Problem
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
<script>
$(document).ready(function() {
    $('#summernote').summernote({
        height: 220
    });

    var tcCount = $('.test-case-item').length;
    $('#btnAddTestCase').on('click', function() {
        tcCount++;
        var html = `
            <div class="test-case-item border rounded p-3 mb-2 bg-light position-relative">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong class="text-primary case-title">Test Case #${tcCount}</strong>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove-case" title="Remove">&times;</button>
                </div>
                <div class="form-group mb-2">
                    <label class="small font-weight-bold mb-1">Standard Input (STDIN)</label>
                    <textarea name="tc_input[]" rows="2" class="form-control form-control-sm font-family-monospace" placeholder="Input values..."></textarea>
                </div>
                <div class="form-group mb-2">
                    <label class="small font-weight-bold mb-1">Expected Output (STDOUT) <span class="text-danger">*</span></label>
                    <textarea name="tc_output[]" rows="2" class="form-control form-control-sm font-family-monospace" required placeholder="Expected output..."></textarea>
                </div>
                <div class="row align-items-center">
                    <div class="col-6">
                        <label class="small font-weight-bold mb-1">Points</label>
                        <input type="number" name="tc_points[]" class="form-control form-control-sm" value="25" min="1">
                    </div>
                    <div class="col-6 pt-3">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" name="tc_sample[${tcCount - 1}]" value="1" class="custom-control-input sample-check" id="sampleCheck${tcCount}">
                            <label class="custom-control-label small font-weight-bold" for="sampleCheck${tcCount}">Sample?</label>
                        </div>
                    </div>
                </div>
            </div>
        `;
        $('#testCasesContainer').append(html);
    });

    $(document).on('click', '.btn-remove-case', function() {
        if ($('.test-case-item').length > 1) {
            $(this).closest('.test-case-item').remove();
            $('.test-case-item').each(function(i) {
                $(this).find('.case-title').text('Test Case #' + (i + 1));
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

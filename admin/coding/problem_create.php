<?php
// admin/coding/problem_create.php
// Create New Coding Problem

require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('coding');
require_once __DIR__ . '/../../includes/coding_engine.php';

$error = '';
$success = '';

$effective_admin_id = get_effective_admin_id();
$author_id = (int)$_SESSION['admin_id'];

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

    // If slug is empty, make slug from title
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

            $stmt = $pdo->prepare("
                INSERT INTO coding_problems (
                    admin_id, created_by, title, slug, difficulty, description,
                    input_format, output_format, constraints, sample_input, sample_output, sample_explanation,
                    time_limit_ms, memory_limit_mb, allowed_languages,
                    starter_code_python, starter_code_java, starter_code_c, starter_code_cpp,
                    total_points, is_active
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, 1
                )
            ");
            $stmt->execute([
                $effective_admin_id, $author_id, $title, $slug, $difficulty, $description,
                $input_format, $output_format, $constraints, $sample_input, $sample_output, $sample_explanation,
                $time_limit_ms, $memory_limit_mb, $allowed_langs,
                $starter_python, $starter_java, $starter_c, $starter_cpp,
                $total_points
            ]);
            $problem_id = (int)$pdo->lastInsertId();

            // Insert Test Cases
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
                if ($out === '') continue; // Skip empty output
                $in = $tc_inputs[$idx] ?? '';
                $is_samp = isset($tc_samples[$idx]) ? 1 : 0;
                $is_hid = $is_samp ? 0 : 1;
                $pts = max(1, (int)($tc_points[$idx] ?? 10));

                $insertTC->execute([$problem_id, $in, $out, $is_samp, $is_hid, $pts]);
                $has_test_cases = true;
            }

            // If no test cases were supplied in the table, auto-seed the sample test case if provided
            if (!$has_test_cases && !empty($sample_output)) {
                $insertTC->execute([$problem_id, $sample_input, $sample_output, 1, 0, 100]);
            }

            $pdo->commit();
            header('Location: ' . BASE_URL . '/admin/coding/index.php?msg=problem_created');
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to create problem: ' . $e->getMessage();
        }
    }
}
?>

<!-- Summernote & CodeMirror CSS -->
<link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
<!-- CodeMirror for Code Highlighting -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.13/theme/dracula.min.css">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <h2 class="h3 font-weight-bold text-gray-800 mb-1">
            <i class="fas fa-plus-circle text-primary mr-2"></i>Create New Coding Problem
        </h2>
        <p class="text-muted mb-0">Define problem statement, starter templates, and test cases for automated evaluation.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo h($error); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<form method="POST" id="problemForm">
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
                            <input type="text" name="title" id="probTitle" class="form-control" required placeholder="e.g. Find Max in Array" value="<?php echo h($_POST['title'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">URL Slug</label>
                            <input type="text" name="slug" id="probSlug" class="form-control" placeholder="find-max-in-array" value="<?php echo h($_POST['slug'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Difficulty <span class="text-danger">*</span></label>
                            <select name="difficulty" class="form-control font-weight-bold">
                                <option value="Easy" class="text-success" <?php echo ($_POST['difficulty'] ?? '') === 'Easy' ? 'selected' : ''; ?>>Easy</option>
                                <option value="Medium" class="text-warning" <?php echo ($_POST['difficulty'] ?? '') === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="Hard" class="text-danger" <?php echo ($_POST['difficulty'] ?? '') === 'Hard' ? 'selected' : ''; ?>>Hard</option>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Total Points <span class="text-danger">*</span></label>
                            <input type="number" name="total_points" class="form-control" min="1" value="<?php echo (int)($_POST['total_points'] ?? 100); ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label class="font-weight-bold">Time Limit (ms)</label>
                            <input type="number" name="time_limit_ms" class="form-control" min="500" max="10000" step="500" value="<?php echo (int)($_POST['time_limit_ms'] ?? 2000); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Problem Description <span class="text-danger">*</span></label>
                        <textarea name="description" id="summernote" class="form-control"><?php echo h($_POST['description'] ?? ''); ?></textarea>
                        <small class="text-muted">LaTeX equations like $x^2 + y^2 = r^2$ are supported!</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Input Format</label>
                            <textarea name="input_format" rows="3" class="form-control" placeholder="Describe the structure and order of standard input..."><?php echo h($_POST['input_format'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Output Format</label>
                            <textarea name="output_format" rows="3" class="form-control" placeholder="Describe what should be printed to standard output..."><?php echo h($_POST['output_format'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="font-weight-bold">Constraints</label>
                        <input type="text" name="constraints" class="form-control" placeholder="e.g. 1 <= N <= 10^5, -10^9 <= A[i] <= 10^9" value="<?php echo h($_POST['constraints'] ?? ''); ?>">
                    </div>

                    <hr>
                    <h6 class="font-weight-bold text-dark mb-3"><i class="fas fa-lightbulb text-warning mr-1"></i> Sample Test Case & Explanation</h6>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Sample Input</label>
                            <textarea name="sample_input" rows="4" class="form-control font-family-monospace" placeholder="e.g. 5&#10;1 2 3 4 5"><?php echo h($_POST['sample_input'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold">Sample Output</label>
                            <textarea name="sample_output" rows="4" class="form-control font-family-monospace" placeholder="e.g. 15"><?php echo h($_POST['sample_output'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Sample Explanation</label>
                        <textarea name="sample_explanation" rows="2" class="form-control" placeholder="Explain why the sample input yields this output..."><?php echo h($_POST['sample_explanation'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Starter Code Templates -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-code mr-2"></i>Starter Code Templates</h6>
                    <small class="text-muted">Pre-filled boilerplates shown to students when they open the editor.</small>
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
                            <textarea name="starter_code_python" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($_POST['starter_code_python'] ?? "import sys\n\ndef main():\n    # Read input from standard input\n    pass\n\nif __name__ == '__main__':\n    main()\n"); ?></textarea>
                        </div>
                        <div class="tab-pane fade" id="starter-java">
                            <textarea name="starter_code_java" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($_POST['starter_code_java'] ?? "import java.util.Scanner;\n\npublic class Solution {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        // Write code here\n    }\n}\n"); ?></textarea>
                        </div>
                        <div class="tab-pane fade" id="starter-cpp">
                            <textarea name="starter_code_cpp" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($_POST['starter_code_cpp'] ?? "#include <iostream>\n\nusing namespace std;\n\nint main() {\n    // Write code here\n    return 0;\n}\n"); ?></textarea>
                        </div>
                        <div class="tab-pane fade" id="starter-c">
                            <textarea name="starter_code_c" rows="8" class="form-control font-family-monospace bg-light"><?php echo h($_POST['starter_code_c'] ?? "#include <stdio.h>\n\nint main() {\n    // Write code here\n    return 0;\n}\n"); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Test Cases & Configuration -->
        <div class="col-lg-4">
            <!-- Allowed Languages -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-cogs mr-2"></i>Allowed Compilers</h6>
                </div>
                <div class="card-body">
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" name="allowed_languages[]" value="python" class="custom-control-input" id="langPython" checked>
                        <label class="custom-control-label font-weight-bold" for="langPython">
                            <i class="fab fa-python text-info mr-1"></i> Python 3.12+
                        </label>
                    </div>
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" name="allowed_languages[]" value="java" class="custom-control-input" id="langJava" checked>
                        <label class="custom-control-label font-weight-bold" for="langJava">
                            <i class="fab fa-java text-danger mr-1"></i> Java (OpenJDK 17)
                        </label>
                    </div>
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" name="allowed_languages[]" value="cpp" class="custom-control-input" id="langCpp" checked>
                        <label class="custom-control-label font-weight-bold" for="langCpp">
                            <i class="fas fa-code text-primary mr-1"></i> C++ (GCC C++17)
                        </label>
                    </div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" name="allowed_languages[]" value="c" class="custom-control-input" id="langC" checked>
                        <label class="custom-control-label font-weight-bold" for="langC">
                            <i class="fas fa-c mr-1"></i> C (GCC C11)
                        </label>
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
                    <div class="test-case-item border rounded p-3 mb-2 bg-light position-relative">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong class="text-primary case-title">Test Case #1</strong>
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
                                    <input type="checkbox" name="tc_sample[0]" value="1" class="custom-control-input sample-check" id="sampleCheck0">
                                    <label class="custom-control-label small font-weight-bold" for="sampleCheck0">Sample?</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2 shadow-sm">
                        <i class="fas fa-save mr-1"></i> Save Problem
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
        height: 220,
        placeholder: 'Write the complete problem statement here...'
    });

    // Auto slug from title
    $('#probTitle').on('keyup change', function() {
        if ($('#probSlug').val() === '' || $('#probSlug').data('autofill') !== false) {
            var val = $(this).val().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            $('#probSlug').val(val);
        }
    });
    $('#probSlug').on('input', function() {
        $(this).data('autofill', false);
    });

    var tcCount = 1;
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
            // Re-index titles
            $('.test-case-item').each(function(i) {
                $(this).find('.case-title').text('Test Case #' + (i + 1));
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

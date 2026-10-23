<?php
// student/coding/solve.php
// Dual-Pane Interactive Coding IDE

require_once __DIR__ . '/../../includes/student_header.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

$student_id = (int)$_SESSION['student_id'];
$student_admin_id = !empty($current_student['admin_id']) ? (int)$current_student['admin_id'] : 1;
$is_platform_enabled = is_coding_platform_enabled($pdo, $student_admin_id);

if (!$is_platform_enabled) {
    echo "<div class='container my-5 alert alert-warning text-center'>Online Coding Platform is currently disabled by administration. <a href='" . BASE_URL . "/student/dashboard.php'>Return to Dashboard</a></div>";
    require_once __DIR__ . '/../../includes/student_footer.php';
    exit;
}

$problem_id = (int)($_GET['id'] ?? 0);
$competition_id = !empty($_GET['competition_id']) ? (int)$_GET['competition_id'] : null;

if (!$problem_id) {
    header('Location: ' . BASE_URL . '/student/coding/index.php');
    exit;
}

$stmtP = $pdo->prepare("SELECT * FROM coding_problems WHERE id = ? AND is_active = 1");
$stmtP->execute([$problem_id]);
$problem = $stmtP->fetch(PDO::FETCH_ASSOC);

if (!$problem) {
    echo "<div class='container my-5 alert alert-danger text-center'>Problem not found or inactive. <a href='index.php'>Back to Problem Bank</a></div>";
    require_once __DIR__ . '/../../includes/student_footer.php';
    exit;
}

// Fetch Sample Test Cases
$stmtTC = $pdo->prepare("SELECT * FROM coding_test_cases WHERE problem_id = ? AND is_sample = 1 ORDER BY id ASC");
$stmtTC->execute([$problem_id]);
$sample_test_cases = $stmtTC->fetchAll(PDO::FETCH_ASSOC);
if (empty($sample_test_cases) && !empty($problem['sample_output'])) {
    $sample_test_cases[] = [
        'id' => 0,
        'input_data' => $problem['sample_input'],
        'expected_output' => $problem['sample_output']
    ];
}

// Competition context if active
$competition = null;
if ($competition_id) {
    $stmtC = $pdo->prepare("SELECT * FROM coding_competitions WHERE id = ? AND is_active = 1");
    $stmtC->execute([$competition_id]);
    $competition = $stmtC->fetch(PDO::FETCH_ASSOC);
}

// Fetch Student's Previous Submissions for this problem
$stmtMySubs = $pdo->prepare("
    SELECT * FROM coding_submissions 
    WHERE problem_id = ? AND student_id = ? 
    ORDER BY submitted_at DESC 
    LIMIT 20
");
$stmtMySubs->execute([$problem_id, $student_id]);
$my_submissions = $stmtMySubs->fetchAll(PDO::FETCH_ASSOC);

// Allowed languages
$allowed_langs_raw = explode(',', $problem['allowed_languages'] ?? 'python,java,c,cpp');
$all_langs = get_supported_coding_languages();
$supported_langs = [];
foreach ($allowed_langs_raw as $l) {
    $l = trim($l);
    if (isset($all_langs[$l])) {
        $supported_langs[$l] = $all_langs[$l];
    }
}
if (empty($supported_langs)) {
    $supported_langs = $all_langs;
}

// Starter codes
$starter_codes = [
    'python' => !empty($problem['starter_code_python']) ? $problem['starter_code_python'] : get_starter_code_for_lang('python'),
    'java'   => !empty($problem['starter_code_java']) ? $problem['starter_code_java'] : get_starter_code_for_lang('java'),
    'cpp'    => !empty($problem['starter_code_cpp']) ? $problem['starter_code_cpp'] : get_starter_code_for_lang('cpp'),
    'c'      => !empty($problem['starter_code_c']) ? $problem['starter_code_c'] : get_starter_code_for_lang('c'),
];

$default_lang = isset($supported_langs['python']) ? 'python' : array_key_first($supported_langs);
?>

<style>
/* Dual Pane IDE Layout */
.ide-wrapper {
    min-height: calc(100vh - 140px);
    display: flex;
    flex-direction: column;
}
.pane-left {
    height: 750px;
    overflow-y: auto;
    border-right: 1px solid #e5e7eb;
    background: #ffffff;
}
.pane-right {
    height: 750px;
    display: flex;
    flex-direction: column;
    background: #1e1e1e;
}
#aceEditor {
    flex-grow: 1;
    width: 100%;
    font-size: 14px;
    line-height: 1.5;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}
.console-panel {
    height: 240px;
    overflow-y: auto;
    background: #18181b;
    color: #e4e4e7;
    border-top: 1px solid #27272a;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
}
.console-output-pre {
    color: #a1a1aa;
    font-size: 13px;
    margin: 0;
    white-space: pre-wrap;
    word-break: break-all;
}
.nav-tabs-dark {
    border-bottom: 1px solid #27272a;
    background: #121214;
}
.nav-tabs-dark .nav-link {
    color: #a1a1aa;
    border: none;
    border-bottom: 2px solid transparent;
    font-weight: 600;
    font-size: 0.85rem;
    padding: 0.5rem 1rem;
}
.nav-tabs-dark .nav-link.active {
    color: #ffffff;
    background: transparent;
    border-bottom: 2px solid #3b82f6;
}
.verdict-badge-lg {
    font-size: 1.1rem;
    padding: 0.35rem 0.85rem;
    font-weight: 700;
    border-radius: 6px;
}
</style>

<!-- Ace Editor CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ext-language_tools.js"></script>

<div class="container-fluid px-lg-4 mb-4">
    <!-- Top Contest Alert Bar (If in Competition) -->
    <?php if ($competition): ?>
        <div class="alert alert-info shadow-sm d-flex justify-content-between align-items-center py-2 px-3 mb-3 flex-wrap">
            <div class="d-flex align-items-center mr-3">
                <i class="fas fa-trophy text-warning fa-lg mr-2"></i>
                <div>
                    <strong>Competition: <?php echo h($competition['title']); ?></strong>
                    <span class="badge badge-light border text-primary ml-2"><?php echo (int)$problem['total_points']; ?> Points</span>
                </div>
            </div>
            <div class="d-flex align-items-center mt-2 mt-sm-0">
                <a href="<?php echo BASE_URL; ?>/student/coding/competition.php?id=<?php echo $competition['id']; ?>" class="btn btn-sm btn-outline-primary mr-2 font-weight-bold">
                    <i class="fas fa-th-list mr-1"></i> Contest Problem Set
                </a>
                <a href="<?php echo BASE_URL; ?>/student/coding/leaderboard.php?competition_id=<?php echo $competition['id']; ?>" target="_blank" class="btn btn-sm btn-outline-success font-weight-bold">
                    <i class="fas fa-chart-line mr-1"></i> Live Standings
                </a>
            </div>
        </div>
    <?php endif; ?>

    <div class="row no-gutters shadow-sm rounded-lg overflow-hidden border">
        <!-- LEFT PANE: Problem Statement & Past Submissions -->
        <div class="col-lg-6 pane-left p-4">
            <!-- Problem Header -->
            <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap">
                <div>
                    <a href="<?php echo $competition ? BASE_URL . '/student/coding/competition.php?id=' . $competition['id'] : BASE_URL . '/student/coding/index.php'; ?>" class="text-muted small font-weight-bold mb-1 d-inline-block">
                        <i class="fas fa-arrow-left mr-1"></i> <?php echo $competition ? 'Back to Competition' : 'Back to Problem Bank'; ?>
                    </a>
                    <h3 class="font-weight-bold text-dark mb-1"><?php echo h($problem['title']); ?></h3>
                    <div class="d-flex align-items-center gap-2">
                        <?php
                            $diffClass = 'badge-success';
                            if ($problem['difficulty'] === 'Medium') $diffClass = 'badge-warning text-dark';
                            if ($problem['difficulty'] === 'Hard') $diffClass = 'badge-danger';
                        ?>
                        <span class="badge <?php echo $diffClass; ?> px-2 py-1 font-weight-bold mr-2"><?php echo h($problem['difficulty']); ?></span>
                        <span class="badge badge-primary px-2 py-1 font-weight-bold mr-2"><?php echo (int)$problem['total_points']; ?> Points</span>
                        <small class="text-muted mr-3"><i class="fas fa-stopwatch mr-1"></i><?php echo (int)$problem['time_limit_ms']; ?> ms</small>
                        <small class="text-muted"><i class="fas fa-memory mr-1"></i><?php echo (int)$problem['memory_limit_mb']; ?> MB</small>
                    </div>
                </div>
            </div>

            <!-- Left Navigation Tabs -->
            <ul class="nav nav-tabs mb-3" id="problemTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active font-weight-bold" id="desc-tab" data-toggle="tab" href="#tab-desc" role="tab">
                        <i class="fas fa-file-alt mr-1"></i> Description
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link font-weight-bold" id="submissions-tab" data-toggle="tab" href="#tab-submissions" role="tab">
                        <i class="fas fa-history mr-1"></i> My Submissions (<?php echo count($my_submissions); ?>)
                    </a>
                </li>
            </ul>

            <div class="tab-content" id="problemTabsContent">
                <!-- Tab: Description -->
                <div class="tab-pane fade show active" id="tab-desc" role="tabpanel">
                    <!-- Problem Body -->
                    <div class="problem-content mb-4 text-dark" style="font-size: 1.02rem; line-height: 1.7;">
                        <?php echo $problem['description']; ?>
                    </div>

                    <?php if (!empty($problem['input_format'])): ?>
                        <div class="mb-3">
                            <h6 class="font-weight-bold text-dark mb-1"><i class="fas fa-sign-in-alt text-primary mr-1"></i> Input Format</h6>
                            <p class="text-muted mb-0 small"><?php echo nl2br(h($problem['input_format'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($problem['output_format'])): ?>
                        <div class="mb-3">
                            <h6 class="font-weight-bold text-dark mb-1"><i class="fas fa-sign-out-alt text-success mr-1"></i> Output Format</h6>
                            <p class="text-muted mb-0 small"><?php echo nl2br(h($problem['output_format'])); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($problem['constraints'])): ?>
                        <div class="mb-4">
                            <h6 class="font-weight-bold text-dark mb-1"><i class="fas fa-shield-alt text-warning mr-1"></i> Constraints</h6>
                            <code class="p-2 bg-light border rounded d-block text-dark small"><?php echo h($problem['constraints']); ?></code>
                        </div>
                    <?php endif; ?>

                    <!-- Sample Test Cases -->
                    <?php if (!empty($sample_test_cases)): ?>
                        <h6 class="font-weight-bold text-dark mb-3"><i class="fas fa-vial text-info mr-1"></i> Examples</h6>
                        <?php foreach ($sample_test_cases as $idx => $stc): ?>
                            <div class="card bg-light border mb-3">
                                <div class="card-header bg-white py-2 font-weight-bold text-muted small d-flex justify-content-between align-items-center">
                                    <span>Sample Test Case #<?php echo $idx + 1; ?></span>
                                    <button type="button" class="btn btn-sm btn-link text-primary p-0 copy-input-btn" data-text="<?php echo h($stc['input_data'] ?? ''); ?>">
                                        <i class="far fa-copy mr-1"></i> Copy Input
                                    </button>
                                </div>
                                <div class="card-body p-3">
                                    <div class="row">
                                        <div class="col-md-6 mb-2 mb-md-0">
                                            <strong class="small text-muted d-block mb-1">Input:</strong>
                                            <pre class="bg-white border rounded p-2 text-dark font-family-monospace mb-0 small" style="white-space: pre-wrap;"><?php echo h($stc['input_data'] ?? ''); ?></pre>
                                        </div>
                                        <div class="col-md-6">
                                            <strong class="small text-muted d-block mb-1">Expected Output:</strong>
                                            <pre class="bg-white border rounded p-2 text-dark font-family-monospace mb-0 small" style="white-space: pre-wrap;"><?php echo h($stc['expected_output']); ?></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($problem['sample_explanation'])): ?>
                        <div class="card bg-white border border-info mb-4">
                            <div class="card-body py-2 px-3 small text-muted">
                                <strong class="text-info"><i class="fas fa-lightbulb mr-1"></i> Explanation:</strong>
                                <?php echo nl2br(h($problem['sample_explanation'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab: My Past Submissions -->
                <div class="tab-pane fade" id="tab-submissions" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center small">
                            <thead class="thead-light">
                                <tr>
                                    <th>Verdict</th>
                                    <th>Language</th>
                                    <th>Score</th>
                                    <th>Time</th>
                                    <th>Date</th>
                                    <th>Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($my_submissions)): ?>
                                    <tr>
                                        <td colspan="6" class="py-4 text-muted">
                                            You haven't submitted a solution for this problem yet.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($my_submissions as $sub): ?>
                                        <?php
                                            $vBadge = 'badge-secondary';
                                            if ($sub['status'] === 'AC') $vBadge = 'badge-success';
                                            elseif ($sub['status'] === 'WA') $vBadge = 'badge-danger';
                                            elseif ($sub['status'] === 'TLE') $vBadge = 'badge-warning text-dark';
                                            elseif ($sub['status'] === 'CE') $vBadge = 'badge-dark';
                                            elseif ($sub['status'] === 'RE') $vBadge = 'badge-danger';
                                        ?>
                                        <tr>
                                            <td><span class="badge <?php echo $vBadge; ?> px-2 py-1 font-weight-bold"><?php echo h($sub['status']); ?></span></td>
                                            <td class="text-uppercase font-weight-bold"><?php echo h($sub['language']); ?></td>
                                            <td class="font-weight-bold text-primary"><?php echo (float)$sub['score']; ?> pts</td>
                                            <td><?php echo (int)$sub['execution_time_ms']; ?> ms</td>
                                            <td><?php echo date('M d, H:i', strtotime($sub['submitted_at'])); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-xs btn-outline-primary py-0 px-2 btn-load-past-code" data-code="<?php echo htmlspecialchars($sub['source_code'], ENT_QUOTES); ?>" data-lang="<?php echo h($sub['language']); ?>" title="Load Code into Editor">
                                                    Load
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANE: Code Editor & Execution Console -->
        <div class="col-lg-6 pane-right">
            <!-- Editor Controls Bar -->
            <div class="d-flex justify-content-between align-items-center p-2 bg-dark border-bottom border-secondary flex-wrap">
                <div class="d-flex align-items-center">
                    <label class="text-white-50 small mr-2 mb-0 font-weight-bold"><i class="fas fa-terminal mr-1"></i> Language:</label>
                    <select id="languageSelect" class="form-control form-control-sm bg-secondary text-white font-weight-bold border-0" style="width: 170px;">
                        <?php foreach ($supported_langs as $k => $langInfo): ?>
                            <option value="<?php echo $k; ?>" <?php echo $k === $default_lang ? 'selected' : ''; ?>>
                                <?php echo h($langInfo['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex align-items-center mt-1 mt-sm-0">
                    <select id="themeSelect" class="form-control form-control-sm bg-secondary text-white border-0 mr-2" style="width: 120px;">
                        <option value="ace/theme/dracula">Dracula</option>
                        <option value="ace/theme/monokai">Monokai</option>
                        <option value="ace/theme/tomorrow_night">Night</option>
                        <option value="ace/theme/chrome">Light</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-light py-1 px-2 mr-2" id="btnResetCode" title="Reset to Starter Template">
                        <i class="fas fa-undo"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-light py-1 px-2" id="btnToggleCustomInput" title="Toggle Custom STDIN Drawer">
                        <i class="fas fa-keyboard mr-1"></i> Custom Input
                    </button>
                </div>
            </div>

            <!-- Custom STDIN Drawer (Collapsible) -->
            <div id="customInputDrawer" class="p-2 bg-dark border-bottom border-secondary" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-white small font-weight-bold"><i class="fas fa-keyboard text-warning mr-1"></i> Standard Input (STDIN)</span>
                    <button type="button" class="btn btn-sm btn-link text-white-50 p-0" id="btnCloseCustomInput">&times;</button>
                </div>
                <textarea id="customInputArea" rows="3" class="form-control form-control-sm bg-black text-white font-family-monospace border-secondary" placeholder="Enter input values to feed into standard input..."></textarea>
            </div>

            <!-- The Ace Code Editor Instance -->
            <div id="aceEditor"></div>

            <!-- Bottom Console / Output Drawer -->
            <div class="console-panel">
                <ul class="nav nav-tabs nav-tabs-dark" id="consoleTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="tab-test-btn" data-toggle="tab" href="#console-test-pane" role="tab">
                            <i class="fas fa-terminal mr-1"></i> Test Results
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="tab-verdict-btn" data-toggle="tab" href="#console-verdict-pane" role="tab">
                            <i class="fas fa-check-double mr-1"></i> Official Verdict
                        </a>
                    </li>
                </ul>

                <div class="tab-content p-3" id="consoleTabContent">
                    <!-- Pane: Test Run Output -->
                    <div class="tab-pane fade show active" id="console-test-pane" role="tabpanel">
                        <div id="testOutputPlaceholder" class="text-muted small">
                            <i class="fas fa-play-circle mr-1 text-primary"></i> Click <strong>Run Code (Test)</strong> to execute against sample test cases or custom input.
                        </div>
                        <div id="testRunningSpinner" style="display: none;" class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                            <span class="text-light ml-2 font-weight-bold small">Compiling and running code...</span>
                        </div>
                        <div id="testResultsContainer" style="display: none;"></div>
                    </div>

                    <!-- Pane: Official Submission Verdict -->
                    <div class="tab-pane fade" id="console-verdict-pane" role="tabpanel">
                        <div id="verdictPlaceholder" class="text-muted small">
                            <i class="fas fa-paper-plane mr-1 text-success"></i> Click <strong>Submit Solution</strong> to test against all hidden cases and record your score.
                        </div>
                        <div id="verdictRunningSpinner" style="display: none;" class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-success" role="status"></div>
                            <span class="text-light ml-2 font-weight-bold small">Grading full test suite on judging server...</span>
                        </div>
                        <div id="verdictContainer" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Editor Action Buttons Footer -->
            <div class="d-flex justify-content-between align-items-center p-3 bg-dark border-top border-secondary">
                <div class="small text-white-50">
                    <i class="fas fa-shield-alt text-success mr-1"></i> Sandboxed Multi-Language Compiler
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-light font-weight-bold mr-2 px-3 shadow-sm" id="btnRunCode">
                        <i class="fas fa-play mr-1"></i> Run Code
                    </button>
                    <button type="button" class="btn btn-success font-weight-bold px-4 shadow-sm" id="btnSubmitCode">
                        <i class="fas fa-paper-plane mr-1"></i> Submit Solution
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    var csrfToken = '<?php echo generate_csrf_token(); ?>';
    var problemId = <?php echo $problem_id; ?>;
    var competitionId = <?php echo $competition_id ? $competition_id : 'null'; ?>;

    var starterCodes = <?php echo json_encode($starter_codes); ?>;
    var supportedLangs = <?php echo json_encode($supported_langs); ?>;

    // Initialize Ace Editor
    ace.require("ace/ext/language_tools");
    var editor = ace.edit("aceEditor");
    editor.setTheme("ace/theme/dracula");
    editor.setShowPrintMargin(false);
    editor.setOptions({
        enableBasicAutocompletion: true,
        enableLiveAutocompletion: true,
        enableSnippets: true,
        tabSize: 4,
        useSoftTabs: true
    });

    function getAceMode(lang) {
        if (lang === 'python') return 'ace/mode/python';
        if (lang === 'java') return 'ace/mode/java';
        if (lang === 'cpp' || lang === 'c') return 'ace/mode/c_cpp';
        return 'ace/mode/text';
    }

    var currentLang = $('#languageSelect').val();
    editor.session.setMode(getAceMode(currentLang));

    // Try restoring draft from localStorage or fallback to starter code
    var storageKey = 'shreyansir_coding_' + problemId + '_' + currentLang;
    var savedCode = localStorage.getItem(storageKey);
    if (savedCode && savedCode.trim() !== '') {
        editor.setValue(savedCode, -1);
    } else {
        editor.setValue(starterCodes[currentLang] || '', -1);
    }

    // Auto-save draft on edit
    editor.on('change', function() {
        var code = editor.getValue();
        localStorage.setItem('shreyansir_coding_' + problemId + '_' + $('#languageSelect').val(), code);
    });

    // Language Change
    $('#languageSelect').on('change', function() {
        var newLang = $(this).val();
        editor.session.setMode(getAceMode(newLang));

        var key = 'shreyansir_coding_' + problemId + '_' + newLang;
        var existing = localStorage.getItem(key);
        if (existing && existing.trim() !== '') {
            editor.setValue(existing, -1);
        } else {
            editor.setValue(starterCodes[newLang] || '', -1);
        }
    });

    // Theme Change
    $('#themeSelect').on('change', function() {
        editor.setTheme($(this).val());
    });

    // Reset Code
    $('#btnResetCode').on('click', function() {
        var lang = $('#languageSelect').val();
        Swal.fire({
            title: 'Reset Starter Code?',
            text: 'Your current edits in this language will be reverted to the original template.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Yes, Reset'
        }).then(function(res) {
            if (res.isConfirmed) {
                editor.setValue(starterCodes[lang] || '', -1);
                localStorage.removeItem('shreyansir_coding_' + problemId + '_' + lang);
            }
        });
    });

    // Toggle Custom Input
    $('#btnToggleCustomInput, #btnCloseCustomInput').on('click', function() {
        $('#customInputDrawer').slideToggle(200);
    });

    // Copy Input Button
    $('.copy-input-btn').on('click', function() {
        var text = $(this).data('text');
        navigator.clipboard.writeText(text);
        $(this).html('<i class="fas fa-check mr-1"></i> Copied!');
        var btn = $(this);
        setTimeout(function() {
            btn.html('<i class="far fa-copy mr-1"></i> Copy Input');
        }, 1500);
    });

    // Load Past Code
    $('.btn-load-past-code').on('click', function() {
        var code = $(this).data('code');
        var lang = $(this).data('lang');
        $('#languageSelect').val(lang).trigger('change');
        editor.setValue(code, -1);
        $('#desc-tab').tab('show');
    });

    // RUN CODE (TEST)
    $('#btnRunCode').on('click', function() {
        var lang = $('#languageSelect').val();
        var code = editor.getValue();
        var isCustom = $('#customInputDrawer').is(':visible');
        var customIn = $('#customInputArea').val();

        if (!code.trim()) {
            Swal.fire('Empty Code', 'Please write some code before compiling.', 'info');
            return;
        }

        $('#tab-test-btn').tab('show');
        $('#testOutputPlaceholder').hide();
        $('#testResultsContainer').hide().empty();
        $('#testRunningSpinner').show();
        $('#btnRunCode').prop('disabled', true);

        $.ajax({
            url: 'run_ajax.php',
            type: 'POST',
            data: {
                csrf_token: csrfToken,
                problem_id: problemId,
                language: lang,
                source_code: code,
                use_custom_input: isCustom ? 1 : 0,
                custom_input: customIn
            },
            dataType: 'json',
            success: function(resp) {
                $('#testRunningSpinner').hide();
                $('#btnRunCode').prop('disabled', false);

                if (!resp.success) {
                    $('#testResultsContainer').html(`
                        <div class="alert alert-danger py-2 small mb-0 font-family-monospace">
                            <strong>Execution Error:</strong> ${resp.error}
                        </div>
                    `).show();
                    return;
                }

                var html = '';
                if (resp.mode === 'custom') {
                    var statusColor = (resp.status === 'OK' || resp.status === 'AC') ? 'text-success' : 'text-danger';
                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold ${statusColor}"><i class="fas fa-check-circle mr-1"></i> Status: ${resp.status}</span>
                            <span class="text-muted small"><i class="fas fa-stopwatch mr-1"></i> ${resp.execution_time_ms} ms</span>
                        </div>
                    `;
                    if (resp.compile_output && resp.compile_output.trim() !== '') {
                        html += `
                            <div class="mb-2">
                                <span class="text-danger small font-weight-bold">Compiler Logs:</span>
                                <pre class="bg-black text-warning p-2 rounded small font-family-monospace mb-1">${resp.compile_output}</pre>
                            </div>
                        `;
                    }
                    html += `
                        <div>
                            <span class="text-white-50 small font-weight-bold">Program Output (STDOUT):</span>
                            <pre class="console-output-pre bg-black p-2 rounded text-light border border-secondary">${resp.output || '(No standard output)'}</pre>
                        </div>
                    `;
                } else {
                    // Sample test cases evaluation
                    var allPass = (resp.overall_status === 'AC' || resp.overall_status === 'PASS');
                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="badge ${allPass ? 'badge-success' : 'badge-danger'} px-2 py-1 font-weight-bold">
                                    ${allPass ? 'ALL SAMPLE TESTS PASSED' : 'SAMPLE TESTS FAILED: ' + resp.overall_status}
                                </span>
                            </div>
                            <span class="text-muted small"><i class="fas fa-stopwatch mr-1"></i> Max Time: ${resp.execution_time_ms} ms</span>
                        </div>
                    `;

                    if (resp.compile_output && resp.compile_output.trim() !== '') {
                        html += `
                            <div class="mb-3">
                                <span class="text-danger small font-weight-bold">Compiler Output:</span>
                                <pre class="bg-black text-warning p-2 rounded small font-family-monospace mb-1">${resp.compile_output}</pre>
                            </div>
                        `;
                    }

                    if (resp.results && resp.results.length > 0) {
                        resp.results.forEach(function(r) {
                            var isPass = (r.status === 'AC');
                            html += `
                                <div class="card bg-black border ${isPass ? 'border-success' : 'border-danger'} mb-2">
                                    <div class="card-header bg-dark py-1 px-2 d-flex justify-content-between align-items-center">
                                        <span class="font-weight-bold ${isPass ? 'text-success' : 'text-danger'} small">
                                            <i class="fas ${isPass ? 'fa-check' : 'fa-times'} mr-1"></i> Sample Case #${r.case_num} &mdash; ${r.status}
                                        </span>
                                        <span class="text-muted small">${r.time_ms} ms</span>
                                    </div>
                                    <div class="card-body p-2 small">
                                        <div class="row">
                                            <div class="col-md-6 mb-1">
                                                <span class="text-muted d-block font-weight-bold">Input:</span>
                                                <pre class="text-light bg-dark p-1 rounded font-family-monospace mb-0">${r.input}</pre>
                                            </div>
                                            <div class="col-md-6 mb-1">
                                                <span class="text-muted d-block font-weight-bold">Expected Output:</span>
                                                <pre class="text-light bg-dark p-1 rounded font-family-monospace mb-0">${r.expected}</pre>
                                            </div>
                                            <div class="col-12 mt-1">
                                                <span class="${isPass ? 'text-success' : 'text-danger'} d-block font-weight-bold">Your Output:</span>
                                                <pre class="text-light bg-dark p-1 rounded font-family-monospace mb-0">${r.actual || '(No output)'}</pre>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            `;
                        });
                    }
                }

                $('#testResultsContainer').html(html).show();
            },
            error: function() {
                $('#testRunningSpinner').hide();
                $('#btnRunCode').prop('disabled', false);
                $('#testResultsContainer').html(`
                    <div class="alert alert-danger py-2 small mb-0 font-family-monospace">
                        Server communication or timeout error. Please check your code for infinite loops.
                    </div>
                `).show();
            }
        });
    });

    // SUBMIT SOLUTION (OFFICIAL JUDGING)
    $('#btnSubmitCode').on('click', function() {
        var lang = $('#languageSelect').val();
        var code = editor.getValue();

        if (!code.trim()) {
            Swal.fire('Empty Code', 'Please write code before submitting.', 'info');
            return;
        }

        $('#tab-verdict-btn').tab('show');
        $('#verdictPlaceholder').hide();
        $('#verdictContainer').hide().empty();
        $('#verdictRunningSpinner').show();
        $('#btnSubmitCode').prop('disabled', true);

        $.ajax({
            url: 'submit_ajax.php',
            type: 'POST',
            data: {
                csrf_token: csrfToken,
                problem_id: problemId,
                competition_id: competitionId,
                language: lang,
                source_code: code
            },
            dataType: 'json',
            success: function(resp) {
                $('#verdictRunningSpinner').hide();
                $('#btnSubmitCode').prop('disabled', false);

                if (!resp.success) {
                    $('#verdictContainer').html(`
                        <div class="alert alert-danger py-2 small mb-0 font-family-monospace">
                            <strong>Submission Error:</strong> ${resp.error}
                        </div>
                    `).show();
                    return;
                }

                var isAC = (resp.status === 'AC');
                var vBadgeClass = isAC ? 'badge-success' : (resp.status === 'WA' ? 'badge-danger' : (resp.status === 'TLE' ? 'badge-warning text-dark' : 'badge-dark'));

                var html = `
                    <div class="card bg-black border ${isAC ? 'border-success' : 'border-danger'} p-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <div>
                                <span class="badge ${vBadgeClass} verdict-badge-lg mr-2">
                                    <i class="fas ${isAC ? 'fa-check-circle' : 'fa-times-circle'} mr-1"></i> ${resp.status}
                                </span>
                                <span class="font-weight-bold text-white" style="font-size: 1.1rem;">
                                    ${resp.passed_test_cases} / ${resp.total_test_cases} Test Cases Passed
                                </span>
                            </div>
                            <div class="mt-2 mt-md-0">
                                <span class="badge badge-primary px-3 py-2 font-weight-bold" style="font-size: 1rem;">
                                    Score: ${resp.score} pts
                                </span>
                            </div>
                        </div>
                        <div class="mt-2 text-muted small">
                            <i class="fas fa-stopwatch mr-1"></i> Execution Time: ${resp.execution_time_ms} ms &bull; Submission ID: #${resp.submission_id}
                        </div>
                    </div>
                `;

                if (resp.compile_output && resp.compile_output.trim() !== '') {
                    html += `
                        <div class="mb-3">
                            <span class="text-danger small font-weight-bold">Compiler Logs:</span>
                            <pre class="bg-black text-warning p-2 rounded small font-family-monospace mb-1">${resp.compile_output}</pre>
                        </div>
                    `;
                }

                if (resp.test_case_results && resp.test_case_results.length > 0) {
                    html += '<h6 class="text-white small font-weight-bold mb-2">Test Case Breakdown:</h6><div class="row">';
                    resp.test_case_results.forEach(function(tc) {
                        var tcPass = (tc.status === 'AC');
                        var tcBadge = tcPass ? 'badge-success' : 'badge-danger';
                        html += `
                            <div class="col-sm-6 mb-2">
                                <div class="bg-dark p-2 rounded border border-secondary d-flex justify-content-between align-items-center">
                                    <span class="text-light small font-weight-bold">
                                        Test Case #${tc.case_num} ${tc.is_sample ? '<span class="badge badge-info ml-1">Sample</span>' : ''}
                                    </span>
                                    <div>
                                        <span class="badge ${tcBadge} px-2 py-1 font-weight-bold">${tc.status}</span>
                                        <small class="text-muted ml-1">${tc.time_ms}ms</small>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                }

                $('#verdictContainer').html(html).show();

                if (isAC) {
                    Swal.fire({
                        title: 'Accepted! (AC)',
                        text: 'Congratulations! You solved all test cases perfectly.',
                        icon: 'success',
                        confirmButtonColor: '#10b981',
                        confirmButtonText: 'Awesome!'
                    });
                }
            },
            error: function() {
                $('#verdictRunningSpinner').hide();
                $('#btnSubmitCode').prop('disabled', false);
                $('#verdictContainer').html(`
                    <div class="alert alert-danger py-2 small mb-0 font-family-monospace">
                        Judge server timeout. Please check your submission.
                    </div>
                `).show();
            }
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>

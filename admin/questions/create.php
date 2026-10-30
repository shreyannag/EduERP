<?php
// admin/questions/create.php
require_once '../../includes/admin_header.php';
require_once '../../includes/image_storage.php';
require_once '../../includes/ai_generator.php';

require_admin_feature('questions');

$current_admin_id = (int)$_SESSION['admin_id'];

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll();
$boards = $pdo->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();

$subStmt = $pdo->prepare("SELECT id, name FROM subjects WHERE admin_id = ? ORDER BY name");
$subStmt->execute([$current_admin_id]);
$subjects = $subStmt->fetchAll();

$topStmt = $pdo->prepare("SELECT id, name, subject_id FROM topics WHERE admin_id = ? ORDER BY name");
$topStmt->execute([$current_admin_id]);
$topics = $topStmt->fetchAll();

$aiConfig = get_openrouter_config($pdo, $current_admin_id);

$error = '';
$success = '';

// Reset sticky form settings handler
if (isset($_GET['reset_settings'])) {
    unset($_SESSION['last_q_settings']);
    header("Location: create.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $class_id = intval($_POST['class_id'] ?? 0);
    $board_id = intval($_POST['board_id'] ?? 0);
    $subject_id = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : null;
    $topic_id = !empty($_POST['topic_id']) ? intval($_POST['topic_id']) : null;
    $marks = intval($_POST['marks'] ?? 1);
    $q = trim($_POST['question_text'] ?? '');
    $o1 = trim($_POST['option1'] ?? '');
    $o2 = trim($_POST['option2'] ?? '');
    $o3 = trim($_POST['option3'] ?? '');
    $o4 = trim($_POST['option4'] ?? '');
    $c = intval($_POST['correct_option'] ?? 1);
    
    // Verify subject and topic belong to current admin
    if ($subject_id) {
        $chkSub = $pdo->prepare("SELECT id FROM subjects WHERE id = ? AND admin_id = ?");
        $chkSub->execute([$subject_id, $current_admin_id]);
        if (!$chkSub->fetch()) $subject_id = null;
    }
    if ($topic_id) {
        $chkTop = $pdo->prepare("SELECT id FROM topics WHERE id = ? AND admin_id = ?");
        $chkTop->execute([$topic_id, $current_admin_id]);
        if (!$chkTop->fetch()) $topic_id = null;
    }
    
    $question_type = in_array($_POST['question_type'] ?? '', ['mcq', 'fill_blank']) ? $_POST['question_type'] : 'mcq';
    
    // Support multiple fill in the blanks
    $correct_blank_answers = (array)($_POST['correct_blank_answers'] ?? []);
    $legacy_ans_text = trim($_POST['correct_answer_text'] ?? '');
    
    $clean_blanks = [];
    foreach ($correct_blank_answers as $ba) {
        $trimmed_ba = trim((string)$ba);
        if ($trimmed_ba !== '') {
            $clean_blanks[] = $trimmed_ba;
        }
    }
    if (empty($clean_blanks) && $legacy_ans_text !== '') {
        $clean_blanks = array_values(parse_fill_blank_answers($legacy_ans_text));
    }

    if (count($clean_blanks) > 1) {
        $correct_answer_text = json_encode($clean_blanks, JSON_UNESCAPED_UNICODE);
    } else {
        $correct_answer_text = $clean_blanks[0] ?? '';
    }

    if ($question_type === 'fill_blank') {
        if (!$class_id || !$board_id || $q === '' || $correct_answer_text === '') {
            $error = "Class, board, question prompt, and at least one accepted blank answer are required for Fill in the Blanks.";
        }
    } else {
        if (!$class_id || !$board_id || $q === '' || strlen($o1) === 0 || strlen($o2) === 0 || strlen($o3) === 0 || strlen($o4) === 0 || !in_array($c, [1,2,3,4])) {
            $error = "All mandatory fields are fully required for safe ingestion into the schema.";
        }
    }

    if (!$error) {
        try {
            $q = process_question_images($q, $pdo, $current_admin_id);
            if ($question_type === 'fill_blank') {
                $opt1_display = implode(' / ', $clean_blanks);
                $stmt = $pdo->prepare("INSERT INTO questions (class_id, board_id, subject_id, topic_id, question_type, question_text, option1, option2, option3, option4, correct_option, correct_answer_text, marks, admin_id) VALUES (?, ?, ?, ?, 'fill_blank', ?, ?, NULL, NULL, NULL, NULL, ?, ?, ?)");
                $stmt->execute([$class_id, $board_id, $subject_id, $topic_id, $q, $opt1_display, $correct_answer_text, $marks, $current_admin_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO questions (class_id, board_id, subject_id, topic_id, question_type, question_text, option1, option2, option3, option4, correct_option, correct_answer_text, marks, admin_id) VALUES (?, ?, ?, ?, 'mcq', ?, ?, ?, ?, ?, ?, NULL, ?, ?)");
                $stmt->execute([$class_id, $board_id, $subject_id, $topic_id, $q, $o1, $o2, $o3, $o4, $c, $marks, $current_admin_id]);
            }
            $new_q_id = (int)$pdo->lastInsertId();

            $additional_classes = (array)($_POST['additional_classes'] ?? []);
            $all_classes = array_unique(array_filter(array_map('intval', $additional_classes)));
            $all_classes[] = $class_id;
            sync_question_classes($new_q_id, $all_classes, $pdo);

            // Save sticky form settings for seamless consecutive question entry
            $_SESSION['last_q_settings'] = [
                'class_id' => $class_id,
                'board_id' => $board_id,
                'subject_id' => $subject_id,
                'topic_id' => $topic_id,
                'marks' => $marks,
                'additional_classes' => $additional_classes,
                'question_type' => $question_type,
            ];

            $success = ($question_type === 'fill_blank' ? "Fill in the Blanks" : "Multiple Choice") . " question instantiated successfully in your question bank (Class availability mapped).";
        } catch (\PDOException $e) {
            $error = "Persistence execution failure via database boundary: " . $e->getMessage();
        }
    }
}

// Form state persistence / sticky settings
$sticky = $_SESSION['last_q_settings'] ?? [];
$has_sticky = !empty($sticky);
$is_post_success = ($success !== '');

$sel_class_id = (isset($_POST['class_id']) && !$is_post_success) ? intval($_POST['class_id']) : intval($sticky['class_id'] ?? 0);
$sel_board_id = (isset($_POST['board_id']) && !$is_post_success) ? intval($_POST['board_id']) : intval($sticky['board_id'] ?? 0);
$sel_subject_id = (isset($_POST['subject_id']) && !$is_post_success) ? intval($_POST['subject_id']) : intval($sticky['subject_id'] ?? 0);
$sel_topic_id = (isset($_POST['topic_id']) && !$is_post_success) ? intval($_POST['topic_id']) : intval($sticky['topic_id'] ?? 0);
$sel_marks = (isset($_POST['marks']) && !$is_post_success) ? intval($_POST['marks']) : intval($sticky['marks'] ?? 1);
$sel_additional_classes = (isset($_POST['additional_classes']) && !$is_post_success) ? (array)$_POST['additional_classes'] : (array)($sticky['additional_classes'] ?? []);
$sel_question_type = (isset($_POST['question_type']) && !$is_post_success) ? $_POST['question_type'] : ($sticky['question_type'] ?? 'mcq');
if (!in_array($sel_question_type, ['mcq', 'fill_blank'])) $sel_question_type = 'mcq';

// Input values for prompt, options, and correct option choice
$val_q = ($error !== '') ? ($_POST['question_text'] ?? '') : '';
$val_o1 = ($error !== '') ? ($_POST['option1'] ?? '') : '';
$val_o2 = ($error !== '') ? ($_POST['option2'] ?? '') : '';
$val_o3 = ($error !== '') ? ($_POST['option3'] ?? '') : '';
$val_o4 = ($error !== '') ? ($_POST['option4'] ?? '') : '';
$val_c = ($error !== '') ? intval($_POST['correct_option'] ?? 1) : 1;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Add Single Question</h2>
        <span class="text-muted small">Manually author or automatically generate academic multiple choice questions.</span>
    </div>
    <div class="mt-2 mt-md-0">
        <button type="button" class="btn text-white font-weight-bold shadow-sm rounded-pill px-4 mr-2" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);" data-toggle="modal" data-target="#aiGenerateModal">
            <i class="fas fa-magic mr-2"></i>Generate with AI
        </button>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-arrow-left mr-2"></i>Back to Question Bank
        </a>
    </div>
</div>

<?php if($error): ?>
    <div class="alert alert-danger shadow-sm rounded border-0 border-left border-danger" style="border-width: 4px !important;">
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?>
    </div>
<?php endif; ?>

<?php if($success): ?>
    <div class="alert alert-success shadow-sm rounded border-0 border-left border-success" style="border-width: 4px !important;">
        <i class="fas fa-check-circle mr-2"></i><?php echo $success; ?>
    </div>
<?php endif; ?>

<?php if($has_sticky): ?>
    <div class="alert alert-light border shadow-sm rounded-lg d-flex justify-content-between align-items-center mb-4 py-2 px-3 max-w-4xl mx-auto">
        <div class="d-flex align-items-center">
            <i class="fas fa-thumbtack text-primary mr-2"></i>
            <span class="small font-weight-bold text-dark">
                <strong>Sticky Settings Active:</strong> Class, Board, Subject, Topic, and Marks configurations are remembered for fast question creation.
            </span>
        </div>
        <a href="create.php?reset_settings=1" class="btn btn-xs btn-outline-danger font-weight-bold rounded-pill px-3 shadow-xs flex-shrink-0 ml-2" title="Clear sticky configuration">
            <i class="fas fa-undo-alt mr-1"></i>Reset to Defaults
        </a>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-lg max-w-4xl mx-auto">
    <div class="card-header bg-white border-bottom-0 py-4 px-4 px-md-5 mt-2">
        <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-pencil-alt text-primary mr-3 shadow-sm p-2 bg-light rounded-circle"></i>Author Custom Problem</h5>
        <p class="text-muted small mt-2 mb-0">Construct and map a new computational record linking directly into targeted segment bounds.</p>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
        <div class="card-body p-4 p-md-5">
            <div class="bg-light p-4 rounded-lg mb-4 border">
                <div class="row">
                    <div class="col-md-6 form-group mb-3 mb-md-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Target Class Segment</label>
                        <select name="class_id" class="form-control custom-select bg-white shadow-sm border-0 font-weight-bold" required>
                            <option value="">-- Dropdown: Isolate Class --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo ($sel_class_id === (int)$c['id']) ? 'selected' : ''; ?>>Class <?php echo h($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 form-group mb-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Board Specification</label>
                        <select name="board_id" class="form-control custom-select bg-white shadow-sm border-0 font-weight-bold" required>
                            <option value="">-- Dropdown: Isolate Board --</option>
                            <?php foreach($boards as $b): ?>
                                <option value="<?php echo $b['id']; ?>" <?php echo ($sel_board_id === (int)$b['id']) ? 'selected' : ''; ?>><?php echo h($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 form-group mt-3 mb-0">
                        <label class="font-weight-bold small text-muted text-uppercase d-block">
                            <i class="fas fa-layer-group text-primary mr-1"></i>Also Available For Other Classes (Optional)
                        </label>
                        <div class="d-flex flex-wrap p-2 bg-white rounded border shadow-sm" style="gap: 15px;">
                            <?php foreach($classes as $c): 
                                $isAddChecked = in_array((int)$c['id'], array_map('intval', $sel_additional_classes)) ? 'checked' : '';
                            ?>
                                <div class="custom-control custom-checkbox mr-3">
                                    <input type="checkbox" name="additional_classes[]" value="<?php echo $c['id']; ?>" class="custom-control-input" id="addClass<?php echo $c['id']; ?>" <?php echo $isAddChecked; ?>>
                                    <label class="custom-control-label font-weight-bold text-dark" for="addClass<?php echo $c['id']; ?>">Class <?php echo h($c['name']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted"><i class="fas fa-info-circle mr-1"></i>Select any other classes that should also be able to use this question in exams.</small>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-4 form-group mb-3 mb-md-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Subject (Optional)</label>
                        <select name="subject_id" id="subject_select" class="form-control custom-select bg-white shadow-sm border-0 font-weight-bold">
                            <option value="">-- Select Subject --</option>
                            <?php foreach($subjects as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo ($sel_subject_id === (int)$s['id']) ? 'selected' : ''; ?>><?php echo h($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 form-group mb-3 mb-md-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Topic (Optional)</label>
                        <select name="topic_id" id="topic_select" class="form-control custom-select bg-white shadow-sm border-0 font-weight-bold">
                            <option value="">-- Select Topic --</option>
                            <?php foreach($topics as $t): ?>
                                <option value="<?php echo $t['id']; ?>" data-subject="<?php echo $t['subject_id']; ?>" <?php echo ($sel_topic_id === (int)$t['id']) ? 'selected' : ''; ?> style="<?php echo ($sel_subject_id && $sel_subject_id === (int)$t['subject_id']) ? '' : 'display:none;'; ?>"><?php echo h($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 form-group mb-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Marks Allocation</label>
                        <input type="number" name="marks" class="form-control bg-white shadow-sm border-0 font-weight-bold" value="<?php echo $sel_marks; ?>" min="1" required>
                    </div>
                </div>
            </div>

            <!-- Question Format / Type Selector -->
            <div class="card border-0 shadow-sm rounded-lg mb-4 bg-light border-left border-primary" style="border-left-width: 4px !important;">
                <div class="card-body p-3 p-md-4">
                    <label class="font-weight-bold text-dark small text-uppercase mb-2 d-flex align-items-center">
                        <i class="fas fa-shapes text-primary mr-2"></i>Select Question Format
                    </label>
                    <div class="row">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <label class="q-type-card d-block p-3 bg-white border rounded-lg shadow-sm mb-0 transition cursor-pointer" id="labelTypeMCQ" style="cursor: pointer; border-width: 2px !important; border-color: #3b82f6 !important;">
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="type_mcq" name="question_type" value="mcq" class="custom-control-input" <?php echo ($sel_question_type === 'mcq') ? 'checked' : ''; ?>>
                                    <label class="custom-control-label font-weight-bold text-dark mb-0" for="type_mcq" style="cursor: pointer;">
                                        <i class="fas fa-list-ul text-primary mr-1"></i> Multiple Choice Question (MCQ)
                                    </label>
                                </div>
                                <small class="text-muted d-block ml-4 mt-1">4 distinct options (A, B, C, D) with single declared correct answer.</small>
                            </label>
                        </div>
                        <div class="col-md-6">
                            <label class="q-type-card d-block p-3 bg-white border rounded-lg shadow-sm mb-0 transition cursor-pointer" id="labelTypeFillBlank" style="cursor: pointer; border-width: 2px !important; border-color: #e5e7eb !important;">
                                <div class="custom-control custom-radio">
                                    <input type="radio" id="type_fill_blank" name="question_type" value="fill_blank" class="custom-control-input" <?php echo ($sel_question_type === 'fill_blank') ? 'checked' : ''; ?>>
                                    <label class="custom-control-label font-weight-bold text-dark mb-0" for="type_fill_blank" style="cursor: pointer;">
                                        <i class="fas fa-pen-alt text-warning mr-1"></i> Fill in the Blanks
                                    </label>
                                </div>
                                <small class="text-muted d-block ml-4 mt-1">Sentence with missing word(s). Students type their answer directly.</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2 bg-light p-2 rounded border-left border-primary" style="border-width: 4px !important;">
                    <label class="font-weight-bold text-dark h5 mb-0"><i class="fas fa-quote-left text-muted mx-2 opacity-50 text-sm"></i>Question Prompt Text</label>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-warning text-dark font-weight-bold rounded-pill px-3 shadow-sm mr-2 d-none" id="btnInsertBlankTag" title="Insert [blank] placeholder into prompt">
                            <i class="fas fa-plus mr-1 text-warning"></i>Insert [blank]
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info font-weight-bold rounded-pill px-3 shadow-sm mr-2" data-toggle="modal" data-target="#latexFormulaModal" id="btnOpenFormulaModalPrompt">
                            <i class="fas fa-square-root-alt mr-1"></i>LaTeX Formula Helper
                        </button>
                        <button type="button" class="btn btn-sm text-white font-weight-bold rounded-pill px-3 shadow-sm" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%); font-size: 0.8rem;" data-toggle="modal" data-target="#aiGenerateModal">
                            <i class="fas fa-magic mr-1"></i>Generate with AI
                        </button>
                    </div>
                </div>
                <div id="fillBlankPromptHelp" class="alert alert-info border-0 rounded-lg p-3 small mb-2 d-none">
                    <i class="fas fa-lightbulb text-warning mr-2 fa-lg"></i><strong>Tip for Fill in the Blanks:</strong> Use <code>[blank]</code> or <code>______</code> in your sentence where the blank should appear (e.g. <em>"The capital of France is [blank]."</em>). Click <strong>Insert [blank]</strong> above to add it automatically.
                </div>

                <!-- Quick LaTeX Math Symbols Bar -->
                <div class="latex-quick-bar mb-2 p-2 bg-light rounded border d-flex flex-wrap align-items-center" style="gap: 4px;">
                    <span class="small font-weight-bold text-muted mr-1"><i class="fas fa-calculator text-primary mr-1"></i>Quick Insert:</span>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\frac{a}{b}$" title="Fraction: \frac{a}{b}">$\frac{a}{b}$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\sqrt{x}$" title="Square Root: \sqrt{x}">$\sqrt{x}$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$x^{2}$" title="Power: x^{2}">$x^2$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$x_{i}$" title="Subscript: x_{i}">$x_i$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\int_{a}^{b} f(x) dx$" title="Definite Integral">$\int$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\sum_{i=1}^{n}$" title="Summation">$\sum$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\lim_{x \to 0}$" title="Limit">$\lim$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\alpha$" title="Alpha">&alpha;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\beta$" title="Beta">&beta;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\theta$" title="Theta">&theta;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\pi$" title="Pi">&pi;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\Delta$" title="Delta">&Delta;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\pm$" title="Plus-Minus">&plusmn;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\le$" title="Less than or equal">&le;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\ge$" title="Greater than or equal">&ge;</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\text{H}_2\text{O}$" title="Water (Chemistry)">$\text{H}_2\text{O}$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$\vec{F}$" title="Vector F">$\vec{F}$</button>
                    <button type="button" class="btn btn-xs btn-white border shadow-sm px-2 py-1 quick-latex-insert" data-latex="$$ \frac{-b \pm \sqrt{b^2 - 4ac}}{2a} $$" title="Quadratic Formula">Quad Formula</button>
                    <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold ml-auto" data-toggle="modal" data-target="#latexFormulaModal" id="btnMoreLatexPrompt">
                        <i class="fas fa-ellipsis-h mr-1"></i>All Symbols & Live Tester
                    </button>
                </div>

                <textarea name="question_text" class="form-control font-weight-bold text-dark shadow-sm" rows="4" placeholder="Articulate the full structure of the computational prompt..." required style="resize: none;"><?php echo h($val_q); ?></textarea>
            </div>
            
            <!-- Fill in the Blanks Answer Declaration Section -->
            <div id="fillBlankAnswerSection" class="p-4 bg-light rounded-lg border border-primary mb-4" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                    <label class="font-weight-bold text-dark h5 mb-0">
                        <i class="fas fa-check-circle text-success mr-2"></i>Declare Correct Blank Answer(s)
                    </label>
                    <div class="d-flex align-items-center mt-2 mt-sm-0">
                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill font-weight-bold mr-2 shadow-sm" id="btnAddAnotherBlank">
                            <i class="fas fa-plus mr-1"></i>Add Another Blank
                        </button>
                        <span class="badge badge-success px-3 py-1 font-weight-bold rounded-pill shadow-sm"><i class="fas fa-spell-check mr-1"></i>Case-Insensitive</span>
                    </div>
                </div>
                <p class="text-muted small mb-3">
                    Specify the accepted answer for each blank in your question. Multiple blanks (e.g. Blank 1, Blank 2) correspond sequentially to the <code>[blank]</code> placeholders in your prompt. For acceptable synonyms or spellings per blank, separate them with a pipe <code>|</code> (e.g. <code>extends | extend</code>).
                </p>
                
                <div id="blankInputsContainer">
                    <div class="blank-row p-3 bg-white rounded border mb-2 shadow-xs" data-blank-idx="1">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge badge-primary font-weight-bold px-2 py-1 blank-badge"><i class="fas fa-pen-alt mr-1"></i>Blank 1</span>
                        </div>
                        <div class="input-group input-group-lg shadow-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text bg-light border-0 text-success font-weight-bold px-3"><i class="fas fa-key"></i></span>
                            </div>
                            <input type="text" name="correct_blank_answers[]" class="form-control border-0 bg-light font-weight-bold text-dark blank-answer-input" placeholder="Accepted answer(s) for Blank 1 (e.g. Mitochondria | mitochondrion)">
                        </div>
                    </div>
                </div>
                
                <small class="text-muted d-block mt-2"><i class="fas fa-info-circle mr-1 text-primary"></i>Tip: Typing multiple <code>[blank]</code> tags in the question prompt automatically creates corresponding blank answer fields above.</small>
            </div>

            <!-- MCQ Options Section -->
            <div id="mcqOptionsSection">
                <div class="d-flex justify-content-between align-items-center mb-3 bg-light p-2 rounded border-left border-warning" style="border-width: 4px !important;">
                    <label class="font-weight-bold text-dark h5 mb-0"><i class="fas fa-list-ol text-muted mx-2 opacity-50 text-sm"></i>Option Variations (A, B, C, D)</label>
                    <span class="text-muted small font-weight-normal"><i class="fas fa-info-circle mr-1 text-primary"></i>Multi-line text, equations & code snippets supported (Shift+Enter / Enter)</span>
                </div>
                <div class="row">
                    <!-- Option A -->
                    <div class="col-md-6 form-group mb-4">
                        <div class="card border shadow-sm h-100">
                            <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                                <span class="font-weight-bold text-dark"><span class="badge badge-primary mr-1 px-2 py-1">A</span> Option A</span>
                                <div class="d-flex align-items-center">
                                    <button type="button" class="btn btn-xs btn-white border text-primary font-weight-bold btn-opt-latex px-2 mr-2" data-target-opt="opt1" title="Insert LaTeX into Option A">
                                        <i class="fas fa-square-root-alt mr-1"></i>+ LaTeX
                                    </button>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" name="opt_correct_radio" id="correct_radio_1" value="1" class="custom-control-input opt-correct-radio" <?php echo ($val_c === 1) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label font-weight-bold text-success small" for="correct_radio_1" style="cursor: pointer;">
                                            <i class="fas fa-check-circle mr-1"></i>Correct Choice
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <textarea name="option1" id="option1_input" rows="3" class="form-control border-0 bg-white opt-math-input font-weight-normal" placeholder="Option A text, multi-line code snippet, or formula..." required style="resize: vertical; font-family: inherit; line-height: 1.5; min-height: 80px;"><?php echo h($val_o1); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Option B -->
                    <div class="col-md-6 form-group mb-4">
                        <div class="card border shadow-sm h-100">
                            <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                                <span class="font-weight-bold text-dark"><span class="badge badge-primary mr-1 px-2 py-1">B</span> Option B</span>
                                <div class="d-flex align-items-center">
                                    <button type="button" class="btn btn-xs btn-white border text-primary font-weight-bold btn-opt-latex px-2 mr-2" data-target-opt="opt2" title="Insert LaTeX into Option B">
                                        <i class="fas fa-square-root-alt mr-1"></i>+ LaTeX
                                    </button>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" name="opt_correct_radio" id="correct_radio_2" value="2" class="custom-control-input opt-correct-radio" <?php echo ($val_c === 2) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label font-weight-bold text-success small" for="correct_radio_2" style="cursor: pointer;">
                                            <i class="fas fa-check-circle mr-1"></i>Correct Choice
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <textarea name="option2" id="option2_input" rows="3" class="form-control border-0 bg-white opt-math-input font-weight-normal" placeholder="Option B text, multi-line code snippet, or formula..." required style="resize: vertical; font-family: inherit; line-height: 1.5; min-height: 80px;"><?php echo h($val_o2); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Option C -->
                    <div class="col-md-6 form-group mb-4">
                        <div class="card border shadow-sm h-100">
                            <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                                <span class="font-weight-bold text-dark"><span class="badge badge-primary mr-1 px-2 py-1">C</span> Option C</span>
                                <div class="d-flex align-items-center">
                                    <button type="button" class="btn btn-xs btn-white border text-primary font-weight-bold btn-opt-latex px-2 mr-2" data-target-opt="opt3" title="Insert LaTeX into Option C">
                                        <i class="fas fa-square-root-alt mr-1"></i>+ LaTeX
                                    </button>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" name="opt_correct_radio" id="correct_radio_3" value="3" class="custom-control-input opt-correct-radio" <?php echo ($val_c === 3) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label font-weight-bold text-success small" for="correct_radio_3" style="cursor: pointer;">
                                            <i class="fas fa-check-circle mr-1"></i>Correct Choice
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <textarea name="option3" id="option3_input" rows="3" class="form-control border-0 bg-white opt-math-input font-weight-normal" placeholder="Option C text, multi-line code snippet, or formula..." required style="resize: vertical; font-family: inherit; line-height: 1.5; min-height: 80px;"><?php echo h($val_o3); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Option D -->
                    <div class="col-md-6 form-group mb-4">
                        <div class="card border shadow-sm h-100">
                            <div class="card-header bg-light py-2 px-3 d-flex justify-content-between align-items-center">
                                <span class="font-weight-bold text-dark"><span class="badge badge-primary mr-1 px-2 py-1">D</span> Option D</span>
                                <div class="d-flex align-items-center">
                                    <button type="button" class="btn btn-xs btn-white border text-primary font-weight-bold btn-opt-latex px-2 mr-2" data-target-opt="opt4" title="Insert LaTeX into Option D">
                                        <i class="fas fa-square-root-alt mr-1"></i>+ LaTeX
                                    </button>
                                    <div class="custom-control custom-radio">
                                        <input type="radio" name="opt_correct_radio" id="correct_radio_4" value="4" class="custom-control-input opt-correct-radio" <?php echo ($val_c === 4) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label font-weight-bold text-success small" for="correct_radio_4" style="cursor: pointer;">
                                            <i class="fas fa-check-circle mr-1"></i>Correct Choice
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-2">
                                <textarea name="option4" id="option4_input" rows="3" class="form-control border-0 bg-white opt-math-input font-weight-normal" placeholder="Option D text, multi-line code snippet, or formula..." required style="resize: vertical; font-family: inherit; line-height: 1.5; min-height: 80px;"><?php echo h($val_o4); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live LaTeX & MathJax Preview Card -->
            <div class="card border-0 shadow-sm rounded-lg mb-4 bg-light border-left border-info" style="border-left-width: 4px !important;" id="liveMathPreviewContainer">
                <div class="card-header bg-white border-bottom-0 py-3 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="font-weight-bold text-dark mb-0 d-flex align-items-center">
                            <i class="fas fa-eye text-info mr-2"></i>Live Question & MathJax Preview
                            <span class="badge badge-light border text-muted ml-2 font-weight-normal px-2 py-1" style="font-size: 0.75rem;">Real-Time Typesetting</span>
                        </h6>
                    </div>
                    <div class="d-flex align-items-center">
                        <span class="badge badge-success px-2 py-1 small font-weight-bold mr-2" id="previewStatusBadge">
                            <i class="fas fa-sync-alt fa-spin mr-1 d-none" id="previewSpinner"></i><i class="fas fa-check-circle mr-1" id="previewCheck"></i>Live Ready
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill font-weight-bold" id="btnRefreshPreview" title="Force re-render MathJax">
                            <i class="fas fa-redo-alt mr-1"></i>Render
                        </button>
                    </div>
                </div>
                <div class="card-body p-4 pt-2">
                    <div class="p-3 bg-white rounded border mb-3 shadow-sm" id="livePreviewQuestion" style="min-height: 50px;">
                        <span class="text-muted font-italic">Question prompt preview will render here with LaTeX equations formatted...</span>
                    </div>
                    <div class="row" id="livePreviewOptionsRow">
                        <div class="col-md-6 mb-2">
                            <div class="p-2 bg-white rounded border d-flex align-items-center shadow-sm" id="liveOptCard1">
                                <span class="badge badge-secondary mr-2 px-2 py-1 font-weight-bold">A</span>
                                <span class="opt-live-text flex-grow-1" id="liveOptText1"><em class="text-muted">Option 1 preview</em></span>
                                <span class="badge badge-success ml-2 d-none live-correct-badge" id="liveCorrectBadge1"><i class="fas fa-check mr-1"></i>Correct Choice</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="p-2 bg-white rounded border d-flex align-items-center shadow-sm" id="liveOptCard2">
                                <span class="badge badge-secondary mr-2 px-2 py-1 font-weight-bold">B</span>
                                <span class="opt-live-text flex-grow-1" id="liveOptText2"><em class="text-muted">Option 2 preview</em></span>
                                <span class="badge badge-success ml-2 d-none live-correct-badge" id="liveCorrectBadge2"><i class="fas fa-check mr-1"></i>Correct Choice</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="p-2 bg-white rounded border d-flex align-items-center shadow-sm" id="liveOptCard3">
                                <span class="badge badge-secondary mr-2 px-2 py-1 font-weight-bold">C</span>
                                <span class="opt-live-text flex-grow-1" id="liveOptText3"><em class="text-muted">Option 3 preview</em></span>
                                <span class="badge badge-success ml-2 d-none live-correct-badge" id="liveCorrectBadge3"><i class="fas fa-check mr-1"></i>Correct Choice</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-2">
                            <div class="p-2 bg-white rounded border d-flex align-items-center shadow-sm" id="liveOptCard4">
                                <span class="badge badge-secondary mr-2 px-2 py-1 font-weight-bold">D</span>
                                <span class="opt-live-text flex-grow-1" id="liveOptText4"><em class="text-muted">Option 4 preview</em></span>
                                <span class="badge badge-success ml-2 d-none live-correct-badge" id="liveCorrectBadge4"><i class="fas fa-check mr-1"></i>Correct Choice</span>
                            </div>
                        </div>
                    </div>

                    <!-- Fill in the Blank Live Preview Card -->
                    <div id="livePreviewBlankContainer" class="p-3 bg-white rounded border shadow-sm mb-2 d-none">
                        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap">
                            <span class="badge badge-warning text-dark font-weight-bold px-3 py-1 shadow-sm rounded-pill mb-1"><i class="fas fa-pen-alt mr-1"></i>Fill in the Blank Format</span>
                            <div id="liveBlankBadgesList" class="mb-1"></div>
                        </div>
                        <div class="p-3 bg-light rounded-lg border">
                            <div class="small font-weight-bold text-uppercase mb-2 text-primary d-flex align-items-center"><i class="fas fa-keyboard mr-2"></i>Student Response Fields (Typeset Preview):</div>
                            <div id="liveBlankStudentFields"></div>
                            <small class="text-muted mt-2 d-block"><i class="fas fa-spell-check mr-1 text-success"></i>Answers are evaluated case-insensitively and space-normalized per blank.</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="mcqCorrectSection" class="form-group mb-0 p-4 bg-success bg-opacity-10 rounded-lg border border-success">
                <label class="font-weight-bold text-success text-uppercase small"><i class="fas fa-check-circle mr-2"></i>Declare Correct Option Map</label>
                <select name="correct_option" id="correct_option_select" class="form-control custom-select bg-white shadow-sm border-0 font-weight-bold text-success" required>
                    <option value="1" <?php echo ($val_c === 1) ? 'selected' : ''; ?>>Option A is the verified correct answer</option>
                    <option value="2" <?php echo ($val_c === 2) ? 'selected' : ''; ?>>Option B is the verified correct answer</option>
                    <option value="3" <?php echo ($val_c === 3) ? 'selected' : ''; ?>>Option C is the verified correct answer</option>
                    <option value="4" <?php echo ($val_c === 4) ? 'selected' : ''; ?>>Option D is the verified correct answer</option>
                </select>
            </div>
        </div>
        <div class="card-footer bg-light border-top-0 py-4 px-4 px-md-5 rounded-bottom text-right">
            <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow"><i class="fas fa-save mr-2"></i>Inject Node Array</button>
        </div>
    </form>
</div>

<!-- OpenRouter AI Question Generation Modal -->
<div class="modal fade" id="aiGenerateModal" tabindex="-1" role="dialog" aria-labelledby="aiGenerateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg overflow-hidden">
            <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #1e1b4b 0%, #312e81 50%, #4338ca 100%); color: white;">
                <h5 class="modal-title font-weight-bold d-flex align-items-center mb-0" id="aiGenerateModalLabel">
                    <i class="fas fa-magic text-warning mr-2"></i>AI Question Generator
                    <span class="badge badge-light text-primary font-weight-bold ml-2 px-2 py-1" style="font-size: 0.75rem;">OpenRouter</span>
                </h5>
                <button type="button" class="close text-white opacity-75" data-dismiss="modal" aria-label="Close" style="outline: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <!-- API Key Status / Setup Alert -->
                <div id="modal_api_key_alert" class="alert alert-warning border-0 shadow-sm rounded-lg d-flex align-items-center justify-content-between p-3 mb-3 <?php echo !empty($aiConfig['api_key']) ? 'd-none' : ''; ?>">
                    <div class="d-flex align-items-center">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mr-3"></i>
                        <div>
                            <strong class="d-block text-dark">OpenRouter API Key Required</strong>
                            <span class="small text-muted">Provide your key below or permanently save it in AI settings to generate questions.</span>
                        </div>
                    </div>
                    <a href="../ai_settings.php" target="_blank" class="btn btn-sm btn-dark font-weight-bold rounded-pill px-3 ml-2 flex-shrink-0">
                        <i class="fas fa-cog mr-1"></i>AI Settings
                    </a>
                </div>

                <!-- API Key Quick Config Bar -->
                <div class="bg-light p-3 rounded-lg border mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold small text-muted text-uppercase">
                            <i class="fas fa-key mr-1"></i>OpenRouter Key:
                            <?php if (!empty($aiConfig['api_key'])): ?>
                                <span class="badge badge-success ml-1"><i class="fas fa-check mr-1"></i>Configured</span>
                            <?php else: ?>
                                <span class="badge badge-danger ml-1"><i class="fas fa-times mr-1"></i>Missing</span>
                            <?php endif; ?>
                        </span>
                        <a href="javascript:void(0)" id="toggleKeyOverride" class="small text-primary font-weight-bold text-decoration-none">
                            <i class="fas fa-sliders-h mr-1"></i><?php echo !empty($aiConfig['api_key']) ? 'Override / Change Key' : 'Enter API Key'; ?>
                        </a>
                    </div>
                    <div id="keyOverrideWrapper" class="<?php echo !empty($aiConfig['api_key']) ? 'd-none' : ''; ?> mt-2 pt-2 border-top">
                        <div class="input-group">
                            <input type="password" id="modal_ai_api_key" class="form-control font-weight-bold" placeholder="sk-or-v1-..." value="<?php echo h($aiConfig['api_key']); ?>">
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary bg-white" type="button" id="toggleModalKeyEye">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="custom-control custom-checkbox mt-2">
                            <input type="checkbox" class="custom-control-input" id="modal_ai_save_key" checked>
                            <label class="custom-control-label small text-muted font-weight-bold" for="modal_ai_save_key">
                                Automatically save this key to your account settings
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Generation Parameters -->
                <div class="row">
                    <div class="col-md-6 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Target Class</label>
                        <select id="modal_ai_class" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="">-- Use Form Class --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>">Class <?php echo h($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Target Board</label>
                        <select id="modal_ai_board" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="">-- Use Form Board --</option>
                            <?php foreach($boards as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo h($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Subject</label>
                        <select id="modal_ai_subject" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="">-- Use Form Subject --</option>
                            <?php foreach($subjects as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo h($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Topic / Chapter</label>
                        <select id="modal_ai_topic" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="">-- All Topics / General --</option>
                            <?php foreach($topics as $t): ?>
                                <option value="<?php echo $t['id']; ?>" data-subject="<?php echo $t['subject_id']; ?>"><?php echo h($t['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Format</label>
                        <select id="modal_ai_type" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="mcq">MCQ (4 Options)</option>
                            <option value="fill_blank">Fill in the Blanks</option>
                        </select>
                    </div>
                    <div class="col-md-3 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Difficulty</label>
                        <select id="modal_ai_difficulty" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="Easy">Easy (Recall)</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="Hard">Hard</option>
                            <option value="Olympiad / Advanced">Olympiad</option>
                        </select>
                    </div>
                    <div class="col-md-2 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Marks</label>
                        <input type="number" id="modal_ai_marks" class="form-control bg-white font-weight-bold shadow-sm" value="1" min="1">
                    </div>
                    <div class="col-md-4 form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">AI Model</label>
                        <select id="modal_ai_model" class="form-control custom-select bg-white font-weight-bold shadow-sm">
                            <option value="google/gemini-2.0-flash-001" <?php echo ($aiConfig['model'] === 'google/gemini-2.0-flash-001') ? 'selected' : ''; ?>>Gemini 2.0 Flash (Fastest)</option>
                            <option value="deepseek/deepseek-chat" <?php echo ($aiConfig['model'] === 'deepseek/deepseek-chat') ? 'selected' : ''; ?>>DeepSeek V3 (Reasoning)</option>
                            <option value="meta-llama/llama-3.3-70b-instruct" <?php echo ($aiConfig['model'] === 'meta-llama/llama-3.3-70b-instruct') ? 'selected' : ''; ?>>Llama 3.3 70B</option>
                            <option value="openai/gpt-4o-mini" <?php echo ($aiConfig['model'] === 'openai/gpt-4o-mini') ? 'selected' : ''; ?>>GPT-4o Mini</option>
                            <option value="anthropic/claude-3.5-sonnet" <?php echo ($aiConfig['model'] === 'anthropic/claude-3.5-sonnet') ? 'selected' : ''; ?>>Claude 3.5 Sonnet</option>
                            <?php if (!in_array($aiConfig['model'], ['google/gemini-2.0-flash-001', 'deepseek/deepseek-chat', 'meta-llama/llama-3.3-70b-instruct', 'openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet'])): ?>
                                <option value="<?php echo h($aiConfig['model']); ?>" selected><?php echo h($aiConfig['model']); ?> (Default)</option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group mb-3">
                    <label class="font-weight-bold small text-muted text-uppercase">Custom Prompt / Specific Topic Guidance (Optional)</label>
                    <textarea id="modal_ai_prompt" class="form-control shadow-sm" rows="2" placeholder="e.g. Focus on Ohm's law with numerical resistance calculation, or photosynthesis light-dependent Calvin cycle reaction..."></textarea>
                </div>

                <!-- Generate Trigger Button -->
                <button type="button" id="btnAiGenerate" class="btn btn-primary btn-block py-2 font-weight-bold shadow rounded-pill" style="background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border: none;">
                    <i class="fas fa-sparkles mr-2"></i>Generate Question with AI
                </button>

                <!-- Loading Spinner Indicator -->
                <div id="aiLoadingIndicator" class="text-center py-4 d-none">
                    <div class="spinner-border text-primary" role="status" style="width: 2.8rem; height: 2.8rem;">
                        <span class="sr-only">Loading...</span>
                    </div>
                    <div class="font-weight-bold text-dark mt-3">Synthesizing Question with OpenRouter AI...</div>
                    <div class="text-muted small">Validating curriculum bounds, distractors, and markdown formatting</div>
                </div>

                <!-- Error Alert Box -->
                <div id="aiErrorAlert" class="alert alert-danger d-none shadow-sm rounded-lg mt-3 border-0 border-left border-danger" style="border-width: 4px !important;">
                    <i class="fas fa-exclamation-circle mr-2"></i><span id="aiErrorText"></span>
                </div>

                <!-- Result Preview Card -->
                <div id="aiPreviewContainer" class="d-none mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fas fa-check-circle text-success mr-2"></i>Generated Question Preview
                        </h6>
                        <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold" id="aiModelBadge"></span>
                    </div>
                    <div class="card bg-light border-0 rounded-lg p-3 mb-3">
                        <div id="aiPreviewQuestionText" class="font-weight-bold text-dark mb-3 bg-white p-3 rounded shadow-sm border"></div>
                        <div class="row" id="aiPreviewOptions">
                            <div class="col-md-6 mb-2">
                                <div class="p-2 bg-white rounded border d-flex align-items-center" id="preview_opt_1">
                                    <span class="badge badge-secondary mr-2 px-2 py-1">A</span>
                                    <span class="opt-text flex-grow-1" id="preview_opt_text_1"></span>
                                    <span class="badge badge-success ml-2 d-none" id="preview_badge_1"><i class="fas fa-check"></i> Correct</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="p-2 bg-white rounded border d-flex align-items-center" id="preview_opt_2">
                                    <span class="badge badge-secondary mr-2 px-2 py-1">B</span>
                                    <span class="opt-text flex-grow-1" id="preview_opt_text_2"></span>
                                    <span class="badge badge-success ml-2 d-none" id="preview_badge_2"><i class="fas fa-check"></i> Correct</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="p-2 bg-white rounded border d-flex align-items-center" id="preview_opt_3">
                                    <span class="badge badge-secondary mr-2 px-2 py-1">C</span>
                                    <span class="opt-text flex-grow-1" id="preview_opt_text_3"></span>
                                    <span class="badge badge-success ml-2 d-none" id="preview_badge_3"><i class="fas fa-check"></i> Correct</span>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="p-2 bg-white rounded border d-flex align-items-center" id="preview_opt_4">
                                    <span class="badge badge-secondary mr-2 px-2 py-1">D</span>
                                    <span class="opt-text flex-grow-1" id="preview_opt_text_4"></span>
                                    <span class="badge badge-success ml-2 d-none" id="preview_badge_4"><i class="fas fa-check"></i> Correct</span>
                                </div>
                            </div>
                        </div>
                        <div id="aiPreviewExplanationBox" class="mt-2 p-2 rounded bg-info bg-opacity-10 border border-info small text-dark d-none">
                            <strong><i class="fas fa-lightbulb text-warning mr-1"></i>Explanation:</strong> <span id="aiPreviewExplanation"></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" id="btnAiRegenerate" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4">
                            <i class="fas fa-redo mr-2"></i>Regenerate
                        </button>
                        <button type="button" id="btnAiApply" class="btn btn-success font-weight-bold rounded-pill px-5 shadow">
                            <i class="fas fa-check-circle mr-2"></i>Apply to Form
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- LaTeX Formula Helper & Live Tester Modal -->
<div class="modal fade" id="latexFormulaModal" tabindex="-1" role="dialog" aria-labelledby="latexFormulaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg overflow-hidden">
            <div class="modal-header py-3 px-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white;">
                <h5 class="modal-title font-weight-bold d-flex align-items-center mb-0" id="latexFormulaModalLabel">
                    <i class="fas fa-square-root-alt text-warning mr-2"></i>LaTeX Formula Helper & Live Tester
                </h5>
                <button type="button" class="close text-white opacity-75" data-dismiss="modal" aria-label="Close" style="outline: none;">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4 bg-light">
                <!-- Formula Input & Real-Time Preview Area -->
                <div class="bg-white p-3 rounded-lg border shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                        <div class="d-flex align-items-center mb-1 mb-md-0">
                            <label class="font-weight-bold text-dark small text-uppercase mb-0 mr-2">Formula Editor (LaTeX):</label>
                            <span class="badge badge-light border text-primary font-weight-bold px-2 py-1" id="currentLatexTargetBadge">
                                Target: <span id="currentLatexTargetLabel">Question Prompt</span>
                            </span>
                        </div>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnWrapInline" title="Wrap in $...$ for inline math">$...$ Inline</button>
                            <button type="button" class="btn btn-outline-secondary py-0 px-2" id="btnWrapDisplay" title="Wrap in $$...$$ for centered block math">$$...$$ Block</button>
                            <button type="button" class="btn btn-outline-danger py-0 px-2" id="btnClearFormula" title="Clear input">Clear</button>
                        </div>
                    </div>
                    <textarea id="modalLatexInput" class="form-control font-weight-bold text-dark font-monospace mb-2" rows="2" style="font-family: Consolas, monospace; font-size: 0.95rem;" placeholder="Type or click symbols below (e.g. \frac{-b \pm \sqrt{b^2 - 4ac}}{2a})"></textarea>
                    
                    <label class="font-weight-bold text-muted small text-uppercase mb-1"><i class="fas fa-eye mr-1 text-info"></i>Live MathJax Rendering Preview:</label>
                    <div id="modalLatexLiveRender" class="p-3 bg-light rounded border text-center font-weight-bold text-dark shadow-sm" style="min-height: 60px; font-size: 1.25rem; display: flex; align-items: center; justify-content: center; overflow-x: auto;">
                        <span class="text-muted font-weight-normal small">Formula will render here live...</span>
                    </div>
                </div>

                <!-- Symbol Palettes & Templates Tabs -->
                <div class="bg-white rounded-lg border shadow-sm p-3 mb-3">
                    <ul class="nav nav-pills nav-fill mb-3" id="latexSymbolTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active font-weight-bold py-1 px-2 small" id="tab-algebra-link" data-toggle="pill" href="#tab-algebra" role="tab">Fractions & Algebra</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold py-1 px-2 small" id="tab-calculus-link" data-toggle="pill" href="#tab-calculus" role="tab">Calculus & Limits</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold py-1 px-2 small" id="tab-greek-link" data-toggle="pill" href="#tab-greek" role="tab">Greek Letters</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link font-weight-bold py-1 px-2 small" id="tab-science-link" data-toggle="pill" href="#tab-science" role="tab">Physics & Chemistry</a>
                        </li>
                    </ul>

                    <div class="tab-content" id="latexSymbolTabContent">
                        <!-- Tab 1: Algebra -->
                        <div class="tab-pane fade show active" id="tab-algebra" role="tabpanel">
                            <div class="d-flex flex-wrap" style="gap: 6px;">
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\frac{a}{b}" title="Fraction">\frac{a}{b}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\sqrt{x}" title="Square root">\sqrt{x}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\sqrt[n]{x}" title="N-th root">\sqrt[n]{x}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="x^{2}" title="Superscript">x^{2}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="x_{i}" title="Subscript">x_{i}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\pm" title="Plus-minus">&plusmn;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\times" title="Multiplication">&times;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\div" title="Division">&divide;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\le" title="Less equal">&le;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\ge" title="Greater equal">&ge;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\neq" title="Not equal">&ne;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\approx" title="Approximately">&asymp;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\infty" title="Infinity">&infin;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="ax^2 + bx + c = 0" title="Quadratic Equation">ax^2+bx+c=0</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\log_{10}(x)" title="Logarithm">\log_{10}(x)</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\ln(x)" title="Natural Log">\ln(x)</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="|x|" title="Absolute value">|x|</button>
                            </div>
                        </div>

                        <!-- Tab 2: Calculus -->
                        <div class="tab-pane fade" id="tab-calculus" role="tabpanel">
                            <div class="d-flex flex-wrap" style="gap: 6px;">
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\int f(x) dx" title="Indefinite Integral">\int f(x)dx</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\int_{a}^{b} f(x) dx" title="Definite Integral">\int_{a}^{b} f(x)dx</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\frac{dy}{dx}" title="Derivative">\frac{dy}{dx}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\frac{d^2y}{dx^2}" title="Second Derivative">\frac{d^2y}{dx^2}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\frac{\partial f}{\partial x}" title="Partial Derivative">\frac{\partial f}{\partial x}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\lim_{x \to 0}" title="Limit">\lim_{x \to 0}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\lim_{x \to \infty}" title="Limit at infinity">\lim_{x \to \infty}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\sum_{i=1}^{n}" title="Summation">\sum_{i=1}^{n}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\prod_{i=1}^{n}" title="Product">\prod_{i=1}^{n}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\sin(\theta)" title="Sine">\sin(\theta)</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\cos(\theta)" title="Cosine">\cos(\theta)</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\tan(\theta)" title="Tangent">\tan(\theta)</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\vec{F}" title="Vector">\vec{F}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\hat{i} + \hat{j} + \hat{k}" title="Unit Vectors">\hat{i}+\hat{j}+\hat{k}</button>
                            </div>
                        </div>

                        <!-- Tab 3: Greek Letters -->
                        <div class="tab-pane fade" id="tab-greek" role="tabpanel">
                            <div class="d-flex flex-wrap" style="gap: 6px;">
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\alpha" title="alpha">&alpha;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\beta" title="beta">&beta;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\gamma" title="gamma">&gamma;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\delta" title="delta">&delta;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\Delta" title="Delta (capital)">&Delta;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\epsilon" title="epsilon">&epsilon;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\theta" title="theta">&theta;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\lambda" title="lambda">&lambda;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\mu" title="mu">&mu;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\pi" title="pi">&pi;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\rho" title="rho">&rho;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\sigma" title="sigma">&sigma;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\tau" title="tau">&tau;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\phi" title="phi">&phi;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\omega" title="omega">&omega;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\Omega" title="Omega (capital)">&Omega;</button>
                            </div>
                        </div>

                        <!-- Tab 4: Science -->
                        <div class="tab-pane fade" id="tab-science" role="tabpanel">
                            <div class="d-flex flex-wrap" style="gap: 6px;">
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\text{H}_2\text{O}" title="Water">\text{H}_2\text{O}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\text{CO}_2" title="Carbon Dioxide">\text{CO}_2</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\rightarrow" title="Reaction Arrow">&rarr;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\rightleftharpoons" title="Equilibrium">&#8652;</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\Delta H" title="Enthalpy change">\Delta H</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="E = mc^2" title="Mass-energy">E = mc^2</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="F = G\frac{m_1 m_2}{r^2}" title="Gravitation">F = G\frac{m_1 m_2}{r^2}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="V = IR" title="Ohm's Law">V = IR</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="\lambda = \frac{h}{p}" title="De Broglie">\lambda = \frac{h}{p}</button>
                                <button type="button" class="btn btn-sm btn-light border palette-btn" data-code="^{\circ}\text{C}" title="Degrees Celsius">&deg;C</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Insertion Targets Row -->
                <div class="bg-white p-3 rounded-lg border shadow-sm">
                    <span class="font-weight-bold text-dark small text-uppercase d-block mb-2"><i class="fas fa-sign-in-alt text-success mr-1"></i>Insert Formula Into:</span>
                    <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                        <button type="button" class="btn btn-sm btn-primary font-weight-bold shadow-sm insert-target-btn" data-target="prompt">
                            <i class="fas fa-quote-left mr-1"></i>Question Prompt
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-dark font-weight-bold shadow-sm insert-target-btn" data-target="opt1">
                            <span class="badge badge-secondary mr-1">A</span>Option 1
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-dark font-weight-bold shadow-sm insert-target-btn" data-target="opt2">
                            <span class="badge badge-secondary mr-1">B</span>Option 2
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-dark font-weight-bold shadow-sm insert-target-btn" data-target="opt3">
                            <span class="badge badge-secondary mr-1">C</span>Option 3
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-dark font-weight-bold shadow-sm insert-target-btn" data-target="opt4">
                            <span class="badge badge-secondary mr-1">D</span>Option 4
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-info font-weight-bold shadow-sm ml-auto" id="btnCopyLatex">
                            <i class="fas fa-copy mr-1"></i>Copy Formula
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top-0 py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm font-weight-bold rounded-pill px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let lastGeneratedData = null;
    let activeLatexTarget = 'prompt';

    function uploadSummernoteImage(file, editor) {
        let data = new FormData();
        data.append('image', file);
        data.append('csrf_token', '<?php echo generate_csrf_token(); ?>');

        $.ajax({
            url: 'upload_image.php',
            method: 'POST',
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success && res.url) {
                    editor.summernote('insertImage', res.url);
                } else {
                    alert(res.error || 'Failed to upload and compress image.');
                }
            },
            error: function() {
                alert('Server error occurred during image upload.');
            }
        });
    }

    // Initialize Summernote with onChange callback for live preview
    $('textarea[name="question_text"]').summernote({
        height: 150,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'italic', 'underline', 'clear']],
            ['color', ['forecolor']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['link', 'picture']],
            ['more', ['backcolor', 'fullscreen', 'codeview', 'help']]
        ],
        callbacks: {
            onImageUpload: function(files) {
                for (let i = 0; i < files.length; i++) {
                    uploadSummernoteImage(files[i], $(this));
                }
            },
            onChange: function(contents) {
                debouncedLivePreview();
            }
        }
    });

    // --- Dynamic Multi-Blank Management ---
    var isModifyingBlanks = false;

    function getBlankInputsCount() {
        return $('#blankInputsContainer .blank-row').length;
    }

    function addBlankRow(initialVal) {
        var nextIdx = getBlankInputsCount() + 1;
        var val = (typeof initialVal === 'string') ? initialVal : '';
        var rowHtml = '<div class="blank-row p-3 bg-white rounded border mb-2 shadow-xs" data-blank-idx="' + nextIdx + '">' +
            '<div class="d-flex justify-content-between align-items-center mb-2">' +
                '<span class="badge badge-primary font-weight-bold px-2 py-1 blank-badge"><i class="fas fa-pen-alt mr-1"></i>Blank ' + nextIdx + '</span>' +
                (nextIdx > 1 ? '<button type="button" class="btn btn-xs btn-outline-danger font-weight-bold btn-remove-blank py-0 px-2 rounded-pill"><i class="fas fa-times mr-1"></i>Remove</button>' : '') +
            '</div>' +
            '<div class="input-group input-group-lg shadow-sm">' +
                '<div class="input-group-prepend">' +
                    '<span class="input-group-text bg-light border-0 text-success font-weight-bold px-3"><i class="fas fa-key"></i></span>' +
                '</div>' +
                '<input type="text" name="correct_blank_answers[]" class="form-control border-0 bg-light font-weight-bold text-dark blank-answer-input" placeholder="Accepted answer(s) for Blank ' + nextIdx + ' (e.g. Mitochondria | mitochondrion)" value="' + $('<div>').text(val).html() + '" required>' +
            '</div>' +
        '</div>';
        $('#blankInputsContainer').append(rowHtml);
        reindexBlankRows();
        debouncedLivePreview();
    }

    function reindexBlankRows() {
        $('#blankInputsContainer .blank-row').each(function(i) {
            var num = i + 1;
            $(this).attr('data-blank-idx', num);
            $(this).find('.blank-badge').html('<i class="fas fa-pen-alt mr-1"></i>Blank ' + num);
            $(this).find('.blank-answer-input').attr('placeholder', 'Accepted answer(s) for Blank ' + num + ' (e.g. Mitochondria | mitochondrion)');
            var removeBtn = $(this).find('.btn-remove-blank');
            if (num === 1) {
                removeBtn.remove();
            } else if (!removeBtn.length) {
                $(this).find('.d-flex').append('<button type="button" class="btn btn-xs btn-outline-danger font-weight-bold btn-remove-blank py-0 px-2 rounded-pill"><i class="fas fa-times mr-1"></i>Remove</button>');
            }
        });
    }

    // Remove the N-th [blank] placeholder from the Summernote prompt when user removes that blank
    function removeNthBlankFromPrompt(n) {
        var code = $('textarea[name="question_text"]').summernote('code') || '';
        var count = 0;
        var pattern = /\[blank(?:\s*|_|-)?(?:\d*)\]/gi;
        var newCode = code.replace(pattern, function(match) {
            count++;
            if (count === n) {
                return '';
            }
            return match;
        });
        if (count >= n) {
            isModifyingBlanks = true;
            $('textarea[name="question_text"]').summernote('code', newCode);
            setTimeout(function() { isModifyingBlanks = false; }, 300);
        }
    }

    // Auto-detect blanks from question prompt only when user types in editor
    function syncBlanksFromPrompt() {
        if (isModifyingBlanks) return;
        var promptCode = $('textarea[name="question_text"]').summernote('code') || '';
        var matches = promptCode.match(/\[blank(?:\s*|_|-)?(?:\d*)\]/gi);
        var detectedCount = matches ? matches.length : 1;
        var currentCount = getBlankInputsCount();
        if (detectedCount > currentCount) {
            for (var i = currentCount; i < detectedCount; i++) {
                addBlankRow('');
            }
        }
    }

    $('#btnAddAnotherBlank').click(function() {
        isModifyingBlanks = true;
        $('textarea[name="question_text"]').summernote('insertText', ' [blank] ');
        addBlankRow('');
        setTimeout(function() { isModifyingBlanks = false; }, 300);
    });

    $('#btnInsertBlankTag').click(function() {
        isModifyingBlanks = true;
        $('textarea[name="question_text"]').summernote('insertText', ' [blank] ');
        addBlankRow('');
        setTimeout(function() { isModifyingBlanks = false; }, 300);
    });

    $(document).on('click', '.btn-remove-blank', function(e) {
        e.preventDefault();
        var row = $(this).closest('.blank-row');
        var idxToRemove = parseInt(row.attr('data-blank-idx'), 10) || 0;
        if (idxToRemove > 0) {
            removeNthBlankFromPrompt(idxToRemove);
        }
        row.remove();
        reindexBlankRows();
        debouncedLivePreview();
    });

    $(document).on('input', '.blank-answer-input', debouncedLivePreview);

    // --- Live LaTeX & Question Preview Logic ---
    let livePreviewTimer = null;
    function updateLivePreview() {
        $('#previewSpinner').removeClass('d-none');
        $('#previewCheck').addClass('d-none');

        var promptCode = $('textarea[name="question_text"]').summernote('code');
        var cleanPrompt = promptCode ? promptCode.replace(/<[^>]+>/g, '').trim() : '';
        var hasImg = promptCode && promptCode.indexOf('<img') !== -1;

        var qType = $('input[name="question_type"]:checked').val() || 'mcq';

        if (qType === 'fill_blank') {
            $('#livePreviewOptionsRow').addClass('d-none');
            $('#livePreviewBlankContainer').removeClass('d-none');

            // Typeset blank badges into question prompt preview
            var blankCounter = 0;
            var formattedPrompt = (promptCode || '').replace(/\[blank(?:\s*|_|-)?(?:\d*)\]|_{3,}/gi, function() {
                blankCounter++;
                return '<span class="badge badge-light border border-primary text-primary font-weight-bold px-2 py-1 mx-1 shadow-xs" style="font-size: 0.9em;"><i class="fas fa-pen-alt mr-1 text-warning"></i>Blank ' + blankCounter + ': _________</span>';
            });

            if (cleanPrompt.length > 0 || hasImg) {
                $('#livePreviewQuestion').html(formattedPrompt);
            } else {
                $('#livePreviewQuestion').html('<span class="text-muted font-italic">Question prompt preview will render here with blanks and equations formatted...</span>');
            }

            // Render live preview accepted answers & student fields
            var blankBadgesHtml = '';
            var studentFieldsHtml = '';
            var rows = $('#blankInputsContainer .blank-row');
            rows.each(function(i) {
                var num = i + 1;
                var val = $(this).find('.blank-answer-input').val().trim();
                var dispVal = val ? val : 'None specified';
                blankBadgesHtml += '<span class="badge badge-success px-2 py-1 font-weight-bold shadow-sm rounded-pill mr-1 mb-1"><i class="fas fa-check-circle mr-1"></i>Blank ' + num + ': ' + $('<div>').text(dispVal).html() + '</span> ';
                studentFieldsHtml += '<div class="mb-2"><div class="small font-weight-bold text-dark mb-1"><span class="badge badge-primary mr-1">Blank ' + num + '</span>Student Response:</div><input type="text" class="form-control bg-white font-weight-bold text-dark border shadow-xs" disabled placeholder="Students will type response for Blank ' + num + '..."></div>';
            });
            $('#liveBlankBadgesList').html(blankBadgesHtml);
            $('#liveBlankStudentFields').html(studentFieldsHtml);
        } else {
            $('#livePreviewOptionsRow').removeClass('d-none');
            $('#livePreviewBlankContainer').addClass('d-none');

            if (cleanPrompt.length > 0 || hasImg) {
                $('#livePreviewQuestion').html(promptCode);
            } else {
                $('#livePreviewQuestion').html('<span class="text-muted font-italic">Question prompt preview will render here with LaTeX equations formatted...</span>');
            }

            var correctOpt = $('#correct_option_select').val() || $('select[name="correct_option"]').val();
            for (var i = 1; i <= 4; i++) {
                var optVal = $('[name="option' + i + '"]').val().trim();
                var card = $('#liveOptCard' + i);
                var badge = $('#liveCorrectBadge' + i);
                var textSpan = $('#liveOptText' + i);

                if (optVal) {
                    textSpan.css({'white-space': 'pre-wrap', 'word-break': 'break-word'}).text(optVal);
                } else {
                    textSpan.html('<em class="text-muted">Option ' + String.fromCharCode(64 + i) + ' preview</em>');
                }

                if (String(i) === String(correctOpt)) {
                    card.addClass('border-success bg-success bg-opacity-10 text-success font-weight-bold');
                    badge.removeClass('d-none');
                } else {
                    card.removeClass('border-success bg-success bg-opacity-10 text-success font-weight-bold');
                    badge.addClass('d-none');
                }
            }
        }

        if (window.MathJax && window.MathJax.typesetPromise) {
            MathJax.typesetPromise([document.getElementById('liveMathPreviewContainer')]).then(function() {
                $('#previewSpinner').addClass('d-none');
                $('#previewCheck').removeClass('d-none');
            }).catch(function() {
                $('#previewSpinner').addClass('d-none');
                $('#previewCheck').removeClass('d-none');
            });
        } else {
            $('#previewSpinner').addClass('d-none');
            $('#previewCheck').removeClass('d-none');
        }
    }

    function debouncedLivePreview() {
        clearTimeout(livePreviewTimer);
        livePreviewTimer = setTimeout(updateLivePreview, 250);
    }

    // Option card radio button sync with bottom selector
    $('.opt-correct-radio').change(function() {
        var val = $(this).val();
        $('#correct_option_select').val(val).trigger('change');
    });
    $('#correct_option_select').change(function() {
        var val = $(this).val();
        $('.opt-correct-radio[value="' + val + '"]').prop('checked', true);
    });

    // Attach live preview listeners
    $('[name^="option"]').on('input', debouncedLivePreview);
    $('select[name="correct_option"]').on('change', debouncedLivePreview);
    $('#btnRefreshPreview').click(updateLivePreview);

    // Question Type Mode Switching
    function syncQuestionType(type) {
        if (type === 'fill_blank') {
            $('#labelTypeFillBlank').css({'border-color': '#f59e0b', 'background-color': '#fffbeb'});
            $('#labelTypeMCQ').css({'border-color': '#e5e7eb', 'background-color': '#ffffff'});
            $('#mcqOptionsSection, #mcqCorrectSection').slideUp(200);
            $('#fillBlankAnswerSection, #fillBlankPromptHelp, #btnInsertBlankTag').fadeIn(200);

            $('[name^="option"]').prop('required', false);
            $('select[name="correct_option"]').prop('required', false);
            $('.blank-answer-input').prop('required', true);
        } else {
            $('#labelTypeMCQ').css({'border-color': '#3b82f6', 'background-color': '#eff6ff'});
            $('#labelTypeFillBlank').css({'border-color': '#e5e7eb', 'background-color': '#ffffff'});
            $('#mcqOptionsSection, #mcqCorrectSection').slideDown(200);
            $('#fillBlankAnswerSection, #fillBlankPromptHelp, #btnInsertBlankTag').fadeOut(200);

            $('[name^="option"]').prop('required', true);
            $('select[name="correct_option"]').prop('required', true);
            $('.blank-answer-input').prop('required', false);
        }
        updateLivePreview();
    }

    $('input[name="question_type"]').change(function() {
        syncQuestionType($(this).val());
    });
    syncQuestionType($('input[name="question_type"]:checked').val());

    // Insert [blank] tag into Summernote
    $('#btnInsertBlankTag').click(function() {
        $('textarea[name="question_text"]').summernote('insertText', ' [blank] ');
        addBlankRow('');
    });

    // --- LaTeX Quick Toolbar Buttons (above prompt) ---
    $('.quick-latex-insert').click(function() {
        var latex = $(this).data('latex');
        $('textarea[name="question_text"]').summernote('insertText', latex);
        debouncedLivePreview();
    });

    // --- LaTeX Modal Target Management ---
    function setLatexTarget(target) {
        activeLatexTarget = target;
        var labels = {
            'prompt': 'Question Prompt',
            'opt1': 'Option A',
            'opt2': 'Option B',
            'opt3': 'Option C',
            'opt4': 'Option D'
        };
        $('#currentLatexTargetLabel').text(labels[target] || 'Question Prompt');
        $('.insert-target-btn').removeClass('btn-primary').addClass('btn-outline-dark');
        $('.insert-target-btn[data-target="' + target + '"]').removeClass('btn-outline-dark').addClass('btn-primary');
    }

    $('#btnOpenFormulaModalPrompt, #btnMoreLatexPrompt').click(function() {
        setLatexTarget('prompt');
    });

    $('.btn-opt-latex').click(function() {
        var target = $(this).data('target-opt');
        setLatexTarget(target);
        $('#latexFormulaModal').modal('show');
    });

    // --- LaTeX Modal Symbol Palettes & Preview ---
    $('.palette-btn').click(function() {
        var code = $(this).data('code');
        var input = document.getElementById('modalLatexInput');
        var start = input.selectionStart || input.value.length;
        var end = input.selectionEnd || input.value.length;
        var text = input.value;
        input.value = text.substring(0, start) + code + text.substring(end);
        input.selectionStart = input.selectionEnd = start + code.length;
        $(input).trigger('input').focus();
    });

    function updateModalLatexPreview() {
        var val = $('#modalLatexInput').val().trim();
        var renderBox = $('#modalLatexLiveRender');
        if (!val) {
            renderBox.html('<span class="text-muted font-weight-normal small">Formula will render here live...</span>');
            return;
        }
        var displayVal = val;
        if (!displayVal.startsWith('$')) {
            displayVal = '$' + displayVal + '$';
        }
        renderBox.text(displayVal);
        if (window.MathJax && window.MathJax.typesetPromise) {
            MathJax.typesetPromise([document.getElementById('modalLatexLiveRender')]);
        }
    }

    let modalDebounceTimer = null;
    $('#modalLatexInput').on('input', function() {
        clearTimeout(modalDebounceTimer);
        modalDebounceTimer = setTimeout(updateModalLatexPreview, 120);
    });

    $('#btnWrapInline').click(function() {
        var input = $('#modalLatexInput');
        var val = input.val().trim();
        if (!val) return;
        val = val.replace(/^\$+|\$+$/g, '');
        input.val('$' + val + '$').trigger('input');
    });

    $('#btnWrapDisplay').click(function() {
        var input = $('#modalLatexInput');
        var val = input.val().trim();
        if (!val) return;
        val = val.replace(/^\$+|\$+$/g, '');
        input.val('$$' + val + '$$').trigger('input');
    });

    $('#btnClearFormula').click(function() {
        $('#modalLatexInput').val('').trigger('input');
    });

    $('#btnCopyLatex').click(function() {
        var val = $('#modalLatexInput').val().trim();
        if (!val) return;
        if (!val.startsWith('$')) val = '$' + val + '$';
        navigator.clipboard.writeText(val).then(function() {
            var btn = $('#btnCopyLatex');
            var orig = btn.html();
            btn.html('<i class="fas fa-check mr-1 text-success"></i>Copied!');
            setTimeout(function() { btn.html(orig); }, 1500);
        });
    });

    // Insert into selected target
    $('.insert-target-btn').click(function() {
        var target = $(this).data('target') || activeLatexTarget;
        var code = $('#modalLatexInput').val().trim();
        if (!code) {
            alert('Please enter or select a formula first.');
            return;
        }
        if (!code.startsWith('$')) {
            code = '$' + code + '$';
        }

        if (target === 'prompt') {
            $('textarea[name="question_text"]').summernote('insertText', ' ' + code + ' ');
        } else if (target && target.startsWith('opt')) {
            var optNum = target.replace('opt', '');
            var optInput = $('[name="option' + optNum + '"]');
            var curVal = optInput.val();
            if (curVal.trim().length > 0) {
                optInput.val(curVal + ' ' + code);
            } else {
                optInput.val(code);
            }
            optInput.trigger('input');
        }

        debouncedLivePreview();
        $('#latexFormulaModal').modal('hide');
    });

    // Subject -> Topic cascading for main form
    $('#subject_select').change(function() {
        var sub = $(this).val();
        $('#topic_select').val('');
        $('#topic_select option').each(function() {
            if($(this).val() === '') return;
            if($(this).data('subject') == sub) $(this).show();
            else $(this).hide();
        });
    });

    // Subject -> Topic cascading for modal
    $('#modal_ai_subject').change(function() {
        var sub = $(this).val();
        $('#modal_ai_topic').val('');
        $('#modal_ai_topic option').each(function() {
            if($(this).val() === '') return;
            if(!sub || $(this).data('subject') == sub) $(this).show();
            else $(this).hide();
        });
    });

    // Toggle API Key eye view
    $('#toggleModalKeyEye').click(function() {
        var input = $('#modal_ai_api_key');
        var icon = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    // Toggle Key Override section
    $('#toggleKeyOverride').click(function() {
        $('#keyOverrideWrapper').toggleClass('d-none');
    });

    // Sync modal with main form on open
    $('#aiGenerateModal').on('show.bs.modal', function() {
        var classVal = $('select[name="class_id"]').val();
        var boardVal = $('select[name="board_id"]').val();
        var subjVal = $('#subject_select').val();
        var topicVal = $('#topic_select').val();
        var marksVal = $('input[name="marks"]').val();

        if (classVal) $('#modal_ai_class').val(classVal);
        if (boardVal) $('#modal_ai_board').val(boardVal);
        if (subjVal) {
            $('#modal_ai_subject').val(subjVal).trigger('change');
            if (topicVal) $('#modal_ai_topic').val(topicVal);
        }
        if (marksVal) $('#modal_ai_marks').val(marksVal);
    });

    // Check URL parameters for ?ai=1 auto-trigger
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('ai') === '1') {
        $('#aiGenerateModal').modal('show');
    }

    // AI Generation Handler
    function runAiGeneration() {
        var apiKey = $('#modal_ai_api_key').val().trim();
        var classId = $('#modal_ai_class').val() || $('select[name="class_id"]').val();
        var boardId = $('#modal_ai_board').val() || $('select[name="board_id"]').val();
        var subjectId = $('#modal_ai_subject').val() || $('#subject_select').val();
        var topicId = $('#modal_ai_topic').val() || $('#topic_select').val();
        var difficulty = $('#modal_ai_difficulty').val();
        var marks = $('#modal_ai_marks').val() || 1;
        var model = $('#modal_ai_model').val();
        var prompt = $('#modal_ai_prompt').val().trim();
        var saveKey = $('#modal_ai_save_key').is(':checked') ? 1 : 0;

        $('#aiErrorAlert').addClass('d-none');
        $('#aiPreviewContainer').addClass('d-none');
        $('#btnAiGenerate').prop('disabled', true);
        $('#aiLoadingIndicator').removeClass('d-none');

        $.ajax({
            url: 'ai_generate_ajax.php',
            method: 'POST',
            data: {
                csrf_token: '<?php echo generate_csrf_token(); ?>',
                api_key: apiKey,
                class_id: classId,
                board_id: boardId,
                subject_id: subjectId,
                topic_id: topicId,
                question_type: $('#modal_ai_type').val() || 'mcq',
                difficulty: difficulty,
                marks: marks,
                model: model,
                prompt: prompt,
                save_key: saveKey
            },
            dataType: 'json',
            timeout: 90000,
            success: function(res) {
                $('#aiLoadingIndicator').addClass('d-none');
                $('#btnAiGenerate').prop('disabled', false);

                if (res.success && res.data) {
                    lastGeneratedData = res.data;
                    renderAiPreview(res.data);
                } else {
                    $('#aiErrorText').text(res.error || 'Failed to generate question. Please verify your OpenRouter API key and model.');
                    $('#aiErrorAlert').removeClass('d-none');
                }
            },
            error: function(xhr, textStatus, errorThrown) {
                $('#aiLoadingIndicator').addClass('d-none');
                $('#btnAiGenerate').prop('disabled', false);
                var errMsg = 'Network or server error occurred during AI generation.';
                if (textStatus === 'timeout') {
                    errMsg = 'Request timed out waiting for OpenRouter response. Please try again or select a faster model (e.g. Gemini 2.0 Flash).';
                } else {
                    try {
                        if (xhr.responseText) {
                            var parsed = JSON.parse(xhr.responseText);
                            if (parsed.error) errMsg = parsed.error;
                        }
                    } catch(e) {
                        if (xhr.status) {
                            errMsg += ' (HTTP ' + xhr.status + (errorThrown ? ': ' + errorThrown : '') + ')';
                        }
                    }
                }
                $('#aiErrorText').text(errMsg);
                $('#aiErrorAlert').removeClass('d-none');
            }
        });
    }

    function renderAiPreview(data) {
        $('#aiModelBadge').text('Model: ' + (data.model_used || $('#modal_ai_model').val()));
        $('#aiPreviewQuestionText').html(data.question_text);
        
        var isAiFill = (data.question_type === 'fill_blank');
        if (isAiFill) {
            $('#aiPreviewOptionsRow').addClass('d-none');
            var accepted = data.correct_answer || data.correct_answer_text || '';
            if (!$('#aiPreviewBlankBox').length) {
                $('#aiPreviewQuestionText').after('<div id="aiPreviewBlankBox" class="p-3 bg-light rounded border border-success mb-3"><div class="small font-weight-bold text-success text-uppercase mb-1"><i class="fas fa-check-circle mr-1"></i>Accepted Blank Answer:</div><div class="h5 font-weight-bold text-dark mb-0" id="aiPreviewBlankAns"></div></div>');
            }
            $('#aiPreviewBlankAns').text(accepted);
            $('#aiPreviewBlankBox').removeClass('d-none');
        } else {
            $('#aiPreviewOptionsRow').removeClass('d-none');
            $('#aiPreviewBlankBox').addClass('d-none');
            for (var i = 1; i <= 4; i++) {
                var rawOpt = data['option' + i] || '';
                if (rawOpt.indexOf('<') !== -1) {
                    $('#preview_opt_text_' + i).html(rawOpt);
                } else {
                    $('#preview_opt_text_' + i).text(rawOpt);
                }
                var card = $('#preview_opt_' + i);
                var badge = $('#preview_badge_' + i);
                if (i === data.correct_option) {
                    card.addClass('border-success bg-success bg-opacity-10 font-weight-bold text-success');
                    badge.removeClass('d-none');
                } else {
                    card.removeClass('border-success bg-success bg-opacity-10 font-weight-bold text-success');
                    badge.addClass('d-none');
                }
            }
        }

        if (data.explanation) {
            $('#aiPreviewExplanation').text(data.explanation);
            $('#aiPreviewExplanationBox').removeClass('d-none');
        } else {
            $('#aiPreviewExplanationBox').addClass('d-none');
        }

        $('#aiPreviewContainer').removeClass('d-none');
        if (window.MathJax && window.MathJax.typesetPromise) {
            MathJax.typesetPromise([document.getElementById('aiPreviewContainer')]);
        }
    }

    $('#btnAiGenerate').click(runAiGeneration);
    $('#btnAiRegenerate').click(runAiGeneration);

    // Apply AI Question to Form
    $('#btnAiApply').click(function() {
        if (!lastGeneratedData) return;

        // Populate Summernote
        $('textarea[name="question_text"]').summernote('code', lastGeneratedData.question_text);

        if (lastGeneratedData.question_type === 'fill_blank') {
            $('#type_fill_blank').prop('checked', true);
            syncQuestionType('fill_blank');
            $('#blankInputsContainer').empty();
            var answersList = [];
            if (Array.isArray(lastGeneratedData.correct_answers)) {
                answersList = lastGeneratedData.correct_answers;
            } else if (lastGeneratedData.correct_answer) {
                answersList = [lastGeneratedData.correct_answer];
            } else if (lastGeneratedData.correct_answer_text) {
                try {
                    var parsed = JSON.parse(lastGeneratedData.correct_answer_text);
                    if (Array.isArray(parsed)) answersList = parsed;
                    else answersList = [lastGeneratedData.correct_answer_text];
                } catch(e) {
                    answersList = [lastGeneratedData.correct_answer_text];
                }
            }
            if (answersList.length === 0) answersList = [''];
            for (var bi = 0; bi < answersList.length; bi++) {
                addBlankRow(answersList[bi]);
            }
        } else {
            $('#type_mcq').prop('checked', true);
            syncQuestionType('mcq');
            $('[name="option1"]').val(lastGeneratedData.option1);
            $('[name="option2"]').val(lastGeneratedData.option2);
            $('[name="option3"]').val(lastGeneratedData.option3);
            $('[name="option4"]').val(lastGeneratedData.option4);
            $('#correct_option_select').val(lastGeneratedData.correct_option).trigger('change');
        }

        // Populate Marks
        if (lastGeneratedData.marks) {
            $('input[name="marks"]').val(lastGeneratedData.marks);
        }

        // Apply metadata if set in modal
        var mClass = $('#modal_ai_class').val();
        if (mClass) $('select[name="class_id"]').val(mClass);

        var mBoard = $('#modal_ai_board').val();
        if (mBoard) $('select[name="board_id"]').val(mBoard);

        var mSubject = $('#modal_ai_subject').val();
        if (mSubject) {
            $('#subject_select').val(mSubject).trigger('change');
            var mTopic = $('#modal_ai_topic').val();
            if (mTopic) $('#topic_select').val(mTopic);
        }

        // Close modal
        $('#aiGenerateModal').modal('hide');

        // Refresh live preview with new question data
        updateLivePreview();

        // Scroll to form smoothly
        $('html, body').animate({
            scrollTop: $('textarea[name="question_text"]').offset().top - 100
        }, 400);
    });

    // Initialize topics if sticky subject exists
    var initSub = $('#subject_select').val();
    if (initSub) {
        var stickyTopic = '<?php echo $sel_topic_id; ?>';
        $('#topic_select option').each(function() {
            if($(this).val() === '') return;
            if($(this).data('subject') == initSub) $(this).show();
            else $(this).hide();
        });
        if (stickyTopic) {
            $('#topic_select').val(stickyTopic);
        }
    }

    // Initial run for live preview
    setTimeout(updateLivePreview, 400);
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>


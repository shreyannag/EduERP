<?php
// student/exam.php
require_once '../includes/student_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

$student_id = (int)$_SESSION['student_id'];
$exam_id = intval($_GET['id'] ?? 0);

if (!$exam_id) die("Invalid endpoint mapping trace.");

$stmt = $pdo->prepare("
    SELECT e.*, a.institute_name FROM exams e
    JOIN enrolments en ON e.id = en.exam_id
    LEFT JOIN admins a ON e.admin_id = a.id
    WHERE en.student_id = ? AND e.id = ? AND e.status = 'published'
");
$stmt->execute([$student_id, $exam_id]);
$exam = $stmt->fetch();

if (!$exam) die("Assessment node unreachably mapped or status rendered invalid.");

$now_ts = time();
$start_ts = strtotime($exam['scheduled_date'].' '.$exam['scheduled_time']);
$end_ts = $start_ts + ($exam['duration_minutes'] * 60);

if ($now_ts < $start_ts) die("This exam has not started yet.");
if ($now_ts > $end_ts) die("This exam has already ended.");

// Check attempt state: strict guard against re-entering a submitted exam
$stmt_submitted = $pdo->prepare("SELECT id FROM attempts WHERE student_id = ? AND exam_id = ? AND is_submitted = 1 ORDER BY id DESC LIMIT 1");
$stmt_submitted->execute([$student_id, $exam_id]);
if ($stmt_submitted->fetch()) {
    $_SESSION['flash_info'] = "You have already completed and submitted this examination.";
    header("Location: dashboard.php?tab=completed");
    exit;
}

// Fetch active in-progress attempt (if any)
$stmt_attempt = $pdo->prepare("SELECT * FROM attempts WHERE student_id = ? AND exam_id = ? AND is_submitted = 0 ORDER BY id DESC LIMIT 1");
$stmt_attempt->execute([$student_id, $exam_id]);
$attempt = $stmt_attempt->fetch();

if (!$attempt) {
    // Generate scale constraint config
    $stmt_qmeta = $pdo->prepare("
        SELECT COUNT(eq.id) as q_count, COALESCE(SUM(q.marks), COUNT(eq.id)) as total_marks 
        FROM exam_questions eq 
        JOIN questions q ON eq.question_id = q.id 
        WHERE eq.exam_id = ?
    ");
    $stmt_qmeta->execute([$exam_id]);
    $meta = $stmt_qmeta->fetch();
    $total_q = (int)($meta['q_count'] ?? 0);
    $total_m = (int)($meta['total_marks'] ?? $total_q);
    
    // Mount initial buffer with race-condition safety
    try {
        $stmt_ins = $pdo->prepare("INSERT INTO attempts (student_id, exam_id, total_questions, total_marks, started_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt_ins->execute([$student_id, $exam_id, $total_q, $total_m]);
        $attempt_id = (int)$pdo->lastInsertId();
    } catch (\PDOException $e) {
        // Fallback if concurrent insert occurred in another tab/request
        $stmt_attempt = $pdo->prepare("SELECT * FROM attempts WHERE student_id = ? AND exam_id = ? AND is_submitted = 0 ORDER BY id DESC LIMIT 1");
        $stmt_attempt->execute([$student_id, $exam_id]);
        $attempt = $stmt_attempt->fetch();
        $attempt_id = $attempt ? (int)$attempt['id'] : 0;
    }
} else {
    $attempt_id = (int)$attempt['id'];
}

// Fetch questions
$stmt_qs = $pdo->prepare("
    SELECT q.*, eq.order_no 
    FROM questions q
    JOIN exam_questions eq ON q.id = eq.question_id
    WHERE eq.exam_id = ?
    ORDER BY eq.order_no ASC
");
$stmt_qs->execute([$exam_id]);
$questions = $stmt_qs->fetchAll();

// Fetch already saved answers for this attempt from database (real-time resilience)
$saved_answers = [];
$saved_text_answers = [];
if ($attempt_id > 0) {
    $stmt_ans = $pdo->prepare("SELECT question_id, chosen_option, answer_text FROM attempt_answers WHERE attempt_id = ?");
    $stmt_ans->execute([$attempt_id]);
    $raw_saved = $stmt_ans->fetchAll(PDO::FETCH_ASSOC);
    foreach ($raw_saved as $r) {
        $qid = (int)$r['question_id'];
        if ($r['chosen_option'] !== null) $saved_answers[$qid] = (int)$r['chosen_option'];
        if ($r['answer_text'] !== null && trim($r['answer_text']) !== '') {
            $parsed = parse_student_fill_blank_answers($r['answer_text']);
            $hasContent = false;
            foreach ($parsed as $v) {
                if (trim((string)$v) !== '') { $hasContent = true; break; }
            }
            if ($hasContent) {
                $saved_text_answers[$qid] = $r['answer_text'];
            }
        }
    }
}
$total_answered_initial = count($saved_answers) + count($saved_text_answers);

$remaining_seconds = max(0, $end_ts - $now_ts);
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Exam - <?php echo h($exam['title']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- MathJax v3 for mathematical equations -->
    <script>
    window.MathJax = {
        tex: {
            inlineMath: [['$', '$'], ['\\(', '\\)']],
            displayMath: [['$$', '$$'], ['\\[', '\\]']],
            processEscapes: true,
            processEnvironments: true
        },
        options: {
            skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code']
        }
    };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js"></script>
    <style>
        html {
            overflow-y: scroll;
            scrollbar-gutter: stable;
        }
        body { background-color: #f3f4f6; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; padding-top: 85px; padding-bottom: 50px; }
        .exam-header { background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.05); position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; align-items: center; padding: 12px 24px; border-bottom: 1px solid #e5e7eb; }
        .timer-box { font-family: monospace; font-size: 1.45rem; font-weight: bold; background: #fff0f2; color: #dc2626; padding: 6px 18px; border-radius: 999px; border: 1px solid #fecdd3; display: inline-flex; align-items: center; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
        .q-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 25px; border: 1px solid #e5e7eb; overflow: hidden; }
        .q-header { background: #f9fafb; padding: 20px 25px; border-bottom: 1px solid #e5e7eb; font-weight: 500; color: #1f2937; display: flex; align-items: flex-start; font-size: 1.05rem; }
        .q-num { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: #fff; padding: 5px 12px; border-radius: 6px; margin-right: 16px; font-size: 0.9rem; font-weight: bold; box-shadow: 0 2px 5px rgba(59,130,246,0.3); flex-shrink: 0; }
        .q-body { padding: 25px; }
        .opt-row { margin-bottom: 15px; }
        .opt-label { cursor: pointer; display: flex; align-items: center; padding: 16px 20px; border: 2px solid #e5e7eb; border-radius: 10px; transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, color 0.2s ease !important; background: #fff; margin: 0; width: 100%; font-weight: 500; color: #4b5563; }
        .opt-label:hover { border-color: #cbd5e1; background: #f8fafc; }
        .custom-control-input:focus ~ .opt-label { box-shadow: 0 0 0 3px rgba(59,130,246,0.3); border-color: #93c5fd; }
        .custom-control-input:checked ~ .opt-label { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; font-weight: 600; box-shadow: 0 2px 8px rgba(37,99,235,0.1); }
        .custom-control-input:checked ~ .opt-label .letter { background: #2563eb; color: #fff; border-color: #2563eb; }
        .letter { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 2px solid #cbd5e1; border-radius: 50%; margin-right: 18px; font-size: 0.9rem; background: #f8fafc; color: #64748b; font-weight: bold; flex-shrink: 0; transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease !important; }
        .nav-sidebar { position: sticky; top: 110px; max-height: calc(100vh - 130px); overflow-y: auto; background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .q-btn { width: 42px; height: 42px; margin: 0 6px 12px 6px; border-radius: 8px; font-weight: bold; font-family: monospace; border-width: 2px; }
        .q-btn:hover { background: #f1f5f9; }
        .q-btn.answered { background: #10b981 !important; color: white !important; border-color: #059669 !important; }
        .q-btn.flagged { background: #f59e0b !important; color: white !important; border-color: #d97706 !important; }
        .q-btn.answered.flagged { background: linear-gradient(135deg, #10b981 50%, #f59e0b 50%) !important; border-color: #d97706 !important; color: white !important; }
        .q-btn.unanswered { border-color: #cbd5e1; color: #64748b; background: #f8fafc; }
        .q-text-content { flex-grow: 1; min-width: 0; line-height: 1.6; }
        .q-text-content img { max-width: 100%; height: auto; border-radius: 6px; display: block; margin: 12px 0; }
        .save-badge { font-size: 0.85rem; padding: 5px 12px; border-radius: 999px; transition: all 0.3s; }
        @media (max-width: 767.98px) {
            body { padding-top: 75px !important; padding-bottom: 80px !important; }
            .exam-header { padding: 8px 12px !important; }
            .timer-box { font-size: 1.15rem !important; padding: 4px 10px !important; }
            .timer-box i { margin-right: 6px !important; }
            .q-card { margin-bottom: 18px; border-radius: 10px; }
            .q-header { padding: 14px !important; font-size: 0.98rem !important; }
            .q-num { padding: 4px 9px; font-size: 0.82rem; margin-right: 10px; }
            .q-body { padding: 14px !important; }
            .opt-row { margin-bottom: 10px; }
            .opt-label { padding: 12px 14px !important; font-size: 0.92rem; }
            .letter { width: 28px !important; height: 28px !important; font-size: 0.82rem !important; margin-right: 12px !important; }
            .floating-palette-btn {
                position: fixed;
                bottom: 20px;
                right: 20px;
                width: 54px;
                height: 54px;
                border-radius: 50%;
                background: #1e3a8a;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 6px 20px rgba(0,0,0,0.3);
                z-index: 1040;
                border: 2px solid #ffffff;
                cursor: pointer;
                transition: transform 0.2s;
            }
            .floating-palette-btn:active {
                transform: scale(0.92);
            }
        }
    </style>
</head>
<body>

<form method="POST" action="submit.php" id="examForm">
    <input type="hidden" name="csrf_token" id="formCsrfToken" value="<?php echo h($csrf_token); ?>">
    <input type="hidden" name="exam_id" id="formExamId" value="<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="attempt_id" id="formAttemptId" value="<?php echo $attempt_id; ?>">

    <!-- Top Sticky Exam Navigation Header -->
    <div class="exam-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <div>
                <div class="small font-weight-bold text-primary mb-0">
                    <i class="fas fa-university mr-1"></i><?php echo h(!empty($exam['institute_name']) ? $exam['institute_name'] : 'Main Campus'); ?>
                </div>
                <h6 class="mb-0 font-weight-bold text-dark d-none d-md-block" style="letter-spacing: -0.3px;"><?php echo h($exam['title']); ?></h6>
                <div class="small text-muted font-weight-bold d-none d-sm-block"><i class="fas fa-user-circle mr-1 opacity-50"></i><?php echo h($_SESSION['student_user'] ?? 'Student'); ?></div>
            </div>
        </div>

        <div class="timer-box shadow-sm mx-2">
            <i class="fas fa-clock mr-2 opacity-50"></i> <span id="timeDisplay" style="letter-spacing: 2px;">00:00:00</span>
        </div>

        <div class="d-flex align-items-center">
            <!-- Real-time auto-save indicator badge -->
            <span id="saveStatusBadge" class="save-badge bg-light border text-muted mr-3 d-none d-md-inline-block font-weight-bold">
                <i class="fas fa-cloud text-primary mr-1"></i><span id="saveStatusText">All Answers Saved</span>
            </span>
            <button type="button" class="btn btn-outline-primary font-weight-bold px-3 py-2 rounded-pill shadow-sm transition" onclick="openSubmitModal()">
                <i class="fas fa-check-circle mr-1"></i><span class="d-none d-sm-inline">Finish </span>Submit
            </button>
        </div>
    </div>

    <!-- Questions Content Area -->
    <div class="container-fluid px-md-5 mt-4">
        <div class="row">
            <div class="col-lg-9 pr-lg-5">
                <?php foreach($questions as $index => $q): 
                    $qNum = $index + 1;
                    $savedOpt = (int)($saved_answers[$q['id']] ?? 0);
                ?>
                <div class="q-card" id="q<?php echo $qNum; ?>">
                    <div class="q-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center flex-grow-1 pr-3">
                            <span class="q-num">Q<?php echo $qNum; ?></span>
                            <div class="q-text-content"><?php echo render_formatted_content(($q['question_type'] ?? 'mcq') === 'fill_blank' ? format_blank_prompt($q['question_text']) : $q['question_text']); ?></div>
                        </div>
                        <div class="flex-shrink-0 text-right">
                            <button type="button" class="btn btn-sm btn-outline-warning text-dark font-weight-bold rounded-pill px-3 shadow-sm btn-flag-q" id="flagBtn<?php echo $qNum; ?>" onclick="openFlagModal(<?php echo $q['id']; ?>, <?php echo $qNum; ?>)">
                                <i class="fas fa-flag mr-1 text-warning"></i><span class="flag-text">Flag Doubt</span>
                            </button>
                        </div>
                    </div>
                    <?php if (($q['question_type'] ?? 'mcq') === 'fill_blank'): 
                        $savedRaw = $saved_text_answers[$q['id']] ?? '';
                        $parsedSaved = parse_student_fill_blank_answers($savedRaw);
                        $totalBlanks = get_question_total_blanks($q['question_text'], $q['correct_answer_text'] ?? '');
                    ?>
                    <div class="q-body px-4 pb-3">
                        <div class="p-4 bg-light rounded-lg border border-primary shadow-sm">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="font-weight-bold text-dark small text-uppercase mb-0 d-flex align-items-center">
                                    <i class="fas fa-pen-alt text-primary mr-2"></i>Fill in the Blank<?php echo $totalBlanks > 1 ? " ({$totalBlanks} Blanks)" : ''; ?>
                                </label>
                                <span class="badge badge-success px-3 py-1 font-weight-bold rounded-pill shadow-sm"><i class="fas fa-spell-check mr-1"></i>Case Insensitive</span>
                            </div>
                            <p class="text-muted small mb-3">Type your response below. Your progress auto-saves continuously to the cloud.</p>
                            
                            <?php if ($totalBlanks > 1): ?>
                                <div class="row">
                                    <?php for ($bi = 1; $bi <= $totalBlanks; $bi++): 
                                        $val = $parsedSaved[$bi] ?? '';
                                    ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="p-3 bg-white rounded border shadow-xs">
                                                <div class="small font-weight-bold text-primary mb-2 d-flex align-items-center">
                                                    <span class="badge badge-primary mr-2 px-2 py-1">Blank <?php echo $bi; ?></span> Enter response:
                                                </div>
                                                <div class="input-group">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text bg-light border-right-0 text-primary"><i class="fas fa-keyboard"></i></span>
                                                    </div>
                                                    <input type="text" 
                                                           name="text_answers[<?php echo $q['id']; ?>][<?php echo $bi; ?>]" 
                                                           id="q_fill_<?php echo $q['id']; ?>_<?php echo $bi; ?>" 
                                                           class="form-control form-control-lg border-left-0 bg-white font-weight-bold text-dark q-fill-input" 
                                                           placeholder="Type response for Blank <?php echo $bi; ?>..."
                                                           value="<?php echo h($val); ?>"
                                                           data-qnum="<?php echo $qNum; ?>"
                                                           data-qid="<?php echo $q['id']; ?>"
                                                           data-blank-idx="<?php echo $bi; ?>"
                                                           autocomplete="off">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            <?php else: ?>
                                <?php $val = $parsedSaved[1] ?? (is_string($savedRaw) ? $savedRaw : ''); ?>
                                <div class="input-group input-group-lg shadow-sm">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-white border-right-0 text-primary"><i class="fas fa-keyboard"></i></span>
                                    </div>
                                    <input type="text" 
                                           name="text_answers[<?php echo $q['id']; ?>][1]" 
                                           id="q_fill_<?php echo $q['id']; ?>" 
                                           class="form-control form-control-lg border-left-0 bg-white font-weight-bold text-dark q-fill-input" 
                                           placeholder="Type your answer here..."
                                           value="<?php echo h($val); ?>"
                                           data-qnum="<?php echo $qNum; ?>"
                                           data-qid="<?php echo $q['id']; ?>"
                                           data-blank-idx="1"
                                           autocomplete="off">
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="q-body row mx-0">
                        <?php for($i=1; $i<=4; $i++): 
                            $optText = $q['option'.$i]; 
                            if ($optText === null || trim((string)$optText) === '') continue;
                            $identifier = "q_{$q['id']}_opt_{$i}";
                            $isChecked = ($savedOpt === $i) ? 'checked' : '';
                        ?>
                        <div class="col-md-6 opt-row px-2">
                            <div class="custom-control custom-radio p-0">
                                <input type="radio" class="custom-control-input q-radio d-none" name="answers[<?php echo $q['id']; ?>]" id="<?php echo $identifier; ?>" value="<?php echo $i; ?>" data-qnum="<?php echo $qNum; ?>" data-qid="<?php echo $q['id']; ?>" <?php echo $isChecked; ?>>
                                <label class="opt-label shadow-sm" for="<?php echo $identifier; ?>">
                                    <span class="letter shadow-sm"><?php echo chr(64+$i); ?></span>
                                    <span class="flex-grow-1"><?php echo render_formatted_content($optText); ?></span>
                                </label>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Question footer navigation bar -->
                    <div class="q-card-footer d-flex justify-content-between align-items-center bg-light px-4 py-2 border-top">
                        <div>
                            <?php if ($qNum > 1): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary font-weight-bold rounded-pill px-3" onclick="scrollToQuestion(<?php echo $qNum - 1; ?>)">
                                    <i class="fas fa-arrow-left mr-1"></i> Prev
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="small font-weight-bold text-muted">
                            Question <?php echo $qNum; ?> of <?php echo count($questions); ?>
                        </div>
                        <div>
                            <?php if ($qNum < count($questions)): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3" onclick="scrollToQuestion(<?php echo $qNum + 1; ?>)">
                                    Next <i class="fas fa-arrow-right ml-1"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-success font-weight-bold rounded-pill px-3 shadow-sm" onclick="openSubmitModal()">
                                    <i class="fas fa-check-circle mr-1"></i> Review & Submit
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Dedicated End of Examination Card -->
                <div class="card shadow-sm border-0 rounded-lg p-4 mb-5 text-center bg-white border-top border-primary" style="border-top-width: 4px !important;">
                    <h5 class="font-weight-bold text-dark mb-2">Reached the End of Examination</h5>
                    <p class="text-muted small mb-3">All your selected answers have been saved to the server. You can review any question before final submission.</p>
                    <div class="d-flex justify-content-center">
                        <button type="button" class="btn btn-primary btn-lg font-weight-bold rounded-pill px-5 shadow-sm" onclick="openSubmitModal()">
                            <i class="fas fa-paper-plane mr-2"></i>Review & Submit Exam
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Question Navigator Sidebar (Desktop) -->
            <div class="col-lg-3 d-none d-lg-block">
                <div class="nav-sidebar">
                    <h6 class="font-weight-bold text-uppercase text-muted mb-4 border-bottom pb-3"><i class="fas fa-map-signs mr-2 text-primary"></i>Question Navigator</h6>
                    <div class="d-flex flex-wrap" id="qNavContainer">
                        <?php for($i=1; $i<=count($questions); $i++): 
                            $qIdCurrent = $questions[$i-1]['id'];
                            $isAns = !empty($saved_answers[$qIdCurrent]) || !empty($saved_text_answers[$qIdCurrent]);
                            $btnClass = $isAns ? 'answered shadow text-white' : 'unanswered';
                        ?>
                            <a href="#q<?php echo $i; ?>" class="btn q-btn <?php echo $btnClass; ?> border text-center p-0 d-flex align-items-center justify-content-center shadow-sm" id="nav_btn_<?php echo $i; ?>" onclick="scrollToQuestion(<?php echo $i; ?>); return false;"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <div class="mt-4 pt-4 border-top small text-muted font-weight-bold text-uppercase">
                        <div class="mb-3 d-flex align-items-center"><span class="d-inline-block bg-success rounded-circle shadow-sm mr-3" style="width:14px; height:14px;"></span> Answered (<span id="desktopAnsweredBadge"><?php echo $total_answered_initial; ?></span>)</div>
                        <div class="d-flex align-items-center"><span class="d-inline-block bg-light border border-secondary rounded-circle shadow-sm mr-3" style="width:14px; height:14px;"></span> Unanswered (<span id="desktopUnansweredBadge"><?php echo count($questions) - $total_answered_initial; ?></span>)</div>
                    </div>
                    <div class="mt-4 pt-3 border-top text-center">
                        <button type="button" class="btn btn-primary font-weight-bold rounded-pill btn-block shadow-sm" onclick="openSubmitModal()">
                            <i class="fas fa-paper-plane mr-1"></i>Final Submit
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Floating Question Palette Trigger -->
    <div class="floating-palette-btn d-lg-none" data-toggle="modal" data-target="#paletteModal" title="Open Question Palette">
        <i class="fas fa-th-large fa-lg"></i>
        <span class="badge badge-light text-primary position-absolute font-weight-bold shadow-sm" id="mobileFloatingBadge" style="top: -4px; right: -4px; font-size: 0.65rem; border-radius: 10px; padding: 3px 6px;">
            <?php echo $total_answered_initial; ?>/<?php echo count($questions); ?>
        </span>
    </div>

    <!-- Mobile Question Palette Modal -->
    <div class="modal fade" id="paletteModal" tabindex="-1" role="dialog" aria-labelledby="paletteModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" role="document">
            <div class="modal-content shadow-lg border-0 rounded-lg">
                <div class="modal-header bg-light">
                    <h5 class="modal-title font-weight-bold text-dark" id="paletteModalTitle">
                        <i class="fas fa-map-signs text-primary mr-2"></i>Question Navigator
                    </h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom small font-weight-bold">
                        <span class="text-success"><i class="fas fa-check-circle mr-1"></i><span id="mobileAnsweredCount"><?php echo $total_answered_initial; ?></span> Answered</span>
                        <span class="text-muted"><i class="fas fa-circle mr-1"></i><span id="mobileUnansweredCount"><?php echo count($questions) - $total_answered_initial; ?></span> Unanswered</span>
                    </div>
                    <div class="d-flex flex-wrap justify-content-center" id="mobileQNavGrid">
                        <?php for($i=1; $i<=count($questions); $i++): 
                            $qIdCurrent = $questions[$i-1]['id'];
                            $isAns = !empty($saved_answers[$qIdCurrent]) || !empty($saved_text_answers[$qIdCurrent]);
                            $btnClass = $isAns ? 'answered shadow text-white' : 'unanswered';
                        ?>
                            <a href="#q<?php echo $i; ?>" class="btn q-btn <?php echo $btnClass; ?> border text-center p-0 d-flex align-items-center justify-content-center shadow-sm mobile-q-link" id="nav_btn_mobile_<?php echo $i; ?>" onclick="scrollToQuestion(<?php echo $i; ?>); $('#paletteModal').modal('hide'); return false;"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 font-weight-bold" data-dismiss="modal">Back to Exam</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 font-weight-bold shadow-sm" onclick="$('#paletteModal').modal('hide'); openSubmitModal();">
                        Review & Submit <i class="fas fa-paper-plane ml-1"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Dedicated High-Friction Submission Confirmation Modal -->
<div class="modal fade" id="submitConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0 rounded-lg overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 border-0" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important;">
                <h5 class="modal-title font-weight-bold mb-0 text-white">
                    <i class="fas fa-clipboard-check mr-2"></i>Review Before Final Submission
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4 text-center">
                <p class="text-muted small mb-3">Please verify your answered questions summary below before submitting:</p>
                <div class="row text-center mb-3">
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border">
                            <div class="h4 font-weight-bold text-dark mb-0" id="modalTotalQ"><?php echo count($questions); ?></div>
                            <div class="small text-muted font-weight-bold text-uppercase" style="font-size:0.7rem;">Total</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-success">
                            <div class="h4 font-weight-bold text-success mb-0" id="modalAnsweredQ">0</div>
                            <div class="small text-success font-weight-bold text-uppercase" style="font-size:0.7rem;">Answered</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded border border-warning">
                            <div class="h4 font-weight-bold text-warning mb-0" id="modalUnansweredQ"><?php echo count($questions); ?></div>
                            <div class="small text-warning font-weight-bold text-uppercase" style="font-size:0.7rem;">Unanswered</div>
                        </div>
                    </div>
                </div>

                <div id="unansweredWarningAlert" class="alert alert-warning text-left small font-weight-bold mb-3 d-none">
                    <i class="fas fa-exclamation-triangle mr-2 text-warning"></i>
                    <span>Notice: You have <strong id="unansweredWarningCount">0</strong> unanswered question(s). If you submit now, unanswered questions will be marked as skipped.</span>
                </div>

                <p class="small text-muted mb-0 font-weight-500">
                    <i class="fas fa-info-circle mr-1 text-primary"></i>This action cannot be undone. Once submitted, your examination is complete.
                </p>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-between p-3">
                <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">
                    <i class="fas fa-arrow-left mr-1"></i>Return to Questions
                </button>
                <button type="button" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm" id="btnFinalSubmitConfirm" onclick="finalizeAndSubmitExam()">
                    <i class="fas fa-paper-plane mr-1"></i>Yes, Submit Finally
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Flag Doubt Modal -->
<div class="modal fade" id="flagModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0 rounded-lg">
            <div class="modal-header bg-warning text-dark border-0 py-3">
                <h5 class="modal-title font-weight-bold mb-0">
                    <i class="fas fa-flag text-danger mr-2"></i>Flag Question <span id="flagModalQNum"></span> for Review
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="flagForm" onsubmit="handleFlagSubmit(event)">
                <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
                <input type="hidden" name="question_id" id="flagQuestionId">
                <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">
                        If this question is doubtful, ambiguous, has typos, or missing formulas, report it here. Your teacher will review it.
                    </p>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Reason for Doubt / Flag</label>
                        <select name="flag_reason" class="form-control custom-select" required>
                            <option value="Doubtful / Confusing Question">Doubtful / Confusing Question</option>
                            <option value="Typo or Text Error">Typo or Text Error</option>
                            <option value="Incorrect Options / Answer Key">Incorrect Options / Answer Key</option>
                            <option value="Missing Diagram or Formula">Missing Diagram or Formula</option>
                            <option value="Other">Other Query</option>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small text-muted text-uppercase">Your Remarks / Specific Issue (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Explain what seems doubtful or incorrect about this question..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-3" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning font-weight-bold rounded-pill px-4 shadow-sm" id="flagSubmitBtn">
                        <i class="fas fa-flag mr-1"></i>Submit Doubt Flag
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const examId = <?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>;
    const attemptId = <?php echo $attempt_id; ?>;
    const csrfToken = "<?php echo $csrf_token; ?>";
    const lsKey = 'exam_matrix_state_' + examId + '_' + <?php echo $student_id; ?>;
    const totalQuestions = <?php echo count($questions); ?>;
    
    let remainingTime = <?php echo $remaining_seconds; ?>;
    
    // Countdown Timer Engine
    function updateTimer() {
        if (remainingTime <= 0) {
            clearInterval(timerInterval);
            document.getElementById('timeDisplay').innerText = "00:00:00";
            localStorage.removeItem(lsKey);
            alert("Time is up! Submitting your exam automatically.");
            document.getElementById('examForm').submit();
            return;
        }
        
        let hrs = Math.floor(remainingTime / 3600);
        let mins = Math.floor((remainingTime % 3600) / 60);
        let secs = remainingTime % 60;
        
        let dHrs = hrs < 10 ? '0' + hrs : hrs;
        let dMins = mins < 10 ? '0' + mins : mins;
        let dSecs = secs < 10 ? '0' + secs : secs;
        
        document.getElementById('timeDisplay').innerText = dHrs + ":" + dMins + ":" + dSecs;
        remainingTime--;
    }
    
    let timerInterval = setInterval(updateTimer, 1000);
    updateTimer();
    
    // Smooth scrolling to question
    function scrollToQuestion(qNum) {
        let el = document.getElementById('q' + qNum);
        if (el) {
            let offset = 95;
            let bodyRect = document.body.getBoundingClientRect().top;
            let elementRect = el.getBoundingClientRect().top;
            let elementPosition = elementRect - bodyRect;
            let offsetPosition = elementPosition - offset;
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    }

    // Client localStorage sync
    function saveState() {
        let state = {};
        $('.q-radio:checked').each(function() {
            state[$(this).attr('name')] = $(this).val();
        });
        localStorage.setItem(lsKey, JSON.stringify(state));
    }
    
    function restoreState() {
        let saved = localStorage.getItem(lsKey);
        if (saved) {
            try {
                let state = JSON.parse(saved);
                for (let key in state) {
                    let radio = $('input[name="'+key+'"][value="'+state[key]+'"]');
                    if (!radio.is(':checked')) {
                        radio.prop('checked', true);
                        let qNum = radio.data('qnum');
                        updatePalette(qNum);
                    }
                }
            } catch(e){}
        }
        syncCounts();
    }
    
    function updatePalette(qNum) {
        $('#nav_btn_' + qNum).removeClass('unanswered').addClass('answered shadow text-white');
        $('#nav_btn_mobile_' + qNum).removeClass('unanswered').addClass('answered shadow text-white');
        syncCounts();
    }

    function syncCounts() {
        let ansMCQ = $('.q-radio:checked').length;
        let answeredQuestions = {};
        $('.q-fill-input').each(function() {
            let qid = $(this).data('qid');
            if ($(this).val().trim() !== '') {
                answeredQuestions[qid] = true;
            }
        });
        let ansFill = Object.keys(answeredQuestions).length;
        let ans = ansMCQ + ansFill;
        let unans = totalQuestions - ans;
        $('#mobileAnsweredCount').text(ans);
        $('#mobileUnansweredCount').text(unans);
        $('#desktopAnsweredBadge').text(ans);
        $('#desktopUnansweredBadge').text(unans);
        $('#mobileFloatingBadge').text(ans + '/' + totalQuestions);
    }

    // Fill in the Blanks Input Handler & Server Auto-Saver
    let fillDebounceTimers = {};
    $('.q-fill-input').on('input', function() {
        let inputEl = $(this);
        let qNum = inputEl.data('qnum');
        let qId = inputEl.data('qid');

        let qInputs = $('.q-fill-input[data-qid="' + qId + '"]');
        let hasAnyAnswer = false;
        let answersObj = {};
        let isMultiple = qInputs.length > 1;

        qInputs.each(function() {
            let bIdx = $(this).data('blank-idx') || 1;
            let val = $(this).val().trim();
            answersObj[bIdx] = val;
            if (val.length > 0) hasAnyAnswer = true;
        });

        if (hasAnyAnswer) {
            updatePalette(qNum);
        } else {
            $('#nav_btn_' + qNum).removeClass('answered shadow text-white').addClass('unanswered');
            $('#nav_btn_mobile_' + qNum).removeClass('answered shadow text-white').addClass('unanswered');
            syncCounts();
        }

        let payload = isMultiple ? JSON.stringify(answersObj) : (qInputs.first().val().trim());

        clearTimeout(fillDebounceTimers[qId]);
        fillDebounceTimers[qId] = setTimeout(function() {
            autoSaveTextToServer(qId, payload);
        }, 400);
    });

    function autoSaveTextToServer(questionId, answerText) {
        let badge = $('#saveStatusBadge');
        let text = $('#saveStatusText');
        badge.removeClass('bg-light text-muted bg-danger text-white bg-success').addClass('bg-warning text-dark');
        text.html('<i class="fas fa-spinner fa-spin mr-1"></i>Saving...');

        $.ajax({
            url: 'save_answer_ajax.php',
            method: 'POST',
            data: {
                attempt_id: attemptId,
                exam_id: examId,
                question_id: questionId,
                answer_text: answerText,
                csrf_token: csrfToken
            },
            dataType: 'json'
        }).done(function(res) {
            if (res.success) {
                badge.removeClass('bg-warning text-dark bg-danger').addClass('bg-success text-white');
                text.html('<i class="fas fa-check-circle mr-1"></i>Saved');
                setTimeout(function() {
                    badge.removeClass('bg-success text-white').addClass('bg-light text-muted');
                    text.html('<i class="fas fa-cloud text-primary mr-1"></i>All Answers Saved');
                }, 2000);
            } else {
                badge.removeClass('bg-warning text-dark').addClass('bg-danger text-white');
                text.html('<i class="fas fa-exclamation-triangle mr-1"></i>Save Warning');
            }
        }).fail(function() {
            badge.removeClass('bg-warning text-dark').addClass('bg-secondary text-white');
            text.html('<i class="fas fa-wifi mr-1"></i>Saved Locally');
        });
    }

    // Real-Time Asynchronous Server-Side Auto-Saver
    function autoSaveToServer(questionId, chosenOption) {
        let badge = $('#saveStatusBadge');
        let text = $('#saveStatusText');
        
        badge.removeClass('bg-light text-muted bg-danger text-white bg-success').addClass('bg-warning text-dark');
        text.html('<i class="fas fa-spinner fa-spin mr-1"></i>Saving...');

        $.ajax({
            url: 'save_answer_ajax.php',
            method: 'POST',
            data: {
                attempt_id: attemptId,
                exam_id: examId,
                question_id: questionId,
                chosen_option: chosenOption,
                csrf_token: csrfToken
            },
            dataType: 'json'
        }).done(function(res) {
            if (res.success) {
                badge.removeClass('bg-warning text-dark bg-danger').addClass('bg-success text-white');
                text.html('<i class="fas fa-check-circle mr-1"></i>Saved');
                setTimeout(function() {
                    badge.removeClass('bg-success text-white').addClass('bg-light text-muted');
                    text.html('<i class="fas fa-cloud text-primary mr-1"></i>All Answers Saved');
                }, 2000);
            } else {
                badge.removeClass('bg-warning text-dark').addClass('bg-danger text-white');
                text.html('<i class="fas fa-exclamation-triangle mr-1"></i>Save Warning');
            }
        }).fail(function(xhr) {
            badge.removeClass('bg-warning text-dark').addClass('bg-secondary text-white');
            text.html('<i class="fas fa-wifi mr-1"></i>Saved Locally');
        });
    }

    $('.q-radio').change(function() {
        saveState();
        let qNum = $(this).data('qnum');
        let qId = $(this).data('qid');
        let optVal = $(this).val();
        
        updatePalette(qNum);
        autoSaveToServer(qId, optVal);
    });

    // High-friction submit confirmation modal
    function openSubmitModal() {
        let ansMCQ = $('.q-radio:checked').length;
        let answeredQuestions = {};
        $('.q-fill-input').each(function() {
            let qid = $(this).data('qid');
            if ($(this).val().trim() !== '') {
                answeredQuestions[qid] = true;
            }
        });
        let ansFill = Object.keys(answeredQuestions).length;
        let ans = ansMCQ + ansFill;
        let unans = totalQuestions - ans;
        
        $('#modalTotalQ').text(totalQuestions);
        $('#modalAnsweredQ').text(ans);
        $('#modalUnansweredQ').text(unans);
        
        if (unans > 0) {
            $('#unansweredWarningCount').text(unans);
            $('#unansweredWarningAlert').removeClass('d-none');
        } else {
            $('#unansweredWarningAlert').addClass('d-none');
        }
        
        $('#submitConfirmModal').modal('show');
    }

    function finalizeAndSubmitExam() {
        let btn = document.getElementById('btnFinalSubmitConfirm');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Submitting Final Exam...';
        localStorage.removeItem(lsKey);
        document.getElementById('examForm').submit();
    }

    $(document).ready(function() {
        restoreState();
    });

    // Active Exam Session Heartbeat Beacon - Keeps student session live during long exams
    (function() {
        let consecutiveFailures = 0;
        function pingExamHeartbeat() {
            $.ajax({
                url: 'heartbeat.php',
                method: 'POST',
                data: { current_page: 'Active Exam ID: ' + examId },
                dataType: 'json'
            }).done(function() {
                consecutiveFailures = 0;
            }).fail(function(xhr) {
                consecutiveFailures++;
                if (xhr.status === 401 && consecutiveFailures >= 3) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.error === 'concurrent') {
                            alert("Session Alert: Your account was logged in from another device.");
                            window.location.href = 'login.php?msg=concurrent';
                        }
                    } catch(e) {}
                }
            });
        }
        setInterval(pingExamHeartbeat, 25000);
    })();

    // Doubt Flagging Handlers
    let currentFlaggingQNum = null;

    function openFlagModal(questionId, qNum) {
        currentFlaggingQNum = qNum;
        document.getElementById('flagQuestionId').value = questionId;
        document.getElementById('flagModalQNum').textContent = 'Q' + qNum;
        $('#flagModal').modal('show');
    }

    function handleFlagSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('flagSubmitBtn');
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
            btn.innerHTML = '<i class="fas fa-flag mr-1"></i>Submit Doubt Flag';
            if (data.success) {
                $('#flagModal').modal('hide');
                const flagBtn = document.getElementById('flagBtn' + currentFlaggingQNum);
                if (flagBtn) {
                    flagBtn.classList.remove('btn-outline-warning');
                    flagBtn.classList.add('btn-warning');
                    flagBtn.querySelector('.flag-text').textContent = 'Flagged';
                }
                const navBtn = document.getElementById('nav_btn_' + currentFlaggingQNum);
                if (navBtn) {
                    navBtn.classList.add('flagged');
                }
                const navBtnMobile = document.getElementById('nav_btn_mobile_' + currentFlaggingQNum);
                if (navBtnMobile) {
                    navBtnMobile.classList.add('flagged');
                }
                alert('Question marked and recorded for teacher review!');
            } else {
                alert(data.error || 'Failed to submit flag.');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-flag mr-1"></i>Submit Doubt Flag';
            alert('An error occurred: ' + err);
        });
    }
</script>
</body>
</html>

<?php
// student/exam.php
require_once '../includes/student_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

$student_id = $_SESSION['student_id'];
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

// Check array bounds
$stmt_attempt = $pdo->prepare("SELECT * FROM attempts WHERE student_id = ? AND exam_id = ?");
$stmt_attempt->execute([$student_id, $exam_id]);
$attempt = $stmt_attempt->fetch();

if ($attempt && $attempt['is_submitted']) {
    header("Location: dashboard.php");
    exit;
}

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
        $stmt_attempt = $pdo->prepare("SELECT * FROM attempts WHERE student_id = ? AND exam_id = ?");
        $stmt_attempt->execute([$student_id, $exam_id]);
        $attempt = $stmt_attempt->fetch();
        $attempt_id = $attempt ? (int)$attempt['id'] : 0;
    }
} else {
    $attempt_id = (int)$attempt['id'];
}

// Fetch pointers
$stmt_qs = $pdo->prepare("
    SELECT q.*, eq.order_no 
    FROM questions q
    JOIN exam_questions eq ON q.id = eq.question_id
    WHERE eq.exam_id = ?
    ORDER BY eq.order_no ASC
");
$stmt_qs->execute([$exam_id]);
$questions = $stmt_qs->fetchAll();

$remaining_seconds = max(0, $end_ts - $now_ts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Exam - <?php echo h($exam['title']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
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
        body { background-color: #f3f4f6; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; padding-top: 85px; padding-bottom: 50px; }
        .exam-header { background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.05); position: fixed; top: 0; left: 0; right: 0; z-index: 1030; display: flex; align-items: center; padding: 15px 30px; border-bottom: 1px solid #e5e7eb; }
        .timer-box { font-family: monospace; font-size: 1.6rem; font-weight: bold; background: #fff0f2; color: #dc2626; padding: 8px 20px; border-radius: 999px; border: 1px solid #fecdd3; display: inline-flex; align-items: center; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02); }
        .q-card { background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); margin-bottom: 30px; border: 1px solid #e5e7eb; overflow: hidden; }
        .q-header { background: #f9fafb; padding: 25px 30px; border-bottom: 1px solid #e5e7eb; font-weight: 500; color: #1f2937; display: flex; align-items: flex-start; font-size: 1.1rem; }
        .q-num { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: #fff; padding: 6px 14px; border-radius: 6px; margin-right: 20px; font-size: 0.95rem; font-weight: bold; box-shadow: 0 2px 5px rgba(59,130,246,0.3); flex-shrink: 0; }
        .q-body { padding: 30px; }
        .opt-row { margin-bottom: 20px; }
        .opt-label { cursor: pointer; display: flex; align-items: center; padding: 18px 20px; border: 2px solid #e5e7eb; border-radius: 10px; transition: all 0.2s; background: #fff; margin: 0; width: 100%; font-weight: 500; color: #4b5563; }
        .opt-label:hover { border-color: #cbd5e1; background: #f8fafc; }
        .custom-control-input:focus ~ .opt-label { box-shadow: 0 0 0 3px rgba(59,130,246,0.3); border-color: #93c5fd; }
        .custom-control-input:checked ~ .opt-label { border-color: #2563eb; background: #eff6ff; color: #1d4ed8; font-weight: bold; box-shadow: 0 2px 8px rgba(37,99,235,0.1); }
        .custom-control-input:checked ~ .opt-label .letter { background: #2563eb; color: #fff; border-color: #2563eb; }
        .letter { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 2px solid #cbd5e1; border-radius: 50%; margin-right: 20px; font-size: 0.9rem; background: #f8fafc; color: #64748b; font-weight: bold; flex-shrink: 0; transition: all 0.2s; }
        .nav-sidebar { position: sticky; top: 110px; max-height: calc(100vh - 130px); overflow-y: auto; background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .q-btn { width: 42px; height: 42px; margin: 0 6px 12px 6px; border-radius: 8px; font-weight: bold; font-family: monospace; border-width: 2px; }
        .q-btn:hover { background: #f1f5f9; }
        .q-btn.answered { background: #10b981; color: white; border-color: #059669; }
        .q-btn.unanswered { border-color: #cbd5e1; color: #64748b; background: #f8fafc; }
        .q-text-content { flex-grow: 1; min-width: 0; line-height: 1.6; }
        .q-text-content img { max-width: 100%; height: auto; border-radius: 6px; display: block; margin: 12px 0; }
        @media (max-width: 767.98px) {
            body { padding-top: 75px !important; padding-bottom: 80px !important; }
            .exam-header { padding: 10px 14px !important; }
            .timer-box { font-size: 1.15rem !important; padding: 4px 10px !important; }
            .timer-box i { margin-right: 6px !important; }
            .exam-header .btn-primary { font-size: 0.85rem !important; padding: 6px 14px !important; }
            .q-card { margin-bottom: 20px; border-radius: 10px; }
            .q-header { padding: 16px !important; font-size: 1rem !important; }
            .q-num { padding: 4px 10px; font-size: 0.85rem; margin-right: 12px; }
            .q-body { padding: 16px !important; }
            .opt-row { margin-bottom: 12px; }
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
    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
    <input type="hidden" name="exam_id" value="<?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">

    <div class="exam-header d-flex justify-content-between align-items-center">
        <div>
            <div class="small font-weight-bold text-primary mb-1">
                <i class="fas fa-university mr-1"></i><?php echo h(!empty($exam['institute_name']) ? $exam['institute_name'] : 'Main Campus'); ?>
            </div>
            <h5 class="mb-0 font-weight-bold text-dark d-none d-md-block" style="letter-spacing: -0.5px;"><?php echo h($exam['title']); ?></h5>
            <div class="small text-muted font-weight-bold mt-1 text-uppercase"><i class="fas fa-fingerprint mr-1 opacity-50"></i><?php echo h($_SESSION['student_user']); ?></div>
        </div>
        <div class="timer-box shadow-sm">
            <i class="fas fa-clock mr-3 opacity-50"></i> <span id="timeDisplay" style="letter-spacing: 2px;">00:00:00</span>
        </div>
        <div>
            <button type="button" class="btn btn-primary font-weight-bold px-4 py-2 rounded-pill shadow-sm transition" style="font-size:1.1rem;" onclick="confirmSubmit()">
                Submit Exam <i class="fas fa-paper-plane ml-2"></i>
            </button>
        </div>
    </div>

    <div class="container-fluid px-md-5 mt-4">
        <div class="row">
            <div class="col-lg-9 pr-lg-5">
                <?php foreach($questions as $index => $q): $qNum = $index + 1; ?>
                <div class="q-card" id="q<?php echo $qNum; ?>">
                    <div class="q-header">
                        <span class="q-num">Q<?php echo $qNum; ?></span>
                        <div class="q-text-content"><?php echo render_formatted_content($q['question_text']); ?></div>
                    </div>
                    <div class="q-body row mx-0">
                        <?php for($i=1; $i<=4; $i++): 
                            $optText = $q['option'.$i]; 
                            $identifier = "q_{$q['id']}_opt_{$i}";
                        ?>
                        <div class="col-md-6 opt-row px-2">
                            <div class="custom-control custom-radio p-0">
                                <input type="radio" class="custom-control-input q-radio d-none" name="answers[<?php echo $q['id']; ?>]" id="<?php echo $identifier; ?>" value="<?php echo $i; ?>" data-qnum="<?php echo $qNum; ?>">
                                <label class="opt-label shadow-sm" for="<?php echo $identifier; ?>">
                                    <span class="letter shadow-sm"><?php echo chr(64+$i); ?></span>
                                    <span class="flex-grow-1"><?php echo render_formatted_content($optText); ?></span>
                                </label>
                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="col-lg-3 d-none d-lg-block">
                <div class="nav-sidebar">
                    <h6 class="font-weight-bold text-uppercase text-muted mb-4 border-bottom pb-3"><i class="fas fa-map-signs mr-2 text-primary"></i>Question Navigator</h6>
                    <div class="d-flex flex-wrap" id="qNavContainer">
                        <?php for($i=1; $i<=count($questions); $i++): ?>
                            <a href="#q<?php echo $i; ?>" class="btn q-btn unanswered border text-center p-0 d-flex align-items-center justify-content-center shadow-sm" id="nav_btn_<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                    <div class="mt-4 pt-4 border-top small text-muted font-weight-bold text-uppercase">
                        <div class="mb-3 d-flex align-items-center"><span class="d-inline-block bg-success rounded-circle shadow-sm mr-3" style="width:14px; height:14px;"></span> Answered</div>
                        <div class="d-flex align-items-center"><span class="d-inline-block bg-light border border-secondary rounded-circle shadow-sm mr-3" style="width:14px; height:14px;"></span> Unanswered</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Floating Question Palette Trigger -->
    <div class="floating-palette-btn d-lg-none" data-toggle="modal" data-target="#paletteModal" title="Open Question Palette">
        <i class="fas fa-th-large fa-lg"></i>
        <span class="badge badge-light text-primary position-absolute font-weight-bold shadow-sm" id="mobileFloatingBadge" style="top: -4px; right: -4px; font-size: 0.65rem; border-radius: 10px; padding: 3px 6px;">
            0/<?php echo count($questions); ?>
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
                        <span class="text-success"><i class="fas fa-check-circle mr-1"></i><span id="mobileAnsweredCount">0</span> Answered</span>
                        <span class="text-muted"><i class="fas fa-circle mr-1"></i><span id="mobileUnansweredCount"><?php echo count($questions); ?></span> Unanswered</span>
                    </div>
                    <div class="d-flex flex-wrap justify-content-center" id="mobileQNavGrid">
                        <?php for($i=1; $i<=count($questions); $i++): ?>
                            <a href="#q<?php echo $i; ?>" class="btn q-btn unanswered border text-center p-0 d-flex align-items-center justify-content-center shadow-sm mobile-q-link" id="nav_btn_mobile_<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 font-weight-bold" data-dismiss="modal">Back to Exam</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4 font-weight-bold shadow-sm" onclick="$('#paletteModal').modal('hide'); confirmSubmit();">
                        Submit Exam <i class="fas fa-paper-plane ml-1"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    const examId = <?php echo htmlspecialchars($exam_id, ENT_QUOTES, 'UTF-8'); ?>;
    const lsKey = 'exam_matrix_state_' + examId + '_' + <?php echo $student_id; ?>;
    
    let remainingTime = <?php echo $remaining_seconds; ?>;
    
    function updateTimer() {
        if(remainingTime <= 0) {
            clearInterval(timerInterval);
            document.getElementById('timeDisplay').innerText = "00:00:00";
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
    
    function saveState() {
        let state = {};
        $('.q-radio:checked').each(function() {
            state[$(this).attr('name')] = $(this).val();
        });
        localStorage.setItem(lsKey, JSON.stringify(state));
    }
    
    function restoreState() {
        let saved = localStorage.getItem(lsKey);
        if(saved) {
            try {
                let state = JSON.parse(saved);
                for(let key in state) {
                    $('input[name="'+key+'"][value="'+state[key]+'"]').prop('checked', true).trigger('change');
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
        let total = <?php echo count($questions); ?>;
        let ans = $('.q-radio:checked').length;
        $('#mobileAnsweredCount').text(ans);
        $('#mobileUnansweredCount').text(total - ans);
        $('#mobileFloatingBadge').text(ans + '/' + total);
    }

    $('.q-radio').change(function() {
        saveState();
        let qNum = $(this).data('qnum');
        updatePalette(qNum);
    });

    $(document).on('click', '.mobile-q-link', function(e) {
        e.preventDefault();
        $('#paletteModal').modal('hide');
        let targetId = $(this).attr('href');
        setTimeout(function() {
            let el = document.querySelector(targetId);
            if (el) {
                let offset = 85;
                let bodyRect = document.body.getBoundingClientRect().top;
                let elementRect = el.getBoundingClientRect().top;
                let elementPosition = elementRect - bodyRect;
                let offsetPosition = elementPosition - offset;
                window.scrollTo({
                    top: offsetPosition,
                    behavior: 'smooth'
                });
            }
        }, 350);
    });
    
    function confirmSubmit() {
        let total = <?php echo count($questions); ?>;
        let ans = $('.q-radio:checked').length;
        let unans = total - ans;
        
        let msg = "Are you sure you want to submit your exam?\n\n";
        msg += "Total Questions: " + total + "\n";
        msg += "Answered: " + ans + "\n";
        msg += "Unanswered: " + unans + "\n\n";
        msg += "This action cannot be undone.";
        
        if(confirm(msg)) {
            localStorage.removeItem(lsKey);
            document.getElementById('examForm').submit();
        }
    }

    $(document).ready(function() {
        restoreState();
    });
</script>

</body>
</html>

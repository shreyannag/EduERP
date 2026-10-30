<?php
// student/submit.php
require_once '../includes/student_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF verification - log mismatch without dropping student answers if authenticated
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        error_log("Exam submit: CSRF token mismatch for student_id " . ($_SESSION['student_id'] ?? 'unknown'));
    }
    
    $student_id = (int)($_SESSION['student_id'] ?? 0);
    $exam_id = intval($_POST['exam_id'] ?? 0);
    $attempt_id = intval($_POST['attempt_id'] ?? 0);
    $post_answers = $_POST['answers'] ?? []; // Map struct => answers[question_id] = option_number
    $post_text_answers = $_POST['text_answers'] ?? []; // Map struct => text_answers[question_id] = text_answer
    
    if (!$student_id || !$exam_id) {
        header("Location: dashboard.php");
        exit;
    }
    
    // Find attempt
    $attempt = null;
    if ($attempt_id > 0) {
        $stmt_chk = $pdo->prepare("SELECT id, is_submitted FROM attempts WHERE id = ? AND student_id = ? AND exam_id = ?");
        $stmt_chk->execute([$attempt_id, $student_id, $exam_id]);
        $attempt = $stmt_chk->fetch();
    }
    
    // Fallback: lookup by student and exam if attempt_id was missing or mismatched
    if (!$attempt) {
        $stmt_chk2 = $pdo->prepare("SELECT id, is_submitted FROM attempts WHERE student_id = ? AND exam_id = ? AND is_submitted = 0 ORDER BY id DESC LIMIT 1");
        $stmt_chk2->execute([$student_id, $exam_id]);
        $attempt = $stmt_chk2->fetch();
        if ($attempt) {
            $attempt_id = (int)$attempt['id'];
        }
    }
    
    // If no unsubmitted attempt exists, check if already submitted
    if (!$attempt || (int)$attempt['is_submitted'] === 1) {
        $_SESSION['flash_info'] = "This examination has already been completed and submitted.";
        header("Location: dashboard.php?tab=completed");
        exit;
    }
    
    $score = 0;
    
    try {
        $pdo->beginTransaction();

        // 1. Fetch any answers already persisted in real-time via AJAX
        $stmt_existing = $pdo->prepare("SELECT question_id, chosen_option, answer_text FROM attempt_answers WHERE attempt_id = ?");
        $stmt_existing->execute([$attempt_id]);
        $existing_rows = $stmt_existing->fetchAll(PDO::FETCH_ASSOC);
        $all_mcq_answers = [];
        $all_text_answers = [];
        foreach ($existing_rows as $er) {
            $qid = (int)$er['question_id'];
            if ($er['chosen_option'] !== null) $all_mcq_answers[$qid] = (int)$er['chosen_option'];
            if ($er['answer_text'] !== null && trim($er['answer_text']) !== '') $all_text_answers[$qid] = $er['answer_text'];
        }

        // 2. Merge with answers submitted in the form POST (form takes priority)
        if (!empty($post_answers) && is_array($post_answers)) {
            foreach ($post_answers as $q_id => $sel_opt) {
                if ($sel_opt !== null && $sel_opt !== '') {
                    $all_mcq_answers[intval($q_id)] = intval($sel_opt);
                }
            }
        }
        if (!empty($post_text_answers) && is_array($post_text_answers)) {
            foreach ($post_text_answers as $q_id => $txt_ans) {
                if (is_array($txt_ans)) {
                    $all_text_answers[intval($q_id)] = $txt_ans;
                } elseif ($txt_ans !== null && trim((string)$txt_ans) !== '') {
                    $all_text_answers[intval($q_id)] = trim((string)$txt_ans);
                }
            }
        }

        // 3. Fetch questions, types, correct options, acceptable text, and marks for grading
        $stmt_q = $pdo->prepare("
            SELECT q.id, q.question_type, q.question_text, q.correct_option, q.correct_answer_text, q.option1, q.marks
            FROM questions q
            JOIN exam_questions eq ON q.id = eq.question_id
            WHERE eq.exam_id = ?
        ");
        $stmt_q->execute([$exam_id]);
        $questions_list = $stmt_q->fetchAll(PDO::FETCH_ASSOC);

        $total_answered_count = 0;

        // 4. Save/update all answers in attempt_answers and calculate score
        foreach ($questions_list as $q_row) {
            $qid = (int)$q_row['id'];
            $q_type = $q_row['question_type'] ?? 'mcq';
            $marks = (int)($q_row['marks'] ?? 1);

            $stmt_find = $pdo->prepare("SELECT id FROM attempt_answers WHERE attempt_id = ? AND question_id = ? LIMIT 1");
            $stmt_find->execute([$attempt_id, $qid]);
            $existing_ans_id = $stmt_find->fetchColumn();

            if ($q_type === 'fill_blank') {
                $student_text = $all_text_answers[$qid] ?? null;
                $clean_student_ans = null;
                if (is_array($student_text)) {
                    $hasAny = false;
                    foreach ($student_text as $v) {
                        if (trim((string)$v) !== '') { $hasAny = true; break; }
                    }
                    if ($hasAny) {
                        $clean_student_ans = json_encode($student_text, JSON_UNESCAPED_UNICODE);
                    }
                } elseif ($student_text !== null && trim((string)$student_text) !== '') {
                    $clean_student_ans = trim((string)$student_text);
                }

                if ($clean_student_ans !== null) {
                    $total_answered_count++;
                    $cor_text = !empty($q_row['correct_answer_text']) ? $q_row['correct_answer_text'] : ($q_row['option1'] ?? '');
                    $eval = evaluate_fill_blanks($clean_student_ans, $cor_text, $q_row['question_text'] ?? '');
                    $score += calculate_blank_score($eval, $marks);

                    if ($existing_ans_id) {
                        $pdo->prepare("UPDATE attempt_answers SET answer_text = ?, chosen_option = NULL WHERE id = ?")->execute([$clean_student_ans, $existing_ans_id]);
                    } else {
                        $pdo->prepare("INSERT INTO attempt_answers (attempt_id, question_id, chosen_option, answer_text) VALUES (?, ?, NULL, ?)")->execute([$attempt_id, $qid, $clean_student_ans]);
                    }
                }
            } else {
                $sel_opt = $all_mcq_answers[$qid] ?? null;
                if ($sel_opt !== null) {
                    $total_answered_count++;
                    if ((int)$sel_opt === (int)$q_row['correct_option']) {
                        $score += $marks;
                    }
                    if ($existing_ans_id) {
                        $pdo->prepare("UPDATE attempt_answers SET chosen_option = ?, answer_text = NULL WHERE id = ?")->execute([$sel_opt, $existing_ans_id]);
                    } else {
                        $pdo->prepare("INSERT INTO attempt_answers (attempt_id, question_id, chosen_option, answer_text) VALUES (?, ?, ?, NULL)")->execute([$attempt_id, $qid, $sel_opt]);
                    }
                }
            }
        }
        
        // 5. Finalize attempt in database
        $stmt_sub = $pdo->prepare("
            UPDATE attempts 
            SET score = ?, 
                total_marks = COALESCE(total_marks, (SELECT COALESCE(SUM(q.marks), COUNT(eq.id)) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = ?)),
                submitted_at = NOW(), 
                is_submitted = 1 
            WHERE id = ?
        ");
        $stmt_sub->execute([$score, $exam_id, $attempt_id]);
        
        // Close any redundant duplicate attempts for this student/exam
        $pdo->prepare("UPDATE attempts SET is_submitted = 1, submitted_at = NOW(), score = 0 WHERE student_id = ? AND exam_id = ? AND id != ? AND is_submitted = 0")->execute([$student_id, $exam_id, $attempt_id]);

        $pdo->commit();
        $_SESSION['flash_success'] = "Examination submitted successfully! All your responses (" . $total_answered_count . " answered) have been recorded safely.";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Exam submission failure: " . $e->getMessage());
        $_SESSION['flash_error'] = "An error occurred during submission, but our servers have logged the event. Please contact administration if your attempt is missing.";
        header("Location: dashboard.php?tab=completed");
        exit;
    }
    
    header("Location: dashboard.php?tab=completed&submitted=1");
    exit;
}
header("Location: dashboard.php");
exit;

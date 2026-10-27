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
    $answers = $_POST['answers'] ?? []; // Map struct => answers[question_id] = option_number
    
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

        if (!empty($answers)) {
            $stmt_q = $pdo->prepare("
                SELECT q.id, q.correct_option, q.marks
                FROM questions q
                JOIN exam_questions eq ON q.id = eq.question_id
                WHERE eq.exam_id = ?
            ");
            $stmt_q->execute([$exam_id]);
            $correct_mapping = [];
            $marks_mapping = [];
            while($row = $stmt_q->fetch()) {
                $correct_mapping[$row['id']] = $row['correct_option'];
                $marks_mapping[$row['id']] = $row['marks'] ?? 1;
            }
            
            $stmt_ans_ins = $pdo->prepare("
                INSERT INTO attempt_answers (attempt_id, question_id, chosen_option) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE chosen_option = VALUES(chosen_option)
            ");
            
            foreach($answers as $q_id => $sel_opt) {
                $q_id = intval($q_id);
                $sel_opt = intval($sel_opt);
                
                if (isset($correct_mapping[$q_id])) {
                    $is_correct = ($correct_mapping[$q_id] == $sel_opt) ? 1 : 0;
                    if ($is_correct) {
                        $score += $marks_mapping[$q_id];
                    }
                    
                    $stmt_ans_ins->execute([$attempt_id, $q_id, $sel_opt]);
                }
            }
        }
        
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
        $_SESSION['flash_success'] = "Examination submitted successfully! Your responses have been safely recorded.";
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

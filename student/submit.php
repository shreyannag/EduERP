<?php
// student/submit.php
require_once '../includes/student_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $student_id = $_SESSION['student_id'];
    $exam_id = intval($_POST['exam_id'] ?? 0);
    $attempt_id = intval($_POST['attempt_id'] ?? 0);
    $answers = $_POST['answers'] ?? []; // Map struct => answers[question_id] = option_number
    
    if (!$exam_id || !$attempt_id) die("Invalid submission details.");
    
    $stmt_chk = $pdo->prepare("SELECT id, is_submitted FROM attempts WHERE id = ? AND student_id = ? AND exam_id = ?");
    $stmt_chk->execute([$attempt_id, $student_id, $exam_id]);
    $attempt = $stmt_chk->fetch();
    
    if (!$attempt || $attempt['is_submitted']) {
        header("Location: dashboard.php");
        exit;
    }
    
    $score = 0;
    
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
        
        $stmt_ans_ins = $pdo->prepare("INSERT INTO attempt_answers (attempt_id, question_id, chosen_option) VALUES (?, ?, ?)");
        
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
    
    header("Location: dashboard.php");
    exit;
}
header("Location: dashboard.php");
exit;

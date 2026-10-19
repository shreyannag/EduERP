<?php
// admin/exams/clone.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
        $stmt->execute([$id]);
        $exam = $stmt->fetch();
        
        if ($exam) {
            $new_title = "Copy of " . $exam['title'];
            $stmt_ins = $pdo->prepare("INSERT INTO exams (title, class_id, board_id, status, scheduled_date, scheduled_time, duration_minutes) VALUES (?, ?, ?, 'draft', ?, ?, ?)");
            $stmt_ins->execute([$new_title, $exam['class_id'], $exam['board_id'], $exam['scheduled_date'], $exam['scheduled_time'], $exam['duration_minutes']]);
            $new_exam_id = $pdo->lastInsertId();
            
            // Clone questions accurately representing order
            $stmt_q = $pdo->prepare("SELECT question_id, order_no FROM exam_questions WHERE exam_id = ?");
            $stmt_q->execute([$id]);
            $questions = $stmt_q->fetchAll();
            
            if ($questions) {
                $stmt_q_ins = $pdo->prepare("INSERT INTO exam_questions (exam_id, question_id, order_no) VALUES (?, ?, ?)");
                foreach($questions as $q) {
                    $stmt_q_ins->execute([$new_exam_id, $q['question_id'], $q['order_no']]);
                }
            }
            
            header("Location: create.php?edit=$new_exam_id");
            exit;
        }
    }
}
header('Location: index.php');
exit;

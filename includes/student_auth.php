<?php
// includes/student_auth.php
ini_set('session.cookie_lifetime', 0);
session_name('STUDENT_SESSION');
session_start();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student' || !isset($_SESSION['student_id'])) {
    session_destroy();
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'session_expired', 'message' => 'Your student session has expired. Please log in again.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/student/login.php');
    exit;
}

// Inactivity timeout: generous limits (3 hours general portal, 6 hours for active exam / submissions)
$current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$is_exam_context = in_array($current_script, ['exam.php', 'submit.php', 'heartbeat.php', 'save_answer_ajax.php', 'flag_question_ajax.php', 'flag_doubt_ajax.php']);
$timeout_limit = $is_exam_context ? 21600 : 10800;

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_limit)) {
    session_unset();
    session_destroy();
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'timeout', 'message' => 'Your session timed out due to inactivity.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/student/login.php?msg=timeout');
    exit;
}
$_SESSION['last_activity'] = time();

// Concurrent single-session enforcement
if (!isset($_SESSION['session_token']) || !is_user_session_active('student', (int)$_SESSION['student_id'], $_SESSION['session_token'], $pdo)) {
    // Exam Resilience Guard: If the student is actively taking or submitting an exam, protect them from being kicked out
    // due to superseded tokens caused by mobile double-tap logins or page refreshes.
    $rescued_session = false;
    if ($is_exam_context && isset($_SESSION['student_id'])) {
        try {
            $stmtActiveAttempt = $pdo->prepare("
                SELECT id FROM attempts 
                WHERE student_id = ? AND is_submitted = 0 
                ORDER BY id DESC LIMIT 1
            ");
            $stmtActiveAttempt->execute([(int)$_SESSION['student_id']]);
            if ($stmtActiveAttempt->fetch()) {
                // Fetch the latest active session token for this student
                $stmtSync = $pdo->prepare("SELECT session_token FROM students WHERE id = ?");
                $stmtSync->execute([(int)$_SESSION['student_id']]);
                $currentDbToken = $stmtSync->fetchColumn();
                if (!empty($currentDbToken)) {
                    $_SESSION['session_token'] = $currentDbToken;
                    $rescued_session = is_user_session_active('student', (int)$_SESSION['student_id'], $currentDbToken, $pdo);
                }
            }
        } catch (\Throwable $ex) {
            // Fallback gracefully
        }
    }

    if (!$rescued_session && (!isset($_SESSION['session_token']) || !is_user_session_active('student', (int)$_SESSION['student_id'], $_SESSION['session_token'], $pdo))) {
        session_unset();
        session_destroy();
        if ($isAjax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'concurrent', 'message' => 'Your account was logged in from another device. Please log in again.']);
            exit;
        }
        header('Location: ' . BASE_URL . '/student/login.php?msg=concurrent');
        exit;
    }
}

// Touch active session for real-time presence
touch_user_session('student', (int)$_SESSION['student_id'], $_SESSION['session_token'], $_SERVER['REQUEST_URI'] ?? '', $pdo);

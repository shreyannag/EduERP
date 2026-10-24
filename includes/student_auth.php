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

// Inactivity timeout (10 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 600)) {
    session_unset();
    session_destroy();
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'timeout', 'message' => 'Your session timed out due to 10 minutes of inactivity.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/student/login.php?msg=timeout');
    exit;
}
$_SESSION['last_activity'] = time();

// Concurrent single-session enforcement
if (!isset($_SESSION['session_token']) || !is_user_session_active('student', (int)$_SESSION['student_id'], $_SESSION['session_token'], $pdo)) {
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

// Touch active session for real-time presence
touch_user_session('student', (int)$_SESSION['student_id'], $_SESSION['session_token'], $_SERVER['REQUEST_URI'] ?? '', $pdo);

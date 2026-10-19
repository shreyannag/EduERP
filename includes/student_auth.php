<?php
// includes/student_auth.php
ini_set('session.cookie_lifetime', 0);
session_name('STUDENT_SESSION');
session_start();

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student' || !isset($_SESSION['student_id'])) {
    session_destroy();
    header('Location: ' . BASE_URL . '/student/login.php');
    exit;
}

// Inactivity timeout (10 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 600)) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . '/student/login.php?msg=timeout');
    exit;
}
$_SESSION['last_activity'] = time();

// Concurrent login check
$stmt = $pdo->prepare("SELECT session_token FROM students WHERE id = ?");
$stmt->execute([$_SESSION['student_id']]);
$db_token = $stmt->fetchColumn();

if (!$db_token || !isset($_SESSION['session_token']) || $_SESSION['session_token'] !== $db_token) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . '/student/login.php?msg=concurrent');
    exit;
}

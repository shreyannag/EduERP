<?php
// student/heartbeat.php
// Real-time session validation & activity beacon
require_once __DIR__ . '/../includes/student_auth.php';

header('Content-Type: application/json; charset=utf-8');

// Update activity page if provided
$current_page = trim($_POST['current_page'] ?? ($_GET['page'] ?? 'Dashboard'));
touch_user_session('student', (int)$_SESSION['student_id'], $_SESSION['session_token'], $current_page, $pdo);

echo json_encode([
    'success' => true,
    'status'  => 'active',
    'student_id' => (int)$_SESSION['student_id'],
    'timestamp' => time()
]);
exit;

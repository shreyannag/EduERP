<?php
// admin/heartbeat.php
// Real-time admin session validation & presence beacon
require_once __DIR__ . '/../includes/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$current_page = trim($_POST['current_page'] ?? ($_GET['page'] ?? 'Admin Portal'));
touch_user_session('admin', (int)$_SESSION['admin_id'], $_SESSION['session_token'], $current_page, $pdo);

echo json_encode([
    'success'    => true,
    'status'     => 'active',
    'admin_id'   => (int)$_SESSION['admin_id'],
    'timestamp'  => time()
]);
exit;

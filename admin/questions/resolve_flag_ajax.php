<?php
// admin/questions/resolve_flag_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$flag_id = (int)($_POST['flag_id'] ?? 0);
$action  = $_POST['action'] ?? 'resolve'; // 'resolve' or 'dismiss'
$notes   = trim($_POST['notes'] ?? '');

if (!$flag_id) {
    echo json_encode(['success' => false, 'error' => 'Flag ID required']);
    exit;
}

$status = ($action === 'dismiss') ? 'dismissed' : 'resolved';
$current_admin_id = (int)$_SESSION['admin_id'];

$stmt = $pdo->prepare("
    UPDATE question_flags 
    SET status = ?, resolved_by = ?, resolved_at = NOW(), resolution_notes = ? 
    WHERE id = ?
");
$stmt->execute([$status, $current_admin_id, $notes ?: null, $flag_id]);

echo json_encode(['success' => true, 'message' => "Flag marked as {$status}."]);

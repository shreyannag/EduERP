<?php
// admin/admins/get_admin_devices_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_super_admin();

$admin_id = (int)($_GET['admin_id'] ?? 0);
if (!$admin_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid administrator ID']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, role, full_name, institute_name FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$adm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$adm) {
    echo json_encode(['success' => false, 'error' => 'Administrator record not found']);
    exit;
}

$sessions = get_user_device_sessions('admin', $admin_id, $pdo, 30);

echo json_encode([
    'success'  => true,
    'admin'    => $adm,
    'sessions' => $sessions
]);
exit;

<?php
// admin/students/get_online_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_feature('students');

$effective_admin_id = is_super_admin() ? null : get_effective_admin_id();
$students = get_active_online_students($effective_admin_id, $pdo, 100);
$totalOnline = count($students);

echo json_encode([
    'success' => true,
    'total_online' => $totalOnline,
    'students' => $students
]);
exit;

<?php
// admin/attendance/notify_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

require_admin_feature('attendance');

// Validate CSRF token
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
    exit;
}

$register_id = !empty($_POST['register_id']) ? (int)$_POST['register_id'] : 0;
$student_id = !empty($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$channel = trim($_POST['channel'] ?? 'email');
$mark_only = !empty($_POST['mark_only']) ? 1 : 0;

if ($register_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid register ID.']);
    exit;
}

$effective_admin_id = is_super_admin() ? null : get_effective_admin_id();

// Verify ownership of the register
$regWhere = "id = ?";
$regParams = [$register_id];
if (!is_super_admin()) {
    $regWhere .= " AND admin_id = ?";
    $regParams[] = $effective_admin_id;
}

$stmtReg = $pdo->prepare("SELECT * FROM attendance_registers WHERE $regWhere");
$stmtReg->execute($regParams);
$register = $stmtReg->fetch(PDO::FETCH_ASSOC);

if (!$register) {
    echo json_encode(['success' => false, 'error' => 'Attendance register not found or access denied.']);
    exit;
}

// If mark_only (e.g. from WhatsApp click)
if ($mark_only === 1 && $student_id > 0) {
    $stmtUp = $pdo->prepare("
        UPDATE attendance_records 
        SET parent_notified = 1, parent_notified_at = NOW(), parent_notification_channel = ?
        WHERE register_id = ? AND student_id = ? AND status = 'absent'
    ");
    $stmtUp->execute([$channel ?: 'whatsapp', $register_id, $student_id]);
    echo json_encode(['success' => true, 'marked' => true]);
    exit;
}

// Fetch absentees to notify
$absQuery = "
    SELECT ar.id as record_id, ar.student_id, ar.parent_notified,
           s.full_name, s.username, s.email, s.parent_name, s.parent_phone, s.admin_id,
           c.name as class_name
    FROM attendance_records ar
    JOIN students s ON ar.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE ar.register_id = ? AND ar.status = 'absent'
";
$absParams = [$register_id];

if ($student_id > 0) {
    $absQuery .= " AND ar.student_id = ?";
    $absParams[] = $student_id;
} else {
    // Only send to unnotified parents when in bulk mode
    $absQuery .= " AND (ar.parent_notified = 0 OR ar.parent_notified IS NULL)";
}

$stmtAbs = $pdo->prepare($absQuery);
$stmtAbs->execute($absParams);
$absentees = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);

if (empty($absentees)) {
    echo json_encode(['success' => false, 'error' => 'No pending absentee students found to notify.']);
    exit;
}

$sentCount = 0;
$failedCount = 0;
$errors = [];

$stmtMark = $pdo->prepare("
    UPDATE attendance_records 
    SET parent_notified = 1, parent_notified_at = NOW(), parent_notification_channel = 'email'
    WHERE id = ?
");

foreach ($absentees as $student) {
    $mailRes = send_parent_absentee_email($student, $register, $register['admin_id'], $pdo);

    if ($mailRes['success']) {
        $stmtMark->execute([$student['record_id']]);
        $sentCount++;
    } else {
        $failedCount++;
        $errors[] = $student['full_name'] . ': ' . ($mailRes['error'] ?? 'Mail dispatch failed');
    }
}

if ($sentCount > 0) {
    echo json_encode([
        'success'      => true,
        'sent_count'   => $sentCount,
        'failed_count' => $failedCount,
        'message'      => "Dispatched {$sentCount} absentee alert email(s) successfully!" . ($failedCount > 0 ? " ({$failedCount} failed)" : '')
    ]);
} else {
    echo json_encode([
        'success'      => false,
        'error'        => 'Failed to dispatch emails: ' . implode('; ', array_slice($errors, 0, 3))
    ]);
}
exit;

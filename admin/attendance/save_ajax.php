<?php
// admin/attendance/save_ajax.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

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
$session_date = trim($_POST['session_date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session_date)) {
    $session_date = date('Y-m-d');
}

$class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
$batch_id = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
$session_title = trim($_POST['session_title'] ?? 'Standard Lecture Session');
$time_start = !empty($_POST['schedule_time_start']) ? trim($_POST['schedule_time_start']) : null;
$time_end = !empty($_POST['schedule_time_end']) ? trim($_POST['schedule_time_end']) : null;
$notes = trim($_POST['notes'] ?? '');

if ($class_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid academic class.']);
    exit;
}

$records = $_POST['records'] ?? [];
if (!is_array($records) || empty($records)) {
    echo json_encode(['success' => false, 'error' => 'No student records received in submission.']);
    exit;
}

$current_user_id = (int)($_SESSION['admin_id'] ?? 1);
$effective_admin_id = is_super_admin() ? (int)($_SESSION['admin_id'] ?? 1) : get_effective_admin_id();

try {
    $pdo->beginTransaction();

    if ($register_id > 0) {
        // Verify ownership if not super admin
        $chkQuery = "SELECT id, admin_id FROM attendance_registers WHERE id = ?";
        $chkParams = [$register_id];
        if (!is_super_admin()) {
            $chkQuery .= " AND admin_id = ?";
            $chkParams[] = $effective_admin_id;
        }
        $stmtChk = $pdo->prepare($chkQuery);
        $stmtChk->execute($chkParams);
        $reg = $stmtChk->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Attendance register not found or access denied.']);
            exit;
        }

        // Update existing register
        $stmtUp = $pdo->prepare("
            UPDATE attendance_registers 
            SET session_date = ?, class_id = ?, batch_id = ?, session_title = ?, 
                schedule_time_start = ?, schedule_time_end = ?, notes = ?, recorded_by_id = ?
            WHERE id = ?
        ");
        $stmtUp->execute([
            $session_date, $class_id, $batch_id ?: null, $session_title,
            $time_start ?: null, $time_end ?: null, $notes, $current_user_id,
            $register_id
        ]);
    } else {
        // Insert new register
        $stmtIns = $pdo->prepare("
            INSERT INTO attendance_registers 
            (admin_id, class_id, batch_id, session_date, session_title, schedule_time_start, schedule_time_end, recorded_by_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtIns->execute([
            $effective_admin_id, $class_id, $batch_id ?: null, $session_date,
            $session_title, $time_start ?: null, $time_end ?: null, $current_user_id, $notes
        ]);
        $register_id = (int)$pdo->lastInsertId();
    }

    // Prepare upsert for student attendance records
    $stmtRecord = $pdo->prepare("
        INSERT INTO attendance_records 
        (register_id, student_id, status, marked_by_id, auto_detected, remarks)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            marked_by_id = VALUES(marked_by_id),
            auto_detected = VALUES(auto_detected),
            remarks = VALUES(remarks)
    ");

    $countOffline = 0;
    $countOnline = 0;
    $countAbsent = 0;
    $countLate = 0;

    $validStatuses = ['offline', 'online', 'absent', 'late'];

    foreach ($records as $studentId => $data) {
        $studentId = (int)$studentId;
        if ($studentId <= 0) continue;

        $status = strtolower(trim($data['status'] ?? 'offline'));
        if (!in_array($status, $validStatuses, true)) {
            $status = 'offline';
        }

        $autoDetected = !empty($data['auto_detected']) ? 1 : 0;
        $remarks = trim($data['remarks'] ?? '');

        $stmtRecord->execute([
            $register_id,
            $studentId,
            $status,
            $current_user_id,
            $autoDetected,
            $remarks
        ]);

        if ($status === 'offline') $countOffline++;
        elseif ($status === 'online') $countOnline++;
        elseif ($status === 'absent') $countAbsent++;
        elseif ($status === 'late') $countLate++;
    }

    $pdo->commit();

    echo json_encode([
        'success'       => true,
        'register_id'   => $register_id,
        'total'         => count($records),
        'count_offline' => $countOffline,
        'count_online'  => $countOnline,
        'count_absent'  => $countAbsent,
        'count_late'    => $countLate,
        'message'       => "Attendance recorded successfully! (Offline: {$countOffline}, Online: {$countOnline}, Absent: {$countAbsent})"
    ]);
    exit;

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error while saving attendance: ' . $e->getMessage()
    ]);
    exit;
}

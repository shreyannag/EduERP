<?php
// admin/fees/notify_ajax.php
// AJAX endpoint for email statements, bulk student fee reminders, and admin collection digests.

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fee_manager.php';

@set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// CSRF check
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Please reload the page.']);
    exit;
}

$action = trim($_POST['action'] ?? '');
$year   = intval($_POST['year'] ?? date('Y'));
$month  = intval($_POST['month'] ?? date('n'));

if ($action === 'send_student_statement') {
    $student_id = intval($_POST['student_id'] ?? 0);
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Student ID is required.']);
        exit;
    }

    $senderAdminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $res = send_student_fee_statement_email($student_id, $year, $pdo, $senderAdminId);
    echo json_encode($res);
    exit;
}

if ($action === 'send_bulk_due_reminders') {
    $senderAdminId = (!empty($_SESSION['role']) && $_SESSION['role'] === 'super_admin') ? null : (int)($_SESSION['admin_id'] ?? 1);
    $summary = get_all_students_fee_summary($year, $month, $pdo, $senderAdminId);
    $sentCount = 0;
    $skippedCount = 0;
    $errors = [];

    foreach ($summary['defaulters'] as $defaulter) {
        $stuId = $defaulter['student_id'];
        $email = trim($defaulter['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skippedCount++;
            continue;
        }

        $res = send_student_fee_statement_email($stuId, $year, $pdo, $senderAdminId);
        if ($res['success']) {
            $sentCount++;
        } else {
            $errors[] = "{$defaulter['full_name']}: " . $res['error'];
        }

        // Slight throttle between emails to be gentle on SMTP
        usleep(150000); // 0.15s
    }

    echo json_encode([
        'success'       => true,
        'sent_count'    => $sentCount,
        'skipped_count' => $skippedCount,
        'total_due'     => count($summary['defaulters']),
        'errors'        => $errors,
        'message'       => "Dispatched fee reminder emails to {$sentCount} student(s) with pending dues." . ($skippedCount > 0 ? " ({$skippedCount} skipped due to missing email)." : "")
    ]);
    exit;
}

if ($action === 'send_admin_digest') {
    $reminderType = trim($_POST['reminder_type'] ?? 'manual');
    if (!in_array($reminderType, ['mid_month', 'end_month', 'manual'])) {
        $reminderType = 'manual';
    }

    $targetAdminId = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;
    $res = send_admin_fee_digest_email($pdo, $reminderType, $targetAdminId);
    echo json_encode($res);
    exit;
}

if ($action === 'get_student_ledger') {
    $student_id = intval($_POST['student_id'] ?? 0);
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Student ID is required.']);
        exit;
    }

    $ledger = get_student_fee_ledger($student_id, $year, $pdo);
    if (!$ledger) {
        echo json_encode(['success' => false, 'error' => 'Student ledger not found.']);
        exit;
    }

    echo json_encode(['success' => true, 'data' => $ledger]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);
exit;

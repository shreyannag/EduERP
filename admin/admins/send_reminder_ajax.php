<?php
// admin/admins/send_reminder_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

header('Content-Type: application/json; charset=utf-8');

// Super Admin authorization
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$admin_id      = (int)($_POST['admin_id'] ?? 0);
$billing_month = (int)($_POST['billing_month'] ?? date('n'));
$billing_year  = (int)($_POST['billing_year'] ?? date('Y'));
$batch_all     = !empty($_POST['batch_all']);

try {
    if ($batch_all) {
        // Send reminders to all tenant admins with overdue/pending payments
        $overview = get_all_admins_billing_overview($billing_month, $billing_year, $pdo);
        $sentCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($overview['admins'] as $adm) {
            if ($adm['billing']['status'] !== 'paid') {
                $res = send_admin_payment_reminder_email($adm['id'], $billing_month, $billing_year, $pdo);
                if (!empty($res['success'])) {
                    $sentCount++;
                } else {
                    $failedCount++;
                    if (!empty($res['error'])) $errors[] = "{$adm['username']}: {$res['error']}";
                }
            }
        }

        echo json_encode([
            'success'      => true,
            'message'      => "Sent delayed payment reminders to {$sentCount} administrator(s)." . ($failedCount > 0 ? " Failed for {$failedCount}." : ""),
            'sent_count'   => $sentCount,
            'failed_count' => $failedCount,
            'errors'       => $errors
        ]);
        exit;
    }

    if ($admin_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid administrator record specified.']);
        exit;
    }

    $result = send_admin_payment_reminder_email($admin_id, $billing_month, $billing_year, $pdo);
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error dispatching payment reminder: ' . $e->getMessage()]);
}

<?php
/**
 * cron/fee_reminders.php
 * Automated Fee Reminder and Monthly Digest Cron Script
 *
 * Runs automatically via CLI cron, Windows Task Scheduler, or web cron URL:
 * - Mid-Month Digest: Dispatched on the 15th of every month to the admin.
 * - Month-End Digest: Dispatched on the last day of every month to the admin.
 * - Optional: Can also dispatch monthly statements to students with dues.
 *
 * CLI Usage:
 *   php cron/fee_reminders.php [--force-mid] [--force-end] [--notify-students] [--notify-defaulters] [--dry-run]
 *
 * Web Usage (with CRON_SECRET token):
 *   http://localhost/shreyansir/cron/fee_reminders.php?token=fee_cron_secret&action=auto
 */

// Allow execution from CLI or secured web call
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
    $cronSecret = getenv('CRON_SECRET') ?: 'fee_cron_secret';
    if ($token !== $cronSecret) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized cron access: invalid token.']);
        exit;
    }
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/fee_manager.php';

function cron_log($msg) {
    global $isCli;
    $time = date('Y-m-d H:i:s');
    $line = "[{$time}] {$msg}\n";
    if ($isCli) {
        echo $line;
    }
}

// Parse CLI options if in CLI mode
$forceMid = false;
$forceEnd = false;
$forceAll = false;
$notifyStudents = false;
$notifyDefaulters = false;
$dryRun = false;

if ($isCli) {
    $opts = getopt('', ['force', 'force-mid', 'force-end', 'notify-students', 'notify-defaulters', 'dry-run', 'help']);
    if (isset($opts['help'])) {
        echo "Student Fees Automated Reminder Cron\n";
        echo "====================================\n";
        echo "Options:\n";
        echo "  --force              Force sending digest based on closest milestone\n";
        echo "  --force-mid          Force trigger 15th Mid-Month Admin Digest\n";
        echo "  --force-end          Force trigger Month-End Admin Digest\n";
        echo "  --notify-students    Send statement emails to all students\n";
        echo "  --notify-defaulters  Send statement emails to students with unpaid fees\n";
        echo "  --dry-run            Simulate execution without sending emails\n";
        echo "  --help               Show this help message\n";
        exit(0);
    }
    $forceMid = isset($opts['force-mid']);
    $forceEnd = isset($opts['force-end']);
    $forceAll = isset($opts['force']);
    $notifyStudents = isset($opts['notify-students']);
    $notifyDefaulters = isset($opts['notify-defaulters']);
    $dryRun = isset($opts['dry-run']);
} else {
    $action = $_GET['action'] ?? 'auto';
    if ($action === 'mid') $forceMid = true;
    if ($action === 'end') $forceEnd = true;
    if (isset($_GET['notify_students'])) $notifyStudents = true;
    if (isset($_GET['notify_defaulters'])) $notifyDefaulters = true;
    if (isset($_GET['dry_run'])) $dryRun = true;
}

$todayDay   = (int)date('j');
$lastDay    = (int)date('t');
$currentYm  = date('Y-m');
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

cron_log("Starting Fee Reminder Cron. Today: " . date('Y-m-d') . " (Day {$todayDay} of {$lastDay})");

$results = [
    'date' => date('Y-m-d H:i:s'),
    'day' => $todayDay,
    'days_in_month' => $lastDay,
    'admin_digest' => null,
    'student_notifications' => []
];

// 1. Check Mid-Month Trigger (15th)
$shouldSendMid = false;
$shouldSendEnd = false;

if ($forceEnd) {
    $shouldSendEnd = true;
} elseif ($forceMid) {
    $shouldSendMid = true;
} else {
    // Natural date-based checks
    if ($todayDay === 15 || ($forceAll && $todayDay <= 20)) {
        $stmtSet = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'fee_last_midmonth_reminder'");
        $lastSent = $stmtSet->fetchColumn();
        if ($lastSent !== $currentYm) {
            $shouldSendMid = true;
        } else {
            cron_log("Mid-month reminder already dispatched previously for {$currentYm}. Skipping.");
        }
    }

    if ($todayDay === $lastDay || ($forceAll && $todayDay > 20)) {
        $stmtSet = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'fee_last_endmonth_reminder'");
        $lastSent = $stmtSet->fetchColumn();
        if ($lastSent !== $currentYm) {
            $shouldSendEnd = true;
        } else {
            cron_log("Month-end reminder already dispatched previously for {$currentYm}. Skipping.");
        }
    }
}

// Execute Admin Digest if triggered
if ($shouldSendMid) {
    cron_log("Triggering Mid-Month Admin Fee Digest (15th)...");
    if ($dryRun) {
        cron_log("[DRY-RUN] Mid-Month Admin Digest email would be sent.");
        $results['admin_digest'] = ['type' => 'mid_month', 'status' => 'simulated'];
    } else {
        $res = send_admin_fee_digest_email($pdo, 'mid_month');
        cron_log("Result: " . ($res['success'] ? 'SUCCESS: ' . $res['message'] : 'FAILED: ' . ($res['error'] ?? '')));
        $results['admin_digest'] = array_merge(['type' => 'mid_month'], $res);
    }
} elseif ($shouldSendEnd) {
    cron_log("Triggering Month-End Admin Fee Digest (Last day)...");
    if ($dryRun) {
        cron_log("[DRY-RUN] Month-End Admin Digest email would be sent.");
        $results['admin_digest'] = ['type' => 'end_month', 'status' => 'simulated'];
    } else {
        $res = send_admin_fee_digest_email($pdo, 'end_month');
        cron_log("Result: " . ($res['success'] ? 'SUCCESS: ' . $res['message'] : 'FAILED: ' . ($res['error'] ?? '')));
        $results['admin_digest'] = array_merge(['type' => 'end_month'], $res);
    }
} else {
    cron_log("No admin digest scheduled for today (Day {$todayDay}).");
    $results['admin_digest'] = ['type' => 'none', 'message' => 'Not a mid-month (15th) or month-end date.'];
}

// 3. Optional Student Notifications
if ($notifyStudents || $notifyDefaulters) {
    cron_log("Processing student email notifications...");
    $summary = get_all_students_fee_summary($currentYear, $currentMonth, $pdo);
    $targetStudents = $notifyDefaulters ? $summary['defaulters'] : $summary['students'];

    cron_log("Target students to notify: " . count($targetStudents));

    $sentCount = 0;
    $errCount = 0;

    foreach ($targetStudents as $target) {
        $stuId = (int)$target['student_id'];
        $stuName = $target['full_name'];
        $email = $target['email'] ?? '';

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            cron_log("Skipping student #{$stuId} ({$stuName}): No valid email.");
            continue;
        }

        if ($dryRun) {
            cron_log("[DRY-RUN] Would send statement to {$stuName} <{$email}>");
            $results['student_notifications'][] = [
                'student_id' => $stuId,
                'name' => $stuName,
                'email' => $email,
                'status' => 'simulated'
            ];
            $sentCount++;
            continue;
        }

        $res = send_student_fee_statement_email($stuId, $currentYear, $pdo);
        if ($res['success']) {
            $sentCount++;
            cron_log("Sent statement to {$stuName} ({$email})");
            $results['student_notifications'][] = [
                'student_id' => $stuId,
                'name' => $stuName,
                'email' => $email,
                'status' => 'sent'
            ];
        } else {
            $errCount++;
            cron_log("Failed sending to {$stuName}: " . ($res['error'] ?? 'Unknown error'));
            $results['student_notifications'][] = [
                'student_id' => $stuId,
                'name' => $stuName,
                'email' => $email,
                'status' => 'failed',
                'error' => $res['error'] ?? 'Error'
            ];
        }

        // Slight sleep to avoid SMTP rate-limiting
        usleep(250000); // 250ms
    }

    cron_log("Student notification complete. Sent: {$sentCount}, Failed: {$errCount}");
}

cron_log("Fee Reminder Cron finished successfully.");

if (!$isCli) {
    echo json_encode([
        'success' => true,
        'data' => $results
    ], JSON_PRETTY_PRINT);
}

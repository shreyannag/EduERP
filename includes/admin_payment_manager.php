<?php
// includes/admin_payment_manager.php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Get super admin payment and billing settings
 */
function get_admin_billing_settings($pdo) {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $keys = [
        'admin_default_monthly_fee' => '1500.00',
        'admin_payment_due_day'     => '10',
        'super_admin_upi_id'        => 'shreyansir@okaxis',
        'super_admin_bank_details' => 'State Bank of India | A/C: 38291048591 | IFSC: SBIN0001234',
        'fee_currency_symbol'       => '₹'
    ];

    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('admin_default_monthly_fee', 'admin_payment_due_day', 'super_admin_upi_id', 'super_admin_bank_details', 'fee_currency_symbol')");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $keys[$row['setting_key']] = $row['setting_value'];
        }
    } catch (\Throwable $e) {}

    $cached = $keys;
    return $keys;
}

/**
 * Retrieve custom billing profile for an institute admin if configured
 */
function get_admin_billing_profile($admin_id, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_billing_profiles WHERE admin_id = ?");
        $stmt->execute([$admin_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['feature_prices'] = !empty($row['feature_prices']) ? json_decode($row['feature_prices'], true) : [];
            return $row;
        }
        return null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Save or update custom billing profile for an institute admin
 */
function save_admin_billing_profile($admin_id, array $data, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $model = $data['billing_model'] ?? 'standard';
    $baseFee = (float)($data['base_monthly_fee'] ?? 1500.00);
    $perStudent = (float)($data['per_student_fee'] ?? 0.00);
    $featurePrices = !empty($data['feature_prices']) ? (is_array($data['feature_prices']) ? json_encode($data['feature_prices']) : $data['feature_prices']) : json_encode([]);
    $discount = (float)($data['discount_amount'] ?? 0.00);
    $dueDay = min(28, max(1, (int)($data['billing_due_day'] ?? 10)));
    $notes = trim($data['notes'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO admin_billing_profiles 
        (admin_id, billing_model, base_monthly_fee, per_student_fee, feature_prices, discount_amount, billing_due_day, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            billing_model = VALUES(billing_model),
            base_monthly_fee = VALUES(base_monthly_fee),
            per_student_fee = VALUES(per_student_fee),
            feature_prices = VALUES(feature_prices),
            discount_amount = VALUES(discount_amount),
            billing_due_day = VALUES(billing_due_day),
            notes = VALUES(notes),
            updated_at = NOW()
    ");
    return $stmt->execute([$admin_id, $model, $baseFee, $perStudent, $featurePrices, $discount, $dueDay, $notes]);
}

/**
 * Calculates complete itemized billing breakdown for an administrator for a month/year
 */
function calculate_admin_monthly_billing($admin_id, $month = null, $year = null, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $settings = get_admin_billing_settings($pdo);
    $profile = get_admin_billing_profile($admin_id, $pdo);

    // Active students count under this admin
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admin_id = ? AND is_active = 1");
    $stmtCount->execute([$admin_id]);
    $studentCount = (int)$stmtCount->fetchColumn();

    // Admin features
    $stmtAdm = $pdo->prepare("SELECT features, institute_name FROM admins WHERE id = ?");
    $stmtAdm->execute([$admin_id]);
    $adm = $stmtAdm->fetch(PDO::FETCH_ASSOC);
    $enabledFeatures = !empty($adm['features']) ? json_decode($adm['features'], true) : [];
    if (!is_array($enabledFeatures)) {
        $enabledFeatures = [];
    }

    $allFeaturesMeta = defined('ALL_ADMIN_FEATURES') ? ALL_ADMIN_FEATURES : [];

    $model = $profile['billing_model'] ?? 'standard';
    $baseFee = (float)($profile ? $profile['base_monthly_fee'] : $settings['admin_default_monthly_fee']);
    $perStudentFee = (float)($profile ? $profile['per_student_fee'] : 0.00);
    $featurePrices = !empty($profile['feature_prices']) && is_array($profile['feature_prices']) ? $profile['feature_prices'] : [];
    $discount = (float)($profile ? $profile['discount_amount'] : 0.00);
    $dueDay = (int)($profile ? $profile['billing_due_day'] : $settings['admin_payment_due_day']);

    $featureItems = [];
    $featuresTotal = 0.00;

    foreach ($featurePrices as $featKey => $price) {
        $p = (float)$price;
        if ($p > 0 && in_array($featKey, $enabledFeatures, true)) {
            $meta = $allFeaturesMeta[$featKey] ?? ['label' => ucfirst(str_replace('_', ' ', $featKey)), 'icon' => 'fas fa-cube'];
            $featureItems[] = [
                'feature_key'   => $featKey,
                'feature_label' => $meta['label'],
                'feature_icon'  => $meta['icon'],
                'price'         => $p
            ];
            $featuresTotal += $p;
        }
    }

    $studentsTotal = $studentCount * $perStudentFee;

    switch ($model) {
        case 'custom_fixed':
            $totalDue = $baseFee - $discount;
            break;
        case 'per_feature':
            $totalDue = $featuresTotal - $discount;
            break;
        case 'hybrid':
            $totalDue = $baseFee + $studentsTotal + $featuresTotal - $discount;
            break;
        case 'standard':
        default:
            $totalDue = $baseFee;
            break;
    }

    $totalDue = max(0.00, $totalDue);

    return [
        'amount_due'         => $totalDue,
        'billing_model'      => $model,
        'base_fee'           => $baseFee,
        'per_student_fee'    => $perStudentFee,
        'enrolled_students'  => $studentCount,
        'students_total'     => $studentsTotal,
        'features_total'     => $featuresTotal,
        'feature_breakdown'  => $featureItems,
        'discount_amount'    => $discount,
        'billing_due_day'    => $dueDay,
        'profile'            => $profile
    ];
}

/**
 * Get or automatically create a billing record for an administrator for a given month/year
 */
function get_or_create_admin_billing($admin_id, $month = null, $year = null, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    // Sub-admins and staff under an institute are employees, completely exempt from monthly billing
    $stmtRole = $pdo->prepare("SELECT role, parent_admin_id FROM admins WHERE id = ?");
    $stmtRole->execute([$admin_id]);
    $admInfo = $stmtRole->fetch(PDO::FETCH_ASSOC);
    if ($admInfo && ($admInfo['role'] === 'sub_admin' || (!empty($admInfo['parent_admin_id']) && (int)$admInfo['parent_admin_id'] !== (int)$admin_id))) {
        return [
            'id'                => 0,
            'admin_id'          => $admin_id,
            'is_employee'       => true,
            'billing_month'     => $month ? (int)$month : (int)date('n'),
            'billing_year'      => $year ? (int)$year : (int)date('Y'),
            'enrolled_students' => 0,
            'plan_name'         => 'Staff / Employee (Payroll)',
            'amount_due'        => 0.00,
            'amount_paid'       => 0.00,
            'status'            => 'exempt',
            'due_date'          => null,
            'payment_date'      => null,
            'payment_method'    => 'N/A',
            'transaction_ref'   => null,
            'receipt_number'    => null,
            'reminder_sent_at'  => null,
            'reminder_count'    => 0,
            'notes'             => 'Employees are on institute payroll and exempt from billing'
        ];
    }

    $month = $month ? (int)$month : (int)date('n');
    $year  = $year ? (int)$year : (int)date('Y');
    $settings = get_admin_billing_settings($pdo);

    // Count enrolled students under this admin
    $stmtStudents = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admin_id = ?");
    $stmtStudents->execute([$admin_id]);
    $totalStudents = (int)$stmtStudents->fetchColumn();

    $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM students WHERE admin_id = ? AND is_active = 1");
    $stmtActive->execute([$admin_id]);
    $activeStudents = (int)$stmtActive->fetchColumn();

    $stmtEnrol = $pdo->prepare("SELECT COUNT(*) FROM enrolments en JOIN exams ex ON en.exam_id = ex.id WHERE ex.admin_id = ?");
    $stmtEnrol->execute([$admin_id]);
    $examEnrolments = (int)$stmtEnrol->fetchColumn();

    // Fetch existing record
    $stmt = $pdo->prepare("SELECT * FROM admin_payments WHERE admin_id = ? AND billing_month = ? AND billing_year = ?");
    $stmt->execute([$admin_id, $month, $year]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    $calculated = calculate_admin_monthly_billing($admin_id, $month, $year, $pdo);
    $amountDue = (float)$calculated['amount_due'];
    $breakdownJson = json_encode($calculated);

    $dueDay = min(28, max(1, (int)$calculated['billing_due_day']));
    $dueDate = sprintf('%04d-%02d-%02d', $year, $month, $dueDay);
    $today = date('Y-m-d');

    if (!$record) {
        $status = ($today > $dueDate) ? 'overdue' : 'pending';
        $planName = ($calculated['billing_model'] === 'standard') 
            ? 'Institute Platform Subscription' 
            : 'Custom Institute Plan (' . ucfirst(str_replace('_', ' ', $calculated['billing_model'])) . ')';

        $stmtIns = $pdo->prepare("
            INSERT INTO admin_payments 
            (admin_id, billing_month, billing_year, enrolled_students, plan_name, amount_due, amount_paid, status, due_date, itemized_breakdown)
            VALUES (?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?)
        ");
        $stmtIns->execute([$admin_id, $month, $year, $totalStudents, $planName, $amountDue, $status, $dueDate, $breakdownJson]);

        $record = [
            'id'                 => (int)$pdo->lastInsertId(),
            'admin_id'           => $admin_id,
            'billing_month'      => $month,
            'billing_year'       => $year,
            'enrolled_students'  => $totalStudents,
            'plan_name'          => $planName,
            'amount_due'         => $amountDue,
            'amount_paid'        => 0.00,
            'status'             => $status,
            'due_date'           => $dueDate,
            'payment_date'       => null,
            'payment_method'     => 'UPI',
            'transaction_ref'    => null,
            'receipt_number'     => null,
            'reminder_sent_at'   => null,
            'reminder_count'     => 0,
            'notes'              => null,
            'itemized_breakdown' => $breakdownJson
        ];
    } else {
        // Always sync latest enrolled student count
        if ((int)$record['enrolled_students'] !== $totalStudents) {
            $stmtUpCount = $pdo->prepare("UPDATE admin_payments SET enrolled_students = ? WHERE id = ?");
            $stmtUpCount->execute([$totalStudents, $record['id']]);
            $record['enrolled_students'] = $totalStudents;
        }

        // If unpaid, sync dynamic custom billing calculation and overdue status
        if ($record['status'] !== 'paid') {
            $newStatus = ($today > $record['due_date']) ? 'overdue' : 'pending';
            $stmtUp = $pdo->prepare("UPDATE admin_payments SET amount_due = ?, itemized_breakdown = ?, status = ? WHERE id = ?");
            $stmtUp->execute([$amountDue, $breakdownJson, $newStatus, $record['id']]);
            $record['amount_due'] = $amountDue;
            $record['itemized_breakdown'] = $breakdownJson;
            $record['status'] = $newStatus;
        }
    }

    // Calculate delay days if overdue or past due date
    $delayDays = 0;
    if ($today > $record['due_date'] && $record['status'] !== 'paid') {
        $diff = strtotime($today) - strtotime($record['due_date']);
        $delayDays = max(0, (int)round($diff / 86400));
    }

    $record['delay_days']      = $delayDays;
    $record['active_students'] = $activeStudents;
    $record['exam_enrolments'] = $examEnrolments;
    $record['currency']        = $settings['fee_currency_symbol'];

    return $record;
}

/**
 * Record a payment received from a tenant administrator
 */
function record_admin_payment($admin_id, $month, $year, $amount_paid, $payment_method = 'UPI', $transaction_ref = '', $notes = '', $recorder_id = 1, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $record = get_or_create_admin_billing($admin_id, $month, $year, $pdo);
    $amount_paid = (float)$amount_paid;

    if ($amount_paid <= 0) {
        return ['success' => false, 'error' => 'Payment amount must be greater than zero.'];
    }

    $receiptNumber = $record['receipt_number'];
    if (empty($receiptNumber)) {
        $receiptNumber = sprintf('ADM-PAY-%04d%02d-A%d-%04d', $year, $month, $admin_id, random_int(1000, 9999));
    }

    $stmt = $pdo->prepare("
        UPDATE admin_payments SET
            amount_paid = ?,
            status = 'paid',
            payment_date = NOW(),
            payment_method = ?,
            transaction_ref = ?,
            receipt_number = ?,
            notes = ?,
            recorded_by_admin_id = ?
        WHERE id = ?
    ");

    $stmt->execute([
        $amount_paid,
        $payment_method,
        $transaction_ref,
        $receiptNumber,
        $notes,
        $recorder_id,
        $record['id']
    ]);

    return [
        'success'        => true,
        'message'        => "Payment of {$record['currency']}" . number_format($amount_paid, 2) . " recorded successfully.",
        'receipt_number' => $receiptNumber,
        'payment_id'     => $record['id'],
        'status'         => 'paid',
        'amount_paid'    => $amount_paid
    ];
}

/**
 * Dispatch an official payment reminder email to a tenant administrator regarding delayed/pending payment
 */
function send_admin_payment_reminder_email($admin_id, $month = null, $year = null, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $month = $month ? (int)$month : (int)date('n');
    $year  = $year ? (int)$year : (int)date('Y');

    // Fetch admin details
    $stmtAdm = $pdo->prepare("SELECT id, username, email, institute_name, role, parent_admin_id FROM admins WHERE id = ?");
    $stmtAdm->execute([$admin_id]);
    $admin = $stmtAdm->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        return ['success' => false, 'error' => 'Administrator account not found.'];
    }

    // Subadmins/employees are exempt from tenant billing and reminders
    if ($admin['role'] === 'sub_admin' || (!empty($admin['parent_admin_id']) && (int)$admin['parent_admin_id'] !== (int)$admin['id'])) {
        return ['success' => false, 'error' => "Administrator '{$admin['username']}' is an employee/staff member and is exempt from monthly platform billing."];
    }

    $email = trim($admin['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => "Administrator '{$admin['username']}' does not have a valid email configured."];
    }

    $billing = get_or_create_admin_billing($admin_id, $month, $year, $pdo);
    $settings = get_admin_billing_settings($pdo);

    $monthNames = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    $monthName = $monthNames[$month] ?? "Month $month";
    $formattedDue = $billing['currency'] . number_format($billing['amount_due'], 2);
    $formattedDueDate = date('d M Y', strtotime($billing['due_date']));
    $delayText = ($billing['delay_days'] > 0) ? "{$billing['delay_days']} days overdue" : "Due on {$formattedDueDate}";

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.zoho.in';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'shreyan_sncc@zohomail.in';
        $mail->Password   = getenv('SMTP_PASS') ?: 'TT5gcaYVwRp&iU';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        $smtpFromEmail = getenv('SMTP_FROM_EMAIL') ?: 'shreyan_sncc@zohomail.in';
        $fromName  = 'Platform Administration & Billing';

        $mail->setFrom($smtpFromEmail, $fromName);
        $mail->addAddress($email, $admin['username']);

        $mail->isHTML(true);
        $mail->Subject = "Important: Platform Subscription Payment Notice - {$admin['institute_name']} ({$monthName} {$year})";

        $body = "
        <div style='background-color: #f8fafc; padding: 25px 15px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <div style='max-width: 620px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;'>
                <!-- Header Banner -->
                <div style='background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%); padding: 28px 24px; color: #ffffff;'>
                    <h2 style='margin: 0 0 6px 0; font-size: 21px; font-weight: 800; letter-spacing: -0.5px;'>Platform Subscription Payment Notice</h2>
                    <p style='margin: 0; opacity: 0.9; font-size: 14px;'>Account Billing Statement • {$monthName} {$year}</p>
                </div>

                <!-- Admin & Enrolment Strip -->
                <div style='padding: 18px 24px; background: #fef2f2; border-bottom: 1px solid #fecaca;'>
                    <table style='width: 100%; font-size: 14px; color: #1e293b;'>
                        <tr>
                            <td style='padding: 3px 0;'><strong>Institute:</strong> {$admin['institute_name']}</td>
                            <td style='padding: 3px 0; text-align: right;'><strong>Administrator:</strong> {$admin['username']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 3px 0;'><strong>Active Enrolled Students:</strong> <span style='background: #fee2e2; color: #991b1b; padding: 2px 8px; border-radius: 6px; font-weight: bold;'>{$billing['enrolled_students']} Students</span></td>
                            <td style='padding: 3px 0; text-align: right;'><strong>Status:</strong> <span style='color: #dc2626; font-weight: bold;'>{$delayText}</span></td>
                        </tr>
                    </table>
                </div>

                <div style='padding: 24px;'>
                    <p style='font-size: 15px; color: #334155; line-height: 1.6; margin-top: 0;'>
                        Dear <strong>{$admin['username']}</strong>,
                    </p>
                    <p style='font-size: 14px; color: #475569; line-height: 1.6;'>
                        This is a notification regarding the platform maintenance and hosting subscription for <strong>{$admin['institute_name']}</strong> for the billing period of <strong>{$monthName} {$year}</strong>.
                    </p>

                    <!-- Amount Due Box -->
                    <div style='background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 8px; padding: 20px; text-align: center; margin: 20px 0;'>
                        <div style='font-size: 12px; text-transform: uppercase; color: #64748b; font-weight: bold;'>Outstanding Amount Due</div>
                        <div style='font-size: 32px; font-weight: 800; color: #dc2626; margin: 6px 0;'>{$formattedDue}</div>
                        <div style='font-size: 13px; color: #64748b;'>Due Date: <strong>{$formattedDueDate}</strong> (" . ($billing['delay_days'] > 0 ? "Delayed by {$billing['delay_days']} days" : "Action required") . ")</div>
                    </div>

                    <!-- Payment Information -->
                    <div style='background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px;'>
                        <h4 style='margin: 0 0 10px 0; font-size: 14px; color: #1e40af;'>Payment Instructions:</h4>
                        <p style='margin: 4px 0; font-size: 13px; color: #1e3a8a;'><strong>UPI ID:</strong> <span style='font-family: monospace; background: #ffffff; padding: 2px 6px; border-radius: 4px;'>{$settings['super_admin_upi_id']}</span></p>
                        <p style='margin: 4px 0; font-size: 13px; color: #1e3a8a;'><strong>Bank Details:</strong> {$settings['super_admin_bank_details']}</p>
                    </div>

                    <p style='font-size: 13px; color: #64748b; line-height: 1.5;'>
                        Timely settlement ensures seamless platform availability, live online examination hosting, and automated fee statements for your <strong>{$billing['enrolled_students']} registered students</strong>. After completing the transfer, please share the transaction reference with the Super Administrator.
                    </p>
                    <p style='font-size: 14px; color: #334155; margin-bottom: 0;'>
                        Best regards,<br>
                        <strong>Super Administrator Team</strong>
                    </p>
                </div>
            </div>
        </div>
        ";

        $mail->Body = $body;
        $mail->AltBody = "Payment Notice for {$admin['institute_name']}\nBilling Month: {$monthName} {$year}\nAmount Due: {$formattedDue}\nStatus: {$delayText}\nEnrolled Students: {$billing['enrolled_students']}\nUPI ID: {$settings['super_admin_upi_id']}";

        $mail->send();

        // Record reminder event
        $stmtUp = $pdo->prepare("UPDATE admin_payments SET reminder_sent_at = NOW(), reminder_count = reminder_count + 1 WHERE id = ?");
        $stmtUp->execute([$billing['id']]);

        return [
            'success'   => true,
            'message'   => "Payment reminder email successfully sent to {$admin['username']} ({$email}).",
            'admin_id'  => $admin_id,
            'sent_time' => date('d M Y, h:i A')
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error'   => "Failed to dispatch reminder email: " . $mail->ErrorInfo
        ];
    }
}

/**
 * Get comprehensive billing overview of all tenant administrators for a given month/year
 */
function get_all_admins_billing_overview($month = null, $year = null, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $month = $month ? (int)$month : (int)date('n');
    $year  = $year ? (int)$year : (int)date('Y');
    $settings = get_admin_billing_settings($pdo);

    // Fetch all tenant administrators (role = 'admin', excluding employees/sub-admins)
    $stmtAdmins = $pdo->query("
        SELECT id, username, email, institute_name, is_active, created_at
        FROM admins
        WHERE role = 'admin' AND (parent_admin_id IS NULL OR parent_admin_id = id)
        ORDER BY id ASC
    ");
    $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $totalBilled = 0;
    $totalCollected = 0;
    $totalPending = 0;
    $totalOverdue = 0;
    $paidCount = 0;
    $pendingCount = 0;
    $overdueCount = 0;
    $totalEnrolledStudents = 0;

    foreach ($admins as $adm) {
        $billing = get_or_create_admin_billing($adm['id'], $month, $year, $pdo);
        $totalEnrolledStudents += (int)$billing['enrolled_students'];
        $totalBilled += (float)$billing['amount_due'];

        if ($billing['status'] === 'paid') {
            $totalCollected += (float)$billing['amount_paid'];
            $paidCount++;
        } elseif ($billing['status'] === 'overdue') {
            $totalOverdue += (float)$billing['amount_due'];
            $overdueCount++;
        } else {
            $totalPending += (float)$billing['amount_due'];
            $pendingCount++;
        }

        $items[] = array_merge($adm, [
            'billing' => $billing
        ]);
    }

    return [
        'month'                   => $month,
        'year'                    => $year,
        'currency'                => $settings['fee_currency_symbol'],
        'settings'                => $settings,
        'admins'                  => $items,
        'total_admins'            => count($admins),
        'total_enrolled_students' => $totalEnrolledStudents,
        'total_billed'            => $totalBilled,
        'total_collected'         => $totalCollected,
        'total_pending'           => $totalPending,
        'total_overdue'           => $totalOverdue,
        'paid_count'              => $paidCount,
        'pending_count'           => $pendingCount,
        'overdue_count'           => $overdueCount
    ];
}

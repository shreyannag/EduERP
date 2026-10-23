<?php
// includes/fee_manager.php
// Student Fees Payment Engine, Ledger Calculation, and Automated Email Notifications.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Returns month names mapped 1-12
 */
function get_fee_months_list() {
    return [
        1  => 'January',
        2  => 'February',
        3  => 'March',
        4  => 'April',
        5  => 'May',
        6  => 'June',
        7  => 'July',
        8  => 'August',
        9  => 'September',
        10 => 'October',
        11 => 'November',
        12 => 'December'
    ];
}

/**
 * Get fee currency symbol from settings (default: ₹)
 */
function get_fee_currency_symbol($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'fee_currency_symbol'");
        $stmt->execute();
        $symbol = $stmt->fetchColumn();
        return !empty($symbol) ? $symbol : '₹';
    } catch (\Exception $e) {
        return '₹';
    }
}

/**
 * Retrieve monthly fee rate for a specific class
 */
function get_class_monthly_fee($class_id, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT monthly_fee FROM fee_structures WHERE class_id = ?");
        $stmt->execute([$class_id]);
        $fee = $stmt->fetchColumn();
        if ($fee !== false && $fee !== null) {
            return (float)$fee;
        }

        // Fallback to default
        $stmtDef = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'fee_default_monthly_amount'");
        $stmtDef->execute();
        $def = $stmtDef->fetchColumn();
        return $def !== false && $def !== null ? (float)$def : 1500.00;
    } catch (\Exception $e) {
        return 1500.00;
    }
}

/**
 * Update or insert class monthly fee rate
 */
function save_class_monthly_fee($class_id, $amount, $pdo) {
    $stmt = $pdo->prepare("
        INSERT INTO fee_structures (class_id, monthly_fee)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE monthly_fee = VALUES(monthly_fee)
    ");
    return $stmt->execute([$class_id, $amount]);
}

/**
 * Retrieve custom fee profile for a student if one exists.
 */
function get_student_custom_fee_profile($student_id, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM student_custom_fee_profiles WHERE student_id = ? AND is_active = 1");
        $stmt->execute([$student_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Calculates effective monthly fee for a student (accounting for custom fee profiles, scholarships, concessions).
 */
function get_student_effective_monthly_fee($student_id, $class_id = null, $month = null, $year = null, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    if ($class_id === null) {
        $stmtC = $pdo->prepare("SELECT class_id FROM students WHERE id = ?");
        $stmtC->execute([$student_id]);
        $class_id = (int)$stmtC->fetchColumn();
    }

    $standardFee = get_class_monthly_fee($class_id, $pdo);
    $profile = get_student_custom_fee_profile($student_id, $pdo);

    if (!$profile) {
        return [
            'base_fee'       => $standardFee,
            'effective_fee'  => $standardFee,
            'is_custom'      => false,
            'profile_type'   => 'standard',
            'discount_desc'  => null,
            'profile'        => null
        ];
    }

    // Check date range if specified
    if ($month !== null && $year !== null) {
        $targetDate = sprintf('%04d-%02d-01', (int)$year, (int)$month);
        if (!empty($profile['start_date']) && $targetDate < date('Y-m-01', strtotime($profile['start_date']))) {
            return [
                'base_fee'       => $standardFee,
                'effective_fee'  => $standardFee,
                'is_custom'      => false,
                'profile_type'   => 'standard',
                'discount_desc'  => null,
                'profile'        => null
            ];
        }
        if (!empty($profile['end_date']) && $targetDate > date('Y-m-01', strtotime($profile['end_date']))) {
            return [
                'base_fee'       => $standardFee,
                'effective_fee'  => $standardFee,
                'is_custom'      => false,
                'profile_type'   => 'standard',
                'discount_desc'  => null,
                'profile'        => null
            ];
        }
    }

    $type = $profile['profile_type'] ?? 'fixed_amount';
    $customAmount = (float)($profile['custom_amount'] ?? 0);
    $discountPct = (float)($profile['discount_percent'] ?? 0);
    $extraCharges = (float)($profile['additional_charges'] ?? 0);
    $effective = $standardFee;
    $desc = '';

    switch ($type) {
        case 'full_scholarship':
            $effective = 0.00;
            $desc = '100% Full Scholarship / Fee Exemption';
            break;
        case 'fixed_amount':
            $effective = max(0.00, $customAmount + $extraCharges);
            $desc = 'Special Negotiated Fixed Rate';
            break;
        case 'discount_percent':
            $discountVal = ($standardFee * $discountPct) / 100.0;
            $effective = max(0.00, ($standardFee - $discountVal) + $extraCharges);
            $desc = number_format($discountPct, 0) . '% Special Concession';
            break;
        case 'discount_amount':
            $effective = max(0.00, ($standardFee - $customAmount) + $extraCharges);
            $desc = 'Flat Concession of ₹' . number_format($customAmount, 2);
            break;
        default:
            $effective = $standardFee;
            break;
    }

    if (!empty($profile['reason'])) {
        $desc .= ' (' . $profile['reason'] . ')';
    }

    return [
        'base_fee'       => $standardFee,
        'effective_fee'  => $effective,
        'is_custom'      => true,
        'profile_type'   => $type,
        'discount_desc'  => $desc,
        'profile'        => $profile
    ];
}

function save_student_custom_fee_profile($student_id, array $data, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $profileType = $data['profile_type'] ?? 'fixed_amount';
    $customAmount = (float)($data['custom_amount'] ?? 0);
    $discountPct = (float)($data['discount_percent'] ?? 0);
    $additionalCharges = (float)($data['additional_charges'] ?? 0);
    $reason = trim($data['reason'] ?? '');
    $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
    $endDate = !empty($data['end_date']) ? $data['end_date'] : null;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    $createdBy = !empty($data['created_by']) ? (int)$data['created_by'] : null;

    $stmt = $pdo->prepare("
        INSERT INTO student_custom_fee_profiles 
        (student_id, profile_type, custom_amount, discount_percent, additional_charges, reason, start_date, end_date, is_active, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            profile_type = VALUES(profile_type),
            custom_amount = VALUES(custom_amount),
            discount_percent = VALUES(discount_percent),
            additional_charges = VALUES(additional_charges),
            reason = VALUES(reason),
            start_date = VALUES(start_date),
            end_date = VALUES(end_date),
            is_active = VALUES(is_active),
            updated_at = NOW()
    ");
    return $stmt->execute([
        $student_id, $profileType, $customAmount, $discountPct, $additionalCharges,
        $reason, $startDate, $endDate, $isActive, $createdBy
    ]);
}

function delete_student_custom_fee_profile($student_id, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }
    $stmt = $pdo->prepare("DELETE FROM student_custom_fee_profiles WHERE student_id = ?");
    return $stmt->execute([$student_id]);
}

/**
 * Generate a unique receipt number: e.g. REC-202610-0012-7A9B
 */
function generate_fee_receipt_number($student_id, $year, $month) {
    $monthPad = str_pad((int)$month, 2, '0', STR_PAD_LEFT);
    $stuPad   = str_pad((int)$student_id, 4, '0', STR_PAD_LEFT);
    $randHex  = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 4));
    return "REC-{$year}{$monthPad}-{$stuPad}-{$randHex}";
}

/**
 * Get complete annual fee ledger for an individual student
 */
function get_student_fee_ledger($student_id, $year = null, $pdo = null) {
    global $pdo;
    $year = $year ? (int)$year : (int)date('Y');
    $currentMonth = (int)date('n');
    $currentYear  = (int)date('Y');

    // Fetch student info with institute branding
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, b.name as board_name, a.institute_name, a.email as admin_email, a.username as admin_username
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN boards b ON s.board_id = b.id
        LEFT JOIN admins a ON s.admin_id = a.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return null;
    }

    $feeInfo = get_student_effective_monthly_fee($student_id, $student['class_id'], null, $year, $pdo);
    $monthlyRate = $feeInfo['effective_fee'];
    $student['custom_fee_profile'] = $feeInfo;

    // Fetch all recorded payments for this student in this year
    $stmtPayments = $pdo->prepare("
        SELECT * FROM student_fees 
        WHERE student_id = ? AND fee_year = ?
    ");
    $stmtPayments->execute([$student_id, $year]);
    $paymentRows = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

    $paymentMap = [];
    foreach ($paymentRows as $row) {
        $paymentMap[(int)$row['fee_month']] = $row;
    }

    $monthsList = get_fee_months_list();
    $ledger = [];
    $totalPaid = 0.00;
    $totalDue = 0.00;
    $paidMonthsCount = 0;
    $unpaidMonthsCount = 0;

    // Determine student enrollment year and month
    $enrDateStr = !empty($student['enrollment_date']) ? $student['enrollment_date'] : ($student['created_at'] ?? date('Y-m-d'));
    $enrYear = (int)date('Y', strtotime($enrDateStr));
    $enrMonth = (int)date('n', strtotime($enrDateStr));

    foreach ($monthsList as $mNum => $mName) {
        $isRecorded = isset($paymentMap[$mNum]);
        $record = $isRecorded ? $paymentMap[$mNum] : null;

        // Is this month before student's official enrollment?
        $isBeforeEnrollment = ($year < $enrYear) || ($year === $enrYear && $mNum < $enrMonth);

        // Is this month in the past/present relative to current date?
        $isPastOrCurrent = ($year < $currentYear) || ($year === $currentYear && $mNum <= $currentMonth);

        $mFeeInfo = get_student_effective_monthly_fee($student_id, $student['class_id'], $mNum, $year, $pdo);
        $monthDue = $mFeeInfo['effective_fee'];
        $monthPaid = 0.00;
        $status = 'unpaid';
        $payDate = null;
        $payMethod = null;
        $receipt = null;
        $notes = null;

        if ($record) {
            $monthDue = (float)$record['amount_due'] > 0 ? (float)$record['amount_due'] : $monthlyRate;
            $monthPaid = (float)$record['amount_paid'];
            $status = $record['status'];
            $payDate = $record['payment_date'];
            $payMethod = $record['payment_method'];
            $receipt = $record['receipt_number'];
            $notes = $record['notes'];
        } elseif ($isBeforeEnrollment) {
            // Student was not yet enrolled in this month: no dues, exempt
            $monthDue = 0.00;
            $monthPaid = 0.00;
            $status = 'not_enrolled';
            $payDate = null;
            $payMethod = null;
            $receipt = null;
            $notes = 'Not Enrolled';
        }

        if ($status === 'paid') {
            $totalPaid += $monthPaid;
            $paidMonthsCount++;
        } elseif ($status === 'partial') {
            $totalPaid += $monthPaid;
            $totalDue += max(0, $monthDue - $monthPaid);
            $unpaidMonthsCount++;
        } elseif ($status === 'not_enrolled') {
            // Pre-enrollment period: zero dues, not counted as unpaid
        } else {
            // Unpaid
            if ($isPastOrCurrent) {
                $totalDue += $monthDue;
                $unpaidMonthsCount++;
            }
        }

        $ledger[$mNum] = [
            'month_number'         => $mNum,
            'month_name'           => $mName,
            'year'                 => $year,
            'amount_due'           => $monthDue,
            'amount_paid'          => $monthPaid,
            'status'               => $status,
            'payment_date'         => $payDate,
            'payment_method'       => $payMethod,
            'receipt_number'       => $receipt,
            'notes'                => $notes,
            'is_before_enrollment' => $isBeforeEnrollment,
            'is_current_month'     => ($year === $currentYear && $mNum === $currentMonth),
            'is_past'              => ($year < $currentYear || ($year === $currentYear && $mNum < $currentMonth)),
            'is_future'            => ($year > $currentYear || ($year === $currentYear && $mNum > $currentMonth))
        ];
    }

    return [
        'student'              => $student,
        'enrollment_date'      => $enrDateStr,
        'enrollment_year'      => $enrYear,
        'enrollment_month'     => $enrMonth,
        'year'                 => $year,
        'monthly_rate'         => $monthlyRate,
        'currency'             => get_fee_currency_symbol($pdo),
        'ledger'               => $ledger,
        'total_paid'           => $totalPaid,
        'total_due'            => $totalDue,
        'paid_months_count'    => $paidMonthsCount,
        'unpaid_months_count'  => $unpaidMonthsCount,
        'current_month_status' => $ledger[$currentMonth]['status'] ?? 'unpaid'
    ];
}

/**
 * Record or update a student fee payment
 */
function record_student_fee_payment($student_id, $month, $year, $amount_paid, $payment_method = 'Cash', $ref = null, $notes = null, $pdo = null) {
    global $pdo;
    $student_id = (int)$student_id;
    $month = (int)$month;
    $year = (int)$year;
    $amount_paid = max(0.00, (float)$amount_paid);

    if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
        return ['success' => false, 'error' => 'Invalid month or year specification.'];
    }

    // Fetch class monthly rate
    $stmtStu = $pdo->prepare("SELECT class_id FROM students WHERE id = ?");
    $stmtStu->execute([$student_id]);
    $classId = $stmtStu->fetchColumn();

    if (!$classId) {
        return ['success' => false, 'error' => 'Student record not found.'];
    }

    $amountDue = get_class_monthly_fee($classId, $pdo);

    // Determine status
    if ($amount_paid >= $amountDue) {
        $status = 'paid';
    } elseif ($amount_paid > 0) {
        $status = 'partial';
    } else {
        $status = 'unpaid';
    }

    // Check existing record for receipt
    $stmtEx = $pdo->prepare("SELECT receipt_number FROM student_fees WHERE student_id = ? AND fee_month = ? AND fee_year = ?");
    $stmtEx->execute([$student_id, $month, $year]);
    $receipt = $stmtEx->fetchColumn();

    if (empty($receipt)) {
        $receipt = generate_fee_receipt_number($student_id, $year, $month);
    }

    $payDate = ($status === 'paid' || $status === 'partial') ? date('Y-m-d') : null;

    $stmt = $pdo->prepare("
        INSERT INTO student_fees (
            student_id, fee_month, fee_year, amount_due, amount_paid, status, 
            payment_date, payment_method, transaction_ref, receipt_number, notes
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE 
            amount_due = VALUES(amount_due),
            amount_paid = VALUES(amount_paid),
            status = VALUES(status),
            payment_date = VALUES(payment_date),
            payment_method = VALUES(payment_method),
            transaction_ref = VALUES(transaction_ref),
            notes = VALUES(notes),
            receipt_number = VALUES(receipt_number),
            updated_at = NOW()
    ");

    $success = $stmt->execute([
        $student_id,
        $month,
        $year,
        $amountDue,
        $amount_paid,
        $status,
        $payDate,
        $payment_method ?: 'Cash',
        $ref,
        $receipt,
        $notes
    ]);

    if ($success) {
        return [
            'success'        => true,
            'message'        => "Fee payment for " . get_fee_months_list()[$month] . " {$year} recorded successfully!",
            'receipt_number' => $receipt,
            'status'         => $status,
            'amount_paid'    => $amount_paid
        ];
    }

    return ['success' => false, 'error' => 'Database persistence failure.'];
}

/**
 * Get comprehensive fee summary for all active students (month/year)
 */
function get_all_students_fee_summary($year = null, $month = null, $pdo = null, $admin_id = null) {
    global $pdo;
    $year = $year ? (int)$year : (int)date('Y');
    $month = $month ? (int)$month : (int)date('n');

    $whereClause = "WHERE s.is_active = 1";
    $params = [];
    if (!empty($admin_id)) {
        $whereClause .= " AND s.admin_id = ?";
        $params[] = (int)$admin_id;
    }

    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, b.name as board_name, a.institute_name,
               COALESCE(fs.monthly_fee, 1500.00) as class_monthly_fee
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN boards b ON s.board_id = b.id
        LEFT JOIN admins a ON s.admin_id = a.id
        LEFT JOIN fee_structures fs ON (fs.class_id = s.class_id AND (fs.admin_id = s.admin_id OR fs.admin_id IS NULL))
        {$whereClause}
        ORDER BY CAST(c.name AS UNSIGNED) ASC, s.full_name ASC
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch payments for current month
    $stmtMonth = $pdo->prepare("
        SELECT * FROM student_fees 
        WHERE fee_year = ? AND fee_month = ?
    ");
    $stmtMonth->execute([$year, $month]);
    $monthPayments = $stmtMonth->fetchAll(PDO::FETCH_ASSOC);

    $monthPaymentMap = [];
    foreach ($monthPayments as $p) {
        $monthPaymentMap[$p['student_id']] = $p;
    }

    // Fetch all payments for entire year to calculate year-to-date dues
    $stmtYear = $pdo->prepare("
        SELECT student_id, fee_month, amount_paid, status, receipt_number, payment_date
        FROM student_fees
        WHERE fee_year = ?
    ");
    $stmtYear->execute([$year]);
    $yearPayments = $stmtYear->fetchAll(PDO::FETCH_ASSOC);

    $yearPaymentMap = [];
    foreach ($yearPayments as $yp) {
        $yearPaymentMap[$yp['student_id']][(int)$yp['fee_month']] = $yp;
    }

    $monthsList = get_fee_months_list();
    $totalStudents = count($students);
    $totalCollectedMonth = 0.00;
    $totalDueMonth = 0.00;
    $paidCountMonth = 0;
    $unpaidCountMonth = 0;
    $totalCollectedYear = 0.00;
    $totalDueYear = 0.00;

    $defaulters = [];
    $paidStudents = [];
    $studentSummaries = [];

    foreach ($students as $stu) {
        $stuId = (int)$stu['id'];
        $feeRate = (float)$stu['class_monthly_fee'];

        // Determine student enrollment year and month
        $enrDateStr = !empty($stu['enrollment_date']) ? $stu['enrollment_date'] : ($stu['created_at'] ?? date('Y-m-d'));
        $enrYear = (int)date('Y', strtotime($enrDateStr));
        $enrMonth = (int)date('n', strtotime($enrDateStr));

        $isTargetMonthBeforeEnrollment = ($year < $enrYear) || ($year === $enrYear && $month < $enrMonth);

        // Current Month status
        $currentMonthPayment = $monthPaymentMap[$stuId] ?? null;
        $isPaidCurrent = false;
        $paidAmountCurrent = 0.00;
        $dueAmountCurrent = $feeRate;

        if ($currentMonthPayment) {
            $paidAmountCurrent = (float)$currentMonthPayment['amount_paid'];
            if ($currentMonthPayment['status'] === 'paid') {
                $isPaidCurrent = true;
                $dueAmountCurrent = 0.00;
            } elseif ($currentMonthPayment['status'] === 'partial') {
                $dueAmountCurrent = max(0.00, $feeRate - $paidAmountCurrent);
            }
        } elseif ($isTargetMonthBeforeEnrollment) {
            // Student was not yet enrolled in this month
            $dueAmountCurrent = 0.00;
            $isPaidCurrent = true; // exempt / not due
        }

        $totalCollectedMonth += $paidAmountCurrent;
        $totalDueMonth += $dueAmountCurrent;

        if ($isTargetMonthBeforeEnrollment) {
            // Pre-enrollment month: not counted as unpaid
        } elseif ($isPaidCurrent) {
            $paidCountMonth++;
        } else {
            $unpaidCountMonth++;
        }

        // Full Year Ledger analysis
        $unpaidMonthsNames = [];
        $paidMonthsNames = [];
        $totalStuPaidYear = 0.00;
        $totalStuDueYear = 0.00;

        for ($m = 1; $m <= 12; $m++) {
            $rec = $yearPaymentMap[$stuId][$m] ?? null;
            $mPast = ($year < (int)date('Y')) || ($year === (int)date('Y') && $m <= (int)date('n'));
            $isMBeforeEnrollment = ($year < $enrYear) || ($year === $enrYear && $m < $enrMonth);

            if ($rec && $rec['status'] === 'paid') {
                $totalStuPaidYear += (float)$rec['amount_paid'];
                $paidMonthsNames[] = $monthsList[$m];
            } elseif ($rec && $rec['status'] === 'partial') {
                $totalStuPaidYear += (float)$rec['amount_paid'];
                $mDue = max(0.00, $feeRate - (float)$rec['amount_paid']);
                $totalStuDueYear += $mDue;
                $unpaidMonthsNames[] = $monthsList[$m] . " (Bal: ₹{$mDue})";
            } elseif ($isMBeforeEnrollment) {
                // Not enrolled, zero dues
            } else {
                if ($mPast) {
                    $totalStuDueYear += $feeRate;
                    $unpaidMonthsNames[] = $monthsList[$m];
                }
            }
        }

        $totalCollectedYear += $totalStuPaidYear;
        $totalDueYear += $totalStuDueYear;

        $currentMonthStatus = 'unpaid';
        if ($isTargetMonthBeforeEnrollment) {
            $currentMonthStatus = ($currentMonthPayment && $currentMonthPayment['status'] === 'paid') ? 'paid' : 'not_enrolled';
        } elseif ($isPaidCurrent) {
            $currentMonthStatus = 'paid';
        } elseif ($paidAmountCurrent > 0) {
            $currentMonthStatus = 'partial';
        }

        $summaryItem = [
            'student_id'                  => $stuId,
            'full_name'                   => $stu['full_name'],
            'email'                       => $stu['email'],
            'username'                    => $stu['username'],
            'class_id'                    => $stu['class_id'],
            'class_name'                  => $stu['class_name'],
            'board_name'                  => $stu['board_name'],
            'enrollment_date'             => $enrDateStr,
            'is_target_before_enrollment' => $isTargetMonthBeforeEnrollment,
            'monthly_fee'                 => $feeRate,
            'is_paid_current'             => ($currentMonthStatus === 'paid' || $currentMonthStatus === 'not_enrolled'),
            'current_month_paid'          => $paidAmountCurrent,
            'current_month_due'           => $dueAmountCurrent,
            'current_month_status'        => $currentMonthStatus,
            'current_month_receipt'       => $currentMonthPayment['receipt_number'] ?? null,
            'current_month_date'          => $currentMonthPayment['payment_date'] ?? null,
            'paid_months_names'           => $paidMonthsNames,
            'unpaid_months_names'         => $unpaidMonthsNames,
            'total_paid_year'             => $totalStuPaidYear,
            'total_due_year'              => $totalStuDueYear,
            'has_year_dues'               => ($totalStuDueYear > 0)
        ];

        $studentSummaries[] = $summaryItem;

        if ($totalStuDueYear > 0 || (!$isTargetMonthBeforeEnrollment && !$isPaidCurrent)) {
            $defaulters[] = $summaryItem;
        } else {
            $paidStudents[] = $summaryItem;
        }
    }

    $collectionRateMonth = ($totalCollectedMonth + $totalDueMonth) > 0 
        ? round(($totalCollectedMonth / ($totalCollectedMonth + $totalDueMonth)) * 100, 1) 
        : 0;

    return [
        'year'                   => $year,
        'month'                  => $month,
        'month_name'             => $monthsList[$month],
        'currency'               => get_fee_currency_symbol($pdo),
        'total_students'         => $totalStudents,
        'paid_count_month'       => $paidCountMonth,
        'unpaid_count_month'     => $unpaidCountMonth,
        'total_collected_month'  => $totalCollectedMonth,
        'total_due_month'        => $totalDueMonth,
        'collection_rate_month'  => $collectionRateMonth,
        'total_collected_year'   => $totalCollectedYear,
        'total_due_year'         => $totalDueYear,
        'students'               => $studentSummaries,
        'defaulters'             => $defaulters,
        'paid_students'          => $paidStudents
    ];
}

/**
 * Send an email statement to student detailing paid months and unpaid months
 */
function send_student_fee_statement_email($student_id, $year = null, $pdo = null, $sender_admin_id = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }
    $year = $year ? (int)$year : (int)date('Y');
    $ledgerData = get_student_fee_ledger($student_id, $year, $pdo);

    if (!$ledgerData) {
        return ['success' => false, 'error' => 'Student record not found.'];
    }

    $student = $ledgerData['student'];
    $email = trim($student['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => "Student '{$student['full_name']}' does not have a valid email address."];
    }

    $currency = $ledgerData['currency'];
    $monthsList = get_fee_months_list();

    $paidRowsHtml = '';
    $unpaidRowsHtml = '';

    foreach ($ledgerData['ledger'] as $item) {
        if ($item['status'] === 'paid') {
            $payDateFormatted = $item['payment_date'] ? date('d M Y', strtotime($item['payment_date'])) : 'Recorded';
            $paidRowsHtml .= "
                <tr style='border-bottom: 1px solid #e2e8f0;'>
                    <td style='padding: 10px 12px; font-weight: bold; color: #1e293b;'>{$item['month_name']} {$year}</td>
                    <td style='padding: 10px 12px; color: #166534; font-weight: bold;'>{$currency}" . number_format($item['amount_paid'], 2) . "</td>
                    <td style='padding: 10px 12px; color: #475569;'>{$payDateFormatted}</td>
                    <td style='padding: 10px 12px; color: #475569;'>{$item['payment_method']}</td>
                    <td style='padding: 10px 12px; font-family: monospace; font-size: 12px; color: #0f766e;'>{$item['receipt_number']}</td>
                    <td style='padding: 10px 12px;'><span style='background: #dcfce7; color: #15803d; padding: 3px 8px; border-radius: 9999px; font-size: 11px; font-weight: bold;'>PAID</span></td>
                </tr>
            ";
        } elseif ($item['status'] !== 'not_enrolled' && ($item['is_past'] || $item['is_current_month'])) {
            $statusText = ($item['status'] === 'partial') ? 'PARTIAL' : 'DUE / UNPAID';
            $badgeBg = ($item['status'] === 'partial') ? '#fef3c7' : '#fee2e2';
            $badgeColor = ($item['status'] === 'partial') ? '#b45309' : '#b91c1c';
            $dueAmt = ($item['status'] === 'partial') ? ($item['amount_due'] - $item['amount_paid']) : $item['amount_due'];

            $unpaidRowsHtml .= "
                <tr style='border-bottom: 1px solid #e2e8f0;'>
                    <td style='padding: 10px 12px; font-weight: bold; color: #1e293b;'>{$item['month_name']} {$year}</td>
                    <td style='padding: 10px 12px; color: #b91c1c; font-weight: bold;'>{$currency}" . number_format($dueAmt, 2) . "</td>
                    <td style='padding: 10px 12px; color: #dc2626;'>Payment Pending</td>
                    <td style='padding: 10px 12px;' colspan='2'><span style='background: {$badgeBg}; color: {$badgeColor}; padding: 3px 8px; border-radius: 9999px; font-size: 11px; font-weight: bold;'>{$statusText}</span></td>
                </tr>
            ";
        }
    }

    if (empty($paidRowsHtml)) {
        $paidRowsHtml = "<tr><td colspan='6' style='padding: 14px; text-align: center; color: #94a3b8;'>No payments recorded yet for {$year}.</td></tr>";
    }

    if (empty($unpaidRowsHtml)) {
        $unpaidRowsHtml = "<tr><td colspan='4' style='padding: 14px; text-align: center; color: #16a34a; font-weight: bold;'>🎉 Excellent! All fees are fully paid and up-to-date.</td></tr>";
    }

    $siteUrl = getenv('BASE_URL') ?: 'http://localhost/shreyansir';

    require_once __DIR__ . '/mailer.php';

    // Determine the administrator whose personal SMTP will physically dispatch this statement
    $admin_id = null;
    if (!empty($sender_admin_id)) {
        $admin_id = (int)$sender_admin_id;
    } elseif (!empty($_SESSION['admin_id']) && (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin')) {
        // Tenant administrator is logged in and initiating this payment/statement
        $admin_id = (int)$_SESSION['admin_id'];
    } elseif (!empty($student['admin_id'])) {
        $admin_id = (int)$student['admin_id'];
    } elseif (!empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    } else {
        $admin_id = 1;
    }

    list($mail, $smtpConfig) = create_admin_mailer($admin_id, $pdo);

    // Fetch sending administrator profile details
    $adminEmail = '';
    $instituteName = 'Online Examination System';
    try {
        $stmtAdm = $pdo->prepare("SELECT email, institute_name FROM admins WHERE id = ?");
        $stmtAdm->execute([$admin_id]);
        $admRow = $stmtAdm->fetch(\PDO::FETCH_ASSOC);
        if ($admRow) {
            $adminEmail = trim($admRow['email'] ?? '');
            $instituteName = trim($admRow['institute_name'] ?? '');
        }
    } catch (\Throwable $e) {}

    if (empty($instituteName)) {
        $instituteName = !empty($student['institute_name']) ? trim($student['institute_name']) : 'Online Examination Portal';
    }
    if (empty($adminEmail)) {
        $adminEmail = !empty($student['admin_email']) ? trim($student['admin_email']) : '';
    }
    if (!empty($smtpConfig['from_name'])) {
        $instituteName = $smtpConfig['from_name'];
    }
    if (!empty($smtpConfig['from_email'])) {
        $adminEmail = $smtpConfig['from_email'];
    }

    try {

        $mail->addAddress($email, $student['full_name']);

        $mail->isHTML(true);
        $mail->Subject = "Fee Payment Statement & Monthly Status - {$student['full_name']} ({$year}) - {$instituteName}";

        $adminHeaderInfo = !empty($adminEmail)
            ? "<div style='margin-top: 8px; font-size: 13px; opacity: 0.9;'>Issued by: <strong>" . htmlspecialchars($instituteName) . "</strong> &bull; Contact: <a href='mailto:" . htmlspecialchars($adminEmail) . "' style='color: #ffffff; text-decoration: underline; font-weight: bold;'>" . htmlspecialchars($adminEmail) . "</a></div>"
            : "";

        $enrollmentFormatted = date('d M Y', strtotime($ledgerData['enrollment_date']));

        $body = "
        <div style='background-color: #f8fafc; padding: 25px 15px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <div style='max-width: 650px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #e2e8f0;'>
                <!-- Header -->
                <div style='background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding: 30px 25px; color: #ffffff;'>
                    <h2 style='margin: 0 0 6px 0; font-size: 22px; font-weight: 800; letter-spacing: -0.5px;'>{$instituteName}</h2>
                    <p style='margin: 0; opacity: 0.85; font-size: 14px;'>Fee Payment Statement & Student Account Ledger • Year {$year}</p>
                    {$adminHeaderInfo}
                </div>

                <!-- Student Info Strip -->
                <div style='padding: 20px 25px; background: #f1f5f9; border-bottom: 1px solid #e2e8f0;'>
                    <table style='width: 100%; font-size: 14px; color: #334155;'>
                        <tr>
                            <td style='padding: 4px 0;'><strong>Student Name:</strong> {$student['full_name']}</td>
                            <td style='padding: 4px 0;'><strong>Student ID / Roll:</strong> {$student['username']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 4px 0;'><strong>Class / Grade:</strong> Class {$student['class_name']}</td>
                            <td style='padding: 4px 0;'><strong>Curriculum Board:</strong> {$student['board_name']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 4px 0;' colspan='2'><strong>Enrollment / Joining Date:</strong> {$enrollmentFormatted} <span style='color: #64748b; font-size: 12px;'>(Monthly fees tracked from this date)</span></td>
                        </tr>
                    </table>
                </div>

                <!-- Financial Highlights -->
                <div style='padding: 25px; border-bottom: 1px solid #e2e8f0;'>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='width: 50%; padding-right: 10px;'>
                                <div style='background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 15px; text-align: center;'>
                                    <div style='font-size: 12px; font-weight: bold; color: #047857; text-transform: uppercase;'>Total Fees Paid ({$year})</div>
                                    <div style='font-size: 22px; font-weight: 800; color: #065f46; margin-top: 4px;'>{$currency}" . number_format($ledgerData['total_paid'], 2) . "</div>
                                    <div style='font-size: 12px; color: #059669; margin-top: 2px;'>{$ledgerData['paid_months_count']} Month(s) Paid</div>
                                </div>
                            </td>
                            <td style='width: 50%; padding-left: 10px;'>
                                <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 15px; text-align: center;'>
                                    <div style='font-size: 12px; font-weight: bold; color: #b91c1c; text-transform: uppercase;'>Outstanding Balance Due</div>
                                    <div style='font-size: 22px; font-weight: 800; color: #991b1b; margin-top: 4px;'>{$currency}" . number_format($ledgerData['total_due'], 2) . "</div>
                                    <div style='font-size: 12px; color: #dc2626; margin-top: 2px;'>{$ledgerData['unpaid_months_count']} Month(s) Pending</div>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Paid Months Table -->
                <div style='padding: 25px;'>
                    <h3 style='font-size: 16px; color: #166534; margin: 0 0 12px 0; display: flex; align-items: center;'>
                        <span style='background: #22c55e; color: #ffffff; width: 22px; height: 22px; border-radius: 50%; display: inline-block; text-align: center; line-height: 22px; font-size: 12px; margin-right: 8px;'>✓</span>
                        Verified Paid Months
                    </h3>
                    <table style='width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;'>
                        <thead>
                            <tr style='background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #64748b;'>
                                <th style='padding: 10px 12px;'>Month</th>
                                <th style='padding: 10px 12px;'>Amount Paid</th>
                                <th style='padding: 10px 12px;'>Date</th>
                                <th style='padding: 10px 12px;'>Method</th>
                                <th style='padding: 10px 12px;'>Receipt #</th>
                                <th style='padding: 10px 12px;'>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$paidRowsHtml}
                        </tbody>
                    </table>
                </div>

                <!-- Unpaid Months Table -->
                <div style='padding: 0 25px 25px 25px;'>
                    <h3 style='font-size: 16px; color: #991b1b; margin: 0 0 12px 0; display: flex; align-items: center;'>
                        <span style='background: #ef4444; color: #ffffff; width: 22px; height: 22px; border-radius: 50%; display: inline-block; text-align: center; line-height: 22px; font-size: 12px; margin-right: 8px;'>!</span>
                        Pending / Unpaid Months
                    </h3>
                    <table style='width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden;'>
                        <thead>
                            <tr style='background: #f8fafc; border-bottom: 2px solid #e2e8f0; color: #64748b;'>
                                <th style='padding: 10px 12px;'>Month</th>
                                <th style='padding: 10px 12px;'>Amount Due</th>
                                <th style='padding: 10px 12px;'>Payment Status</th>
                                <th style='padding: 10px 12px;'>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$unpaidRowsHtml}
                        </tbody>
                    </table>
                </div>

                <!-- Footer & Portal link -->
                <div style='padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center; font-size: 13px; color: #64748b;'>
                    <p style='margin: 0 0 10px 0;'>You can also log in to your Student Portal to view real-time records and receipts.</p>
                    <p style='margin: 15px 0 0 0; font-size: 11px; color: #94a3b8;'>This statement was issued by <strong>{$instituteName}</strong>. For any queries or receipt corrections, please contact your administrator directly" . (!empty($adminEmail) ? " at <a href='mailto:{$adminEmail}' style='color: #2563eb; font-weight: bold;'>{$adminEmail}</a>" : "") . ".</p>
                </div>
            </div>
        </div>
        ";

        $mail->Body = $body;
        $mail->AltBody = "Fee Statement for {$student['full_name']} ({$year})\n\nTotal Paid: {$currency}" . number_format($ledgerData['total_paid'], 2) . "\nTotal Outstanding Due: {$currency}" . number_format($ledgerData['total_due'], 2) . "\n\nPlease check your student portal for receipt details.";

        $mail->send();
        $senderHost = !empty($mail->Host) ? $mail->Host : 'SMTP';
        $senderFrom = !empty($mail->From) ? $mail->From : 'admin';
        return [
            'success' => true,
            'message' => "Fee statement email successfully dispatched to {$email} via {$senderFrom} ({$senderHost})!"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error'   => "Mailer Error: " . $mail->ErrorInfo
        ];
    }
}

/**
 * Send automated fee collection reminder/digest to admin (Middle & End of Month)
 */
function send_admin_fee_digest_email($pdo = null, $reminder_type = 'manual', $target_admin_id = null) {
    if (!$pdo) {
        global $pdo;
    }
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }
    $currentYear  = (int)date('Y');
    $currentMonth = (int)date('n');

    $target_admin_id = $target_admin_id ? (int)$target_admin_id : (!empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1);

    // Retrieve summary of students for this admin
    $scoped_id = ($target_admin_id > 1) ? $target_admin_id : null;
    $summary = get_all_students_fee_summary($currentYear, $currentMonth, $pdo, $scoped_id);

    // Retrieve Admin Email and Institute details
    $adminEmail = '';
    $instituteName = 'Online Examination System';
    try {
        $stmtAdmin = $pdo->prepare("SELECT email, institute_name FROM admins WHERE id = ?");
        $stmtAdmin->execute([$target_admin_id]);
        $admRow = $stmtAdmin->fetch(\PDO::FETCH_ASSOC);
        if ($admRow) {
            $adminEmail = trim($admRow['email'] ?? '');
            $instituteName = trim($admRow['institute_name'] ?? '');
        }
    } catch (\Throwable $e) {}

    if (empty($adminEmail)) {
        $adminEmail = 'shreyan_sncc@zohomail.in';
    }

    $currency = $summary['currency'];
    $monthName = $summary['month_name'];

    // Determine Title & Type
    $title = "Student Fees Status Report";
    $subtitle = "Overview of monthly fee collections and pending student accounts";
    if ($reminder_type === 'mid_month') {
        $title = "Mid-Month Fee Collection Reminder (15th {$monthName})";
        $subtitle = "Mid-month reminder of student fee payments received and accounts currently pending";
    } elseif ($reminder_type === 'end_month') {
        $title = "Month-End Fee Collection Summary (End of {$monthName})";
        $subtitle = "Final month-end summary of collected fees and outstanding student dues";
    }

    // Build Defaulters HTML
    $defaultersHtml = '';
    foreach ($summary['defaulters'] as $d) {
        $unpaidList = !empty($d['unpaid_months_names']) ? implode(', ', $d['unpaid_months_names']) : 'Current Month';
        $emailDisplay = !empty($d['email']) ? $d['email'] : "<span style='color:#94a3b8;'>No email</span>";
        $defaultersHtml .= "
            <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 10px 12px; font-weight: bold; color: #1e293b;'>{$d['full_name']}</td>
                <td style='padding: 10px 12px; color: #475569;'>Class {$d['class_name']} ({$d['board_name']})</td>
                <td style='padding: 10px 12px; color: #b91c1c; font-weight: bold;'>{$currency}" . number_format($d['total_due_year'], 2) . "</td>
                <td style='padding: 10px 12px; color: #64748b; font-size: 12px;'>{$unpaidList}</td>
                <td style='padding: 10px 12px; font-size: 12px; color: #475569;'>{$emailDisplay}</td>
            </tr>
        ";
    }

    if (empty($defaultersHtml)) {
        $defaultersHtml = "<tr><td colspan='5' style='padding: 15px; text-align: center; color: #16a34a; font-weight: bold;'>All students have paid their dues! No defaulters.</td></tr>";
    }

    // Build Paid Students HTML (Top 10 recent)
    $paidHtml = '';
    $recentPaid = array_slice($summary['paid_students'], 0, 15);
    foreach ($recentPaid as $p) {
        $paidDateFormatted = $p['current_month_date'] ? date('d M Y', strtotime($p['current_month_date'])) : 'Recorded';
        $paidHtml .= "
            <tr style='border-bottom: 1px solid #f1f5f9;'>
                <td style='padding: 9px 12px; font-weight: bold; color: #1e293b;'>{$p['full_name']}</td>
                <td style='padding: 9px 12px; color: #475569;'>Class {$p['class_name']}</td>
                <td style='padding: 9px 12px; color: #166534; font-weight: bold;'>{$currency}" . number_format($p['current_month_paid'], 2) . "</td>
                <td style='padding: 9px 12px; color: #64748b; font-size: 12px;'>{$paidDateFormatted}</td>
                <td style='padding: 9px 12px; font-family: monospace; font-size: 11px; color: #0f766e;'>{$p['current_month_receipt']}</td>
            </tr>
        ";
    }

    if (empty($paidHtml)) {
        $paidHtml = "<tr><td colspan='5' style='padding: 15px; text-align: center; color: #94a3b8;'>No payments recorded yet for {$monthName}.</td></tr>";
    }

    $siteUrl = getenv('BASE_URL') ?: 'http://localhost/shreyansir';

    require_once __DIR__ . '/mailer.php';
    list($mail, $smtpConfig) = create_admin_mailer($target_admin_id, $pdo);

    try {
        $mail->addAddress($adminEmail, !empty($instituteName) ? $instituteName : 'Administrator');

        $mail->isHTML(true);
        $mail->Subject = "[Fee Report] {$title} - {$monthName} {$currentYear} - {$instituteName}";

        $body = "
        <div style='background-color: #f1f5f9; padding: 25px 15px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif;'>
            <div style='max-width: 700px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.06); border: 1px solid #cbd5e1;'>
                <!-- Header -->
                <div style='background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%); padding: 30px 25px; color: #ffffff;'>
                    <span style='background: #3b82f6; color: #ffffff; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;'>Admin Notification</span>
                    <h2 style='margin: 10px 0 6px 0; font-size: 22px; font-weight: 800;'>{$title}</h2>
                    <p style='margin: 0; opacity: 0.8; font-size: 14px;'>{$subtitle} • Academic Year {$currentYear}</p>
                </div>

                <!-- 4 KPI Cards -->
                <div style='padding: 25px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;'>
                    <table style='width: 100%; border-collapse: separate; border-spacing: 10px;'>
                        <tr>
                            <td style='width: 25%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 10px; text-align: center;'>
                                <div style='font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase;'>Total Students</div>
                                <div style='font-size: 22px; font-weight: 800; color: #0f172a; margin-top: 4px;'>{$summary['total_students']}</div>
                            </td>
                            <td style='width: 25%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 10px; text-align: center;'>
                                <div style='font-size: 11px; font-weight: bold; color: #16a34a; text-transform: uppercase;'>Collected ({$monthName})</div>
                                <div style='font-size: 20px; font-weight: 800; color: #15803d; margin-top: 4px;'>{$currency}" . number_format($summary['total_collected_month'], 0) . "</div>
                                <div style='font-size: 11px; color: #166534;'>{$summary['paid_count_month']} Paid</div>
                            </td>
                            <td style='width: 25%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 10px; text-align: center;'>
                                <div style='font-size: 11px; font-weight: bold; color: #dc2626; text-transform: uppercase;'>Pending ({$monthName})</div>
                                <div style='font-size: 20px; font-weight: 800; color: #b91c1c; margin-top: 4px;'>{$currency}" . number_format($summary['total_due_month'], 0) . "</div>
                                <div style='font-size: 11px; color: #991b1b;'>{$summary['unpaid_count_month']} Pending</div>
                            </td>
                            <td style='width: 25%; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 10px; text-align: center;'>
                                <div style='font-size: 11px; font-weight: bold; color: #2563eb; text-transform: uppercase;'>Collection Rate</div>
                                <div style='font-size: 22px; font-weight: 800; color: #1d4ed8; margin-top: 4px;'>{$summary['collection_rate_month']}%</div>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Defaulters Section -->
                <div style='padding: 25px;'>
                    <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;'>
                        <h3 style='font-size: 16px; color: #b91c1c; margin: 0;'>
                            ⚠️ Students with Pending Dues (" . count($summary['defaulters']) . ")
                        </h3>
                    </div>
                    <table style='width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; background: #ffffff; border: 1px solid #fecaca; border-radius: 6px; overflow: hidden;'>
                        <thead>
                            <tr style='background: #fef2f2; border-bottom: 2px solid #fecaca; color: #991b1b;'>
                                <th style='padding: 10px 12px;'>Student Name</th>
                                <th style='padding: 10px 12px;'>Class</th>
                                <th style='padding: 10px 12px;'>Total Due</th>
                                <th style='padding: 10px 12px;'>Pending Months</th>
                                <th style='padding: 10px 12px;'>Email Contact</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$defaultersHtml}
                        </tbody>
                    </table>
                </div>

                <!-- Recent Collections Section -->
                <div style='padding: 0 25px 25px 25px;'>
                    <h3 style='font-size: 16px; color: #166534; margin: 0 0 12px 0;'>
                        ✓ Verified Collections for {$monthName} (" . count($summary['paid_students']) . ")
                    </h3>
                    <table style='width: 100%; border-collapse: collapse; font-size: 13px; text-align: left; background: #ffffff; border: 1px solid #bbf7d0; border-radius: 6px; overflow: hidden;'>
                        <thead>
                            <tr style='background: #f0fdf4; border-bottom: 2px solid #bbf7d0; color: #166534;'>
                                <th style='padding: 10px 12px;'>Student Name</th>
                                <th style='padding: 10px 12px;'>Class</th>
                                <th style='padding: 10px 12px;'>Amount Paid</th>
                                <th style='padding: 10px 12px;'>Payment Date</th>
                                <th style='padding: 10px 12px;'>Receipt #</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$paidHtml}
                        </tbody>
                    </table>
                </div>

                <!-- Action Button & Footer -->
                <div style='padding: 20px 25px; background: #f8fafc; border-top: 1px solid #e2e8f0; text-align: center;'>
                    <a href='{$siteUrl}/admin/fees/index.php' style='display: inline-block; background: #0f172a; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; font-size: 14px;'>Manage Fees in Admin Portal</a>
                    <p style='margin: 15px 0 0 0; font-size: 12px; color: #94a3b8;'>Automated notification generated by the Online Examination & Student Fees System.</p>
                </div>
            </div>
        </div>
        ";

        $mail->Body = $body;
        $mail->AltBody = "{$title} ({$monthName} {$currentYear})\n\nTotal Students: {$summary['total_students']}\nCollected this month: {$currency}" . number_format($summary['total_collected_month'], 2) . "\nPending this month: {$currency}" . number_format($summary['total_due_month'], 2) . "\nCollection Rate: {$summary['collection_rate_month']}%\nDefaulters: " . count($summary['defaulters']);

        $mail->send();

        // Update last reminder tracker in settings
        if ($reminder_type === 'mid_month') {
            $pdo->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('fee_last_midmonth_reminder', '" . date('Y-m') . "') ON DUPLICATE KEY UPDATE setting_value = '" . date('Y-m') . "'");
        } elseif ($reminder_type === 'end_month') {
            $pdo->query("INSERT INTO system_settings (setting_key, setting_value) VALUES ('fee_last_endmonth_reminder', '" . date('Y-m') . "') ON DUPLICATE KEY UPDATE setting_value = '" . date('Y-m') . "'");
        }

        return [
            'success' => true,
            'message' => "Fee status digest email successfully delivered to Administrator ({$adminEmail})!"
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error'   => "Mailer Error: " . $mail->ErrorInfo
        ];
    }
}

<?php
// admin/fees/custom_profile_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/fee_manager.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_feature('fees');

$action = trim($_REQUEST['action'] ?? '');
$scoped_admin_id = is_super_admin() ? (!empty($_REQUEST['admin_id']) ? (int)$_REQUEST['admin_id'] : null) : get_effective_admin_id();

if ($action === 'get') {
    $student_id = (int)($_GET['student_id'] ?? 0);
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Valid student ID is required.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.username, s.class_id, s.admin_id, c.name as class_name, a.institute_name
        FROM students s
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN admins a ON s.admin_id = a.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student record not found.']);
        exit;
    }

    // Permission check for scoped admin
    if (!is_super_admin() && (int)$student['admin_id'] !== $scoped_admin_id) {
        echo json_encode(['success' => false, 'error' => 'Access denied to this student record.']);
        exit;
    }

    $standardFee = get_class_monthly_fee($student['class_id'], $pdo);
    $feeInfo = get_student_effective_monthly_fee($student_id, $student['class_id'], null, null, $pdo);
    $currency = get_fee_currency_symbol($pdo);

    echo json_encode([
        'success'      => true,
        'student'      => $student,
        'standard_fee' => $standardFee,
        'currency'     => $currency,
        'fee_info'     => $feeInfo,
        'profile'      => $feeInfo['profile']
    ]);
    exit;
}

if ($action === 'save') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    verify_csrf_token($_POST['csrf_token'] ?? '');

    $student_id = (int)($_POST['student_id'] ?? 0);
    $profile_type = trim($_POST['profile_type'] ?? 'fixed_amount');
    $custom_amount = (float)($_POST['custom_amount'] ?? 0);
    $discount_percent = (float)($_POST['discount_percent'] ?? 0);
    $additional_charges = (float)($_POST['additional_charges'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;

    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Valid student ID is required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, name, admin_id, class_id FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student record not found.']);
        exit;
    }

    if (!is_super_admin() && (int)$student['admin_id'] !== $scoped_admin_id) {
        echo json_encode(['success' => false, 'error' => 'Access denied. You can only customize fees for your own students.']);
        exit;
    }

    if (!in_array($profile_type, ['fixed_amount', 'discount_percent', 'discount_amount', 'full_scholarship'], true)) {
        echo json_encode(['success' => false, 'error' => 'Invalid fee profile type selected.']);
        exit;
    }

    if ($profile_type === 'discount_percent' && ($discount_percent < 0 || $discount_percent > 100)) {
        echo json_encode(['success' => false, 'error' => 'Discount percentage must be between 0% and 100%.']);
        exit;
    }

    $res = save_student_custom_fee_profile($student_id, [
        'profile_type'       => $profile_type,
        'custom_amount'      => $custom_amount,
        'discount_percent'   => $discount_percent,
        'additional_charges' => $additional_charges,
        'reason'             => $reason,
        'start_date'         => $start_date,
        'end_date'           => $end_date,
        'is_active'          => 1,
        'created_by'         => (int)$_SESSION['admin_id']
    ], $pdo);

    if ($res) {
        $updatedFeeInfo = get_student_effective_monthly_fee($student_id, $student['class_id'], null, null, $pdo);
        echo json_encode([
            'success'        => true,
            'message'        => "Custom fee profile saved successfully for {$student['name']}!",
            'effective_fee'  => $updatedFeeInfo['effective_fee'],
            'discount_desc'  => $updatedFeeInfo['discount_desc']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to persist custom fee profile.']);
    }
    exit;
}

if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    verify_csrf_token($_POST['csrf_token'] ?? '');

    $student_id = (int)($_POST['student_id'] ?? 0);
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Valid student ID is required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, name, admin_id, class_id FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        echo json_encode(['success' => false, 'error' => 'Student record not found.']);
        exit;
    }

    if (!is_super_admin() && (int)$student['admin_id'] !== $scoped_admin_id) {
        echo json_encode(['success' => false, 'error' => 'Access denied.']);
        exit;
    }

    $del = delete_student_custom_fee_profile($student_id, $pdo);
    $standardFee = get_class_monthly_fee($student['class_id'], $pdo);

    echo json_encode([
        'success'      => true,
        'message'      => "Custom fee profile removed. {$student['name']} has been reset to standard class criteria.",
        'standard_fee' => $standardFee
    ]);
    exit;
}

if ($action === 'list') {
    $sql = "
        SELECT p.*, s.name as student_name, s.username, s.roll_no, s.class_id, c.name as class_name, a.institute_name
        FROM student_custom_fee_profiles p
        JOIN students s ON p.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN admins a ON s.admin_id = a.id
        WHERE p.is_active = 1
    ";
    $params = [];
    if (!is_super_admin() && $scoped_admin_id) {
        $sql .= " AND s.admin_id = ?";
        $params[] = $scoped_admin_id;
    }
    $sql .= " ORDER BY s.name ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $currency = get_fee_currency_symbol($pdo);
    $formatted = [];
    foreach ($rows as $r) {
        $stdFee = get_class_monthly_fee($r['class_id'], $pdo);
        $feeInfo = get_student_effective_monthly_fee($r['student_id'], $r['class_id'], null, null, $pdo);
        $r['standard_fee'] = $stdFee;
        $r['effective_fee'] = $feeInfo['effective_fee'];
        $r['discount_desc'] = $feeInfo['discount_desc'];
        $formatted[] = $r;
    }

    echo json_encode([
        'success'  => true,
        'currency' => $currency,
        'profiles' => $formatted
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action request.']);

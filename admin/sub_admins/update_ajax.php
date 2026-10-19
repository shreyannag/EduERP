<?php
// admin/sub_admins/update_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!can_manage_sub_admins()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: Only Admins and Super Admins can manage sub-admins.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$sub_admin_id   = (int)($_POST['sub_admin_id'] ?? 0);
$full_name      = trim($_POST['full_name'] ?? '');
$email          = trim($_POST['email'] ?? '');
$phone          = trim($_POST['phone'] ?? '');
$sub_role       = trim($_POST['sub_role'] ?? 'custom');
$salary         = max(0, (float)($_POST['salary'] ?? 0));
$is_active      = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$features_input = $_POST['features'] ?? [];

// Verify target sub-admin exists and is under this admin's scope
$queryCheck = is_super_admin() 
    ? "SELECT id, username, parent_admin_id FROM admins WHERE id = ? AND role = 'sub_admin'" 
    : "SELECT id, username, parent_admin_id FROM admins WHERE id = ? AND role = 'sub_admin' AND parent_admin_id = ?";
$stmtCheck = $pdo->prepare($queryCheck);
$paramsCheck = is_super_admin() ? [$sub_admin_id] : [$sub_admin_id, (int)$_SESSION['admin_id']];
$stmtCheck->execute($paramsCheck);
$target = $stmtCheck->fetch(\PDO::FETCH_ASSOC);

if (!$target) {
    echo json_encode(['success' => false, 'error' => 'Sub-admin account not found or access denied.']);
    exit;
}

if (!empty($email)) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
        exit;
    }
    $stmtEmail = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
    $stmtEmail->execute([$email, $sub_admin_id]);
    if ($stmtEmail->fetch()) {
        echo json_encode(['success' => false, 'error' => "An account with email '{$email}' already exists."]);
        exit;
    }
}

if (!in_array($sub_role, ['teacher', 'accountant', 'custom'], true)) {
    $sub_role = 'custom';
}

$valid_feature_keys = array_keys(ALL_ADMIN_FEATURES);
$selected_features = [];
if (is_array($features_input)) {
    foreach ($features_input as $feat) {
        if (in_array($feat, $valid_feature_keys, true)) {
            $selected_features[] = $feat;
        }
    }
}
$features_json = json_encode(array_values(array_unique($selected_features)));

try {
    $stmt = $pdo->prepare("
        UPDATE admins 
        SET full_name = ?, email = ?, phone = ?, sub_role = ?, salary = ?, is_active = ?, features = ?
        WHERE id = ?
    ");
    $stmt->execute([
        !empty($full_name) ? $full_name : $target['username'],
        !empty($email) ? $email : null,
        !empty($phone) ? $phone : null,
        $sub_role,
        $salary,
        $is_active ? 1 : 0,
        $features_json,
        $sub_admin_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Sub-Admin '{$target['username']}' updated successfully!"
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

<?php
// admin/sub_admins/create_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

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

$full_name      = trim($_POST['full_name'] ?? '');
$username       = trim($_POST['username'] ?? '');
$email          = trim($_POST['email'] ?? '');
$phone          = trim($_POST['phone'] ?? '');
$sub_role       = trim($_POST['sub_role'] ?? 'custom');
$salary         = max(0, (float)($_POST['salary'] ?? 0));
$password       = $_POST['password'] ?? '';
$is_active      = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$features_input = $_POST['features'] ?? [];
$send_email     = !empty($_POST['send_email']);

// Determine parent admin ID and institute
if (is_super_admin() && !empty($_POST['parent_admin_id'])) {
    $parent_admin_id = (int)$_POST['parent_admin_id'];
    $stmtInst = $pdo->prepare("SELECT institute_name FROM admins WHERE id = ?");
    $stmtInst->execute([$parent_admin_id]);
    $institute_name = $stmtInst->fetchColumn() ?: 'Main Campus';
} else {
    $parent_admin_id = (int)$_SESSION['admin_id'];
    $institute_name  = $_SESSION['institute_name'] ?? 'Main Campus';
}

// Validations
if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Username and password are required.']);
    exit;
}

if (strlen($username) < 3 || strlen($username) > 50) {
    echo json_encode(['success' => false, 'error' => 'Username must be between 3 and 50 characters.']);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
    echo json_encode(['success' => false, 'error' => 'Username may only contain letters, numbers, dots, hyphens, and underscores.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters long.']);
    exit;
}

if (!in_array($sub_role, ['teacher', 'accountant', 'custom'], true)) {
    $sub_role = 'custom';
}

// Check username uniqueness
$stmtUser = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
$stmtUser->execute([$username]);
if ($stmtUser->fetch()) {
    echo json_encode(['success' => false, 'error' => "An account with username '{$username}' already exists."]);
    exit;
}

// Check email uniqueness if provided
if (!empty($email)) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
        exit;
    }
    $stmtEmail = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
    $stmtEmail->execute([$email]);
    if ($stmtEmail->fetch()) {
        echo json_encode(['success' => false, 'error' => "An account with email '{$email}' already exists."]);
        exit;
    }
}

// Filter submitted features against valid keys
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

$password_hash = password_hash($password, PASSWORD_DEFAULT);
$created_by    = (int)$_SESSION['admin_id'];

try {
    $stmt = $pdo->prepare("
        INSERT INTO admins (
            username, email, password_hash, role, sub_role, full_name, phone, salary,
            parent_admin_id, institute_name, is_active, features, created_by,
            must_change_password, created_at
        ) VALUES (
            ?, ?, ?, 'sub_admin', ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            1, NOW()
        )
    ");
    $stmt->execute([
        $username,
        !empty($email) ? $email : null,
        $password_hash,
        $sub_role,
        !empty($full_name) ? $full_name : $username,
        !empty($phone) ? $phone : null,
        $salary,
        $parent_admin_id,
        $institute_name,
        $is_active ? 1 : 0,
        $features_json,
        $created_by
    ]);

    $new_id = (int)$pdo->lastInsertId();
    $emailNotice = '';

    if ($send_email && !empty($email)) {
        $mailRes = send_sub_admin_credentials($new_id, $password, $pdo);
        if ($mailRes['success']) {
            $emailNotice = " Login credentials were automatically emailed to {$email}.";
        } else {
            $emailNotice = " However, credential email could not be sent: " . $mailRes['error'];
        }
    }

    echo json_encode([
        'success'      => true,
        'message'      => "Sub-Admin '{$username}' (" . ucfirst($sub_role) . ") successfully created!{$emailNotice}",
        'sub_admin_id' => $new_id,
        'sub_role'     => $sub_role,
        'username'     => $username
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

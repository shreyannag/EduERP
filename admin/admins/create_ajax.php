<?php
// admin/admins/create_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Super Admin authorization
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$username       = trim($_POST['username'] ?? '');
$email          = trim($_POST['email'] ?? '');
$institute_name = trim($_POST['institute_name'] ?? '');
$password       = $_POST['password'] ?? '';
$role           = trim($_POST['role'] ?? 'admin');
$is_active      = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$features_input = $_POST['features'] ?? [];

// Input validations
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

if (empty($institute_name)) {
    $institute_name = 'Main Campus';
}

if (!in_array($role, ['admin', 'super_admin'], true)) {
    $role = 'admin';
}

// Check username uniqueness
$stmtUser = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
$stmtUser->execute([$username]);
if ($stmtUser->fetch()) {
    echo json_encode(['success' => false, 'error' => "An administrator account with username '{$username}' already exists."]);
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
        echo json_encode(['success' => false, 'error' => "An administrator account with email '{$email}' already exists."]);
        exit;
    }
}

// Prepare feature list
$valid_feature_keys = array_keys(ALL_ADMIN_FEATURES);
if ($role === 'super_admin') {
    // Super Admin receives all features
    $features_json = json_encode($valid_feature_keys);
} else {
    // Filter submitted features against valid keys
    $selected_features = [];
    if (is_array($features_input)) {
        foreach ($features_input as $feat) {
            if (in_array($feat, $valid_feature_keys, true)) {
                $selected_features[] = $feat;
            }
        }
    }
    $features_json = json_encode(array_values(array_unique($selected_features)));
}

$must_change_password = isset($_POST['must_change_password']) ? (int)$_POST['must_change_password'] : 1;
$password_hash = password_hash($password, PASSWORD_DEFAULT);
$created_by    = (int)$_SESSION['admin_id'];

try {
    $stmt = $pdo->prepare("
        INSERT INTO admins (
            username, email, password_hash, role, institute_name, 
            is_active, features, created_by, must_change_password, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $username,
        !empty($email) ? $email : null,
        $password_hash,
        $role,
        $institute_name,
        $is_active ? 1 : 0,
        $features_json,
        $created_by,
        $must_change_password ? 1 : 0
    ]);

    $new_id = (int)$pdo->lastInsertId();

    echo json_encode([
        'success'        => true,
        'message'        => "Administrator account '{$username}' created successfully for '{$institute_name}'!",
        'admin_id'       => $new_id,
        'role'           => $role,
        'institute_name' => $institute_name
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

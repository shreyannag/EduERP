<?php
// admin/admins/edit_ajax.php
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

$admin_id       = (int)($_POST['admin_id'] ?? 0);
$username       = trim($_POST['username'] ?? '');
$email          = trim($_POST['email'] ?? '');
$institute_name = trim($_POST['institute_name'] ?? '');
$password       = $_POST['password'] ?? '';
$role           = trim($_POST['role'] ?? 'admin');
$is_active      = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$features_input = $_POST['features'] ?? [];

if ($admin_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid administrator record specified.']);
    exit;
}

// Fetch existing admin
$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo json_encode(['success' => false, 'error' => 'Administrator account not found.']);
    exit;
}

// Safety check: Cannot deactivate self or remove super_admin from self
$current_user_id = (int)$_SESSION['admin_id'];
if ($admin_id === $current_user_id) {
    if (!$is_active) {
        echo json_encode(['success' => false, 'error' => 'Security restriction: You cannot deactivate your own Super Administrator account.']);
        exit;
    }
    if ($role !== 'super_admin') {
        echo json_encode(['success' => false, 'error' => 'Security restriction: You cannot remove Super Administrator status from your own account.']);
        exit;
    }
}

// Individual Ownership Protection Enforcement
// Super Admin cannot change tenant admin's personal credentials (password, institute branding, email)
// unless the tenant admin has explicitly granted support_permission from their Account Settings.
// Sub-admins are subordinate employees under direct institute control; their settings can be modified freely.
$is_sub_admin = ($admin['role'] === 'sub_admin' || (!empty($admin['parent_admin_id']) && (int)$admin['parent_admin_id'] !== (int)$admin['id']));
$is_target_tenant = ($admin_id !== $current_user_id && $admin['role'] !== 'super_admin' && !$is_sub_admin);
$has_support_permission = !empty($admin['support_permission']);

// Check if a permission code was submitted to authorize unrestrictive settings change
$permission_code = trim($_POST['support_code'] ?? '');
if (!$has_support_permission && !empty($permission_code) && !empty($admin['support_otp']) && !empty($admin['support_otp_expires_at'])) {
    if (strtotime($admin['support_otp_expires_at']) >= time() && password_verify($permission_code, $admin['support_otp'])) {
        $has_support_permission = true;
        $updCode = $pdo->prepare("UPDATE admins SET support_permission = 1, support_permission_updated_at = NOW(), support_otp = NULL, support_otp_expires_at = NULL WHERE id = ?");
        $updCode->execute([$admin_id]);
        $admin['support_permission'] = 1;
    }
}

if ($is_target_tenant && !$has_support_permission) {
    $existing_institute = !empty($admin['institute_name']) ? $admin['institute_name'] : 'Main Campus';
    $existing_email = $admin['email'] ?? '';

    $attempting_password_change  = !empty($password);
    $attempting_institute_change = ($institute_name !== '' && $institute_name !== $existing_institute);
    $attempting_email_change     = ($email !== $existing_email);

    if ($attempting_password_change || $attempting_institute_change || $attempting_email_change) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => "Individual Ownership Protection: Administrator '{$admin['username']}' has not granted support permission from their Account Settings. You cannot modify their password, institute branding, or email address without their explicit permission."
        ]);
        exit;
    }

    // Preserve personal settings unchanged
    $institute_name = $existing_institute;
    $email = $existing_email;
    $password = '';
}

// Validations
if (empty($username)) {
    echo json_encode(['success' => false, 'error' => 'Username is required.']);
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

if (!empty($password) && strlen($password) < 6) {
    echo json_encode(['success' => false, 'error' => 'New password must be at least 6 characters long.']);
    exit;
}

if (empty($institute_name)) {
    $institute_name = 'Main Campus';
}

if (!in_array($role, ['admin', 'super_admin', 'sub_admin'], true)) {
    $role = ($admin['role'] === 'sub_admin') ? 'sub_admin' : 'admin';
}

// Check username uniqueness excluding current record
$stmtUser = $pdo->prepare("SELECT id FROM admins WHERE username = ? AND id != ?");
$stmtUser->execute([$username, $admin_id]);
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
    $stmtEmail = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
    $stmtEmail->execute([$email, $admin_id]);
    if ($stmtEmail->fetch()) {
        echo json_encode(['success' => false, 'error' => "An administrator account with email '{$email}' already exists."]);
        exit;
    }
}

// Prepare feature list
$valid_feature_keys = array_keys(ALL_ADMIN_FEATURES);
if ($role === 'super_admin') {
    $features_json = json_encode($valid_feature_keys);
} else {
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

try {
    $fields = [
        'username'       => $username,
        'email'          => !empty($email) ? $email : null,
        'role'           => $role,
        'institute_name' => $institute_name,
        'is_active'      => $is_active ? 1 : 0,
        'features'       => $features_json,
    ];

    $sql = "UPDATE admins SET username = :username, email = :email, role = :role, institute_name = :institute_name, is_active = :is_active, features = :features";

    // Optional password update
    if (!empty($password)) {
        $fields['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $fields['plain_password'] = $password;
        $sql .= ", password_hash = :password_hash, plain_password = :plain_password, must_change_password = 0";
    }

    // If deactivated, revoke session token immediately
    if (!$is_active) {
        $sql .= ", session_token = NULL";
    }

    $sql .= " WHERE id = :id";
    $fields['id'] = $admin_id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($fields);

    // If current user is editing themselves, update session data immediately
    if ($admin_id === $current_user_id) {
        $_SESSION['institute_name'] = $institute_name;
    }

    echo json_encode([
        'success'        => true,
        'message'        => "Administrator account '{$username}' updated successfully!",
        'admin_id'       => $admin_id,
        'role'           => $role,
        'institute_name' => $institute_name,
        'is_active'      => $is_active
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database update error: ' . $e->getMessage()]);
}

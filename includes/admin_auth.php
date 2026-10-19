<?php
// includes/admin_auth.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

send_security_headers();
start_secure_session('ADMIN_SESSION');

// Prevent browser caching for admin pages to ensure back button reflects logged-out state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Canonical list of all modular admin features available in the platform
if (!defined('ALL_ADMIN_FEATURES')) {
    define('ALL_ADMIN_FEATURES', [
        'questions'     => ['label' => 'Question Bank', 'icon' => 'fas fa-database', 'desc' => 'Manage questions, choices, answers & explanations'],
        'upload_csv'    => ['label' => 'Bulk CSV Upload', 'icon' => 'fas fa-upload', 'desc' => 'Import large batches of questions with LaTeX math support'],
        'ai_generation' => ['label' => 'OpenRouter AI Generation', 'icon' => 'fas fa-brain', 'desc' => 'Generate questions automatically via OpenRouter AI models'],
        'exams'         => ['label' => 'Exam Management', 'icon' => 'fas fa-list-alt', 'desc' => 'Create, schedule, configure & publish student exams'],
        'students'      => ['label' => 'Student Management', 'icon' => 'fas fa-users', 'desc' => 'Register students, view profiles & reset credentials'],
        'enrolments'    => ['label' => 'Exam Enrolments', 'icon' => 'fas fa-user-plus', 'desc' => 'Enrol students into scheduled examinations'],
        'fees'          => ['label' => 'Fees & Payments', 'icon' => 'fas fa-hand-holding-usd', 'desc' => 'Monthly fee structures, collection receipts & ledgers'],
        'messages'      => ['label' => 'Direct Messaging', 'icon' => 'fas fa-envelope', 'desc' => 'Send and receive communications with enrolled students'],
        'reports'       => ['label' => 'Reports & Analytics', 'icon' => 'fas fa-chart-bar', 'desc' => 'Analyze student scores, attendance & export report cards'],
        'subjects'      => ['label' => 'Subjects & Topics', 'icon' => 'fas fa-book', 'desc' => 'Manage academic boards, classes, subjects & curriculum topics'],
        'batches'       => ['label' => 'Batches Management', 'icon' => 'fas fa-layer-group', 'desc' => 'Organize enrolled students into teaching batches and groups'],
        'sub_admins'    => ['label' => 'Staff & Sub-Admins', 'icon' => 'fas fa-user-shield', 'desc' => 'Manage sub-admin accounts, teachers, accountants and permissions'],
        'payroll'       => ['label' => 'Payroll & Salaries', 'icon' => 'fas fa-money-check-alt', 'desc' => 'Process salary disbursements and view payroll history'],
        'homework'      => ['label' => 'Homework & Assignments', 'icon' => 'fas fa-book-reader', 'desc' => 'Assign homework with text, images, LaTeX, code snippets & due dates'],
        'coding'        => ['label' => 'Online Coding & Competitions', 'icon' => 'fas fa-laptop-code', 'desc' => 'Online code editor, multi-language execution engine & coding competitions'],
        'image_storage' => ['label' => 'Image Storage & CDN', 'icon' => 'fas fa-images', 'desc' => 'Compressed image bank, WebP conversion & Cloudinary CDN'],
        'ai_settings'   => ['label' => 'AI Settings', 'icon' => 'fas fa-cogs', 'desc' => 'Configure OpenRouter API keys, model presets & temperature'],
    ]);
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['admin_id'])) {
    session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your admin session has expired. Please refresh the page and log in again.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/admin/');
    exit;
}

// Inactivity timeout (10 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 600)) {
    session_unset();
    session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your session timed out due to 10 minutes of inactivity. Please refresh and log in again.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/admin/?msg=timeout');
    exit;
}
$_SESSION['last_activity'] = time();

// Concurrent login check and live status/permissions synchronization
$stmt = $pdo->prepare("SELECT session_token, role, sub_role, full_name, phone, salary, parent_admin_id, institute_name, is_active, features, must_change_password FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$current_admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_admin || empty($current_admin['session_token']) || !isset($_SESSION['session_token']) || $_SESSION['session_token'] !== $current_admin['session_token']) {
    session_unset();
    session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your account was logged in from another browser or device. Please log in again.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/admin/?msg=concurrent');
    exit;
}

// Check if account has been deactivated / suspended
if (empty($current_admin['is_active'])) {
    session_unset();
    session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your admin account has been suspended by the Super Administrator.']);
        exit;
    }
    header('Location: ' . BASE_URL . '/admin/?msg=suspended');
    exit;
}

// Sync permissions, sub-admin hierarchy, and institute name into active session
$_SESSION['admin_role'] = !empty($current_admin['role']) ? $current_admin['role'] : 'admin';
$_SESSION['is_super_admin'] = ($_SESSION['admin_role'] === 'super_admin');
$_SESSION['is_sub_admin'] = ($_SESSION['admin_role'] === 'sub_admin');
$_SESSION['sub_role'] = !empty($current_admin['sub_role']) ? $current_admin['sub_role'] : 'custom';
$_SESSION['full_name'] = !empty($current_admin['full_name']) ? $current_admin['full_name'] : '';
$_SESSION['parent_admin_id'] = !empty($current_admin['parent_admin_id']) ? (int)$current_admin['parent_admin_id'] : (int)$_SESSION['admin_id'];
$_SESSION['institute_name'] = !empty($current_admin['institute_name']) ? $current_admin['institute_name'] : 'Main Campus';
$_SESSION['must_change_password'] = !empty($current_admin['must_change_password']);

if ($_SESSION['is_super_admin']) {
    $_SESSION['admin_features'] = array_keys(ALL_ADMIN_FEATURES);
} else {
    $decodedFeatures = !empty($current_admin['features']) ? json_decode($current_admin['features'], true) : [];
    $_SESSION['admin_features'] = is_array($decodedFeatures) ? $decodedFeatures : [];
}

/**
 * Checks if the logged in user is a Super Admin
 */
function is_super_admin(): bool {
    return !empty($_SESSION['is_super_admin']);
}

/**
 * Checks if the logged in user is a Sub-Admin (Teacher, Accountant, or Staff)
 */
function is_sub_admin(): bool {
    return !empty($_SESSION['is_sub_admin']);
}

/**
 * Returns sub-role (teacher, accountant, custom)
 */
function get_sub_role(): string {
    return $_SESSION['sub_role'] ?? '';
}

/**
 * Checks if the logged in user is a Teacher sub-admin
 */
function is_teacher(): bool {
    return is_sub_admin() && get_sub_role() === 'teacher';
}

/**
 * Checks if the logged in user is an Accountant sub-admin
 */
function is_accountant(): bool {
    return is_sub_admin() && get_sub_role() === 'accountant';
}

/**
 * Checks if the current admin is permitted to view monthly aggregate fee collection totals
 * Strictly restricted to Super Admin and full Admins; hidden from sub-admins
 */
function can_view_fee_totals(): bool {
    return is_super_admin() || (!is_sub_admin() && ($_SESSION['admin_role'] ?? '') === 'admin');
}

/**
 * Checks if the current user can manage sub-admins (Super Admin and Parent Admins)
 */
function can_manage_sub_admins(): bool {
    return is_super_admin() || (!is_sub_admin() && ($_SESSION['admin_role'] ?? '') === 'admin');
}

/**
 * Checks if current user can process staff salaries (Super Admin, Admins, or Accountant sub-admin)
 */
function can_process_payroll(): bool {
    return is_super_admin() || (!is_sub_admin() && ($_SESSION['admin_role'] ?? '') === 'admin') || is_accountant() || admin_has_feature('payroll');
}

/**
 * Returns the effective tenant admin ID (the parent admin ID for sub-admins, or self)
 */
function get_effective_admin_id(): int {
    if (is_sub_admin() && !empty($_SESSION['parent_admin_id'])) {
        return (int)$_SESSION['parent_admin_id'];
    }
    return (int)($_SESSION['admin_id'] ?? 1);
}

/**
 * Checks if the current admin is required to update their temporary password
 */
function admin_must_change_password(): bool {
    return !empty($_SESSION['must_change_password']);
}

/**
 * Checks whether the current admin has permission for a specific feature.
 * Super Admins inherently have access to all features.
 */
function admin_has_feature(string $feature): bool {
    if (is_super_admin()) {
        return true;
    }
    // Teachers inherently have access to batches, homework, and coding
    if (($feature === 'batches' || $feature === 'homework' || $feature === 'coding') && is_teacher()) {
        return true;
    }
    // Accountants inherently have access to fees and payroll
    if (($feature === 'fees' || $feature === 'payroll') && is_accountant()) {
        return true;
    }
    $features = $_SESSION['admin_features'] ?? [];
    return in_array($feature, $features, true);
}

/**
 * Enforces feature-level access control.
 * Terminates with JSON 403 for AJAX or redirects with error for standard page requests.
 */
function require_admin_feature(string $feature): void {
    if (!admin_has_feature($feature)) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                  || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);
        
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => "Access Denied: You do not have permission to access the '{$feature}' module. Please contact your Super Administrator."
            ]);
            exit;
        }

        $_SESSION['flash_error'] = "Access Denied: Your administrator role does not have permission to access the '{$feature}' feature.";
        header('Location: ' . BASE_URL . '/admin/dashboard.php?error=unauthorized_feature');
        exit;
    }
}

/**
 * Enforces Super Admin role requirement.
 */
function require_super_admin(): void {
    if (!is_super_admin()) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
                  || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => "Access Denied: Super Administrator privileges are required."
            ]);
            exit;
        }

        $_SESSION['flash_error'] = "Access Denied: Super Administrator privileges required.";
        header('Location: ' . BASE_URL . '/admin/dashboard.php?error=unauthorized_super_admin');
        exit;
    }
}

/**
 * Returns current administrator's institute name
 */
function get_current_admin_institute(): string {
    return $_SESSION['institute_name'] ?? 'Main Campus';
}

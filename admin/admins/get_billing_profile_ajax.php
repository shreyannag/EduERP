<?php
// admin/admins/get_billing_profile_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

header('Content-Type: application/json; charset=utf-8');

require_super_admin();

$admin_id = (int)($_GET['admin_id'] ?? 0);
if (!$admin_id) {
    echo json_encode(['success' => false, 'error' => 'Administrator ID is required.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, email, institute_name, role, features FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo json_encode(['success' => false, 'error' => 'Administrator not found.']);
    exit;
}

$settings = get_admin_billing_settings($pdo);
$profile  = get_admin_billing_profile($admin_id, $pdo);
$calc     = calculate_admin_monthly_billing($admin_id, (int)date('n'), (int)date('Y'), $pdo);

$rawFeatures = !empty($admin['features']) ? json_decode($admin['features'], true) : [];
$enabledFeatures = is_array($rawFeatures) ? $rawFeatures : [];

$allFeaturesMeta = defined('ALL_ADMIN_FEATURES') ? ALL_ADMIN_FEATURES : [];

echo json_encode([
    'success'          => true,
    'admin'            => [
        'id'             => (int)$admin['id'],
        'username'       => $admin['username'],
        'email'          => $admin['email'],
        'institute_name' => $admin['institute_name']
    ],
    'settings'         => $settings,
    'profile'          => $profile,
    'enabled_features' => $enabledFeatures,
    'all_features'     => $allFeaturesMeta,
    'calculation'      => $calc
]);

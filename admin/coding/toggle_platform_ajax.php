<?php
// admin/coding/toggle_platform_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_super_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only Super Administrator can toggle the Coding Platform.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$enabled = isset($_POST['enabled']) && (int)$_POST['enabled'] === 1;

if (set_coding_platform_enabled($pdo, $enabled)) {
    echo json_encode([
        'success' => true,
        'enabled' => $enabled,
        'message' => 'Online Coding Platform is now ' . ($enabled ? 'ENABLED' : 'DISABLED') . ' globally.'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update system setting.']);
}

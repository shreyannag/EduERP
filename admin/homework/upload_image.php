<?php
// admin/homework/upload_image.php
// AJAX image upload endpoint for Homework description editor.

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/image_storage.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_has_feature('homework') && !is_teacher()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: You do not have permission to manage homework.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed.']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => 'Image upload error (Code: ' . $errCode . ').']);
    exit;
}

$file = $_FILES['image'];

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Image size cannot exceed 10MB.']);
    exit;
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['success' => false, 'error' => 'Only JPEG, PNG, WebP, and GIF images are allowed.']);
    exit;
}

$extMap = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif'
];
$ext = $extMap[$mimeType] ?? 'png';

$binaryData = file_get_contents($file['tmp_name']);
if (!$binaryData) {
    echo json_encode(['success' => false, 'error' => 'Failed to read uploaded image.']);
    exit;
}

try {
    $saveResult = save_question_image($binaryData, $ext, $pdo);
    echo json_encode([
        'success'  => true,
        'url'      => $saveResult['url'],
        'storage'  => $saveResult['storage'],
        'raw_size' => $saveResult['raw_size'],
        'opt_size' => $saveResult['opt_size']
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Storage processing error: ' . $e->getMessage()]);
}

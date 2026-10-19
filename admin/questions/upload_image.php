<?php
// admin/questions/upload_image.php
// AJAX image upload endpoint for Summernote question editor.

require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/image_storage.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// Check CSRF token (from POST or header)
$csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed.']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => 'File upload error (Code: ' . $errCode . ').']);
    exit;
}

$file = $_FILES['image'];

// Max size: 10MB
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Image size cannot exceed 10MB.']);
    exit;
}

// Validate mime type
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

$admin_id = (int)($_SESSION['admin_id'] ?? 1);
$result = store_question_image($binaryData, $ext, $pdo, $admin_id);

if (!empty($result['success']) && !empty($result['url'])) {
    echo json_encode([
        'success' => true,
        'url'     => $result['url'],
        'driver'  => $result['driver'] ?? 'local',
        'size'    => $result['size'] ?? strlen($binaryData)
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => $result['error'] ?? 'Image compression or storage failed.'
    ]);
}

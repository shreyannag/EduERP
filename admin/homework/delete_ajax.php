<?php
// admin/homework/delete_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_has_feature('homework') && !is_teacher()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$homework_id = (int)($_POST['homework_id'] ?? 0);
if (!$homework_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Homework ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM homework WHERE id = ?");
$stmt->execute([$homework_id]);
$hw = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$hw) {
    echo json_encode(['success' => false, 'error' => 'Homework not found.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$hw['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this homework.']);
        exit;
    }
    if (is_teacher() && (int)$hw['created_by'] !== (int)$_SESSION['admin_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You can only delete homework you assigned.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Delete submissions
    $stmtDelSubs = $pdo->prepare("DELETE FROM homework_submissions WHERE homework_id = ?");
    $stmtDelSubs->execute([$homework_id]);

    // Delete homework
    $stmtDelHw = $pdo->prepare("DELETE FROM homework WHERE id = ?");
    $stmtDelHw->execute([$homework_id]);

    // Unlink attachment file if exists
    if (!empty($hw['attachment_path'])) {
        $filePath = __DIR__ . '/../../' . $hw['attachment_path'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Homework '{$hw['title']}' deleted successfully!"
    ]);
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

<?php
// admin/batches/delete_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_feature('batches');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$batch_id = (int)($_POST['batch_id'] ?? 0);

if (!$batch_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Batch ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$batch['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not have permission to delete this batch.']);
        exit;
    }
    if (is_teacher() && (int)$batch['teacher_id'] !== (int)$_SESSION['admin_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You are not assigned to this batch.']);
        exit;
    }
}

// If sub-admin/staff, queue deletion for Super Admin / Admin review
$res = queue_or_execute_deletion('batch', $batch_id, $batch['name'], 'Staff requested deletion', null, $pdo);
if ($res['queued']) {
    echo json_encode([
        'success' => true,
        'queued'  => true,
        'message' => $res['message']
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Remove student assignments first
    $stmtDelStudents = $pdo->prepare("DELETE FROM batch_students WHERE batch_id = ?");
    $stmtDelStudents->execute([$batch_id]);

    // Delete batch
    $stmtDelBatch = $pdo->prepare("DELETE FROM batches WHERE id = ?");
    $stmtDelBatch->execute([$batch_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Batch '{$batch['name']}' deleted successfully!"
    ]);
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

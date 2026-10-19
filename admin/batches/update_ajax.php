<?php
// admin/batches/update_ajax.php
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

$batch_id      = (int)($_POST['batch_id'] ?? 0);
$name          = trim($_POST['name'] ?? '');
$class_id      = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
$board_id      = !empty($_POST['board_id']) ? (int)$_POST['board_id'] : null;
$subject_id    = !empty($_POST['subject_id']) ? (int)$_POST['subject_id'] : null;
$schedule_desc = trim($_POST['schedule_desc'] ?? '');
$is_active     = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

if (!$batch_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Batch ID.']);
    exit;
}

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Batch name is required.']);
    exit;
}

if (strlen($name) < 2 || strlen($name) > 150) {
    echo json_encode(['success' => false, 'error' => 'Batch name must be between 2 and 150 characters.']);
    exit;
}

// Fetch existing batch
$stmtB = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
$stmtB->execute([$batch_id]);
$batch = $stmtB->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found.']);
    exit;
}

// Security & Ownership
$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$batch['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this batch.']);
        exit;
    }
    // If teacher, they must be assigned or institute staff
    if (is_teacher() && (int)$batch['teacher_id'] !== (int)$_SESSION['admin_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You are not assigned to this batch.']);
        exit;
    }
}

// Teacher assignment
if (is_teacher()) {
    $teacher_id = (int)$batch['teacher_id']; // keep existing teacher
} else {
    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
    if ($teacher_id) {
        $stmtT = $pdo->prepare("SELECT id, parent_admin_id FROM admins WHERE id = ?");
        $stmtT->execute([$teacher_id]);
        $teacherRow = $stmtT->fetch(PDO::FETCH_ASSOC);
        if (!$teacherRow) {
            $teacher_id = null;
        } elseif (!is_super_admin()) {
            $tParent = !empty($teacherRow['parent_admin_id']) ? (int)$teacherRow['parent_admin_id'] : (int)$teacherRow['id'];
            if ($tParent !== $effective_admin_id && (int)$teacherRow['id'] !== $effective_admin_id) {
                echo json_encode(['success' => false, 'error' => 'Assigned teacher does not belong to your institute.']);
                exit;
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("
        UPDATE batches SET
            teacher_id = ?,
            name = ?,
            class_id = ?,
            board_id = ?,
            subject_id = ?,
            schedule_desc = ?,
            is_active = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $teacher_id,
        $name,
        $class_id,
        $board_id,
        $subject_id,
        !empty($schedule_desc) ? $schedule_desc : null,
        $is_active ? 1 : 0,
        $batch_id
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Batch '{$name}' updated successfully!"
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

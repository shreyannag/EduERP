<?php
// student/homework_submit_ajax.php
require_once __DIR__ . '/../includes/student_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$student_id  = (int)($_SESSION['student_id'] ?? 0);
$homework_id = (int)($_POST['homework_id'] ?? 0);
$submission_text = trim($_POST['submission_text'] ?? '');
$submitted_code  = trim($_POST['submitted_code'] ?? '');

if (!$student_id || !$homework_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid request parameters.']);
    exit;
}

// Fetch student
$stmtStu = $pdo->prepare("SELECT id, admin_id, class_id, board_id FROM students WHERE id = ? AND is_active = 1");
$stmtStu->execute([$student_id]);
$student = $stmtStu->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo json_encode(['success' => false, 'error' => 'Student account not active.']);
    exit;
}

// Fetch homework
$stmtHw = $pdo->prepare("SELECT * FROM homework WHERE id = ? AND is_active = 1");
$stmtHw->execute([$homework_id]);
$hw = $stmtHw->fetch(PDO::FETCH_ASSOC);

if (!$hw) {
    echo json_encode(['success' => false, 'error' => 'Homework assignment not found or no longer active.']);
    exit;
}

// Verify student is eligible to submit this homework
$isEligible = false;
if ((int)$hw['admin_id'] === (int)$student['admin_id'] || (int)$hw['admin_id'] === 1) {
    if (!empty($hw['batch_id'])) {
        $stmtBatchCheck = $pdo->prepare("SELECT id FROM batch_students WHERE batch_id = ? AND student_id = ?");
        $stmtBatchCheck->execute([$hw['batch_id'], $student_id]);
        if ($stmtBatchCheck->fetch()) {
            $isEligible = true;
        }
    } else {
        $matchClass = empty($hw['class_id']) || ((int)$hw['class_id'] === (int)$student['class_id']);
        $matchBoard = empty($hw['board_id']) || ((int)$hw['board_id'] === (int)$student['board_id']);
        if ($matchClass && $matchBoard) {
            $isEligible = true;
        }
    }
}

if (!$isEligible) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied: This homework is not assigned to you.']);
    exit;
}

// Handle Photo / Solution Attachment Upload
$attachment_path = null;
if (isset($_FILES['solution_attachment']) && $_FILES['solution_attachment']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['solution_attachment'];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions)) {
        echo json_encode(['success' => false, 'error' => 'Attachment must be an image (JPG, PNG, WebP) or PDF file.']);
        exit;
    }

    if ($file['size'] > 15 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Attachment size cannot exceed 15MB.']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/homework/submissions/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = 'sub_' . $student_id . '_' . $homework_id . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $destPath)) {
        $attachment_path = 'uploads/homework/submissions/' . $fileName;
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save solution attachment file.']);
        exit;
    }
}

if (empty($submission_text) && empty($submitted_code) && empty($attachment_path)) {
    // Check if an existing submission had an attachment
    $stmtCheck = $pdo->prepare("SELECT attachment_path FROM homework_submissions WHERE homework_id = ? AND student_id = ?");
    $stmtCheck->execute([$homework_id, $student_id]);
    $existingAtt = $stmtCheck->fetchColumn();
    if (!$existingAtt) {
        echo json_encode(['success' => false, 'error' => 'Please provide written notes, submit programming code, or upload a solution picture.']);
        exit;
    }
}

try {
    $isPastDue = (strtotime($hw['due_date']) < time());
    $status = $isPastDue ? 'late' : 'submitted';

    if ($attachment_path !== null) {
        $stmtIns = $pdo->prepare("
            INSERT INTO homework_submissions (
                homework_id, student_id, submission_text, submitted_code, attachment_path, status, submitted_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, NOW()
            ) ON DUPLICATE KEY UPDATE
                submission_text = VALUES(submission_text),
                submitted_code = VALUES(submitted_code),
                attachment_path = VALUES(attachment_path),
                status = VALUES(status),
                submitted_at = NOW()
        ");
        $stmtIns->execute([
            $homework_id,
            $student_id,
            !empty($submission_text) ? $submission_text : null,
            !empty($submitted_code) ? $submitted_code : null,
            $attachment_path,
            $status
        ]);
    } else {
        $stmtIns = $pdo->prepare("
            INSERT INTO homework_submissions (
                homework_id, student_id, submission_text, submitted_code, status, submitted_at
            ) VALUES (
                ?, ?, ?, ?, ?, NOW()
            ) ON DUPLICATE KEY UPDATE
                submission_text = VALUES(submission_text),
                submitted_code = VALUES(submitted_code),
                status = VALUES(status),
                submitted_at = NOW()
        ");
        $stmtIns->execute([
            $homework_id,
            $student_id,
            !empty($submission_text) ? $submission_text : null,
            !empty($submitted_code) ? $submitted_code : null,
            $status
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Your homework solution was successfully turned in!' . ($isPastDue ? ' (Marked as turned in after deadline).' : '')
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

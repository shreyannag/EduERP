<?php
// admin/batches/students_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

require_admin_feature('batches');

$action = $_REQUEST['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
}

$batch_id = (int)($_REQUEST['batch_id'] ?? 0);

if (!$batch_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Batch ID.']);
    exit;
}

// Fetch batch
$stmtB = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
$stmtB->execute([$batch_id]);
$batch = $stmtB->fetch(PDO::FETCH_ASSOC);

if (!$batch) {
    echo json_encode(['success' => false, 'error' => 'Batch not found.']);
    exit;
}

// Ownership verification
$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$batch['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this batch.']);
        exit;
    }
    if (is_teacher() && (int)$batch['teacher_id'] !== (int)$_SESSION['admin_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You are not assigned to this batch.']);
        exit;
    }
}

$parent_admin_id = (int)$batch['admin_id'];

// ACTION: LIST STUDENTS IN BATCH & CANDIDATES
if ($action === 'list') {
    try {
        // Enrolled in batch
        $stmtAssigned = $pdo->prepare("
            SELECT 
                bs.id as link_id,
                bs.joined_at,
                s.id as student_id,
                s.full_name,
                s.username,
                s.email,
                c.name as class_name,
                b.name as board_name
            FROM batch_students bs
            JOIN students s ON bs.student_id = s.id
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN boards b ON s.board_id = b.id
            WHERE bs.batch_id = ?
            ORDER BY s.full_name ASC
        ");
        $stmtAssigned->execute([$batch_id]);
        $assigned = $stmtAssigned->fetchAll(PDO::FETCH_ASSOC);

        // Available students (enrolled in institute, not currently in this batch)
        $whereClass = "";
        $availParams = [$parent_admin_id, $batch_id];
        
        $filter_class = !empty($_GET['filter_class']) ? (int)$_GET['filter_class'] : 0;
        if ($filter_class > 0) {
            $whereClass = " AND s.class_id = ?";
            $availParams[] = $filter_class;
        } elseif (!empty($batch['class_id']) && empty($_GET['all_classes'])) {
            // By default filter by batch's target class if set
            $whereClass = " AND s.class_id = ?";
            $availParams[] = (int)$batch['class_id'];
        }

        $stmtAvail = $pdo->prepare("
            SELECT 
                s.id as student_id,
                s.full_name,
                s.username,
                s.email,
                s.class_id,
                c.name as class_name,
                b.name as board_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN boards b ON s.board_id = b.id
            WHERE s.admin_id = ?
              AND s.is_active = 1
              AND s.id NOT IN (SELECT student_id FROM batch_students WHERE batch_id = ?)
              {$whereClass}
            ORDER BY s.full_name ASC
            LIMIT 200
        ");
        $stmtAvail->execute($availParams);
        $available = $stmtAvail->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'   => true,
            'batch'     => [
                'id'       => (int)$batch['id'],
                'name'     => $batch['name'],
                'class_id' => $batch['class_id'],
            ],
            'assigned'  => $assigned,
            'available' => $available
        ]);
        exit;
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ACTION: ADD STUDENTS TO BATCH
if ($action === 'add') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $rawIds = $_POST['student_ids'] ?? [];
    if (!is_array($rawIds)) {
        $rawIds = [$rawIds];
    }
    $student_ids = array_filter(array_map('intval', $rawIds));

    if (empty($student_ids)) {
        echo json_encode(['success' => false, 'error' => 'Please select at least one student to add.']);
        exit;
    }

    try {
        $added = 0;
        $insertStmt = $pdo->prepare("
            INSERT INTO batch_students (batch_id, student_id, joined_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE joined_at = joined_at
        ");

        $checkStmt = $pdo->prepare("SELECT id FROM students WHERE id = ? AND admin_id = ?");

        foreach ($student_ids as $sid) {
            // Verify student belongs to this institute
            if (!is_super_admin()) {
                $checkStmt->execute([$sid, $parent_admin_id]);
                if (!$checkStmt->fetch()) {
                    continue; // skip foreign student
                }
            }

            $insertStmt->execute([$batch_id, $sid]);
            if ($insertStmt->rowCount() > 0) {
                $added++;
            }
        }

        echo json_encode([
            'success'     => true,
            'message'     => "{$added} student(s) successfully added to batch!",
            'added_count' => $added
        ]);
        exit;
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ACTION: REMOVE STUDENT FROM BATCH
if ($action === 'remove') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }

    $student_id = (int)($_POST['student_id'] ?? 0);
    if (!$student_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid student ID.']);
        exit;
    }

    try {
        $stmtDel = $pdo->prepare("DELETE FROM batch_students WHERE batch_id = ? AND student_id = ?");
        $stmtDel->execute([$batch_id, $student_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Student removed from batch successfully.'
        ]);
        exit;
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action.']);

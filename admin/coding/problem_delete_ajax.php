<?php
// admin/coding/problem_delete_ajax.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!admin_has_feature('coding') && !is_teacher()) {
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

$problem_id = (int)($_POST['problem_id'] ?? 0);
if (!$problem_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Problem ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM coding_problems WHERE id = ?");
$stmt->execute([$problem_id]);
$prob = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prob) {
    echo json_encode(['success' => false, 'error' => 'Problem not found.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$prob['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this problem.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Check if problem is part of any active competitions
    $checkComp = $pdo->prepare("
        SELECT c.title FROM coding_competitions c 
        JOIN coding_competition_problems cp ON c.id = cp.competition_id 
        WHERE cp.problem_id = ? AND c.end_time > NOW() AND c.is_active = 1
    ");
    $checkComp->execute([$problem_id]);
    $activeComp = $checkComp->fetchColumn();
    if ($activeComp) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => "Cannot delete: This problem is assigned to active competition '{$activeComp}'."]);
        exit;
    }

    // Cascade deletes test_cases, competition_problems, submissions via foreign keys
    $stmtDel = $pdo->prepare("DELETE FROM coding_problems WHERE id = ?");
    $stmtDel->execute([$problem_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Problem deleted successfully.']);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Problem delete error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to delete problem: ' . $e->getMessage()]);
}

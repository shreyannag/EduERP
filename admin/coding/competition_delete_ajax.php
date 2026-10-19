<?php
// admin/coding/competition_delete_ajax.php
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

$comp_id = (int)($_POST['competition_id'] ?? 0);
if (!$comp_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid Competition ID.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM coding_competitions WHERE id = ?");
$stmt->execute([$comp_id]);
$comp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$comp) {
    echo json_encode(['success' => false, 'error' => 'Competition not found.']);
    exit;
}

$effective_admin_id = get_effective_admin_id();
if (!is_super_admin()) {
    if ((int)$comp['admin_id'] !== $effective_admin_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access Denied: You do not own this competition.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Delete competition submissions
    $stmtDelSubs = $pdo->prepare("DELETE FROM coding_submissions WHERE competition_id = ?");
    $stmtDelSubs->execute([$comp_id]);

    // Delete competition problems mapping
    $stmtDelProbs = $pdo->prepare("DELETE FROM coding_competition_problems WHERE competition_id = ?");
    $stmtDelProbs->execute([$comp_id]);

    // Delete competition
    $stmtDel = $pdo->prepare("DELETE FROM coding_competitions WHERE id = ?");
    $stmtDel->execute([$comp_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Competition deleted successfully.']);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Competition delete error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to delete competition: ' . $e->getMessage()]);
}

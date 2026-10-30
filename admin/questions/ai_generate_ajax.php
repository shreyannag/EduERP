<?php
// admin/questions/ai_generate_ajax.php
// AJAX endpoint for on-demand AI question generation via OpenRouter.

require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/ai_generator.php';

require_admin_feature('ai_generation');

@set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// Verify CSRF
$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Please reload and try again.']);
    exit;
}

$classId   = intval($_POST['class_id'] ?? 0);
$boardId   = intval($_POST['board_id'] ?? 0);
$subjectId = !empty($_POST['subject_id']) ? intval($_POST['subject_id']) : 0;
$topicId   = !empty($_POST['topic_id']) ? intval($_POST['topic_id']) : 0;
$prompt    = trim($_POST['prompt'] ?? '');
$question_type = in_array($_POST['question_type'] ?? '', ['mcq', 'fill_blank']) ? $_POST['question_type'] : 'mcq';
$difficulty= trim($_POST['difficulty'] ?? 'Medium');
$marks     = max(1, intval($_POST['marks'] ?? 1));
$model     = trim($_POST['model'] ?? '');
$apiKey    = trim((string)($_POST['api_key'] ?? ''), " \t\n\r\0\x0B\"'");

// Fetch metadata names for richer AI prompt
$className = '';
if ($classId) {
    $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
    $stmt->execute([$classId]);
    $className = $stmt->fetchColumn() ?: '';
}

$boardName = '';
if ($boardId) {
    $stmt = $pdo->prepare("SELECT name FROM boards WHERE id = ?");
    $stmt->execute([$boardId]);
    $boardName = $stmt->fetchColumn() ?: '';
}

$admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();

$subjectName = '';
if ($subjectId) {
    if ($is_super) {
        $stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
        $stmt->execute([$subjectId]);
    } else {
        $stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ? AND admin_id = ?");
        $stmt->execute([$subjectId, $admin_id]);
    }
    $subjectName = $stmt->fetchColumn() ?: '';
}

$topicName = '';
if ($topicId) {
    if ($is_super) {
        $stmt = $pdo->prepare("SELECT name FROM topics WHERE id = ?");
        $stmt->execute([$topicId]);
    } else {
        $stmt = $pdo->prepare("SELECT name FROM topics WHERE id = ? AND admin_id = ?");
        $stmt->execute([$topicId, $admin_id]);
    }
    $topicName = $stmt->fetchColumn() ?: '';
}

// If user supplied an API key in the modal, optionally save it for future use
if (!empty($apiKey) && !empty($_POST['save_key'])) {
    save_openrouter_config($pdo, ['api_key' => $apiKey], $admin_id);
}

$params = [
    'class_name'   => $className ?: 'Secondary Education',
    'board_name'   => $boardName ?: 'Standard Curriculum',
    'subject_name' => $subjectName ?: 'General Studies',
    'topic_name'   => $topicName,
    'prompt'       => $prompt,
    'difficulty'   => $difficulty,
    'marks'        => $marks,
    'model'        => $model,
    'api_key'      => $apiKey
];

try {
    if ($question_type === 'fill_blank') {
        $result = generate_fill_blank_question($params, $pdo, $admin_id);
    } else {
        $result = generate_mcq_question($params, $pdo, $admin_id);
    }
} catch (\Throwable $e) {
    $result = [
        'success' => false,
        'error'   => 'Server execution error: ' . $e->getMessage()
    ];
}

echo json_encode($result);

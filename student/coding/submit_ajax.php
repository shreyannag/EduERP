<?php
// student/coding/submit_ajax.php
// Official submission endpoint: grades full test suite and records submission

require_once __DIR__ . '/../../includes/student_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

header('Content-Type: application/json; charset=utf-8');

$stmtAdmin = $pdo->prepare("SELECT admin_id FROM students WHERE id = ?");
$stmtAdmin->execute([$_SESSION['student_id']]);
$student_admin_id = (int)$stmtAdmin->fetchColumn() ?: 1;

if (!is_coding_platform_enabled($pdo, $student_admin_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'The Online Coding Platform is currently disabled for your institute.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

// Anti-DDoS & resource exhaustion: rate limit code submissions per student session
$last_sub = $_SESSION['last_coding_submit_time'] ?? 0;
if (microtime(true) - $last_sub < 3.0) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Please wait at least 3 seconds between contest submissions.']);
    exit;
}
$_SESSION['last_coding_submit_time'] = microtime(true);

$student_id = (int)$_SESSION['student_id'];
$problem_id = (int)($_POST['problem_id'] ?? 0);
$competition_id = !empty($_POST['competition_id']) ? (int)$_POST['competition_id'] : null;
$language = strtolower(trim($_POST['language'] ?? ''));
$source_code = $_POST['source_code'] ?? '';

$supported_languages = get_supported_coding_languages();
if (!isset($supported_languages[$language])) {
    echo json_encode(['success' => false, 'error' => 'Unsupported programming language.']);
    exit;
}

if (trim($source_code) === '') {
    echo json_encode(['success' => false, 'error' => 'Source code cannot be empty.']);
    exit;
}

// Verify problem
$stmtP = $pdo->prepare("SELECT * FROM coding_problems WHERE id = ? AND is_active = 1");
$stmtP->execute([$problem_id]);
$problem = $stmtP->fetch(PDO::FETCH_ASSOC);

if (!$problem) {
    echo json_encode(['success' => false, 'error' => 'Problem not found or inactive.']);
    exit;
}

// If competition_id is provided, verify eligibility and active window
if ($competition_id) {
    $stmtC = $pdo->prepare("SELECT * FROM coding_competitions WHERE id = ? AND is_active = 1");
    $stmtC->execute([$competition_id]);
    $comp = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$comp) {
        echo json_encode(['success' => false, 'error' => 'Competition not found or inactive.']);
        exit;
    }

    $now = time();
    $st = strtotime($comp['start_time']);
    $et = strtotime($comp['end_time']);

    if ($now < $st) {
        echo json_encode(['success' => false, 'error' => 'This competition has not started yet.']);
        exit;
    }

    if ($now > $et) {
        echo json_encode(['success' => false, 'error' => 'This competition has already concluded. Submissions are closed.']);
        exit;
    }

    // Verify problem is part of this competition
    $stmtCheck = $pdo->prepare("SELECT points FROM coding_competition_problems WHERE competition_id = ? AND problem_id = ?");
    $stmtCheck->execute([$competition_id, $problem_id]);
    if (!$stmtCheck->fetch()) {
        echo json_encode(['success' => false, 'error' => 'This problem does not belong to the selected competition.']);
        exit;
    }
}

try {
    $verdict = judge_full_submission($pdo, $student_id, $problem_id, $competition_id, $language, $source_code);

    echo json_encode([
        'success' => true,
        'submission_id' => $verdict['submission_id'],
        'status' => $verdict['status'], // AC, WA, TLE, CE, RE
        'score' => $verdict['score'],
        'passed_test_cases' => $verdict['passed_test_cases'],
        'total_test_cases' => $verdict['total_test_cases'],
        'execution_time_ms' => $verdict['execution_time_ms'],
        'compile_output' => $verdict['compile_output'],
        'test_case_results' => $verdict['test_case_results']
    ]);
} catch (\Throwable $e) {
    error_log("submit_ajax error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Submission judge error: ' . $e->getMessage()]);
}

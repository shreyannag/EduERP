<?php
// student/coding/run_ajax.php
// Compiles and runs student code against sample test cases or custom STDIN

require_once __DIR__ . '/../../includes/student_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/coding_engine.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_coding_platform_enabled($pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'The Online Coding Platform is currently disabled by administration.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

verify_csrf_token($_POST['csrf_token'] ?? '');

$student_id = (int)$_SESSION['student_id'];
$problem_id = (int)($_POST['problem_id'] ?? 0);
$language = strtolower(trim($_POST['language'] ?? ''));
$source_code = $_POST['source_code'] ?? '';
$use_custom_input = (int)($_POST['use_custom_input'] ?? 0);
$custom_input = $_POST['custom_input'] ?? '';

$supported_languages = get_supported_coding_languages();
if (!isset($supported_languages[$language])) {
    echo json_encode(['success' => false, 'error' => 'Unsupported programming language.']);
    exit;
}

if (trim($source_code) === '') {
    echo json_encode(['success' => false, 'error' => 'Source code cannot be empty.']);
    exit;
}

$stmtP = $pdo->prepare("SELECT * FROM coding_problems WHERE id = ? AND is_active = 1");
$stmtP->execute([$problem_id]);
$problem = $stmtP->fetch(PDO::FETCH_ASSOC);

if (!$problem) {
    echo json_encode(['success' => false, 'error' => 'Problem not found or inactive.']);
    exit;
}

$timeout_seconds = max(1, (int)ceil($problem['time_limit_ms'] / 1000));

try {
    if ($use_custom_input) {
        // Execute against student custom STDIN
        $res = execute_code($language, $source_code, $custom_input, $timeout_seconds);
        echo json_encode([
            'success' => true,
            'mode' => 'custom',
            'status' => $res['status'],
            'output' => $res['output'],
            'error' => $res['error'],
            'execution_time_ms' => $res['execution_time_ms'],
            'compile_output' => $res['compile_output']
        ]);
        exit;
    } else {
        // Execute against sample test cases
        $stmtTC = $pdo->prepare("SELECT * FROM coding_test_cases WHERE problem_id = ? AND is_sample = 1 ORDER BY id ASC");
        $stmtTC->execute([$problem_id]);
        $sampleCases = $stmtTC->fetchAll(PDO::FETCH_ASSOC);

        // If no explicit sample test case in test_cases table, use problem's sample_input/sample_output
        if (empty($sampleCases) && !empty($problem['sample_output'])) {
            $sampleCases[] = [
                'id' => 0,
                'input_data' => $problem['sample_input'],
                'expected_output' => $problem['sample_output']
            ];
        }

        if (empty($sampleCases)) {
            // Default empty test case
            $sampleCases[] = [
                'id' => 0,
                'input_data' => '',
                'expected_output' => ''
            ];
        }

        $results = [];
        $overall_status = 'PASS';
        $max_time = 0;
        $compile_output = '';

        foreach ($sampleCases as $idx => $tc) {
            $j = judge_single_test_case($language, $source_code, $tc['input_data'] ?? '', $tc['expected_output'], $timeout_seconds);
            if ($j['execution_time_ms'] > $max_time) {
                $max_time = $j['execution_time_ms'];
            }
            if (!empty($j['compile_output'])) {
                $compile_output = $j['compile_output'];
            }
            if ($j['status'] !== 'AC' && $overall_status === 'PASS') {
                $overall_status = $j['status'];
            }

            $results[] = [
                'case_num' => $idx + 1,
                'input' => $tc['input_data'] ?? '',
                'expected' => $tc['expected_output'],
                'actual' => $j['actual_output'],
                'status' => $j['status'], // AC, WA, TLE, CE, RE
                'error' => $j['error'],
                'time_ms' => $j['execution_time_ms']
            ];

            // If compilation failed, stop evaluating further test cases
            if ($j['status'] === 'CE') {
                break;
            }
        }

        echo json_encode([
            'success' => true,
            'mode' => 'sample',
            'overall_status' => $overall_status,
            'execution_time_ms' => $max_time,
            'compile_output' => $compile_output,
            'results' => $results
        ]);
        exit;
    }
} catch (\Throwable $e) {
    error_log("run_ajax error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Code execution exception: ' . $e->getMessage()]);
}

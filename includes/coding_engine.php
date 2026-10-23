<?php
// includes/coding_engine.php
// Online Code Compilation, Sandboxed Execution, Test Case Judge & Competition Leaderboard Engine

require_once __DIR__ . '/../config/db.php';

/**
 * Checks whether the Online Coding Platform is enabled globally and for a specific admin/institute.
 */
function is_coding_platform_enabled(PDO $pdo, ?int $admin_id = null): bool {
    try {
        // Check global platform switch
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'coding_platform_enabled'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        $globalEnabled = ($val === false || $val === null || (string)$val === '1');
        if (!$globalEnabled) {
            return false;
        }

        // If specific admin ID is provided, check if that admin has 'coding' feature enabled
        if ($admin_id !== null && $admin_id > 0) {
            $stmtAdm = $pdo->prepare("SELECT role, parent_admin_id, features FROM admins WHERE id = ?");
            $stmtAdm->execute([$admin_id]);
            $adm = $stmtAdm->fetch(PDO::FETCH_ASSOC);

            if (!$adm) {
                return false;
            }

            // Super Admin always has access
            if ($adm['role'] === 'super_admin') {
                return true;
            }

            // If sub-admin, verify that the parent institute admin also has coding enabled
            if ($adm['role'] === 'sub_admin' && !empty($adm['parent_admin_id']) && (int)$adm['parent_admin_id'] !== $admin_id) {
                $parentHasCoding = is_coding_platform_enabled($pdo, (int)$adm['parent_admin_id']);
                if (!$parentHasCoding) {
                    return false;
                }
            }

            $rawFeats = !empty($adm['features']) ? json_decode($adm['features'], true) : [];
            $feats = is_array($rawFeats) ? $rawFeats : [];
            return in_array('coding', $feats, true);
        }

        return true;
    } catch (\Throwable $e) {
        return true;
    }
}

/**
 * Switches the Online Coding Platform ON or OFF globally.
 */
function set_coding_platform_enabled(PDO $pdo, bool $enabled): bool {
    try {
        $val = $enabled ? '1' : '0';
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES ('coding_platform_enabled', ?)
            ON DUPLICATE KEY UPDATE setting_value = ?
        ");
        return $stmt->execute([$val, $val]);
    } catch (\Throwable $e) {
        error_log("Failed to update coding_platform_enabled: " . $e->getMessage());
        return false;
    }
}

/**
 * Returns supported programming languages and configuration.
 */
function get_supported_coding_languages(): array {
    return [
        'python' => [
            'key'         => 'python',
            'name'        => 'Python 3',
            'version'     => '3.12+',
            'badge_color' => '#3b82f6',
            'icon'        => 'fab fa-python',
            'ace_mode'    => 'python',
            'ext'         => 'py',
            'starter'     => "# Write your Python 3 solution here\nimport sys\n\ndef solve():\n    lines = sys.stdin.read().split()\n    if not lines:\n        return\n    # Process input\n\nif __name__ == '__main__':\n    solve()\n"
        ],
        'cpp' => [
            'key'         => 'cpp',
            'name'        => 'C++ (C++17)',
            'version'     => 'GCC 13 / C++17',
            'badge_color' => '#6366f1',
            'icon'        => 'fas fa-code',
            'ace_mode'    => 'c_cpp',
            'ext'         => 'cpp',
            'starter'     => "// Write your C++ solution here\n#include <iostream>\n#include <vector>\n#include <string>\n#include <algorithm>\n\nusing namespace std;\n\nint main() {\n    ios_base::sync_with_stdio(false);\n    cin.tie(NULL);\n    \n    // Read input and solve\n    \n    return 0;\n}\n"
        ],
        'c' => [
            'key'         => 'c',
            'name'        => 'C (C11)',
            'version'     => 'GCC 13 / C11',
            'badge_color' => '#0ea5e9',
            'icon'        => 'fas fa-terminal',
            'ace_mode'    => 'c_cpp',
            'ext'         => 'c',
            'starter'     => "// Write your C solution here\n#include <stdio.h>\n#include <stdlib.h>\n#include <string.h>\n\nint main() {\n    // Read input and solve\n    \n    return 0;\n}\n"
        ],
        'java' => [
            'key'         => 'java',
            'name'        => 'Java 17',
            'version'     => 'OpenJDK 17',
            'badge_color' => '#f97316',
            'icon'        => 'fab fa-java',
            'ace_mode'    => 'java',
            'ext'         => 'java',
            'starter'     => "// Write your Java solution here\nimport java.util.*;\nimport java.io.*;\n\npublic class Solution {\n    public static void main(String[] args) {\n        Scanner sc = new Scanner(System.in);\n        // Read input and solve\n        \n    }\n}\n"
        ]
    ];
}

/**
 * Returns starter code template for a specific language.
 */
function get_starter_code_for_lang(string $lang, array $problem = []): string {
    $lang = strtolower(trim($lang));
    if (!empty($problem["starter_code_{$lang}"])) {
        return $problem["starter_code_{$lang}"];
    }
    $langs = get_supported_coding_languages();
    return $langs[$lang]['starter'] ?? "// Write your solution here\n";
}

/**
 * Internal process executor with non-blocking stream select and strict timeout enforcement.
 */
function execute_system_process(string $cmd, string $stdin = '', ?string $cwd = null, float $timeoutSec = 3.0): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    $startTime = microtime(true);
    $process = @proc_open($cmd, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
        return [
            'exit_code' => -1,
            'stdout'    => '',
            'stderr'    => 'Failed to spawn process runner.',
            'time_ms'   => 0,
            'timed_out' => false
        ];
    }

    // Write STDIN
    if ($stdin !== '') {
        @fwrite($pipes[0], $stdin);
    }
    @fclose($pipes[0]);

    @stream_set_blocking($pipes[1], false);
    @stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        @stream_select($read, $write, $except, 0, 50000); // 50ms interval

        $chunkOut = @stream_get_contents($pipes[1]);
        if ($chunkOut !== false && $chunkOut !== '') $stdout .= $chunkOut;

        $chunkErr = @stream_get_contents($pipes[2]);
        if ($chunkErr !== false && $chunkErr !== '') $stderr .= $chunkErr;

        $status = @proc_get_status($process);
        if (!$status || !$status['running']) {
            break;
        }

        if ((microtime(true) - $startTime) > $timeoutSec) {
            $timedOut = true;
            @proc_terminate($process, 9);
            break;
        }
    }

    $finalOut = @stream_get_contents($pipes[1]);
    if ($finalOut !== false && $finalOut !== '') $stdout .= $finalOut;

    $finalErr = @stream_get_contents($pipes[2]);
    if ($finalErr !== false && $finalErr !== '') $stderr .= $finalErr;

    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = @proc_close($process);

    $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

    return [
        'exit_code' => $exitCode,
        'stdout'    => $stdout,
        'stderr'    => $stderr,
        'time_ms'   => $elapsedMs,
        'timed_out' => $timedOut
    ];
}

/**
 * Compiles and executes code in an isolated temporary sandbox directory.
 */
function _execute_code_internal(string $language, string $sourceCode, string $stdin = '', int $timeLimitMs = 2000): array {
    $language = strtolower(trim($language));
    $timeoutSec = max(0.5, (float)$timeLimitMs / 1000.0);
    $compileTimeoutSec = 10.0;

    // Create unique temporary workspace
    $tempBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'code_sandboxes';
    if (!is_dir($tempBase)) {
        @mkdir($tempBase, 0777, true);
    }
    $runId = bin2hex(random_bytes(8));
    $workDir = $tempBase . DIRECTORY_SEPARATOR . 'run_' . $runId;
    if (!@mkdir($workDir, 0777, true)) {
        return [
            'status'         => 'RE',
            'stdout'         => '',
            'stderr'         => 'Could not create isolated execution directory.',
            'compile_output' => '',
            'time_ms'        => 0,
            'timed_out'      => false
        ];
    }

    $isWindows = (DIRECTORY_SEPARATOR === '\\');

    try {
        switch ($language) {
            case 'python':
                $scriptFile = $workDir . DIRECTORY_SEPARATOR . 'solution.py';
                file_put_contents($scriptFile, $sourceCode);
                $cmd = $isWindows ? "python \"{$scriptFile}\"" : "python3 \"{$scriptFile}\"";
                $runRes = execute_system_process($cmd, $stdin, $workDir, $timeoutSec);
                
                if ($runRes['timed_out']) {
                    return [
                        'status'         => 'TLE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => 'Time Limit Exceeded (' . $timeLimitMs . 'ms)',
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => true
                    ];
                }
                if ($runRes['exit_code'] !== 0) {
                    return [
                        'status'         => 'RE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => $runRes['stderr'],
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => false
                    ];
                }
                return [
                    'status'         => 'AC',
                    'stdout'         => $runRes['stdout'],
                    'stderr'         => $runRes['stderr'],
                    'compile_output' => '',
                    'time_ms'        => $runRes['time_ms'],
                    'timed_out'      => false
                ];

            case 'c':
                $srcFile = $workDir . DIRECTORY_SEPARATOR . 'solution.c';
                $binName = $isWindows ? 'solution.exe' : 'solution';
                $binPath = $workDir . DIRECTORY_SEPARATOR . $binName;
                file_put_contents($srcFile, $sourceCode);

                // Compile
                $compileCmd = "gcc -O2 -std=c11 \"{$srcFile}\" -o \"{$binPath}\" -lm";
                $compRes = execute_system_process($compileCmd, '', $workDir, $compileTimeoutSec);
                if ($compRes['exit_code'] !== 0 || !file_exists($binPath)) {
                    return [
                        'status'         => 'CE',
                        'stdout'         => '',
                        'stderr'         => $compRes['stderr'] ?: 'Compilation failed.',
                        'compile_output' => $compRes['stderr'] ?: $compRes['stdout'],
                        'time_ms'        => $compRes['time_ms'],
                        'timed_out'      => false
                    ];
                }

                // Run
                $runCmd = $isWindows ? "\"{$binPath}\"" : "./{$binName}";
                $runRes = execute_system_process($runCmd, $stdin, $workDir, $timeoutSec);
                if ($runRes['timed_out']) {
                    return [
                        'status'         => 'TLE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => 'Time Limit Exceeded (' . $timeLimitMs . 'ms)',
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => true
                    ];
                }
                if ($runRes['exit_code'] !== 0) {
                    return [
                        'status'         => 'RE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => $runRes['stderr'],
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => false
                    ];
                }
                return [
                    'status'         => 'AC',
                    'stdout'         => $runRes['stdout'],
                    'stderr'         => $runRes['stderr'],
                    'compile_output' => '',
                    'time_ms'        => $runRes['time_ms'],
                    'timed_out'      => false
                ];

            case 'cpp':
                $srcFile = $workDir . DIRECTORY_SEPARATOR . 'solution.cpp';
                $binName = $isWindows ? 'solution.exe' : 'solution';
                $binPath = $workDir . DIRECTORY_SEPARATOR . $binName;
                file_put_contents($srcFile, $sourceCode);

                // Compile
                $compileCmd = "g++ -O2 -std=c++17 \"{$srcFile}\" -o \"{$binPath}\"";
                $compRes = execute_system_process($compileCmd, '', $workDir, $compileTimeoutSec);
                if ($compRes['exit_code'] !== 0 || !file_exists($binPath)) {
                    return [
                        'status'         => 'CE',
                        'stdout'         => '',
                        'stderr'         => $compRes['stderr'] ?: 'Compilation failed.',
                        'compile_output' => $compRes['stderr'] ?: $compRes['stdout'],
                        'time_ms'        => $compRes['time_ms'],
                        'timed_out'      => false
                    ];
                }

                // Run
                $runCmd = $isWindows ? "\"{$binPath}\"" : "./{$binName}";
                $runRes = execute_system_process($runCmd, $stdin, $workDir, $timeoutSec);
                if ($runRes['timed_out']) {
                    return [
                        'status'         => 'TLE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => 'Time Limit Exceeded (' . $timeLimitMs . 'ms)',
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => true
                    ];
                }
                if ($runRes['exit_code'] !== 0) {
                    return [
                        'status'         => 'RE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => $runRes['stderr'],
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => false
                    ];
                }
                return [
                    'status'         => 'AC',
                    'stdout'         => $runRes['stdout'],
                    'stderr'         => $runRes['stderr'],
                    'compile_output' => '',
                    'time_ms'        => $runRes['time_ms'],
                    'timed_out'      => false
                ];

            case 'java':
                // Check public class name or default to Solution
                $className = 'Solution';
                if (preg_match('/public\s+class\s+([A-Za-z0-9_]+)/', $sourceCode, $matches)) {
                    $className = $matches[1];
                }
                $srcFile = $workDir . DIRECTORY_SEPARATOR . $className . '.java';
                file_put_contents($srcFile, $sourceCode);

                // Compile
                $compileCmd = "javac \"{$srcFile}\"";
                $compRes = execute_system_process($compileCmd, '', $workDir, $compileTimeoutSec);
                if ($compRes['exit_code'] !== 0 || !file_exists($workDir . DIRECTORY_SEPARATOR . $className . '.class')) {
                    return [
                        'status'         => 'CE',
                        'stdout'         => '',
                        'stderr'         => $compRes['stderr'] ?: 'Java compilation failed.',
                        'compile_output' => $compRes['stderr'] ?: $compRes['stdout'],
                        'time_ms'        => $compRes['time_ms'],
                        'timed_out'      => false
                    ];
                }

                // Run
                $runCmd = "java {$className}";
                $runRes = execute_system_process($runCmd, $stdin, $workDir, $timeoutSec + 1.0); // extra buffer for JVM startup
                if ($runRes['timed_out']) {
                    return [
                        'status'         => 'TLE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => 'Time Limit Exceeded (' . $timeLimitMs . 'ms)',
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => true
                    ];
                }
                if ($runRes['exit_code'] !== 0) {
                    return [
                        'status'         => 'RE',
                        'stdout'         => $runRes['stdout'],
                        'stderr'         => $runRes['stderr'],
                        'compile_output' => '',
                        'time_ms'        => $runRes['time_ms'],
                        'timed_out'      => false
                    ];
                }
                return [
                    'status'         => 'AC',
                    'stdout'         => $runRes['stdout'],
                    'stderr'         => $runRes['stderr'],
                    'compile_output' => '',
                    'time_ms'        => $runRes['time_ms'],
                    'timed_out'      => false
                ];

            default:
                return [
                    'status'         => 'RE',
                    'stdout'         => '',
                    'stderr'         => "Unsupported language: '{$language}'.",
                    'compile_output' => '',
                    'time_ms'        => 0,
                    'timed_out'      => false
                ];
        }
    } finally {
        // Safe recursive directory cleanup
        clean_directory_recursively($workDir);
    }
}

/**
 * Public wrapper for compiling and executing code with full result key aliases.
 */
function execute_code(string $language, string $sourceCode, string $stdin = '', int $timeLimitMs = 2000): array {
    $res = _execute_code_internal($language, $sourceCode, $stdin, $timeLimitMs);
    $res['output'] = $res['stdout'] ?? '';
    $res['error'] = $res['stderr'] ?? '';
    $res['execution_time_ms'] = $res['time_ms'] ?? 0;
    $res['ok'] = ($res['status'] === 'AC');
    return $res;
}

/**
 * Normalizes string output for robust cross-platform comparison.
 */
function normalize_code_output(string $str): string {
    // Standardize CRLF to LF, strip trailing blank spaces and trailing newlines
    $str = str_replace("\r\n", "\n", $str);
    $str = str_replace("\r", "\n", $str);
    $lines = explode("\n", $str);
    $trimmedLines = array_map('rtrim', $lines);
    $result = implode("\n", $trimmedLines);
    return trim($result);
}

/**
 * Judges a single test case input against expected output.
 */
function judge_single_test_case(string $language, string $sourceCode, string $inputData, string $expectedOutput, int $timeLimitMs = 2000): array {
    $exec = execute_code($language, $sourceCode, $inputData, $timeLimitMs);

    if ($exec['status'] === 'CE' || $exec['status'] === 'TLE' || $exec['status'] === 'RE') {
        return [
            'status'            => $exec['status'],
            'actual_output'     => $exec['stdout'],
            'output'            => $exec['stdout'],
            'expected_output'   => $expectedOutput,
            'error_message'     => $exec['stderr'] ?: $exec['compile_output'],
            'error'             => $exec['stderr'] ?: $exec['compile_output'],
            'compile_output'    => $exec['compile_output'] ?? '',
            'time_ms'           => $exec['time_ms'],
            'execution_time_ms' => $exec['time_ms']
        ];
    }

    $actualNorm = normalize_code_output($exec['stdout']);
    $expectedNorm = normalize_code_output($expectedOutput);

    $isMatch = ($actualNorm === $expectedNorm);

    return [
        'status'            => $isMatch ? 'AC' : 'WA',
        'actual_output'     => $exec['stdout'],
        'output'            => $exec['stdout'],
        'expected_output'   => $expectedOutput,
        'error_message'     => $isMatch ? '' : 'Output did not match expected result.',
        'error'             => $isMatch ? '' : 'Output did not match expected result.',
        'compile_output'    => $exec['compile_output'] ?? '',
        'time_ms'           => $exec['time_ms'],
        'execution_time_ms' => $exec['time_ms']
    ];
}

/**
 * Judges an entire submission against all test cases of a problem and records it in database.
 */
function judge_full_submission(...$args): array {
    if (isset($args[0]) && $args[0] instanceof PDO) {
        $pdo = $args[0];
        $studentId = (int)$args[1];
        $problemId = (int)$args[2];
        $competitionId = !empty($args[3]) ? (int)$args[3] : null;
        $language = (string)$args[4];
        $sourceCode = (string)$args[5];
    } else {
        $problemId = (int)$args[0];
        $language = (string)$args[1];
        $sourceCode = (string)$args[2];
        $pdo = $args[3];
        $competitionId = !empty($args[4]) ? (int)$args[4] : null;
        $studentId = !empty($args[5]) ? (int)$args[5] : null;
    }

    // 1. Fetch problem
    $stmtP = $pdo->prepare("SELECT * FROM coding_problems WHERE id = ?");
    $stmtP->execute([$problemId]);
    $problem = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$problem) {
        return ['success' => false, 'error' => 'Problem not found.'];
    }

    $timeLimitMs = (int)($problem['time_limit_ms'] ?: 2000);
    $totalProblemPoints = (int)($problem['total_points'] ?: 100);

    // 2. Fetch test cases
    $stmtTC = $pdo->prepare("SELECT * FROM coding_test_cases WHERE problem_id = ? ORDER BY is_sample DESC, id ASC");
    $stmtTC->execute([$problemId]);
    $testCases = $stmtTC->fetchAll(PDO::FETCH_ASSOC);

    if (empty($testCases)) {
        // Fallback to sample test case from problem definition
        $testCases = [[
            'id'              => 0,
            'input_data'      => $problem['sample_input'] ?? '',
            'expected_output' => $problem['sample_output'] ?? '',
            'is_sample'       => 1,
            'is_hidden'       => 0,
            'points'          => $totalProblemPoints
        ]];
    }

    $totalCases = count($testCases);
    $passedCases = 0;
    $overallStatus = 'AC';
    $maxTimeMs = 0;
    $compileOutput = '';
    $results = [];

    // Evaluate each test case
    foreach ($testCases as $idx => $tc) {
        $caseNum = $idx + 1;
        $tcRes = judge_single_test_case($language, $sourceCode, (string)$tc['input_data'], (string)$tc['expected_output'], $timeLimitMs);

        $maxTimeMs = max($maxTimeMs, (int)$tcRes['time_ms']);

        if ($tcRes['status'] === 'CE') {
            $overallStatus = 'CE';
            $compileOutput = $tcRes['error_message'];
            $results[] = [
                'case_number'     => $caseNum,
                'status'          => 'CE',
                'is_sample'       => (bool)$tc['is_sample'],
                'input'           => $tc['is_sample'] ? $tc['input_data'] : '[Hidden Test Case]',
                'expected_output' => $tc['is_sample'] ? $tc['expected_output'] : '[Hidden Test Case]',
                'actual_output'   => '',
                'error'           => $tcRes['error_message'],
                'time_ms'         => $tcRes['time_ms']
            ];
            // Stop on compilation error
            break;
        }

        if ($tcRes['status'] === 'AC') {
            $passedCases++;
            $results[] = [
                'case_number'     => $caseNum,
                'status'          => 'AC',
                'is_sample'       => (bool)$tc['is_sample'],
                'input'           => $tc['is_sample'] ? $tc['input_data'] : '[Hidden Test Case]',
                'expected_output' => $tc['is_sample'] ? $tc['expected_output'] : '[Hidden Test Case]',
                'actual_output'   => $tc['is_sample'] ? $tcRes['actual_output'] : '[Hidden Test Case Output]',
                'error'           => '',
                'time_ms'         => $tcRes['time_ms']
            ];
        } else {
            if ($overallStatus === 'AC') {
                $overallStatus = $tcRes['status']; // Set to first failing status (WA, TLE, RE)
            }
            $results[] = [
                'case_number'     => $caseNum,
                'status'          => $tcRes['status'],
                'is_sample'       => (bool)$tc['is_sample'],
                'input'           => $tc['is_sample'] ? $tc['input_data'] : '[Hidden Test Case]',
                'expected_output' => $tc['is_sample'] ? $tc['expected_output'] : '[Hidden Test Case]',
                'actual_output'   => $tc['is_sample'] ? $tcRes['actual_output'] : '[Hidden Test Case Output]',
                'error'           => $tcRes['error_message'],
                'time_ms'         => $tcRes['time_ms']
            ];
        }
    }

    // Calculate score
    $score = ($totalCases > 0) ? round(($passedCases / $totalCases) * $totalProblemPoints, 2) : 0.00;

    // Record submission if studentId is provided
    $submissionId = null;
    if ($studentId) {
        try {
            $stmtSub = $pdo->prepare("
                INSERT INTO coding_submissions (
                    competition_id, problem_id, student_id, language, source_code,
                    status, score, passed_test_cases, total_test_cases, execution_time_ms,
                    compile_output, submitted_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, NOW()
                )
            ");
            $stmtSub->execute([
                $competitionId,
                $problemId,
                $studentId,
                $language,
                $sourceCode,
                $overallStatus,
                $score,
                $passedCases,
                $totalCases,
                $maxTimeMs,
                !empty($compileOutput) ? $compileOutput : null
            ]);
            $submissionId = (int)$pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log("Failed to insert coding submission: " . $e->getMessage());
        }
    }

    return [
        'success'           => true,
        'submission_id'     => $submissionId,
        'status'            => $overallStatus,
        'overall_status'    => $overallStatus,
        'score'             => $score,
        'total_points'      => $totalProblemPoints,
        'passed_test_cases' => $passedCases,
        'total_test_cases'  => $totalCases,
        'execution_time_ms' => $maxTimeMs,
        'time_ms'           => $maxTimeMs,
        'compile_output'    => $compileOutput,
        'results'           => $results,
        'test_case_results' => $results
    ];
}

/**
 * Calculates live real-time leaderboard rankings for a Coding Competition.
 */
function get_competition_leaderboard($arg1, $arg2): array {
    if ($arg1 instanceof PDO) {
        $pdo = $arg1;
        $competitionId = (int)$arg2;
    } else {
        $competitionId = (int)$arg1;
        $pdo = $arg2;
    }

    // 1. Fetch competition
    $stmtC = $pdo->prepare("SELECT * FROM coding_competitions WHERE id = ?");
    $stmtC->execute([$competitionId]);
    $comp = $stmtC->fetch(PDO::FETCH_ASSOC);

    if (!$comp) return [];

    // 2. Fetch problems in competition
    $stmtP = $pdo->prepare("
        SELECT cp.problem_id, cp.points, p.title, p.slug
        FROM coding_competition_problems cp
        JOIN coding_problems p ON cp.problem_id = p.id
        WHERE cp.competition_id = ?
        ORDER BY cp.order_index ASC, cp.id ASC
    ");
    $stmtP->execute([$competitionId]);
    $compProblems = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    if (empty($compProblems)) return [];

    // 3. Fetch all submissions in this competition
    $stmtSubs = $pdo->prepare("
        SELECT 
            cs.*,
            s.full_name,
            s.username,
            s.admin_id as student_admin_id,
            c.name as class_name,
            adm.institute_name
        FROM coding_submissions cs
        JOIN students s ON cs.student_id = s.id
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN admins adm ON s.admin_id = adm.id
        WHERE cs.competition_id = ?
        ORDER BY cs.submitted_at ASC
    ");
    $stmtSubs->execute([$competitionId]);
    $submissions = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);

    $leaderboard = [];

    foreach ($submissions as $sub) {
        $stId = (int)$sub['student_id'];
        $probId = (int)$sub['problem_id'];

        if (!isset($leaderboard[$stId])) {
            $leaderboard[$stId] = [
                'student_id'     => $stId,
                'full_name'      => $sub['full_name'],
                'username'       => $sub['username'],
                'class_name'     => $sub['class_name'] ?? 'N/A',
                'institute_name' => $sub['institute_name'] ?? 'Main Campus',
                'total_score'    => 0.0,
                'solved_count'   => 0,
                'penalty_time'   => 0, // In minutes from competition start
                'last_submission'=> $sub['submitted_at'],
                'problems'       => []
            ];
            foreach ($compProblems as $cp) {
                $leaderboard[$stId]['problems'][$cp['problem_id']] = [
                    'score'      => 0.0,
                    'status'     => 'NOT_ATTEMPTED',
                    'attempts'   => 0,
                    'time_taken' => 0
                ];
            }
        }

        $pData = &$leaderboard[$stId]['problems'][$probId];
        $pData['attempts']++;

        $subScore = (float)$sub['score'];
        if ($subScore > $pData['score']) {
            $pData['score'] = $subScore;
            $pData['status'] = $sub['status'];
            $compStart = strtotime($comp['start_time']);
            $subTime = strtotime($sub['submitted_at']);
            $pData['time_taken'] = max(0, round(($subTime - $compStart) / 60));
        }
    }

    // Tally up total scores, solved counts, and penalty times
    foreach ($leaderboard as $stId => &$entry) {
        $totScore = 0.0;
        $solved = 0;
        $totPenalty = 0;

        foreach ($entry['problems'] as $probId => &$pData) {
            $totScore += (float)$pData['score'];
            $pData['solved'] = ($pData['status'] === 'AC');
            $pData['solved_time_minutes'] = $pData['time_taken'];
            if ($pData['status'] === 'AC') {
                $solved++;
                $totPenalty += (int)$pData['time_taken'] + (($pData['attempts'] - 1) * 20); // 20m penalty per failed attempt
            }
        }
        unset($pData);

        $entry['total_score'] = round($totScore, 2);
        $entry['solved_count'] = $solved;
        $entry['penalty_time'] = $totPenalty;
        $entry['total_penalty_minutes'] = $totPenalty;
        $entry['problem_stats'] = $entry['problems'];
    }
    unset($entry);

    // Sort leaderboard: Score DESC, Solved Count DESC, Penalty Time ASC
    usort($leaderboard, function($a, $b) {
        if ($b['total_score'] != $a['total_score']) {
            return ($b['total_score'] > $a['total_score']) ? 1 : -1;
        }
        if ($b['solved_count'] != $a['solved_count']) {
            return $b['solved_count'] - $a['solved_count'];
        }
        return $a['penalty_time'] - $b['penalty_time'];
    });

    // Assign ranking numbers
    $rank = 1;
    foreach ($leaderboard as $idx => &$item) {
        $item['rank'] = $rank++;
    }
    unset($item);

    $standingsList = array_values($leaderboard);

    return [
        'competition' => $comp,
        'problems'    => $compProblems,
        'standings'   => $standingsList,
        'leaderboard' => $standingsList
    ];
}

/**
 * Helper to delete a folder and all its contents recursively.
 */
function clean_directory_recursively(string $dir): void {
    if (!is_dir($dir)) return;
    $items = @scandir($dir);
    if (!$items) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            clean_directory_recursively($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

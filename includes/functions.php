<?php
// includes/functions.php
require_once __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', 0); // Disable direct error output in production
error_reporting(E_ALL);

function clean_html($html) {
    if (!$html) return '';
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null);
    $config->set('Attr.EnableID', true);
    $config->set('Attr.AllowedClasses', null); // Allow classes for Prism.js code syntax highlighting, etc.
    $config->set('URI.AllowedSchemes', [
        'http' => true,
        'https' => true,
        'mailto' => true,
        'data' => true
    ]);
    $purifier = new HTMLPurifier($config);
    return $purifier->purify($html);
}

function render_formatted_content($text) {
    if ($text === null || $text === '') return '';
    // If it contains block-level HTML tags, purify directly
    if (preg_match('/<(?:p|div|br|ul|ol|li|pre|blockquote|table|h[1-6])\b/i', $text)) {
        return clean_html($text);
    }
    // Inline HTML or plain text: purify and preserve newlines
    return nl2br(clean_html($text));
}

// Generates a random CSRF token if one is not set, and returns it
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Verifies a submitted CSRF token against the session
function verify_csrf_token($token) {
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
               || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
               || (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '_ajax.php') !== false);
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Security token (CSRF) validation failed or session expired. Please refresh the page and try again.']);
            exit;
        }
        die("CSRF token validation failed. Please return to the previous page and try again.");
    }
}

// Generates a random 8-character password
function generate_random_password($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

// Shorthand for HTML escaping
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// Auto-complete logic for exams that have surpassed their allotted time (throttled to avoid DB lockups)
function update_exam_statuses($pdo, $force = false) {
    if (!$force) {
        $throttleFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eduerp_exam_status_throttle.tmp';
        $now = time();
        if (file_exists($throttleFile)) {
            $mtime = @filemtime($throttleFile);
            if ($mtime && ($now - $mtime < 30)) {
                return; // Throttled: checked in last 30s
            }
        }
        @touch($throttleFile);
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE exams 
            SET status = 'completed' 
            WHERE status = 'published' 
            AND TIMESTAMPADD(MINUTE, duration_minutes, TIMESTAMP(scheduled_date, scheduled_time)) <= NOW()
        ");
        $stmt->execute();
    } catch (\PDOException $e) {
        // Silently fail if DB error, keeping production clean
        error_log("Failed to update exam statuses: " . $e->getMessage());
    }
}

// Canonical list of all modular admin features available in the platform
if (!defined('ALL_ADMIN_FEATURES')) {
    define('ALL_ADMIN_FEATURES', [
        'questions'     => ['label' => 'Question Bank', 'icon' => 'fas fa-database', 'desc' => 'Manage questions, choices, answers & explanations'],
        'upload_csv'    => ['label' => 'Bulk CSV Upload', 'icon' => 'fas fa-upload', 'desc' => 'Import large batches of questions with LaTeX math support'],
        'ai_generation' => ['label' => 'OpenRouter AI Generation', 'icon' => 'fas fa-brain', 'desc' => 'Generate questions automatically via OpenRouter AI models'],
        'exams'         => ['label' => 'Exam Management', 'icon' => 'fas fa-list-alt', 'desc' => 'Create, schedule, configure & publish student exams'],
        'students'      => ['label' => 'Student Management', 'icon' => 'fas fa-users', 'desc' => 'Register students, view profiles & reset credentials'],
        'enrolments'    => ['label' => 'Exam Enrolments', 'icon' => 'fas fa-user-plus', 'desc' => 'Enrol students into scheduled examinations'],
        'fees'          => ['label' => 'Fees & Payments', 'icon' => 'fas fa-hand-holding-usd', 'desc' => 'Monthly fee structures, collection receipts & ledgers'],
        'messages'      => ['label' => 'Direct Messaging', 'icon' => 'fas fa-envelope', 'desc' => 'Send and receive communications with enrolled students'],
        'reports'       => ['label' => 'Reports & Analytics', 'icon' => 'fas fa-chart-bar', 'desc' => 'Analyze student scores, attendance & export report cards'],
        'subjects'      => ['label' => 'Subjects & Topics', 'icon' => 'fas fa-book', 'desc' => 'Manage academic boards, classes, subjects & curriculum topics'],
        'batches'       => ['label' => 'Batches Management', 'icon' => 'fas fa-layer-group', 'desc' => 'Organize enrolled students into teaching batches and groups'],
        'sub_admins'    => ['label' => 'Staff & Sub-Admins', 'icon' => 'fas fa-user-shield', 'desc' => 'Manage sub-admin accounts, teachers, accountants and permissions'],
        'payroll'       => ['label' => 'Payroll & Salaries', 'icon' => 'fas fa-money-check-alt', 'desc' => 'Process salary disbursements and view payroll history'],
        'homework'      => ['label' => 'Homework & Assignments', 'icon' => 'fas fa-book-reader', 'desc' => 'Assign homework with text, images, LaTeX, code snippets & due dates'],
        'coding'        => ['label' => 'Online Coding & Competitions', 'icon' => 'fas fa-laptop-code', 'desc' => 'Online code editor, multi-language execution engine & coding competitions'],
        'image_storage' => ['label' => 'Image Storage & CDN', 'icon' => 'fas fa-images', 'desc' => 'Compressed image bank, WebP conversion & Cloudinary CDN'],
        'ai_settings'   => ['label' => 'AI Settings', 'icon' => 'fas fa-cogs', 'desc' => 'Configure OpenRouter API keys, model presets & temperature'],
    ]);
}

/**
 * Send standard HTTP security headers (Anti-Clickjacking, CSP, MIME nosniff)
 */
function send_security_headers() {
    if (!headers_sent()) {
        header("X-Frame-Options: DENY");
        header("Content-Security-Policy: frame-ancestors 'none';");
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
    }
}

/**
 * Configure and start a hardened PHP session with secure cookie flags
 */
function start_secure_session($session_name = 'ADMIN_SESSION') {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', 1);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_name($session_name);
        session_start();
    }
}

/**
 * Safely retrieve client IP address
 */
function get_client_ip() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $cfIp = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($cfIp, FILTER_VALIDATE_IP)) {
            return $cfIp;
        }
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '127.0.0.1';
    }
    return $ip;
}

/**
 * Check if a client IP address has exceeded failed attempt limits for an action
 * Returns true if locked out, false if permitted.
 */
function check_ip_rate_limit($ip, $action, $max_attempts, $lockout_seconds, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT attempts, last_attempt, locked_until FROM login_rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $action]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return false;
        }

        // Check if currently locked
        if (!empty($row['locked_until']) && strtotime($row['locked_until']) > time()) {
            return true;
        }

        // If lockout expired or last attempt was long ago, reset
        if (!empty($row['locked_until']) && strtotime($row['locked_until']) <= time()) {
            $pdo->prepare("UPDATE login_rate_limits SET attempts = 0, locked_until = NULL WHERE ip_address = ? AND action = ?")->execute([$ip, $action]);
            return false;
        }

        if (time() - strtotime($row['last_attempt']) > $lockout_seconds) {
            $pdo->prepare("UPDATE login_rate_limits SET attempts = 0, locked_until = NULL WHERE ip_address = ? AND action = ?")->execute([$ip, $action]);
            return false;
        }

        return ((int)$row['attempts'] >= $max_attempts);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Record a failed attempt for an IP address and lock if threshold is reached
 */
function record_ip_failed_attempt($ip, $action, $max_attempts, $lockout_seconds, $pdo) {
    try {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("SELECT attempts FROM login_rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $action]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            $stmtIns = $pdo->prepare("INSERT INTO login_rate_limits (ip_address, action, attempts, last_attempt) VALUES (?, ?, 1, ?)");
            $stmtIns->execute([$ip, $action, $now]);
        } else {
            $newAttempts = (int)$current + 1;
            $lockedUntil = null;
            if ($newAttempts >= $max_attempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + $lockout_seconds);
            }
            $stmtUp = $pdo->prepare("UPDATE login_rate_limits SET attempts = ?, last_attempt = ?, locked_until = ? WHERE ip_address = ? AND action = ?");
            $stmtUp->execute([$newAttempts, $now, $lockedUntil, $ip, $action]);
        }
    } catch (\Throwable $e) {}
}

/**
 * Clear/reset rate limit records for an IP on successful action
 */
function reset_ip_rate_limit($ip, $action, $pdo) {
    try {
        $stmt = $pdo->prepare("DELETE FROM login_rate_limits WHERE ip_address = ? AND action = ?");
        $stmt->execute([$ip, $action]);
    } catch (\Throwable $e) {}
}



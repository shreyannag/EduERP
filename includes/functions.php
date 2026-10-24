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
        'attendance'    => ['label' => 'Attendance Management', 'icon' => 'fas fa-clipboard-check', 'desc' => 'Online & offline class attendance tracking with parent absentee alerts'],
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




/**
 * Parse HTTP user agent into device type, operating system, and browser
 */
function parse_user_agent($ua = null) {
    if ($ua === null) {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    $deviceType = 'Desktop';
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
        $deviceType = 'Tablet';
    } elseif (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android|iemobile|mobile)/i', $ua)) {
        $deviceType = 'Mobile';
    }

    // Operating System
    $os = 'Unknown OS';
    $osRules = [
        'Windows 11'  => '/windows nt 10\.0.*(?:arm|x64)/i',
        'Windows 10'  => '/windows nt 10\.0/i',
        'Windows 8.1' => '/windows nt 6\.3/i',
        'Windows 8'   => '/windows nt 6\.2/i',
        'Windows 7'   => '/windows nt 6\.1/i',
        'macOS'       => '/macintosh|mac os x/i',
        'iOS'         => '/iphone|ipad|ipod/i',
        'Android'     => '/android/i',
        'Linux'       => '/linux/i',
        'ChromeOS'    => '/cros/i'
    ];
    foreach ($osRules as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            $os = $name;
            break;
        }
    }

    // Browser
    $browser = 'Unknown Browser';
    if (preg_match('/edg(?:e)?\/([0-9.]+)/i', $ua, $matches)) {
        $browser = 'Edge ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/opr\/([0-9.]+)/i', $ua, $matches)) {
        $browser = 'Opera ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/chrome\/([0-9.]+)/i', $ua, $matches)) {
        $browser = 'Chrome ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/firefox\/([0-9.]+)/i', $ua, $matches)) {
        $browser = 'Firefox ' . explode('.', $matches[1])[0];
    } elseif (preg_match('/safari\/([0-9.]+)/i', $ua, $matches) && !preg_match('/chrome/i', $ua)) {
        $browser = 'Safari';
    }

    return [
        'device_type' => $deviceType,
        'os'          => $os,
        'browser'     => $browser,
        'raw_ua'      => substr($ua, 0, 500)
    ];
}

/**
 * Record a new login session and revoke all previous sessions for this user (Single-session enforcement)
 */
function record_user_login($userType, $userId, $sessionToken, $pdo) {
    try {
        $ip = get_client_ip();
        $parsed = parse_user_agent();

        // 1. Invalidate all previous active sessions for this user
        $stmtRevoke = $pdo->prepare("
            UPDATE user_sessions 
            SET is_active = 0, revoked_at = NOW(), revoked_reason = 'new_login_superseded' 
            WHERE user_type = ? AND user_id = ? AND is_active = 1
        ");
        $stmtRevoke->execute([$userType, $userId]);

        // 2. Insert new session
        $stmtIns = $pdo->prepare("
            INSERT INTO user_sessions 
            (user_type, user_id, session_token, ip_address, user_agent, device_type, browser, os, login_time, last_activity, current_page, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 'Dashboard', 1)
        ");
        $stmtIns->execute([
            $userType,
            $userId,
            $sessionToken,
            $ip,
            $parsed['raw_ua'],
            $parsed['device_type'],
            $parsed['browser'],
            $parsed['os']
        ]);

        // 3. Update last_seen and last_ip on user table
        $table = ($userType === 'admin') ? 'admins' : 'students';
        $stmtUser = $pdo->prepare("UPDATE {$table} SET session_token = ?, last_seen = NOW(), last_ip = ? WHERE id = ?");
        $stmtUser->execute([$sessionToken, $ip, $userId]);

        return true;
    } catch (\Throwable $e) {
        error_log("record_user_login error: " . $e->getMessage());
        return false;
    }
}

/**
 * Update activity timestamp and current page on active session
 */
function touch_user_session($userType, $userId, $sessionToken, $page, $pdo) {
    try {
        $cleanPage = substr(trim($page), 0, 200);
        $stmt = $pdo->prepare("
            UPDATE user_sessions 
            SET last_activity = NOW(), current_page = ? 
            WHERE user_type = ? AND user_id = ? AND session_token = ? AND is_active = 1
        ");
        $stmt->execute([$cleanPage, $userType, $userId, $sessionToken]);

        $table = ($userType === 'admin') ? 'admins' : 'students';
        $pdo->prepare("UPDATE {$table} SET last_seen = NOW() WHERE id = ?")->execute([$userId]);
    } catch (\Throwable $e) {}
}

/**
 * Check if the session token is active and valid (Single-session enforcement check)
 */
function is_user_session_active($userType, $userId, $sessionToken, $pdo) {
    try {
        $table = ($userType === 'admin') ? 'admins' : 'students';
        $stmt = $pdo->prepare("SELECT session_token FROM {$table} WHERE id = ?");
        $stmt->execute([$userId]);
        $dbToken = $stmt->fetchColumn();

        if (!$dbToken || empty($sessionToken) || !hash_equals($dbToken, $sessionToken)) {
            return false;
        }

        $stmtSess = $pdo->prepare("SELECT is_active FROM user_sessions WHERE user_type = ? AND user_id = ? AND session_token = ?");
        $stmtSess->execute([$userType, $userId, $sessionToken]);
        $sess = $stmtSess->fetch();
        if ($sess && (int)$sess['is_active'] === 0) {
            return false;
        }

        return true;
    } catch (\Throwable $e) {
        return true;
    }
}

/**
 * Get device sessions history for a user
 */
function get_user_device_sessions($userType, $userId, $pdo, $limit = 25) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM user_sessions 
            WHERE user_type = ? AND user_id = ? 
            ORDER BY id DESC 
            LIMIT ?
        ");
        $stmt->bindValue(1, $userType);
        $stmt->bindValue(2, $userId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Revoke all other active sessions for this user
 */
function revoke_all_other_sessions($userType, $userId, $currentSessionToken, $pdo) {
    try {
        $stmt = $pdo->prepare("
            UPDATE user_sessions 
            SET is_active = 0, revoked_at = NOW(), revoked_reason = 'user_revoked_all_other' 
            WHERE user_type = ? AND user_id = ? AND session_token != ? AND is_active = 1
        ");
        return $stmt->execute([$userType, $userId, $currentSessionToken]);
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Get students currently active online (activity within last 5 minutes)
 */
function get_active_online_students($adminId = null, $pdo = null, $limit = 50) {
    if (!$pdo) global $pdo;
    try {
        $where = "s.is_active = 1 AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE)";
        $params = [];

        if ($adminId !== null && !is_super_admin()) {
            $where .= " AND s.admin_id = ?";
            $params[] = (int)$adminId;
        }

        $sql = "
            SELECT s.id, s.full_name, s.username, s.class_id, s.board_id, s.admin_id, s.last_seen,
                   c.name as class_name, b.name as board_name,
                   us.device_type, us.browser, us.os, us.ip_address, us.last_activity, us.current_page,
                   TIMESTAMPDIFF(MINUTE, us.last_activity, NOW()) as minutes_ago
            FROM user_sessions us
            JOIN students s ON us.user_id = s.id AND us.user_type = 'student'
            LEFT JOIN classes c ON s.class_id = c.id
            LEFT JOIN boards b ON s.board_id = b.id
            WHERE $where
            ORDER BY us.last_activity DESC
            LIMIT ?
        ";
        $stmt = $pdo->prepare($sql);
        $idx = 1;
        foreach ($params as $p) {
            $stmt->bindValue($idx++, $p);
        }
        $stmt->bindValue($idx, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Get count of students online within last 5 minutes
 */
function get_online_students_count($adminId = null, $pdo = null) {
    if (!$pdo) global $pdo;
    try {
        $where = "s.is_active = 1 AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE)";
        $params = [];

        if ($adminId !== null && !is_super_admin()) {
            $where .= " AND s.admin_id = ?";
            $params[] = (int)$adminId;
        }

        $sql = "
            SELECT COUNT(DISTINCT s.id) 
            FROM user_sessions us
            JOIN students s ON us.user_id = s.id AND us.user_type = 'student'
            WHERE $where
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

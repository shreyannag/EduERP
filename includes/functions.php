<?php
// includes/functions.php
require_once __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', 0); // Disable direct error output in production
error_reporting(E_ALL);

function clean_html($html) {
    if ($html === null || $html === '') return '';
    $html = (string)$html;
    if ($html === '0') return '0';
    static $purifier = null;
    if ($purifier === null) {
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
    }
    return $purifier->purify($html);
}

function render_formatted_content($text) {
    if ($text === null || $text === '') return '';
    $text = (string)$text;
    if ($text === '0') return '0';

    // If text is a standalone single pseudo-tag like <A>, <B>, <none of these>, treat as plain text literal
    if (preg_match('/^<[A-Za-z0-9_\s-]+>$/', trim($text))) {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Check if content contains real HTML elements (must have recognized tags)
    $has_html = preg_match('/<\s*(?:p|div|br|ul|ol|li|pre|blockquote|table|tr|td|th|h[1-6]|span|b|strong|i|em|u|sub|sup|img|code)\b[^>]*>/i', $text);

    if ($has_html) {
        $purified = clean_html($text);
        if ($purified === '' && trim($text) !== '') {
            // Fallback: if HTMLPurifier completely stripped the text (e.g. invalid tags), preserve as safe escaped text!
            return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }
        return $purified;
    }

    $encoded = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Restore unescaped math operators inside LaTeX math delimiters ($...$, \(...\), \[...\])
    if (strpos($encoded, '$') !== false || strpos($encoded, '\\(') !== false || strpos($encoded, '\\[') !== false) {
        $encoded = preg_replace_callback('/(\$[^$\n]+\$|\\\\\([\\s\\S]*?\\\\\)|\x5C\\[[\\s\\S]*?\x5C\\])/', function($matches) {
            return str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $matches[0]);
        }, $encoded);
    }

    return nl2br($encoded);
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
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $fwd = trim($parts[0]);
        if (filter_var($fwd, FILTER_VALIDATE_IP)) {
            return $fwd;
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $real = trim($_SERVER['HTTP_X_REAL_IP']);
        if (filter_var($real, FILTER_VALIDATE_IP)) {
            return $real;
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
/**
 * Return relative time string (e.g. "5 mins ago", "2 hours ago", "yesterday")
 */
if (!function_exists('time_elapsed_string')) {
    function time_elapsed_string($datetime, $full = false): string {
        if (empty($datetime)) return 'Never';
        $now = new DateTime;
        try {
            $ago = new DateTime($datetime);
        } catch (\Exception $e) {
            return (string)$datetime;
        }
        $diff = $now->diff($ago);

        $diff->w = (int)floor($diff->d / 7);
        $diff->d -= $diff->w * 7;

        $string = [
            'y' => 'year',
            'm' => 'month',
            'w' => 'week',
            'd' => 'day',
            'h' => 'hour',
            'i' => 'min',
            's' => 'sec',
        ];
        foreach ($string as $k => &$v) {
            if ($diff->$k) {
                $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
            } else {
                unset($string[$k]);
            }
        }

        if (!$full) $string = array_slice($string, 0, 1);
        return $string ? implode(', ', $string) . ' ago' : 'just now';
    }
}

/**
 * Generate CSRF hidden input field
 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}


/**
 * Synchronize multiple classes mapped to a question
 */
function sync_question_classes(int $question_id, array $class_ids, $pdo = null): void {
    if (!$pdo) global $pdo;
    if ($question_id <= 0) return;
    
    $clean_ids = array_unique(array_filter(array_map('intval', $class_ids)));
    try {
        $pdo->prepare("DELETE FROM question_classes WHERE question_id = ?")->execute([$question_id]);
        if (!empty($clean_ids)) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO question_classes (question_id, class_id) VALUES (?, ?)");
            foreach ($clean_ids as $cid) {
                if ($cid > 0) $stmt->execute([$question_id, $cid]);
            }
        }
    } catch (\Throwable $e) {
        error_log("sync_question_classes error: " . $e->getMessage());
    }
}

/**
 * Get all class IDs assigned to a question
 */
function get_question_class_ids(int $question_id, $pdo = null): array {
    if (!$pdo) global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT class_id FROM question_classes WHERE question_id = ?");
        $stmt->execute([$question_id]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $ids);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Checks if the logged in user is a sub-admin and queues deletion requests for approval.
 * Returns array: ['queued' => bool, 'message' => string]
 */
function queue_or_execute_deletion(string $entity_type, int $entity_id, string $entity_title, ?string $reason = null, ?string $details = null, $pdo = null): array {
    if (!$pdo) global $pdo;
    
    $isSubAdmin = function_exists('is_sub_admin') ? is_sub_admin() : (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'sub_admin');
    if ($isSubAdmin) {
        $sub_admin_id = (int)($_SESSION['admin_id'] ?? 0);
        $parent_admin_id = function_exists('get_effective_admin_id') ? get_effective_admin_id() : (int)($_SESSION['parent_admin_id'] ?? $_SESSION['admin_id'] ?? 0);
        
        try {
            // Check if already queued
            $chk = $pdo->prepare("SELECT id FROM deletion_requests WHERE entity_type = ? AND entity_id = ? AND status = 'pending'");
            $chk->execute([$entity_type, $entity_id]);
            if ($chk->fetch()) {
                return [
                    'queued' => true,
                    'message' => "A deletion request for this {$entity_type} is already pending approval from Super Admin / Admin."
                ];
            }
            
            $stmt = $pdo->prepare("INSERT INTO deletion_requests (entity_type, entity_id, entity_title, details, requested_by, parent_admin_id, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$entity_type, $entity_id, $entity_title, $details, $sub_admin_id, $parent_admin_id, $reason]);
            
            // Flag entity as deletion_pending if supported
            $tableMap = [
                'question' => 'questions',
                'exam'     => 'exams',
                'homework' => 'homework',
                'batch'    => 'batches'
            ];
            if (isset($tableMap[$entity_type])) {
                $tbl = $tableMap[$entity_type];
                $pdo->prepare("UPDATE {$tbl} SET deletion_pending = 1 WHERE id = ?")->execute([$entity_id]);
            }
            
            return [
                'queued' => true,
                'message' => "Deletion request for '{$entity_title}' has been submitted. As a staff member, your request is queued for Super Admin / Admin review and will only be permanently removed upon approval."
            ];
        } catch (\Throwable $e) {
            error_log("queue_or_execute_deletion error: " . $e->getMessage());
        }
    }
    
    return [
        'queued' => false
    ];
}

/**
 * Get count of pending deletion requests for Super Admin / Tenant Admin
 */
function get_pending_deletion_count($pdo = null): int {
    if (!$pdo) global $pdo;
    $isSubAdmin = function_exists('is_sub_admin') ? is_sub_admin() : (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'sub_admin');
    if ($isSubAdmin) return 0;
    
    try {
        $isSuper = function_exists('is_super_admin') ? is_super_admin() : (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin');
        if ($isSuper) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM deletion_requests WHERE status = 'pending'");
            return (int)$stmt->fetchColumn();
        } else {
            $admin_id = function_exists('get_effective_admin_id') ? get_effective_admin_id() : (int)($_SESSION['admin_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM deletion_requests WHERE status = 'pending' AND parent_admin_id = ?");
            $stmt->execute([$admin_id]);
            return (int)$stmt->fetchColumn();
        }
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Get count of pending student question flags for Super Admin / Tenant Admin
 */
function get_pending_question_flags_count($pdo = null): int {
    if (!$pdo) global $pdo;
    try {
        $isSuper = function_exists('is_super_admin') ? is_super_admin() : (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin');
        if ($isSuper) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM question_flags WHERE status = 'pending'");
            return (int)$stmt->fetchColumn();
        } else {
            $eff_id = function_exists('get_effective_admin_id') ? get_effective_admin_id() : (int)($_SESSION['admin_id'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM question_flags qf
                JOIN questions q ON qf.question_id = q.id
                WHERE qf.status = 'pending' AND q.admin_id IN (SELECT id FROM admins WHERE id = ? OR parent_admin_id = ?)
            ");
            $stmt->execute([$eff_id, $eff_id]);
            return (int)$stmt->fetchColumn();
        }
    } catch (\Throwable $e) {
        return 0;
    }
}

/**
 * Test whether an IP matches a CIDR range
 */
function ip_matches_cidr(string $ip, string $cidr): bool {
    if (strpos($cidr, '/') === false) {
        return $ip === $cidr;
    }
    list($subnet, $bits) = explode('/', $cidr, 2);
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet);
    $mask = -1 << (32 - (int)$bits);
    $subnet_long &= $mask;
    return ($ip_long & $mask) == $subnet_long;
}

/**
 * Check if a client IP address is blocked from registering via enrollment links.
 * Returns array: ['blocked' => bool, 'reason' => string, 'rule_id' => int|null]
 */
function check_enrollment_ip_block(string $ip, ?int $admin_id = null, $pdo = null): array {
    if (!$pdo) global $pdo;
    $clean_ip = trim($ip);
    if (empty($clean_ip)) {
        return ['blocked' => false, 'reason' => '', 'rule_id' => null];
    }

    try {
        $sql = "SELECT id, ip_address, reason FROM blocked_enrollment_ips WHERE is_active = 1";
        $params = [];
        if ($admin_id !== null && $admin_id > 0) {
            $sql .= " AND (admin_id IS NULL OR admin_id = 0 OR admin_id = ?)";
            $params[] = $admin_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rules = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rules as $r) {
            $pattern = trim($r['ip_address']);
            // Exact match
            if (strcasecmp($pattern, $clean_ip) === 0) {
                return ['blocked' => true, 'reason' => $r['reason'], 'rule_id' => (int)$r['id']];
            }
            // Wildcard match (e.g. 192.168.1.*)
            if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace(['.', '*'], ['\.', '.*'], $pattern) . '$/i';
                if (preg_match($regex, $clean_ip)) {
                    return ['blocked' => true, 'reason' => $r['reason'], 'rule_id' => (int)$r['id']];
                }
            }
            // CIDR match (e.g. 103.45.67.0/24)
            if (strpos($pattern, '/') !== false) {
                if (ip_matches_cidr($clean_ip, $pattern)) {
                    return ['blocked' => true, 'reason' => $r['reason'], 'rule_id' => (int)$r['id']];
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("check_enrollment_ip_block error: " . $e->getMessage());
    }

    return ['blocked' => false, 'reason' => '', 'rule_id' => null];
}

/**
 * Convenient boolean check if IP is blocked
 */
function is_ip_blocked_for_enrollment(string $ip, ?int $admin_id = null, $pdo = null): bool {
    $res = check_enrollment_ip_block($ip, $admin_id, $pdo);
    return $res['blocked'];
}

/**
 * Increment the intercepted attempt counter when a blocked IP attempts to register
 */
function log_blocked_enrollment_attempt(int $rule_id, $pdo = null): void {
    if (!$pdo) global $pdo;
    if ($rule_id <= 0) return;
    try {
        $stmt = $pdo->prepare("UPDATE blocked_enrollment_ips SET blocked_attempts_count = blocked_attempts_count + 1, last_blocked_at = NOW() WHERE id = ?");
        $stmt->execute([$rule_id]);
    } catch (\Throwable $e) {
        error_log("log_blocked_enrollment_attempt error: " . $e->getMessage());
    }
}

/**
 * Get count of active blocked enrollment IPs
 */
function get_blocked_enrollment_ips_count(?int $admin_id = null, $pdo = null): int {
    if (!$pdo) global $pdo;
    try {
        $isSuper = function_exists('is_super_admin') ? is_super_admin() : (!empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin');
        if ($isSuper) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM blocked_enrollment_ips WHERE is_active = 1");
            return (int)$stmt->fetchColumn();
        } else {
            $target_admin = $admin_id !== null ? $admin_id : (function_exists('get_effective_admin_id') ? get_effective_admin_id() : (int)($_SESSION['admin_id'] ?? 0));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM blocked_enrollment_ips WHERE is_active = 1 AND (admin_id IS NULL OR admin_id = 0 OR admin_id = ?)");
            $stmt->execute([$target_admin]);
            return (int)$stmt->fetchColumn();
        }
    } catch (\Throwable $e) {
        return 0;
    }
}


/**
 * Retrieves the current Git commit information (short hash, full hash, branch, and timestamp).
 * Pure PHP implementation reading directly from .git with zero subprocess latency.
 */
function get_git_commit_info(): array {
    static $commitInfo = null;
    if ($commitInfo !== null) {
        return $commitInfo;
    }

    $projectDir = realpath(__DIR__ . '/..');
    $gitDir = $projectDir . '/.git';
    $shortHash = 'unknown';
    $fullHash  = '';
    $branch    = 'main';
    $commitDate = '';
    $commitMessage = '';

    if (is_dir($gitDir)) {
        $headFile = $gitDir . '/HEAD';
        if (file_exists($headFile)) {
            $headContent = trim((string)@file_get_contents($headFile));
            if (strpos($headContent, 'ref:') === 0) {
                $refPart = trim(substr($headContent, 4));
                $branch = basename($refPart);
                $refFile = $gitDir . '/' . $refPart;
                if (file_exists($refFile)) {
                    $fullHash = trim((string)@file_get_contents($refFile));
                } elseif (file_exists($gitDir . '/packed-refs')) {
                    $packed = (string)@file_get_contents($gitDir . '/packed-refs');
                    foreach (explode("\n", $packed) as $line) {
                        $line = trim($line);
                        if (strpos($line, '#') === 0 || empty($line)) continue;
                        $parts = explode(' ', $line, 2);
                        if (count($parts) === 2 && $parts[1] === $refPart) {
                            $fullHash = $parts[0];
                            break;
                        }
                    }
                }
            } else {
                $fullHash = $headContent;
            }
        }

        // Parse last log entry for timestamp & message
        $logFile = $gitDir . '/logs/HEAD';
        if (file_exists($logFile)) {
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($lines)) {
                $lastLine = end($lines);
                if (preg_match('/([0-9a-f]{40})\s+[^<]*<[^>]*>\s+(\d+)\s+([+-]\d{4})\t(.*)$/i', $lastLine, $matches)) {
                    $ts = (int)$matches[2];
                    if ($ts > 0) {
                        $commitDate = date('Y-m-d H:i', $ts);
                    }
                    $commitMessage = trim($matches[4]);
                }
            }
        }
    }

    if (empty($fullHash) && function_exists('shell_exec')) {
        $cliHash = @shell_exec('git rev-parse HEAD 2>/dev/null');
        if ($cliHash && strlen(trim($cliHash)) >= 7) {
            $fullHash = trim($cliHash);
        }
    }

    if (!empty($fullHash)) {
        $shortHash = substr($fullHash, 0, 7);
    }

    $commitInfo = [
        'short_hash' => $shortHash,
        'full_hash'  => $fullHash,
        'branch'     => $branch,
        'date'       => $commitDate,
        'message'    => $commitMessage,
        'badge'      => $shortHash !== 'unknown' ? "git:{$shortHash}" : "git:live",
        'github_url' => ($shortHash !== 'unknown' && !empty($fullHash)) ? "https://github.com/shreyannag/EduERP/commit/{$fullHash}" : "https://github.com/shreyannag/EduERP"
    ];

    return $commitInfo;
}

/**
 * Evaluates whether a student's fill-in-the-blank answer matches the acceptable answer(s).
 * Matching is case-insensitive and trims extraneous whitespace.
 * Multiple acceptable answers can be delimited by '|' (e.g. "Paris|Paris, France") or newlines.
 */
function is_fill_blank_correct($student_answer, $correct_answer): bool {
    if ($student_answer === null || trim((string)$student_answer) === '') {
        return false;
    }
    if ($correct_answer === null || trim((string)$correct_answer) === '') {
        return false;
    }

    // Normalize student answer: trim, lower case, collapse multiple internal spaces
    $clean_student = preg_replace('/\s+/', ' ', trim(mb_strtolower((string)$student_answer, 'UTF-8')));

    // Split acceptable answers by '|' or newlines
    $options = preg_split('/[|\r\n]+/', (string)$correct_answer);
    foreach ($options as $opt) {
        $clean_opt = preg_replace('/\s+/', ' ', trim(mb_strtolower((string)$opt, 'UTF-8')));
        if ($clean_opt !== '' && $clean_student === $clean_opt) {
            return true;
        }
    }
    return false;
}

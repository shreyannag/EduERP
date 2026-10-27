<?php
// student/login.php
session_name('STUDENT_SESSION');
session_start();

if (isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/db.php';
require_once '../includes/functions.php';

$error = '';
$info = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'timeout') {
        $info = "You have been logged out due to inactivity.";
    } elseif ($_GET['msg'] === 'concurrent') {
        $info = "You have been logged out because your account was accessed from another device.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!$username || !$password) {
        $error = "Please enter both student ID and password.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, is_active, login_attempts, locked_until FROM students WHERE username = ?");
        $stmt->execute([$username]);
        $student = $stmt->fetch();
        
        if ($student) {
            if (!$student['is_active']) {
                $error = "Account is inactive. Please contact the administrator.";
            } elseif ($student['locked_until'] && strtotime($student['locked_until']) > time()) {
                $error = "Account locked due to too many failed attempts. Try again later or contact support.";
            } else {
                if (password_verify($password, $student['password_hash'])) {
                    $ip = get_client_ip();
                    
                    // Race-condition guard: check if there is an active session from the same IP created within last 60 seconds
                    // (Prevents mobile double-tap logins from superseding and destroying the session)
                    $stmtRecent = $pdo->prepare("
                        SELECT session_token FROM user_sessions 
                        WHERE user_type = 'student' AND user_id = ? AND ip_address = ? AND is_active = 1 AND login_time >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
                        ORDER BY id DESC LIMIT 1
                    ");
                    $stmtRecent->execute([$student['id'], $ip]);
                    $recentToken = $stmtRecent->fetchColumn();

                    if (!empty($recentToken)) {
                        $session_token = $recentToken;
                        $pdo->prepare("UPDATE students SET login_attempts = 0, locked_until = NULL, session_token = ? WHERE id = ?")->execute([$session_token, $student['id']]);
                    } else {
                        $session_token = bin2hex(random_bytes(32));
                        $pdo->prepare("UPDATE students SET login_attempts = 0, locked_until = NULL, session_token = ? WHERE id = ?")->execute([$session_token, $student['id']]);
                        record_user_login('student', $student['id'], $session_token, $pdo);
                    }

                    session_regenerate_id(true);
                    $_SESSION['student_id'] = $student['id'];
                    $_SESSION['student_user'] = $student['username'] ?? $username;
                    $_SESSION['role'] = 'student';
                    $_SESSION['session_token'] = $session_token;
                    $_SESSION['last_activity'] = time();
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $attempts = $student['login_attempts'] + 1;
                    if ($attempts >= 5) {
                        $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                        $pdo->prepare("UPDATE students SET login_attempts = ?, locked_until = ? WHERE id = ?")->execute([$attempts, $locked_until, $student['id']]);
                        $error = "Account locked due to 5 failed attempts. Please wait 15 minutes.";
                    } else {
                        $pdo->prepare("UPDATE students SET login_attempts = ? WHERE id = ?")->execute([$attempts, $student['id']]);
                        $error = "Invalid credentials. Attempt $attempts of 5.";
                    }
                }
            }
        } else {
            $error = "Invalid credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Login - Online Exam</title>
    <!-- PWA Progressive Web App Manifest & Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="/assets/icons/favicon.png">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="EduERP">
    <script src="/assets/js/pwa.js" defer></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/custom.css') ? filemtime(__DIR__ . '/../assets/css/custom.css') : time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="student-login-bg d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg border-0 rounded-lg overflow-hidden">
                <div class="card-header bg-primary text-white text-center py-4 border-0" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important;">
                    <h3 class="mb-0 font-weight-bold text-white">Student Portal</h3>
                    <p class="mb-0 text-white-50">Online Examination System</p>
                </div>
                <div class="card-body p-4 p-md-5 bg-white">
                    <?php if ($error): ?>
                        <div class="alert alert-danger shadow-sm py-2"><?php echo h($error); ?></div>
                    <?php endif; ?>
                    <?php if ($info): ?>
                        <div class="alert alert-info shadow-sm py-2"><?php echo h($info); ?></div>
                    <?php endif; ?>
                    <form method="POST" class="needs-validation" novalidate id="studentLoginForm">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-dark small text-uppercase">Student ID (Username)</label>
                            <input type="text" name="username" class="form-control form-control-lg" required autofocus placeholder="Enter your Student ID">
                            <div class="invalid-feedback">Please enter your username.</div>
                        </div>
                        <div class="form-group mb-5">
                            <label class="font-weight-bold text-dark small text-uppercase">Password</label>
                            <input type="password" name="password" class="form-control form-control-lg" required placeholder="Enter your password">
                            <div class="invalid-feedback">Please enter your password.</div>
                        </div>
                        <button type="submit" id="loginSubmitBtn" class="btn btn-primary btn-lg btn-block shadow-sm font-weight-bold">
                            Login to Dashboard
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
  'use strict';
  window.addEventListener('load', function() {
    var form = document.getElementById('studentLoginForm');
    if (form) {
      form.addEventListener('submit', function(event) {
        if (form.checkValidity() === false) {
          event.preventDefault();
          event.stopPropagation();
        } else {
          var btn = document.getElementById('loginSubmitBtn');
          if (btn && !btn.disabled) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>Logging in...';
          }
        }
        form.classList.add('was-validated');
      }, false);
    }
  }, false);
})();
</script>
</body>
</html>

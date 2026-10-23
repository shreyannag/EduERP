<?php
// admin/login.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers();
start_secure_session('ADMIN_SESSION');

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Canonical clean URL: If accessed directly via browser URL as login.php, redirect to ./ (the clean /admin URL)
if (isset($_SERVER['SCRIPT_NAME']) && substr($_SERVER['SCRIPT_NAME'], -9) === 'login.php' && strpos($_SERVER['REQUEST_URI'], 'login.php') !== false && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ./' . $queryString, true, 301);
    exit;
}

$error = '';
$info = '';

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'timeout') {
        $info = "You have been logged out due to inactivity.";
    } elseif ($_GET['msg'] === 'concurrent') {
        $info = "You have been logged out because your account was accessed from another device.";
    } elseif ($_GET['msg'] === 'reset_success') {
        $info = "Your password has been successfully reset. Please log in with your new password.";
    } elseif ($_GET['msg'] === '2fa_lockout') {
        $error = "Too many failed two-factor authentication attempts. Please log in again.";
    } elseif ($_GET['msg'] === 'suspended') {
        $error = "Your administrator account has been deactivated. Please contact the Super Administrator.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $ip = get_client_ip();
    if (check_ip_rate_limit($ip, 'admin_login', 5, 900, $pdo)) {
        $error = "Too many failed login attempts from this network. Please wait 15 minutes before trying again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (!$username || !$password) {
            record_ip_failed_attempt($ip, 'admin_login', 5, 900, $pdo);
            $error = "Invalid username or password.";
        } else {
            $stmt = $pdo->prepare("SELECT id, password_hash, login_attempts, locked_until, two_factor_enabled, two_factor_secret, role, sub_role, full_name, phone, salary, parent_admin_id, institute_name, is_active, features, must_change_password FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();
            
            // Standard dummy bcrypt hash with cost 10 for timing-attack mitigation when username does not exist
            $dummy_hash = '$2y$10$e8T73f5ZqIov1Q4k2h.b1e8eZ84sF7lE08MvBwN79J2pC5x5Q8nDu';
            
            if ($admin) {
                // Perform password verification
                $password_matches = password_verify($password, $admin['password_hash']);
                
                if ($password_matches) {
                    if (isset($admin['is_active']) && (int)$admin['is_active'] === 0) {
                        record_ip_failed_attempt($ip, 'admin_login', 5, 900, $pdo);
                        $error = "Invalid username or password.";
                    } else {
                        // Reset IP rate limit on successful credentials
                        reset_ip_rate_limit($ip, 'admin_login', $pdo);

                        // Check if Two-Factor Authentication is enabled
                        if (!empty($admin['two_factor_enabled']) && !empty($admin['two_factor_secret'])) {
                            session_regenerate_id(true);
                            $_SESSION['2fa_pending_admin_id'] = $admin['id'];
                            $_SESSION['2fa_pending_time'] = time();
                            header('Location: 2fa_verify.php');
                            exit;
                        }

                        // Direct login if 2FA is not enabled
                        $session_token = bin2hex(random_bytes(32));
                        $pdo->prepare("UPDATE admins SET login_attempts = 0, locked_until = NULL, two_factor_attempts = 0, two_factor_locked_until = NULL, session_token = ? WHERE id = ?")
                            ->execute([$session_token, $admin['id']]);
                        
                        session_regenerate_id(true);
                        $_SESSION['admin_id'] = $admin['id'];
                        $_SESSION['role'] = 'admin';
                        $_SESSION['admin_role'] = !empty($admin['role']) ? $admin['role'] : 'admin';
                        $_SESSION['is_super_admin'] = ($_SESSION['admin_role'] === 'super_admin');
                        $_SESSION['is_sub_admin'] = ($_SESSION['admin_role'] === 'sub_admin');
                        $_SESSION['sub_role'] = !empty($admin['sub_role']) ? $admin['sub_role'] : 'custom';
                        $_SESSION['full_name'] = !empty($admin['full_name']) ? $admin['full_name'] : '';
                        $_SESSION['parent_admin_id'] = !empty($admin['parent_admin_id']) ? (int)$admin['parent_admin_id'] : (int)$admin['id'];
                        $_SESSION['institute_name'] = !empty($admin['institute_name']) ? $admin['institute_name'] : 'Main Campus';
                        $_SESSION['must_change_password'] = !empty($admin['must_change_password']);
                        
                        if ($_SESSION['is_super_admin']) {
                            $_SESSION['admin_features'] = array_keys(ALL_ADMIN_FEATURES);
                        } else {
                            $decodedFeatures = !empty($admin['features']) ? json_decode($admin['features'], true) : [];
                            $_SESSION['admin_features'] = is_array($decodedFeatures) ? $decodedFeatures : [];
                        }

                        $_SESSION['session_token'] = $session_token;
                        $_SESSION['last_activity'] = time();

                        if (!empty($admin['must_change_password'])) {
                            header('Location: settings.php?first_login=1');
                            exit;
                        }

                        header('Location: dashboard.php');
                        exit;
                    }
                } else {
                    record_ip_failed_attempt($ip, 'admin_login', 5, 900, $pdo);
                    $error = "Invalid username or password.";
                }
            } else {
                // User does not exist: run dummy bcrypt verify to match execution time and record failed IP attempt
                password_verify($password, $dummy_hash);
                record_ip_failed_attempt($ip, 'admin_login', 5, 900, $pdo);
                $error = "Invalid username or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - Online Exam</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo defined('BASE_URL') ? BASE_URL : '..'; ?>/assets/css/custom.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/custom.css') ? filemtime(__DIR__ . '/../assets/css/custom.css') : time(); ?>">
</head>
<body class="admin-login-bg d-flex align-items-center">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card shadow-lg border-0 rounded-lg overflow-hidden">
                <div class="card-header bg-dark text-white text-center py-4 border-0" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%) !important;">
                    <h3 class="font-weight-bold mb-0 text-white">Admin Portal</h3>
                    <p class="mb-0 text-white-50 small">System Management</p>
                </div>
                <div class="card-body p-4 bg-white">
                    <?php if ($error): ?>
                        <div class="alert alert-danger shadow-sm py-2 px-3"><?php echo h($error); ?></div>
                    <?php endif; ?>
                    <?php if ($info): ?>
                        <div class="alert alert-info shadow-sm py-2 px-3"><?php echo h($info); ?></div>
                    <?php endif; ?>
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Username</label>
                            <input type="text" name="username" class="form-control form-control-lg" required autofocus placeholder="Enter username">
                            <div class="invalid-feedback">Please enter your username.</div>
                        </div>
                        <div class="form-group mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="font-weight-bold text-dark small text-uppercase mb-0">Password</label>
                                <a href="forgot_password.php" class="small text-primary font-weight-bold">Forgot password?</a>
                            </div>
                            <input type="password" name="password" class="form-control form-control-lg" required placeholder="Enter password">
                            <div class="invalid-feedback">Please enter your password.</div>
                        </div>
                        <button type="submit" class="btn btn-dark btn-lg btn-block shadow-sm font-weight-bold">Authenticate</button>
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
    var forms = document.getElementsByClassName('needs-validation');
    Array.prototype.filter.call(forms, function(form) {
      form.addEventListener('submit', function(event) {
        if (form.checkValidity() === false) {
          event.preventDefault();
          event.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    });
  }, false);
})();
</script>
</body>
</html>

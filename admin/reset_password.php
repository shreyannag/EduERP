<?php
// admin/reset_password.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

send_security_headers();
start_secure_session('ADMIN_SESSION');

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';
$valid_request = false;
$reset_email = '';
$token_hash = '';

$ip = get_client_ip();
if (check_ip_rate_limit($ip, 'password_reset_submit', 5, 900, $pdo)) {
    $error = "Too many attempts from this network. Please wait 15 minutes before trying again.";
} else {
    if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
        record_ip_failed_attempt($ip, 'password_reset_submit', 5, 900, $pdo);
        $error = "This password reset link is invalid or malformed. Please request a new one.";
    } else {
        $token_hash = hash('sha256', $token);

        $stmt = $pdo->prepare("SELECT id, email, expires_at, used FROM password_resets WHERE token_hash = ?");
        $stmt->execute([$token_hash]);
        $reset = $stmt->fetch();

        if (!$reset) {
            record_ip_failed_attempt($ip, 'password_reset_submit', 5, 900, $pdo);
            $error = "This password reset link is invalid. It may have already been used.";
        } elseif ($reset['used'] == 1) {
            $error = "This password reset link has already been used. Please request a new one if needed.";
        } elseif (strtotime($reset['expires_at']) < time()) {
            $error = "This password reset link has expired (links are valid for 20 minutes). Please request a new one.";
        } else {
            $valid_request = true;
            $reset_email = $reset['email'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_request) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "The new password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "The new password and confirmation password do not match.";
    } else {
        // Hash new password securely
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $new_session_token = bin2hex(random_bytes(32));

        // Update administrator password and reset lockout/attempts
        $update_admin = $pdo->prepare("
            UPDATE admins 
            SET password_hash = ?, session_token = ?, login_attempts = 0, locked_until = NULL, two_factor_attempts = 0, two_factor_locked_until = NULL 
            WHERE email = ?
        ");
        $update_admin->execute([$new_hash, $new_session_token, $reset_email]);

        // Invalidate token so it cannot be reused
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE token_hash = ?")->execute([$token_hash]);

        // Clear IP rate limits on successful reset
        reset_ip_rate_limit($ip, 'password_reset_submit', $pdo);
        reset_ip_rate_limit($ip, 'admin_login', $pdo);

        $success = "Your password has been successfully restored! You can now log in with your new password.";
        $valid_request = false; // Hide form after success
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set New Password - Admin Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="admin-login-bg d-flex align-items-center py-5">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-lg border-0 rounded-lg">
                <div class="card-header bg-dark text-white text-center py-4 border-0">
                    <div class="mb-2">
                        <i class="fas fa-lock-open fa-2x text-primary"></i>
                    </div>
                    <h3 class="font-weight-bold mb-0">Set New Password</h3>
                    <p class="mb-0 text-white-50 small">Create a secure password for your admin account</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger shadow-sm py-3 px-3 mb-4">
                            <i class="fas fa-exclamation-triangle mr-2"></i> <?php echo h($error); ?>
                        </div>
                        <?php if (!$valid_request): ?>
                            <div class="text-center mt-3">
                                <a href="forgot_password.php" class="btn btn-outline-primary btn-sm font-weight-bold">
                                    <i class="fas fa-redo mr-1"></i> Request a New Link
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success shadow-sm py-3 px-3 mb-4">
                            <i class="fas fa-check-circle mr-2"></i> <?php echo h($success); ?>
                        </div>
                        <div class="text-center mt-4">
                            <a href="login.php" class="btn btn-dark btn-lg btn-block font-weight-bold shadow-sm">
                                <i class="fas fa-sign-in-alt mr-2"></i> Proceed to Login
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($valid_request): ?>
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">

                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-muted small text-uppercase">New Password</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-light border-right-0"><i class="fas fa-lock text-muted"></i></span>
                                </div>
                                <input type="password" name="new_password" id="new_password" class="form-control form-control-lg bg-light border-left-0" required placeholder="At least 8 characters" autocomplete="new-password">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="new_password" title="Show/Hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="form-text text-muted mt-2">Must be at least 8 characters long.</small>
                        </div>

                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-muted small text-uppercase">Confirm New Password</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-light border-right-0"><i class="fas fa-check-double text-muted"></i></span>
                                </div>
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control form-control-lg bg-light border-left-0" required placeholder="Re-enter new password" autocomplete="new-password">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="confirm_password" title="Show/Hide password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-dark btn-lg btn-block shadow-sm font-weight-bold py-2 mb-3">
                            <i class="fas fa-save mr-2"></i>Update Password
                        </button>
                    </form>
                    <?php endif; ?>

                    <div class="text-center mt-3 pt-3 border-top">
                        <a href="login.php" class="text-decoration-none font-weight-bold text-muted small">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".toggle-pwd-btn").forEach(function(button) {
        button.addEventListener("click", function() {
            var targetId = this.getAttribute("data-target");
            var input = document.getElementById(targetId);
            var icon = this.querySelector("i");
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                input.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        });
    });
});
</script>
</body>
</html>

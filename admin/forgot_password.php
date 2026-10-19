<?php
// admin/forgot_password.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

send_security_headers();
start_secure_session('ADMIN_SESSION');

if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $ip = get_client_ip();
    if (check_ip_rate_limit($ip, 'password_reset', 5, 900, $pdo)) {
        $error = "Too many password reset requests from this network. Please wait 15 minutes before trying again.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            record_ip_failed_attempt($ip, 'password_reset', 5, 900, $pdo);
            $error = "Please enter a valid email address.";
        } else {
            // Rate-limit check: only 1 reset request per email per 60 seconds
            $recent_stmt = $pdo->prepare("SELECT id FROM password_resets WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
            $recent_stmt->execute([$email]);
            if ($recent_stmt->fetch()) {
                $message = "A password reset link was recently generated. Please check your inbox or wait a moment before trying again.";
            } else {
                // Check if admin with this email exists
                $stmt = $pdo->prepare("SELECT id, username, email FROM admins WHERE email = ?");
                $stmt->execute([$email]);
                $admin = $stmt->fetch();

                if ($admin) {
                    // Generate secure random 64-character token
                    $plain_token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $plain_token);
                    $expires_at = date('Y-m-d H:i:s', strtotime('+20 minutes'));

                    // Invalidate any existing unused tokens for this email
                    $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ?")->execute([$email]);

                    // Insert new reset record
                    $ins = $pdo->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)");
                    $ins->execute([$email, $token_hash, $expires_at]);

                    // Build absolute reset link
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
                    $reset_link = $protocol . $host . BASE_URL . '/admin/reset_password.php?token=' . $plain_token;

                    // Send email via PHPMailer
                    $mailResult = send_password_reset_email($email, $reset_link);
                    if ($mailResult !== true) {
                        error_log("Failed to send password reset email to $email: " . $mailResult);
                    }
                } else {
                    // Constant-time execution defense to prevent email enumeration timing attacks
                    hash('sha256', random_bytes(32));
                }

                // Increment rate limit attempts for IP to prevent spamming
                record_ip_failed_attempt($ip, 'password_reset', 5, 900, $pdo);

                // Always display standard generic success message to prevent user enumeration
                $message = "If an account is associated with this email address, a password reset link has been sent. Please check your inbox (and spam folder). The link is valid for 20 minutes.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password - Admin Portal</title>
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
                        <i class="fas fa-key fa-2x text-primary"></i>
                    </div>
                    <h3 class="font-weight-bold mb-0">Reset Password</h3>
                    <p class="mb-0 text-white-50 small">Enter your email to receive a recovery link</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger shadow-sm py-2 px-3 mb-4"><?php echo h($error); ?></div>
                    <?php endif; ?>

                    <?php if ($message): ?>
                        <div class="alert alert-success shadow-sm py-3 px-3 mb-4">
                            <i class="fas fa-check-circle mr-2"></i> <?php echo h($message); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$message): ?>
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                        
                        <div class="form-group mb-4">
                            <label class="font-weight-bold text-muted small text-uppercase">Registered Email Address</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-light border-right-0"><i class="fas fa-envelope text-muted"></i></span>
                                </div>
                                <input type="email" name="email" class="form-control form-control-lg bg-light border-left-0" required autofocus placeholder="e.g. admin@example.com">
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>
                            <small class="form-text text-muted mt-2">A secure single-use recovery link will be generated and emailed to this address.</small>
                        </div>

                        <button type="submit" class="btn btn-dark btn-lg btn-block shadow-sm font-weight-bold py-2 mb-3">
                            <i class="fas fa-paper-plane mr-2"></i>Send Reset Link
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

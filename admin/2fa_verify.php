<?php
// admin/2fa_verify.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/totp.php';

send_security_headers();
start_secure_session('ADMIN_SESSION');

// Ensure there is a pending 2FA login within the 5-minute timeout window
if (empty($_SESSION['2fa_pending_admin_id']) || empty($_SESSION['2fa_pending_time']) || (time() - $_SESSION['2fa_pending_time'] > 300)) {
    unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_failed_attempts']);
    header('Location: login.php');
    exit;
}

$admin_id = (int)$_SESSION['2fa_pending_admin_id'];
$ip = get_client_ip();
$error = '';

// Check IP-level rate limit for 2FA attempts
if (check_ip_rate_limit($ip, 'admin_2fa', 5, 900, $pdo)) {
    unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_failed_attempts']);
    header('Location: login.php?msg=2fa_lockout');
    exit;
}

// Fetch current 2FA status and check persistent database lockout
$lockStmt = $pdo->prepare("SELECT two_factor_attempts, two_factor_locked_until FROM admins WHERE id = ?");
$lockStmt->execute([$admin_id]);
$adminLock = $lockStmt->fetch();

if ($adminLock && !empty($adminLock['two_factor_locked_until']) && strtotime($adminLock['two_factor_locked_until']) > time()) {
    unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_failed_attempts']);
    header('Location: login.php?msg=2fa_lockout');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $code_input = trim($_POST['totp_code'] ?? '');

    if (empty($code_input)) {
        $error = "Please enter your 6-digit authenticator code or backup code.";
    } else {
        // Fetch 2FA secret, backup codes and admin profile from database
        $stmt = $pdo->prepare("SELECT id, username, two_factor_secret, two_factor_backup_codes, two_factor_attempts, role, sub_role, full_name, phone, salary, parent_admin_id, institute_name, is_active, features, must_change_password FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $admin = $stmt->fetch();

        $authenticated = false;

        if ($admin && $admin['two_factor_secret']) {
            // Check standard 6-digit TOTP code
            if (strlen($code_input) === 6 && ctype_digit($code_input)) {
                if (TOTP::verifyCode($admin['two_factor_secret'], $code_input)) {
                    $authenticated = true;
                }
            } 
            // Check 8-character backup code (format XXXX-XXXX or XXXXXXXX)
            else {
                $clean_code = strtoupper(str_replace('-', '', $code_input));
                $stored_codes = json_decode($admin['two_factor_backup_codes'] ?? '[]', true);
                if (is_array($stored_codes)) {
                    foreach ($stored_codes as $idx => $hashed_code) {
                        if (password_verify($clean_code, $hashed_code)) {
                            $authenticated = true;
                            // Remove used single-use backup code
                            unset($stored_codes[$idx]);
                            $update_codes = $pdo->prepare("UPDATE admins SET two_factor_backup_codes = ? WHERE id = ?");
                            $update_codes->execute([json_encode(array_values($stored_codes)), $admin_id]);
                            break;
                        }
                    }
                }
            }
        }

        if ($authenticated) {
            if (isset($admin['is_active']) && (int)$admin['is_active'] === 0) {
                unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_failed_attempts']);
                header('Location: login.php?msg=suspended');
                exit;
            }

            // Clear 2FA attempts in database and IP rate limiter
            $pdo->prepare("UPDATE admins SET two_factor_attempts = 0, two_factor_locked_until = NULL WHERE id = ?")->execute([$admin_id]);
            reset_ip_rate_limit($ip, 'admin_2fa', $pdo);

            // Clear 2FA pending session variables
            unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_failed_attempts']);

            // Issue secure session token and authenticate
            $session_token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE admins SET login_attempts = 0, locked_until = NULL, session_token = ? WHERE id = ?")
                ->execute([$session_token, $admin_id]);
            record_user_login('admin', $admin_id, $session_token, $pdo);

            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin_id;
            $_SESSION['role'] = 'admin';
            $_SESSION['admin_role'] = !empty($admin['role']) ? $admin['role'] : 'admin';
            $_SESSION['is_super_admin'] = ($_SESSION['admin_role'] === 'super_admin');
            $_SESSION['is_sub_admin'] = ($_SESSION['admin_role'] === 'sub_admin');
            $_SESSION['sub_role'] = !empty($admin['sub_role']) ? $admin['sub_role'] : 'custom';
            $_SESSION['full_name'] = !empty($admin['full_name']) ? $admin['full_name'] : '';
            $_SESSION['parent_admin_id'] = !empty($admin['parent_admin_id']) ? (int)$admin['parent_admin_id'] : (int)$admin_id;
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
        } else {
            // Failed 2FA verification: track in both IP rate limiter and persistent database row
            record_ip_failed_attempt($ip, 'admin_2fa', 5, 900, $pdo);

            $attempts = ($admin ? (int)$admin['two_factor_attempts'] : 0) + 1;
            if ($attempts >= 5) {
                $lockedUntil = date('Y-m-d H:i:s', time() + 900);
                $pdo->prepare("UPDATE admins SET two_factor_attempts = ?, two_factor_locked_until = ? WHERE id = ?")
                    ->execute([$attempts, $lockedUntil, $admin_id]);
                unset($_SESSION['2fa_pending_admin_id'], $_SESSION['2fa_pending_time'], $_SESSION['2fa_failed_attempts']);
                header('Location: login.php?msg=2fa_lockout');
                exit;
            } else {
                $pdo->prepare("UPDATE admins SET two_factor_attempts = ? WHERE id = ?")
                    ->execute([$attempts, $admin_id]);
                $remaining = 5 - $attempts;
                $error = "Invalid verification code. Please check your Google Authenticator app and try again. ($remaining attempts remaining)";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Two-Factor Authentication - Admin Portal</title>
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
                        <i class="fas fa-shield-alt fa-3x text-primary"></i>
                    </div>
                    <h3 class="font-weight-bold mb-1">Two-Step Verification</h3>
                    <p class="mb-0 text-white-50 small">Google Authenticator Verification</p>
                </div>
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger shadow-sm py-2 px-3 mb-4">
                            <i class="fas fa-exclamation-circle mr-1"></i> <?php echo h($error); ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted text-center mb-4 small">
                        Please open the <strong>Google Authenticator</strong> app on your mobile device and enter the 6-digit code for this account.
                    </p>

                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">

                        <div class="form-group mb-4 text-center">
                            <label for="totp_code" class="font-weight-bold text-muted small text-uppercase">Authentication Code</label>
                            <input type="text" 
                                   name="totp_code" 
                                   id="totp_code" 
                                   class="form-control form-control-lg text-center font-weight-bold" 
                                   style="letter-spacing: 6px; font-size: 1.8rem; height: 60px;" 
                                   maxlength="9" 
                                   placeholder="123456" 
                                   pattern="[0-9A-Za-z-]{6,9}" 
                                   required 
                                   autofocus 
                                   autocomplete="one-time-code">
                        </div>

                        <button type="submit" class="btn btn-dark btn-lg btn-block shadow-sm font-weight-bold py-2 mb-3">
                            <i class="fas fa-unlock-alt mr-2"></i>Verify & Unlock Account
                        </button>
                    </form>

                    <div class="text-center mt-3 pt-3 border-top">
                        <small class="text-muted d-block mb-2">Have a recovery code? You can paste it directly above.</small>
                        <a href="login.php" class="text-decoration-none font-weight-bold text-muted small">
                            <i class="fas fa-arrow-left mr-1"></i> Cancel & Return to Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>

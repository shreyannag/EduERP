<?php
// admin/2fa_setup.php
require_once '../includes/admin_header.php';
require_once '../includes/totp.php';

$error = '';
$success = '';
$new_backup_codes_display = [];

// Fetch current 2FA status from database
$stmt = $pdo->prepare("SELECT id, username, email, two_factor_enabled, two_factor_secret, two_factor_backup_codes FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();

$is_2fa_enabled = !empty($admin['two_factor_enabled']) && !empty($admin['two_factor_secret']);

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // Action 1: Enable 2FA
    if ($action === 'enable_2fa') {
        $secret = $_SESSION['pending_2fa_secret'] ?? '';
        $code = trim($_POST['totp_code'] ?? '');

        if (!$secret) {
            $error = "Session expired. Please reload the page and try again.";
        } elseif (empty($code) || strlen($code) !== 6) {
            $error = "Please enter the 6-digit code shown in Google Authenticator.";
        } elseif (!TOTP::verifyCode($secret, $code)) {
            $error = "Invalid verification code. Please make sure your phone's time is synchronized and try again.";
        } else {
            // Generate 8 single-use recovery backup codes
            $raw_backup_codes = TOTP::generateBackupCodes(8);
            $hashed_backup_codes = [];
            foreach ($raw_backup_codes as $bc) {
                $clean = strtoupper(str_replace('-', '', $bc));
                $hashed_backup_codes[] = password_hash($clean, PASSWORD_DEFAULT);
            }

            // Save to database
            $update = $pdo->prepare("
                UPDATE admins 
                SET two_factor_secret = ?, two_factor_enabled = 1, two_factor_backup_codes = ? 
                WHERE id = ?
            ");
            $update->execute([$secret, json_encode($hashed_backup_codes), $_SESSION['admin_id']]);

            unset($_SESSION['pending_2fa_secret']);
            $is_2fa_enabled = true;
            $new_backup_codes_display = $raw_backup_codes;
            $success = "Two-Factor Authentication has been successfully enabled! Save your recovery backup codes below.";
        }
    }

    // Action 2: Disable 2FA
    elseif ($action === 'disable_2fa') {
        $password = $_POST['current_password'] ?? '';
        $totp_code = trim($_POST['disable_totp_code'] ?? '');

        $pass_stmt = $pdo->prepare("SELECT password_hash, two_factor_secret FROM admins WHERE id = ?");
        $pass_stmt->execute([$_SESSION['admin_id']]);
        $current_admin = $pass_stmt->fetch();

        if (!password_verify($password, $current_admin['password_hash'])) {
            $error = "Incorrect password. You must enter your current password to disable Two-Factor Authentication.";
        } elseif (empty($totp_code) || !TOTP::verifyCode($current_admin['two_factor_secret'], $totp_code)) {
            $error = "Invalid 6-digit authenticator code. Enter the current code from your Google Authenticator app.";
        } else {
            $disable_stmt = $pdo->prepare("
                UPDATE admins 
                SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_backup_codes = NULL 
                WHERE id = ?
            ");
            $disable_stmt->execute([$_SESSION['admin_id']]);
            $is_2fa_enabled = false;
            $success = "Two-Factor Authentication has been disabled for your account.";
        }
    }

    // Action 3: Regenerate Backup Codes
    elseif ($action === 'regenerate_backup_codes') {
        $password = $_POST['current_password'] ?? '';

        $pass_stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $pass_stmt->execute([$_SESSION['admin_id']]);
        $current_hash = $pass_stmt->fetchColumn();

        if (!password_verify($password, $current_hash)) {
            $error = "Incorrect password. You must enter your current password to regenerate backup codes.";
        } else {
            $raw_backup_codes = TOTP::generateBackupCodes(8);
            $hashed_backup_codes = [];
            foreach ($raw_backup_codes as $bc) {
                $clean = strtoupper(str_replace('-', '', $bc));
                $hashed_backup_codes[] = password_hash($clean, PASSWORD_DEFAULT);
            }

            $update_codes = $pdo->prepare("UPDATE admins SET two_factor_backup_codes = ? WHERE id = ?");
            $update_codes->execute([json_encode($hashed_backup_codes), $_SESSION['admin_id']]);

            $new_backup_codes_display = $raw_backup_codes;
            $success = "New recovery backup codes have been generated. Previous backup codes are now invalid.";
        }
    }
}

// Generate pending secret if 2FA is not enabled
$pending_secret = '';
$otpauth_url = '';
if (!$is_2fa_enabled) {
    if (empty($_SESSION['pending_2fa_secret'])) {
        $_SESSION['pending_2fa_secret'] = TOTP::generateSecret(16);
    }
    $pending_secret = $_SESSION['pending_2fa_secret'];
    $otpauth_url = TOTP::getOtpAuthUrl($pending_secret, $adminUser ?: 'admin', 'ExamAdmin');
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent p-0 mb-1">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/admin/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Two-Factor Authentication</li>
            </ol>
        </nav>
        <h1 class="h2 font-weight-bold text-dark mb-0">Two-Factor Authentication (2FA)</h1>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8 col-md-11">

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fas fa-exclamation-circle fa-lg mr-3 text-danger"></i>
                <div><?php echo h($error); ?></div>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fas fa-check-circle fa-lg mr-3 text-success"></i>
                <div><?php echo h($success); ?></div>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($new_backup_codes_display)): ?>
            <div class="card shadow-sm border-0 rounded-lg mb-4 bg-light border-warning" style="border-left: 5px solid #f59e0b !important;">
                <div class="card-body p-4">
                    <h5 class="font-weight-bold text-dark mb-2">
                        <i class="fas fa-key text-warning mr-2"></i>Save Your Recovery Backup Codes
                    </h5>
                    <p class="text-muted small mb-3">
                        If you ever lose access to your phone or Google Authenticator, you can use any of these one-time backup codes to log in. Each code can only be used once. Keep them in a safe, secure place.
                    </p>
                    <div class="row bg-white p-3 rounded border mx-0 mb-3">
                        <?php foreach ($new_backup_codes_display as $code): ?>
                            <div class="col-6 col-sm-3 py-2 text-center font-weight-bold font-monospace" style="font-family: monospace; font-size: 1.1rem; letter-spacing: 1px;">
                                <code><?php echo h($code); ?></code>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn-outline-dark btn-sm" onclick="window.print()">
                        <i class="fas fa-print mr-1"></i> Print / Save as PDF
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$is_2fa_enabled): ?>
            <!-- Setup 2FA Card -->
            <div class="card shadow-sm border-0 rounded-lg">
                <div class="card-header bg-white border-bottom py-3">
                    <div class="d-flex align-items-center">
                        <div class="bg-primary text-white rounded-circle p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <div>
                            <h5 class="font-weight-bold mb-0 text-dark">Enable Google Authenticator</h5>
                            <small class="text-muted">Protect your administrator portal with two-step verification.</small>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <div class="row align-items-center mb-4">
                        <div class="col-md-5 text-center mb-3 mb-md-0">
                            <div id="qrcode" class="p-3 bg-white border rounded d-inline-block shadow-sm"></div>
                            <small class="d-block text-muted mt-2">Scan with Google Authenticator</small>
                        </div>
                        <div class="col-md-7">
                            <h6 class="font-weight-bold text-dark">Step 1: Scan the QR Code</h6>
                            <p class="text-muted small mb-3">
                                Open the <strong>Google Authenticator</strong> (or Microsoft Authenticator) app on your mobile device and tap <strong>+</strong> to scan this QR code.
                            </p>

                            <h6 class="font-weight-bold text-dark">Step 2: Or Enter Key Manually</h6>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control font-monospace font-weight-bold bg-light" id="secretKeyText" value="<?php echo h($pending_secret); ?>" readonly>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="copySecretKey()" title="Copy to clipboard">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="enable_2fa">

                        <h6 class="font-weight-bold text-dark mb-2">Step 3: Verify Code & Enable</h6>
                        <p class="text-muted small mb-3">Enter the 6-digit code currently generated by your app to verify the setup.</p>

                        <div class="form-group mb-4" style="max-width: 320px;">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text bg-light border-right-0"><i class="fas fa-key text-muted"></i></span>
                                </div>
                                <input type="text" 
                                       name="totp_code" 
                                       class="form-control form-control-lg border-left-0 font-weight-bold text-center" 
                                       style="letter-spacing: 4px; font-size: 1.4rem;" 
                                       placeholder="123456" 
                                       maxlength="6" 
                                       pattern="\d{6}" 
                                       required>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between">
                            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="btn btn-outline-secondary px-4 py-2 font-weight-bold">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-primary px-4 py-2 font-weight-bold shadow-sm">
                                <i class="fas fa-shield-alt mr-2"></i>Verify & Activate 2FA
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Client-side QR Code Generation -->
            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                var otpUrl = <?php echo json_encode($otpauth_url); ?>;
                new QRCode(document.getElementById("qrcode"), {
                    text: otpUrl,
                    width: 170,
                    height: 170,
                    colorDark : "#1e293b",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.M
                });
            });

            function copySecretKey() {
                var copyText = document.getElementById("secretKeyText");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                navigator.clipboard.writeText(copyText.value);
                alert("Secret key copied to clipboard!");
            }
            </script>

        <?php else: ?>
            <!-- 2FA Active Card -->
            <div class="card shadow-sm border-0 rounded-lg mb-4">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <div class="bg-success text-white rounded-circle p-2 mr-3 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <h5 class="font-weight-bold mb-0 text-dark">Two-Factor Authentication</h5>
                            <span class="badge badge-success px-2 py-1 mt-1"><i class="fas fa-check-circle mr-1"></i> ACTIVE & PROTECTED</span>
                        </div>
                    </div>
                </div>

                <div class="card-body p-4">
                    <p class="text-muted">
                        Two-factor authentication is currently <strong>enabled</strong> for your administrator account. Every time you log in, you will be prompted to enter the 6-digit verification code from your Google Authenticator app.
                    </p>

                    <hr class="my-4">

                    <h6 class="font-weight-bold text-dark mb-3">Backup Recovery Codes</h6>
                    <p class="text-muted small">
                        If you ever misplace your phone or need a new set of single-use recovery backup codes, you can regenerate them below.
                    </p>

                    <button class="btn btn-outline-secondary font-weight-bold" type="button" data-toggle="collapse" data-target="#regenerateCodesBox">
                        <i class="fas fa-sync-alt mr-1"></i> Regenerate Backup Codes
                    </button>

                    <div class="collapse mt-3" id="regenerateCodesBox">
                        <div class="card card-body bg-light border-0">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                <input type="hidden" name="action" value="regenerate_backup_codes">
                                <div class="form-group mb-3" style="max-width: 350px;">
                                    <label class="small font-weight-bold">Confirm Account Password:</label>
                                    <input type="password" name="current_password" class="form-control" required placeholder="Enter your current password">
                                </div>
                                <button type="submit" class="btn btn-warning font-weight-bold">Generate New Backup Codes</button>
                            </form>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="font-weight-bold text-danger mb-2">Disable Two-Factor Authentication</h6>
                    <p class="text-muted small">
                        Disabling 2FA reduces account security. You will only need your username and password to log in.
                    </p>

                    <button class="btn btn-outline-danger font-weight-bold" type="button" data-toggle="collapse" data-target="#disable2faBox">
                        <i class="fas fa-times-circle mr-1"></i> Disable 2FA
                    </button>

                    <div class="collapse mt-3" id="disable2faBox">
                        <div class="card card-body bg-light border-danger">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                <input type="hidden" name="action" value="disable_2fa">

                                <div class="row">
                                    <div class="col-md-6 form-group mb-3">
                                        <label class="small font-weight-bold">Current Password:</label>
                                        <input type="password" name="current_password" class="form-control" required placeholder="Enter password">
                                    </div>
                                    <div class="col-md-6 form-group mb-3">
                                        <label class="small font-weight-bold">Current 6-Digit 2FA Code:</label>
                                        <input type="text" name="disable_totp_code" class="form-control text-center font-weight-bold" maxlength="6" pattern="\d{6}" required placeholder="123456">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-danger font-weight-bold">
                                    Confirm & Disable Two-Factor Authentication
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once '../includes/admin_footer.php'; ?>

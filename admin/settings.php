<?php
// admin/settings.php

// Handle early AJAX test requests before rendering HTML headers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_smtp_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    require_once '../includes/admin_auth.php';
    require_once '../config/db.php';
    require_once '../includes/functions.php';
    require_once '../includes/mailer.php';

    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh the page.']);
        exit;
    }

    $settings = [
        'host'       => trim($_POST['host'] ?? ''),
        'port'       => (int)($_POST['port'] ?? 587),
        'encryption' => trim($_POST['encryption'] ?? 'tls'),
        'username'   => trim($_POST['username'] ?? ''),
        'password'   => (string)($_POST['password'] ?? ''),
        'from_email' => trim($_POST['from_email'] ?? ''),
        'from_name'  => trim($_POST['from_name'] ?? '')
    ];

    // If password is blank, try loading existing password from DB
    if ($settings['password'] === '') {
        $curSmtp = get_admin_smtp_config((int)$_SESSION['admin_id'], $pdo);
        if (!empty($curSmtp['password'])) {
            $settings['password'] = $curSmtp['password'];
        }
    }

    $recipient = !empty($_POST['test_recipient']) ? trim($_POST['test_recipient']) : null;
    $res = test_admin_smtp_connection($settings, $recipient);
    echo json_encode($res);
    exit;
}

require_once '../includes/admin_header.php';
require_once '../includes/image_storage.php';
require_once '../includes/ai_generator.php';
require_once '../includes/mailer.php';

$error = '';
$success = '';

$admin_id = (int)$_SESSION['admin_id'];

// Fetch current administrator details
$stmt = $pdo->prepare("SELECT id, username, email, role, institute_name, is_active, features, must_change_password, two_factor_enabled, cloudinary_settings, openrouter_settings, smtp_settings, support_permission, support_permission_updated_at, created_at FROM admins WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit;
}

$is_super = ($admin['role'] === 'super_admin');
$must_change_pwd = !empty($admin['must_change_password']);
$default_tab = ($must_change_pwd || isset($_GET['first_login'])) ? 'password' : (isset($_GET['tab']) ? trim($_GET['tab']) : 'password');

$cloudConfig = get_image_storage_config($pdo, $admin_id);
$aiConfig = get_openrouter_config($pdo, $admin_id);
$smtpConfig = get_admin_smtp_config($admin_id, $pdo);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // ==========================================
    // ACTION 1: CHANGE PASSWORD
    // ==========================================
    if ($action === 'change_password') {
        $default_tab = 'password';
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all password fields.";
        } elseif (strlen($new_password) < 8) {
            $error = "The new password must be at least 8 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "The new password and confirmation password do not match.";
        } elseif ($current_password === $new_password) {
            $error = "Your new password must be different from your current temporary password.";
        } else {
            // Verify current password against database hash
            $hash_stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
            $hash_stmt->execute([$admin_id]);
            $current_hash = $hash_stmt->fetchColumn();

            if (!$current_hash || !password_verify($current_password, $current_hash)) {
                $error = "Your current password is incorrect. Please try again.";
            } else {
                // Securely hash new password
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $new_session_token = bin2hex(random_bytes(32));

                // Update database: new hash, new session token, and clear must_change_password
                $upd_stmt = $pdo->prepare("UPDATE admins SET password_hash = ?, session_token = ?, must_change_password = 0 WHERE id = ?");
                $upd_stmt->execute([$new_hash, $new_session_token, $admin_id]);

                // Update session state
                $_SESSION['session_token'] = $new_session_token;
                $_SESSION['must_change_password'] = false;
                $must_change_pwd = false;

                $success = "Your password has been changed successfully! Your administrator account is now secured.";
            }
        }
    }

    // ==========================================
    // ACTION 2: UPDATE PROFILE & BRANDING
    // ==========================================
    elseif ($action === 'update_profile') {
        $default_tab = 'profile';
        $new_institute = trim($_POST['institute_name'] ?? '');
        $new_email     = trim($_POST['email'] ?? '');
        $auth_password = $_POST['auth_password'] ?? '';

        if (empty($auth_password)) {
            $error = "Please enter your account password to confirm profile updates.";
        } else {
            // Verify password
            $hash_stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
            $hash_stmt->execute([$admin_id]);
            $current_hash = $hash_stmt->fetchColumn();

            if (!$current_hash || !password_verify($auth_password, $current_hash)) {
                $error = "Authentication failed: Incorrect password provided.";
            } else {
                // Email validation
                if (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    $error = "Please enter a valid email address.";
                } else {
                    // Check email uniqueness if modified
                    if (!empty($new_email) && $new_email !== ($admin['email'] ?? '')) {
                        $check_email = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
                        $check_email->execute([$new_email, $admin_id]);
                        if ($check_email->fetch()) {
                            $error = "This email address is already in use by another administrator.";
                        }
                    }

                    if (!$error) {
                        $final_institute = !empty($new_institute) ? $new_institute : ($admin['institute_name'] ?: 'Main Campus');
                        $final_email = !empty($new_email) ? $new_email : null;

                        $upd_prof = $pdo->prepare("UPDATE admins SET institute_name = ?, email = ? WHERE id = ?");
                        $upd_prof->execute([$final_institute, $final_email, $admin_id]);

                        $_SESSION['institute_name'] = $final_institute;
                        $admin['institute_name'] = $final_institute;
                        $admin['email'] = $final_email;

                        $success = "Account profile and institute branding updated successfully!";
                    }
                }
            }
        }
    }

    // ==========================================
    // ACTION 3: TOGGLE SUPER ADMIN SUPPORT ACCESS
    // ==========================================
    elseif ($action === 'toggle_support_permission') {
        $default_tab = 'ownership';
        $grant_support = !empty($_POST['grant_support']) ? 1 : 0;
        $auth_password = $_POST['auth_password'] ?? '';

        if (empty($auth_password)) {
            $error = "Please enter your account password to authorize support permission changes.";
        } else {
            // Verify password
            $hash_stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
            $hash_stmt->execute([$admin_id]);
            $current_hash = $hash_stmt->fetchColumn();

            if (!$current_hash || !password_verify($auth_password, $current_hash)) {
                $error = "Authentication failed: Incorrect password provided.";
            } else {
                $stmtUp = $pdo->prepare("UPDATE admins SET support_permission = ?, support_permission_updated_at = NOW() WHERE id = ?");
                $stmtUp->execute([$grant_support, $admin_id]);
                $admin['support_permission'] = $grant_support;
                $admin['support_permission_updated_at'] = date('Y-m-d H:i:s');

                if ($grant_support) {
                    $success = "Support permission granted! The Super Administrator is now authorized to assist in configuring your settings and resolving technical issues.";
                } else {
                    $success = "Support permission revoked. Your account settings and Cloudinary keys are now strictly private under your individual ownership.";
                }
            }
        }
    }

    // ==========================================
    // ACTION 4: UPDATE API KEYS & INTEGRATIONS
    // ==========================================
    elseif ($action === 'update_api_keys') {
        $default_tab = 'apikeys';
        
        // Cloudinary credentials
        $driver            = in_array($_POST['driver'] ?? '', ['local', 'cloudinary']) ? $_POST['driver'] : 'local';
        $cloudinaryName   = trim($_POST['cloudinary_name'] ?? '');
        $cloudinaryKey    = trim($_POST['cloudinary_key'] ?? '');
        $cloudinarySecret = trim($_POST['cloudinary_secret'] ?? '');
        $cloudinaryFolder = trim($_POST['cloudinary_folder'] ?? 'questions');
        $quality           = max(10, min(100, (int)($_POST['quality'] ?? 75)));
        $maxWidth          = max(0, min(4000, (int)($_POST['max_width'] ?? 1200)));

        // OpenRouter credentials
        $openrouterKey     = trim((string)($_POST['openrouter_key'] ?? ''), " \t\n\r\0\x0B\"'");
        $openrouterModel   = trim($_POST['openrouter_model'] ?? 'google/gemini-2.0-flash-001');
        $openrouterTemp    = max(0.0, min(1.0, (float)($_POST['openrouter_temperature'] ?? 0.7)));

        // Save Cloudinary settings
        save_image_storage_config($pdo, [
            'driver'            => $driver,
            'quality'           => $quality,
            'max_width'         => $maxWidth,
            'cloudinary_name'   => $cloudinaryName,
            'cloudinary_key'    => $cloudinaryKey,
            'cloudinary_secret' => $cloudinarySecret,
            'cloudinary_folder' => $cloudinaryFolder
        ], $admin_id);

        // Save OpenRouter settings
        save_openrouter_config($pdo, [
            'api_key'     => $openrouterKey,
            'model'       => $openrouterModel,
            'temperature' => $openrouterTemp
        ], $admin_id);

        $cloudConfig = get_image_storage_config($pdo, $admin_id);
        $aiConfig    = get_openrouter_config($pdo, $admin_id);

        $success = "API Keys and Cloud Integration settings updated successfully!";
    }

    // ==========================================
    // ACTION 5: UPDATE EMAIL & SMTP SETTINGS
    // ==========================================
    elseif ($action === 'update_smtp_settings') {
        $default_tab = 'smtp';
        $smtp_host       = trim($_POST['smtp_host'] ?? '');
        $smtp_port       = max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
        $smtp_encryption = in_array($_POST['smtp_encryption'] ?? '', ['tls', 'ssl', 'none']) ? $_POST['smtp_encryption'] : 'tls';
        $smtp_username   = trim($_POST['smtp_username'] ?? '');
        $smtp_password   = (string)($_POST['smtp_password'] ?? '');
        $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
        $smtp_from_name  = trim($_POST['smtp_from_name'] ?? '');
        $smtp_enabled    = (!empty($smtp_host) && !empty($smtp_username)) ? 1 : (!empty($_POST['smtp_enabled']) ? 1 : 0);

        $saveData = [
            'enabled'    => $smtp_enabled,
            'host'       => $smtp_host,
            'port'       => $smtp_port,
            'encryption' => $smtp_encryption,
            'username'   => $smtp_username,
            'password'   => $smtp_password,
            'from_email' => $smtp_from_email,
            'from_name'  => $smtp_from_name
        ];

        save_admin_smtp_config($admin_id, $saveData, $pdo);
        $smtpConfig = get_admin_smtp_config($admin_id, $pdo);

        $success = "Personal SMTP Server configuration saved successfully! Outbound student emails will now route directly through this account.";
    }
}

// Re-read current admin info if refreshed
$assigned_features = [];
if ($is_super) {
    $assigned_features = array_keys(ALL_ADMIN_FEATURES);
} else {
    $decoded = !empty($admin['features']) ? json_decode($admin['features'], true) : [];
    $assigned_features = is_array($decoded) ? $decoded : [];
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent p-0 mb-1">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/admin/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Account Settings</li>
            </ol>
        </nav>
        <h1 class="h2 font-weight-bold text-dark mb-1">
            <i class="fas fa-cog text-primary mr-2"></i>Account Settings & Security
        </h1>
        <p class="text-muted small mb-0">
            Manage your personal administrator credentials, institute branding, and review allocated functional permissions.
        </p>
    </div>
    <div class="mt-2 mt-md-0">
        <span class="badge <?= $is_super ? 'badge-warning text-dark' : 'badge-primary' ?> px-3 py-2 font-weight-bold shadow-sm" style="font-size: 0.85rem;">
            <i class="fas <?= $is_super ? 'fa-crown' : 'fa-university' ?> mr-1"></i>
            <?= $is_super ? 'Super Administrator' : 'Institute Administrator' ?>
        </span>
    </div>
</div>

<?php if ($must_change_pwd || isset($_GET['first_login'])): ?>
    <div class="alert alert-warning border-warning shadow-sm rounded-lg p-4 mb-4" role="alert" style="background-color: #fffbeb; border-left: 6px solid #f59e0b !important;">
        <div class="d-flex align-items-start">
            <div class="mr-3 text-warning" style="font-size: 1.75rem; line-height: 1;">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div>
                <h5 class="font-weight-bold text-dark mb-1">
                    First-Time Setup: Please Change Your Temporary Password
                </h5>
                <p class="text-muted mb-0 small">
                    Your account was provisioned by the Super Administrator with a temporary initial password. 
                    To protect your account and students' confidential data, please choose a private, secure password below.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-lg d-flex align-items-center mb-4" role="alert">
        <i class="fas fa-exclamation-circle fa-lg mr-3 text-danger"></i>
        <div class="font-weight-bold"><?php echo h($error); ?></div>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-lg d-flex align-items-center mb-4" role="alert">
        <i class="fas fa-check-circle fa-lg mr-3 text-success"></i>
        <div class="font-weight-bold"><?php echo h($success); ?></div>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Left Navigation Nav-Pills -->
    <div class="col-lg-3 col-md-4 mb-4">
        <div class="card shadow-sm border-0 rounded-lg overflow-hidden">
            <div class="card-body p-3 bg-light border-bottom text-center">
                <div class="rounded-circle <?= $is_super ? 'bg-warning text-dark' : 'bg-primary text-white' ?> mx-auto d-flex align-items-center justify-content-center shadow-sm font-weight-bold mb-2" style="width: 54px; height: 54px; font-size: 1.5rem;">
                    <?= strtoupper(substr($admin['username'] ?? 'A', 0, 1)) ?>
                </div>
                <h6 class="font-weight-bold text-dark mb-0"><?= h($admin['username']) ?></h6>
                <small class="text-muted text-truncate d-block"><?= h($admin['institute_name'] ?? 'Main Campus') ?></small>
            </div>
            <div class="list-group list-group-flush" id="settingsTabs" role="tablist">
                <a class="list-group-item list-group-item-action font-weight-bold py-3 <?= $default_tab === 'password' ? 'active' : '' ?>" id="tab-password-link" data-toggle="pill" href="#tab-password" role="tab" aria-controls="tab-password" aria-selected="<?= $default_tab === 'password' ? 'true' : 'false' ?>">
                    <i class="fas fa-key fa-fw mr-2 text-warning"></i> Change Password
                    <?php if ($must_change_pwd): ?>
                        <span class="badge badge-warning text-dark ml-2">Action Required</span>
                    <?php endif; ?>
                </a>
                <a class="list-group-item list-group-item-action font-weight-bold py-3 <?= $default_tab === 'profile' ? 'active' : '' ?>" id="tab-profile-link" data-toggle="pill" href="#tab-profile" role="tab" aria-controls="tab-profile" aria-selected="<?= $default_tab === 'profile' ? 'true' : 'false' ?>">
                    <i class="fas fa-university fa-fw mr-2 text-primary"></i> Profile & Branding
                </a>
                <a class="list-group-item list-group-item-action font-weight-bold py-3 <?= $default_tab === 'apikeys' ? 'active' : '' ?>" id="tab-apikeys-link" data-toggle="pill" href="#tab-apikeys" role="tab" aria-controls="tab-apikeys" aria-selected="<?= $default_tab === 'apikeys' ? 'true' : 'false' ?>">
                    <i class="fas fa-plug fa-fw mr-2 text-info"></i> API Keys &amp; Integrations
                    <?php if (!empty($cloudConfig['cloudinary_key']) && !empty($aiConfig['api_key'])): ?>
                        <span class="badge badge-success ml-auto">Active</span>
                    <?php elseif (!empty($cloudConfig['cloudinary_key']) || !empty($aiConfig['api_key'])): ?>
                        <span class="badge badge-info ml-auto">Partial</span>
                    <?php else: ?>
                        <span class="badge badge-light border ml-auto">Config</span>
                    <?php endif; ?>
                </a>
                <a class="list-group-item list-group-item-action font-weight-bold py-3 <?= $default_tab === 'smtp' ? 'active' : '' ?>" id="tab-smtp-link" data-toggle="pill" href="#tab-smtp" role="tab" aria-controls="tab-smtp" aria-selected="<?= $default_tab === 'smtp' ? 'true' : 'false' ?>">
                    <i class="fas fa-envelope-open-text fa-fw mr-2 text-danger"></i> Email &amp; SMTP Server
                    <?php if (!empty($smtpConfig['has_custom_smtp'])): ?>
                        <span class="badge badge-success ml-auto">Active</span>
                    <?php else: ?>
                        <span class="badge badge-light border ml-auto">Default</span>
                    <?php endif; ?>
                </a>
                <?php if (!$is_super): ?>
                <a class="list-group-item list-group-item-action font-weight-bold py-3 <?= $default_tab === 'ownership' ? 'active' : '' ?>" id="tab-ownership-link" data-toggle="pill" href="#tab-ownership" role="tab" aria-controls="tab-ownership" aria-selected="<?= $default_tab === 'ownership' ? 'true' : 'false' ?>">
                    <i class="fas fa-user-shield fa-fw mr-2 text-info"></i> Support Permission
                    <?php if (!empty($admin['support_permission'])): ?>
                        <span class="badge badge-success ml-auto">Allowed</span>
                    <?php else: ?>
                        <span class="badge badge-secondary ml-auto">Private</span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a class="list-group-item list-group-item-action font-weight-bold py-3" id="tab-features-link" data-toggle="pill" href="#tab-features" role="tab" aria-controls="tab-features" aria-selected="false">
                    <i class="fas fa-th-large fa-fw mr-2 text-info"></i> Assigned Modules
                    <span class="badge badge-light border ml-auto font-weight-bold"><?= count($assigned_features) ?></span>
                </a>
                <a class="list-group-item list-group-item-action font-weight-bold py-3" id="tab-security-link" data-toggle="pill" href="#tab-security" role="tab" aria-controls="tab-security" aria-selected="false">
                    <i class="fas fa-shield-alt fa-fw mr-2 text-success"></i> 2FA & Security
                </a>
            </div>
        </div>
    </div>

    <!-- Right Tab Content -->
    <div class="col-lg-9 col-md-8">
        <div class="tab-content" id="settingsTabContent">

            <!-- ============================================== -->
            <!-- TAB 1: CHANGE PASSWORD                         -->
            <!-- ============================================== -->
            <div class="tab-pane fade <?= $default_tab === 'password' ? 'show active' : '' ?>" id="tab-password" role="tabpanel" aria-labelledby="tab-password-link">
                <div class="card shadow-sm border-0 rounded-lg mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="font-weight-bold text-dark mb-0">
                                <i class="fas fa-key text-warning mr-2"></i>Change Account Password
                            </h5>
                            <small class="text-muted">Update your credentials to maintain tight administrative control.</small>
                        </div>
                    </div>

                    <div class="card-body p-4">
                        <form method="POST" action="" autocomplete="off" id="changePasswordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="change_password">

                            <div class="form-group mb-4">
                                <label for="current_password" class="font-weight-bold text-dark small text-uppercase">
                                    Current Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fas fa-lock text-muted"></i></span>
                                    </div>
                                    <input type="password" name="current_password" id="current_password" class="form-control form-control-lg border-left-0" required placeholder="Enter current temporary or existing password" autocomplete="current-password">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="current_password" title="Show/Hide password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <small class="form-text text-muted mt-1">If this account was newly generated by the Super Admin, enter the password they provided.</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <label for="new_password" class="font-weight-bold text-dark small text-uppercase">
                                        New Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-light border-right-0"><i class="fas fa-shield-alt text-muted"></i></span>
                                        </div>
                                        <input type="password" name="new_password" id="new_password" class="form-control form-control-lg border-left-0" required minlength="8" placeholder="Enter new password (min. 8 chars)" autocomplete="new-password">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="new_password" title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="progress mt-2" style="height: 5px;">
                                        <div id="passwordStrengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%;"></div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <small id="passwordStrengthLabel" class="font-weight-bold text-muted" style="font-size: 0.72rem;">Strength: Empty</small>
                                        <small class="text-muted" style="font-size: 0.72rem;">Min 8 characters</small>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-4">
                                    <label for="confirm_password" class="font-weight-bold text-dark small text-uppercase">
                                        Confirm New Password <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-light border-right-0"><i class="fas fa-check-double text-muted"></i></span>
                                        </div>
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control form-control-lg border-left-0" required minlength="8" placeholder="Re-enter new password" autocomplete="new-password">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="confirm_password" title="Show/Hide password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <small id="matchStatusText" class="form-text mt-2 font-weight-bold" style="font-size: 0.75rem;">&nbsp;</small>
                                </div>
                            </div>

                            <div class="bg-light p-3 rounded-lg mb-4 border">
                                <h6 class="font-weight-bold text-dark mb-2" style="font-size: 0.82rem;">
                                    <i class="fas fa-info-circle text-primary mr-1"></i> Password Best Practices:
                                </h6>
                                <ul class="mb-0 text-muted small pl-3">
                                    <li>Use at least 8 characters with a mix of letters, numbers, and symbols.</li>
                                    <li>Do not share your administrative credentials with students or staff.</li>
                                    <li>Changing your password updates your active authentication token across devices.</li>
                                </ul>
                            </div>

                            <div class="d-flex align-items-center justify-content-between">
                                <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4">
                                    <i class="fas fa-arrow-left mr-2"></i>Dashboard
                                </a>
                                <button type="submit" id="savePasswordBtn" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">
                                    <i class="fas fa-save mr-2"></i>Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ============================================== -->
            <!-- TAB 2: PROFILE & INSTITUTE BRANDING             -->
            <!-- ============================================== -->
            <div class="tab-pane fade <?= $default_tab === 'profile' ? 'show active' : '' ?>" id="tab-profile" role="tabpanel" aria-labelledby="tab-profile-link">
                <div class="card shadow-sm border-0 rounded-lg mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h5 class="font-weight-bold text-dark mb-0">
                            <i class="fas fa-university text-primary mr-2"></i>Profile & Institute Branding
                        </h5>
                        <small class="text-muted">Manage the branding name presented to your students across portals, tests, and vouchers.</small>
                    </div>

                    <div class="card-body p-4">
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="update_profile">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Username</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span></div>
                                        <input type="text" class="form-control bg-light" value="<?= h($admin['username']) ?>" readonly>
                                        <div class="input-group-append"><span class="input-group-text bg-light text-muted small"><i class="fas fa-lock"></i> Fixed</span></div>
                                    </div>
                                    <small class="text-muted">Unique login identifier managed by system administrators.</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Administrative Role</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas <?= $is_super ? 'fa-crown text-warning' : 'fa-user-tie text-primary' ?>"></i></span></div>
                                        <input type="text" class="form-control bg-light font-weight-bold" value="<?= $is_super ? 'Super Administrator' : 'Institute Administrator (Multi-Tenant)' ?>" readonly>
                                    </div>
                                    <small class="text-muted"><?= $is_super ? 'Unrestricted platform master authority' : 'Independent tenant scope' ?></small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="institute_name" class="font-weight-bold text-dark small text-uppercase">
                                        Institute / Campus Name <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-graduation-cap text-muted"></i></span></div>
                                        <input type="text" name="institute_name" id="institute_name" class="form-control" required value="<?= h($admin['institute_name'] ?? 'Main Campus') ?>">
                                    </div>
                                    <small class="text-muted">Appears prominently on your students' navbar, test headers, and fees receipts.</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="email" class="font-weight-bold text-dark small text-uppercase">
                                        Recovery & Notification Email
                                    </label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span></div>
                                        <input type="email" name="email" id="email" class="form-control" value="<?= h($admin['email'] ?? '') ?>" placeholder="admin@institute.org">
                                    </div>
                                    <small class="text-muted">Used for automated fee digests and password recovery links.</small>
                                </div>
                            </div>

                            <div class="form-group mb-4 pt-2 border-top">
                                <label for="auth_password" class="font-weight-bold text-dark small text-uppercase">
                                    Confirm Account Password <span class="text-danger">*</span>
                                </label>
                                <div class="input-group" style="max-width: 450px;">
                                    <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span></div>
                                    <input type="password" name="auth_password" id="auth_password" class="form-control" required placeholder="Enter current password to save changes">
                                </div>
                                <small class="text-muted">Required to confirm any modifications to your profile branding or email.</small>
                            </div>

                            <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">
                                <i class="fas fa-save mr-2"></i>Save Profile Changes
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ============================================== -->
            <!-- TAB: API KEYS & INTEGRATIONS                  -->
            <!-- ============================================== -->
            <div class="tab-pane fade <?= $default_tab === 'apikeys' ? 'show active' : '' ?>" id="tab-apikeys" role="tabpanel" aria-labelledby="tab-apikeys-link">
                <form method="POST" action="settings.php?tab=apikeys" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="update_api_keys">

                    <!-- Tenancy & Security Guarantee Banner -->
                    <div class="alert alert-primary border-0 shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center" style="background-color: #eef2ff; border-left: 5px solid #6366f1 !important;">
                        <i class="fas fa-lock fa-2x text-primary mr-3"></i>
                        <div>
                            <strong class="text-dark d-block">Independent &amp; Isolated API Credentials</strong>
                            <span class="small text-muted">
                                <?= $is_super 
                                    ? 'As Super Administrator, your Cloudinary and OpenRouter keys manage the platform default configurations. Individual tenant administrators maintain their own separate, private credentials.' 
                                    : 'Your institute Cloudinary and OpenRouter API keys are strictly isolated to your own account. Question image CDN storage and AI tokens are billed directly to your own accounts without sharing across the platform.' ?>
                            </span>
                        </div>
                    </div>

                    <!-- Card 1: Cloudinary CDN & Image Storage -->
                    <div class="card shadow-sm border-0 rounded-lg mb-4">
                        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="font-weight-bold text-dark mb-0">
                                    <i class="fas fa-cloud text-info mr-2"></i>Cloudinary CDN &amp; Image Storage
                                </h5>
                                <small class="text-muted">Configure cloud hosting and automatic WebP image compression for exam questions.</small>
                            </div>
                            <div>
                                <a href="image_storage.php" class="btn btn-sm btn-outline-info font-weight-bold rounded-pill px-3">
                                    <i class="fas fa-external-link-alt mr-1"></i>Dedicated Storage Console
                                </a>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Storage Driver</label>
                                    <select name="driver" class="form-control custom-select bg-light font-weight-bold">
                                        <option value="local" <?= ($cloudConfig['driver'] ?? 'local') === 'local' ? 'selected' : '' ?>>Local Compressed WebP (Server Disk)</option>
                                        <option value="cloudinary" <?= ($cloudConfig['driver'] ?? 'local') === 'cloudinary' ? 'selected' : '' ?>>Cloudinary CDN (Global Cloud Delivery)</option>
                                    </select>
                                    <small class="form-text text-muted">Select Cloudinary to deliver question images worldwide via CDN.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Cloud Name</label>
                                    <input type="text" name="cloudinary_name" class="form-control" placeholder="e.g. my-institute-cloud" value="<?= h($cloudConfig['cloudinary_name'] ?? '') ?>">
                                    <small class="form-text text-muted">Found in your Cloudinary Dashboard under Account Details.</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">API Key</label>
                                    <div class="input-group">
                                        <input type="text" name="cloudinary_key" class="form-control" placeholder="e.g. 123456789012345" value="<?= h($cloudConfig['cloudinary_key'] ?? '') ?>">
                                        <div class="input-group-append">
                                            <span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">API Secret</label>
                                    <div class="input-group">
                                        <input type="password" name="cloudinary_secret" id="settings_cloud_secret" class="form-control" placeholder="e.g. abcde12345..." value="<?= h($cloudConfig['cloudinary_secret'] ?? '') ?>">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="settings_cloud_secret">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Folder Prefix</label>
                                    <input type="text" name="cloudinary_folder" class="form-control" placeholder="questions" value="<?= h($cloudConfig['cloudinary_folder'] ?? 'questions') ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">WebP Compression Quality (%)</label>
                                    <input type="number" name="quality" class="form-control" min="10" max="100" value="<?= (int)($cloudConfig['quality'] ?? 75) ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Max Image Width (px)</label>
                                    <input type="number" name="max_width" class="form-control" min="0" max="4000" value="<?= (int)($cloudConfig['max_width'] ?? 1200) ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: OpenRouter AI Question Generation -->
                    <div class="card shadow-sm border-0 rounded-lg mb-4">
                        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="font-weight-bold text-dark mb-0">
                                    <i class="fas fa-brain text-primary mr-2"></i>OpenRouter AI Question Generator
                                </h5>
                                <small class="text-muted">Configure your personal AI credentials for instant academic MCQ drafting.</small>
                            </div>
                            <div>
                                <a href="ai_settings.php" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3">
                                    <i class="fas fa-external-link-alt mr-1"></i>Dedicated AI Console
                                </a>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="form-group mb-4">
                                <label class="font-weight-bold text-dark small text-uppercase">
                                    OpenRouter API Key
                                </label>
                                <div class="input-group">
                                    <input type="password" name="openrouter_key" id="settings_openrouter_key" class="form-control" placeholder="sk-or-v1-xxxxxxxxxxxxxxxx" value="<?= h($aiConfig['api_key'] ?? '') ?>">
                                    <div class="input-group-append">
                                        <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="settings_openrouter_key">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <small class="form-text text-muted">
                                    Generate an API key with $1-$5 credits at <a href="https://openrouter.ai/keys" target="_blank" class="font-weight-bold">openrouter.ai/keys</a>. Keys are strictly separated per institute.
                                </small>
                            </div>

                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Model Identifier</label>
                                    <input type="text" name="openrouter_model" id="settings_openrouter_model" class="form-control font-weight-bold" placeholder="e.g. google/gemini-2.0-flash-001" value="<?= h($aiConfig['model'] ?? 'google/gemini-2.0-flash-001') ?>">
                                    <div class="d-flex flex-wrap gap-1 mt-2">
                                        <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill mr-1 mb-1" onclick="$('#settings_openrouter_model').val('google/gemini-2.0-flash-001')">Gemini 2.0 Flash</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill mr-1 mb-1" onclick="$('#settings_openrouter_model').val('deepseek/deepseek-chat')">DeepSeek V3</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill mr-1 mb-1" onclick="$('#settings_openrouter_model').val('meta-llama/llama-3.3-70b-instruct')">Llama 3.3 70B</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill mr-1 mb-1" onclick="$('#settings_openrouter_model').val('openai/gpt-4o-mini')">GPT-4o Mini</button>
                                    </div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Creativity / Temperature (0.0 - 1.0)</label>
                                    <input type="number" name="openrouter_temperature" step="0.05" min="0.0" max="1.0" class="form-control" value="<?= (float)($aiConfig['temperature'] ?? 0.7) ?>">
                                    <small class="form-text text-muted">Recommended: 0.7</small>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-light border-top py-3 px-4 text-right">
                            <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow">
                                <i class="fas fa-save mr-2"></i>Save API Keys &amp; Integrations
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ============================================== -->
            <!-- TAB: EMAIL & PERSONAL SMTP SERVER             -->
            <!-- ============================================== -->
            <div class="tab-pane fade <?= $default_tab === 'smtp' ? 'show active' : '' ?>" id="tab-smtp" role="tabpanel" aria-labelledby="tab-smtp-link">
                <form method="POST" action="settings.php?tab=smtp" autocomplete="off" id="smtpSettingsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="update_smtp_settings">

                    <!-- Independence & Deliverability Banner -->
                    <div class="alert alert-danger border-0 shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center" style="background-color: #fef2f2; border-left: 5px solid #ef4444 !important;">
                        <i class="fas fa-paper-plane fa-2x text-danger mr-3"></i>
                        <div>
                            <strong class="text-dark d-block">Admin Personal SMTP Account Routing</strong>
                            <span class="small text-muted">
                                Configure your own SMTP email server (e.g. Gmail, Outlook, Yahoo, Zoho, or Custom). All outbound student emails (credentials, fee statements, payment reminders) will originate directly from your authenticated email account instead of the superadmin account.
                            </span>
                        </div>
                    </div>

                    <?php if (!empty($smtpConfig['has_custom_smtp'])): ?>
                        <div class="alert alert-success border-success bg-light shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center">
                            <i class="fas fa-check-circle fa-2x text-success mr-3"></i>
                            <div>
                                <strong class="text-success d-block">Personal SMTP Account is Active</strong>
                                <span class="small text-muted">
                                    Emails are dispatched via <strong><?= h($smtpConfig['host']) ?>:<?= (int)$smtpConfig['port'] ?></strong> using username <strong><?= h($smtpConfig['username']) ?></strong>.
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning border-warning bg-light shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center">
                            <i class="fas fa-info-circle fa-2x text-warning mr-3"></i>
                            <div>
                                <strong class="text-dark d-block">Using Platform Default SMTP Credentials</strong>
                                <span class="small text-muted">
                                    To ensure your students receive emails from your personal or institute domain (Gmail, Outlook, Yahoo, etc.), configure and enable your personal SMTP credentials below.
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="card shadow-sm border-0 rounded-lg mb-4">
                        <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="font-weight-bold text-dark mb-0">
                                    <i class="fas fa-server text-danger mr-2"></i>SMTP Server Configuration
                                </h5>
                                <small class="text-muted">Configure connection host, port, authentication, and sender identity.</small>
                            </div>
                        </div>

                        <div class="card-body p-4">
                            <!-- Quick Presets Toolbar -->
                            <div class="bg-light p-3 rounded-lg border mb-4">
                                <label class="font-weight-bold text-muted small text-uppercase d-block mb-2">
                                    <i class="fas fa-magic text-primary mr-1"></i> Quick Configuration Presets
                                </label>
                                <div class="d-flex flex-wrap" style="gap: 8px;">
                                    <button type="button" class="btn btn-sm btn-outline-danger font-weight-bold rounded-pill px-3" onclick="applySmtpPreset('gmail')">
                                        <i class="fab fa-google mr-1"></i> Gmail / Google Workspace
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3" onclick="applySmtpPreset('outlook')">
                                        <i class="fab fa-microsoft mr-1"></i> Outlook / Office 365
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary font-weight-bold rounded-pill px-3" style="color: #6b21a8; border-color: #c084fc;" onclick="applySmtpPreset('yahoo')">
                                        <i class="fab fa-yahoo mr-1"></i> Yahoo Mail
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill px-3" onclick="applySmtpPreset('zoho')">
                                        <i class="fas fa-envelope mr-1"></i> Zoho Mail
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-dark font-weight-bold rounded-pill px-3" onclick="applySmtpPreset('custom')">
                                        <i class="fas fa-sliders-h mr-1"></i> Custom SMTP
                                    </button>
                                </div>
                                <div id="presetHelperBox" class="mt-2 text-muted small d-none"></div>
                            </div>

                            <!-- Enable Switch -->
                            <div class="custom-control custom-switch mb-4 p-2 bg-light rounded border">
                                <input type="checkbox" class="custom-control-input" id="smtp_enabled" name="smtp_enabled" value="1" <?= (!empty($smtpConfig['enabled']) || !empty($smtpConfig['host']) || empty($smtpConfig['has_custom_smtp'])) ? 'checked' : '' ?>>
                                <label class="custom-control-label font-weight-bold text-dark ml-2" for="smtp_enabled">
                                    Enable Personal SMTP for Outbound Student Emails
                                </label>
                                <small class="form-text text-muted ml-2">When checked, all emails to your students will be routed through the server configured below.</small>
                            </div>

                            <!-- Host, Port, Encryption Row -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">SMTP Host <span class="text-danger">*</span></label>
                                    <input type="text" name="smtp_host" id="smtp_host" class="form-control font-weight-bold" placeholder="e.g. smtp.gmail.com" value="<?= h($smtpConfig['host'] ?? '') ?>" required>
                                    <small class="form-text text-muted">Mail server hostname provided by your email provider.</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">SMTP Port <span class="text-danger">*</span></label>
                                    <input type="number" name="smtp_port" id="smtp_port" class="form-control font-weight-bold" placeholder="587" value="<?= (int)($smtpConfig['port'] ?? 587) ?>" min="1" max="65535" required>
                                    <small class="form-text text-muted">Typically 587 (TLS) or 465 (SSL).</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Encryption <span class="text-danger">*</span></label>
                                    <select name="smtp_encryption" id="smtp_encryption" class="form-control custom-select font-weight-bold">
                                        <option value="tls" <?= ($smtpConfig['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS)</option>
                                        <option value="ssl" <?= ($smtpConfig['encryption'] ?? 'tls') === 'ssl' ? 'selected' : '' ?>>SSL (SMTPS)</option>
                                        <option value="none" <?= ($smtpConfig['encryption'] ?? 'tls') === 'none' ? 'selected' : '' ?>>None (Plain)</option>
                                    </select>
                                    <small class="form-text text-muted">TLS is recommended for port 587.</small>
                                </div>
                            </div>

                            <!-- Username & Password Row -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">SMTP Username / Email Address <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span>
                                        </div>
                                        <input type="text" name="smtp_username" id="smtp_username" class="form-control font-weight-bold" placeholder="your_email@gmail.com" value="<?= h($smtpConfig['username'] ?? '') ?>">
                                    </div>
                                    <small class="form-text text-muted">Your full login email address.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">SMTP Password / App Password <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span>
                                        </div>
                                        <input type="password" name="smtp_password" id="smtp_password" class="form-control" placeholder="<?= !empty($smtpConfig['password']) ? '•••••••••••• (Leave blank to keep existing)' : 'Enter account or App Password' ?>">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary toggle-pwd-btn border" type="button" data-target="smtp_password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted" id="smtpPasswordHelp">For Gmail/Yahoo with 2FA, use an App Password generated in your account security.</small>
                                </div>
                            </div>

                            <!-- Sender Identity Row -->
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">From Email Address</label>
                                    <input type="email" name="smtp_from_email" id="smtp_from_email" class="form-control font-weight-bold" placeholder="Same as username (e.g. your_email@gmail.com)" value="<?= h($smtpConfig['from_email'] ?? '') ?>">
                                    <small class="form-text text-muted">Address shown as sender to students. Must match or be authorized by your SMTP account.</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">From Display Name / Institute Name</label>
                                    <input type="text" name="smtp_from_name" id="smtp_from_name" class="form-control font-weight-bold" placeholder="<?= h($admin['institute_name'] ?: 'Your Institute Name') ?>" value="<?= h($smtpConfig['from_name'] ?? ($admin['institute_name'] ?: '')) ?>">
                                    <small class="form-text text-muted">Sender name displayed in the student's email inbox.</small>
                                </div>
                            </div>
                        </div>

                        <div class="card-footer bg-light border-top py-3 px-4 d-flex justify-content-between align-items-center flex-wrap" style="gap: 10px;">
                            <span class="small text-muted"><i class="fas fa-shield-alt text-success mr-1"></i>Passwords are stored securely in database and never exposed in HTML source.</span>
                            <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow">
                                <i class="fas fa-save mr-2"></i>Save SMTP Settings
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Dedicated Card: Live SMTP Tester & Verification -->
                <div class="card shadow-sm border-0 rounded-lg mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h6 class="font-weight-bold text-dark mb-0">
                            <i class="fas fa-vial text-info mr-2"></i>Live SMTP Connection &amp; Test Email Delivery
                        </h6>
                        <small class="text-muted">Test connection and authentication before saving to verify host, port, and credentials.</small>
                    </div>
                    <div class="card-body p-4">
                        <div class="row align-items-end">
                            <div class="col-md-7 mb-3 mb-md-0">
                                <label class="font-weight-bold text-dark small text-uppercase">Send Test Verification Email To:</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-paper-plane text-muted"></i></span></div>
                                    <input type="email" id="test_smtp_recipient" class="form-control" placeholder="recipient@example.com" value="<?= h($admin['email'] ?: ($smtpConfig['from_email'] ?: $smtpConfig['username'])) ?>">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <button type="button" id="btnTestSmtp" class="btn btn-outline-info btn-block font-weight-bold rounded-pill shadow-sm">
                                    <i class="fas fa-paper-plane mr-2" id="testSmtpIcon"></i><span id="testSmtpText">Test Connection &amp; Send Test Email</span>
                                </button>
                            </div>
                        </div>

                        <div id="smtpTestResult" class="mt-3 d-none"></div>
                    </div>
                </div>
            </div>

            <?php if (!$is_super): ?>
            <!-- ============================================== -->
            <!-- TAB 2.5: SUPPORT PERMISSION & OWNERSHIP        -->
            <!-- ============================================== -->
            <div class="tab-pane fade <?= $default_tab === 'ownership' ? 'show active' : '' ?>" id="tab-ownership" role="tabpanel" aria-labelledby="tab-ownership-link">
                <div class="card shadow-sm border-0 rounded-lg mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="font-weight-bold text-dark mb-0">
                                <i class="fas fa-user-shield text-info mr-2"></i>Support Permission & Individual Ownership
                            </h5>
                            <small class="text-muted">Control whether the Super Administrator can assist in diagnosing or modifying your account settings.</small>
                        </div>
                        <?php if (!empty($admin['support_permission'])): ?>
                            <span class="badge badge-success px-3 py-2 rounded-pill font-weight-bold">
                                <i class="fas fa-lock-open mr-1"></i> Support Allowed
                            </span>
                        <?php else: ?>
                            <span class="badge badge-secondary px-3 py-2 rounded-pill font-weight-bold">
                                <i class="fas fa-lock mr-1"></i> Private Ownership
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body p-4">
                        <!-- High Ownership Guarantee Banner -->
                        <div class="alert alert-light border rounded-lg p-3 mb-4">
                            <div class="d-flex align-items-start">
                                <div class="text-primary mr-3 mt-1">
                                    <i class="fas fa-shield-alt fa-2x"></i>
                                </div>
                                <div>
                                    <h6 class="font-weight-bold text-dark mb-1">High Level of Individual Ownership Guarantee</h6>
                                    <p class="text-muted small mb-0">
                                        Your institute branding, notification email, account password, and private Cloudinary CDN credentials belong strictly to you. 
                                        The Super Administrator cannot alter these parameters or troubleshoot issues on your behalf without your explicit permission.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Current Status Card -->
                        <?php if (!empty($admin['support_permission'])): ?>
                            <div class="card border-success bg-light mb-4 shadow-none">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-3" style="width: 44px; height: 44px;">
                                                <i class="fas fa-check fa-lg"></i>
                                            </div>
                                            <div>
                                                <h6 class="font-weight-bold text-success mb-0">Super Admin Support Access is ACTIVE</h6>
                                                <small class="text-muted">
                                                    Granted on <?= h($admin['support_permission_updated_at'] ? date('M j, Y g:i A', strtotime($admin['support_permission_updated_at'])) : 'Recently') ?>. 
                                                    The Super Admin is currently permitted to troubleshoot Cloudinary CDN and assist with account settings.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card border-secondary bg-light mb-4 shadow-none">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mr-3" style="width: 44px; height: 44px;">
                                                <i class="fas fa-user-lock fa-lg"></i>
                                            </div>
                                            <div>
                                                <h6 class="font-weight-bold text-dark mb-0">Support Access is LOCKED (Private Mode)</h6>
                                                <small class="text-muted">
                                                    <?= !empty($admin['support_permission_updated_at']) ? 'Revoked on ' . h(date('M j, Y g:i A', strtotime($admin['support_permission_updated_at']))) . '. ' : '' ?>
                                                    The Super Admin is strictly prevented from editing your credentials or Cloudinary configuration.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Toggle Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                            <input type="hidden" name="action" value="toggle_support_permission">

                            <div class="form-group mb-4">
                                <label for="auth_password_support" class="font-weight-bold text-dark small text-uppercase">
                                    Confirm Account Password to Change Support State <span class="text-danger">*</span>
                                </label>
                                <div class="input-group" style="max-width: 450px;">
                                    <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-key text-muted"></i></span></div>
                                    <input type="password" name="auth_password" id="auth_password_support" class="form-control" required placeholder="Enter your current password">
                                </div>
                                <small class="text-muted">Required to authenticate and protect your ownership status.</small>
                            </div>

                            <?php if (!empty($admin['support_permission'])): ?>
                                <input type="hidden" name="grant_support" value="0">
                                <button type="submit" class="btn btn-outline-danger font-weight-bold rounded-pill px-4 shadow-sm">
                                    <i class="fas fa-times-circle mr-2"></i>Revoke Support Permission
                                </button>
                                <span class="text-muted small ml-3">Re-locks all personal settings and private Cloudinary keys.</span>
                            <?php else: ?>
                                <input type="hidden" name="grant_support" value="1">
                                <button type="submit" class="btn btn-success font-weight-bold rounded-pill px-4 shadow-sm">
                                    <i class="fas fa-check-circle mr-2"></i>Grant Super Admin Support Access
                                </button>
                                <span class="text-muted small ml-3">Allows Super Admin to directly fix problems and configure Cloudinary CDN.</span>
                            <?php endif; ?>
                        </form>

                        <div class="mt-4 pt-3 border-top d-flex align-items-center justify-content-between flex-wrap" style="gap: 10px;">
                            <div>
                                <h6 class="font-weight-bold mb-0 text-dark">Need assistance or want to talk to Super Admin?</h6>
                                <small class="text-muted">Use the integrated direct messaging channel to communicate directly with platform administration.</small>
                            </div>
                            <a href="messages.php?channel=admin" class="btn btn-outline-primary font-weight-bold rounded-pill px-4 shadow-sm">
                                <i class="fas fa-comments mr-2"></i>Open Super Admin Chat
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============================================== -->
            <!-- TAB 3: ALLOCATED MODULES & PERMISSIONS         -->
            <!-- ============================================== -->
            <div class="tab-pane fade" id="tab-features" role="tabpanel" aria-labelledby="tab-features-link">
                <div class="card shadow-sm border-0 rounded-lg mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="font-weight-bold text-dark mb-0">
                                <i class="fas fa-th-large text-info mr-2"></i>Allocated Feature Permissions
                            </h5>
                            <small class="text-muted">Overview of features and tools enabled for your administrator account.</small>
                        </div>
                        <?php if ($is_super): ?>
                            <span class="badge badge-warning text-dark font-weight-bold px-3 py-2 rounded-pill">
                                <i class="fas fa-crown mr-1"></i> Full Access Granted
                            </span>
                        <?php else: ?>
                            <span class="badge badge-primary font-weight-bold px-3 py-2 rounded-pill">
                                <?= count($assigned_features) ?> of <?= count(ALL_ADMIN_FEATURES) ?> Enabled
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="card-body p-4">
                        <?php if ($is_super): ?>
                            <div class="alert alert-info border-info mb-4 rounded-lg">
                                <i class="fas fa-info-circle mr-2"></i>
                                As a <strong>Super Administrator</strong>, your account possesses complete, unrestricted governance over all system modules, multi-tenant accounts, and settings.
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-4">
                                The features enabled below determine which sidebar navigation items and management capabilities are visible. Contact your platform Super Administrator to request additional modules.
                            </p>
                        <?php endif; ?>

                        <div class="row">
                            <?php foreach (ALL_ADMIN_FEATURES as $fKey => $fMeta): 
                                $hasAccess = $is_super || in_array($fKey, $assigned_features, true);
                            ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100 border <?= $hasAccess ? 'border-primary' : 'bg-light text-muted' ?> rounded-lg shadow-none">
                                    <div class="card-body p-3 d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle <?= $hasAccess ? 'bg-primary text-white' : 'bg-secondary text-white-50' ?> d-flex align-items-center justify-content-center mr-3" style="width: 40px; height: 40px;">
                                                <i class="<?= $fMeta['icon'] ?>"></i>
                                            </div>
                                            <div>
                                                <h6 class="font-weight-bold mb-0 <?= $hasAccess ? 'text-dark' : 'text-muted' ?>">
                                                    <?= h($fMeta['label']) ?>
                                                </h6>
                                                <small class="text-muted" style="font-size: 0.75rem;"><?= h($fMeta['desc']) ?></small>
                                            </div>
                                        </div>
                                        <div>
                                            <?php if ($hasAccess): ?>
                                                <span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Enabled</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary px-2 py-1"><i class="fas fa-lock mr-1"></i>Locked</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================================== -->
            <!-- TAB 4: 2FA & SECURITY STATUS                   -->
            <!-- ============================================== -->
            <div class="tab-pane fade" id="tab-security" role="tabpanel" aria-labelledby="tab-security-link">
                <div class="card shadow-sm border-0 rounded-lg mb-4">
                    <div class="card-header bg-white border-bottom py-3 px-4">
                        <h5 class="font-weight-bold text-dark mb-0">
                            <i class="fas fa-shield-alt text-success mr-2"></i>Two-Factor Authentication & Session Security
                        </h5>
                        <small class="text-muted">Review current session integrity and configure additional protection layers.</small>
                    </div>

                    <div class="card-body p-4">
                        <!-- 2FA Status Row -->
                        <div class="d-flex justify-content-between align-items-center p-3 rounded-lg border mb-4 <?= !empty($admin['two_factor_enabled']) ? 'bg-light border-success' : 'bg-light' ?>">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle <?= !empty($admin['two_factor_enabled']) ? 'bg-success text-white' : 'bg-secondary text-white' ?> d-flex align-items-center justify-content-center mr-3" style="width: 46px; height: 46px;">
                                    <i class="fas fa-mobile-alt fa-lg"></i>
                                </div>
                                <div>
                                    <h6 class="font-weight-bold mb-0 text-dark">Google Authenticator (TOTP 2FA)</h6>
                                    <small class="text-muted">
                                        <?= !empty($admin['two_factor_enabled']) ? 'Active: Requires a 6-digit dynamic code upon login.' : 'Disabled: Account is protected only with a password.' ?>
                                    </small>
                                </div>
                            </div>
                            <div>
                                <a href="<?php echo BASE_URL; ?>/admin/2fa_setup.php" class="btn btn-sm <?= !empty($admin['two_factor_enabled']) ? 'btn-outline-danger' : 'btn-success' ?> font-weight-bold rounded-pill px-3">
                                    <i class="fas <?= !empty($admin['two_factor_enabled']) ? 'fa-cog' : 'fa-shield-alt' ?> mr-1"></i>
                                    <?= !empty($admin['two_factor_enabled']) ? 'Manage 2FA' : 'Enable 2FA' ?>
                                </a>
                            </div>
                        </div>

                        <!-- Active Session Integrity -->
                        <h6 class="font-weight-bold text-dark mb-3">
                            <i class="fas fa-laptop-code text-muted mr-1"></i> Active Session Information
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm small mb-0">
                                <tbody>
                                    <tr>
                                        <th class="bg-light text-muted w-25">Logged In IP Address</th>
                                        <td><code><?= h($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></code></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-muted">Session Token</th>
                                        <td><code><?= substr($_SESSION['session_token'] ?? '', 0, 16) ?>... [Protected]</code></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-muted">Browser User-Agent</th>
                                        <td class="text-truncate" style="max-width: 400px;"><?= h($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light text-muted">Inactivity Timeout</th>
                                        <td><span class="badge badge-light border">10 Minutes (Auto-logout)</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Password visibility toggle buttons
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

    // Real-time password strength and match verification
    var newPwd = document.getElementById("new_password");
    var confPwd = document.getElementById("confirm_password");
    var strengthBar = document.getElementById("passwordStrengthBar");
    var strengthLabel = document.getElementById("passwordStrengthLabel");
    var matchStatus = document.getElementById("matchStatusText");

    function checkStrength(pwd) {
        if (!pwd) {
            strengthBar.style.width = "0%";
            strengthBar.className = "progress-bar bg-danger";
            strengthLabel.textContent = "Strength: Empty";
            return 0;
        }

        var score = 0;
        if (pwd.length >= 8) score += 25;
        if (pwd.length >= 12) score += 15;
        if (/[A-Z]/.test(pwd)) score += 20;
        if (/[0-9]/.test(pwd)) score += 20;
        if (/[^A-Za-z0-9]/.test(pwd)) score += 20;

        strengthBar.style.width = score + "%";

        if (score < 40) {
            strengthBar.className = "progress-bar bg-danger";
            strengthLabel.textContent = "Strength: Weak";
            strengthLabel.className = "font-weight-bold text-danger";
        } else if (score < 75) {
            strengthBar.className = "progress-bar bg-warning";
            strengthLabel.textContent = "Strength: Moderate";
            strengthLabel.className = "font-weight-bold text-warning";
        } else {
            strengthBar.className = "progress-bar bg-success";
            strengthLabel.textContent = "Strength: Strong";
            strengthLabel.className = "font-weight-bold text-success";
        }
        return score;
    }

    function checkMatch() {
        if (!confPwd.value) {
            matchStatus.innerHTML = "&nbsp;";
            return;
        }
        if (newPwd.value === confPwd.value) {
            matchStatus.innerHTML = '<span class="text-success"><i class="fas fa-check-circle mr-1"></i> Passwords match</span>';
        } else {
            matchStatus.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle mr-1"></i> Passwords do not match</span>';
        }
    }

    if (newPwd) {
        newPwd.addEventListener("input", function() {
            checkStrength(this.value);
            checkMatch();
        });
    }

    if (confPwd) {
        confPwd.addEventListener("input", checkMatch);
    }

    // Support URL hash navigation e.g. settings.php#password, settings.php#profile
    var hash = window.location.hash;
    if (hash) {
        var cleanTab = hash.replace('#', '');
        var targetLink = document.getElementById('tab-' + cleanTab + '-link');
        if (targetLink) {
            $(targetLink).tab('show');
        }
    }

    // Live AJAX SMTP connection tester
    var btnTestSmtp = document.getElementById("btnTestSmtp");
    if (btnTestSmtp) {
        btnTestSmtp.addEventListener("click", function() {
            var icon = document.getElementById("testSmtpIcon");
            var label = document.getElementById("testSmtpText");
            var resultDiv = document.getElementById("smtpTestResult");

            var host = document.getElementById("smtp_host").value.trim();
            var port = document.getElementById("smtp_port").value.trim();
            var encryption = document.getElementById("smtp_encryption").value;
            var username = document.getElementById("smtp_username").value.trim();
            var password = document.getElementById("smtp_password").value;
            var fromEmail = document.getElementById("smtp_from_email").value.trim();
            var fromName = document.getElementById("smtp_from_name").value.trim();
            var recipient = document.getElementById("test_smtp_recipient").value.trim();
            var csrfToken = document.querySelector("#smtpSettingsForm input[name='csrf_token']").value;

            if (!host || !username) {
                resultDiv.className = "alert alert-warning shadow-sm rounded-lg mt-3 p-3";
                resultDiv.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>Please fill in at least the SMTP Host and Username before testing.';
                resultDiv.classList.remove("d-none");
                return;
            }

            btnTestSmtp.disabled = true;
            icon.className = "fas fa-spinner fa-spin mr-2";
            label.textContent = "Connecting & Authenticating...";
            resultDiv.classList.add("d-none");

            var formData = new FormData();
            formData.append("action", "test_smtp_ajax");
            formData.append("csrf_token", csrfToken);
            formData.append("host", host);
            formData.append("port", port);
            formData.append("encryption", encryption);
            formData.append("username", username);
            formData.append("password", password);
            formData.append("from_email", fromEmail);
            formData.append("from_name", fromName);
            formData.append("test_recipient", recipient);

            fetch("settings.php", {
                method: "POST",
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btnTestSmtp.disabled = false;
                icon.className = "fas fa-paper-plane mr-2";
                label.textContent = "Test Connection & Send Test Email";
                resultDiv.classList.remove("d-none");

                if (data.success) {
                    resultDiv.className = "alert alert-success shadow-sm rounded-lg mt-3 p-3";
                    resultDiv.innerHTML = '<i class="fas fa-check-circle mr-2 fa-lg"></i><strong>Success:</strong> ' + data.message;
                } else {
                    resultDiv.className = "alert alert-danger shadow-sm rounded-lg mt-3 p-3";
                    resultDiv.innerHTML = '<i class="fas fa-times-circle mr-2 fa-lg"></i><strong>Connection Failed:</strong> ' + data.message;
                }
            })
            .catch(function(err) {
                btnTestSmtp.disabled = false;
                icon.className = "fas fa-paper-plane mr-2";
                label.textContent = "Test Connection & Send Test Email";
                resultDiv.classList.remove("d-none");
                resultDiv.className = "alert alert-danger shadow-sm rounded-lg mt-3 p-3";
                resultDiv.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Network or server error during SMTP test: ' + err;
            });
        });
    }
});

function applySmtpPreset(type) {
    var host = document.getElementById('smtp_host');
    var port = document.getElementById('smtp_port');
    var enc = document.getElementById('smtp_encryption');
    var helper = document.getElementById('presetHelperBox');
    var pwdHelp = document.getElementById('smtpPasswordHelp');
    var enableSwitch = document.getElementById('smtp_enabled');
    if (enableSwitch) enableSwitch.checked = true;

    helper.classList.remove('d-none');

    if (type === 'gmail') {
        host.value = 'smtp.gmail.com';
        port.value = '587';
        enc.value = 'tls';
        helper.innerHTML = '<span class="text-danger font-weight-bold"><i class="fab fa-google mr-1"></i>Gmail / Google Workspace Setup:</span> For Gmail accounts with 2-Step Verification, generate a 16-character <strong>App Password</strong> in your Google Account: <a href="https://myaccount.google.com/apppasswords" target="_blank" class="font-weight-bold">myaccount.google.com/apppasswords</a>. Standard Gmail account passwords cannot be used directly.';
        if (pwdHelp) pwdHelp.textContent = 'Enter the 16-letter App Password generated in Google Account Security.';
    } else if (type === 'outlook') {
        host.value = 'smtp.office365.com';
        port.value = '587';
        enc.value = 'tls';
        helper.innerHTML = '<span class="text-primary font-weight-bold"><i class="fab fa-microsoft mr-1"></i>Microsoft Outlook / Office 365 Setup:</span> Uses <code>smtp.office365.com</code> on port 587 with STARTTLS encryption. Enter your Microsoft 365 or Outlook.com email and password.';
        if (pwdHelp) pwdHelp.textContent = 'Enter your Outlook / Microsoft 365 account password or App Password.';
    } else if (type === 'yahoo') {
        host.value = 'smtp.mail.yahoo.com';
        port.value = '587';
        enc.value = 'tls';
        helper.innerHTML = '<span class="font-weight-bold" style="color: #6b21a8;"><i class="fab fa-yahoo mr-1"></i>Yahoo Mail Setup:</span> Requires generating an <strong>App Password</strong> in Yahoo Account Security -> Manage App Passwords.';
        if (pwdHelp) pwdHelp.textContent = 'Enter Yahoo App Password generated in Yahoo Account Security.';
    } else if (type === 'zoho') {
        host.value = 'smtp.zoho.in';
        port.value = '587';
        enc.value = 'tls';
        helper.innerHTML = '<span class="text-success font-weight-bold"><i class="fas fa-envelope mr-1"></i>Zoho Mail Setup:</span> Uses <code>smtp.zoho.in</code> (or <code>smtp.zoho.com</code>) on port 587 with TLS. If 2FA is active on your Zoho account, use an Application-Specific Password.';
        if (pwdHelp) pwdHelp.textContent = 'Enter Zoho password or Application-Specific Password.';
    } else if (type === 'custom') {
        host.value = '';
        port.value = '587';
        enc.value = 'tls';
        helper.innerHTML = '<span class="text-dark font-weight-bold"><i class="fas fa-sliders-h mr-1"></i>Custom SMTP:</span> Enter your web host, cPanel, or custom email provider SMTP details.';
        if (pwdHelp) pwdHelp.textContent = 'Enter your custom SMTP account password.';
    }
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>

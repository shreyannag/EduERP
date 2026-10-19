<?php
// includes/mailer.php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Get SMTP settings for a specific administrator.
 * If not configured:
 *  - Super Admin falls back to platform default (env / Zoho)
 *  - Tenant Admin has has_custom_smtp = false
 */
function get_admin_smtp_config($admin_id, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $admin_id = (int)$admin_id;
    $default = [
        'enabled'        => 0,
        'host'           => 'smtp.gmail.com',
        'port'           => 587,
        'encryption'     => 'tls',
        'username'       => '',
        'password'       => '',
        'from_email'     => '',
        'from_name'      => '',
        'has_custom_smtp'=> false,
        'is_super_admin' => false
    ];

    try {
        $stmt = $pdo->prepare("SELECT id, role, email, institute_name, smtp_settings FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        $is_super = ($row['role'] === 'super_admin');
        $default['is_super_admin'] = $is_super;

        if (!empty($row['smtp_settings'])) {
            $parsed = json_decode($row['smtp_settings'], true);
            if (is_array($parsed)) {
                $merged = array_merge($default, $parsed);
                // If personal host, username, and password are configured, personal SMTP is active
                $has_creds = !empty($merged['host']) && !empty($merged['username']) && !empty($merged['password']);
                if ($has_creds) {
                    $merged['has_custom_smtp'] = true;
                    $merged['enabled'] = 1;
                } else {
                    $merged['has_custom_smtp'] = false;
                    $merged['enabled'] = 0;
                }
                return $merged;
            }
        }

        // If unconfigured:
        if ($is_super) {
            $default['enabled'] = 1;
            $default['host'] = getenv('SMTP_HOST') ?: 'smtp.zoho.in';
            $default['port'] = (int)(getenv('SMTP_PORT') ?: 587);
            $default['encryption'] = 'tls';
            $default['username'] = getenv('SMTP_USER') ?: 'shreyan_sncc@zohomail.in';
            $default['password'] = getenv('SMTP_PASS') ?: 'TT5gcaYVwRp&iU';
            $default['from_email'] = getenv('SMTP_FROM_EMAIL') ?: 'shreyan_sncc@zohomail.in';
            $default['from_name'] = getenv('SMTP_FROM_NAME') ?: ($row['institute_name'] ?: 'Main Campus');
            $default['has_custom_smtp'] = true;
        } else {
            $default['from_email'] = $row['email'] ?: '';
            $default['from_name'] = $row['institute_name'] ?: '';
            $default['has_custom_smtp'] = false;
        }

        return $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

/**
 * Save SMTP settings for a specific administrator.
 */
function save_admin_smtp_config($admin_id, $settings, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $admin_id = (int)$admin_id;
    $host     = trim($settings['host'] ?? '');
    $username = trim($settings['username'] ?? '');
    $password = (string)($settings['password'] ?? '');

    // If password is empty and there is existing password, keep existing password
    if ($password === '') {
        $existing = get_admin_smtp_config($admin_id, $pdo);
        if (!empty($existing['password'])) {
            $password = $existing['password'];
        }
    }

    // Auto-enable if host and username are provided
    $enabled = (!empty($host) && !empty($username)) ? 1 : (!empty($settings['enabled']) ? 1 : 0);

    $clean = [
        'enabled'    => $enabled,
        'host'       => $host,
        'port'       => max(1, min(65535, (int)($settings['port'] ?? 587))),
        'encryption' => in_array($settings['encryption'] ?? '', ['tls', 'ssl', 'none']) ? $settings['encryption'] : 'tls',
        'username'   => $username,
        'password'   => $password,
        'from_email' => trim($settings['from_email'] ?? ''),
        'from_name'  => trim($settings['from_name'] ?? '')
    ];

    $json = json_encode($clean);
    $stmt = $pdo->prepare("UPDATE admins SET smtp_settings = ? WHERE id = ?");
    return $stmt->execute([$json, $admin_id]);
}

/**
 * Test an SMTP configuration by attempting connection / authentication
 * and optionally sending a test email to the recipient.
 */
function test_admin_smtp_connection($settings, $testRecipient = null) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = trim($settings['host'] ?? '');
        $mail->SMTPAuth   = true;
        $mail->Username   = trim($settings['username'] ?? '');
        $mail->Password   = (string)($settings['password'] ?? '');
        
        $encryption = $settings['encryption'] ?? 'tls';
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->Port = (int)($settings['port'] ?? 587);
        $mail->Timeout = 15;

        // SSL verification options for Windows XAMPP
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $fromEmail = !empty($settings['from_email']) ? trim($settings['from_email']) : trim($settings['username'] ?? '');
        $fromName  = !empty($settings['from_name']) ? trim($settings['from_name']) : 'Institute Administrator';

        $mail->setFrom($fromEmail, $fromName);

        if (!empty($testRecipient)) {
            $mail->addAddress($testRecipient);
            $mail->isHTML(true);
            $mail->Subject = "SMTP Test Verification - {$fromName}";
            $mail->Body = "<div style='font-family: Arial, sans-serif; padding: 20px; background: #f8fafc; border-radius: 8px;'>
                <h3 style='color: #166534;'>✅ SMTP Connection Successful!</h3>
                <p>This is a verification test email confirming that your personal SMTP server <strong>" . htmlspecialchars($mail->Host) . "</strong> is properly configured.</p>
                <p>All student fee reminders, exam credentials, and notifications will now be dispatched directly from your email account (<strong>" . htmlspecialchars($fromEmail) . "</strong>).</p>
                <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 15px 0;'>
                <small style='color: #64748b;'>Delivered via Online Examination Platform Multi-Tenant SMTP Engine</small>
            </div>";
            $mail->AltBody = "SMTP Connection Successful! Your personal SMTP server {$mail->Host} is properly configured.";
            $mail->send();
            return [
                'success' => true,
                'message' => "SMTP connection verified! Test email successfully sent to {$testRecipient}."
            ];
        } else {
            $mail->getSMTPInstance();
            $connect = $mail->smtpConnect();
            if ($connect) {
                $mail->smtpClose();
                return [
                    'success' => true,
                    'message' => "SMTP connection and authentication successful on {$mail->Host}:{$mail->Port}!"
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "Could not authenticate with SMTP server {$mail->Host}."
                ];
            }
        }
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'message' => "SMTP Error: " . $mail->ErrorInfo . " (" . $e->getMessage() . ")"
        ];
    }
}

/**
 * Creates and configures a PHPMailer instance bound strictly to an administrator's personal SMTP server.
 * Returns an array [$mail, $config].
 */
function create_admin_mailer($admin_id, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    $config = get_admin_smtp_config($admin_id, $pdo);
    $mail = new PHPMailer(true);
    $mail->isSMTP();

    // Fetch institute name and email
    $adminEmail = '';
    $instituteName = 'Online Examination System';
    try {
        $stmt = $pdo->prepare("SELECT email, institute_name FROM admins WHERE id = ?");
        $stmt->execute([(int)$admin_id]);
        $adm = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($adm) {
            $adminEmail = trim($adm['email'] ?? '');
            $instituteName = trim($adm['institute_name'] ?? '');
        }
    } catch (\Throwable $e) {}

    // Check if administrator has their own personal SMTP configured and enabled
    if (!empty($config['has_custom_smtp']) && !empty($config['enabled'])) {
        $mail->Host       = $config['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];

        $encryption = $config['encryption'] ?? 'tls';
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->Port = (int)$config['port'];

        $fromEmail = !empty($config['from_email']) ? $config['from_email'] : $config['username'];
        $fromName  = !empty($config['from_name']) ? $config['from_name'] : ($instituteName ?: 'Institute Administrator');

        $mail->setFrom($fromEmail, $fromName);
        $mail->Sender = $fromEmail;
        $mail->addReplyTo($fromEmail, $fromName);
    } else {
        // Fallback to platform default SMTP (Zoho / env)
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.zoho.in';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'shreyan_sncc@zohomail.in';
        $mail->Password   = getenv('SMTP_PASS') ?: 'TT5gcaYVwRp&iU';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);

        $platformSender = getenv('SMTP_FROM_EMAIL') ?: 'shreyan_sncc@zohomail.in';
        $fromName = $instituteName ?: 'Online Examination Portal';

        $mail->setFrom($platformSender, $fromName);
        $mail->Sender = $platformSender;
        if (!empty($adminEmail)) {
            $mail->addReplyTo($adminEmail, $fromName);
        }
    }

    // Windows SSL compatibility options
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ];

    return [$mail, $config];
}

function send_student_credentials($email, $name, $username, $password, $admin_id = null, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    // If admin_id is not passed, check logged-in admin first, then look up student record
    if (empty($admin_id) && !empty($_SESSION['admin_id'])) {
        $admin_id = (int)$_SESSION['admin_id'];
    }

    if (empty($admin_id)) {
        try {
            $stmt = $pdo->prepare("SELECT admin_id FROM students WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$username, $email]);
            $admin_id = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {}
    }

    if (empty($admin_id)) {
        $admin_id = 1;
    }

    // Instantiate mailer scoped strictly to the student's administrator's personal SMTP account
    list($mail, $smtpConfig) = create_admin_mailer($admin_id, $pdo);

    // Fetch admin contact and institute details for email template
    $adminEmail = '';
    $instituteName = 'Online Examination System';
    try {
        $stmt = $pdo->prepare("SELECT email, institute_name FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $adm = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($adm) {
            $adminEmail = trim($adm['email'] ?? '');
            $instituteName = trim($adm['institute_name'] ?? '');
        }
    } catch (\Throwable $e) {}

    try {
        // Recipients
        $mail->addAddress($email, $name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = "Your Account Credentials - {$instituteName}";

        $adminContactBlock = !empty($adminEmail) 
            ? "<p style='margin: 6px 0 0 0; color: #64748b; font-size: 13px;'>Administrator Contact: <a href='mailto:" . htmlspecialchars($adminEmail) . "' style='color: #2563eb; font-weight: bold;'>" . htmlspecialchars($adminEmail) . "</a></p>"
            : "";

        $body = "
        <div style='font-family: Arial, sans-serif; padding: 20px; background: #f3f4f6;'>
            <div style='max-width: 600px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 8px; border-top: 5px solid #1e3a8a; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                <h2 style='color: #1e293b; margin-top: 0;'>Welcome to {$instituteName}!</h2>
                <p style='color: #475569;'>Hello <strong>{$name}</strong>,</p>
                <p style='color: #475569;'>An account has been created for you on the <strong>{$instituteName}</strong> Online Examination Portal.</p>
                <p style='color: #475569;'>Please find your login credentials below:</p>
                <div style='background: #f8fafc; padding: 18px; border-left: 4px solid #3b82f6; margin: 20px 0; border-radius: 4px;'>
                    <p style='margin: 0 0 8px 0; color: #334155;'><strong>Student ID / Username:</strong> <span style='font-family: monospace; font-size: 15px; color: #1e40af; font-weight: bold;'>{$username}</span></p>
                    <p style='margin: 0; color: #334155;'><strong>Password:</strong> <span style='font-family: monospace; font-size: 15px; color: #1e40af; font-weight: bold;'>{$password}</span></p>
                </div>
                <div style='border-top: 1px solid #e2e8f0; margin-top: 25px; padding-top: 15px;'>
                    <p style='margin: 0; font-weight: bold; color: #1e293b;'>Best Regards,<br>{$instituteName}</p>
                    {$adminContactBlock}
                </div>
            </div>
        </div>
        ";

        $mail->Body = $body;
        $mail->AltBody = "Welcome, {$name}!\n\nYour Student ID / Username: {$username}\nYour Password: {$password}\n\nBest Regards,\n{$instituteName}" . (!empty($adminEmail) ? " ({$adminEmail})" : "");

        $mail->send();
        return true;
    } catch (Exception $e) {
        return "Mailer Error: {$mail->ErrorInfo}";
    }
}

/**
 * Sends a secure password reset link to an administrator.
 */
function send_password_reset_email($email, $resetLink) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.zoho.in';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'shreyan_sncc@zohomail.in';
        $mail->Password   = getenv('SMTP_PASS') ?: 'TT5gcaYVwRp&iU';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'shreyan_sncc@zohomail.in';
        $fromName  = getenv('SMTP_FROM_NAME') ?: 'Online Examination System';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($email, 'Administrator');

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - Online Examination Portal';

        $body = "
        <div style='font-family: Arial, sans-serif; padding: 25px; background: #f3f4f6;'>
            <div style='max-width: 580px; margin: 0 auto; background: #ffffff; padding: 35px; border-radius: 8px; border-top: 5px solid #2563eb; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                <h2 style='color: #1f2937; margin-top: 0;'>Password Reset Request</h2>
                <p style='color: #4b5563; font-size: 15px; line-height: 1.6;'>Hello,</p>
                <p style='color: #4b5563; font-size: 15px; line-height: 1.6;'>We received a request to restore your administrator password. Click the button below to set a new password:</p>
                
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$resetLink}' style='background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 6px; font-weight: bold; display: inline-block; font-size: 16px;'>Reset My Password</a>
                </div>

                <p style='color: #4b5563; font-size: 14px; line-height: 1.5;'>Or copy and paste this link into your browser:</p>
                <p style='word-break: break-all; background: #f8fafc; padding: 12px; border-radius: 4px; font-size: 13px; color: #3b82f6;'><a href='{$resetLink}'>{$resetLink}</a></p>

                <div style='margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 13px;'>
                    <p style='margin: 0 0 8px 0;'><strong>Note:</strong> This link is single-use and will expire in 20 minutes.</p>
                    <p style='margin: 0;'>If you did not make this request, you can safely ignore this email. Your current password will remain unchanged.</p>
                </div>
            </div>
        </div>
        ";

        $mail->Body = $body;
        $mail->AltBody = "Password Reset Request\n\nClick the link below to set a new password:\n{$resetLink}\n\nThis link is single-use and expires in 20 minutes.\nIf you did not request this, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Password reset email failed: " . $mail->ErrorInfo);
        return "Mailer Error: " . $mail->ErrorInfo;
    }
}

/**
 * Sends a 6-digit authorization code to an administrator's email
 * to allow the Super Administrator to perform unrestrictive settings updates.
 */
function send_admin_support_code_email($targetAdmin, $code, $superAdmin) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.zoho.in';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: 'shreyan_sncc@zohomail.in';
        $mail->Password   = getenv('SMTP_PASS') ?: 'TT5gcaYVwRp&iU';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = getenv('SMTP_PORT') ?: 587;

        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: 'shreyan_sncc@zohomail.in';
        $fromName  = getenv('SMTP_FROM_NAME') ?: 'Online Examination Platform Master';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($targetAdmin['email'], $targetAdmin['username']);

        $mail->isHTML(true);
        $mail->Subject = '🔐 Support Authorization Code: ' . $code . ' - Settings Access Request';

        $superUsername = htmlspecialchars($superAdmin['username'] ?? 'Super Administrator');
        $targetInstitute = htmlspecialchars($targetAdmin['institute_name'] ?? 'Your Institute');
        $targetUser = htmlspecialchars($targetAdmin['username']);

        $body = "
        <div style='font-family: Arial, sans-serif; padding: 25px; background: #f1f5f9;'>
            <div style='max-width: 580px; margin: 0 auto; background: #ffffff; padding: 35px; border-radius: 12px; border-top: 5px solid #f59e0b; box-shadow: 0 4px 12px rgba(0,0,0,0.06);'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <span style='background: #fef3c7; color: #b45309; padding: 6px 14px; border-radius: 999px; font-weight: bold; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px;'>Individual Ownership Security</span>
                </div>
                <h2 style='color: #1e293b; margin-top: 0; text-align: center; font-size: 22px;'>Support Authorization Request</h2>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>Hello <strong>{$targetUser}</strong> ({$targetInstitute}),</p>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>
                    Super Administrator <strong>{$superUsername}</strong> has requested permission to modify your administrator account settings and resolve technical configurations (including institute branding, recovery email, password, and private Cloudinary CDN credentials).
                </p>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>
                    To protect your <strong>individual ownership</strong>, the Super Administrator cannot make any changes without entering the verification code below:
                </p>
                
                <div style='background: #f0fdf4; border: 2px dashed #16a34a; padding: 22px; text-align: center; border-radius: 10px; margin: 25px 0;'>
                    <div style='font-size: 13px; color: #15803d; text-transform: uppercase; font-weight: bold; letter-spacing: 1px;'>Your 6-Digit Permission Code</div>
                    <div style='font-size: 38px; font-weight: 800; color: #166534; letter-spacing: 8px; margin: 10px 0; font-family: Courier New, monospace;'>{$code}</div>
                    <div style='font-size: 13px; color: #64748b;'><i style='color: #ea580c;'>&#9201; Valid for 15 minutes</i></div>
                </div>

                <div style='background: #eff6ff; border-left: 4px solid #3b82f6; padding: 14px 16px; border-radius: 4px; margin-bottom: 25px;'>
                    <p style='margin: 0; color: #1e40af; font-size: 13.5px; line-height: 1.5;'>
                        <strong>What to do:</strong> If you requested support or approve this change, share this 6-digit code with your Super Administrator via direct chat, call, or email. Once entered, unrestricted settings change will be unlocked for them.
                    </p>
                </div>

                <div style='margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 13px;'>
                    <p style='margin: 0 0 8px 0;'><strong>Did not request this?</strong> You can safely ignore this email. Without this code, your account settings and credentials remain strictly locked under your ownership.</p>
                    <p style='margin: 0;'>Online Examination Platform &bull; Security & Autonomous Multi-Tenancy</p>
                </div>
            </div>
        </div>
        ";

        $mail->Body = $body;
        $mail->AltBody = "Support Authorization Code: {$code}\n\nSuper Administrator {$superUsername} requested permission to modify settings for {$targetInstitute}.\nShare this code with your Super Admin to authorize unrestricted changes.\nValid for 15 minutes.\nIf you did not request this, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Support code email failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send welcome login credentials email to a newly created or reset Sub-Admin
 */
function send_sub_admin_credentials($sub_admin_id, $plain_password, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    try {
        $stmt = $pdo->prepare("SELECT id, username, email, full_name, role, sub_role, parent_admin_id, institute_name FROM admins WHERE id = ?");
        $stmt->execute([(int)$sub_admin_id]);
        $subAdmin = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$subAdmin || empty($subAdmin['email'])) {
            return ['success' => false, 'error' => 'Sub-admin email not found.'];
        }

        $parent_admin_id = !empty($subAdmin['parent_admin_id']) ? (int)$subAdmin['parent_admin_id'] : 1;
        list($mail, $config) = create_admin_mailer($parent_admin_id, $pdo);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        $loginUrl = $protocol . $host . BASE_URL . '/admin/';

        $roleLabel = ucfirst($subAdmin['sub_role'] ?? 'Staff');
        if ($subAdmin['sub_role'] === 'teacher') $roleLabel = 'Teacher / Faculty';
        if ($subAdmin['sub_role'] === 'accountant') $roleLabel = 'Accountant / Financial Officer';

        $instituteName = htmlspecialchars($subAdmin['institute_name'] ?: 'Online Examination Portal');
        $recipientName = htmlspecialchars(!empty($subAdmin['full_name']) ? $subAdmin['full_name'] : $subAdmin['username']);
        $username = htmlspecialchars($subAdmin['username']);
        $safePassword = htmlspecialchars($plain_password);

        $mail->addAddress($subAdmin['email'], $recipientName);
        $mail->isHTML(true);
        $mail->Subject = "Welcome to {$instituteName} - Your {$roleLabel} Account Credentials";

        $body = "
        <div style='font-family: Arial, sans-serif; padding: 25px; background: #f1f5f9;'>
            <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 35px; border-radius: 12px; border-top: 5px solid #2563eb; box-shadow: 0 4px 12px rgba(0,0,0,0.06);'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <span style='background: #eff6ff; color: #1d4ed8; padding: 6px 14px; border-radius: 999px; font-weight: bold; font-size: 13px; text-transform: uppercase;'>Staff Onboarding</span>
                </div>
                <h2 style='color: #1e293b; margin-top: 0; text-align: center;'>Welcome to {$instituteName}</h2>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>Hello <strong>{$recipientName}</strong>,</p>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>
                    Your administrator has provisioned a <strong>{$roleLabel}</strong> account for you on the examination and management portal.
                </p>
                
                <div style='background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <h4 style='margin-top: 0; color: #0f172a; margin-bottom: 12px;'>🔑 Your Login Credentials</h4>
                    <p style='margin: 6px 0; color: #334155;'><strong>Portal URL:</strong> <a href='{$loginUrl}' style='color: #2563eb;'>{$loginUrl}</a></p>
                    <p style='margin: 6px 0; color: #334155;'><strong>Username:</strong> <code style='background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 15px;'>{$username}</code></p>
                    <p style='margin: 6px 0; color: #334155;'><strong>Temporary Password:</strong> <code style='background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 15px;'>{$safePassword}</code></p>
                    <p style='margin: 6px 0; color: #334155;'><strong>Designated Role:</strong> <span style='font-weight: 600; color: #0284c7;'>{$roleLabel}</span></p>
                </div>

                <div style='background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 4px; margin-bottom: 20px;'>
                    <p style='margin: 0; color: #92400e; font-size: 13.5px;'>
                        <strong>Security Notice:</strong> Upon your first login, the system will prompt you to replace this temporary password with a secure password of your choice.
                    </p>
                </div>

                <div style='text-align: center; margin: 25px 0;'>
                    <a href='{$loginUrl}' style='background: #2563eb; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block;'>Login to Portal</a>
                </div>

                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 13px;'>
                    <p style='margin: 0;'>If you have any questions, please contact your Institute Administrator directly.</p>
                </div>
            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = "Welcome to {$instituteName}\n\nYour {$roleLabel} credentials:\nPortal URL: {$loginUrl}\nUsername: {$username}\nPassword: {$plain_password}\n\nPlease change your password upon logging in.";

        $mail->send();
        return ['success' => true, 'message' => "Login credentials successfully emailed to {$subAdmin['email']}!"];
    } catch (\Throwable $e) {
        error_log("Failed to send sub-admin credentials: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send Salary Payslip disbursement receipt email to a Sub-Admin
 */
function send_payroll_payslip_email($payroll_payment_id, $pdo = null) {
    if (!$pdo) {
        $pdo = require __DIR__ . '/../config/db.php';
    }

    try {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   s.username as staff_username, s.full_name as staff_name, s.email as staff_email, s.sub_role,
                   a.institute_name, a.email as admin_email
            FROM payroll_payments p
            JOIN admins s ON p.sub_admin_id = s.id
            JOIN admins a ON p.admin_id = a.id
            WHERE p.id = ?
        ");
        $stmt->execute([(int)$payroll_payment_id]);
        $pay = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$pay || empty($pay['staff_email'])) {
            return ['success' => false, 'error' => 'Payment record or staff email not found.'];
        }

        list($mail, $config) = create_admin_mailer($pay['admin_id'], $pdo);

        $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
        $periodName = ($monthNames[(int)$pay['salary_month']] ?? 'Month') . ' ' . $pay['salary_year'];

        $instituteName = htmlspecialchars($pay['institute_name'] ?: 'Institute');
        $staffName = htmlspecialchars(!empty($pay['staff_name']) ? $pay['staff_name'] : $pay['staff_username']);
        $roleLabel = ucfirst($pay['sub_role'] ?? 'Staff');
        $netFormatted = '₹' . number_format($pay['net_amount'], 2);

        $mail->addAddress($pay['staff_email'], $staffName);
        $mail->isHTML(true);
        $mail->Subject = "Salary Payslip - {$periodName} ({$instituteName})";

        $body = "
        <div style='font-family: Arial, sans-serif; padding: 25px; background: #f1f5f9;'>
            <div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 35px; border-radius: 12px; border-top: 5px solid #16a34a; box-shadow: 0 4px 12px rgba(0,0,0,0.06);'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <span style='background: #dcfce7; color: #15803d; padding: 6px 14px; border-radius: 999px; font-weight: bold; font-size: 13px; text-transform: uppercase;'>Payment Voucher / Payslip</span>
                </div>
                <h2 style='color: #1e293b; margin-top: 0; text-align: center;'>Salary Disbursed - {$periodName}</h2>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>Hello <strong>{$staffName}</strong>,</p>
                <p style='color: #475569; font-size: 15px; line-height: 1.6;'>
                    Your salary for <strong>{$periodName}</strong> has been processed and disbursed by <strong>{$instituteName}</strong>.
                </p>

                <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 20px 0;'>
                    <table style='width: 100%; border-collapse: collapse; font-size: 14.5px;'>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b;'>Receipt Number:</td>
                            <td style='padding: 8px 0; font-weight: bold; text-align: right; color: #0f172a;'>{$pay['receipt_no']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b;'>Payment Date:</td>
                            <td style='padding: 8px 0; font-weight: bold; text-align: right; color: #0f172a;'>{$pay['payment_date']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; color: #64748b;'>Payment Method:</td>
                            <td style='padding: 8px 0; font-weight: bold; text-align: right; color: #0f172a; text-transform: uppercase;'>{$pay['payment_method']}</td>
                        </tr>
                        " . (!empty($pay['reference_no']) ? "
                        <tr>
                            <td style='padding: 8px 0; color: #64748b;'>Reference / UTR:</td>
                            <td style='padding: 8px 0; font-weight: bold; text-align: right; color: #0f172a;'>{$pay['reference_no']}</td>
                        </tr>" : "") . "
                        <tr style='border-top: 1px dashed #cbd5e1;'>
                            <td style='padding: 10px 0; color: #475569;'>Base Salary:</td>
                            <td style='padding: 10px 0; font-weight: bold; text-align: right; color: #334155;'>₹" . number_format($pay['base_salary'], 2) . "</td>
                        </tr>
                        " . ((float)$pay['bonus'] > 0 ? "
                        <tr>
                            <td style='padding: 6px 0; color: #16a34a;'>Incentives / Bonus:</td>
                            <td style='padding: 6px 0; font-weight: bold; text-align: right; color: #16a34a;'>+ ₹" . number_format($pay['bonus'], 2) . "</td>
                        </tr>" : "") . "
                        " . ((float)$pay['deductions'] > 0 ? "
                        <tr>
                            <td style='padding: 6px 0; color: #dc2626;'>Deductions:</td>
                            <td style='padding: 6px 0; font-weight: bold; text-align: right; color: #dc2626;'>- ₹" . number_format($pay['deductions'], 2) . "</td>
                        </tr>" : "") . "
                        <tr style='border-top: 2px solid #0f172a;'>
                            <td style='padding: 12px 0; font-size: 16px; font-weight: bold; color: #0f172a;'>Net Paid Amount:</td>
                            <td style='padding: 12px 0; font-size: 18px; font-weight: 800; text-align: right; color: #16a34a;'>{$netFormatted}</td>
                        </tr>
                    </table>
                </div>

                <div style='margin-top: 25px; padding-top: 20px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 13px; text-align: center;'>
                    <p style='margin: 0;'>Generated automatically by {$instituteName} Payroll Management System.</p>
                </div>
            </div>
        </div>";

        $mail->Body = $body;
        $mail->AltBody = "Salary Payslip - {$periodName}\n\nRecipient: {$staffName}\nNet Paid: {$netFormatted}\nReceipt: {$pay['receipt_no']}\nDate: {$pay['payment_date']}";

        $mail->send();
        return ['success' => true, 'message' => "Payslip email successfully sent to {$pay['staff_email']}."];
    } catch (\Throwable $e) {
        error_log("Failed to send payslip email: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}



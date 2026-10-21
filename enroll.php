<?php
// enroll.php - Public Self-Service Student Enrollment Portal
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

$error = '';
$success_data = null;
$link = null;

// Initialize CSRF
if (empty($_SESSION['enroll_csrf_token'])) {
    $_SESSION['enroll_csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($token)) {
    $stmt = $pdo->prepare("
        SELECT el.*, 
               c.name as class_name, 
               b.name as board_name, 
               bt.name as batch_name,
               a.institute_name,
               a.username as admin_username
        FROM enrollment_links el
        JOIN admins a ON el.admin_id = a.id
        LEFT JOIN classes c ON el.class_id = c.id
        LEFT JOIN boards b ON el.board_id = b.id
        LEFT JOIN batches bt ON el.batch_id = bt.id
        WHERE el.token = ?
    ");
    $stmt->execute([$token]);
    $link = $stmt->fetch();
}

// Check validity status
$is_expired = false;
$is_full = false;
$is_inactive = false;

if ($link) {
    $now_ts = time();
    $exp_ts = strtotime($link['expires_at']);
    $is_expired = ($exp_ts < $now_ts);
    $is_full = ($link['max_uses'] > 0 && $link['used_count'] >= $link['max_uses']);
    $is_inactive = ($link['is_active'] != 1);
}

// Fetch classes, boards, and batches for dynamic dropdowns if link is flexible
$all_classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll();
$all_boards  = $pdo->query("SELECT id, name FROM boards ORDER BY name ASC")->fetchAll();

// Handle Form Submission (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link && !$is_expired && !$is_full && !$is_inactive) {
    $submitted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['enroll_csrf_token'], $submitted_csrf)) {
        $error = "Security validation expired. Please refresh the page and try again.";
    } elseif (!empty($_POST['website_url'])) {
        // Honeypot anti-spam triggered
        $error = "Spam bot submission rejected.";
    } else {
        $full_name     = trim($_POST['full_name'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $gender        = trim($_POST['gender'] ?? '');
        $dob           = trim($_POST['dob'] ?? '');
        $parent_name   = trim($_POST['parent_name'] ?? '');
        $parent_phone  = trim($_POST['parent_phone'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $pwd_mode      = $_POST['pwd_mode'] ?? 'auto';
        $custom_pwd    = trim($_POST['custom_password'] ?? '');

        // Resolve Class & Board
        $class_id = !empty($link['class_id']) ? (int)$link['class_id'] : (int)($_POST['class_id'] ?? 0);
        $board_id = !empty($link['board_id']) ? (int)$link['board_id'] : (int)($_POST['board_id'] ?? 0);
        $batch_id = !empty($link['batch_id']) ? (int)$link['batch_id'] : (int)($_POST['batch_id'] ?? 0);

        // Required Validations
        if (empty($full_name)) {
            $error = "Please enter your full name.";
        } elseif (empty($phone)) {
            $error = "Please provide your mobile or WhatsApp contact number.";
        } elseif (!$class_id || !$board_id) {
            $error = "Please select your academic Class and Board.";
        } elseif ($link['collect_parent_info'] && empty($parent_phone)) {
            $error = "Please provide a parent or guardian contact number.";
        } elseif ($pwd_mode === 'custom' && strlen($custom_pwd) < 6) {
            $error = "Your chosen password must be at least 6 characters long.";
        } else {
            try {
                $pdo->beginTransaction();

                // Lock link row for update to prevent race conditions on max_uses
                $stmtLock = $pdo->prepare("SELECT * FROM enrollment_links WHERE id = ? FOR UPDATE");
                $stmtLock->execute([$link['id']]);
                $fresh = $stmtLock->fetch();

                if (!$fresh || !$fresh['is_active'] || strtotime($fresh['expires_at']) < time()) {
                    $pdo->rollBack();
                    $error = "This enrollment link has just expired. Registration could not be completed.";
                } elseif ($fresh['max_uses'] > 0 && $fresh['used_count'] >= $fresh['max_uses']) {
                    $pdo->rollBack();
                    $error = "All available enrollment slots for this link have just been filled.";
                } else {
                    // Check duplicate phone/email under same institute admin
                    $stmt_dup = $pdo->prepare("SELECT id, username FROM students WHERE admin_id = ? AND (phone = ? OR (email != '' AND email = ?))");
                    $stmt_dup->execute([$link['admin_id'], $phone, $email]);
                    $dup = $stmt_dup->fetch();

                    if ($dup) {
                        $pdo->rollBack();
                        $error = "A student profile with this phone number or email is already registered (Student ID: " . $dup['username'] . "). Please log in or contact the institute.";
                    } else {
                        // Generate Class and Board names for username abbreviation
                        $c_stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
                        $c_stmt->execute([$class_id]);
                        $c_row = $c_stmt->fetch();
                        $c_name = $c_row ? $c_row['name'] : 'c';

                        $b_stmt = $pdo->prepare("SELECT name FROM boards WHERE id = ?");
                        $b_stmt->execute([$board_id]);
                        $b_row = $b_stmt->fetch();
                        $b_abbr = $b_row ? preg_replace('/[^a-z]/', '', strtolower($b_row['name'])) : 'b';

                        $firstname = strtolower(explode(' ', $full_name)[0]);
                        $username = "";

                        for ($i = 0; $i < 15; $i++) {
                            $rand = mt_rand(100, 999);
                            $candidate = "{$firstname}.{$c_name}{$b_abbr}{$rand}";
                            $chk = $pdo->prepare("SELECT id FROM students WHERE username = ?");
                            $chk->execute([$candidate]);
                            if (!$chk->fetch()) {
                                $username = $candidate;
                                break;
                            }
                        }

                        if (!$username) {
                            $username = $firstname . '.' . uniqid();
                        }

                        // Determine Password
                        $plain_password = ($pwd_mode === 'custom' && !empty($custom_pwd)) ? $custom_pwd : generate_random_password(8);
                        $hashed_password = password_hash($plain_password, PASSWORD_BCRYPT);

                        // Account status: if require_approval is set, keep inactive until admin confirms
                        $student_status = $link['require_approval'] ? 0 : 1;
                        $dob_val = (!empty($dob) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) ? $dob : null;

                        // Insert Student
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO students 
                            (full_name, email, phone, parent_name, parent_phone, dob, address, gender, class_id, board_id, username, password_hash, plain_password, admin_id, enrollment_date, is_active)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
                        ");
                        $stmt_ins->execute([
                            $full_name, $email, $phone, $parent_name, $parent_phone, $dob_val,
                            $address, $gender, $class_id, $board_id, $username, $hashed_password,
                            $plain_password, $link['admin_id'], $student_status
                        ]);
                        $student_id = (int)$pdo->lastInsertId();

                        // Map to Batch if specified
                        $assigned_batch_name = '';
                        if ($batch_id > 0) {
                            $pdo->prepare("INSERT IGNORE INTO batch_students (batch_id, student_id) VALUES (?, ?)")->execute([$batch_id, $student_id]);
                            $b_info = $pdo->prepare("SELECT name FROM batches WHERE id = ?");
                            $b_info->execute([$batch_id]);
                            $assigned_batch_name = ($b_row = $b_info->fetch()) ? $b_row['name'] : '';
                        }

                        // Increment Link Usage Count
                        $pdo->prepare("UPDATE enrollment_links SET used_count = used_count + 1 WHERE id = ?")->execute([$link['id']]);

                        // Record Audit Submission
                        $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $sub_status = $link['require_approval'] ? 'pending' : 'approved';
                        $pdo->prepare("
                            INSERT INTO enrollment_submissions (link_id, student_id, ip_address, status)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$link['id'], $student_id, $client_ip, $sub_status]);

                        $pdo->commit();

                        $success_data = [
                            'student_id'   => $username,
                            'password'     => $plain_password,
                            'full_name'    => $full_name,
                            'class_name'   => $c_name,
                            'board_name'   => $b_row['name'] ?? '',
                            'batch_name'   => $assigned_batch_name,
                            'requires_approval' => $link['require_approval'],
                            'institute'    => $link['institute_name']
                        ];
                    }
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Enrollment processing error: " . $e->getMessage();
            }
        }
    }
}

// Fetch batches under this institute for student dropdown selection if link doesn't pre-lock batch
$available_batches = [];
if ($link && empty($link['batch_id'])) {
    $stmt_ab = $pdo->prepare("SELECT id, name, class_id FROM batches WHERE admin_id = ? AND is_active = 1 ORDER BY name ASC");
    $stmt_ab->execute([$link['admin_id']]);
    $available_batches = $stmt_ab->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $link ? h($link['title']) . ' - ' . h($link['institute_name']) : 'Student Enrollment' ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .enroll-container {
            max-width: 760px;
            width: 100%;
            margin: auto;
            padding: 2.5rem 1rem;
        }
        .enroll-card {
            background: #ffffff;
            border-radius: 1.25rem;
            border: 1px solid rgba(226, 232, 240, 0.8);
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .enroll-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #ffffff;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .form-section-title {
            font-size: 0.95rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.5rem;
            margin-bottom: 1.25rem;
            margin-top: 1.5rem;
        }
        .form-control, .custom-select {
            border-radius: 0.5rem;
            padding: 0.65rem 0.9rem;
            border: 1px solid #cbd5e1;
            font-size: 0.95rem;
        }
        .form-control:focus, .custom-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }
        .cred-box {
            background: #f8fafc;
            border: 2px dashed #cbd5e1;
            border-radius: 0.75rem;
            padding: 1.5rem;
        }
        .honeypot-field {
            display: none !important;
            visibility: hidden !important;
        }
    </style>
</head>
<body>

<div class="enroll-container">
    <?php if (!$link): ?>
        <!-- Invalid Link State -->
        <div class="enroll-card text-center p-5">
            <div class="text-danger mb-4"><i class="fas fa-exclamation-circle fa-5x opacity-75"></i></div>
            <h3 class="font-weight-bold text-dark mb-2">Invalid or Revoked Link</h3>
            <p class="text-muted mb-4 lead">The admission link you are trying to access does not exist or has been permanently removed by the institute administrator.</p>
            <a href="student/login.php" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow-sm">
                Go to Student Portal Login
            </a>
        </div>

    <?php elseif ($is_inactive): ?>
        <!-- Inactive Link State -->
        <div class="enroll-card text-center p-5">
            <div class="text-warning mb-4"><i class="fas fa-pause-circle fa-5x"></i></div>
            <h4 class="font-weight-bold text-dark mb-2">Enrollment Temporarily Paused</h4>
            <p class="text-muted mb-4 lead">Admissions for <strong><?= h($link['title']) ?></strong> at <strong><?= h($link['institute_name']) ?></strong> are currently paused. Please check back later or contact the administrator.</p>
            <a href="student/login.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4">
                Student Portal
            </a>
        </div>

    <?php elseif ($is_expired): ?>
        <!-- Expired Link State -->
        <div class="enroll-card text-center p-5">
            <div class="text-danger mb-4"><i class="fas fa-calendar-times fa-5x"></i></div>
            <h4 class="font-weight-bold text-dark mb-2">Enrollment Deadline Passed</h4>
            <p class="text-muted mb-3 lead">This temporary link for <strong><?= h($link['title']) ?></strong> expired on <strong><?= date('d M Y, h:i A', strtotime($link['expires_at'])) ?></strong>.</p>
            <p class="text-muted mb-4">If you still need to enroll, please contact <strong><?= h($link['institute_name']) ?></strong> for an updated admission link.</p>
            <a href="student/login.php" class="btn btn-outline-primary font-weight-bold rounded-pill px-4">
                Student Portal Login
            </a>
        </div>

    <?php elseif ($is_full): ?>
        <!-- Limit Reached State -->
        <div class="enroll-card text-center p-5">
            <div class="text-warning mb-4"><i class="fas fa-users-slash fa-5x"></i></div>
            <h4 class="font-weight-bold text-dark mb-2">Batch Capacity Reached</h4>
            <p class="text-muted mb-4 lead">All <strong><?= (int)$link['max_uses'] ?></strong> available enrollment slots for <strong><?= h($link['title']) ?></strong> have been filled. Please reach out to the institute administration for further openings.</p>
            <a href="student/login.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4">
                Student Portal Login
            </a>
        </div>

    <?php elseif ($success_data): ?>
        <!-- Enrollment Success State -->
        <div class="enroll-card">
            <div class="enroll-header bg-success">
                <div class="mb-3"><i class="fas fa-check-circle fa-4x text-white"></i></div>
                <h2 class="font-weight-bold mb-1">Enrollment Successful!</h2>
                <p class="mb-0 text-white-50 font-weight-bold">Welcome to <?= h($success_data['institute']) ?></p>
            </div>
            <div class="p-4 p-md-5">
                <?php if ($success_data['requires_approval']): ?>
                    <div class="alert alert-warning border-0 rounded-lg shadow-sm p-4 text-center mb-4">
                        <i class="fas fa-clock fa-2x mb-2 text-warning d-block"></i>
                        <h5 class="font-weight-bold text-dark mb-1">Registration Awaiting Approval</h5>
                        <p class="text-muted mb-0">Your admission details have been submitted. An administrator will review and activate your account shortly. Please keep your credentials handy below.</p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success border-0 rounded-lg shadow-sm p-3 text-center mb-4">
                        <h5 class="font-weight-bold text-success mb-1"><i class="fas fa-id-badge mr-2"></i>Account Active & Ready</h5>
                        <p class="text-dark small mb-0">Your student portal account has been provisioned. Use the credentials below to log in.</p>
                    </div>
                <?php endif; ?>

                <div class="cred-box mb-4">
                    <div class="row align-items-center text-center text-md-left">
                        <div class="col-md-6 mb-3 mb-md-0 border-md-right">
                            <small class="text-uppercase text-muted font-weight-bold d-block mb-1">Your Student ID (Username)</small>
                            <span class="h4 font-weight-bold text-primary font-family-monospace d-block mb-0" id="dispUsername"><?= h($success_data['student_id']) ?></span>
                        </div>
                        <div class="col-md-6">
                            <small class="text-uppercase text-muted font-weight-bold d-block mb-1">Account Password</small>
                            <span class="h4 font-weight-bold text-dark font-family-monospace d-block mb-0" id="dispPassword"><?= h($success_data['password']) ?></span>
                        </div>
                    </div>
                    <?php if (!empty($success_data['batch_name'])): ?>
                        <hr class="my-3">
                        <div class="text-center small text-muted">
                            <i class="fas fa-layer-group text-primary mr-1"></i>Enrolled Batch: <strong><?= h($success_data['batch_name']) ?></strong> &bull; Class: <strong><?= h($success_data['class_name']) ?></strong> (<?= h($success_data['board_name']) ?>)
                        </div>
                    <?php endif; ?>
                </div>

                <div class="d-flex flex-column flex-sm-row justify-content-center mb-4">
                    <button type="button" class="btn btn-outline-primary font-weight-bold rounded-pill px-4 py-2 mb-2 mb-sm-0 mr-sm-3" onclick="copyCredentials()">
                        <i class="fas fa-copy mr-1"></i> Copy Access Credentials
                    </button>
                    <a href="student/login.php" class="btn btn-primary font-weight-bold rounded-pill px-5 py-2 shadow-sm">
                        <i class="fas fa-sign-in-alt mr-2"></i> Proceed to Login
                    </a>
                </div>

                <p class="text-muted small text-center mb-0">
                    <i class="fas fa-info-circle mr-1"></i>Please save your Student ID and Password. You can change your password anytime after logging in.
                </p>
            </div>
        </div>

    <?php else: ?>
        <!-- Registration Form State -->
        <div class="enroll-card">
            <div class="enroll-header">
                <span class="badge badge-light px-3 py-1 text-uppercase font-weight-bold mb-2 shadow-sm text-primary">
                    <i class="fas fa-graduation-cap mr-1"></i> <?= h($link['institute_name']) ?>
                </span>
                <h3 class="font-weight-bold mb-1"><?= h($link['title']) ?></h3>
                <small class="text-white-50 d-block font-weight-bold">
                    <i class="fas fa-clock mr-1"></i> Registration Open until <?= date('d M Y, h:i A', strtotime($link['expires_at'])) ?>
                    <?php if ($link['max_uses'] > 0): ?>
                        &bull; <?= max(0, $link['max_uses'] - $link['used_count']) ?> Seats Left
                    <?php endif; ?>
                </small>
            </div>

            <div class="p-4 p-md-5">
                <?php if (!empty($link['custom_welcome_message'])): ?>
                    <div class="alert alert-info border-0 rounded-lg shadow-sm mb-4">
                        <i class="fas fa-bullhorn text-info mr-2"></i><?= nl2br(h($link['custom_welcome_message'])) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-lg shadow-sm mb-4">
                        <i class="fas fa-exclamation-triangle mr-2"></i><?= h($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="enroll.php?token=<?= urlencode($token) ?>" id="enrollForm">
                    <input type="hidden" name="token" value="<?= h($token) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['enroll_csrf_token'] ?>">

                    <!-- Honeypot -->
                    <input type="text" name="website_url" class="honeypot-field" tabindex="-1" autocomplete="off">

                    <!-- Section: Student Information -->
                    <div class="form-section-title"><i class="fas fa-user mr-2 text-primary"></i>1. Student Information</div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Rahul Sharma" value="<?= h($_POST['full_name'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Mobile / WhatsApp Number <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g. 9876543210" value="<?= h($_POST['phone'] ?? '') ?>" required>
                            <small class="text-muted">Used for login verification and exam updates.</small>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Email Address (Optional)</label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. rahul@example.com" value="<?= h($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Gender (Optional)</label>
                            <select name="gender" class="custom-select">
                                <option value="">-- Select Gender --</option>
                                <option value="Male" <?= (($_POST['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= (($_POST['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>Female</option>
                                <option value="Other" <?= (($_POST['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Date of Birth (Optional)</label>
                            <input type="date" name="dob" class="form-control" value="<?= h($_POST['dob'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- Section: Academic Placement -->
                    <div class="form-section-title"><i class="fas fa-book-reader mr-2 text-primary"></i>2. Academic Placement</div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Class / Grade <span class="text-danger">*</span></label>
                            <?php if (!empty($link['class_id'])): ?>
                                <input type="hidden" name="class_id" value="<?= $link['class_id'] ?>">
                                <input type="text" class="form-control bg-light font-weight-bold text-dark" value="<?= h($link['class_name']) ?>" readonly>
                            <?php else: ?>
                                <select name="class_id" class="custom-select" required onchange="filterBatchesByClass(this.value)">
                                    <option value="">-- Choose Class --</option>
                                    <?php foreach ($all_classes as $cls): ?>
                                        <option value="<?= $cls['id'] ?>" <?= (($_POST['class_id'] ?? '') == $cls['id']) ? 'selected' : '' ?>>
                                            <?= h($cls['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Board / Curriculum <span class="text-danger">*</span></label>
                            <?php if (!empty($link['board_id'])): ?>
                                <input type="hidden" name="board_id" value="<?= $link['board_id'] ?>">
                                <input type="text" class="form-control bg-light font-weight-bold text-dark" value="<?= h($link['board_name']) ?>" readonly>
                            <?php else: ?>
                                <select name="board_id" class="custom-select" required>
                                    <option value="">-- Choose Board --</option>
                                    <?php foreach ($all_boards as $brd): ?>
                                        <option value="<?= $brd['id'] ?>" <?= (($_POST['board_id'] ?? '') == $brd['id']) ? 'selected' : '' ?>>
                                            <?= h($brd['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Target Batch Assignment</label>
                        <?php if (!empty($link['batch_id'])): ?>
                            <input type="hidden" name="batch_id" value="<?= $link['batch_id'] ?>">
                            <div class="p-3 bg-light rounded border d-flex align-items-center">
                                <i class="fas fa-layer-group text-primary fa-lg mr-3"></i>
                                <div>
                                    <strong class="d-block text-dark font-weight-bold"><?= h($link['batch_name']) ?></strong>
                                    <small class="text-muted">Pre-assigned batch for this enrollment stream.</small>
                                </div>
                            </div>
                        <?php else: ?>
                            <select name="batch_id" id="batchSelect" class="custom-select">
                                <option value="">-- Select Preferred Batch (Optional) --</option>
                                <?php foreach ($available_batches as $bt): ?>
                                    <option value="<?= $bt['id'] ?>" data-class="<?= $bt['class_id'] ?>" <?= (($_POST['batch_id'] ?? '') == $bt['id']) ? 'selected' : '' ?>>
                                        <?= h($bt['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">You can select your preferred study slot or leave it to be assigned by the institute.</small>
                        <?php endif; ?>
                    </div>

                    <!-- Section: Parent Info (if enabled) -->
                    <?php if ($link['collect_parent_info']): ?>
                        <div class="form-section-title"><i class="fas fa-user-friends mr-2 text-primary"></i>3. Parent / Guardian Details</div>
                        <div class="row">
                            <div class="col-md-6 form-group mb-3">
                                <label class="font-weight-bold text-dark">Parent / Guardian Name</label>
                                <input type="text" name="parent_name" class="form-control" placeholder="e.g. Suresh Sharma" value="<?= h($_POST['parent_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 form-group mb-3">
                                <label class="font-weight-bold text-dark">Parent Mobile / WhatsApp <span class="text-danger">*</span></label>
                                <input type="tel" name="parent_phone" class="form-control" placeholder="e.g. 9876543211" value="<?= h($_POST['parent_phone'] ?? '') ?>" required>
                                <small class="text-muted">For fee invoices and performance reports.</small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Section: Address (if enabled) -->
                    <?php if ($link['collect_address']): ?>
                        <div class="form-section-title"><i class="fas fa-map-marker-alt mr-2 text-primary"></i><?= $link['collect_parent_info'] ? '4.' : '3.' ?> Residential Address</div>
                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-dark">Address / City / Pincode</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="e.g. 12/B Lake View Road, Kolkata - 700029"><?= h($_POST['address'] ?? '') ?></textarea>
                        </div>
                    <?php endif; ?>

                    <!-- Section: Security & Password -->
                    <div class="form-section-title"><i class="fas fa-lock mr-2 text-primary"></i>Account Password Preference</div>
                    <div class="bg-light p-3 rounded mb-4 border">
                        <div class="custom-control custom-radio mb-2">
                            <input type="radio" id="pwdAuto" name="pwd_mode" value="auto" class="custom-control-input" checked onchange="togglePwdBox(false)">
                            <label class="custom-control-label font-weight-bold text-dark" for="pwdAuto">Auto-generate a secure random password (Recommended)</label>
                            <small class="d-block text-muted ml-4">The system will generate your password and show it immediately upon submission.</small>
                        </div>
                        <div class="custom-control custom-radio">
                            <input type="radio" id="pwdCustom" name="pwd_mode" value="custom" class="custom-control-input" onchange="togglePwdBox(true)">
                            <label class="custom-control-label font-weight-bold text-dark" for="pwdCustom">Set my own password</label>
                        </div>
                        <div class="mt-3 d-none" id="customPwdBox">
                            <input type="password" name="custom_password" class="form-control" placeholder="Enter password (minimum 6 characters)">
                        </div>
                    </div>

                    <div class="text-center pt-2">
                        <button type="submit" class="btn btn-primary btn-lg font-weight-bold rounded-pill px-5 shadow transition" style="min-width: 260px;">
                            <i class="fas fa-check-circle mr-2"></i> Submit Enrollment
                        </button>
                        <p class="text-muted small mt-3 mb-0">By enrolling, you register for academic access under <?= h($link['institute_name']) ?>.</p>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<footer class="text-center text-muted small py-4 mt-auto">
    &copy; <?= date('Y') ?> Developed by <strong>SN Technologies (Shreyan Nag Technologies)</strong> &bull; EduERP Platform
</footer>

<script>
function togglePwdBox(isCustom) {
    var box = document.getElementById('customPwdBox');
    if (isCustom) {
        box.classList.remove('d-none');
    } else {
        box.classList.add('d-none');
    }
}

function copyCredentials() {
    var u = document.getElementById('dispUsername').innerText;
    var p = document.getElementById('dispPassword').innerText;
    var text = "EduERP Login Credentials:\nStudent ID: " + u + "\nPassword: " + p + "\nLogin Portal: " + window.location.origin + "<?= BASE_URL ?>/student/login.php";
    navigator.clipboard.writeText(text).then(function() {
        alert("Credentials copied to clipboard!");
    });
}

function filterBatchesByClass(classId) {
    var sel = document.getElementById('batchSelect');
    if (!sel) return;
    var opts = sel.options;
    for (var i = 1; i < opts.length; i++) {
        var opt = opts[i];
        var batchClass = opt.getAttribute('data-class');
        if (!classId || !batchClass || batchClass === classId) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
        }
    }
}
</script>

</body>
</html>

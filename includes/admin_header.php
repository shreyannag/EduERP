<?php
// includes/admin_header.php
ob_start();
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Auto-complete any expired exams
update_exam_statuses($pdo);

// Get admin info
$stmt = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$adminUser = $stmt->fetchColumn();

// Get unread messages count
$stmt_msg = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_type = 'student' AND receiver_id = ? AND is_read = 0");
$stmt_msg->execute([$_SESSION['admin_id']]);
$unread_msg_count = (int)$stmt_msg->fetchColumn();

// Get unread admin-to-admin messages count
$stmt_admin_msg = $pdo->prepare("SELECT COUNT(*) FROM admin_messages WHERE receiver_id = ? AND is_read = 0");
$stmt_admin_msg->execute([$_SESSION['admin_id']]);
$unread_admin_msg_count = (int)$stmt_admin_msg->fetchColumn();
$total_unread_msg_count = $unread_msg_count + $unread_admin_msg_count;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Online Exam</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Bootstrap 4.6 -->
        <!-- Google Material Design Fonts & Symbols -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Inter:wght@400;500;600;700&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
    <!-- Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Summernote -->
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/custom.css') ? filemtime(__DIR__ . '/../assets/css/custom.css') : time(); ?>">
    <!-- Prism.js for Programming Questions & Code Syntax Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
    <!-- MathJax v3 for mathematical equations -->
    <script>
    window.MathJax = {
        tex: {
            inlineMath: [['$', '$'], ['\\(', '\\)']],
            displayMath: [['$$', '$$'], ['\\[', '\\]']],
            processEscapes: true,
            processEnvironments: true
        },
        options: {
            skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code']
        }
    };
    </script>
    <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js"></script>
    <!-- Critical Mobile Bottom Nav Inline CSS (Cache-Proof) -->
    <style>
    @media (max-width: 767.98px) {
        body.has-admin-bottom-nav { padding-bottom: 72px !important; }
        .admin-mobile-bottom-nav {
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            height: 60px !important;
            background: #0f172a !important;
            border-top: 1px solid rgba(255, 255, 255, 0.1) !important;
            z-index: 1030 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-around !important;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.2) !important;
            padding-bottom: env(safe-area-inset-bottom, 0px) !important;
        }
        .admin-mobile-nav-item {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            color: #94a3b8 !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            flex: 1 !important;
            height: 100% !important;
            cursor: pointer !important;
            border: none !important;
            background: transparent !important;
            outline: none !important;
        }
        .admin-mobile-nav-item i { font-size: 1.2rem; margin-bottom: 2px; }
        .admin-mobile-nav-item.active { color: #38bdf8 !important; font-weight: 700 !important; }
        .admin-mobile-nav-item .mobile-icon-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 30px;
            border-radius: 16px;
            margin-bottom: 2px;
            transition: all 0.25s cubic-bezier(0.2, 0, 0, 1);
        }
        .admin-mobile-nav-item.active .mobile-icon-wrapper {
            background: rgba(56, 189, 248, 0.2) !important;
            color: #38bdf8 !important;
        }
    }
    body.modal-open .admin-mobile-bottom-nav { display: none !important; }
    </style>
</head>
<body class="has-admin-bottom-nav">

<nav class="navbar navbar-expand-md navbar-dark bg-dark shadow-sm py-2">
    <a class="navbar-brand font-weight-bold d-flex align-items-center" href="<?php echo BASE_URL; ?>/admin/dashboard.php" style="letter-spacing: -0.5px;">
        <i class="fas fa-graduation-cap text-primary mr-2"></i>
        <span><?php echo h($_SESSION['institute_name'] ?? 'Exam Admin'); ?></span>
        <?php if (is_super_admin()): ?>
            <span class="badge badge-warning text-dark font-weight-bold ml-2 px-2 py-1 shadow-sm" style="font-size: 0.72rem;">
                <i class="fas fa-crown mr-1"></i>Super Admin
            </span>
        <?php else: ?>
            <span class="badge badge-secondary font-weight-bold ml-2 px-2 py-1" style="font-size: 0.72rem;">
                <i class="fas fa-university mr-1"></i>Admin
            </span>
        <?php endif; ?>
    </a>
    <button class="navbar-toggler border-0" type="button" data-toggle="collapse" data-target="#adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="adminNavbar">
        <!-- Mobile Drawer (Visible on screens < 768px) -->
        <div class="d-md-none admin-mobile-drawer w-100">
            <!-- Mobile User Profile Card -->
            <div class="d-flex align-items-center justify-content-between p-3 mb-3 rounded" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1);">
                <div class="d-flex align-items-center overflow-hidden">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-2 font-weight-bold flex-shrink-0" style="width:36px; height:36px; font-size:14px;">
                        <?php echo strtoupper(substr($adminUser ?: 'A', 0, 1)); ?>
                    </div>
                    <div class="text-truncate">
                        <div class="font-weight-bold text-white small text-truncate"><?php echo h($adminUser ?: 'Admin'); ?></div>
                        <div class="text-white-50 small" style="font-size: 0.72rem;"><?= h($_SESSION['institute_name'] ?? 'Main Campus') ?></div>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="btn btn-sm btn-outline-danger font-weight-bold ml-2 flex-shrink-0">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>

            <!-- Core Operations -->
            <div class="drawer-category">Core Management</div>
            <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="drawer-link"><i class="fas fa-home text-primary"></i> Dashboard</a>
            <?php if (admin_has_feature('students')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/students/index.php" class="drawer-link"><i class="fas fa-user-graduate text-info"></i> Manage Students</a>
            <?php endif; ?>
            <?php if (admin_has_feature('batches')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/batches/index.php" class="drawer-link"><i class="fas fa-layer-group text-warning"></i> Teaching Batches</a>
            <?php endif; ?>

            <!-- Super Admin Section -->
            <?php if (is_super_admin()): ?>
            <div class="drawer-category text-warning"><i class="fas fa-crown mr-1"></i> Super Admin</div>
            <a href="<?php echo BASE_URL; ?>/admin/admins/index.php" class="drawer-link text-warning"><i class="fas fa-users-cog"></i> Admin Accounts</a>
            <a href="<?php echo BASE_URL; ?>/admin/admins/billing.php" class="drawer-link text-warning"><i class="fas fa-file-invoice-dollar"></i> Billing & Payments</a>
            <?php endif; ?>

            <!-- Questions -->
            <?php if (admin_has_feature('subjects') || admin_has_feature('upload_csv') || admin_has_feature('questions') || admin_has_feature('image_storage') || admin_has_feature('ai_settings') || admin_has_feature('ai_generation')): ?>
            <div class="drawer-category">Question Bank</div>
            <?php if (admin_has_feature('questions')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/questions/index.php" class="drawer-link"><i class="fas fa-database text-primary"></i> Question Bank</a>
            <?php endif; ?>
            <?php if (admin_has_feature('subjects')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/subjects/index.php" class="drawer-link"><i class="fas fa-book text-warning"></i> Subjects & Topics</a>
            <?php endif; ?>
            <?php if (admin_has_feature('upload_csv')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/questions/upload.php" class="drawer-link"><i class="fas fa-upload text-info"></i> Upload CSV</a>
            <?php endif; ?>
            <?php if (admin_has_feature('image_storage')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/image_storage.php" class="drawer-link"><i class="fas fa-images text-muted"></i> Image Storage</a>
            <?php endif; ?>
            <?php if (admin_has_feature('ai_settings') || admin_has_feature('ai_generation')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/ai_settings.php" class="drawer-link"><i class="fas fa-brain text-light"></i> OpenRouter AI</a>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Exams -->
            <?php if (admin_has_feature('exams')): ?>
            <div class="drawer-category">Exams & Tests</div>
            <a href="<?php echo BASE_URL; ?>/admin/exams/create.php" class="drawer-link"><i class="fas fa-plus-circle text-success"></i> Create Exam</a>
            <a href="<?php echo BASE_URL; ?>/admin/exams/index.php" class="drawer-link"><i class="fas fa-list-alt text-primary"></i> All Exams</a>
            <a href="<?php echo BASE_URL; ?>/admin/schedule/index.php" class="drawer-link"><i class="fas fa-calendar-alt text-info"></i> Schedule</a>
            <?php endif; ?>

            <!-- Academics & Tools -->
            <?php if (admin_has_feature('homework') || admin_has_feature('fees') || admin_has_feature('enrolments') || admin_has_feature('messages') || admin_has_feature('coding')): ?>
            <div class="drawer-category">Academics & Management</div>
            <?php if (admin_has_feature('homework')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/homework/index.php" class="drawer-link"><i class="fas fa-book-reader text-warning"></i> Homework Assignments</a>
            <?php endif; ?>
            <?php if (admin_has_feature('fees')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/fees/index.php" class="drawer-link"><i class="fas fa-hand-holding-usd text-success"></i> Fees & Payments</a>
            <?php endif; ?>
            <?php if (admin_has_feature('enrolments')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/enrolments/index.php" class="drawer-link"><i class="fas fa-user-plus text-primary"></i> Exam Enrolments</a>
            <a href="<?php echo BASE_URL; ?>/admin/enrolments/links.php" class="drawer-link"><i class="fas fa-link text-info"></i> Enrollment Links</a>
            <?php endif; ?>
            <?php if (admin_has_feature('coding')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/coding/index.php" class="drawer-link"><i class="fas fa-laptop-code text-primary"></i> Coding Arena & Contests</a>
            <?php endif; ?>
            <?php if (admin_has_feature('messages')): ?>
            <a href="<?php echo BASE_URL; ?>/admin/messages.php" class="drawer-link"><i class="fas fa-envelope text-info"></i> Student Messages <?php if($unread_msg_count > 0): ?><span class="badge badge-danger ml-auto"><?php echo $unread_msg_count; ?></span><?php endif; ?></a>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Staff & Payroll -->
            <?php if (can_manage_sub_admins() || can_process_payroll() || is_sub_admin()): ?>
            <div class="drawer-category">Staff & Payroll</div>
            <?php if (can_manage_sub_admins()): ?>
            <a href="<?php echo BASE_URL; ?>/admin/sub_admins/index.php" class="drawer-link"><i class="fas fa-user-shield text-info"></i> Sub-Admins & Staff</a>
            <?php endif; ?>
            <?php if (can_process_payroll()): ?>
            <a href="<?php echo BASE_URL; ?>/admin/payroll/index.php" class="drawer-link"><i class="fas fa-money-check-alt text-success"></i> Staff Payroll</a>
            <?php endif; ?>
            <?php if (is_sub_admin()): ?>
            <a href="<?php echo BASE_URL; ?>/admin/payroll/my_salary.php" class="drawer-link text-success"><i class="fas fa-file-invoice-dollar"></i> My Salary & Payslips</a>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Reports -->
            <?php if (admin_has_feature('reports')): ?>
            <div class="drawer-category">Reports</div>
            <a href="<?php echo BASE_URL; ?>/admin/reports/attendance.php" class="drawer-link"><i class="fas fa-clipboard-check text-success"></i> Attendance</a>
            <a href="<?php echo BASE_URL; ?>/admin/reports/results.php" class="drawer-link"><i class="fas fa-chart-bar text-warning"></i> Results</a>
            <a href="<?php echo BASE_URL; ?>/admin/exams/history.php" class="drawer-link"><i class="fas fa-history text-secondary"></i> Past Exams</a>
            <?php endif; ?>

            <!-- Account & Security -->
            <div class="drawer-category">Account & Security</div>
            <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="drawer-link"><i class="fas fa-cog text-primary"></i> Account Settings</a>
            <a href="<?php echo BASE_URL; ?>/admin/settings.php#password" class="drawer-link"><i class="fas fa-key text-warning"></i> Change Password</a>
            <a href="<?php echo BASE_URL; ?>/admin/2fa_setup.php" class="drawer-link"><i class="fas fa-shield-alt text-success"></i> Two-Factor Auth (2FA)</a>
            <a href="<?php echo BASE_URL; ?>/admin/messages.php?channel=admin" class="drawer-link"><i class="fas fa-comments text-info"></i> <?= is_super_admin() ? 'Admin Chat Network' : 'Support Chat' ?> <?php if ($unread_admin_msg_count > 0): ?><span class="badge badge-danger ml-auto"><?php echo $unread_admin_msg_count; ?></span><?php endif; ?></a>
        </div>

        <ul class="navbar-nav ml-auto d-none d-md-flex">
            <li class="nav-item dropdown">
                <a class="nav-link dropdown-toggle font-weight-bold text-white px-3" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-user-circle mr-1"></i> <?php echo h($adminUser ?: 'Admin'); ?>
                </a>
                <div class="dropdown-menu dropdown-menu-right shadow-sm border-0 mt-2" aria-labelledby="userDropdown">
                    <div class="px-3 py-2 bg-light border-bottom text-muted small">
                        <div><i class="fas fa-university text-primary mr-1"></i> <?= h($_SESSION['institute_name'] ?? 'Main Campus') ?></div>
                        <?php if (is_sub_admin()): ?>
                            <div class="mt-1">
                                <span class="badge badge-info font-weight-bold text-uppercase"><i class="fas fa-user-tag mr-1"></i><?= h(get_sub_role()) ?></span>
                                <span class="badge badge-secondary ml-1">Sub-Admin</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (is_super_admin()): ?>
                        <a class="dropdown-item py-2 font-weight-bold text-dark" href="<?php echo BASE_URL; ?>/admin/admins/index.php">
                            <i class="fas fa-users-cog mr-2 text-warning"></i>Admin Accounts
                        </a>
                        <a class="dropdown-item py-2 font-weight-bold text-dark" href="<?php echo BASE_URL; ?>/admin/admins/billing.php">
                            <i class="fas fa-file-invoice-dollar mr-2 text-success"></i>Billing & Payments
                        </a>
                        <div class="dropdown-divider"></div>
                    <?php endif; ?>
                    <?php if (can_manage_sub_admins()): ?>
                        <a class="dropdown-item py-2 font-weight-bold" href="<?php echo BASE_URL; ?>/admin/sub_admins/index.php">
                            <i class="fas fa-user-shield mr-2 text-info"></i>Sub-Admins & Staff
                        </a>
                    <?php endif; ?>
                    <?php if (can_process_payroll()): ?>
                        <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/payroll/index.php">
                            <i class="fas fa-money-check-alt mr-2 text-success"></i>Staff Payroll
                        </a>
                    <?php endif; ?>
                    <?php if (is_sub_admin()): ?>
                        <a class="dropdown-item py-2 font-weight-bold" href="<?php echo BASE_URL; ?>/admin/payroll/my_salary.php">
                            <i class="fas fa-file-invoice-dollar mr-2 text-success"></i>My Salary & Payslips
                        </a>
                    <?php endif; ?>
                    <a class="dropdown-item py-2 font-weight-bold" href="<?php echo BASE_URL; ?>/admin/settings.php"><i class="fas fa-cog mr-2 text-primary"></i>Account Settings</a>
                    <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/settings.php#password">
                        <i class="fas fa-key mr-2 text-warning"></i>Change Password
                        <?php if (admin_must_change_password()): ?>
                            <span class="badge badge-warning text-dark ml-1 font-weight-bold" style="font-size: 0.7rem;">Action Needed</span>
                        <?php endif; ?>
                    </a>
                    <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/2fa_setup.php"><i class="fas fa-shield-alt mr-2 text-success"></i>Two-Factor Auth (2FA)</a>
                    <?php if (admin_has_feature('homework')): ?>
                        <a class="dropdown-item py-2 font-weight-bold" href="<?php echo BASE_URL; ?>/admin/homework/index.php"><i class="fas fa-book-reader mr-2 text-primary"></i>Homework Hub</a>
                    <?php endif; ?>
                    <?php if (admin_has_feature('fees')): ?>
                        <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/fees/index.php"><i class="fas fa-hand-holding-usd mr-2 text-muted"></i>Fees & Payments</a>
                    <?php endif; ?>
                    <?php if (admin_has_feature('image_storage')): ?>
                        <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/image_storage.php"><i class="fas fa-images mr-2 text-muted"></i>Image Storage</a>
                    <?php endif; ?>
                    <?php if (admin_has_feature('ai_settings')): ?>
                        <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/ai_settings.php"><i class="fas fa-brain mr-2 text-muted"></i>OpenRouter AI</a>
                    <?php endif; ?>
                    <a class="dropdown-item py-2 font-weight-bold" href="<?php echo BASE_URL; ?>/admin/messages.php?channel=admin">
                        <i class="fas fa-comments mr-2 text-info"></i><?= is_super_admin() ? 'Admin Chat Network' : 'Support Chat (Super Admin)' ?>
                        <?php if ($unread_admin_msg_count > 0): ?>
                            <span class="badge badge-danger ml-1"><?= $unread_admin_msg_count ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item py-2 text-danger" href="<?php echo BASE_URL; ?>/admin/logout.php"><i class="fas fa-sign-out-alt mr-2"></i>Logout</a>
                </div>
            </li>
        </ul>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-3 col-lg-2 d-none d-md-block sidebar shadow-sm py-4">
            <div class="sidebar-sticky">

                <?php if (is_super_admin()): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-warning font-weight-bold">
                    <span><i class="fas fa-crown mr-1"></i> Super Admin</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold text-warning" href="<?php echo BASE_URL; ?>/admin/admins/index.php">
                            <i class="fas fa-users-cog mr-2 fa-fw"></i> Admin Accounts
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold text-warning" href="<?php echo BASE_URL; ?>/admin/admins/billing.php">
                            <i class="fas fa-file-invoice-dollar mr-2 fa-fw"></i> Billing & Payments
                        </a>
                    </li>
                </ul>
                <?php endif; ?>
                
                <?php if (admin_has_feature('subjects') || admin_has_feature('upload_csv') || admin_has_feature('questions') || admin_has_feature('image_storage') || admin_has_feature('ai_settings') || admin_has_feature('ai_generation')): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-white-50 font-weight-bold">
                    <span>Questions</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <?php if (admin_has_feature('subjects')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/subjects/index.php"><i class="fas fa-book mr-2 fa-fw"></i> Subjects & Topics</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('upload_csv')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/questions/upload.php"><i class="fas fa-upload mr-2 fa-fw"></i> Upload CSV</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('questions')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/questions/index.php"><i class="fas fa-database mr-2 fa-fw"></i> Question Bank</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('image_storage')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/image_storage.php"><i class="fas fa-images mr-2 fa-fw"></i> Image Storage</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('ai_settings') || admin_has_feature('ai_generation')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/ai_settings.php"><i class="fas fa-brain mr-2 fa-fw"></i> OpenRouter AI</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>

                <?php if (admin_has_feature('exams')): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-white-50 font-weight-bold">
                    <span>Exams</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/exams/create.php"><i class="fas fa-plus-circle mr-2 fa-fw"></i> Create Exam</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/exams/index.php"><i class="fas fa-list-alt mr-2 fa-fw"></i> All Exams</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/schedule/index.php"><i class="fas fa-calendar-alt mr-2 fa-fw"></i> Schedule</a>
                    </li>
                </ul>
                <?php endif; ?>

                <?php if (admin_has_feature('students') || admin_has_feature('batches') || admin_has_feature('homework') || admin_has_feature('fees') || admin_has_feature('enrolments') || admin_has_feature('messages')): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-white-50 font-weight-bold">
                    <span>Students & Academics</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <?php if (admin_has_feature('students')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/students/index.php"><i class="fas fa-users mr-2 fa-fw"></i> Manage Students</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('batches')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/batches/index.php"><i class="fas fa-layer-group mr-2 fa-fw"></i> Teaching Batches</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('homework')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/homework/index.php"><i class="fas fa-book-reader mr-2 fa-fw"></i> Homework Assignments</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('fees')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/fees/index.php"><i class="fas fa-hand-holding-usd mr-2 fa-fw"></i> Fees & Payments</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('enrolments')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/enrolments/index.php"><i class="fas fa-user-plus mr-2 fa-fw"></i> Exam Enrolments</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/enrolments/links.php"><i class="fas fa-link mr-2 fa-fw"></i> Enrollment Links</a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('messages')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/messages.php">
                            <i class="fas fa-envelope mr-2 fa-fw"></i> Messages
                            <?php if($unread_msg_count > 0): ?>
                                <span class="badge badge-danger ml-2"><?php echo $unread_msg_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (admin_has_feature('coding')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/coding/index.php"><i class="fas fa-laptop-code mr-2 fa-fw"></i> Coding & Contests</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>

                <?php if (can_manage_sub_admins() || can_process_payroll() || is_sub_admin()): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-white-50 font-weight-bold">
                    <span>Staff & Payroll</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <?php if (can_manage_sub_admins()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/sub_admins/index.php"><i class="fas fa-user-shield mr-2 fa-fw"></i> Sub-Admins & Staff</a>
                    </li>
                    <?php endif; ?>
                    <?php if (can_process_payroll()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/payroll/index.php"><i class="fas fa-money-check-alt mr-2 fa-fw"></i> Staff Payroll</a>
                    </li>
                    <?php endif; ?>
                    <?php if (is_sub_admin()): ?>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold text-success" href="<?php echo BASE_URL; ?>/admin/payroll/my_salary.php"><i class="fas fa-file-invoice-dollar mr-2 fa-fw"></i> My Salary & Payslips</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>

                <?php if (admin_has_feature('reports')): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-white-50 font-weight-bold">
                    <span>Reports</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/reports/attendance.php"><i class="fas fa-clipboard-check mr-2 fa-fw"></i> Attendance</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/reports/results.php"><i class="fas fa-chart-bar mr-2 fa-fw"></i> Results</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/exams/history.php"><i class="fas fa-history mr-2 fa-fw"></i> Past Exams</a>
                    </li>
                </ul>
                <?php endif; ?>

                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mb-2 text-white-50 font-weight-bold">
                    <span>Account</span>
                </h6>
                <ul class="nav flex-column mb-4">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/settings.php">
                            <i class="fas fa-cog mr-2 fa-fw"></i> Account Settings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/settings.php#password">
                            <i class="fas fa-key mr-2 fa-fw"></i> Change Password
                            <?php if (admin_must_change_password()): ?>
                                <span class="badge badge-warning text-dark ml-1 font-weight-bold" style="font-size: 0.65rem;">Action Needed</span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/2fa_setup.php"><i class="fas fa-shield-alt mr-2 fa-fw"></i> Two-Factor Auth (2FA)</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/messages.php?channel=admin">
                            <i class="fas fa-comments mr-2 fa-fw text-info"></i> <?php echo is_super_admin() ? 'Admin Chat Network' : 'Super Admin Chat'; ?>
                            <?php if ($unread_admin_msg_count > 0): ?>
                                <span class="badge badge-danger ml-2 font-weight-bold"><?php echo $unread_admin_msg_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
        
        <!-- Main Content -->
        <main role="main" class="col-md-9 ml-sm-auto col-lg-10 px-md-5 py-4">
            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-lg" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?= h($_SESSION['flash_error']) ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-lg" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?= h($_SESSION['flash_success']) ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>

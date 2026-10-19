<?php
// includes/student_header.php
ob_start();
require_once __DIR__ . '/student_auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

// Auto-update exam statuses globally
update_exam_statuses($pdo);

$student_id = $_SESSION['student_id'] ?? 0;
$stmt_stu = $pdo->prepare("
    SELECT s.*, c.name as class_name, b.name as board_name, a.institute_name 
    FROM students s
    JOIN classes c ON s.class_id = c.id
    JOIN boards b ON s.board_id = b.id
    LEFT JOIN admins a ON s.admin_id = a.id
    WHERE s.id = ?
");
$stmt_stu->execute([$student_id]);
$current_student = $stmt_stu->fetch();

// Get unread messages count
$stmt_msg = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_type = 'admin' AND receiver_id = ? AND is_read = 0");
$stmt_msg->execute([$student_id]);
$unread_msg_count = $stmt_msg->fetchColumn();

// Check if Online Coding Platform is active
require_once __DIR__ . '/coding_engine.php';
$coding_platform_active = is_coding_platform_enabled($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Online Examination Platform</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css">
    <!-- Prism.js for Programming Questions & Code Syntax Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
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
    <style>
        body { background-color: #f3f4f6; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; display: flex; flex-direction: column; min-height: 100vh; }
        .student-navbar { background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-bottom: 1px solid rgba(255,255,255,0.1); }
        .student-navbar .navbar-brand { font-weight: 800; letter-spacing: -0.5px; }
        .main-content { padding-top: 40px; padding-bottom: 60px; flex-grow: 1; }
        
        /* Student Navbar Links */
        .student-navbar .nav-link {
            color: rgba(219, 234, 254, 0.75) !important; /* Soft muted periwinkle-blue */
            font-weight: 600;
            padding: 0.55rem 1.15rem !important;
            border-radius: 999px;
            transition: all 0.2s ease-in-out;
            margin: 0 3px;
            border: 1px solid transparent;
        }
        .student-navbar .nav-link:hover {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.14);
        }
        .student-navbar .nav-link.active {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.22) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
            border-color: rgba(255, 255, 255, 0.3) !important;
            font-weight: 700;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark student-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="<?php echo BASE_URL; ?>/student/dashboard.php">
            <i class="fas fa-graduation-cap fa-lg mr-2 text-warning"></i>
            <div class="d-flex flex-column" style="line-height: 1.2;">
                <span class="font-weight-bold" style="letter-spacing: -0.3px;"><?php echo h(!empty($current_student['institute_name']) ? $current_student['institute_name'] : 'Student Portal'); ?></span>
                <small class="text-white-50" style="font-size: 0.7rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Online Examination Platform</small>
            </div>
        </a>
        <button class="navbar-toggler border-0" type="button" data-toggle="collapse" data-target="#studentNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
        <div class="collapse navbar-collapse" id="studentNav">
            <ul class="navbar-nav mr-auto pl-lg-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/student/dashboard.php">
                        <i class="fas fa-home mr-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo in_array($current_page, ['homework.php', 'homework_view.php']) ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/student/homework.php">
                        <i class="fas fa-book-reader mr-1"></i> Homework
                    </a>
                </li>
                <?php if ($coding_platform_active): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], '/coding/') !== false ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/student/coding/index.php">
                        <i class="fas fa-laptop-code mr-1"></i> Coding Arena
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'report_card.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/student/report_card.php">
                        <i class="fas fa-chart-line mr-1"></i> Report Card
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'messages.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/student/messages.php">
                        <i class="fas fa-envelope mr-1"></i> Messages
                        <?php if($unread_msg_count > 0): ?>
                            <span class="badge badge-danger ml-1 shadow-sm rounded-circle px-2 py-1"><?php echo $unread_msg_count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $current_page == 'fees.php' ? 'active' : ''; ?>" href="<?php echo BASE_URL; ?>/student/fees.php">
                        <i class="fas fa-receipt mr-1"></i> Fees
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav ml-auto align-items-lg-center">
                <li class="nav-item mr-3 d-none d-lg-block">
                    <span class="badge badge-light text-primary px-3 py-2 shadow-sm rounded-pill font-weight-bold opacity-90">
                        <i class="fas fa-university text-primary mr-1"></i> <?php echo h(!empty($current_student['institute_name']) ? $current_student['institute_name'] : 'Main Campus'); ?> • Class <?php echo h($current_student['class_name'] ?? ''); ?> • <?php echo h($current_student['board_name'] ?? ''); ?>
                    </span>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white font-weight-bold d-flex align-items-center" href="#" id="studentDropdown" role="button" data-toggle="dropdown">
                        <div class="bg-white text-primary rounded-circle d-flex align-items-center justify-content-center mr-2 shadow-sm font-weight-bold" style="width:32px; height:32px; font-size:14px;">
                            <?php echo strtoupper(substr($current_student['full_name'] ?? 'S', 0, 1)); ?>
                        </div>
                        <?php echo h(explode(' ', $current_student['full_name'] ?? 'Profile')[0]); ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right shadow border-0 rounded-lg mt-2">
                        <div class="px-4 py-3 bg-light border-bottom mb-2 rounded-top">
                            <p class="mb-0 font-weight-bold text-dark text-truncate"><?php echo h($current_student['full_name'] ?? ''); ?></p>
                            <p class="mb-0 small text-muted font-family-monospace"><?php echo h($current_student['username'] ?? ''); ?></p>
                            <div class="small text-primary font-weight-bold mt-1"><i class="fas fa-university mr-1"></i><?php echo h($current_student['institute_name'] ?? 'Main Campus'); ?></div>
                        </div>
                        <a class="dropdown-item py-2 text-dark font-weight-bold" href="<?php echo BASE_URL; ?>/student/homework.php">
                            <i class="fas fa-book-reader mr-2 text-primary"></i>My Homework
                        </a>
                        <a class="dropdown-item py-2 text-dark font-weight-bold" href="<?php echo BASE_URL; ?>/student/fees.php">
                            <i class="fas fa-receipt mr-2 text-primary"></i>My Fees Ledger
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item py-2 text-danger font-weight-bold transition" href="<?php echo BASE_URL; ?>/student/logout.php">
                            <i class="fas fa-sign-out-alt mr-2"></i>Logout
                        </a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container main-content">

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
$student_admin_id = !empty($current_student['admin_id']) ? (int)$current_student['admin_id'] : 1;
$coding_platform_active = is_coding_platform_enabled($pdo, $student_admin_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Online Examination Platform</title>

    <!-- PWA Progressive Web App Manifest & Meta Tags -->
    <link rel="manifest" href="<?php echo BASE_URL; ?>/manifest.json">
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>/assets/icons/favicon.png">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/icons/icon-192.png">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="EduERP">
    <script src="<?php echo BASE_URL; ?>/assets/js/pwa.js" defer></script>
        <!-- Google Material Design Fonts & Symbols -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&family=Inter:wght@400;500;600;700&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/custom.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/custom.css') ? filemtime(__DIR__ . '/../assets/css/custom.css') : time(); ?>">
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
        /* Universal Scrollbar & Viewport Stability: Prevents horizontal layout shifts / page jerking */
        html {
            overflow-y: scroll !important;
            overflow-x: hidden !important;
            scrollbar-gutter: stable;
            width: 100% !important;
            max-width: 100% !important;
        }
        body { 
            overflow-x: hidden !important;
            width: 100% !important;
            max-width: 100% !important;
            background-color: #f3f4f6; 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh; 
        }
        .student-navbar {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding-top: 0.75rem !important;
            padding-bottom: 0.75rem !important;
            min-height: 70px !important;
            height: auto !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1030 !important;
        }
        .student-navbar .navbar-brand { font-weight: 800; letter-spacing: -0.5px; }
        .student-navbar .navbar-nav { align-items: center !important; }
        .student-navbar .dropdown { position: relative !important; }
        .student-navbar .dropdown-menu-right {
            right: 0 !important;
            left: auto !important;
            transform: none !important;
            min-width: 250px;
            max-width: calc(100vw - 24px) !important;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2), 0 8px 10px -6px rgba(0, 0, 0, 0.1) !important;
            border-radius: 12px !important;
        }
        .main-content { padding-top: 32px; padding-bottom: 60px; flex-grow: 1; }
        
        /* Student Navbar Links */
        .student-navbar .nav-link {
            color: rgba(219, 234, 254, 0.8) !important;
            font-weight: 600 !important;
            padding: 0.55rem 1.15rem !important;
            border-radius: 999px;
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease !important;
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
            font-weight: 600 !important;
        }
    </style>
    <!-- Critical Mobile Bottom Nav Inline CSS (Cache-Proof) -->
    <style>
    @media (max-width: 991.98px) {
        body.has-student-bottom-nav { padding-bottom: 76px !important; }
        .student-mobile-bottom-nav {
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            height: 64px !important;
            background: rgba(255, 255, 255, 0.98) !important;
            backdrop-filter: blur(12px) !important;
            -webkit-backdrop-filter: blur(12px) !important;
            border-top: 1px solid #e2e8f0 !important;
            z-index: 1030 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: space-around !important;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.08) !important;
            padding-bottom: env(safe-area-inset-bottom, 0px) !important;
        }
        .student-mobile-nav-item {
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
            justify-content: center !important;
            color: #64748b !important;
            font-size: 0.7rem !important;
            font-weight: 600 !important;
            text-decoration: none !important;
            flex: 1 !important;
            height: 100% !important;
            position: relative !important;
            cursor: pointer !important;
            border: none !important;
            background: transparent !important;
            outline: none !important;
            overflow: hidden !important;
        }
        .student-mobile-nav-item i { font-size: 1.25rem; margin-bottom: 2px; }
        .student-mobile-nav-item.active { color: #1e3a8a !important; font-weight: 600 !important; }
        .student-mobile-nav-item.active i { color: #041e49 !important; }
        .student-mobile-nav-item .mobile-icon-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 56px;
            height: 30px;
            border-radius: 16px;
            margin-bottom: 2px;
            transition: background-color 0.2s ease, color 0.2s ease !important;
        }
        .student-mobile-nav-item.active .mobile-icon-wrapper {
            background: #d3e3fd !important;
            color: #041e49 !important;
        }
    }
    body.modal-open .student-mobile-bottom-nav { display: none !important; }
    </style>
</head>
<body class="has-student-bottom-nav">

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
                <li class="nav-item mr-2 d-none d-sm-block">
                    <button type="button" class="btn btn-sm btn-warning text-dark font-weight-bold rounded-pill shadow-sm px-3 pwa-install-btn" style="display: none;" onclick="installEduERPApp()">
                        <i class="fas fa-download mr-1"></i>Install App
                    </button>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white font-weight-bold d-flex align-items-center" href="#" id="studentDropdown" role="button" data-toggle="dropdown" data-display="static" aria-haspopup="true" aria-expanded="false">
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
                        <a class="dropdown-item py-2 text-dark font-weight-bold" href="<?php echo BASE_URL; ?>/student/report_card.php">
                            <i class="fas fa-award mr-2 text-primary"></i>My Report Card
                        </a>
                        <a class="dropdown-item py-2 text-dark font-weight-bold" href="<?php echo BASE_URL; ?>/student/homework.php">
                            <i class="fas fa-book-reader mr-2 text-primary"></i>My Homework
                        </a>
                        <a class="dropdown-item py-2 text-warning font-weight-bold pwa-install-btn" style="display: none;" href="javascript:void(0)" onclick="installEduERPApp()">
                            <i class="fas fa-download mr-2 text-warning"></i>Install App to Device
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

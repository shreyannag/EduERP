<?php
// admin/dashboard.php
require_once '../includes/admin_header.php';

$admin_id = (int)$_SESSION['admin_id'];

if (is_super_admin()) {
    $totalQuestions = (int)$pdo->query("SELECT COUNT(*) FROM questions WHERE is_archived = 0")->fetchColumn();
    $stmtMyQ = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE admin_id = ? AND is_archived = 0");
    $stmtMyQ->execute([$admin_id]);
    $myQuestions = (int)$stmtMyQ->fetchColumn();
    $activeStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_active = 1")->fetchColumn();
    $upcomingExams = (int)$pdo->query("SELECT COUNT(*) FROM exams WHERE status IN ('draft', 'published') AND TIMESTAMPADD(MINUTE, duration_minutes, TIMESTAMP(scheduled_date, scheduled_time)) >= NOW()")->fetchColumn();
    $completedExams = (int)$pdo->query("SELECT COUNT(*) FROM exams WHERE status = 'completed'")->fetchColumn();
    $recentExams = $pdo->query("SELECT title, status, scheduled_date FROM exams ORDER BY id DESC LIMIT 5")->fetchAll();
    $totalAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    $distinctInstitutes = (int)$pdo->query("SELECT COUNT(DISTINCT institute_name) FROM admins WHERE institute_name IS NOT NULL AND institute_name != ''")->fetchColumn();
} else {
    $stmtQ = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE admin_id = ? AND is_archived = 0");
    $stmtQ->execute([$admin_id]);
    $totalQuestions = (int)$stmtQ->fetchColumn();

    $stmtS = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 AND admin_id = ?");
    $stmtS->execute([$admin_id]);
    $activeStudents = (int)$stmtS->fetchColumn();

    $stmtU = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE admin_id = ? AND status IN ('draft', 'published') AND TIMESTAMPADD(MINUTE, duration_minutes, TIMESTAMP(scheduled_date, scheduled_time)) >= NOW()");
    $stmtU->execute([$admin_id]);
    $upcomingExams = (int)$stmtU->fetchColumn();

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE admin_id = ? AND status = 'completed'");
    $stmtC->execute([$admin_id]);
    $completedExams = (int)$stmtC->fetchColumn();

    $stmtRecent = $pdo->prepare("SELECT title, status, scheduled_date FROM exams WHERE admin_id = ? ORDER BY id DESC LIMIT 5");
    $stmtRecent->execute([$admin_id]);
    $recentExams = $stmtRecent->fetchAll();
}

$onlineStudentsCount = get_online_students_count(is_super_admin() ? null : $admin_id, $pdo);
$activeOnlineStudents = get_active_online_students(is_super_admin() ? null : $admin_id, $pdo, 6);

// Today's attendance summary
$todayDate = date('Y-m-d');
$todayAtt = ['reg_count' => 0, 'total_recorded' => 0, 'count_offline' => 0, 'count_online' => 0, 'count_absent' => 0];
try {
    $attWhere = "ar.session_date = ?";
    $attParams = [$todayDate];
    if (!is_super_admin()) {
        $attWhere .= " AND ar.admin_id = ?";
        $attParams[] = $admin_id;
    }
    $stmtAtt = $pdo->prepare("
        SELECT COUNT(DISTINCT ar.id) as reg_count,
               COUNT(rec.id) as total_recorded,
               SUM(CASE WHEN rec.status = 'offline' THEN 1 ELSE 0 END) as count_offline,
               SUM(CASE WHEN rec.status = 'online' THEN 1 ELSE 0 END) as count_online,
               SUM(CASE WHEN rec.status = 'absent' THEN 1 ELSE 0 END) as count_absent
        FROM attendance_registers ar
        LEFT JOIN attendance_records rec ON rec.register_id = ar.id
        WHERE $attWhere
    ");
    $stmtAtt->execute($attParams);
    $fetchedAtt = $stmtAtt->fetch(PDO::FETCH_ASSOC);
    if ($fetchedAtt && !empty($fetchedAtt['reg_count'])) {
        $todayAtt = $fetchedAtt;
    }
} catch (\Throwable $e) {
    error_log("Dashboard attendance query notice: " . $e->getMessage());
}

?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-4 border-bottom">
    <div>
        <h1 class="h2 font-weight-bold text-dark mb-1">Dashboard Overview</h1>
        <div class="text-muted small">
            <i class="fas fa-university text-primary mr-1"></i> Institute: <strong><?= h(get_current_admin_institute()) ?></strong>
            <?php if (is_super_admin()): ?>
                <span class="badge badge-warning text-dark ml-2 font-weight-bold"><i class="fas fa-crown mr-1"></i>Super Admin Portal</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (is_super_admin()): ?>
    <div class="mt-2 mt-md-0">
        <a href="<?php echo BASE_URL; ?>/admin/admins/index.php" class="btn btn-warning font-weight-bold text-dark shadow-sm rounded-pill px-4">
            <i class="fas fa-users-cog mr-2"></i>Manage Admin Accounts
        </a>
    </div>
    <?php endif; ?>
</div>

<?php if (admin_must_change_password()): ?>
<div class="alert alert-warning border-warning shadow-sm rounded-lg d-flex flex-wrap align-items-center justify-content-between p-3 mb-4" role="alert" style="background-color: #fffbeb; border-left: 5px solid #f59e0b !important;">
    <div class="d-flex align-items-center mb-2 mb-md-0">
        <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center mr-3 shadow-sm font-weight-bold" style="width: 44px; height: 44px; font-size: 1.25rem; flex-shrink: 0;">
            <i class="fas fa-key"></i>
        </div>
        <div>
            <h6 class="font-weight-bold text-dark mb-0">Action Recommended: Update Your Initial Password</h6>
            <div class="text-muted small">Your account was initialized with a temporary password by the Super Administrator. Change it now to secure your account.</div>
        </div>
    </div>
    <div>
        <a href="<?php echo BASE_URL; ?>/admin/settings.php#password" class="btn btn-warning text-dark font-weight-bold rounded-pill px-4 shadow-sm">
            Change Password <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (is_super_admin()): ?>
<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-gradient p-3" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); color: white;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-uppercase text-white-50 font-weight-bold small">Tenant Ecosystem</span>
                    <h3 class="font-weight-bold mb-0 mt-1"><?= $totalAdmins ?> Admin Accounts Registered</h3>
                    <div class="small text-white-50 mt-1"><i class="fas fa-university mr-1"></i> <?= $distinctInstitutes ?> Independent Institutes Powered</div>
                </div>
                <div>
                    <a href="<?php echo BASE_URL; ?>/admin/admins/index.php" class="btn btn-light font-weight-bold rounded-pill px-3 shadow-sm">
                        View All <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-light p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-uppercase text-muted font-weight-bold small">Tenant Student Reach</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= number_format($activeStudents) ?> Total Active Students</h3>
                    <div class="small text-muted mt-1">Platform-wide aggregate across all institutes</div>
                </div>
                <div class="text-primary opacity-50"><i class="fas fa-globe-asia fa-2x"></i></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Key Metrics Row -->
<div class="row mb-5">
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0 stat-card h-100 rounded-lg">
            <div class="card-body py-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase text-muted font-weight-bold mb-2 section-title" style="font-size: 0.8rem;"><?php echo is_super_admin() ? 'Question Bank (Global)' : 'My Question Bank'; ?></h6>
                        <h2 class="mb-0 font-weight-bold"><?php echo number_format($totalQuestions); ?></h2>
                        <?php if (is_super_admin()): ?>
                            <div class="small text-muted mt-1 font-weight-bold"><i class="fas fa-user-shield text-info mr-1"></i><?= number_format($myQuestions) ?> in your bank</div>
                        <?php endif; ?>
                    </div>
                    <div class="text-primary opacity-50"><i class="fas fa-database fa-3x"></i></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0 stat-card stat-card-success h-100 rounded-lg">
            <div class="card-body py-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase text-muted font-weight-bold mb-2" style="font-size: 0.8rem;">Active Students</h6>
                        <h2 class="mb-0 font-weight-bold"><?php echo number_format($activeStudents); ?></h2>
                        <div class="small text-success font-weight-bold mt-1">
                            <span class="spinner-grow spinner-grow-sm text-success mr-1" style="width: 8px; height: 8px;" role="status"></span>
                            <?= $onlineStudentsCount ?> online right now
                        </div>
                    </div>
                    <div class="text-success opacity-50"><i class="fas fa-user-graduate fa-3x"></i></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0 stat-card stat-card-warning h-100 rounded-lg">
            <div class="card-body py-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase text-muted font-weight-bold mb-2" style="font-size: 0.8rem;">Upcoming Exams</h6>
                        <h2 class="mb-0 font-weight-bold"><?php echo number_format($upcomingExams); ?></h2>
                    </div>
                    <div class="text-warning opacity-50"><i class="fas fa-calendar-day fa-3x"></i></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-4">
        <div class="card shadow-sm border-0 stat-card h-100 rounded-lg" style="border-left-color: #6f42c1;">
            <div class="card-body py-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="text-uppercase text-muted font-weight-bold mb-2" style="font-size: 0.8rem;">Completed Tests</h6>
                        <h2 class="mb-0 font-weight-bold"><?php echo number_format($completedExams); ?></h2>
                    </div>
                    <div class="opacity-50" style="color: #6f42c1;"><i class="fas fa-check-circle fa-3x"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card shadow-sm border-0 rounded-lg">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-2 px-4">
                <h5 class="mb-0 font-weight-bold text-dark">Recent Exam Activity</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-dark">
                        <thead class="bg-light text-uppercase text-muted" style="font-size: 0.85rem;">
                            <tr>
                                <th class="border-0 px-4 py-3">Exam Title</th>
                                <th class="border-0 py-3">Date</th>
                                <th class="border-0 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentExams as $ex): ?>
                            <tr>
                                <td class="align-middle font-weight-bold px-4 py-3 text-truncate" style="max-width: 300px;"><?php echo h($ex['title']); ?></td>
                                <td class="align-middle text-muted py-3"><?php echo $ex['scheduled_date'] ? date('M d, Y', strtotime($ex['scheduled_date'])) : 'TBD'; ?></td>
                                <td class="align-middle py-3">
                                    <?php 
                                    if($ex['status'] === 'draft') {
                                        echo '<span class="badge badge-secondary px-2 py-1 user-select-none shadow-sm">Draft</span>';
                                    } elseif($ex['status'] === 'published') {
                                        echo '<span class="badge badge-primary px-2 py-1 user-select-none shadow-sm">Published</span>';
                                    } else {
                                        echo '<span class="badge badge-success px-2 py-1 user-select-none shadow-sm">Completed</span>';
                                    } 
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($recentExams)): ?>
                            <tr><td colspan="3" class="text-center py-5 text-muted">No recent activity. Create your first exam!</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-4">
        <div class="card shadow-lg border-0 bg-gradient-primary text-white rounded-lg h-100">
            <div class="card-body p-5 text-center d-flex flex-column justify-content-center">
                <div>
                    <i class="fas fa-magic fa-3x mb-4 opacity-75"></i>
                    <h4 class="font-weight-bold mb-3">Create New Exam</h4>
                    <p class="text-white-50 mb-4 px-2">Design an assessment, pick questions from your bank, and enroll students effortlessly.</p>
                    <a href="<?php echo BASE_URL; ?>/admin/exams/create.php" class="btn btn-light btn-lg font-weight-bold shadow px-4 py-2 text-primary rounded-pill transition-all">Get Started <i class="fas fa-arrow-right ml-2 opacity-50"></i></a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Real-Time Online Students & Class Attendance Section -->
<div class="row mt-4 mb-4">
    <!-- Live Online Students -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0 font-weight-bold text-dark">
                        <span class="spinner-grow spinner-grow-sm text-success mr-2" style="width: 10px; height: 10px;" role="status"></span>
                        Students Online Right Now
                    </h5>
                    <small class="text-muted">Real-time portal sessions (last 5 minutes)</small>
                </div>
                <span class="badge badge-success px-3 py-2 font-weight-bold rounded-pill">
                    <?= $onlineStudentsCount ?> Active Online
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activeOnlineStudents)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-user-clock fa-3x mb-3 text-black-50 d-block"></i>
                        <h6 class="font-weight-bold">No Students Currently Online</h6>
                        <p class="small text-muted mb-0">Students will appear here as soon as they log into their portal.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($activeOnlineStudents as $st): ?>
                        <div class="list-group-item px-4 py-3 d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="avatar-circle mr-3 font-weight-bold bg-light text-primary rounded-circle d-flex align-items-center justify-content-center border" style="width: 38px; height: 38px;">
                                    <?= strtoupper(substr($st['full_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="font-weight-bold text-dark"><?= h($st['full_name']) ?></div>
                                    <div class="small text-muted">
                                        <span><i class="fas fa-layer-group mr-1 text-primary"></i>Class <?= h($st['class_name']) ?></span>
                                        <?php if (!empty($st['current_page'])): ?>
                                            <span class="ml-2 text-truncate d-inline-block" style="max-width: 180px;"><i class="fas fa-compass mr-1 text-info"></i><?= h(basename($st['current_page'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold">
                                    <i class="fas fa-<?= ($st['device_type'] === 'Mobile') ? 'mobile-alt' : (($st['device_type'] === 'Tablet') ? 'tablet-alt' : 'desktop') ?> mr-1 text-primary"></i>
                                    <?= h($st['device_type'] ?: 'Web') ?>
                                </span>
                                <div class="small text-success mt-1 font-weight-bold">Active now</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white border-top py-2 px-4 text-center">
                <a href="<?php echo BASE_URL; ?>/admin/students/index.php" class="text-primary font-weight-bold small">
                    View All Students <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Today's Attendance Widget -->
    <div class="col-lg-6 mb-4">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0 font-weight-bold text-dark">
                        <i class="fas fa-clipboard-check text-success mr-2"></i>Class Schedule Attendance
                    </h5>
                    <small class="text-muted">Today: <?= date('d M Y, l') ?></small>
                </div>
                <a href="<?php echo BASE_URL; ?>/admin/attendance/take.php" class="btn btn-sm btn-success font-weight-bold rounded-pill px-3 shadow-xs">
                    <i class="fas fa-plus mr-1"></i> Take Attendance
                </a>
            </div>
            <div class="card-body p-4">
                <div class="row text-center mb-3">
                    <div class="col-4">
                        <div class="p-3 bg-light rounded-lg border">
                            <span class="text-muted small text-uppercase font-weight-bold d-block">Registers</span>
                            <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= (int)$todayAtt['reg_count'] ?></h3>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded-lg border">
                            <span class="text-muted small text-uppercase font-weight-bold d-block text-success">Present</span>
                            <h3 class="font-weight-bold text-success mb-0 mt-1"><?= (int)$todayAtt['count_offline'] + (int)$todayAtt['count_online'] ?></h3>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 bg-light rounded-lg border">
                            <span class="text-muted small text-uppercase font-weight-bold d-block text-danger">Absent</span>
                            <h3 class="font-weight-bold text-danger mb-0 mt-1"><?= (int)$todayAtt['count_absent'] ?></h3>
                        </div>
                    </div>
                </div>

                <div class="bg-light p-3 rounded-lg border d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <div class="font-weight-bold text-dark">
                            <i class="fas fa-bell text-danger mr-1"></i> Absentee Parent Notifications
                        </div>
                        <div class="small text-muted">Send automated absentee emails &amp; WhatsApp alerts to parents.</div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/admin/attendance/index.php" class="btn btn-sm btn-outline-danger font-weight-bold rounded-pill px-3 mt-2 mt-sm-0">
                        View Absentees <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
            </div>
            <div class="card-footer bg-white border-top py-2 px-4 text-center">
                <a href="<?php echo BASE_URL; ?>/admin/attendance/index.php" class="text-success font-weight-bold small">
                    Open Attendance Management Portal <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    let current = window.location.pathname;
    let links = document.querySelectorAll('.sidebar .nav-link');
    links.forEach(link => {
        let baseHref = link.getAttribute('href');
        let parts = baseHref.split('/');
        let featureFolder = parts.length > 2 ? parts[2] : null;

        if (current === baseHref) {
           link.classList.add('active');
        } else if (featureFolder && current.includes('/' + featureFolder + '/')) {
           link.classList.add('active');
        }
    });
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>

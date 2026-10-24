<?php
// includes/admin_footer.php
?>
            <footer class="pt-5 pb-3 text-muted border-top mt-5 text-center small">
                <p class="mb-0">&copy; <?php echo date('Y'); ?> Developed by <strong>SN Technologies (Shreyan Nag Technologies)</strong></p>
            </footer>
        </main>
    </div>
</div>

<!-- Bootstrap Bundle (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
<!-- Flatpickr -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<!-- Summernote -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
<!-- Custom JS -->
<script src="<?php echo BASE_URL; ?>/assets/js/app.js"></script>

<!-- Bootstrap Form Validation Initializer -->
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


<!-- Mobile Quick Bottom Action Bar (Screens < 768px) -->
<nav class="admin-mobile-bottom-nav d-md-none" id="adminBottomNav">
    <?php 
    $admin_current_page = basename($_SERVER['PHP_SELF']); 
    $admin_req_uri = $_SERVER['REQUEST_URI'] ?? '';
    ?>
    <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="admin-mobile-nav-item <?php echo ($admin_current_page == 'dashboard.php') ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-home"></i></div>
        <span>Dashboard</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/students/index.php" class="admin-mobile-nav-item <?php echo (strpos($admin_req_uri, '/admin/students/') !== false) ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-user-graduate"></i></div>
        <span>Students</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/exams/index.php" class="admin-mobile-nav-item <?php echo (strpos($admin_req_uri, '/admin/exams/') !== false) ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-file-alt"></i></div>
        <span>Exams</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/batches/index.php" class="admin-mobile-nav-item <?php echo (strpos($admin_req_uri, '/admin/batches/') !== false) ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-layer-group"></i></div>
        <span>Batches</span>
    </a>
    <button type="button" class="admin-mobile-nav-item border-0 bg-transparent p-0" id="btnAdminMenuModalTrigger" data-toggle="modal" data-target="#adminMenuModal">
        <div class="mobile-icon-wrapper"><i class="fas fa-bars"></i></div>
        <span>Menu</span>
    </button>
</nav>

<!-- Admin Mobile "Menu" Bottom-Sheet Modal -->
<div class="modal fade bottom-sheet-modal admin-modal-theme" id="adminMenuModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-bottom" role="document">
        <div class="modal-content shadow-lg border-0 text-white">
            <div class="bottom-sheet-drag-handle"></div>
            <div class="modal-body pt-1 pb-4 px-3">
                <!-- User Profile Card -->
                <div class="d-flex align-items-center justify-content-between p-3 mb-3 rounded" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12);">
                    <div class="d-flex align-items-center overflow-hidden">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-2 font-weight-bold flex-shrink-0" style="width:40px; height:40px; font-size:16px;">
                            <?php echo strtoupper(substr($_SESSION['admin_user'] ?? $adminUser ?? 'A', 0, 1)); ?>
                        </div>
                        <div class="text-truncate">
                            <div class="font-weight-bold text-white"><?php echo h($_SESSION['admin_user'] ?? $adminUser ?? 'Administrator'); ?></div>
                            <div class="text-white-50 small" style="font-size: 0.75rem;"><?php echo h($_SESSION['institute_name'] ?? 'Main Campus'); ?></div>
                        </div>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="btn btn-sm btn-outline-danger font-weight-bold ml-2 flex-shrink-0">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>

                <!-- Navigation List -->
                <div class="admin-mobile-drawer p-0 bg-transparent border-0">
                    <div class="drawer-category">Core Management</div>
                    <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="drawer-link"><i class="fas fa-home text-primary"></i> Dashboard</a>
                    <?php if (admin_has_feature('students')): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/students/index.php" class="drawer-link"><i class="fas fa-user-graduate text-info"></i> Manage Students</a>
                    <?php endif; ?>
                    <?php if (admin_has_feature('attendance')): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/attendance/index.php" class="drawer-link"><i class="fas fa-clipboard-check text-success"></i> Class Attendance</a>
                    <?php endif; ?>
                    <?php if (admin_has_feature('batches')): ?>
                    <a href="<?php echo BASE_URL; ?>/admin/batches/index.php" class="drawer-link"><i class="fas fa-layer-group text-warning"></i> Teaching Batches</a>
                    <?php endif; ?>

                    <?php if (is_super_admin()): ?>
                    <div class="drawer-category text-warning"><i class="fas fa-crown mr-1"></i> Super Admin</div>
                    <a href="<?php echo BASE_URL; ?>/admin/admins/index.php" class="drawer-link text-warning"><i class="fas fa-users-cog"></i> Admin Accounts</a>
                    <a href="<?php echo BASE_URL; ?>/admin/admins/billing.php" class="drawer-link text-warning"><i class="fas fa-file-invoice-dollar"></i> Billing & Payments</a>
                    <?php endif; ?>

                    <?php if (admin_has_feature('questions') || admin_has_feature('subjects') || admin_has_feature('upload_csv') || admin_has_feature('image_storage') || admin_has_feature('ai_settings')): ?>
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

                    <?php if (admin_has_feature('exams')): ?>
                    <div class="drawer-category">Exams & Tests</div>
                    <a href="<?php echo BASE_URL; ?>/admin/exams/create.php" class="drawer-link"><i class="fas fa-plus-circle text-success"></i> Create Exam</a>
                    <a href="<?php echo BASE_URL; ?>/admin/exams/index.php" class="drawer-link"><i class="fas fa-list-alt text-primary"></i> All Exams</a>
                    <a href="<?php echo BASE_URL; ?>/admin/schedule/index.php" class="drawer-link"><i class="fas fa-calendar-alt text-info"></i> Schedule</a>
                    <?php endif; ?>

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
                    <a href="<?php echo BASE_URL; ?>/admin/messages.php" class="drawer-link"><i class="fas fa-envelope text-info"></i> Student Messages</a>
                    <?php endif; ?>
                    <?php endif; ?>

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

                    <?php if (admin_has_feature('reports')): ?>
                    <div class="drawer-category">Reports</div>
                    <a href="<?php echo BASE_URL; ?>/admin/reports/attendance.php" class="drawer-link"><i class="fas fa-clipboard-check text-success"></i> Attendance</a>
                    <a href="<?php echo BASE_URL; ?>/admin/reports/results.php" class="drawer-link"><i class="fas fa-chart-bar text-warning"></i> Results</a>
                    <a href="<?php echo BASE_URL; ?>/admin/exams/history.php" class="drawer-link"><i class="fas fa-history text-secondary"></i> Past Exams</a>
                    <?php endif; ?>

                    <div class="drawer-category">Account & Security</div>
                    <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="drawer-link"><i class="fas fa-cog text-primary"></i> Account Settings</a>
                    <a href="<?php echo BASE_URL; ?>/admin/settings.php#password" class="drawer-link"><i class="fas fa-key text-warning"></i> Change Password</a>
                    <a href="<?php echo BASE_URL; ?>/admin/2fa_setup.php" class="drawer-link"><i class="fas fa-shield-alt text-success"></i> Two-Factor Auth (2FA)</a>
                    <a href="<?php echo BASE_URL; ?>/admin/messages.php?channel=admin" class="drawer-link"><i class="fas fa-comments text-info"></i> Support Chat</a>
                </div>

                <div class="mt-4 pt-3 border-top border-secondary d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary rounded-pill px-4 font-weight-bold" data-dismiss="modal">Close</button>
                    <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="btn btn-danger rounded-pill px-4 font-weight-bold">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Explicit touch & click activation for admin mobile menu modal
$(document).ready(function() {
    $('#btnAdminMenuModalTrigger, [data-target="#adminMenuModal"]').on('click', function(e) {
        e.preventDefault();
        $('#adminMenuModal').modal('show');
    });
});
</script>
<script>
// Real-time Session Guard & Presence for Admin
(function() {
    function pingAdminPresence() {
        var pageTitle = document.title || 'Admin Portal';
        $.ajax({
            url: '<?php echo BASE_URL; ?>/admin/heartbeat.php',
            method: 'POST',
            data: { current_page: pageTitle },
            dataType: 'json'
        }).fail(function(xhr) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp && resp.error && resp.error.indexOf('logged in from another') !== -1) {
                    alert("Session Terminated: Your administrator account was accessed from another device. You will now be redirected to login.");
                    window.location.href = '<?php echo BASE_URL; ?>/admin/?msg=concurrent';
                }
            } catch(e) {}
        });
    }
    setInterval(pingAdminPresence, 35000);
})();
</script>
</body>
</html>

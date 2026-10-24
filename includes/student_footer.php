<?php
// includes/student_footer.php
?>
</div> <!-- padding container end -->

<footer class="bg-white border-top py-4 mt-auto w-100">
    <div class="container d-flex flex-column flex-md-row justify-content-between align-items-center text-muted small font-weight-bold">
        <p class="mb-2 mb-md-0">&copy; <?php echo date('Y'); ?> Developed by SN Technologies (Shreyan Nag Technologies)</p>
        <p class="mb-0 px-3 py-1 bg-light border rounded shadow-sm opacity-75"><i class="fas fa-lock mr-2"></i>Protected Student Sequence Enclave Node</p>
    </div>
</footer>

<script src="<?php echo BASE_URL; ?>/assets/js/app.js"></script>

<!-- Student Mobile Persistent Bottom Navigation Bar -->
<nav class="student-mobile-bottom-nav d-lg-none">
    <?php 
    $s_curr_page = basename($_SERVER['PHP_SELF']); 
    ?>
    <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="student-mobile-nav-item <?php echo ($s_curr_page == 'dashboard.php') ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-home"></i></div>
        <span>Home</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/student/homework.php" class="student-mobile-nav-item <?php echo in_array($s_curr_page, ['homework.php', 'homework_view.php']) ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-book-reader"></i></div>
        <span>Homework</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/student/report_card.php" class="student-mobile-nav-item <?php echo ($s_curr_page == 'report_card.php') ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-chart-line"></i></div>
        <span>Results</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/student/fees.php" class="student-mobile-nav-item <?php echo ($s_curr_page == 'fees.php') ? 'active' : ''; ?>">
        <div class="mobile-icon-wrapper"><i class="fas fa-receipt"></i></div>
        <span>Fees</span>
    </a>
    <button type="button" class="student-mobile-nav-item border-0 bg-transparent p-0" id="btnStudentMoreModalTrigger" data-toggle="modal" data-target="#studentMoreModal">
        <div class="mobile-icon-wrapper position-relative"><i class="fas fa-th-large"></i></div>
        <span>More</span>
        <?php if (!empty($unread_msg_count)): ?>
            <span class="badge badge-danger mobile-badge-dot"></span>
        <?php endif; ?>
    </button>
</nav>

<!-- Student Mobile "More" Drawer Modal -->
<div class="modal fade bottom-sheet-modal" id="studentMoreModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-bottom" role="document">
        <div class="modal-content shadow-lg">
            <div class="bottom-sheet-drag-handle"></div>
            <div class="modal-body pt-1 pb-4 px-4">
                <div class="d-flex align-items-center mb-3 pb-3 border-bottom">
                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-3 shadow-sm font-weight-bold" style="width:46px; height:46px; font-size:18px;">
                        <?php echo strtoupper(substr($current_student['full_name'] ?? 'S', 0, 1)); ?>
                    </div>
                    <div class="overflow-hidden">
                        <h6 class="font-weight-bold mb-0 text-dark text-truncate"><?php echo h($current_student['full_name'] ?? 'Student'); ?></h6>
                        <span class="small text-muted font-family-monospace"><?php echo h($current_student['username'] ?? ''); ?></span>
                        <div class="small text-primary font-weight-bold">
                            <i class="fas fa-university mr-1"></i><?php echo h($current_student['institute_name'] ?? 'Main Campus'); ?> • Class <?php echo h($current_student['class_name'] ?? ''); ?>
                        </div>
                    </div>
                </div>

                <div class="list-group list-group-flush mb-3">
                    <?php if (!empty($coding_platform_active)): ?>
                    <a href="<?php echo BASE_URL; ?>/student/coding/index.php" class="list-group-item list-group-item-action d-flex align-items-center py-3 font-weight-bold text-dark border-0 rounded-lg mb-1">
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-2 mr-3 d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                            <i class="fas fa-laptop-code"></i>
                        </div>
                        <span>Coding Arena & Contests</span>
                        <i class="fas fa-chevron-right ml-auto text-muted small"></i>
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL; ?>/student/messages.php" class="list-group-item list-group-item-action d-flex align-items-center py-3 font-weight-bold text-dark border-0 rounded-lg mb-1">
                        <div class="rounded-circle bg-info bg-opacity-10 text-info p-2 mr-3 d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <span>Messages</span>
                        <?php if(!empty($unread_msg_count)): ?>
                            <span class="badge badge-danger ml-2 px-2 py-1"><?php echo $unread_msg_count; ?></span>
                        <?php endif; ?>
                        <i class="fas fa-chevron-right ml-auto text-muted small"></i>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/fees.php" class="list-group-item list-group-item-action d-flex align-items-center py-3 font-weight-bold text-dark border-0 rounded-lg mb-1">
                        <div class="rounded-circle bg-success bg-opacity-10 text-success p-2 mr-3 d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                            <div class="mobile-icon-wrapper"><i class="fas fa-receipt"></i></div>
                        </div>
                        <span>Fees & Payments Ledger</span>
                        <i class="fas fa-chevron-right ml-auto text-muted small"></i>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/homework.php" class="list-group-item list-group-item-action d-flex align-items-center py-3 font-weight-bold text-dark border-0 rounded-lg mb-1">
                        <div class="rounded-circle bg-warning bg-opacity-10 text-warning p-2 mr-3 d-flex align-items-center justify-content-center" style="width:36px; height:36px;">
                            <i class="fas fa-book-reader"></i>
                        </div>
                        <span>Homework & Assignments</span>
                        <i class="fas fa-chevron-right ml-auto text-muted small"></i>
                    </a>
                </div>

                <div class="d-flex justify-content-between">
                    <button type="button" class="btn btn-light rounded-pill px-4 font-weight-bold text-muted" data-dismiss="modal">Close</button>
                    <a href="<?php echo BASE_URL; ?>/student/logout.php" class="btn btn-outline-danger rounded-pill px-4 font-weight-bold">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
// Explicit touch & click activation for student mobile more modal
$(document).ready(function() {
    $('#btnStudentMoreModalTrigger, [data-target="#studentMoreModal"]').on('click', function(e) {
        e.preventDefault();
        $('#studentMoreModal').modal('show');
    });
});
</script>
<script>
// Real-time Presence & Multi-Login Session Guard
(function() {
    function pingStudentPresence() {
        var pageTitle = document.title || 'Student Portal';
        $.ajax({
            url: '<?php echo BASE_URL; ?>/student/heartbeat.php',
            method: 'POST',
            data: { current_page: pageTitle },
            dataType: 'json'
        }).fail(function(xhr) {
            if (xhr.status === 401) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.error === 'concurrent') {
                        alert("Session Terminated: Your account was logged in from another device. You will now be redirected to login.");
                        window.location.href = '<?php echo BASE_URL; ?>/student/login.php?msg=concurrent';
                        return;
                    }
                } catch(e) {}
                window.location.href = '<?php echo BASE_URL; ?>/student/login.php?msg=timeout';
            }
        });
    }
    setInterval(pingStudentPresence, 25000);
})();
</script>
</body>
</html>

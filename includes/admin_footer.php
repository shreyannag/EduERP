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
<nav class="admin-mobile-bottom-nav d-md-none">
    <?php 
    $admin_current_page = basename($_SERVER['PHP_SELF']); 
    $admin_req_uri = $_SERVER['REQUEST_URI'] ?? '';
    ?>
    <a href="<?php echo BASE_URL; ?>/admin/dashboard.php" class="admin-mobile-nav-item <?php echo ($admin_current_page == 'dashboard.php') ? 'active' : ''; ?>">
        <i class="fas fa-home"></i>
        <span>Dashboard</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/students/index.php" class="admin-mobile-nav-item <?php echo (strpos($admin_req_uri, '/admin/students/') !== false) ? 'active' : ''; ?>">
        <i class="fas fa-user-graduate"></i>
        <span>Students</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/exams/index.php" class="admin-mobile-nav-item <?php echo (strpos($admin_req_uri, '/admin/exams/') !== false) ? 'active' : ''; ?>">
        <i class="fas fa-file-alt"></i>
        <span>Exams</span>
    </a>
    <a href="<?php echo BASE_URL; ?>/admin/batches/index.php" class="admin-mobile-nav-item <?php echo (strpos($admin_req_uri, '/admin/batches/') !== false) ? 'active' : ''; ?>">
        <i class="fas fa-layer-group"></i>
        <span>Batches</span>
    </a>
    <a href="javascript:void(0)" class="admin-mobile-nav-item" data-toggle="collapse" data-target="#adminNavbar" onclick="window.scrollTo({top:0, behavior:'smooth'});">
        <i class="fas fa-bars"></i>
        <span>Menu</span>
    </a>
</nav>

</body>
</html>

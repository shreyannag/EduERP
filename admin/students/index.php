<?php
// admin/students/index.php
require_once '../../includes/admin_header.php';

require_admin_feature('students');

$filter_class = $_GET['class_id'] ?? '';
$filter_board = $_GET['board_id'] ?? '';
$filter_admin = $_GET['admin_id'] ?? '';

$where = "1=1";
$params = [];

if (!is_super_admin()) {
    $where .= " AND s.admin_id = ?";
    $params[] = get_effective_admin_id();
} elseif (!empty($filter_admin)) {
    $where .= " AND s.admin_id = ?";
    $params[] = (int)$filter_admin;
}

if ($filter_class) { $where .= " AND s.class_id = ?"; $params[] = $filter_class; }
if ($filter_board) { $where .= " AND s.board_id = ?"; $params[] = $filter_board; }

try {
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, b.name as board_name, a.institute_name, a.username as admin_username,
        (SELECT COUNT(*) FROM enrolments WHERE student_id = s.id) as enrolled_count,
        (SELECT COUNT(*) FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE)) as is_online_now,
        (SELECT device_type FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as online_device,
        (SELECT browser FROM user_sessions us WHERE us.user_type = 'student' AND us.user_id = s.id AND us.is_active = 1 AND us.last_activity >= (NOW() - INTERVAL 5 MINUTE) ORDER BY last_activity DESC LIMIT 1) as online_browser
        FROM students s
        JOIN classes c ON s.class_id = c.id
        JOIN boards b ON s.board_id = b.id
        LEFT JOIN admins a ON s.admin_id = a.id
        WHERE $where
        ORDER BY s.id DESC
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (\Throwable $e) {
    error_log("Students list query notice: " . $e->getMessage());
    $stmt = $pdo->prepare("
        SELECT s.*, c.name as class_name, b.name as board_name, a.institute_name, a.username as admin_username,
        (SELECT COUNT(*) FROM enrolments WHERE student_id = s.id) as enrolled_count,
        0 as is_online_now, NULL as online_device, NULL as online_browser
        FROM students s
        JOIN classes c ON s.class_id = c.id
        JOIN boards b ON s.board_id = b.id
        LEFT JOIN admins a ON s.admin_id = a.id
        WHERE $where
        ORDER BY s.id DESC
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll();
}

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll();
$boards = $pdo->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();
$all_admins = is_super_admin() ? $pdo->query("SELECT id, username, institute_name FROM admins ORDER BY institute_name ASC")->fetchAll() : [];

$totalOnline = count(array_filter($students, function($st) { return !empty($st['is_online_now']); }));
$totalActive = count(array_filter($students, function($st) { return !empty($st['is_active']); }));
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Students List</h2>
    <div>
        <button type="button" class="btn btn-outline-info font-weight-bold shadow-sm rounded-pill px-4 mr-2" id="bulkEmailBtn" disabled onclick="sendEmails()">
            <i class="fas fa-envelope mr-2"></i>Send Credentials
        </button>
        <a href="../enrolments/links.php" class="btn btn-outline-primary font-weight-bold shadow-sm rounded-pill px-4 mr-2">
            <i class="fas fa-link mr-2"></i>Enrollment Links
        </a>
        <a href="create.php" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-user-plus mr-2"></i>Add Student
        </a>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-check-circle mr-2"></i><?php echo h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- Quick KPI Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-2 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-primary" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Total Students</div>
            <h4 class="font-weight-bold text-dark mb-0 mt-1"><?= count($students) ?></h4>
        </div>
    </div>
    <div class="col-md-3 mb-2 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-success" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Active Accounts</div>
            <h4 class="font-weight-bold text-success mb-0 mt-1"><?= $totalActive ?></h4>
        </div>
    </div>
    <div class="col-md-3 mb-2 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-info" style="border-left-width: 5px !important;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Online Now</div>
                    <h4 class="font-weight-bold text-info mb-0 mt-1"><?= $totalOnline ?></h4>
                </div>
                <?php if ($totalOnline > 0): ?>
                    <span class="spinner-grow text-success" role="status" style="width: 24px; height: 24px;"></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-warning" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Class Attendance</div>
            <div class="mt-1">
                <a href="../attendance/index.php" class="btn btn-sm btn-outline-warning font-weight-bold rounded-pill">
                    <i class="fas fa-clipboard-check mr-1"></i> Open Register
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm border-0 mb-4 bg-light rounded-lg">
    <div class="card-body p-4">
        <form method="GET" class="form-row align-items-end">
            <?php if (is_super_admin()): ?>
            <div class="col-md-3 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Institute / Admin</label>
                <div class="input-group shadow-sm">
                    <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-university text-muted"></i></span></div>
                    <select name="admin_id" class="form-control custom-select bg-white border-0">
                        <option value="">All Institutes</option>
                        <?php foreach($all_admins as $adm): ?>
                            <option value="<?php echo $adm['id']; ?>" <?php echo $filter_admin == $adm['id'] ? 'selected' : ''; ?>>
                                <?php echo h(!empty($adm['institute_name']) ? $adm['institute_name'] : $adm['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            <div class="<?= is_super_admin() ? 'col-md-3' : 'col-md-4' ?> mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Class</label>
                <div class="input-group shadow-sm">
                    <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-layer-group text-muted"></i></span></div>
                    <select name="class_id" class="form-control custom-select bg-white border-0">
                        <option value="">All Classes</option>
                        <?php foreach($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $filter_class == $c['id'] ? 'selected' : ''; ?>>Class <?php echo h($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="<?= is_super_admin() ? 'col-md-3' : 'col-md-4' ?> mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Board</label>
                <div class="input-group shadow-sm">
                    <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-book text-muted"></i></span></div>
                    <select name="board_id" class="form-control custom-select bg-white border-0">
                        <option value="">All Boards</option>
                        <?php foreach($boards as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $filter_board == $b['id'] ? 'selected' : ''; ?>><?php echo h($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="<?= is_super_admin() ? 'col-md-1' : 'col-md-2' ?> mb-3 mb-md-0 d-flex flex-column justify-content-end">
                <button type="submit" class="btn btn-dark btn-block font-weight-bold shadow-sm rounded-pill h-100">Filter</button>
            </div>
            <?php if ($filter_class || $filter_board || $filter_admin): ?>
            <div class="<?= is_super_admin() ? 'col-md-2' : 'col-md-2' ?> mb-3 mb-md-0 d-flex flex-column justify-content-end">
                <a href="index.php" class="btn btn-outline-secondary btn-block font-weight-bold rounded-pill h-100 transition">Clear</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-0">
        <div class="table-responsive p-4">
            <table class="table table-hover table-bordered w-100" id="studentsTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th width="3%" class="text-center border-0 rounded-top-left">
                            <div class="custom-control custom-checkbox ml-auto mr-auto" style="width:16px;">
                                <input type="checkbox" class="custom-control-input" id="checkAll">
                                <label class="custom-control-label" for="checkAll"></label>
                            </div>
                        </th>
                        <th width="15%" class="border-0 px-4">Full Name</th>
                        <th width="12%" class="border-0">Username</th>
                        <?php if (is_super_admin()): ?>
                            <th width="12%" class="border-0">Institute</th>
                        <?php endif; ?>
                        <th width="10%" class="border-0">Class & Board</th>
                        <th width="14%" class="border-0 text-center">Password</th>
                        <th width="8%" class="border-0 text-center">Status</th>
                        <th width="20%" class="text-right border-0 rounded-top-right px-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $s): ?>
                    <tr>
                        <td class="text-center align-middle">
                            <div class="custom-control custom-checkbox mx-auto" style="width:16px;">
                                <input type="checkbox" name="id[]" value="<?php echo $s['id']; ?>" class="custom-control-input s-checkbox" id="chk<?php echo $s['id']; ?>">
                                <label class="custom-control-label" for="chk<?php echo $s['id']; ?>"></label>
                            </div>
                        </td>
                        <td class="align-middle font-weight-bold text-dark px-4">
    <div class="d-flex align-items-center justify-content-between">
        <span><?php echo h($s['full_name']); ?></span>
        <?php if (!empty($s['is_online_now'])): ?>
            <span class="badge badge-success px-2 py-1 shadow-xs ml-2 rounded-pill font-weight-bold" title="Active on <?php echo h($s['online_device'] ?: 'Web'); ?> (<?php echo h($s['online_browser'] ?: 'Browser'); ?>)">
                <span class="spinner-grow spinner-grow-sm mr-1" style="width: 6px; height: 6px;" role="status"></span>Online
            </span>
        <?php endif; ?>
    </div>
    <?php if (!empty($s['roll_no'])): ?>
        <small class="text-muted d-block font-weight-normal">Roll No: <?php echo h($s['roll_no']); ?></small>
    <?php endif; ?>
</td>
                        <td class="align-middle">
                            <span class="font-family-monospace font-weight-bold text-primary px-2 py-1 rounded bg-light border border-light"><?php echo h($s['username']); ?></span>
                        </td>
                        <?php if (is_super_admin()): ?>
                        <td class="align-middle">
                            <span class="badge badge-light border text-dark font-weight-bold">
                                <i class="fas fa-university text-primary mr-1"></i><?php echo h($s['institute_name'] ?? 'Main Campus'); ?>
                            </span>
                        </td>
                        <?php endif; ?>
                        <td class="align-middle">
                            <span class="d-block font-weight-bold text-dark">Class <?php echo h($s['class_name']); ?></span>
                            <span class="small text-muted border bg-light rounded px-1"><?php echo h($s['board_name']); ?></span>
                            <span class="d-block text-muted small mt-1" title="Enrollment Date: <?php echo date('d M Y', strtotime($s['enrollment_date'] ?? $s['created_at'])); ?>">
                                <i class="fas fa-calendar-alt text-primary mr-1"></i>Joined: <?php echo date('M Y', strtotime($s['enrollment_date'] ?? $s['created_at'])); ?>
                            </span>
                        </td>
                        <td class="align-middle px-3">
                            <div class="input-group input-group-sm w-100 shadow-sm rounded">
                                <input type="password" class="form-control bg-white font-weight-bold text-dark border-0 border-right text-center" value="<?php echo h($s['plain_password']); ?>" readonly>
                                <div class="input-group-append bg-white">
                                    <button class="btn btn-light toggle-pass border-0" type="button" tabindex="-1"><i class="fas fa-eye text-muted"></i></button>
                                </div>
                            </div>
                        </td>
                        <td class="align-middle text-center">
                            <?php if($s['is_active']): ?>
                                <span class="badge badge-success px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-check-circle mr-1"></i>Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary px-3 py-1 shadow-sm rounded-pill opacity-75"><i class="fas fa-ban mr-1"></i>Archived</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right align-middle text-nowrap px-4">
                            <form method="POST" action="reset_password.php" class="d-inline" onsubmit="return confirm('Are you sure you want to reset this student\'s password?');">
                                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-light text-info border shadow-sm mx-1 rounded-circle transition" data-toggle="tooltip" title="Reset Password">
                                    <i class="fas fa-recycle"></i>
                                </button>
                            </form>
                            
                            <button class="btn btn-sm btn-light text-warning border shadow-sm mx-1 rounded-circle edit-btn transition" 
                                data-id="<?php echo $s['id']; ?>" 
                                data-username="<?php echo h($s['username']); ?>"
                                data-name="<?php echo h($s['full_name']); ?>" 
                                data-email="<?php echo h($s['email'] ?? ''); ?>" 
                                data-phone="<?php echo h($s['phone'] ?? ''); ?>"
                                data-cid="<?php echo $s['class_id']; ?>" 
                                data-bid="<?php echo $s['board_id']; ?>" 
                                data-enrollment="<?php echo h(date('Y-m-d', strtotime($s['enrollment_date'] ?? $s['created_at']))); ?>" 
                                data-active="<?php echo $s['is_active']; ?>" 
                                data-admin-id="<?php echo $s['admin_id'] ?? ''; ?>"
                                data-toggle="tooltip" title="Edit Student">
                                <i class="fas fa-user-edit"></i>
                            </button>
                            
                            <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this student?');">
                                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-light text-danger border shadow-sm mx-1 rounded-circle transition <?php echo $s['enrolled_count'] > 0 ? 'opacity-50' : ''; ?>" data-toggle="tooltip" title="<?php echo $s['enrolled_count'] > 0 ? 'Enrolled exams exist. Archive mode activated.' : 'Delete'; ?>">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Edit Modal Overlay -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg rounded-lg">
      <div class="modal-header bg-warning border-0 pt-4 px-5 pb-3">
        <h5 class="modal-title font-weight-bold text-dark"><i class="fas fa-sliders-h mr-3 bg-white p-2 rounded-lg shadow-sm"></i>Edit Student</h5>
        <button type="button" class="close text-dark" data-dismiss="modal">&times;</button>
      </div>
      <form method="POST" action="edit.php">
          <div class="modal-body p-4 p-md-5 bg-light">
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            <input type="hidden" name="id" id="edit-id">
            
            <!-- Student Header Info Card -->
            <div class="d-flex align-items-center justify-content-between p-3 bg-white rounded shadow-sm mb-4">
                <div>
                    <span class="text-muted small text-uppercase font-weight-bold d-block">Username / Student ID</span>
                    <span class="font-family-monospace font-weight-bold text-primary h6 mb-0" id="edit-username">-</span>
                </div>
                <div class="custom-control custom-switch custom-control-lg">
                    <input type="checkbox" class="custom-control-input" id="edit-active" name="is_active" value="1">
                    <label class="custom-control-label font-weight-bold text-dark" style="padding-top:2px;" for="edit-active">Account Active</label>
                </div>
            </div>

            <div class="row">
                <div class="col-md-7 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Full Name <span class="text-danger">*</span></label>
                    <input type="text" id="edit-name" name="full_name" class="form-control font-weight-bold shadow-sm border-0" required>
                </div>
                <div class="col-md-5 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Phone Number</label>
                    <input type="text" id="edit-phone" name="phone" class="form-control shadow-sm border-0" placeholder="e.g. 9876543210">
                </div>
            </div>

            <div class="row">
                <div class="col-md-7 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Email Address</label>
                    <input type="email" id="edit-email" name="email" class="form-control shadow-sm border-0" placeholder="student@example.com">
                </div>
                <div class="col-md-5 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase"><i class="fas fa-calendar-alt text-primary mr-1"></i> Joining Date <span class="text-danger">*</span></label>
                    <input type="date" id="edit-enrollment" name="enrollment_date" class="form-control font-weight-bold shadow-sm border-0" required>
                    <small class="form-text text-muted">Fee ledger calculates starting from this month.</small>
                </div>
            </div>

            <div class="row pt-2 border-top">
                <div class="col-md-6 form-group mb-4 mt-3">
                    <label class="font-weight-bold text-muted small text-uppercase">Class <span class="text-danger">*</span></label>
                    <select id="edit-cid" name="class_id" class="form-control custom-select shadow-sm border-0" required>
                        <?php foreach($classes as $c): ?><option value="<?php echo $c['id']; ?>">Class <?php echo h($c['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 form-group mb-4 mt-3">
                    <label class="font-weight-bold text-muted small text-uppercase">Board <span class="text-danger">*</span></label>
                    <select id="edit-bid" name="board_id" class="form-control custom-select shadow-sm border-0" required>
                        <?php foreach($boards as $b): ?><option value="<?php echo $b['id']; ?>"><?php echo h($b['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (is_super_admin()): ?>
            <div class="form-group mb-4">
                <label class="font-weight-bold text-muted small text-uppercase"><i class="fas fa-university text-primary mr-1"></i> Assigned Institute</label>
                <select id="edit-admin-id" name="admin_id" class="form-control custom-select shadow-sm border-0">
                    <?php foreach($all_admins as $adm): ?>
                        <option value="<?php echo $adm['id']; ?>">
                            <?php echo h(!empty($adm['institute_name']) ? $adm['institute_name'] : $adm['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group mb-2 pt-2 border-top">
                <label class="font-weight-bold text-muted small text-uppercase"><i class="fas fa-key text-warning mr-1"></i> Reset Password (Optional)</label>
                <div class="input-group shadow-sm">
                    <input type="password" id="edit-password" name="new_password" class="form-control bg-white border-0" placeholder="Leave blank to retain current password" autocomplete="new-password">
                    <div class="input-group-append">
                        <button class="btn btn-light toggle-modal-pass border-0" type="button" tabindex="-1"><i class="fas fa-eye text-muted"></i></button>
                    </div>
                </div>
                <small class="form-text text-muted">Enter a new password only if you wish to reset it for this student.</small>
            </div>
          </div>
          <div class="modal-footer bg-white border-top-0 py-3 px-5 rounded-bottom">
            <button type="button" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4 transition" data-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning font-weight-bold rounded-pill px-5 shadow-sm transition"><i class="fas fa-save mr-2"></i>Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script>
function sendEmails() {
    if (confirm('Are you sure you want to send credentials to the selected students?')) {
        let form = $('<form>', { 'method': 'POST', 'action': 'email_credentials.php' });
        form.append($('<input>', { 'type': 'hidden', 'name': 'csrf_token', 'value': '<?php echo h(generate_csrf_token()); ?>' }));
        $('.s-checkbox:checked').each(function() {
            form.append($('<input>', { 'type': 'hidden', 'name': 'id[]', 'value': $(this).val() }));
        });
        $('body').append(form);
        form.submit();
    }
}

$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();
    $('#studentsTable').DataTable({
        "order": [], 
        "pageLength": 50,
        "columnDefs": [ { "orderable": false, "targets": 0 } ],
        "language": { "search": "<i class='fas fa-search text-muted mr-2'></i>" }
    });
    
    // Checkboxes
    function toggleBulkBtns() {
        if ($('.s-checkbox:checked').length > 0) {
            $('#bulkEmailBtn').prop('disabled', false).removeClass('btn-outline-info').addClass('btn-info text-white');
        } else {
            $('#bulkEmailBtn').prop('disabled', true).removeClass('btn-info text-white').addClass('btn-outline-info');
        }
    }
    $('#checkAll').click(function() {
        $('.s-checkbox').prop('checked', this.checked);
        toggleBulkBtns();
    });
    $(document).on('change', '.s-checkbox', toggleBulkBtns);
    
    // Toggle password in table (delegated so works across all pages & searches)
    $(document).on('click', '.toggle-pass', function() {
        let input = $(this).closest('.input-group').find('input');
        let icon = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash text-danger');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash text-danger').addClass('fa-eye');
        }
    });

    // Toggle password in edit modal
    $(document).on('click', '.toggle-modal-pass', function() {
        let input = $('#edit-password');
        let icon = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash text-danger');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash text-danger').addClass('fa-eye');
        }
    });
    
    // Edit Student Button Click - Delegated handler for DataTables pagination/search/sort
    $(document).on('click', '.edit-btn', function() {
        let btn = $(this);
        $('#edit-id').val(btn.data('id'));
        $('#edit-username').text(btn.data('username') || '-');
        $('#edit-name').val(btn.data('name'));
        $('#edit-email').val(btn.data('email') || '');
        $('#edit-phone').val(btn.data('phone') || '');
        $('#edit-cid').val(btn.data('cid'));
        $('#edit-bid').val(btn.data('bid'));
        $('#edit-enrollment').val(btn.data('enrollment'));
        $('#edit-active').prop('checked', btn.data('active') == 1);
        $('#edit-password').val('');
        
        let modalPassIcon = $('.toggle-modal-pass i');
        $('#edit-password').attr('type', 'password');
        modalPassIcon.removeClass('fa-eye-slash text-danger').addClass('fa-eye text-muted');

        if ($('#edit-admin-id').length && btn.data('admin-id')) {
            $('#edit-admin-id').val(btn.data('admin-id'));
        }
        $('#editModal').modal('show');
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

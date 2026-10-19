<?php
// admin/batches/index.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('batches');

$scoped_admin_id = is_super_admin() ? (!empty($_GET['admin_id']) ? (int)$_GET['admin_id'] : null) : get_effective_admin_id();

$filter_class   = $_GET['class_id'] ?? '';
$filter_board   = $_GET['board_id'] ?? '';
$filter_teacher = $_GET['teacher_id'] ?? '';
$filter_status  = $_GET['status'] ?? '';
$search         = trim($_GET['search'] ?? '');

// Filter options
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);
$boards  = $pdo->query("SELECT id, name FROM boards ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects
if ($scoped_admin_id) {
    $stmtSub = $pdo->prepare("SELECT id, name FROM subjects WHERE admin_id = ? OR admin_id = 1 ORDER BY name ASC");
    $stmtSub->execute([$scoped_admin_id]);
    $subjects = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
} else {
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch available teachers under this institute (sub-admin teachers + parent admin)
if ($scoped_admin_id) {
    $stmtT = $pdo->prepare("
        SELECT id, username, full_name, role, sub_role 
        FROM admins 
        WHERE (id = ? OR parent_admin_id = ?)
          AND (role = 'admin' OR sub_role = 'teacher')
          AND is_active = 1
        ORDER BY full_name ASC, username ASC
    ");
    $stmtT->execute([$scoped_admin_id, $scoped_admin_id]);
    $availableTeachers = $stmtT->fetchAll(PDO::FETCH_ASSOC);
} else {
    $availableTeachers = $pdo->query("
        SELECT id, username, full_name, role, sub_role 
        FROM admins 
        WHERE (role = 'admin' OR sub_role = 'teacher')
          AND is_active = 1
        ORDER BY full_name ASC, username ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Build Batches Query
$where = "1=1";
$params = [];

if ($scoped_admin_id) {
    $where .= " AND b.admin_id = ?";
    $params[] = $scoped_admin_id;
}

// If logged in as Teacher, default or enforce filter to their batches unless specified
if (is_teacher()) {
    $where .= " AND b.teacher_id = ?";
    $params[] = (int)$_SESSION['admin_id'];
} elseif (!empty($filter_teacher)) {
    $where .= " AND b.teacher_id = ?";
    $params[] = (int)$filter_teacher;
}

if ($filter_class !== '') {
    $where .= " AND b.class_id = ?";
    $params[] = (int)$filter_class;
}

if ($filter_board !== '') {
    $where .= " AND b.board_id = ?";
    $params[] = (int)$filter_board;
}

if ($filter_status === 'active') {
    $where .= " AND b.is_active = 1";
} elseif ($filter_status === 'inactive') {
    $where .= " AND b.is_active = 0";
}

if ($search !== '') {
    $where .= " AND (b.name LIKE ? OR b.schedule_desc LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$stmtBatches = $pdo->prepare("
    SELECT 
        b.*,
        c.name as class_name,
        brd.name as board_name,
        s.name as subject_name,
        t.username as teacher_username,
        t.full_name as teacher_fullname,
        t.sub_role as teacher_sub_role,
        (SELECT COUNT(*) FROM batch_students WHERE batch_id = b.id) as student_count
    FROM batches b
    LEFT JOIN classes c ON b.class_id = c.id
    LEFT JOIN boards brd ON b.board_id = brd.id
    LEFT JOIN subjects s ON b.subject_id = s.id
    LEFT JOIN admins t ON b.teacher_id = t.id
    WHERE {$where}
    ORDER BY b.id DESC
");
$stmtBatches->execute($params);
$batches = $stmtBatches->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalBatches = count($batches);
$activeBatches = 0;
$totalBatchStudents = 0;
$myBatchesCount = 0;

foreach ($batches as $bt) {
    if (!empty($bt['is_active'])) $activeBatches++;
    $totalBatchStudents += (int)$bt['student_count'];
    if ((int)$bt['teacher_id'] === (int)$_SESSION['admin_id']) $myBatchesCount++;
}
?>

<style>
.batch-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    border-radius: 12px;
}
.batch-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}
.badge-role {
    padding: 0.35rem 0.65rem;
    font-size: 0.78rem;
    font-weight: 700;
    border-radius: 999px;
}
.teacher-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #e0f2fe;
    color: #0284c7;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 0.85rem;
}
.student-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.75rem;
    font-weight: 600;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-layer-group text-primary mr-2"></i>Teaching Batches & Student Groups
        </h2>
        <p class="text-muted mb-0 small">
            Organize enrolled students into subject-specific classes and assigned batches managed by Teachers & Faculty.
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap">
        <?php if (can_manage_sub_admins()): ?>
        <a href="<?php echo BASE_URL; ?>/admin/sub_admins/index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2">
            <i class="fas fa-user-shield mr-1"></i>Staff & Sub-Admins
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4 mb-2" onclick="openCreateBatchModal()">
            <i class="fas fa-plus-circle mr-1"></i>Create New Batch
        </button>
    </div>
</div>

<div id="ajaxAlertContainer"></div>

<!-- KPI Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-layer-group fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Total Batches</div>
                    <h3 class="font-weight-bold text-dark mb-0"><?= $totalBatches ?></h3>
                    <span class="small text-muted"><?= $activeBatches ?> Active &bull; <?= ($totalBatches - $activeBatches) ?> Archived</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-user-graduate fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Batch Enrolments</div>
                    <h3 class="font-weight-bold text-success mb-0"><?= $totalBatchStudents ?></h3>
                    <span class="small text-muted">Across all active batches</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-chalkboard-teacher fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Assigned Faculty</div>
                    <h3 class="font-weight-bold text-info mb-0">
                        <?= is_teacher() ? $myBatchesCount : count($availableTeachers) ?>
                    </h3>
                    <span class="small text-muted"><?= is_teacher() ? 'Batches assigned to you' : 'Available instructors' ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-calendar-alt fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Batch Coverage</div>
                    <h3 class="font-weight-bold text-warning mb-0">
                        <?= $totalBatches > 0 ? round(($activeBatches / $totalBatches) * 100) : 0 ?>%
                    </h3>
                    <span class="small text-muted">Active schedule rate</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-light">
    <div class="card-body p-3">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-3 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Search Batches</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
                    </div>
                    <input type="text" name="search" class="form-control bg-white border-left-0" placeholder="Batch name or schedule..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Class</label>
                <select name="class_id" class="form-control bg-white custom-select">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($filter_class == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Board</label>
                <select name="board_id" class="form-control bg-white custom-select">
                    <option value="">All Boards</option>
                    <?php foreach ($boards as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($filter_board == $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!is_teacher()): ?>
            <div class="col-md-3 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Assigned Teacher</label>
                <select name="teacher_id" class="form-control bg-white custom-select">
                    <option value="">All Teachers</option>
                    <?php foreach ($availableTeachers as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($filter_teacher == $t['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['full_name'] ?: $t['username']) ?> (<?= ucfirst($t['sub_role'] ?? $t['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Status</label>
                <select name="status" class="form-control bg-white custom-select">
                    <option value="">All Statuses</option>
                    <option value="active" <?= ($filter_status === 'active') ? 'selected' : '' ?>>Active Only</option>
                    <option value="inactive" <?= ($filter_status === 'inactive') ? 'selected' : '' ?>>Inactive Only</option>
                </select>
            </div>

            <div class="col-md-auto mb-2">
                <button type="submit" class="btn btn-dark font-weight-bold px-3">
                    <i class="fas fa-filter mr-1"></i>Apply
                </button>
                <a href="index.php" class="btn btn-outline-secondary font-weight-bold ml-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Batches Table -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-list text-primary mr-2"></i>Batches Directory (<?= count($batches) ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 28%;">Batch Details</th>
                    <th style="width: 15%;">Class & Board</th>
                    <th style="width: 15%;">Subject</th>
                    <th style="width: 18%;">Assigned Teacher</th>
                    <th style="width: 12%; text-align: center;">Enrolled Students</th>
                    <th style="width: 12%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($batches)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="fas fa-layer-group fa-3x mb-3 text-secondary opacity-50"></i>
                        <p class="mb-0 font-weight-bold">No batches found matching the selected criteria.</p>
                        <small>Click "Create New Batch" to set up your first class cohort.</small>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($batches as $b): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <div>
                                    <div class="font-weight-bold text-dark mb-1 d-flex align-items-center">
                                        <?= htmlspecialchars($b['name']) ?>
                                        <?php if (!empty($b['is_active'])): ?>
                                            <span class="badge badge-success ml-2 font-weight-normal px-2 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary ml-2 font-weight-normal px-2 py-1">Archived</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($b['schedule_desc'])): ?>
                                        <div class="small text-muted">
                                            <i class="far fa-clock mr-1 text-primary"></i><?= htmlspecialchars($b['schedule_desc']) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="small text-muted italic">Schedule not specified</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <td>
                            <div>
                                <span class="badge badge-info px-2 py-1 mb-1">
                                    <i class="fas fa-graduation-cap mr-1"></i><?= htmlspecialchars($b['class_name'] ?: 'All Classes') ?>
                                </span>
                                <?php if (!empty($b['board_name'])): ?>
                                    <br><span class="badge badge-light border text-dark px-2 py-1">
                                        <?= htmlspecialchars($b['board_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td>
                            <?php if (!empty($b['subject_name'])): ?>
                                <span class="font-weight-bold text-dark">
                                    <i class="fas fa-book text-muted mr-1"></i><?= htmlspecialchars($b['subject_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">All Subjects / General</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if (!empty($b['teacher_id'])): ?>
                                <div class="d-flex align-items-center">
                                    <div class="teacher-avatar mr-2">
                                        <?= strtoupper(substr($b['teacher_fullname'] ?: $b['teacher_username'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold text-dark small mb-0">
                                            <?= htmlspecialchars($b['teacher_fullname'] ?: $b['teacher_username']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.72rem;">
                                            @<?= htmlspecialchars($b['teacher_username']) ?> &bull; <?= ucfirst($b['teacher_sub_role'] ?? 'Staff') ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="text-muted small font-italic">Unassigned</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 font-weight-bold shadow-sm" onclick="openManageStudentsModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>', <?= (int)$b['class_id'] ?>)">
                                <i class="fas fa-user-friends mr-1"></i><?= (int)$b['student_count'] ?> Students
                            </button>
                        </td>

                        <td class="text-right">
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" title="Manage Students" onclick="openManageStudentsModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>', <?= (int)$b['class_id'] ?>)">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                                <button type="button" class="btn btn-outline-info" title="Edit Batch" onclick='openEditBatchModal(<?= json_encode($b) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="btn btn-outline-danger" title="Delete Batch" onclick="confirmDeleteBatch(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: CREATE BATCH                        -->
<!-- ========================================== -->
<div class="modal fade" id="createBatchModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-layer-group mr-2"></i>Create New Teaching Batch
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="createBatchForm" onsubmit="handleCreateBatch(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <div class="modal-body p-4">
                    <?php if (is_super_admin()): ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Target Institute / Administrator <span class="text-danger">*</span></label>
                        <select name="admin_id" class="form-control custom-select" required>
                            <?php 
                            $allAdminsList = $pdo->query("SELECT id, username, institute_name FROM admins WHERE role != 'sub_admin' ORDER BY institute_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($allAdminsList as $admOption): 
                                $sel = ($scoped_admin_id && $scoped_admin_id == $admOption['id']) || (!$scoped_admin_id && $admOption['id'] == 1) ? 'selected' : '';
                            ?>
                                <option value="<?= $admOption['id'] ?>" <?= $sel ?>>
                                    <?= htmlspecialchars($admOption['institute_name']) ?> (@<?= htmlspecialchars($admOption['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Batch Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Class 10 Physics Morning Batch A" required maxlength="150">
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Academic Class</label>
                            <select name="class_id" class="form-control custom-select">
                                <option value="">Select Class...</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Educational Board</label>
                            <select name="board_id" class="form-control custom-select">
                                <option value="">Select Board...</option>
                                <?php foreach ($boards as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Subject</label>
                        <select name="subject_id" class="form-control custom-select">
                            <option value="">Select Subject (Optional)...</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Assigned Teacher / Faculty</label>
                        <?php if (is_teacher()): ?>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($_SESSION['full_name'] ?: $_SESSION['admin_user']) ?> (Assigned to You)" readonly>
                            <input type="hidden" name="teacher_id" value="<?= (int)$_SESSION['admin_id'] ?>">
                        <?php else: ?>
                            <select name="teacher_id" class="form-control custom-select">
                                <option value="">Select Assigned Teacher...</option>
                                <?php foreach ($availableTeachers as $t): ?>
                                    <option value="<?= $t['id'] ?>">
                                        <?= htmlspecialchars($t['full_name'] ?: $t['username']) ?> (<?= ucfirst($t['sub_role'] ?? $t['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Schedule Description</label>
                        <input type="text" name="schedule_desc" class="form-control" placeholder="e.g. Mon, Wed, Fri 09:00 AM - 10:30 AM" maxlength="255">
                    </div>

                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="createBatchActive" name="is_active" value="1" checked>
                        <label class="custom-control-label font-weight-bold small text-dark" for="createBatchActive">
                            Batch is Active and accepting student enrolments
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="createBatchSubmitBtn">
                        <i class="fas fa-check mr-1"></i>Create Batch
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: EDIT BATCH                          -->
<!-- ========================================== -->
<div class="modal fade" id="editBatchModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-edit mr-2"></i>Edit Teaching Batch
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="editBatchForm" onsubmit="handleEditBatch(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="batch_id" id="editBatchId">
                <div class="modal-body p-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Batch Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editBatchName" class="form-control" required maxlength="150">
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Academic Class</label>
                            <select name="class_id" id="editBatchClassId" class="form-control custom-select">
                                <option value="">Select Class...</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark small mb-1">Educational Board</label>
                            <select name="board_id" id="editBatchBoardId" class="form-control custom-select">
                                <option value="">Select Board...</option>
                                <?php foreach ($boards as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Subject</label>
                        <select name="subject_id" id="editBatchSubjectId" class="form-control custom-select">
                            <option value="">Select Subject (Optional)...</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Assigned Teacher / Faculty</label>
                        <?php if (is_teacher()): ?>
                            <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($_SESSION['full_name'] ?: $_SESSION['admin_user']) ?> (Assigned to You)" readonly>
                            <input type="hidden" name="teacher_id" value="<?= (int)$_SESSION['admin_id'] ?>">
                        <?php else: ?>
                            <select name="teacher_id" id="editBatchTeacherId" class="form-control custom-select">
                                <option value="">Select Assigned Teacher...</option>
                                <?php foreach ($availableTeachers as $t): ?>
                                    <option value="<?= $t['id'] ?>">
                                        <?= htmlspecialchars($t['full_name'] ?: $t['username']) ?> (<?= ucfirst($t['sub_role'] ?? $t['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark small mb-1">Schedule Description</label>
                        <input type="text" name="schedule_desc" id="editBatchScheduleDesc" class="form-control" maxlength="255">
                    </div>

                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="editBatchActive" name="is_active" value="1">
                        <label class="custom-control-label font-weight-bold small text-dark" for="editBatchActive">
                            Batch is Active and accepting student enrolments
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="editBatchSubmitBtn">
                        <i class="fas fa-save mr-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: MANAGE BATCH STUDENTS               -->
<!-- ========================================== -->
<div class="modal fade" id="manageStudentsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-info text-white py-3">
                <div>
                    <h5 class="modal-title font-weight-bold text-white mb-0">
                        <i class="fas fa-users-cog mr-2"></i>Manage Batch Students: <span id="batchModalName"></span>
                    </h5>
                    <small class="text-white-50">Assign enrolled students to this cohort or remove existing members.</small>
                </div>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="currentBatchId">
                <input type="hidden" id="currentBatchClassId">

                <!-- Tabs -->
                <ul class="nav nav-pills mb-3" id="batchStudentsTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active font-weight-bold" id="assigned-tab" data-toggle="pill" href="#assignedTabPane" role="tab">
                            <i class="fas fa-user-check mr-1"></i>Enrolled in Batch (<span id="assignedCountBadge">0</span>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link font-weight-bold" id="available-tab" data-toggle="pill" href="#availableTabPane" role="tab">
                            <i class="fas fa-user-plus mr-1"></i>Add Students (<span id="availableCountBadge">0</span>)
                        </a>
                    </li>
                </ul>

                <div class="tab-content" id="batchStudentsTabContent">
                    <!-- Tab 1: Assigned Students -->
                    <div class="tab-pane fade show active" id="assignedTabPane" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <input type="text" id="assignedSearchInput" class="form-control form-control-sm w-50" placeholder="Filter enrolled batch students..." onkeyup="filterAssignedList()">
                            <button type="button" class="btn btn-sm btn-outline-info font-weight-bold" onclick="refreshBatchStudents()">
                                <i class="fas fa-sync-alt mr-1"></i>Refresh
                            </button>
                        </div>
                        <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                            <table class="table table-sm table-hover align-middle mb-0" id="assignedTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Username / Email</th>
                                        <th>Class / Board</th>
                                        <th>Joined Batch</th>
                                        <th style="width: 10%; text-align: center;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="assignedTableBody">
                                    <!-- Populated via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Tab 2: Available Students to Add -->
                    <div class="tab-pane fade" id="availableTabPane" role="tabpanel">
                        <div class="card bg-light border-0 p-3 mb-3">
                            <div class="row align-items-center">
                                <div class="col-md-6 mb-2 mb-md-0">
                                    <input type="text" id="availableSearchInput" class="form-control form-control-sm" placeholder="Search candidate students..." onkeyup="filterAvailableList()">
                                </div>
                                <div class="col-md-3 mb-2 mb-md-0">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="showAllClassesCheck" onchange="refreshBatchStudents()">
                                        <label class="custom-control-label small font-weight-bold text-dark" for="showAllClassesCheck">
                                            Show all classes
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3 text-right">
                                    <button type="button" class="btn btn-sm btn-success font-weight-bold px-3 shadow-sm" id="bulkAddBtn" onclick="handleAddSelectedStudents()" disabled>
                                        <i class="fas fa-plus mr-1"></i>Add Selected (<span id="selectedCountSpan">0</span>)
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
                            <table class="table table-sm table-hover align-middle mb-0" id="availableTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 5%; text-align: center;">
                                            <input type="checkbox" id="selectAllAvailable" onchange="toggleSelectAllAvailable(this)">
                                        </th>
                                        <th>Student Name</th>
                                        <th>Username</th>
                                        <th>Class / Board</th>
                                        <th style="width: 15%; text-align: center;">Direct Action</th>
                                    </tr>
                                </thead>
                                <tbody id="availableTableBody">
                                    <!-- Populated via JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= generate_csrf_token() ?>';
let currentAssignedList = [];
let currentAvailableList = [];

function showAlert(type, msg) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>${msg}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    document.getElementById('ajaxAlertContainer').innerHTML = alertHtml;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openCreateBatchModal() {
    document.getElementById('createBatchForm').reset();
    $('#createBatchModal').modal('show');
}

function handleCreateBatch(e) {
    e.preventDefault();
    const btn = document.getElementById('createBatchSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Creating...';

    const formData = new FormData(e.target);

    fetch('<?= BASE_URL ?>/admin/batches/create_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>Create Batch';
        if (data.success) {
            $('#createBatchModal').modal('hide');
            showAlert('success', data.message);
            setTimeout(() => location.reload(), 1200);
        } else {
            alert(data.error || 'Failed to create batch.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check mr-1"></i>Create Batch';
        alert('An unexpected error occurred: ' + err);
    });
}

function openEditBatchModal(batch) {
    document.getElementById('editBatchId').value = batch.id;
    document.getElementById('editBatchName').value = batch.name;
    document.getElementById('editBatchClassId').value = batch.class_id || '';
    document.getElementById('editBatchBoardId').value = batch.board_id || '';
    document.getElementById('editBatchSubjectId').value = batch.subject_id || '';
    if (document.getElementById('editBatchTeacherId')) {
        document.getElementById('editBatchTeacherId').value = batch.teacher_id || '';
    }
    document.getElementById('editBatchScheduleDesc').value = batch.schedule_desc || '';
    document.getElementById('editBatchActive').checked = parseInt(batch.is_active) === 1;

    $('#editBatchModal').modal('show');
}

function handleEditBatch(e) {
    e.preventDefault();
    const btn = document.getElementById('editBatchSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Saving...';

    const formData = new FormData(e.target);

    fetch('<?= BASE_URL ?>/admin/batches/update_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1"></i>Save Changes';
        if (data.success) {
            $('#editBatchModal').modal('hide');
            showAlert('success', data.message);
            setTimeout(() => location.reload(), 1200);
        } else {
            alert(data.error || 'Failed to update batch.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1"></i>Save Changes';
        alert('An unexpected error occurred: ' + err);
    });
}

function confirmDeleteBatch(batchId, batchName) {
    if (!confirm(`Are you sure you want to permanently delete batch "${batchName}"?\nAll student assignments inside this batch will be cleared.`)) {
        return;
    }

    const formData = new FormData();
    formData.append('batch_id', batchId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('<?= BASE_URL ?>/admin/batches/delete_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            setTimeout(() => location.reload(), 1200);
        } else {
            alert(data.error || 'Failed to delete batch.');
        }
    })
    .catch(err => alert('An unexpected error occurred: ' + err));
}

// -------------------------------------------------------------
// MANAGE BATCH STUDENTS AJAX MODAL
// -------------------------------------------------------------
function openManageStudentsModal(batchId, batchName, classId) {
    document.getElementById('currentBatchId').value = batchId;
    document.getElementById('currentBatchClassId').value = classId || 0;
    document.getElementById('batchModalName').textContent = batchName;
    document.getElementById('assignedSearchInput').value = '';
    document.getElementById('availableSearchInput').value = '';
    document.getElementById('showAllClassesCheck').checked = false;

    $('#manageStudentsModal').modal('show');
    refreshBatchStudents();
}

function refreshBatchStudents() {
    const batchId = document.getElementById('currentBatchId').value;
    const showAll = document.getElementById('showAllClassesCheck').checked ? '1' : '';

    const tbodyAssigned = document.getElementById('assignedTableBody');
    const tbodyAvail = document.getElementById('availableTableBody');
    tbodyAssigned.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Loading students...</td></tr>';
    tbodyAvail.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin mr-2"></i>Loading candidates...</td></tr>';

    fetch(`<?= BASE_URL ?>/admin/batches/students_ajax.php?action=list&batch_id=${batchId}&all_classes=${showAll}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            currentAssignedList = data.assigned || [];
            currentAvailableList = data.available || [];
            renderAssignedList(currentAssignedList);
            renderAvailableList(currentAvailableList);
        } else {
            alert(data.error || 'Failed to load students.');
        }
    })
    .catch(err => alert('Error loading batch students: ' + err));
}

function renderAssignedList(list) {
    const tbody = document.getElementById('assignedTableBody');
    document.getElementById('assignedCountBadge').textContent = list.length;

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No students enrolled in this batch yet. Click "Add Students" tab to enrol.</td></tr>';
        return;
    }

    let html = '';
    list.forEach(st => {
        html += `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="student-avatar mr-2">${(st.full_name || 'S').charAt(0).toUpperCase()}</div>
                        <span class="font-weight-bold text-dark">${escapeHtml(st.full_name)}</span>
                    </div>
                </td>
                <td>
                    <span class="text-dark small">@${escapeHtml(st.username)}</span>
                    ${st.email ? `<br><small class="text-muted">${escapeHtml(st.email)}</small>` : ''}
                </td>
                <td>
                    <span class="badge badge-info px-2 py-1">${escapeHtml(st.class_name || 'N/A')}</span>
                    ${st.board_name ? `<span class="badge badge-light border text-dark px-2 py-1">${escapeHtml(st.board_name)}</span>` : ''}
                </td>
                <td class="small text-muted">
                    ${st.joined_at ? escapeHtml(st.joined_at.substring(0, 10)) : 'Recently'}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Remove from Batch" onclick="removeStudentFromBatch(${st.student_id}, '${escapeQuotes(st.full_name)}')">
                        <i class="fas fa-times"></i>
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function filterAssignedList() {
    const q = document.getElementById('assignedSearchInput').value.toLowerCase().trim();
    if (!q) {
        renderAssignedList(currentAssignedList);
        return;
    }
    const filtered = currentAssignedList.filter(s => 
        (s.full_name && s.full_name.toLowerCase().includes(q)) ||
        (s.username && s.username.toLowerCase().includes(q)) ||
        (s.email && s.email.toLowerCase().includes(q))
    );
    renderAssignedList(filtered);
}

function removeStudentFromBatch(studentId, studentName) {
    if (!confirm(`Remove "${studentName}" from this batch?`)) return;

    const batchId = document.getElementById('currentBatchId').value;
    const formData = new FormData();
    formData.append('action', 'remove');
    formData.append('batch_id', batchId);
    formData.append('student_id', studentId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('<?= BASE_URL ?>/admin/batches/students_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            refreshBatchStudents();
        } else {
            alert(data.error || 'Failed to remove student.');
        }
    })
    .catch(err => alert('Error removing student: ' + err));
}

function renderAvailableList(list) {
    const tbody = document.getElementById('availableTableBody');
    document.getElementById('availableCountBadge').textContent = list.length;
    document.getElementById('selectAllAvailable').checked = false;
    updateBulkAddButton();

    if (list.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted">No candidate students available to add. Check "Show all classes" or register more students.</td></tr>';
        return;
    }

    let html = '';
    list.forEach(st => {
        html += `
            <tr>
                <td class="text-center">
                    <input type="checkbox" class="available-checkbox" value="${st.student_id}" onchange="updateBulkAddButton()">
                </td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="student-avatar mr-2">${(st.full_name || 'S').charAt(0).toUpperCase()}</div>
                        <span class="font-weight-bold text-dark">${escapeHtml(st.full_name)}</span>
                    </div>
                </td>
                <td><small class="text-dark">@${escapeHtml(st.username)}</small></td>
                <td>
                    <span class="badge badge-info px-2 py-1">${escapeHtml(st.class_name || 'N/A')}</span>
                    ${st.board_name ? `<span class="badge badge-light border text-dark px-2 py-1">${escapeHtml(st.board_name)}</span>` : ''}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-success font-weight-bold py-0 px-2" onclick="addSingleStudentToBatch(${st.student_id})">
                        <i class="fas fa-plus mr-1"></i>Add
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function filterAvailableList() {
    const q = document.getElementById('availableSearchInput').value.toLowerCase().trim();
    if (!q) {
        renderAvailableList(currentAvailableList);
        return;
    }
    const filtered = currentAvailableList.filter(s => 
        (s.full_name && s.full_name.toLowerCase().includes(q)) ||
        (s.username && s.username.toLowerCase().includes(q))
    );
    renderAvailableList(filtered);
}

function toggleSelectAllAvailable(master) {
    const boxes = document.querySelectorAll('.available-checkbox');
    boxes.forEach(cb => cb.checked = master.checked);
    updateBulkAddButton();
}

function updateBulkAddButton() {
    const checked = document.querySelectorAll('.available-checkbox:checked');
    const btn = document.getElementById('bulkAddBtn');
    const span = document.getElementById('selectedCountSpan');
    span.textContent = checked.length;
    btn.disabled = checked.length === 0;
}

function addSingleStudentToBatch(studentId) {
    const batchId = document.getElementById('currentBatchId').value;
    const formData = new FormData();
    formData.append('action', 'add');
    formData.append('batch_id', batchId);
    formData.append('student_ids[]', studentId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('<?= BASE_URL ?>/admin/batches/students_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            refreshBatchStudents();
        } else {
            alert(data.error || 'Failed to add student.');
        }
    })
    .catch(err => alert('Error adding student: ' + err));
}

function handleAddSelectedStudents() {
    const checked = document.querySelectorAll('.available-checkbox:checked');
    if (checked.length === 0) return;

    const batchId = document.getElementById('currentBatchId').value;
    const formData = new FormData();
    formData.append('action', 'add');
    formData.append('batch_id', batchId);
    formData.append('csrf_token', CSRF_TOKEN);

    checked.forEach(cb => formData.append('student_ids[]', cb.value));

    const btn = document.getElementById('bulkAddBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Adding...';

    fetch('<?= BASE_URL ?>/admin/batches/students_ajax.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus mr-1"></i>Add Selected (<span id="selectedCountSpan">0</span>)';
        if (data.success) {
            refreshBatchStudents();
        } else {
            alert(data.error || 'Failed to add students.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-plus mr-1"></i>Add Selected (<span id="selectedCountSpan">0</span>)';
        alert('Error adding students: ' + err);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeQuotes(str) {
    if (!str) return '';
    return String(str).replace(/'/g, "\\'");
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

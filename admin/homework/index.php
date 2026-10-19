<?php
// admin/homework/index.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_admin_feature('homework');

$scoped_admin_id = is_super_admin() ? (!empty($_GET['admin_id']) ? (int)$_GET['admin_id'] : null) : get_effective_admin_id();

$filter_status  = $_GET['status'] ?? 'active';
$filter_class   = $_GET['class_id'] ?? '';
$filter_batch   = $_GET['batch_id'] ?? '';
$filter_subject = $_GET['subject_id'] ?? '';
$search         = trim($_GET['search'] ?? '');

// Filter data
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects
if ($scoped_admin_id) {
    $stmtSub = $pdo->prepare("SELECT id, name FROM subjects WHERE admin_id = ? OR admin_id = 1 ORDER BY name ASC");
    $stmtSub->execute([$scoped_admin_id]);
    $subjects = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
} else {
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch batches
if ($scoped_admin_id) {
    if (is_teacher()) {
        $stmtB = $pdo->prepare("SELECT id, name FROM batches WHERE admin_id = ? AND teacher_id = ? AND is_active = 1 ORDER BY name ASC");
        $stmtB->execute([$scoped_admin_id, (int)$_SESSION['admin_id']]);
    } else {
        $stmtB = $pdo->prepare("SELECT id, name FROM batches WHERE admin_id = ? AND is_active = 1 ORDER BY name ASC");
        $stmtB->execute([$scoped_admin_id]);
    }
    $batches = $stmtB->fetchAll(PDO::FETCH_ASSOC);
} else {
    $batches = $pdo->query("SELECT id, name FROM batches ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Build query
$where = "1=1";
$params = [];

if ($scoped_admin_id) {
    $where .= " AND hw.admin_id = ?";
    $params[] = $scoped_admin_id;
}

if (is_teacher()) {
    $where .= " AND (hw.created_by = ? OR (hw.batch_id IS NOT NULL AND hw.batch_id IN (SELECT id FROM batches WHERE teacher_id = ?)))";
    $params[] = (int)$_SESSION['admin_id'];
    $params[] = (int)$_SESSION['admin_id'];
}

if ($filter_class !== '') {
    $where .= " AND hw.class_id = ?";
    $params[] = (int)$filter_class;
}

if ($filter_batch !== '') {
    $where .= " AND hw.batch_id = ?";
    $params[] = (int)$filter_batch;
}

if ($filter_subject !== '') {
    $where .= " AND hw.subject_id = ?";
    $params[] = (int)$filter_subject;
}

$now = date('Y-m-d H:i:s');
if ($filter_status === 'active') {
    $where .= " AND hw.due_date >= ? AND hw.is_active = 1";
    $params[] = $now;
} elseif ($filter_status === 'overdue') {
    $where .= " AND hw.due_date < ? AND hw.is_active = 1";
    $params[] = $now;
} elseif ($filter_status === 'archived') {
    $where .= " AND hw.is_active = 0";
}

if (!empty($search)) {
    $where .= " AND (hw.title LIKE ? OR hw.description LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$stmtHw = $pdo->prepare("
    SELECT 
        hw.*,
        c.name as class_name,
        b.name as board_name,
        sub.name as subject_name,
        bt.name as batch_name,
        cr.username as creator_username,
        cr.full_name as creator_fullname,
        cr.sub_role as creator_sub_role,
        (SELECT COUNT(*) FROM homework_submissions WHERE homework_id = hw.id) as submission_count,
        (SELECT COUNT(*) FROM homework_submissions WHERE homework_id = hw.id AND status = 'reviewed') as reviewed_count
    FROM homework hw
    LEFT JOIN classes c ON hw.class_id = c.id
    LEFT JOIN boards b ON hw.board_id = b.id
    LEFT JOIN subjects sub ON hw.subject_id = sub.id
    LEFT JOIN batches bt ON hw.batch_id = bt.id
    LEFT JOIN admins cr ON hw.created_by = cr.id
    WHERE {$where}
    ORDER BY hw.due_date ASC, hw.id DESC
");
$stmtHw->execute($params);
$homeworkList = $stmtHw->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall summary metrics
$metricWhere = $scoped_admin_id ? "admin_id = {$scoped_admin_id}" : "1=1";
$totalHw = (int)$pdo->query("SELECT COUNT(*) FROM homework WHERE {$metricWhere}")->fetchColumn();
$activeHw = (int)$pdo->query("SELECT COUNT(*) FROM homework WHERE {$metricWhere} AND due_date >= NOW() AND is_active = 1")->fetchColumn();
$totalSubmissions = (int)$pdo->query("SELECT COUNT(*) FROM homework_submissions hs JOIN homework hw ON hs.homework_id = hw.id WHERE {$metricWhere}")->fetchColumn();
$totalReviewed = (int)$pdo->query("SELECT COUNT(*) FROM homework_submissions hs JOIN homework hw ON hs.homework_id = hw.id WHERE {$metricWhere} AND hs.status = 'reviewed'")->fetchColumn();
?>

<style>
.badge-tech {
    padding: 0.25rem 0.55rem;
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 6px;
    margin-right: 4px;
}
.badge-code { background: #1e293b; color: #38bdf8; border: 1px solid #334155; }
.badge-latex { background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
.badge-pic { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-book-reader text-primary mr-2"></i>Homework & Assignments Hub
        </h2>
        <p class="text-muted mb-0 small">
            Assign rich homework to cohorts with text, diagrams & pictures, LaTeX formulas, and programming questions. Track submissions and grade student solutions.
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap">
        <a href="create.php" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4 mb-2">
            <i class="fas fa-plus-circle mr-1"></i>Assign New Homework
        </a>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($_SESSION['flash_success']) ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

<div id="ajaxAlertContainer"></div>

<!-- KPI Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-tasks fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Total Homework</div>
                    <h3 class="font-weight-bold text-dark mb-0"><?= $totalHw ?></h3>
                    <span class="small text-muted">Created assignments</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-hourglass-half fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Active & Open</div>
                    <h3 class="font-weight-bold text-success mb-0"><?= $activeHw ?></h3>
                    <span class="small text-muted">Currently accepting work</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-file-upload fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Submissions Received</div>
                    <h3 class="font-weight-bold text-info mb-0"><?= $totalSubmissions ?></h3>
                    <span class="small text-muted">From enrolled students</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-check-double fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Reviewed & Graded</div>
                    <h3 class="font-weight-bold text-warning mb-0"><?= $totalReviewed ?></h3>
                    <span class="small text-muted"><?= ($totalSubmissions > 0) ? round(($totalReviewed / $totalSubmissions) * 100) : 0 ?>% grading rate</span>
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
                <label class="small font-weight-bold text-muted mb-1">Search Assignments</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
                    </div>
                    <input type="text" name="search" class="form-control bg-white border-left-0" placeholder="Assignment title or keywords..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Status</label>
                <select name="status" class="form-control bg-white custom-select">
                    <option value="active" <?= ($filter_status === 'active') ? 'selected' : '' ?>>Active / Open</option>
                    <option value="overdue" <?= ($filter_status === 'overdue') ? 'selected' : '' ?>>Past Due / Closed</option>
                    <option value="all" <?= ($filter_status === 'all') ? 'selected' : '' ?>>All Statuses</option>
                </select>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <label class="small font-weight-bold text-muted mb-1">Teaching Batch</label>
                <select name="batch_id" class="form-control bg-white custom-select">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= ($filter_batch == $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
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
                <label class="small font-weight-bold text-muted mb-1">Subject</label>
                <select name="subject_id" class="form-control bg-white custom-select">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($filter_subject == $s['id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-auto mb-2">
                <button type="submit" class="btn btn-dark font-weight-bold px-3">
                    <i class="fas fa-filter mr-1"></i>Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary font-weight-bold ml-1">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Homework Assignments Directory Table -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-list text-primary mr-2"></i>Assignments Directory (<?= count($homeworkList) ?>)
        </h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width: 30%;">Homework & Target</th>
                    <th style="width: 22%;">Date & Time Schedule</th>
                    <th style="width: 14%;">Subject & Format</th>
                    <th style="width: 14%; text-align: center;">Submissions</th>
                    <th style="width: 20%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($homeworkList)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="fas fa-book-reader fa-3x mb-3 text-secondary opacity-50"></i>
                        <p class="mb-0 font-weight-bold">No homework assignments found.</p>
                        <small>Click "Assign New Homework" to distribute assignments to your students.</small>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($homeworkList as $hw): 
                        $isOverdue = (strtotime($hw['due_date']) < time());
                        $creatorName = $hw['creator_fullname'] ?: $hw['creator_username'];
                    ?>
                    <tr>
                        <td>
                            <div>
                                <h6 class="font-weight-bold text-dark mb-1">
                                    <a href="submissions.php?id=<?= $hw['id'] ?>" class="text-dark text-decoration-none">
                                        <?= htmlspecialchars($hw['title']) ?>
                                    </a>
                                </h6>
                                <div class="small text-muted mb-2">
                                    <?php if (!empty($hw['batch_name'])): ?>
                                        <span class="badge badge-primary px-2 py-1"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($hw['batch_name']) ?></span>
                                    <?php elseif (!empty($hw['class_name'])): ?>
                                        <span class="badge badge-info px-2 py-1"><i class="fas fa-graduation-cap mr-1"></i>Class <?= htmlspecialchars($hw['class_name']) ?></span>
                                        <?php if (!empty($hw['board_name'])): ?>
                                            <span class="badge badge-light border text-dark px-2 py-1"><?= htmlspecialchars($hw['board_name']) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-2 py-1"><i class="fas fa-university mr-1"></i>All Students</span>
                                    <?php endif; ?>

                                    <span class="ml-2 text-muted">By: <?= htmlspecialchars($creatorName) ?></span>
                                </div>

                                <!-- Feature Pills -->
                                <div>
                                    <?php if (!empty($hw['programming_lang']) && $hw['programming_lang'] !== 'none'): ?>
                                        <span class="badge-tech badge-code"><i class="fas fa-code mr-1"></i><?= ucfirst($hw['programming_lang']) ?> Code</span>
                                    <?php endif; ?>
                                    <?php if (!empty($hw['attachment_path'])): ?>
                                        <span class="badge-tech badge-pic"><i class="fas fa-paperclip mr-1"></i>Attachment</span>
                                    <?php endif; ?>
                                    <?php if (strpos($hw['description'], '$') !== false): ?>
                                        <span class="badge-tech badge-latex"><i class="fas fa-square-root-alt mr-1"></i>LaTeX Math</span>
                                    <?php endif; ?>
                                    <span class="badge badge-light border text-dark font-weight-bold"><?= (int)$hw['total_points'] ?> Marks</span>
                                </div>
                            </div>
                        </td>

                        <td>
                            <div class="small">
                                <div class="text-muted mb-1">
                                    <i class="far fa-calendar-alt text-primary mr-1"></i>
                                    <strong>Assigned:</strong> <?= date('d M, Y', strtotime($hw['assigned_date'])) ?> at <?= date('h:i A', strtotime($hw['assigned_date'])) ?>
                                </div>
                                <div>
                                    <i class="far fa-clock text-danger mr-1"></i>
                                    <strong>Due:</strong> <span class="font-weight-bold text-dark"><?= date('d M, Y', strtotime($hw['due_date'])) ?> at <?= date('h:i A', strtotime($hw['due_date'])) ?></span>
                                </div>
                                <div class="mt-1">
                                    <?php if ($isOverdue): ?>
                                        <span class="badge badge-danger px-2 py-1 font-weight-bold">
                                            <i class="fas fa-clock mr-1"></i>Past Due
                                        </span>
                                    <?php else: 
                                        $diff = strtotime($hw['due_date']) - time();
                                        $days = floor($diff / 86400);
                                        $hours = floor(($diff % 86400) / 3600);
                                        $timeText = ($days > 0) ? "{$days}d {$hours}h remaining" : "{$hours} hours remaining";
                                    ?>
                                        <span class="badge badge-success px-2 py-1 font-weight-bold">
                                            <i class="fas fa-stopwatch mr-1"></i><?= $timeText ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>

                        <td>
                            <?php if (!empty($hw['subject_name'])): ?>
                                <span class="font-weight-bold text-dark d-block">
                                    <i class="fas fa-book text-muted mr-1"></i><?= htmlspecialchars($hw['subject_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">General Subject</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-center">
                            <a href="submissions.php?id=<?= $hw['id'] ?>" class="btn btn-sm btn-outline-info font-weight-bold rounded-pill px-3 shadow-sm">
                                <i class="fas fa-file-alt mr-1"></i><?= (int)$hw['submission_count'] ?> Submitted
                            </a>
                            <?php if ((int)$hw['reviewed_count'] > 0): ?>
                                <small class="d-block text-success mt-1"><i class="fas fa-check-circle mr-1"></i><?= (int)$hw['reviewed_count'] ?> graded</small>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <div class="btn-group btn-group-sm">
                                <a href="submissions.php?id=<?= $hw['id'] ?>" class="btn btn-outline-primary" title="View Submissions & Grade">
                                    <i class="fas fa-user-check"></i>
                                </a>
                                <a href="edit.php?id=<?= $hw['id'] ?>" class="btn btn-outline-secondary" title="Edit Assignment">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button type="button" class="btn btn-outline-danger" title="Delete Homework" onclick="confirmDeleteHomework(<?= $hw['id'] ?>, '<?= htmlspecialchars(addslashes($hw['title'])) ?>')">
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

<script>
const CSRF_TOKEN = '<?= generate_csrf_token() ?>';

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

function confirmDeleteHomework(hwId, title) {
    if (!confirm(`Are you sure you want to permanently delete homework "${title}"?\nAll associated student submissions and uploaded answers will also be removed.`)) {
        return;
    }

    const formData = new FormData();
    formData.append('homework_id', hwId);
    formData.append('csrf_token', CSRF_TOKEN);

    fetch('delete_ajax.php', {
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
            alert(data.error || 'Failed to delete homework.');
        }
    })
    .catch(err => alert('An unexpected error occurred: ' + err));
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

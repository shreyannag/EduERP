<?php
// admin/subjects/index.php
require_once '../../includes/admin_header.php';

require_admin_feature('subjects');

$current_admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();

$filter_admin = $_GET['admin_id'] ?? ($is_super ? 'my' : '');

$admin_list = [];
if ($is_super) {
    $admin_list = $pdo->query("SELECT id, username, institute_name, role FROM admins ORDER BY (role = 'super_admin') DESC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Determine target admin scope for creation and view
$target_scope_id = $current_admin_id;
if ($is_super) {
    if ($filter_admin === 'all') {
        $target_scope_id = 'all';
    } elseif ($filter_admin !== 'my' && !empty($filter_admin)) {
        $target_scope_id = (int)$filter_admin;
    }
}

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    // Determine author ID to stamp (for Super Admin managing a specific tenant or self)
    $stamp_admin_id = $current_admin_id;
    if ($is_super && is_numeric($target_scope_id)) {
        $stamp_admin_id = (int)$target_scope_id;
    }

    if ($action === 'add_subject') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO subjects (name, admin_id) VALUES (?, ?)");
            $stmt->execute([$name, $stamp_admin_id]);
        }
    } elseif ($action === 'delete_subject') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            if ($is_super) {
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ? AND admin_id = ?");
                $stmt->execute([$id, $current_admin_id]);
            }
        }
    } elseif ($action === 'add_topic') {
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($subject_id && $name) {
            // Verify parent subject belongs to this admin (or super admin)
            if ($is_super) {
                $checkSub = $pdo->prepare("SELECT admin_id FROM subjects WHERE id = ?");
                $checkSub->execute([$subject_id]);
            } else {
                $checkSub = $pdo->prepare("SELECT admin_id FROM subjects WHERE id = ? AND admin_id = ?");
                $checkSub->execute([$subject_id, $current_admin_id]);
            }
            $parentAdminId = $checkSub->fetchColumn();

            if ($parentAdminId !== false) {
                $stmt = $pdo->prepare("INSERT INTO topics (subject_id, name, admin_id) VALUES (?, ?, ?)");
                $stmt->execute([$subject_id, $name, (int)$parentAdminId]);
            }
        }
    } elseif ($action === 'delete_topic') {
        $id = intval($_POST['id'] ?? 0);
        if ($id) {
            if ($is_super) {
                $stmt = $pdo->prepare("DELETE FROM topics WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM topics WHERE id = ? AND admin_id = ?");
                $stmt->execute([$id, $current_admin_id]);
            }
        }
    }
    
    $redirectUrl = "index.php";
    if ($is_super && $filter_admin !== 'my' && $filter_admin !== '') {
        $redirectUrl .= "?admin_id=" . urlencode($filter_admin);
    }
    header("Location: " . $redirectUrl);
    exit;
}

// Fetch subjects and topics according to isolation scope
if ($is_super && $target_scope_id === 'all') {
    $subjects = $pdo->query("
        SELECT s.*, a.username as author_name, a.institute_name as author_institute, a.role as author_role 
        FROM subjects s 
        LEFT JOIN admins a ON s.admin_id = a.id 
        ORDER BY (s.admin_id = 1) DESC, s.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topics = $pdo->query("
        SELECT t.*, s.name as subject_name, a.username as author_name, a.institute_name as author_institute 
        FROM topics t 
        JOIN subjects s ON t.subject_id = s.id 
        LEFT JOIN admins a ON t.admin_id = a.id 
        ORDER BY s.name ASC, t.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $scopeId = is_numeric($target_scope_id) ? (int)$target_scope_id : $current_admin_id;
    $stmtS = $pdo->prepare("SELECT * FROM subjects WHERE admin_id = ? ORDER BY name ASC");
    $stmtS->execute([$scopeId]);
    $subjects = $stmtS->fetchAll(PDO::FETCH_ASSOC);

    $stmtT = $pdo->prepare("
        SELECT t.*, s.name as subject_name 
        FROM topics t 
        JOIN subjects s ON t.subject_id = s.id 
        WHERE t.admin_id = ? 
        ORDER BY s.name ASC, t.name ASC
    ");
    $stmtT->execute([$scopeId]);
    $topics = $stmtT->fetchAll(PDO::FETCH_ASSOC);
}

// For modal subject picker (creating topics for the active creator)
$activeCreatorId = is_numeric($target_scope_id) ? (int)$target_scope_id : $current_admin_id;
$modalSubjectsStmt = $pdo->prepare("SELECT * FROM subjects WHERE admin_id = ? ORDER BY name ASC");
$modalSubjectsStmt->execute([$activeCreatorId]);
$modalSubjects = $modalSubjectsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Subject &amp; Topic Manager</h2>
        <span class="text-muted small">
            <?php if (!$is_super): ?>
                <i class="fas fa-lock text-success mr-1"></i>Private curriculum catalog for <strong><?php echo h($_SESSION['institute_name'] ?? 'Your Institute'); ?></strong>
            <?php elseif ($target_scope_id === 'all'): ?>
                <i class="fas fa-globe text-primary mr-1"></i>Viewing Platform-Wide Curriculum (All Admins)
            <?php elseif ($target_scope_id !== $current_admin_id): ?>
                <?php 
                    $targetAdmName = 'Admin #' . $target_scope_id;
                    foreach ($admin_list as $adm) {
                        if ($adm['id'] == $target_scope_id) {
                            $targetAdmName = $adm['username'] . ($adm['institute_name'] ? ' (' . $adm['institute_name'] . ')' : '');
                            break;
                        }
                    }
                ?>
                <i class="fas fa-user-shield text-warning mr-1"></i>Viewing Admin Curriculum: <strong><?php echo h($targetAdmName); ?></strong>
            <?php else: ?>
                <i class="fas fa-user-check text-info mr-1"></i>My Subjects &amp; Topics (Super Admin Private Catalog)
            <?php endif; ?>
        </span>
    </div>
    <?php if ($is_super): ?>
    <div class="mt-2 mt-md-0">
        <form method="GET" class="form-inline">
            <label class="font-weight-bold text-muted small mr-2 text-uppercase">Curriculum Owner:</label>
            <select name="admin_id" class="form-control custom-select custom-select-sm bg-white font-weight-bold border-primary shadow-sm" onchange="this.form.submit()">
                <option value="my" <?php echo ($filter_admin === 'my' || $filter_admin === '' || $filter_admin === (string)$current_admin_id) ? 'selected' : ''; ?>>
                    ★ My Subjects (Super Admin)
                </option>
                <option value="all" <?php echo $filter_admin === 'all' ? 'selected' : ''; ?>>
                    🌐 All Admins (Platform Audit)
                </option>
                <optgroup label="Tenant Administrator Catalogs">
                    <?php foreach($admin_list as $adm): ?>
                        <?php if ($adm['id'] != $current_admin_id): ?>
                            <option value="<?php echo $adm['id']; ?>" <?php echo $filter_admin == $adm['id'] ? 'selected' : ''; ?>>
                                <?php echo h($adm['username'] . ($adm['institute_name'] ? ' (' . $adm['institute_name'] . ')' : '')); ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </optgroup>
            </select>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Privacy Guarantee Alert -->
<div class="alert alert-light border shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center" style="border-left: 5px solid #22c55e !important;">
    <i class="fas fa-shield-alt fa-2x text-success mr-3"></i>
    <div>
        <strong class="text-dark d-block">Isolated &amp; Private Subjects &amp; Topics Taxonomy</strong>
        <span class="small text-muted">
            Each institute administrator maintains their own independent subject syllabus and chapter topics. Your subjects and topics are visible only to you and seamlessly populate into your single question creation, question bank filters, and AI prompt generator.
        </span>
    </div>
</div>

<div class="row">
    <!-- Subjects Column -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-primary text-white border-bottom-0 py-3 px-4 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-book mr-2"></i>Subjects (<?= count($subjects) ?>)</h5>
                <button class="btn btn-sm btn-light font-weight-bold text-primary rounded-pill px-3 shadow-sm" data-toggle="modal" data-target="#addSubjectModal">
                    <i class="fas fa-plus mr-1"></i> Add Subject
                </button>
            </div>
            <div class="card-body p-0">
                <?php if(empty($subjects)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-book-open fa-3x text-muted opacity-50 mb-3"></i>
                        <p class="mb-2 font-weight-bold">No private subjects defined yet.</p>
                        <small>Click "Add Subject" above to create your first curriculum subject.</small>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach($subjects as $s): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <span class="font-weight-bold text-dark"><?php echo h($s['name']); ?></span>
                                    <?php if ($is_super && $target_scope_id === 'all'): ?>
                                        <div class="small text-muted mt-1">
                                            <span class="badge badge-light border text-muted">
                                                <i class="fas fa-user-circle mr-1"></i><?= h($s['author_institute'] ?: ($s['author_name'] ?: 'Admin #' . $s['admin_id'])) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" class="m-0 p-0" onsubmit="return confirm('Deleting a subject will also delete its topics and clear it from questions. Proceed?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete_subject">
                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle shadow-sm" title="Delete subject"><i class="fas fa-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Topics Column -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-info text-white border-bottom-0 py-3 px-4 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 font-weight-bold"><i class="fas fa-layer-group mr-2"></i>Topics (<?= count($topics) ?>)</h5>
                <button class="btn btn-sm btn-light font-weight-bold text-info rounded-pill px-3 shadow-sm" data-toggle="modal" data-target="#addTopicModal">
                    <i class="fas fa-plus mr-1"></i> Add Topic
                </button>
            </div>
            <div class="card-body p-0">
                <?php if(empty($topics)): ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-layer-group fa-3x text-muted opacity-50 mb-3"></i>
                        <p class="mb-2 font-weight-bold">No private topics defined yet.</p>
                        <small>Click "Add Topic" above to link a chapter/topic to your subjects.</small>
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach($topics as $t): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <span class="font-weight-bold text-dark d-block"><?php echo h($t['name']); ?></span>
                                    <small class="text-muted"><i class="fas fa-book text-muted mr-1"></i>Subject: <?php echo h($t['subject_name']); ?></small>
                                    <?php if ($is_super && $target_scope_id === 'all'): ?>
                                        <div class="small text-muted mt-1">
                                            <span class="badge badge-light border text-muted">
                                                <i class="fas fa-user-circle mr-1"></i><?= h($t['author_institute'] ?: ($t['author_name'] ?: 'Admin #' . $t['admin_id'])) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <form method="POST" class="m-0 p-0" onsubmit="return confirm('Deleting a topic clears it from questions. Proceed?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                                    <input type="hidden" name="action" value="delete_topic">
                                    <input type="hidden" name="id" value="<?php echo $t['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle shadow-sm" title="Delete topic"><i class="fas fa-trash"></i></button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-primary text-white border-0 pt-4 px-4 pb-3">
                <h5 class="modal-title font-weight-bold">Create Private Subject</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                <input type="hidden" name="action" value="add_subject">
                <div class="modal-body p-4 p-md-5 bg-light">
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-muted small text-uppercase">Subject Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control font-weight-bold shadow-sm" placeholder="e.g. Physics, Mathematics, English Literature" required>
                        <small class="form-text text-muted mt-2"><i class="fas fa-lock text-success mr-1"></i>Saved exclusively to your administrator curriculum taxonomy.</small>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">Save Subject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Topic Modal -->
<div class="modal fade" id="addTopicModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-info text-white border-0 pt-4 px-4 pb-3">
                <h5 class="modal-title font-weight-bold">Create Private Topic</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                <input type="hidden" name="action" value="add_topic">
                <div class="modal-body p-4 p-md-5 bg-light">
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-muted small text-uppercase">Parent Subject <span class="text-danger">*</span></label>
                        <select name="subject_id" class="form-control custom-select shadow-sm font-weight-bold" required>
                            <option value="">-- Select From Your Private Subjects --</option>
                            <?php foreach($modalSubjects as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo h($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($modalSubjects)): ?>
                            <small class="form-text text-danger mt-1">Please create at least one subject first before adding topics.</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-muted small text-uppercase">Topic / Chapter Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control font-weight-bold shadow-sm" placeholder="e.g. Thermodynamics, Coordinate Geometry, Organic Chemistry" required>
                        <small class="form-text text-muted mt-2"><i class="fas fa-lock text-success mr-1"></i>Bound exclusively to your private curriculum.</small>
                    </div>
                </div>
                <div class="modal-footer bg-white border-0 py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info font-weight-bold rounded-pill px-4 shadow-sm text-white" <?php echo empty($modalSubjects) ? 'disabled' : ''; ?>>Save Topic</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/admin_footer.php'; ?>

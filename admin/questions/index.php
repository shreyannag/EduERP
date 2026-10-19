<?php
// admin/questions/index.php
require_once '../../includes/admin_header.php';

require_admin_feature('questions');

$is_super = is_super_admin();
$current_admin_id = (int)$_SESSION['admin_id'];

// Fetch filters
$filter_class = $_GET['class_id'] ?? '';
$filter_board = $_GET['board_id'] ?? '';
$filter_admin = $_GET['admin_id'] ?? ($is_super ? 'my' : '');

// Build query
$where = "q.is_archived = 0";
$params = [];
if ($filter_class) { $where .= " AND q.class_id = ?"; $params[] = $filter_class; }
if ($filter_board) { $where .= " AND q.board_id = ?"; $params[] = $filter_board; }

if ($is_super) {
    if ($filter_admin === 'my' || $filter_admin === '' || $filter_admin === (string)$current_admin_id) {
        $where .= " AND q.admin_id = ?";
        $params[] = $current_admin_id;
    } elseif ($filter_admin === 'all') {
        // Show across all admins
    } else {
        $where .= " AND q.admin_id = ?";
        $params[] = (int)$filter_admin;
    }
} else {
    // Tenant admins can strictly and non-negotiably ONLY see their own questions
    $where .= " AND q.admin_id = ?";
    $params[] = $current_admin_id;
}

$sql = "SELECT q.*, c.name as class_name, b.name as board_name, s.name as subject_name, t.name as topic_name,
               a.username as author_name, a.institute_name as author_institute, a.role as author_role
        FROM questions q
        JOIN classes c ON q.class_id = c.id
        JOIN boards b ON q.board_id = b.id
        LEFT JOIN subjects s ON q.subject_id = s.id
        LEFT JOIN topics t ON q.topic_id = t.id
        LEFT JOIN admins a ON q.admin_id = a.id
        WHERE $where
        ORDER BY q.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll();
$boards = $pdo->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();
if ($is_super && $filter_admin === 'all') {
    $subjects = $pdo->query("SELECT id, name FROM subjects ORDER BY name")->fetchAll();
    $topics = $pdo->query("SELECT id, name, subject_id FROM topics ORDER BY name")->fetchAll();
} elseif ($is_super && is_numeric($filter_admin)) {
    $targetAdmin = (int)$filter_admin;
    $sStmt = $pdo->prepare("SELECT id, name FROM subjects WHERE admin_id = ? ORDER BY name");
    $sStmt->execute([$targetAdmin]);
    $subjects = $sStmt->fetchAll();
    $tStmt = $pdo->prepare("SELECT id, name, subject_id FROM topics WHERE admin_id = ? ORDER BY name");
    $tStmt->execute([$targetAdmin]);
    $topics = $tStmt->fetchAll();
} else {
    $sStmt = $pdo->prepare("SELECT id, name FROM subjects WHERE admin_id = ? ORDER BY name");
    $sStmt->execute([$current_admin_id]);
    $subjects = $sStmt->fetchAll();
    $tStmt = $pdo->prepare("SELECT id, name, subject_id FROM topics WHERE admin_id = ? ORDER BY name");
    $tStmt->execute([$current_admin_id]);
    $topics = $tStmt->fetchAll();
}

$admin_list = [];
if ($is_super) {
    $admin_list = $pdo->query("SELECT id, username, institute_name, role FROM admins ORDER BY (role = 'super_admin') DESC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
#viewModalBody img, .note-editor img {
    max-width: 100% !important;
    height: auto !important;
    border-radius: 6px;
    display: block;
    margin: 10px 0;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Question Bank</h2>
        <span class="text-muted small">
            <?php if (!$is_super): ?>
                <i class="fas fa-lock text-success mr-1"></i>Private question bank for <strong><?php echo h($_SESSION['institute_name'] ?? 'Your Institute'); ?></strong>
            <?php elseif ($filter_admin === 'all'): ?>
                <i class="fas fa-globe text-primary mr-1"></i>Viewing Platform-Wide Question Bank (All Admins)
            <?php elseif ($filter_admin !== 'my' && $filter_admin !== (string)$current_admin_id): ?>
                <?php 
                    $targetAdmName = 'Admin #' . $filter_admin;
                    foreach ($admin_list as $adm) {
                        if ($adm['id'] == $filter_admin) {
                            $targetAdmName = $adm['username'] . ($adm['institute_name'] ? ' (' . $adm['institute_name'] . ')' : '');
                            break;
                        }
                    }
                ?>
                <i class="fas fa-user-shield text-warning mr-1"></i>Viewing Admin Question Bank: <strong><?php echo h($targetAdmName); ?></strong>
            <?php else: ?>
                <i class="fas fa-user-check text-info mr-1"></i>My Question Bank (Super Admin)
            <?php endif; ?>
        </span>
    </div>
    <div>
        <button type="button" class="btn btn-outline-danger font-weight-bold shadow-sm rounded-pill px-4 mr-2" id="bulkDeleteBtn" disabled>
            <i class="fas fa-trash-alt mr-2"></i>Bulk Delete
        </button>
        <button type="button" class="btn btn-outline-info font-weight-bold shadow-sm rounded-pill px-4 mr-2" id="bulkPdfBtn" disabled onclick="exportPdf()">
            <i class="fas fa-file-pdf mr-2"></i>Print to PDF
        </button>
        <a href="create.php?ai=1" class="btn text-white font-weight-bold shadow-sm rounded-pill px-4 mr-2" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);">
            <i class="fas fa-magic mr-2"></i>Generate with AI
        </a>
        <a href="create.php" class="btn btn-secondary font-weight-bold shadow-sm rounded-pill px-4 mr-2">
            <i class="fas fa-edit mr-2"></i>Add Single Question
        </a>
        <a href="upload.php" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-file-csv mr-2"></i>Bulk Upload
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm border-0 mb-4 rounded-lg bg-light">
    <div class="card-body p-4">
        <form method="GET" class="form-row align-items-end">
            <?php if ($is_super): ?>
            <div class="col-md-3 mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Question Bank Ownership</label>
                <select name="admin_id" class="form-control custom-select bg-white font-weight-bold border-primary shadow-sm">
                    <option value="my" <?php echo ($filter_admin === 'my' || $filter_admin === '' || $filter_admin === (string)$current_admin_id) ? 'selected' : ''; ?>>
                        ★ My Question Bank (Super Admin)
                    </option>
                    <option value="all" <?php echo $filter_admin === 'all' ? 'selected' : ''; ?>>
                        🌐 All Admins (Global View)
                    </option>
                    <optgroup label="Tenant Administrator Banks">
                        <?php foreach($admin_list as $adm): ?>
                            <?php if ($adm['id'] != $current_admin_id): ?>
                                <option value="<?php echo $adm['id']; ?>" <?php echo $filter_admin == $adm['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($adm['username'] . ($adm['institute_name'] ? ' (' . $adm['institute_name'] . ')' : '')); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <?php endif; ?>
            <div class="<?php echo $is_super ? 'col-md-3' : 'col-md-3'; ?> mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Filter by Class</label>
                <select name="class_id" class="form-control custom-select bg-white">
                    <option value="">All Classes</option>
                    <?php foreach($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $filter_class == $c['id'] ? 'selected' : ''; ?>><?php echo h($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="<?php echo $is_super ? 'col-md-2' : 'col-md-3'; ?> mb-3 mb-md-0">
                <label class="font-weight-bold text-muted small text-uppercase">Filter by Board</label>
                <select name="board_id" class="form-control custom-select bg-white">
                    <option value="">All Boards</option>
                    <?php foreach($boards as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $filter_board == $b['id'] ? 'selected' : ''; ?>><?php echo h($b['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="<?php echo $is_super ? 'col-md-2' : 'col-md-2'; ?> mb-3 mb-md-0">
                <button type="submit" class="btn btn-dark btn-block font-weight-bold shadow-sm">Apply Filters</button>
            </div>
            <?php if($filter_class || $filter_board || ($is_super && $filter_admin !== 'my' && $filter_admin !== '')): ?>
            <div class="<?php echo $is_super ? 'col-md-2' : 'col-md-2'; ?> mb-3 mb-md-0">
                <a href="index.php" class="btn btn-outline-secondary btn-block font-weight-bold">Clear Filters</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<form method="POST" action="delete.php" id="bulkActionForm">
    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
    <!-- Data Table -->
    <div class="card shadow-sm border-0 rounded-lg">
        <div class="card-body p-0">
            <div class="table-responsive p-4">
                <table class="table table-hover table-bordered w-100" id="questionsTable">
                    <thead class="bg-light text-muted text-uppercase small">
                        <tr>
                            <th width="3%" class="text-center border-0 rounded-top-left">
                                <div class="custom-control custom-checkbox ml-auto mr-auto" style="width:16px;">
                                    <input type="checkbox" class="custom-control-input" id="checkAll">
                                    <label class="custom-control-label" for="checkAll"></label>
                                </div>
                            </th>
                            <th width="5%" class="text-center border-0">#</th>
                            <?php if ($is_super && $filter_admin === 'all'): ?>
                                <th width="12%" class="border-0">Admin / Institute</th>
                            <?php endif; ?>
                            <th width="8%" class="border-0">Class</th>
                            <th width="8%" class="border-0">Subject</th>
                            <th width="35%" class="border-0">Question</th>
                            <th width="7%" class="border-0 text-center">Marks</th>
                            <th width="8%" class="border-0">Correct</th>
                            <th width="10%" class="border-0">Updated</th>
                            <th width="9%" class="text-right border-0 rounded-top-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($questions as $q): ?>
                        <tr id="row-<?php echo $q['id']; ?>">
                            <td class="text-center align-middle">
                                <div class="custom-control custom-checkbox mx-auto" style="width:16px;">
                                    <input type="checkbox" name="id[]" value="<?php echo $q['id']; ?>" class="custom-control-input q-checkbox" id="chk<?php echo $q['id']; ?>">
                                    <label class="custom-control-label" for="chk<?php echo $q['id']; ?>"></label>
                                </div>
                            </td>
                            <td class="text-center text-muted align-middle"><?php echo $q['id']; ?></td>
                            <?php if ($is_super && $filter_admin === 'all'): ?>
                                <td class="align-middle">
                                    <?php if (!empty($q['author_role']) && $q['author_role'] === 'super_admin'): ?>
                                        <span class="badge badge-warning text-dark"><i class="fas fa-shield-alt mr-1"></i>Super Admin</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary"><?php echo h($q['author_name'] ?: 'Admin #' . $q['admin_id']); ?></span>
                                        <?php if (!empty($q['author_institute'])): ?>
                                            <div class="small text-muted font-italic"><?php echo h($q['author_institute']); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="font-weight-bold align-middle"><span id="qclass-<?php echo $q['id']; ?>"><?php echo h($q['class_name']); ?></span></td>
                            <td class="align-middle"><span class="badge badge-light border text-muted" id="qsub-<?php echo $q['id']; ?>"><?php echo h($q['subject_name'] ?? 'N/A'); ?></span></td>
                            <?php 
                                $plainText = trim(strip_tags($q['question_text']));
                                $hasImg = stripos($q['question_text'], '<img') !== false;
                            ?>
                            <td class="text-truncate align-middle" style="max-width: 350px;" title="<?php echo h($plainText ?: ($hasImg ? '[Image Question]' : '')); ?>">
                                <span id="qtext-<?php echo $q['id']; ?>">
                                    <?php if ($hasImg): ?>
                                        <span class="badge badge-light border text-primary mr-1"><i class="fas fa-image mr-1"></i>Image</span>
                                    <?php endif; ?>
                                    <?php echo h($plainText ?: ($hasImg ? 'Image Question' : 'No text')); ?>
                                </span>
                            </td>
                            <td class="text-center align-middle font-weight-bold text-success"><span id="qmarks-<?php echo $q['id']; ?>"><?php echo $q['marks']; ?></span></td>
                        <td><span class="badge badge-info bg-opacity-75" id="qcorr-<?php echo $q['id']; ?>">Option <?php echo $q['correct_option']; ?></span></td>
                        <td class="small text-muted align-middle"><?php echo date('M d, Y', strtotime($q['updated_at'])); ?></td>
                        <td class="text-right text-nowrap align-middle">
                            <button type="button" class="btn btn-sm btn-light text-primary shadow-sm view-btn rounded-circle" data-id="<?php echo $q['id']; ?>" title="View details">
                                <i class="fas fa-eye"></i>
                            </button>
                                <button type="button" class="btn btn-sm btn-light text-warning shadow-sm mx-1 edit-btn rounded-circle" data-id="<?php echo $q['id']; ?>" title="Edit question">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-light text-danger shadow-sm delete-btn rounded-circle" data-id="<?php echo $q['id']; ?>" title="Delete question">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-lg">
      <div class="modal-header bg-light border-0 pt-4 px-4">
        <h5 class="modal-title font-weight-bold text-dark"><i class="fas fa-file-alt mr-2 text-primary"></i>Question Details</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-5" id="viewModalBody">
          <div class="text-center"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
      </div>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-lg">
      <div class="modal-header bg-warning text-dark border-0 py-3 px-4">
        <h5 class="modal-title font-weight-bold"><i class="fas fa-pencil-alt mr-2 shadow-sm p-2 bg-white rounded-circle text-warning"></i>Edit Question</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <form id="editForm">
          <div class="modal-body p-4 p-md-5 bg-light">
            <input type="hidden" id="edit-id" name="id">
            <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            
            <div class="form-group mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="font-weight-bold text-muted small text-uppercase mb-0">Question Prompt Text</label>
                    <span class="text-muted small"><i class="fas fa-info-circle mr-1 text-primary"></i>Supports LaTeX <code>$...$</code></span>
                </div>
                <!-- Quick LaTeX Math Symbols Bar for Edit -->
                <div class="latex-quick-bar mb-2 p-2 bg-white rounded border d-flex flex-wrap align-items-center shadow-sm" style="gap: 4px;">
                    <span class="small font-weight-bold text-muted mr-1"><i class="fas fa-calculator text-primary mr-1"></i>Insert Math:</span>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\frac{a}{b}$" title="Fraction">$\frac{a}{b}$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\sqrt{x}$" title="Square Root">$\sqrt{x}$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$x^{2}$" title="Power">$x^2$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$x_{i}$" title="Subscript">$x_i$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\int_{a}^{b} f(x) dx$" title="Definite Integral">$\int$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\sum_{i=1}^{n}$" title="Summation">$\sum$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\alpha$" title="Alpha">&alpha;</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\beta$" title="Beta">&beta;</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\theta$" title="Theta">&theta;</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\pi$" title="Pi">&pi;</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\pm$" title="Plus-Minus">&plusmn;</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$\text{H}_2\text{O}$" title="Water (Chemistry)">$\text{H}_2\text{O}$</button>
                    <button type="button" class="btn btn-xs btn-light border px-2 py-1 quick-edit-latex" data-latex="$$ \frac{-b \pm \sqrt{b^2 - 4ac}}{2a} $$" title="Quadratic Formula">Quad Formula</button>
                </div>
                <textarea id="edit-q" name="question_text" class="form-control font-weight-bold text-dark shadow-sm bg-white border-0" rows="3" required></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold small text-muted text-uppercase">Target Class Segment</label>
                    <select id="edit-cid" name="class_id" class="form-control custom-select bg-white shadow-sm border-0" required>
                        <?php foreach($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>">Class <?php echo h($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold small text-muted text-uppercase">Board Specification</label>
                    <select id="edit-bid" name="board_id" class="form-control custom-select bg-white shadow-sm border-0" required>
                        <?php foreach($boards as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo h($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 form-group mb-4">
                    <label class="font-weight-bold small text-muted text-uppercase">Subject (Optional)</label>
                    <select id="edit-sub" name="subject_id" class="form-control custom-select bg-white shadow-sm border-0">
                        <option value="">-- None --</option>
                        <?php foreach($subjects as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo h($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group mb-4">
                    <label class="font-weight-bold small text-muted text-uppercase">Topic (Optional)</label>
                    <select id="edit-top" name="topic_id" class="form-control custom-select bg-white shadow-sm border-0">
                        <option value="">-- None --</option>
                        <?php foreach($topics as $t): ?>
                            <option value="<?php echo $t['id']; ?>" data-subject="<?php echo $t['subject_id']; ?>"><?php echo h($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 form-group mb-4">
                    <label class="font-weight-bold small text-muted text-uppercase">Marks</label>
                    <input type="number" id="edit-marks" name="marks" class="form-control shadow-sm border-0" min="1" required>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold small text-muted text-uppercase">Option 1</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0 font-weight-bold text-muted">A</span></div>
                        <input type="text" id="edit-o1" name="option1" class="form-control border-0 bg-white" required>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-light border-0 text-primary font-weight-bold btn-edit-opt-latex px-2" data-opt="1" title="Insert formula into Option 1"><i class="fas fa-square-root-alt mr-1"></i>+ Math</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold small text-muted text-uppercase">Option 2</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0 font-weight-bold text-muted">B</span></div>
                        <input type="text" id="edit-o2" name="option2" class="form-control border-0 bg-white" required>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-light border-0 text-primary font-weight-bold btn-edit-opt-latex px-2" data-opt="2" title="Insert formula into Option 2"><i class="fas fa-square-root-alt mr-1"></i>+ Math</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold small text-muted text-uppercase">Option 3</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0 font-weight-bold text-muted">C</span></div>
                        <input type="text" id="edit-o3" name="option3" class="form-control border-0 bg-white" required>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-light border-0 text-primary font-weight-bold btn-edit-opt-latex px-2" data-opt="3" title="Insert formula into Option 3"><i class="fas fa-square-root-alt mr-1"></i>+ Math</button>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label class="font-weight-bold small text-muted text-uppercase">Option 4</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0 font-weight-bold text-muted">D</span></div>
                        <input type="text" id="edit-o4" name="option4" class="form-control border-0 bg-white" required>
                        <div class="input-group-append">
                            <button type="button" class="btn btn-light border-0 text-primary font-weight-bold btn-edit-opt-latex px-2" data-opt="4" title="Insert formula into Option 4"><i class="fas fa-square-root-alt mr-1"></i>+ Math</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Math Preview in Edit Modal -->
            <div class="card border-0 shadow-sm rounded-lg mb-3 bg-white" id="editMathPreviewContainer">
                <div class="card-header bg-light border-0 py-2 px-3 d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold small text-muted text-uppercase"><i class="fas fa-eye text-primary mr-1"></i>Live Math & LaTeX Preview</span>
                    <button type="button" class="btn btn-xs btn-outline-secondary font-weight-bold py-0 px-2" id="btnRefreshEditPreview"><i class="fas fa-redo-alt mr-1"></i>Render</button>
                </div>
                <div class="card-body p-3">
                    <div id="editPreviewPrompt" class="p-2 bg-light rounded border mb-2 small text-dark" style="min-height: 40px;"><span class="text-muted font-italic">Prompt preview...</span></div>
                    <div class="row small" id="editPreviewOptionsRow">
                        <div class="col-md-6 mb-1">
                            <div class="p-2 rounded border bg-light d-flex align-items-center" id="editLiveOptCard1">
                                <span class="badge badge-secondary mr-2">A</span>
                                <span class="flex-grow-1" id="editLiveOptText1">Option 1</span>
                                <span class="badge badge-success ml-1 d-none" id="editLiveBadge1"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-1">
                            <div class="p-2 rounded border bg-light d-flex align-items-center" id="editLiveOptCard2">
                                <span class="badge badge-secondary mr-2">B</span>
                                <span class="flex-grow-1" id="editLiveOptText2">Option 2</span>
                                <span class="badge badge-success ml-1 d-none" id="editLiveBadge2"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-1">
                            <div class="p-2 rounded border bg-light d-flex align-items-center" id="editLiveOptCard3">
                                <span class="badge badge-secondary mr-2">C</span>
                                <span class="flex-grow-1" id="editLiveOptText3">Option 3</span>
                                <span class="badge badge-success ml-1 d-none" id="editLiveBadge3"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-1">
                            <div class="p-2 rounded border bg-light d-flex align-items-center" id="editLiveOptCard4">
                                <span class="badge badge-secondary mr-2">D</span>
                                <span class="flex-grow-1" id="editLiveOptText4">Option 4</span>
                                <span class="badge badge-success ml-1 d-none" id="editLiveBadge4"><i class="fas fa-check"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-3 mb-0">
                <label class="font-weight-bold text-muted small text-uppercase">Correct Option Choice</label>
                <select id="edit-c" name="correct_option" class="form-control custom-select bg-white shadow-sm border-0 font-weight-bold" required>
                    <option value="1">Option 1 is correct</option>
                    <option value="2">Option 2 is correct</option>
                    <option value="3">Option 3 is correct</option>
                    <option value="4">Option 4 is correct</option>
                </select>
            </div>
          </div>
          <div class="modal-footer bg-white border-0 py-3 px-4 rounded-bottom">
            <button type="button" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Discard</button>
            <button type="submit" class="btn btn-warning font-weight-bold rounded-pill px-5 shadow-sm"><i class="fas fa-save mr-2"></i>Apply Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-lg">
      <div class="modal-header bg-danger text-white border-0 pt-4 px-4 pb-3">
        <h5 class="modal-title font-weight-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Warning: Deletion</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-4 p-md-5 text-center bg-light">
        <i class="fas fa-trash-alt fa-3x text-danger mb-4 opacity-75"></i>
        <p class="lead mb-2 font-weight-bold text-dark">Are you absolutely sure?</p>
        <p class="text-muted">This question will be archived if it is part of existing exam history, keeping records intact. Only completely unused questions are hard deleted.</p>
      </div>
      <div class="modal-footer bg-white border-0 py-3 d-flex justify-content-center border-top">
        <form method="POST" action="delete.php">
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            <input type="hidden" name="id" id="delete-id">
            <button type="button" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4 mr-2" data-dismiss="modal">Go Back</button>
            <button type="submit" class="btn btn-danger font-weight-bold rounded-pill px-5 shadow-sm"><i class="fas fa-trash mr-2"></i>Delete</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function exportPdf() {
    let form = document.getElementById('bulkActionForm');
    form.action = 'export_pdf.php';
    form.target = '_blank';
    form.submit();
    
    // Reset back
    setTimeout(() => {
        form.action = 'delete.php';
        form.target = '_self';
    }, 100);
}

$(document).ready(function() {
    let questionsTable = $('#questionsTable').DataTable({
        "order": [[ 1, "desc" ]],
        "pageLength": 25,
        "columnDefs": [ { "orderable": false, "targets": 0 } ],
        "language": {
            "search": "<i class='fas fa-search text-muted mr-2'></i>"
        }
    });

    // Typeset math equations in questions table on pagination/search/draw
    questionsTable.on('draw', function() {
        if (window.MathJax && window.MathJax.typesetPromise) {
            MathJax.typesetPromise([document.getElementById('questionsTable')]);
        }
    });

    if (window.MathJax && window.MathJax.typesetPromise) {
        MathJax.typesetPromise([document.getElementById('questionsTable')]);
    }
    
    // Checkboxes functionality
    function toggleBulkBtns() {
        if ($('.q-checkbox:checked').length > 0) {
            $('#bulkDeleteBtn').prop('disabled', false).removeClass('btn-outline-danger').addClass('btn-danger');
            $('#bulkPdfBtn').prop('disabled', false).removeClass('btn-outline-info').addClass('btn-info text-white');
        } else {
            $('#bulkDeleteBtn').prop('disabled', true).removeClass('btn-danger').addClass('btn-outline-danger');
            $('#bulkPdfBtn').prop('disabled', true).removeClass('btn-info text-white').addClass('btn-outline-info');
        }
    }

    $('#checkAll').click(function() {
        $('.q-checkbox').prop('checked', this.checked);
        toggleBulkBtns();
    });
    
    $(document).on('change', '.q-checkbox', function() {
        toggleBulkBtns();
    });

    $('#bulkDeleteBtn').click(function() {
        if (confirm('Are you absolutely sure you want to delete the selected questions?')) {
            $('#bulkActionForm').submit();
        }
    });
    
    // View handler
    $(document).on('click', '.view-btn', function() {
        let id = $(this).data('id');
        $('#viewModalBody').html('<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>');
        $('#viewModal').modal('show');
        
        $.get('get_question_ajax.php', { id: id }, function(res) {
            if(res.success) {
                let q = res.data;
                let html = '<div class="text-dark mb-4 pb-4 border-bottom" style="font-size: 1.15rem; line-height: 1.6;">' + q.question_text + '</div>';
                html += '<div class="list-group list-group-flush shadow-sm border rounded">';
                for(let i=1; i<=4; i++) {
                    let rawOpt = q['option'+i] || '';
                    let optText = rawOpt.indexOf('<') !== -1 ? rawOpt : $('<div>').text(rawOpt).html();
                    let isCorr = (i == q.correct_option);
                    let cls = isCorr ? 'list-group-item-success font-weight-bold border-success' : '';
                    let badge = isCorr ? '<span class="badge badge-success float-right px-2 py-1 shadow-sm"><i class="fas fa-check mr-1"></i> Correct Option</span>' : '';
                    html += '<div class="list-group-item list-group-item-action py-3 ' + cls + '"><span class="mr-2 font-weight-bold">[ ' + String.fromCharCode(64 + i) + ' ]</span> <span>' + optText + '</span> ' + badge + '</div>';
                }
                html += '</div>';
                $('#viewModalBody').html(html);
                if (window.MathJax && window.MathJax.typesetPromise) {
                    MathJax.typesetPromise([document.getElementById('viewModalBody')]);
                }
            } else {
                $('#viewModalBody').html('<p class="text-danger">Failed to load question details.</p>');
            }
        }, 'json');
    });
    
    function filterTopics() {
        let sub = $('#edit-sub').val();
        $('#edit-top option').each(function() {
            if($(this).val() === '') return;
            if($(this).data('subject') == sub) $(this).show();
            else $(this).hide();
        });
    }
    $('#edit-sub').change(function() {
        $('#edit-top').val('');
        filterTopics();
    });

    // --- Live Math Preview in Edit Modal ---
    let editPreviewTimer = null;
    function updateEditPreview() {
        var promptCode = $('#edit-q').summernote('code');
        var cleanPrompt = promptCode ? promptCode.replace(/<[^>]+>/g, '').trim() : '';
        var hasImg = promptCode && promptCode.indexOf('<img') !== -1;

        if (cleanPrompt.length > 0 || hasImg) {
            $('#editPreviewPrompt').html(promptCode);
        } else {
            $('#editPreviewPrompt').html('<span class="text-muted font-italic">Prompt preview...</span>');
        }

        var correctOpt = $('#edit-c').val();
        for (var i = 1; i <= 4; i++) {
            var optVal = $('#edit-o' + i).val().trim();
            var card = $('#editLiveOptCard' + i);
            var badge = $('#editLiveBadge' + i);
            var textSpan = $('#editLiveOptText' + i);

            if (optVal) {
                textSpan.text(optVal);
            } else {
                textSpan.html('<em class="text-muted">Option ' + i + '</em>');
            }

            if (String(i) === String(correctOpt)) {
                card.addClass('border-success bg-success bg-opacity-10 text-success font-weight-bold').removeClass('bg-light');
                badge.removeClass('d-none');
            } else {
                card.removeClass('border-success bg-success bg-opacity-10 text-success font-weight-bold').addClass('bg-light');
                badge.addClass('d-none');
            }
        }

        if (window.MathJax && window.MathJax.typesetPromise) {
            MathJax.typesetPromise([document.getElementById('editMathPreviewContainer')]);
        }
    }

    function debouncedEditPreview() {
        clearTimeout(editPreviewTimer);
        editPreviewTimer = setTimeout(updateEditPreview, 250);
    }

    $('#btnRefreshEditPreview').click(updateEditPreview);
    $('#editForm input[name^="option"]').on('input', debouncedEditPreview);
    $('#edit-c').on('change', debouncedEditPreview);

    // Quick LaTeX toolbar for edit prompt
    $('.quick-edit-latex').click(function() {
        var latex = $(this).data('latex');
        $('#edit-q').summernote('insertText', latex);
        debouncedEditPreview();
    });

    // Option math button in edit modal
    $('.btn-edit-opt-latex').click(function() {
        var opt = $(this).data('opt');
        var formula = prompt("Enter or paste LaTeX formula for Option " + opt + " (e.g. \\frac{a}{b} or \\sqrt{x}):", "\\frac{a}{b}");
        if (formula !== null && formula.trim().length > 0) {
            var trimmed = formula.trim();
            if (!trimmed.startsWith('$')) trimmed = '$' + trimmed + '$';
            var input = $('#edit-o' + opt);
            var cur = input.val();
            input.val(cur ? (cur + ' ' + trimmed) : trimmed).trigger('input');
        }
    });

    // Edit handler
    $(document).on('click', '.edit-btn', function() {
        let id = $(this).data('id');
        $.get('get_question_ajax.php', { id: id }, function(res) {
            if(res.success) {
                let q = res.data;
                $('#edit-id').val(q.id);
                $('#edit-q').summernote('code', q.question_text);
                $('#edit-o1').val(q.option1);
                $('#edit-o2').val(q.option2);
                $('#edit-o3').val(q.option3);
                $('#edit-o4').val(q.option4);
                $('#edit-c').val(q.correct_option);
                $('#edit-cid').val(q.class_id);
                $('#edit-bid').val(q.board_id);
                $('#edit-sub').val(q.subject_id);
                filterTopics();
                $('#edit-top').val(q.topic_id);
                $('#edit-marks').val(q.marks);
                $('#editModal').modal('show');
                setTimeout(updateEditPreview, 350);
            } else {
                alert('Failed to fetch data.');
            }
        }, 'json');
    });
    
    // Delete handler
    $(document).on('click', '.delete-btn', function() {
        $('#delete-id').val($(this).data('id'));
        $('#deleteModal').modal('show');
    });
    
    // AJAX form submission
    $('#editForm').submit(function(e) {
        e.preventDefault();
        let btn = $(this).find('button[type="submit"]');
        let origBtnHtml = btn.html();
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Applying...');
        
        $.post('update_ajax.php', $(this).serialize(), function(res) {
            btn.prop('disabled', false).html(origBtnHtml);
            if(res && res.success) {
                $('#editModal').modal('hide');
                showToast('Question details have been securely updated.');
                setTimeout(() => location.reload(), 1000); // Reload to show updated table columns easily
            } else {
                showToast(res.error || 'Failed to update question.', 'danger');
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).html(origBtnHtml);
            showToast('Server connection failed.', 'danger');
        });
    });
    
    function uploadSummernoteImage(file, editor) {
        let data = new FormData();
        data.append('image', file);
        data.append('csrf_token', '<?php echo generate_csrf_token(); ?>');

        $.ajax({
            url: 'upload_image.php',
            method: 'POST',
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success && res.url) {
                    editor.summernote('insertImage', res.url);
                } else {
                    alert(res.error || 'Failed to upload and compress image.');
                }
            },
            error: function() {
                alert('Server error occurred during image upload.');
            }
        });
    }

    // Init Summernote
    $('#edit-q').summernote({
        height: 150,
        toolbar: [
            ['style', ['style']],
            ['font', ['bold', 'underline', 'clear']],
            ['color', ['color']],
            ['para', ['ul', 'ol', 'paragraph']],
            ['table', ['table']],
            ['insert', ['picture']],
            ['view', ['fullscreen', 'codeview', 'help']]
        ],
        callbacks: {
            onImageUpload: function(files) {
                for (let i = 0; i < files.length; i++) {
                    uploadSummernoteImage(files[i], $(this));
                }
            },
            onChange: function(contents) {
                debouncedEditPreview();
            }
        }
    });
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

<?php
// admin/enrolments/link_students.php
require_once __DIR__ . '/../../includes/admin_header.php';

require_admin_feature('enrolments');

$current_admin_id = get_effective_admin_id();
$is_super = is_super_admin();

$link_id = (int)($_GET['id'] ?? 0);

if (!$link_id) {
    header("Location: links.php");
    exit;
}

// Fetch link details
$check_sql = $is_super ? "
    SELECT el.*, 
           c.name as class_name, 
           b.name as board_name, 
           bt.name as batch_name,
           a.institute_name
    FROM enrollment_links el
    LEFT JOIN classes c ON el.class_id = c.id
    LEFT JOIN boards b ON el.board_id = b.id
    LEFT JOIN batches bt ON el.batch_id = bt.id
    LEFT JOIN admins a ON el.admin_id = a.id
    WHERE el.id = ?
" : "
    SELECT el.*, 
           c.name as class_name, 
           b.name as board_name, 
           bt.name as batch_name,
           a.institute_name
    FROM enrollment_links el
    LEFT JOIN classes c ON el.class_id = c.id
    LEFT JOIN boards b ON el.board_id = b.id
    LEFT JOIN batches bt ON el.batch_id = bt.id
    LEFT JOIN admins a ON el.admin_id = a.id
    WHERE el.id = ? AND el.admin_id = ?
";
$params = $is_super ? [$link_id] : [$link_id, $current_admin_id];
$stmt = $pdo->prepare($check_sql);
$stmt->execute($params);
$link = $stmt->fetch();

if (!$link) {
    $_SESSION['flash_error'] = "Enrollment link not found or access denied.";
    header("Location: links.php");
    exit;
}

// Handle Approvals / Status Toggles (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $student_id = (int)($_POST['student_id'] ?? 0);

    if ($action === 'approve_student') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE enrollment_submissions SET status = 'approved' WHERE link_id = ? AND student_id = ?")->execute([$link_id, $student_id]);
        $pdo->prepare("UPDATE students SET is_active = 1 WHERE id = ?")->execute([$student_id]);
        $pdo->commit();
        $_SESSION['flash_success'] = "Student admission approved and account activated!";
        header("Location: link_students.php?id=$link_id");
        exit;
    } elseif ($action === 'reject_student') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE enrollment_submissions SET status = 'rejected' WHERE link_id = ? AND student_id = ?")->execute([$link_id, $student_id]);
        $pdo->prepare("UPDATE students SET is_active = 0 WHERE id = ?")->execute([$student_id]);
        $pdo->commit();
        $_SESSION['flash_success'] = "Student admission rejected and account deactivated.";
        header("Location: link_students.php?id=$link_id");
        exit;
    }
}

// Fetch enrolled students
$stmt_stu = $pdo->prepare("
    SELECT s.*, 
           c.name as class_name, 
           b.name as board_name, 
           es.submitted_at, 
           es.ip_address, 
           es.status as submission_status,
           bt.name as batch_name
    FROM enrollment_submissions es
    JOIN students s ON es.student_id = s.id
    LEFT JOIN classes c ON s.class_id = c.id
    LEFT JOIN boards b ON s.board_id = b.id
    LEFT JOIN batch_students bs ON bs.student_id = s.id
    LEFT JOIN batches bt ON bs.batch_id = bt.id
    WHERE es.link_id = ?
    ORDER BY es.submitted_at DESC
");
$stmt_stu->execute([$link_id]);
$submissions = $stmt_stu->fetchAll();

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=enrollment_roster_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $link['title']) . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($output, ['Student ID', 'Full Name', 'Email', 'Phone', 'Parent Name', 'Parent Phone', 'DOB', 'Gender', 'Address', 'Class', 'Board', 'Batch', 'Enrollment Date', 'Status']);
    foreach ($submissions as $sub) {
        fputcsv($output, [
            $sub['username'],
            $sub['full_name'],
            $sub['email'],
            $sub['phone'],
            $sub['parent_name'],
            $sub['parent_phone'],
            $sub['dob'],
            $sub['gender'],
            $sub['address'],
            $sub['class_name'],
            $sub['board_name'],
            $sub['batch_name'] ?? 'Unassigned',
            $sub['submitted_at'],
            ucfirst($sub['submission_status'])
        ]);
    }
    fclose($output);
    exit;
}

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$protocol = $is_https ? 'https://' : 'http://';
$public_url = $protocol . $_SERVER['HTTP_HOST'] . BASE_URL . '/enroll.php?token=' . urlencode($link['token']);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent p-0 mb-1">
                <li class="breadcrumb-item"><a href="links.php">Enrollment Links</a></li>
                <li class="breadcrumb-item active"><?= h($link['title']) ?></li>
            </ol>
        </nav>
        <h2 class="font-weight-bold text-dark mb-0">Enrolled Students Roster</h2>
    </div>
    <div>
        <a href="links.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-3 shadow-sm mr-2">
            <i class="fas fa-arrow-left mr-1"></i> Back to Links
        </a>
        <?php if (!empty($submissions)): ?>
            <a href="link_students.php?id=<?= $link_id ?>&export=csv" class="btn btn-success font-weight-bold rounded-pill px-4 shadow-sm">
                <i class="fas fa-file-csv mr-2"></i> Export to CSV
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Link Overview Card -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
    <div class="card-body p-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center mb-2">
                    <span class="badge badge-primary px-3 py-1 font-weight-bold mr-2"><i class="fas fa-link mr-1"></i>Active Token</span>
                    <h4 class="font-weight-bold text-dark mb-0"><?= h($link['title']) ?></h4>
                </div>
                <p class="text-muted mb-2">
                    <strong>Scope:</strong> <?= h($link['class_name'] ?? 'Any Class') ?> &bull; <?= h($link['board_name'] ?? 'Any Board') ?> 
                    <?php if (!empty($link['batch_name'])): ?>
                        &bull; Target Batch: <span class="badge badge-light border text-primary font-weight-bold"><?= h($link['batch_name']) ?></span>
                    <?php endif; ?>
                    &bull; Expires: <strong><?= date('d M Y, h:i A', strtotime($link['expires_at'])) ?></strong>
                </p>
                <div class="input-group input-group-sm" style="max-width: 600px;">
                    <input type="text" class="form-control bg-light font-weight-bold" value="<?= $public_url ?>" id="publicLinkInput" readonly>
                    <div class="input-group-append">
                        <button class="btn btn-outline-primary" type="button" onclick="copyLink()">
                            <i class="fas fa-copy mr-1"></i> Copy Link
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-md-4 text-md-right mt-3 mt-md-0">
                <div class="p-3 bg-light rounded text-center d-inline-block shadow-sm">
                    <h3 class="font-weight-bold text-primary mb-0"><?= count($submissions) ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">
                        Enrolled / <?= $link['max_uses'] > 0 ? (int)$link['max_uses'] : '∞' ?> Cap
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submissions Roster -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-user-graduate text-primary mr-2"></i>Registered Students (<?= count($submissions) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($submissions)): ?>
            <div class="text-center py-5">
                <div class="mb-3 text-muted opacity-50"><i class="fas fa-user-friends fa-4x"></i></div>
                <h5 class="font-weight-bold text-dark">No Students Enrolled via this Link Yet</h5>
                <p class="text-muted">Share the public URL above on WhatsApp, Email, or SMS to start collecting student admissions.</p>
                <button type="button" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm" onclick="copyLink()">
                    <i class="fas fa-copy mr-1"></i> Copy Public Link
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="submissionsTable">
                    <thead class="thead-light">
                        <tr>
                            <th class="border-0">Student Username / ID</th>
                            <th class="border-0">Student Details</th>
                            <th class="border-0">Parent / Guardian</th>
                            <th class="border-0">Academic Placement</th>
                            <th class="border-0">Submission Time</th>
                            <th class="border-0 text-center">Status</th>
                            <th class="border-0 text-right pr-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $sub): 
                            $phone_clean = preg_replace('/[^0-9]/', '', $sub['phone'] ?? '');
                            $parent_phone_clean = preg_replace('/[^0-9]/', '', $sub['parent_phone'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <strong class="text-primary font-family-monospace h6 font-weight-bold d-block mb-1"><?= h($sub['username']) ?></strong>
                                <small class="text-muted"><i class="fas fa-key mr-1"></i>Pass: <?= h($sub['plain_password'] ?? '********') ?></small>
                            </td>
                            <td>
                                <strong class="d-block text-dark font-weight-bold"><?= h($sub['full_name']) ?></strong>
                                <?php if (!empty($sub['phone'])): ?>
                                    <small class="d-block text-muted">
                                        <i class="fas fa-phone mr-1"></i><?= h($sub['phone']) ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?= $phone_clean ?>" target="_blank" class="text-success ml-1" title="WhatsApp Chat">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                    </small>
                                <?php endif; ?>
                                <?php if (!empty($sub['email'])): ?>
                                    <small class="d-block text-muted"><i class="fas fa-envelope mr-1"></i><?= h($sub['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($sub['parent_name'])): ?>
                                    <strong class="d-block text-dark small"><?= h($sub['parent_name']) ?></strong>
                                <?php endif; ?>
                                <?php if (!empty($sub['parent_phone'])): ?>
                                    <small class="d-block text-muted">
                                        <i class="fas fa-phone-alt mr-1"></i><?= h($sub['parent_phone']) ?>
                                        <a href="https://api.whatsapp.com/send?phone=<?= $parent_phone_clean ?>" target="_blank" class="text-success ml-1" title="WhatsApp Parent">
                                            <i class="fab fa-whatsapp"></i>
                                        </a>
                                    </small>
                                <?php endif; ?>
                                <?php if (empty($sub['parent_name']) && empty($sub['parent_phone'])): ?>
                                    <span class="text-muted small">Not provided</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-light border text-dark mb-1"><?= h($sub['class_name'] ?? 'N/A') ?> &bull; <?= h($sub['board_name'] ?? 'N/A') ?></span>
                                <small class="d-block text-muted">
                                    <i class="fas fa-layer-group mr-1 text-primary"></i>Batch: <?= h($sub['batch_name'] ?? 'Unassigned') ?>
                                </small>
                            </td>
                            <td>
                                <small class="d-block font-weight-bold text-dark"><?= date('d M Y, h:i A', strtotime($sub['submitted_at'])) ?></small>
                                <small class="text-muted"><i class="fas fa-globe mr-1"></i>IP: <?= h($sub['ip_address'] ?? 'Unknown') ?></small>
                            </td>
                            <td class="text-center">
                                <?php if ($sub['submission_status'] === 'approved'): ?>
                                    <span class="badge badge-success px-3 py-1 font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Active</span>
                                <?php elseif ($sub['submission_status'] === 'pending'): ?>
                                    <span class="badge badge-warning text-dark px-3 py-1 font-weight-bold"><i class="fas fa-clock mr-1"></i>Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-3 py-1 font-weight-bold"><i class="fas fa-times-circle mr-1"></i>Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right pr-4">
                                <?php if ($sub['submission_status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                                        <input type="hidden" name="student_id" value="<?= $sub['id'] ?>">
                                        <input type="hidden" name="action" value="approve_student">
                                        <button type="submit" class="btn btn-sm btn-success font-weight-bold shadow-sm" title="Approve & Activate">
                                            <i class="fas fa-check mr-1"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline ml-1">
                                        <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                                        <input type="hidden" name="student_id" value="<?= $sub['id'] ?>">
                                        <input type="hidden" name="action" value="reject_student">
                                        <button type="submit" class="btn btn-sm btn-outline-danger font-weight-bold shadow-sm" title="Reject Admission">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="../students/index.php" class="btn btn-sm btn-outline-secondary font-weight-bold" title="View in Student Directory">
                                        <i class="fas fa-user mr-1"></i> Profile
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copyLink() {
    var copyText = document.getElementById("publicLinkInput");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        alert("Public link copied to clipboard!");
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

<?php
// admin/deletion_requests/index.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// Only Super Admins and Root Tenant Admins can review deletions
if (is_sub_admin()) {
    echo "<div class='container my-5'><div class='alert alert-danger'>Access Denied: Only Super Administrators and Main Institute Admins can review and approve deletion requests.</div></div>";
    require_once __DIR__ . '/../../includes/admin_footer.php';
    exit;
}

$is_super = is_super_admin();
$current_admin_id = (int)$_SESSION['admin_id'];

// Handle Approval / Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $req_id = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'approve' or 'reject'
    $review_notes = trim($_POST['review_notes'] ?? '');
    
    if ($req_id > 0 && in_array($action, ['approve', 'reject'], true)) {
        // Fetch request
        $whereReq = $is_super ? "id = ?" : "id = ? AND parent_admin_id = ?";
        $paramsReq = $is_super ? [$req_id] : [$req_id, $current_admin_id];
        $stmtR = $pdo->prepare("SELECT * FROM deletion_requests WHERE $whereReq AND status = 'pending'");
        $stmtR->execute($paramsReq);
        $req = $stmtR->fetch(PDO::FETCH_ASSOC);
        
        if ($req) {
            $eType = $req['entity_type'];
            $eId = (int)$req['entity_id'];
            
            try {
                $pdo->beginTransaction();
                
                if ($action === 'approve') {
                    // Permanently execute deletion based on entity type
                    switch ($eType) {
                        case 'question':
                            $stmtL = $pdo->prepare("SELECT COUNT(*) FROM exam_questions WHERE question_id = ?");
                            $stmtL->execute([$eId]);
                            if ($stmtL->fetchColumn() > 0) {
                                $pdo->prepare("UPDATE questions SET is_archived = 1, deletion_pending = 0 WHERE id = ?")->execute([$eId]);
                            } else {
                                $pdo->prepare("DELETE FROM question_classes WHERE question_id = ?")->execute([$eId]);
                                $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$eId]);
                            }
                            break;
                            
                        case 'exam':
                            $pdo->prepare("DELETE FROM exams WHERE id = ?")->execute([$eId]);
                            break;
                            
                        case 'homework':
                            $pdo->prepare("DELETE FROM homework_submissions WHERE homework_id = ?")->execute([$eId]);
                            $pdo->prepare("DELETE FROM homework WHERE id = ?")->execute([$eId]);
                            break;
                            
                        case 'batch':
                            $pdo->prepare("DELETE FROM batch_students WHERE batch_id = ?")->execute([$eId]);
                            $pdo->prepare("DELETE FROM batches WHERE id = ?")->execute([$eId]);
                            break;
                    }
                    
                    $stmtUp = $pdo->prepare("UPDATE deletion_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?");
                    $stmtUp->execute([$current_admin_id, $review_notes ?: 'Approved for permanent deletion', $req_id]);
                    $_SESSION['flash_success'] = "Deletion request for {$eType} '{$req['entity_title']}' has been APPROVED and permanently deleted.";
                } else {
                    // Reject deletion and restore item to active state
                    $tableMap = [
                        'question' => 'questions',
                        'exam'     => 'exams',
                        'homework' => 'homework',
                        'batch'    => 'batches'
                    ];
                    if (isset($tableMap[$eType])) {
                        $tbl = $tableMap[$eType];
                        $pdo->prepare("UPDATE {$tbl} SET deletion_pending = 0 WHERE id = ?")->execute([$eId]);
                    }
                    
                    $stmtUp = $pdo->prepare("UPDATE deletion_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ? WHERE id = ?");
                    $stmtUp->execute([$current_admin_id, $review_notes ?: 'Rejected and preserved', $req_id]);
                    $_SESSION['flash_info'] = "Deletion request REJECTED. The {$eType} '{$req['entity_title']}' has been preserved and restored.";
                }
                
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash_error'] = "Action failed: " . $e->getMessage();
            }
        }
    }
    header('Location: index.php');
    exit;
}

$view = $_GET['view'] ?? 'pending';

// Fetch requests
$whereList = $is_super ? "1=1" : "dr.parent_admin_id = " . (int)$current_admin_id;
if ($view === 'history') {
    $whereList .= " AND dr.status IN ('approved', 'rejected')";
} else {
    $whereList .= " AND dr.status = 'pending'";
}

$stmtList = $pdo->prepare("
    SELECT dr.*, 
           u.username as staff_username, u.full_name as staff_fullname, u.sub_role as staff_sub_role,
           rev.username as reviewer_username,
           p.institute_name
    FROM deletion_requests dr
    JOIN admins u ON dr.requested_by = u.id
    LEFT JOIN admins rev ON dr.reviewed_by = rev.id
    LEFT JOIN admins p ON dr.parent_admin_id = p.id
    WHERE $whereList
    ORDER BY dr.created_at DESC
");
$stmtList->execute();
$requests = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = get_pending_deletion_count($pdo);
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Staff Deletion Approvals</h2>
        <span class="text-muted small">
            Review and approve or reject deletion requests submitted by teachers and sub-admin staff.
        </span>
    </div>
    <div>
        <a href="index.php?view=pending" class="btn <?= $view === 'pending' ? 'btn-primary' : 'btn-outline-primary' ?> font-weight-bold rounded-pill px-4 mr-2 shadow-sm">
            <i class="fas fa-hourglass-half mr-1"></i>Pending Review (<?= $pendingCount ?>)
        </a>
        <a href="index.php?view=history" class="btn <?= $view === 'history' ? 'btn-secondary' : 'btn-outline-secondary' ?> font-weight-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-history mr-1"></i>Audit History
        </a>
    </div>
</div>

<?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-check-circle mr-2"></i><?= h($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_info'])): ?>
    <div class="alert alert-info alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-info-circle mr-2"></i><?= h($_SESSION['flash_info']); unset($_SESSION['flash_info']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-lg py-3 px-4 mb-4">
        <i class="fas fa-exclamation-circle mr-2"></i><?= h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-0">
        <div class="table-responsive p-4">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th style="width: 12%;">Entity Type</th>
                        <th style="width: 32%;">Item Title / Details</th>
                        <th style="width: 20%;">Requested By</th>
                        <th style="width: 14%;">Timestamp</th>
                        <th style="width: 12%;">Status</th>
                        <th style="width: 10%; text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-shield-check fa-3x text-success mb-3 opacity-75"></i>
                            <h5 class="font-weight-bold text-dark">No <?= $view === 'history' ? 'historical records' : 'pending deletion requests' ?> found.</h5>
                            <p class="small mb-0">Staff deletions will appear here for your explicit authorization before any permanent removal takes place.</p>
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $r): 
                            $badgeColor = match($r['entity_type']) {
                                'question' => 'info',
                                'exam'     => 'danger',
                                'homework' => 'warning',
                                'batch'    => 'primary',
                                default    => 'secondary'
                            };
                            $isPending = ($r['status'] === 'pending');
                        ?>
                        <tr>
                            <td>
                                <span class="badge badge-<?= $badgeColor ?> px-3 py-2 font-weight-bold text-uppercase shadow-sm">
                                    <i class="fas fa-tag mr-1"></i><?= h($r['entity_type']) ?> #<?= $r['entity_id'] ?>
                                </span>
                            </td>
                            <td>
                                <div class="font-weight-bold text-dark mb-1"><?= h($r['entity_title']) ?></div>
                                <?php if (!empty($r['reason'])): ?>
                                    <div class="small text-muted font-italic"><i class="fas fa-comment mr-1"></i>Staff Note: <?= h($r['reason']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="rounded-circle bg-light text-primary font-weight-bold d-flex align-items-center justify-content-center mr-2" style="width: 34px; height: 34px;">
                                        <?= strtoupper(substr($r['staff_fullname'] ?: $r['staff_username'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="font-weight-bold text-dark small"><?= h($r['staff_fullname'] ?: $r['staff_username']) ?></div>
                                        <small class="text-muted"><i class="fas fa-user-tag mr-1"></i><?= h(ucfirst($r['staff_sub_role'] ?: 'Staff')) ?> (@<?= h($r['staff_username']) ?>)</small>
                                    </div>
                                </div>
                                <?php if ($is_super && !empty($r['institute_name'])): ?>
                                    <div class="small text-muted mt-1"><i class="fas fa-university mr-1"></i><?= h($r['institute_name']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted font-weight-bold">
                                <div><i class="fas fa-calendar mr-1"></i><?= date('d M, Y', strtotime($r['created_at'])) ?></div>
                                <div><i class="fas fa-clock mr-1"></i><?= date('h:i A', strtotime($r['created_at'])) ?></div>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <span class="badge badge-warning text-dark font-weight-bold px-2 py-1 shadow-sm">
                                        <i class="fas fa-hourglass-half mr-1"></i>PENDING APPROVAL
                                    </span>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="badge badge-danger font-weight-bold px-2 py-1 shadow-sm">
                                        <i class="fas fa-trash-alt mr-1"></i>APPROVED & DELETED
                                    </span>
                                    <?php if (!empty($r['reviewer_username'])): ?>
                                        <div class="small text-muted mt-1">by @<?= h($r['reviewer_username']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-success font-weight-bold px-2 py-1 shadow-sm">
                                        <i class="fas fa-undo mr-1"></i>REJECTED / RESTORED
                                    </span>
                                    <?php if (!empty($r['reviewer_username'])): ?>
                                        <div class="small text-muted mt-1">by @<?= h($r['reviewer_username']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-right">
                                <?php if ($isPending): ?>
                                    <div class="btn-group">
                                        <form method="POST" class="d-inline mr-1" onsubmit="return confirm('APPROVE and permanently delete <?= h($r['entity_type']) ?> \'<?= h(addslashes($r['entity_title'])) ?>\'?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-sm btn-danger font-weight-bold rounded-pill px-3 shadow-sm" title="Approve permanent deletion">
                                                <i class="fas fa-check mr-1"></i>Approve Delete
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('REJECT deletion and restore this item safely?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill px-3" title="Reject and restore item">
                                                <i class="fas fa-undo mr-1"></i>Reject & Keep
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small font-italic">Resolved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

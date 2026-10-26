<?php
// admin/exams/delete.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    
    if ($id) {
        $stmtEx = $pdo->prepare("SELECT id, title, admin_id FROM exams WHERE id = ?");
        $stmtEx->execute([$id]);
        $exam = $stmtEx->fetch(PDO::FETCH_ASSOC);

        if ($exam) {
            $effective_admin_id = get_effective_admin_id();
            if (!is_super_admin()) {
                // Verify exam belongs to current institute
                $chkInst = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND (id = ? OR parent_admin_id = ?)");
                $chkInst->execute([$exam['admin_id'], $effective_admin_id, $effective_admin_id]);
                if (!$chkInst->fetch()) {
                    $_SESSION['flash_error'] = "Access Denied: You do not have permission to delete this exam.";
                    header('Location: ' . ($_POST['redirect_url'] ?? 'index.php'));
                    exit;
                }
            }

            // If sub-admin (teacher/staff), queue deletion for Super Admin approval
            $res = queue_or_execute_deletion('exam', $id, $exam['title'], 'Requested by staff member', null, $pdo);
            if ($res['queued']) {
                $_SESSION['flash_info'] = $res['message'];
            } else {
                // Hard deletion for full admin / super admin
                $pdo->prepare("DELETE FROM exams WHERE id = ?")->execute([$id]);
                $_SESSION['flash_success'] = "Exam '" . $exam['title'] . "' has been deleted successfully.";
            }
        }
    }
}
$redirect = $_POST['redirect_url'] ?? 'index.php';
header('Location: ' . $redirect);
exit;

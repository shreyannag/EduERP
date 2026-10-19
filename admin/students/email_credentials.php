<?php
// admin/students/email_credentials.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}
verify_csrf_token($_POST['csrf_token'] ?? '');

$ids = $_POST['id'] ?? [];
if (empty($ids) || !is_array($ids)) {
    die("No students selected.");
}

$in = str_repeat('?,', count($ids) - 1) . '?';
$stmt = $pdo->prepare("SELECT id, full_name, email, username, plain_password, admin_id FROM students WHERE id IN ($in)");
$stmt->execute($ids);
$students = $stmt->fetchAll();

$successCount = 0;
$failCount = 0;
$lastError = '';

foreach ($students as $s) {
    if (!empty($s['email'])) {
        $result = send_student_credentials($s['email'], $s['full_name'], $s['username'], $s['plain_password'], $s['admin_id'], $pdo);
        if ($result === true) {
            $successCount++;
        } else {
            $failCount++;
            $lastError = $result; // Capture the error string
        }
    } else {
        $failCount++;
        $lastError = "Student does not have an email address set.";
    }
}

$msg = "Sent credentials to $successCount student(s). Failed for $failCount.";
if ($failCount > 0 && $lastError) {
    $msg .= " Last Error: " . addslashes($lastError);
}
?>
<div id="alert-message" data-message="<?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>"></div>
<script>
    var msg = document.getElementById('alert-message').getAttribute('data-message');
    alert(msg);
    window.location.href = 'index.php';
</script>
<?php
exit;

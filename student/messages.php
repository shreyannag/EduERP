<?php
// student/messages.php
require_once '../includes/student_header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $msg = trim($_POST['message'] ?? '');
    if ($msg) {
        $target_admin_id = !empty($current_student['admin_id']) ? (int)$current_student['admin_id'] : 1;
        
        $stmt = $pdo->prepare("INSERT INTO messages (sender_type, sender_id, receiver_id, message) VALUES ('student', ?, ?, ?)");
        $stmt->execute([$student_id, $target_admin_id, $msg]);
    }
    header("Location: messages.php");
    exit;
}

$stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_type = 'admin' AND receiver_id = ?");
$stmt->execute([$student_id]);

$stmt = $pdo->prepare("
    SELECT * FROM messages 
    WHERE (sender_type = 'admin' AND receiver_id = ?)
       OR (sender_type = 'student' AND sender_id = ?)
    ORDER BY created_at ASC
");
$stmt->execute([$student_id, $student_id]);
$messages = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Administrative Comms Link</h2>
</div>

<div class="row justify-content-center mb-4" style="min-height: 60vh;">
    <div class="col-lg-8 h-100">
        <div class="card shadow-sm border-0 rounded-lg h-100 d-flex flex-column">
            <div class="card-header bg-white border-bottom pt-3 pb-3 px-4 d-flex align-items-center">
                <i class="fas fa-university fa-2x text-primary mr-3"></i>
                <div>
                    <h5 class="font-weight-bold mb-0 text-dark"><?php echo h(!empty($current_student['institute_name']) ? $current_student['institute_name'] : 'Institute Administration'); ?></h5>
                    <small class="text-muted">Direct Administrative Communication Link</small>
                </div>
            </div>
            <div class="card-body p-4 bg-light flex-grow-1" style="max-height: 50vh; overflow-y: auto;" id="chatBox">
                <?php if(empty($messages)): ?>
                    <div class="text-center text-muted my-5">
                        <i class="fas fa-satellite fa-3x mb-3 opacity-25"></i>
                        <p>Secure channel established. No prior transmissions.</p>
                    </div>
                <?php else: ?>
                    <?php foreach($messages as $m): 
                        $isMe = $m['sender_type'] === 'student';
                    ?>
                        <div class="d-flex w-100 mb-3 <?php echo $isMe ? 'justify-content-end' : 'justify-content-start'; ?>">
                            <div class="px-4 py-2 rounded-lg shadow-sm" style="max-width: 75%; <?php echo $isMe ? 'background: linear-gradient(135deg, #1e3a8a, #3b82f6); color:#fff;' : 'background-color:#fff; border:1px solid #e5e7eb;'; ?>">
                                <p class="mb-1" style="white-space: pre-wrap; font-size: 0.95rem;"><?php echo h($m['message']); ?></p>
                                <div class="text-right">
                                    <small class="<?php echo $isMe ? 'text-white-50' : 'text-muted'; ?>" style="font-size: 0.75rem;"><?php echo date('M d, H:i', strtotime($m['created_at'])); ?></small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-white border-top-0 p-4 rounded-bottom">
                <form method="POST" action="messages.php" class="d-flex">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <input type="text" name="message" class="form-control form-control-lg border-0 bg-light shadow-sm mr-3" placeholder="Compose inquiry..." required autocomplete="off">
                    <button type="submit" class="btn btn-primary btn-lg font-weight-bold px-4 rounded-pill shadow-sm"><i class="fas fa-paper-plane"></i></button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let cb = document.getElementById('chatBox');
    if(cb) cb.scrollTop = cb.scrollHeight;
});
</script>

<?php require_once '../includes/student_footer.php'; ?>

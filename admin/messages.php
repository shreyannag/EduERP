<?php
// admin/messages.php
// Integrated Communication Relay: Student Inquiries & Super Admin / Admin Direct Chat

require_once '../includes/admin_header.php';

$current_admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();
$channel = $_GET['channel'] ?? 'students';

// If channel is students, verify feature access; admin network chat is available to all admins
if ($channel === 'students' && !admin_has_feature('messages')) {
    $channel = 'admin';
}

// Handle POST transmissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $post_channel = $_POST['channel'] ?? 'students';

    if ($post_channel === 'admin') {
        $target_admin_id = (int)($_POST['target_admin_id'] ?? 0);
        $msg = trim($_POST['message'] ?? '');

        if ($target_admin_id > 0 && !empty($msg)) {
            $stmt = $pdo->prepare("INSERT INTO admin_messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$current_admin_id, $target_admin_id, $msg]);
        }
        header("Location: messages.php?channel=admin&target_admin_id=" . $target_admin_id);
        exit;
    } else {
        $receiver_id = (int)($_POST['student_id'] ?? 0);
        $msg = trim($_POST['message'] ?? '');
        if ($receiver_id && $msg) {
            $stmt = $pdo->prepare("INSERT INTO messages (sender_type, sender_id, receiver_id, message) VALUES ('admin', ?, ?, ?)");
            $stmt->execute([$current_admin_id, $receiver_id, $msg]);
        }
        header("Location: messages.php?channel=students&student_id=" . $receiver_id);
        exit;
    }
}

// -------------------------------------------------------------
// CHANNEL 1: STUDENT COMMUNICATIONS
// -------------------------------------------------------------
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$students = [];
$student_messages = [];
$current_student = null;

if ($channel === 'students') {
    if ($student_id) {
        $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_type = 'student' AND sender_id = ? AND receiver_id = ?");
        $stmt->execute([$student_id, $current_admin_id]);
    }

    $studentWhere = $is_super ? "" : "WHERE s.admin_id = " . $current_admin_id;
    $students = $pdo->query("
        SELECT s.id, s.full_name, s.username,
        (SELECT COUNT(*) FROM messages WHERE sender_type = 'student' AND sender_id = s.id AND receiver_id = $current_admin_id AND is_read = 0) as unread,
        (SELECT created_at FROM messages WHERE (sender_type = 'student' AND sender_id = s.id AND receiver_id = $current_admin_id) OR (sender_type = 'admin' AND sender_id = $current_admin_id AND receiver_id = s.id) ORDER BY created_at DESC LIMIT 1) as last_activity
        FROM students s
        $studentWhere
        ORDER BY unread DESC, last_activity DESC, s.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($student_id) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $current_student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("
            SELECT * FROM messages 
            WHERE (sender_type = 'student' AND sender_id = ? AND receiver_id = ?)
               OR (sender_type = 'admin' AND sender_id = ? AND receiver_id = ?)
            ORDER BY created_at ASC
        ");
        $stmt->execute([$student_id, $current_admin_id, $current_admin_id, $student_id]);
        $student_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// -------------------------------------------------------------
// CHANNEL 2: SUPER ADMIN & ADMIN INTEGRATED MESSAGING
// -------------------------------------------------------------
$target_admin_id = isset($_GET['target_admin_id']) ? intval($_GET['target_admin_id']) : 0;
$admin_roster = [];
$admin_messages = [];
$current_target_admin = null;

if ($channel === 'admin') {
    if ($is_super) {
        // Super Admin sees all tenant administrators
        $stmtAdmins = $pdo->prepare("
            SELECT a.id, a.username, a.institute_name, a.email, a.role, a.support_permission,
            (SELECT COUNT(*) FROM admin_messages WHERE sender_id = a.id AND receiver_id = ? AND is_read = 0) as unread,
            (SELECT created_at FROM admin_messages WHERE (sender_id = a.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = a.id) ORDER BY created_at DESC LIMIT 1) as last_activity
            FROM admins a
            WHERE a.id != ?
            ORDER BY unread DESC, last_activity DESC, a.username ASC
        ");
        $stmtAdmins->execute([$current_admin_id, $current_admin_id, $current_admin_id, $current_admin_id]);
        $admin_roster = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        // Auto-select first admin if none selected
        if ($target_admin_id <= 0 && !empty($admin_roster)) {
            $target_admin_id = (int)$admin_roster[0]['id'];
        }
    } else {
        // Tenant Admin sees the primary Super Administrator
        $stmtSuper = $pdo->query("
            SELECT a.id, a.username, a.institute_name, a.email, a.role,
            (SELECT COUNT(*) FROM admin_messages WHERE sender_id = a.id AND receiver_id = $current_admin_id AND is_read = 0) as unread,
            (SELECT created_at FROM admin_messages WHERE (sender_id = a.id AND receiver_id = $current_admin_id) OR (sender_id = $current_admin_id AND receiver_id = a.id) ORDER BY created_at DESC LIMIT 1) as last_activity
            FROM admins a
            WHERE a.role = 'super_admin' AND a.is_active = 1
            ORDER BY a.id ASC
            LIMIT 1
        ");
        $superAdminRecord = $stmtSuper->fetch(PDO::FETCH_ASSOC);
        if ($superAdminRecord) {
            $admin_roster = [$superAdminRecord];
            $target_admin_id = (int)$superAdminRecord['id'];
        }
    }

    if ($target_admin_id > 0) {
        // Fetch target admin details
        $stmtTarget = $pdo->prepare("SELECT id, username, institute_name, email, role, support_permission FROM admins WHERE id = ?");
        $stmtTarget->execute([$target_admin_id]);
        $current_target_admin = $stmtTarget->fetch(PDO::FETCH_ASSOC);

        // Mark incoming messages as read
        $updRead = $pdo->prepare("UPDATE admin_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $updRead->execute([$target_admin_id, $current_admin_id]);

        // Load conversation
        $stmtConv = $pdo->prepare("
            SELECT m.*, sender.username as sender_username, sender.role as sender_role
            FROM admin_messages m
            JOIN admins sender ON m.sender_id = sender.id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ");
        $stmtConv->execute([$current_admin_id, $target_admin_id, $target_admin_id, $current_admin_id]);
        $admin_messages = $stmtConv->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Global unread counters for channel tabs
$total_unread_students = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE sender_type = 'student' AND receiver_id = $current_admin_id AND is_read = 0")->fetchColumn();
$total_unread_admins = (int)$pdo->query("SELECT COUNT(*) FROM admin_messages WHERE receiver_id = $current_admin_id AND is_read = 0")->fetchColumn();
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-comments text-primary mr-2"></i>Integrated Communication Relay
        </h2>
        <p class="text-muted mb-0 small">
            Dual-channel messaging system for student inquiries and direct administrator collaboration.
        </p>
    </div>

    <!-- Channel Selector Tabs -->
    <div class="btn-group btn-group-toggle mt-3 mt-md-0 shadow-sm" style="border-radius: 999px; overflow: hidden;">
        <?php if (admin_has_feature('messages')): ?>
        <a href="messages.php?channel=students" class="btn font-weight-bold px-4 py-2 <?= $channel === 'students' ? 'btn-primary' : 'btn-light border' ?>">
            <i class="fas fa-user-graduate mr-1"></i> Student Inquiries
            <?php if ($total_unread_students > 0): ?>
                <span class="badge badge-danger ml-1 font-weight-bold"><?= $total_unread_students ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <a href="messages.php?channel=admin" class="btn font-weight-bold px-4 py-2 <?= $channel === 'admin' ? 'btn-primary' : 'btn-light border' ?>">
            <i class="fas fa-shield-alt mr-1"></i> <?= $is_super ? 'Admin Network' : 'Super Admin Support Chat' ?>
            <?php if ($total_unread_admins > 0): ?>
                <span class="badge badge-danger ml-1 font-weight-bold"><?= $total_unread_admins ?></span>
            <?php endif; ?>
        </a>
    </div>
</div>

<!-- ========================================================================= -->
<!-- CHANNEL VIEW: ADMIN NETWORK / SUPER ADMIN CHAT                           -->
<!-- ========================================================================= -->
<?php if ($channel === 'admin'): ?>
<div class="row mb-4" style="min-height: 65vh;">
    <!-- Left Column: Admin Contacts Roster -->
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="font-weight-bold mb-0 text-dark">
                    <i class="fas fa-users-cog mr-2 text-primary"></i><?= $is_super ? 'Tenant Administrators' : 'Platform Support' ?>
                </h6>
                <span class="badge badge-light border font-weight-bold text-muted"><?= count($admin_roster) ?> Contacts</span>
            </div>
            <div class="card-body p-0" style="max-height: 60vh; overflow-y: auto;">
                <?php if (empty($admin_roster)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-user-slash fa-2x mb-2 text-black-50"></i>
                        <p class="small mb-0">No active administrator contacts found.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($admin_roster as $adm): 
                            $isSelected = ($target_admin_id === (int)$adm['id']);
                            $admIsSuper = ($adm['role'] === 'super_admin');
                        ?>
                            <a href="messages.php?channel=admin&target_admin_id=<?= $adm['id'] ?>" class="list-group-item list-group-item-action py-3 px-3 <?= $isSelected ? 'bg-primary text-white' : '' ?>" style="transition: all 0.15s ease;">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center" style="min-width: 0;">
                                        <div class="rounded-circle <?= $admIsSuper ? 'bg-warning text-dark' : ($isSelected ? 'bg-white text-primary' : 'bg-primary text-white') ?> d-flex align-items-center justify-content-center font-weight-bold shadow-xs mr-3 flex-shrink-0" style="width: 42px; height: 42px; font-size: 1.05rem;">
                                            <?= strtoupper(substr($adm['username'], 0, 1)) ?>
                                        </div>
                                        <div style="min-width: 0;">
                                            <div class="font-weight-bold text-truncate <?= $isSelected ? 'text-white' : 'text-dark' ?>">
                                                <?= h($adm['username']) ?>
                                                <?php if ($admIsSuper): ?>
                                                    <i class="fas fa-crown text-warning ml-1" title="Super Administrator"></i>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-truncate d-block <?= $isSelected ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.76rem;">
                                                <?= h($adm['institute_name'] ?: 'Main Campus') ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0 ml-2">
                                        <?php if ((int)($adm['unread'] ?? 0) > 0): ?>
                                            <span class="badge badge-danger badge-pill px-2 py-1 shadow-sm"><?= (int)$adm['unread'] ?> New</span>
                                        <?php elseif (!empty($adm['last_activity'])): ?>
                                            <small class="<?= $isSelected ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.7rem;">
                                                <?= date('M d', strtotime($adm['last_activity'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Conversation Stage -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0 rounded-lg h-100 d-flex flex-column">
            <?php if ($target_admin_id > 0 && $current_target_admin): 
                $isTargetSuper = ($current_target_admin['role'] === 'super_admin');
            ?>
                <!-- Chat Stage Header -->
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap" style="gap: 10px;">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle <?= $isTargetSuper ? 'bg-warning text-dark' : 'bg-primary text-white' ?> d-flex align-items-center justify-content-center font-weight-bold mr-3 shadow-xs" style="width: 44px; height: 44px; font-size: 1.15rem;">
                            <?= strtoupper(substr($current_target_admin['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <h6 class="font-weight-bold mb-0 text-dark">
                                <?= h($current_target_admin['username']) ?>
                                <?php if ($isTargetSuper): ?>
                                    <span class="badge badge-warning text-dark ml-1 font-weight-bold"><i class="fas fa-crown mr-1"></i> Super Admin</span>
                                <?php else: ?>
                                    <span class="badge badge-primary ml-1 font-weight-bold"><i class="fas fa-user-shield mr-1"></i> Institute Admin</span>
                                <?php endif; ?>
                            </h6>
                            <small class="text-muted">
                                <i class="fas fa-university mr-1"></i><?= h($current_target_admin['institute_name'] ?: 'Main Campus') ?>
                                <?php if (!empty($current_target_admin['email'])): ?>
                                    &bull; <a href="mailto:<?= h($current_target_admin['email']) ?>" class="text-muted"><?= h($current_target_admin['email']) ?></a>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>

                    <!-- Header Quick Actions -->
                    <div class="d-flex align-items-center flex-wrap" style="gap: 6px;">
                        <?php if ($is_super && !$isTargetSuper): ?>
                            <?php if (!empty($current_target_admin['support_permission'])): ?>
                                <a href="image_storage.php?fix_admin_id=<?= $current_target_admin['id'] ?>" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill px-3 shadow-xs">
                                    <i class="fas fa-tools mr-1"></i> Fix Cloudinary CDN
                                </a>
                            <?php else: ?>
                                <span class="badge badge-secondary px-3 py-2 rounded-pill font-weight-bold" title="Individual Ownership Protected: Support Permission disabled by this admin">
                                    <i class="fas fa-lock mr-1"></i> Settings Locked
                                </span>
                            <?php endif; ?>
                            <a href="admins/index.php#admin-row-<?= $current_target_admin['id'] ?>" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-3 shadow-xs">
                                <i class="fas fa-cog mr-1"></i> Manage Account
                            </a>
                        <?php elseif (!$is_super): ?>
                            <a href="settings.php#tab-ownership" class="btn btn-sm btn-outline-info font-weight-bold rounded-pill px-3 shadow-xs">
                                <i class="fas fa-user-shield mr-1"></i> Support Permission
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chat Messages Body -->
                <div class="card-body p-4 bg-light flex-grow-1" style="max-height: 52vh; overflow-y: auto;" id="adminChatBox">
                    <?php if (empty($admin_messages)): ?>
                        <div class="text-center text-muted my-5">
                            <i class="fas fa-comments fa-3x mb-3 text-black-50 opacity-25"></i>
                            <h6 class="font-weight-bold">Start a Direct Conversation</h6>
                            <p class="small text-muted mb-0">
                                Send a message to discuss system configurations, billing details, or share authorization codes.
                            </p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($admin_messages as $m): 
                            $isMe = ((int)$m['sender_id'] === $current_admin_id);
                        ?>
                            <div class="d-flex w-100 mb-3 <?= $isMe ? 'justify-content-end' : 'justify-content-start' ?>">
                                <div class="px-4 py-3 rounded-lg shadow-sm" style="max-width: 78%; <?= $isMe ? 'background: #1e3a8a; color: #ffffff; border-bottom-right-radius: 2px;' : 'background: #ffffff; color: #1e293b; border: 1px solid #e2e8f0; border-bottom-left-radius: 2px;' ?>">
                                    <?php if (!$isMe): ?>
                                        <div class="font-weight-bold small mb-1 text-primary">
                                            <?= h($m['sender_username']) ?>
                                            <?php if ($m['sender_role'] === 'super_admin'): ?>
                                                <span class="badge badge-warning text-dark font-weight-bold" style="font-size: 0.65rem;">Super Admin</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <p class="mb-1" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5;"><?= h($m['message']) ?></p>
                                    <div class="text-right">
                                        <small class="<?= $isMe ? 'text-white-50' : 'text-muted' ?>" style="font-size: 0.72rem;">
                                            <?= date('M d, g:i A', strtotime($m['created_at'])) ?>
                                            <?php if ($isMe): ?>
                                                <i class="fas <?= !empty($m['is_read']) ? 'fa-check-double text-info' : 'fa-check' ?> ml-1"></i>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Chat Footer Message Box -->
                <div class="card-footer bg-white border-top p-3 rounded-bottom">
                    <form method="POST" action="messages.php" class="d-flex align-items-center">
                        <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                        <input type="hidden" name="channel" value="admin">
                        <input type="hidden" name="target_admin_id" value="<?= $target_admin_id ?>">
                        <input type="text" name="message" class="form-control form-control-lg border-0 bg-light shadow-sm mr-2" placeholder="Type transmission payload..." required autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-lg font-weight-bold px-4 rounded-pill shadow-sm">
                            <i class="fas fa-paper-plane mr-1"></i> Send
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center p-5">
                    <i class="fas fa-comment-dots fa-4x text-light mb-4 opacity-50"></i>
                    <h5 class="font-weight-bold text-muted">Select an Administrator</h5>
                    <p class="text-muted small">Choose an administrator from the roster to start a direct message thread.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- CHANNEL VIEW: STUDENT INQUIRIES                                           -->
<!-- ========================================================================= -->
<?php else: ?>
<div class="row mb-4" style="min-height: 65vh;">
    <!-- Left Column: Student Roster -->
    <div class="col-md-4 mb-3 mb-md-0">
        <div class="card shadow-sm border-0 rounded-lg h-100">
            <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="font-weight-bold mb-0 text-dark">
                    <i class="fas fa-user-graduate mr-2 text-primary"></i>Student Inquiries
                </h6>
                <span class="badge badge-light border font-weight-bold text-muted"><?= count($students) ?> Students</span>
            </div>
            <div class="card-body p-0" style="max-height: 60vh; overflow-y: auto;">
                <?php if (empty($students)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-user-slash fa-2x mb-2 text-black-50"></i>
                        <p class="small mb-0">No enrolled students found.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach($students as $s): ?>
                            <a href="messages.php?channel=students&student_id=<?php echo $s['id']; ?>" class="list-group-item list-group-item-action py-3 px-3 <?php echo $student_id == $s['id'] ? 'bg-primary text-white' : ''; ?>" style="transition: all 0.15s ease;">
                                <div class="d-flex w-100 justify-content-between align-items-center">
                                    <div class="d-flex align-items-center" style="min-width: 0;">
                                        <div class="rounded-circle <?php echo $student_id == $s['id'] ? 'bg-white text-primary' : 'bg-primary text-white'; ?> d-flex align-items-center justify-content-center font-weight-bold shadow-xs mr-3 flex-shrink-0" style="width: 40px; height: 40px; font-size: 1rem;">
                                            <?php echo strtoupper(substr($s['full_name'], 0, 1)); ?>
                                        </div>
                                        <div style="min-width: 0;">
                                            <h6 class="mb-0 font-weight-bold text-truncate <?php echo $student_id == $s['id'] ? 'text-white' : 'text-dark'; ?>"><?php echo h($s['full_name']); ?></h6>
                                            <small class="text-truncate d-block <?php echo $student_id == $s['id'] ? 'text-white-50' : 'text-muted'; ?>" style="font-size: 0.75rem;"><?php echo h($s['username']); ?></small>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0 ml-2">
                                        <?php if($s['unread'] > 0): ?>
                                            <span class="badge badge-danger badge-pill px-2 py-1 shadow-sm"><?php echo $s['unread']; ?> New</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Student Chat View -->
    <div class="col-md-8">
        <div class="card shadow-sm border-0 rounded-lg h-100 d-flex flex-column">
            <?php if($student_id && $current_student): ?>
                <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center font-weight-bold mr-3 shadow-xs" style="width: 42px; height: 42px;">
                            <?= strtoupper(substr($current_student['full_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <h6 class="font-weight-bold mb-0 text-dark"><?php echo h($current_student['full_name']); ?></h6>
                            <small class="text-muted"><i class="fas fa-id-card mr-1"></i><?php echo h($current_student['username']); ?></small>
                        </div>
                    </div>
                    <a href="students/index.php" class="btn btn-sm btn-outline-secondary font-weight-bold rounded-pill px-3">
                        <i class="fas fa-user-edit mr-1"></i> View Profile
                    </a>
                </div>
                <div class="card-body p-4 bg-light flex-grow-1" style="max-height: 52vh; overflow-y: auto;" id="studentChatBox">
                    <?php if(empty($student_messages)): ?>
                        <div class="text-center text-muted my-5">
                            <i class="fas fa-envelope-open-text fa-3x mb-3 opacity-25"></i>
                            <p>No messages established in this channel.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($student_messages as $m): 
                            $isMe = $m['sender_type'] === 'admin';
                        ?>
                            <div class="d-flex w-100 mb-3 <?php echo $isMe ? 'justify-content-end' : 'justify-content-start'; ?>">
                                <div class="px-4 py-3 rounded-lg shadow-sm" style="max-width: 78%; <?php echo $isMe ? 'background-color:#1e3a8a; color:#fff; border-bottom-right-radius: 2px;' : 'background-color:#fff; color: #1e293b; border:1px solid #e5e7eb; border-bottom-left-radius: 2px;'; ?>">
                                    <p class="mb-1" style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5;"><?php echo h($m['message']); ?></p>
                                    <div class="text-right">
                                        <small class="<?php echo $isMe ? 'text-white-50' : 'text-muted'; ?>" style="font-size: 0.72rem;"><?php echo date('M d, H:i', strtotime($m['created_at'])); ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white border-top p-3 rounded-bottom">
                    <form method="POST" action="messages.php" class="d-flex align-items-center">
                        <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                        <input type="hidden" name="channel" value="students">
                        <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="text" name="message" class="form-control form-control-lg border-0 bg-light shadow-sm mr-2" placeholder="Type transmission payload..." required autocomplete="off">
                        <button type="submit" class="btn btn-primary btn-lg font-weight-bold px-4 rounded-pill shadow-sm"><i class="fas fa-paper-plane mr-1"></i> Send</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center p-5">
                    <i class="fas fa-comment-dots fa-4x text-light mb-4 opacity-50"></i>
                    <h5 class="font-weight-bold text-muted">Select a Student</h5>
                    <p class="text-muted small">Choose an enrolled student to initiate or resume an inquiry channel.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    let cb = document.getElementById('adminChatBox') || document.getElementById('studentChatBox');
    if(cb) {
        cb.scrollTop = cb.scrollHeight;
    }
});
</script>

<?php require_once '../includes/admin_footer.php'; ?>

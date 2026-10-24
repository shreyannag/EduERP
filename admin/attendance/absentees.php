<?php
// admin/attendance/absentees.php
require_once '../../includes/admin_header.php';

require_admin_feature('attendance');

$effective_admin_id = is_super_admin() ? null : get_effective_admin_id();
$register_id = !empty($_GET['register_id']) ? (int)$_GET['register_id'] : 0;

if ($register_id <= 0) {
    $_SESSION['flash_error'] = "Invalid register ID.";
    header("Location: index.php");
    exit;
}

// Fetch register details
$regWhere = "ar.id = ?";
$regParams = [$register_id];
if (!is_super_admin()) {
    $regWhere .= " AND ar.admin_id = ?";
    $regParams[] = $effective_admin_id;
}

$stmtReg = $pdo->prepare("
    SELECT ar.*, c.name as class_name, b.name as batch_name, adm.institute_name, adm.username as admin_user,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id) as total_recorded,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'offline') as count_offline,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'online') as count_online,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'absent') as count_absent,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'late') as count_late,
           (SELECT COUNT(*) FROM attendance_records WHERE register_id = ar.id AND status = 'absent' AND parent_notified = 1) as count_notified
    FROM attendance_registers ar
    JOIN classes c ON ar.class_id = c.id
    LEFT JOIN batches b ON ar.batch_id = b.id
    JOIN admins adm ON ar.admin_id = adm.id
    WHERE $regWhere
");
$stmtReg->execute($regParams);
$register = $stmtReg->fetch(PDO::FETCH_ASSOC);

if (!$register) {
    $_SESSION['flash_error'] = "Attendance register not found or access denied.";
    header("Location: index.php");
    exit;
}

// Fetch absent students
$stmtAbs = $pdo->prepare("
    SELECT rec.id as record_id, rec.register_id, rec.student_id, rec.status, rec.remarks,
           rec.parent_notified, rec.parent_notified_at, rec.parent_notification_channel,
           s.full_name, s.username, s.email, s.phone, s.parent_name, s.parent_phone, s.roll_no,
           c.name as class_name
    FROM attendance_records rec
    JOIN students s ON rec.student_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE rec.register_id = ? AND rec.status = 'absent'
    ORDER BY s.full_name ASC
");
$stmtAbs->execute([$register_id]);
$absentees = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);

$totalAbsent = count($absentees);
$notifiedCount = (int)$register['count_notified'];
$pendingCount = max(0, $totalAbsent - $notifiedCount);
$instituteTitle = !empty($register['institute_name']) ? $register['institute_name'] : 'EduERP Institute';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <div class="d-flex align-items-center">
            <a href="index.php?date=<?= urlencode($register['session_date']) ?>" class="btn btn-outline-secondary rounded-pill btn-sm mr-3 font-weight-bold shadow-xs">
                <i class="fas fa-arrow-left mr-1"></i> Attendance Registers
            </a>
            <h2 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-user-times text-danger mr-2"></i>Absentee Follow-up &amp; Parent Alerts
            </h2>
        </div>
        <p class="text-muted small mb-0 mt-1">
            Session: <strong><?= h($register['session_title'] ?: 'Class Session') ?></strong> (Class <?= h($register['class_name']) ?><?= !empty($register['batch_name']) ? ' - ' . h($register['batch_name']) : '' ?>) on <strong><?= date('d M Y, l', strtotime($register['session_date'])) ?></strong>
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap" style="gap: 8px;">
        <a href="take.php?register_id=<?= $register['id'] ?>" class="btn btn-outline-primary font-weight-bold rounded-pill px-3 shadow-xs">
            <i class="fas fa-edit mr-1"></i> Edit Register
        </a>
        <?php if ($pendingCount > 0): ?>
        <button type="button" class="btn btn-danger font-weight-bold rounded-pill px-4 shadow-sm" id="notifyAllBtn" onclick="notifyAllParents()">
            <i class="fas fa-paper-plane mr-1"></i> Notify All Pending Parents (<?= $pendingCount ?>)
        </button>
        <?php else: ?>
        <button type="button" class="btn btn-success font-weight-bold rounded-pill px-4 shadow-sm" disabled>
            <i class="fas fa-check-circle mr-1"></i> All Absent Parents Notified
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Session Summary KPI Row -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-dark" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Total Roster</div>
            <h3 class="font-weight-bold text-dark mb-0 mt-1"><?= (int)$register['total_recorded'] ?> Students</h3>
            <small class="text-muted">Recorded on <?= date('d M Y', strtotime($register['session_date'])) ?></small>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-success" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Present (Offline + Online)</div>
            <h3 class="font-weight-bold text-success mb-0 mt-1">
                <?= (int)$register['count_offline'] + (int)$register['count_online'] ?>
            </h3>
            <small class="text-muted"><?= (int)$register['count_offline'] ?> In-person • <?= (int)$register['count_online'] ?> Portal</small>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-danger" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Total Absentees</div>
            <h3 class="font-weight-bold text-danger mb-0 mt-1"><?= $totalAbsent ?></h3>
            <small class="text-muted">Unattended scheduled lecture</small>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card border-0 shadow-sm rounded-lg p-3 bg-white border-left border-info" style="border-left-width: 5px !important;">
            <div class="text-muted small text-uppercase font-weight-bold">Parent Alert Status</div>
            <h3 class="font-weight-bold text-info mb-0 mt-1"><?= $notifiedCount ?> / <?= $totalAbsent ?></h3>
            <small class="text-muted"><?= $pendingCount ?> alerts pending dispatcher</small>
        </div>
    </div>
</div>

<!-- Absent Students Roster -->
<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center flex-wrap">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-bell text-danger mr-2"></i>Absentee Follow-up Roster
            </h5>
            <small class="text-muted">Directly dispatch email notifications and open WhatsApp messages for parents.</small>
        </div>
        <div class="mt-2 mt-md-0">
            <span class="badge badge-danger px-3 py-2 font-weight-bold">
                <?= $totalAbsent ?> Absent Students
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($absentees)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-glass-cheers fa-3x mb-3 text-success d-block"></i>
                <h5 class="font-weight-bold text-dark">Zero Absentees for This Session!</h5>
                <p class="small text-muted mb-0">All enrolled students were present either in classroom or logged in online.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive p-4">
                <table class="table table-hover table-bordered mb-0" id="absenteesTable">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Student Information</th>
                            <th>Parent Details &amp; Contact</th>
                            <th>Remarks</th>
                            <th class="text-center">Notification Status</th>
                            <th class="text-right px-4">Instant Alert Channels</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 0;
                        foreach ($absentees as $abs): 
                            $i++;
                            $sName = $abs['full_name'];
                            $pName = !empty($abs['parent_name']) ? $abs['parent_name'] : 'Parent';
                            $pPhone = !empty($abs['parent_phone']) ? $abs['parent_phone'] : $abs['phone'];
                            $pEmail = !empty($abs['email']) ? $abs['email'] : '';
                            $notified = (int)$abs['parent_notified'] === 1;

                            // Format WhatsApp link
                            $cleanPhone = preg_replace('/[^0-9]/', '', (string)$pPhone);
                            if (strlen($cleanPhone) === 10) {
                                $cleanPhone = '91' . $cleanPhone;
                            }
                            $waMsg = "Dear {$pName},\n\nThis is to inform you that your child {$sName} was marked ABSENT for today's {$register['session_title']} (Class {$register['class_name']}) on " . date('d M Y', strtotime($register['session_date'])) . ".\n\nPlease contact {$instituteTitle} for queries or leave justification.\n\nRegards,\n{$instituteTitle}";
                            $waUrl = !empty($cleanPhone) ? "https://wa.me/{$cleanPhone}?text=" . rawurlencode($waMsg) : '';
                        ?>
                        <tr id="row-student-<?= (int)$abs['student_id'] ?>">
                            <td class="align-middle font-weight-bold text-muted"><?= $i ?></td>
                            <td class="align-middle">
                                <div class="font-weight-bold text-dark" style="font-size: 0.95rem;">
                                    <?= h($sName) ?>
                                    <?php if (!empty($abs['roll_no'])): ?>
                                        <span class="badge badge-light border text-muted ml-1">Roll #<?= h($abs['roll_no']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <i class="fas fa-user text-muted mr-1"></i><?= h($abs['username']) ?> • Class <?= h($abs['class_name']) ?>
                                </div>
                            </td>
                            <td class="align-middle">
                                <div class="font-weight-bold text-dark">
                                    <i class="fas fa-user-shield text-muted mr-1"></i><?= h($pName) ?>
                                </div>
                                <div class="small text-muted">
                                    <?php if (!empty($pPhone)): ?>
                                        <span><i class="fas fa-phone-alt text-success mr-1"></i><?= h($pPhone) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($pEmail)): ?>
                                        <span class="ml-2"><i class="fas fa-envelope text-info mr-1"></i><?= h($pEmail) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="align-middle text-muted small">
                                <?= !empty($abs['remarks']) ? h($abs['remarks']) : '<span class="text-black-50 italic">None</span>' ?>
                            </td>
                            <td class="align-middle text-center" id="status-col-<?= (int)$abs['student_id'] ?>">
                                <?php if ($notified): ?>
                                    <span class="badge badge-success px-3 py-2 rounded-pill font-weight-bold shadow-xs">
                                        <i class="fas fa-check-circle mr-1"></i> Notified
                                    </span>
                                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                                        <?= date('d M, h:i A', strtotime($abs['parent_notified_at'])) ?>
                                        <span class="badge badge-light border text-uppercase ml-1"><?= h($abs['parent_notification_channel'] ?: 'email') ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-warning text-dark px-3 py-2 rounded-pill font-weight-bold shadow-xs">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="align-middle text-right px-4 text-nowrap">
                                <!-- Send Email Button -->
                                <?php if (!empty($pEmail)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 font-weight-bold shadow-xs mr-1 email-btn" 
                                            onclick="notifySingleParent(<?= (int)$abs['student_id'] ?>, '<?= htmlspecialchars(addslashes($sName)) ?>')"
                                            id="btn-email-<?= (int)$abs['student_id'] ?>">
                                        <i class="fas fa-envelope mr-1"></i> <?= $notified ? 'Resend Email' : 'Send Email' ?>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-light border rounded-pill px-3 text-muted mr-1" disabled title="No email address on file">
                                        <i class="fas fa-envelope-open mr-1"></i> No Email
                                    </button>
                                <?php endif; ?>

                                <!-- WhatsApp Button -->
                                <?php if (!empty($waUrl)): ?>
                                    <a href="<?= $waUrl ?>" target="_blank" class="btn btn-sm btn-outline-success rounded-pill px-3 font-weight-bold shadow-xs" title="Open WhatsApp chat with parent" onclick="markNotifiedChannel(<?= (int)$abs['student_id'] ?>, 'whatsapp')">
                                        <i class="fab fa-whatsapp mr-1"></i> WhatsApp
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-light border rounded-pill px-3 text-muted" disabled title="No phone number on file">
                                        <i class="fab fa-whatsapp mr-1"></i> No Phone
                                    </button>
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
function notifySingleParent(studentId, studentName) {
    const $btn = $('#btn-email-' + studentId);
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending...');

    $.ajax({
        url: 'notify_ajax.php',
        method: 'POST',
        data: {
            csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>',
            register_id: <?= $register_id ?>,
            student_id: studentId,
            channel: 'email'
        },
        dataType: 'json',
        success: function(resp) {
            if (resp && resp.success) {
                $('#status-col-' + studentId).html(`
                    <span class="badge badge-success px-3 py-2 rounded-pill font-weight-bold shadow-xs">
                        <i class="fas fa-check-circle mr-1"></i> Notified
                    </span>
                    <div class="small text-muted mt-1" style="font-size: 0.75rem;">Just now <span class="badge badge-light border text-uppercase ml-1">email</span></div>
                `);
                $btn.prop('disabled', false).html('<i class="fas fa-check mr-1"></i> Resend Email');
                Swal.fire({
                    icon: 'success',
                    title: 'Email Sent!',
                    text: 'Absentee alert sent to parent of ' + studentName,
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                $btn.prop('disabled', false).html('<i class="fas fa-envelope mr-1"></i> Send Email');
                Swal.fire({
                    icon: 'error',
                    title: 'Sending Failed',
                    text: resp.error || 'Unable to send absentee email.'
                });
            }
        },
        error: function(xhr) {
            $btn.prop('disabled', false).html('<i class="fas fa-envelope mr-1"></i> Send Email');
            let msg = 'Failed to connect to notification mailer.';
            try { const j = JSON.parse(xhr.responseText); if (j.error) msg = j.error; } catch(e){}
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        }
    });
}

function notifyAllParents() {
    Swal.fire({
        title: 'Dispatch Absentee Alerts?',
        text: 'This will send official absence notification emails to all pending parents of this session.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-paper-plane mr-1"></i> Yes, Dispatch Now'
    }).then((result) => {
        if (!result.isConfirmed) return;

        const $btn = $('#notifyAllBtn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Dispatching Emails...');

        $.ajax({
            url: 'notify_ajax.php',
            method: 'POST',
            data: {
                csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>',
                register_id: <?= $register_id ?>,
                channel: 'email'
            },
            dataType: 'json',
            success: function(resp) {
                if (resp && resp.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'All Alerts Dispatched!',
                        html: resp.message || 'Notification emails have been dispatched successfully.',
                        confirmButtonText: 'Refresh Roster'
                    }).then(() => {
                        window.location.reload();
                    });
                } else {
                    $btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i> Retry Dispatching');
                    Swal.fire({
                        icon: 'error',
                        title: 'Dispatch Incomplete',
                        text: resp.error || 'Some or all alerts could not be sent.'
                    });
                }
            },
            error: function(xhr) {
                $btn.prop('disabled', false).html('<i class="fas fa-paper-plane mr-1"></i> Retry Dispatching');
                let msg = 'Server error during batch dispatching.';
                try { const j = JSON.parse(xhr.responseText); if (j.error) msg = j.error; } catch(e){}
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
            }
        });
    });
}

function markNotifiedChannel(studentId, channel) {
    $.post('notify_ajax.php', {
        csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>',
        register_id: <?= $register_id ?>,
        student_id: studentId,
        channel: channel,
        mark_only: 1
    }, function(resp) {
        if (resp && resp.success) {
            $('#status-col-' + studentId).html(`
                <span class="badge badge-success px-3 py-2 rounded-pill font-weight-bold shadow-xs">
                    <i class="fas fa-check-circle mr-1"></i> Notified
                </span>
                <div class="small text-muted mt-1" style="font-size: 0.75rem;">Just now <span class="badge badge-light border text-uppercase ml-1">${channel}</span></div>
            `);
        }
    }, 'json');
}

$(document).ready(function() {
    if ($('#absenteesTable').length) {
        $('#absenteesTable').DataTable({
            pageLength: 25,
            order: [],
            language: { search: "_INPUT_", searchPlaceholder: "Search absentees..." }
        });
    }
});
</script>

<?php require_once '../../includes/admin_footer.php'; ?>

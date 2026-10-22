<?php
// admin/enrolments/links.php
require_once __DIR__ . '/../../includes/admin_header.php';

require_admin_feature('enrolments');

// Self-healing schema check
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        title VARCHAR(200) NOT NULL,
        admin_id INT NOT NULL DEFAULT 1,
        class_id INT NULL,
        board_id INT NULL,
        batch_id INT NULL,
        max_uses INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        require_approval TINYINT(1) NOT NULL DEFAULT 0,
        collect_parent_info TINYINT(1) NOT NULL DEFAULT 1,
        collect_address TINYINT(1) NOT NULL DEFAULT 0,
        custom_welcome_message TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_admin_status (admin_id, is_active),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS enrollment_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link_id INT NOT NULL,
        student_id INT NOT NULL,
        ip_address VARCHAR(45) NULL,
        status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_link (link_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $studentCols = $pdo->query("DESCRIBE students")->fetchAll(PDO::FETCH_COLUMN);
    $neededCols = [
        'phone' => 'VARCHAR(25) NULL',
        'parent_name' => 'VARCHAR(150) NULL',
        'parent_phone' => 'VARCHAR(25) NULL',
        'dob' => 'DATE NULL',
        'address' => 'TEXT NULL',
        'gender' => 'VARCHAR(20) NULL'
    ];
    foreach ($neededCols as $col => $type) {
        if (!in_array($col, $studentCols)) {
            $pdo->exec("ALTER TABLE students ADD COLUMN $col $type");
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
} catch (\Throwable $e) {}

$current_admin_id = get_effective_admin_id();
$is_super = is_super_admin();

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create_link') {
        $title = trim($_POST['title'] ?? '');
        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $board_id = !empty($_POST['board_id']) ? (int)$_POST['board_id'] : null;
        $batch_id = !empty($_POST['batch_id']) ? (int)$_POST['batch_id'] : null;
        $max_uses = max(0, (int)($_POST['max_uses'] ?? 0));
        $expiry_preset = $_POST['expiry_preset'] ?? '7d';
        $custom_expiry = trim($_POST['custom_expiry'] ?? '');
        $require_approval = !empty($_POST['require_approval']) ? 1 : 0;
        $collect_parent_info = !empty($_POST['collect_parent_info']) ? 1 : 0;
        $collect_address = !empty($_POST['collect_address']) ? 1 : 0;
        $custom_welcome = trim($_POST['custom_welcome_message'] ?? '');
        $target_admin_id = ($is_super && !empty($_POST['admin_id'])) ? (int)$_POST['admin_id'] : $current_admin_id;

        // Calculate expires_at
        if ($expiry_preset === 'custom' && !empty($custom_expiry)) {
            $expires_at = date('Y-m-d H:i:s', strtotime($custom_expiry));
        } else {
            switch ($expiry_preset) {
                case '24h': $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours')); break;
                case '3d':  $expires_at = date('Y-m-d H:i:s', strtotime('+3 days')); break;
                case '14d': $expires_at = date('Y-m-d H:i:s', strtotime('+14 days')); break;
                case '30d': $expires_at = date('Y-m-d H:i:s', strtotime('+30 days')); break;
                case '7d':
                default:    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days')); break;
            }
        }

        if (empty($title)) {
            $_SESSION['flash_error'] = "Please provide a title or purpose for the enrollment link.";
        } else {
            // Generate secure unique 32-character token
            $token = bin2hex(random_bytes(16));

            $stmt_ins = $pdo->prepare("
                INSERT INTO enrollment_links 
                (token, title, admin_id, class_id, board_id, batch_id, max_uses, expires_at, is_active, require_approval, collect_parent_info, collect_address, custom_welcome_message, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
            ");
            $stmt_ins->execute([
                $token, $title, $target_admin_id, $class_id, $board_id, $batch_id,
                $max_uses, $expires_at, $require_approval, $collect_parent_info,
                $collect_address, $custom_welcome, (int)$_SESSION['admin_id']
            ]);

            $_SESSION['flash_success'] = "Temporary enrollment link created successfully!";
            header("Location: links.php");
            exit;
        }
    } elseif ($action === 'toggle_active') {
        $link_id = (int)($_POST['link_id'] ?? 0);
        $status = (int)($_POST['status'] ?? 0);
        $check_sql = $is_super ? "UPDATE enrollment_links SET is_active = ? WHERE id = ?" : "UPDATE enrollment_links SET is_active = ? WHERE id = ? AND admin_id = ?";
        $params = $is_super ? [$status, $link_id] : [$status, $link_id, $current_admin_id];
        $pdo->prepare($check_sql)->execute($params);
        $_SESSION['flash_success'] = ($status ? "Link activated." : "Link paused/deactivated.");
        header("Location: links.php");
        exit;
    } elseif ($action === 'extend_expiry') {
        $link_id = (int)($_POST['link_id'] ?? 0);
        $days = (int)($_POST['add_days'] ?? 7);
        $check_sql = $is_super ? 
            "UPDATE enrollment_links SET expires_at = DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL ? DAY), is_active = 1 WHERE id = ?" :
            "UPDATE enrollment_links SET expires_at = DATE_ADD(GREATEST(expires_at, NOW()), INTERVAL ? DAY), is_active = 1 WHERE id = ? AND admin_id = ?";
        $params = $is_super ? [$days, $link_id] : [$days, $link_id, $current_admin_id];
        $pdo->prepare($check_sql)->execute($params);
        $_SESSION['flash_success'] = "Enrollment link expiry extended by $days days.";
        header("Location: links.php");
        exit;
    } elseif ($action === 'delete_link') {
        $link_id = (int)($_POST['link_id'] ?? 0);
        $check_sql = $is_super ? "DELETE FROM enrollment_links WHERE id = ?" : "DELETE FROM enrollment_links WHERE id = ? AND admin_id = ?";
        $params = $is_super ? [$link_id] : [$link_id, $current_admin_id];
        $pdo->prepare($check_sql)->execute($params);
        $_SESSION['flash_success'] = "Enrollment link deleted successfully.";
        header("Location: links.php");
        exit;
    }
}

// Fetch Links
$where = "1=1";
$params = [];
if (!$is_super) {
    $where .= " AND el.admin_id = ?";
    $params[] = $current_admin_id;
}

$stmt_links = $pdo->prepare("
    SELECT el.*, 
           c.name as class_name, 
           b.name as board_name, 
           bt.name as batch_name,
           a.institute_name,
           u.username as creator_username,
           (SELECT COUNT(*) FROM enrollment_submissions WHERE link_id = el.id) as total_enrolled
    FROM enrollment_links el
    LEFT JOIN classes c ON el.class_id = c.id
    LEFT JOIN boards b ON el.board_id = b.id
    LEFT JOIN batches bt ON el.batch_id = bt.id
    LEFT JOIN admins a ON el.admin_id = a.id
    LEFT JOIN admins u ON el.created_by = u.id
    WHERE $where
    ORDER BY el.id DESC
");
$stmt_links->execute($params);
$links = $stmt_links->fetchAll();

// Fetch form options
$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll();
$boards  = $pdo->query("SELECT id, name FROM boards ORDER BY name ASC")->fetchAll();

if ($is_super) {
    $batches = $pdo->query("SELECT id, name, class_id, board_id, admin_id FROM batches WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
    $admins  = $pdo->query("SELECT id, username, institute_name FROM admins WHERE role = 'admin' OR role = 'super_admin' ORDER BY institute_name ASC")->fetchAll();
} else {
    $stmt_b = $pdo->prepare("SELECT id, name, class_id, board_id FROM batches WHERE admin_id = ? AND is_active = 1 ORDER BY name ASC");
    $stmt_b->execute([$current_admin_id]);
    $batches = $stmt_b->fetchAll();
    $admins = [];
}

// Quick stats
$active_count = 0;
$total_students_enrolled = 0;
$expiring_soon_count = 0;
$now_ts = time();

foreach ($links as $l) {
    $exp_ts = strtotime($l['expires_at']);
    $is_expired = ($exp_ts < $now_ts);
    $is_full = ($l['max_uses'] > 0 && $l['used_count'] >= $l['max_uses']);
    if ($l['is_active'] && !$is_expired && !$is_full) {
        $active_count++;
        if ($exp_ts - $now_ts < (86400 * 2)) {
            $expiring_soon_count++;
        }
    }
    $total_students_enrolled += (int)$l['used_count'];
}

// Determine host URL for public links (with reverse-proxy HTTPS support)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
$protocol = $is_https ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Determine web root relative to current script path /admin/enrolments/links.php
$scriptDir = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')));
$webRoot = ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') ? '' : rtrim(str_replace('\\', '/', $scriptDir), '/');

// Prefer BASE_URL if explicitly configured, else use detected web root
$resolvedBasePath = defined('BASE_URL') ? BASE_URL : $webRoot;
$base_enroll_url = $protocol . $host . $resolvedBasePath . '/enroll.php?token=';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Temporary Enrollment Links</h2>
        <p class="text-muted mb-0">Generate shareable, self-service admission links to onboard students into batches with automated credential creation.</p>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-3 shadow-sm mr-2">
            <i class="fas fa-arrow-left mr-1"></i> Exam Enrolments
        </a>
        <button type="button" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm" data-toggle="modal" data-target="#createLinkModal">
            <i class="fas fa-plus-circle mr-2"></i> Create Enrollment Link
        </button>
    </div>
</div>

<!-- Stat Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-link fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-dark"><?= count($links) ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Total Links</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-check-circle fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-success"><?= $active_count ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Active Links</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-user-graduate fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-info"><?= $total_students_enrolled ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Students Enrolled</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-clock fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-warning"><?= $expiring_soon_count ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Expiring Soon</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Links Table -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold text-dark"><i class="fas fa-list-alt text-primary mr-2"></i>Active & Past Enrollment Links</h5>
        <span class="badge badge-light border px-3 py-2 text-muted"><?= count($links) ?> Total Records</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($links)): ?>
            <div class="text-center py-5">
                <div class="mb-3 text-muted opacity-50"><i class="fas fa-link fa-4x"></i></div>
                <h5 class="font-weight-bold text-dark">No Enrollment Links Generated Yet</h5>
                <p class="text-muted">Create a temporary link to share on WhatsApp or Email for self-service student admissions.</p>
                <button type="button" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm" data-toggle="modal" data-target="#createLinkModal">
                    <i class="fas fa-plus-circle mr-1"></i> Create Your First Link
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="linksTable">
                    <thead class="thead-light">
                        <tr>
                            <th class="border-0">Link Details / Purpose</th>
                            <th class="border-0">Target Academic Scope</th>
                            <th class="border-0 text-center">Enrollment Progress</th>
                            <th class="border-0">Validity & Status</th>
                            <th class="border-0 text-center">Share Link</th>
                            <th class="border-0 text-right pr-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($links as $link): 
                            $full_url = $base_enroll_url . urlencode($link['token']);
                            $exp_ts = strtotime($link['expires_at']);
                            $is_expired = ($exp_ts < $now_ts);
                            $is_full = ($link['max_uses'] > 0 && $link['used_count'] >= $link['max_uses']);
                            $pct = ($link['max_uses'] > 0) ? min(100, round(($link['used_count'] / $link['max_uses']) * 100)) : 100;
                            
                            // WhatsApp message
                            $wa_text = urlencode("Hello! Please complete your enrollment for " . ($link['title']) . " by submitting your details here: " . $full_url);
                        ?>
                        <tr>
                            <td>
                                <strong class="d-block text-dark font-weight-bold mb-1"><?= h($link['title']) ?></strong>
                                <small class="text-muted d-block">
                                    <i class="fas fa-calendar-plus mr-1"></i>Created: <?= date('d M Y, h:i A', strtotime($link['created_at'])) ?>
                                    <?php if ($is_super && !empty($link['institute_name'])): ?>
                                        &bull; <span class="badge badge-light border"><?= h($link['institute_name']) ?></span>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <?php if (!empty($link['batch_name'])): ?>
                                    <span class="badge badge-primary px-2 py-1 font-weight-bold mb-1"><i class="fas fa-layer-group mr-1"></i><?= h($link['batch_name']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary px-2 py-1 mb-1">Student Selects Batch</span>
                                <?php endif; ?>
                                <small class="text-muted d-block">
                                    <?= h($link['class_name'] ?? 'Any Class') ?> &bull; <?= h($link['board_name'] ?? 'Any Board') ?>
                                </small>
                            </td>
                            <td class="text-center" style="min-width: 140px;">
                                <div class="font-weight-bold text-dark mb-1">
                                    <span class="h6 font-weight-bold text-primary mb-0"><?= (int)$link['used_count'] ?></span>
                                    <span class="text-muted">/ <?= $link['max_uses'] > 0 ? (int)$link['max_uses'] : '∞' ?></span>
                                </div>
                                <?php if ($link['max_uses'] > 0): ?>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar <?= $is_full ? 'bg-danger' : 'bg-primary' ?>" style="width: <?= $pct ?>%"></div>
                                    </div>
                                <?php else: ?>
                                    <small class="badge badge-light border text-muted">Unlimited</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$link['is_active']): ?>
                                    <span class="badge badge-secondary px-3 py-1 font-weight-bold"><i class="fas fa-pause mr-1"></i>Paused</span>
                                <?php elseif ($is_expired): ?>
                                    <span class="badge badge-danger px-3 py-1 font-weight-bold"><i class="fas fa-hourglass-end mr-1"></i>Expired</span>
                                <?php elseif ($is_full): ?>
                                    <span class="badge badge-warning px-3 py-1 font-weight-bold text-dark"><i class="fas fa-ban mr-1"></i>Full Cap</span>
                                <?php else: ?>
                                    <span class="badge badge-success px-3 py-1 font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Active</span>
                                <?php endif; ?>
                                <small class="text-muted d-block mt-1">
                                    Expires: <?= date('d M Y, h:i A', $exp_ts) ?>
                                </small>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm shadow-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary" title="Copy Public Link" onclick="copyToClipboard('<?= $full_url ?>', this)">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <a href="https://api.whatsapp.com/send?text=<?= $wa_text ?>" target="_blank" class="btn btn-outline-success" title="Share via WhatsApp">
                                        <i class="fab fa-whatsapp"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-dark" title="Show QR Code" onclick="showQrModal('<?= h($link['title']) ?>', '<?= $full_url ?>')">
                                        <i class="fas fa-qrcode"></i>
                                    </button>
                                    <a href="<?= $full_url ?>" target="_blank" class="btn btn-outline-secondary" title="Open Form in New Tab">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </td>
                            <td class="text-right pr-4">
                                <div class="dropdown">
                                    <a href="link_students.php?id=<?= $link['id'] ?>" class="btn btn-sm btn-info font-weight-bold mr-1">
                                        <i class="fas fa-users mr-1"></i> Roster (<?= (int)$link['used_count'] ?>)
                                    </a>
                                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right shadow-sm border-0">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                                            <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                            <input type="hidden" name="action" value="toggle_active">
                                            <input type="hidden" name="status" value="<?= $link['is_active'] ? '0' : '1' ?>">
                                            <button type="submit" class="dropdown-item">
                                                <i class="fas <?= $link['is_active'] ? 'fa-pause text-warning' : 'fa-play text-success' ?> mr-2"></i>
                                                <?= $link['is_active'] ? 'Pause Link' : 'Resume Link' ?>
                                            </button>
                                        </form>
                                        <a href="#" class="dropdown-item" onclick="openExtendModal(<?= $link['id'] ?>, '<?= h(addslashes($link['title'])) ?>')">
                                            <i class="fas fa-calendar-plus text-primary mr-2"></i> Extend Expiry
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this enrollment link? Students already registered will not be affected.');">
                                            <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                                            <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                            <input type="hidden" name="action" value="delete_link">
                                            <button type="submit" class="dropdown-item text-danger">
                                                <i class="fas fa-trash-alt mr-2"></i> Delete Link
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Create Link -->
<div class="modal fade" id="createLinkModal" tabindex="-1" role="dialog" aria-labelledby="createLinkModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title font-weight-bold" id="createLinkModalLabel"><i class="fas fa-link mr-2"></i>Generate Temporary Enrollment Link</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" action="links.php">
                <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_link">
                <div class="modal-body p-4">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Link Title / Purpose <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Class 10 CBSE 2026 Batch Admission" required>
                        <small class="text-muted">Descriptive title shown at the top of the student admission form.</small>
                    </div>

                    <?php if ($is_super && !empty($admins)): ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Assign to Institute Campus (Tenant)</label>
                        <select name="admin_id" class="form-control">
                            <?php foreach ($admins as $adm): ?>
                                <option value="<?= $adm['id'] ?>" <?= $adm['id'] == $current_admin_id ? 'selected' : '' ?>>
                                    <?= h($adm['institute_name']) ?> (<?= h($adm['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Lock to Class (Optional)</label>
                            <select name="class_id" id="modalClassSelect" class="form-control">
                                <option value="">-- Flexible (Student selects Class) --</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Lock to Board (Optional)</label>
                            <select name="board_id" class="form-control">
                                <option value="">-- Flexible (Student selects Board) --</option>
                                <?php foreach ($boards as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Target Batch Placement (Optional)</label>
                        <select name="batch_id" id="modalBatchSelect" class="form-control">
                            <option value="">-- No pre-assigned batch (Student picks or unassigned) --</option>
                            <?php foreach ($batches as $bt): ?>
                                <option value="<?= $bt['id'] ?>" data-class="<?= $bt['class_id'] ?>">
                                    <?= h($bt['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">If selected, registered students will be automatically enrolled into this batch.</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Link Expiry Duration <span class="text-danger">*</span></label>
                            <select name="expiry_preset" id="expiryPresetSelect" class="form-control" onchange="toggleCustomExpiry(this.value)">
                                <option value="24h">24 Hours (Urgent)</option>
                                <option value="3d">3 Days</option>
                                <option value="7d" selected>7 Days (Standard)</option>
                                <option value="14d">14 Days (2 Weeks)</option>
                                <option value="30d">30 Days (1 Month)</option>
                                <option value="custom">Custom Date & Time</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Seat Cap / Max Submissions</label>
                            <input type="number" name="max_uses" class="form-control" min="0" placeholder="0 = Unlimited" value="0">
                            <small class="text-muted">Link auto-closes once this number of students register.</small>
                        </div>
                    </div>

                    <div class="form-group mb-3 d-none" id="customExpiryWrapper">
                        <label class="font-weight-bold text-dark">Custom Expiry Date & Time</label>
                        <input type="datetime-local" name="custom_expiry" class="form-control" min="<?= date('Y-m-d\TH:i') ?>">
                    </div>

                    <div class="bg-light p-3 rounded mb-3 border">
                        <label class="font-weight-bold text-dark d-block mb-2"><i class="fas fa-sliders-h mr-1 text-primary"></i> Information Collection Options</label>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="collectParentCheck" name="collect_parent_info" value="1" checked>
                            <label class="custom-control-label font-weight-bold text-dark" for="collectParentCheck">Collect Parent / Guardian Name & Phone</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" class="custom-control-input" id="collectAddressCheck" name="collect_address" value="1">
                            <label class="custom-control-label font-weight-bold text-dark" for="collectAddressCheck">Collect Residential Address & City</label>
                        </div>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="approvalCheck" name="require_approval" value="1">
                            <label class="custom-control-label font-weight-bold text-dark" for="approvalCheck">Require Admin Approval (Student account is kept pending until approved)</label>
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark">Custom Welcome Message (Optional)</label>
                        <textarea name="custom_welcome_message" class="form-control" rows="2" placeholder="e.g. Welcome to Apex Academy! Please submit your details to receive your student portal access."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">
                        <i class="fas fa-magic mr-1"></i> Generate Link
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Extend Expiry -->
<div class="modal fade" id="extendExpiryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow rounded-lg">
            <div class="modal-header bg-dark text-white py-3">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-calendar-plus mr-2"></i>Extend Link Expiry</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="links.php">
                <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="extend_expiry">
                <input type="hidden" name="link_id" id="extendLinkId" value="">
                <div class="modal-body p-4">
                    <p class="text-dark mb-3">Extending validity for: <strong id="extendLinkTitle"></strong></p>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark">Add Additional Days</label>
                        <select name="add_days" class="form-control">
                            <option value="1">+1 Day (24 Hours)</option>
                            <option value="3">+3 Days</option>
                            <option value="7" selected>+7 Days (1 Week)</option>
                            <option value="14">+14 Days (2 Weeks)</option>
                            <option value="30">+30 Days (1 Month)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">Extend Expiry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: QR Code -->
<div class="modal fade" id="qrModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow rounded-lg text-center p-4">
            <h5 class="font-weight-bold text-dark mb-1" id="qrTitle">Enrollment QR Code</h5>
            <p class="text-muted small mb-3">Scan with any mobile camera to open the admission form directly</p>
            <div class="d-flex justify-content-center mb-3">
                <div id="qrContainer" class="p-3 bg-light border rounded shadow-sm">
                    <img id="qrImage" src="" alt="QR Code" style="width: 220px; height: 220px;" class="img-fluid">
                </div>
            </div>
            <div class="input-group mb-3">
                <input type="text" id="qrLinkInput" class="form-control text-center font-weight-bold bg-light" readonly>
                <div class="input-group-append">
                    <button class="btn btn-outline-primary" type="button" onclick="copyFromInput('qrLinkInput')"><i class="fas fa-copy"></i></button>
                </div>
            </div>
            <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Close</button>
        </div>
    </div>
</div>

<script>
function toggleCustomExpiry(val) {
    var wrapper = document.getElementById('customExpiryWrapper');
    if (val === 'custom') {
        wrapper.classList.remove('d-none');
    } else {
        wrapper.classList.add('d-none');
    }
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(function() {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check text-success"></i>';
        setTimeout(function() { btn.innerHTML = original; }, 2000);
    });
}

function copyFromInput(inputId) {
    var copyText = document.getElementById(inputId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value).then(function() {
        alert("Public link copied to clipboard!");
    });
}

function openExtendModal(id, title) {
    document.getElementById('extendLinkId').value = id;
    document.getElementById('extendLinkTitle').innerText = title;
    $('#extendExpiryModal').modal('show');
}

function showQrModal(title, url) {
    document.getElementById('qrTitle').innerText = title;
    document.getElementById('qrLinkInput').value = url;
    // Use QR code generator API
    document.getElementById('qrImage').src = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(url);
    $('#qrModal').modal('show');
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

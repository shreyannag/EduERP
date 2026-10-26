<?php
// admin/enrolments/blocked_ips.php
require_once __DIR__ . '/../../includes/admin_header.php';

require_admin_feature('enrolments');

$current_admin_id = get_effective_admin_id();
$is_super = is_super_admin();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'block_ip') {
        $raw_ip = trim($_POST['ip_address'] ?? '');
        $reason = trim($_POST['reason'] ?? 'Fraudulent / Spam registration');
        $notes  = trim($_POST['notes'] ?? '');
        $scope_admin_id = 0;

        if ($is_super) {
            $scope_type = $_POST['scope_type'] ?? 'global';
            if ($scope_type === 'institute') {
                $scope_admin_id = (int)($_POST['target_admin_id'] ?? 0);
            }
        } else {
            $scope_admin_id = $current_admin_id;
        }

        // Basic IP validation (exact IPv4, IPv6, wildcard with *, or CIDR)
        $is_valid_format = false;
        if (filter_var($raw_ip, FILTER_VALIDATE_IP)) {
            $is_valid_format = true;
        } elseif (preg_match('/^(\d{1,3}\.){1,3}\*$/', $raw_ip)) {
            // Wildcard pattern e.g. 192.168.1.* or 103.45.*
            $is_valid_format = true;
        } elseif (strpos($raw_ip, '/') !== false) {
            list($net, $mask) = explode('/', $raw_ip, 2);
            if (filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && is_numeric($mask) && (int)$mask >= 8 && (int)$mask <= 32) {
                $is_valid_format = true;
            }
        }

        if (!$is_valid_format) {
            $_SESSION['flash_error'] = "Invalid IP format. Enter a valid IPv4, IPv6, wildcard (e.g. 103.45.67.*), or CIDR subnet (e.g. 103.45.67.0/24).";
            header("Location: blocked_ips.php");
            exit;
        }

        try {
            // Check if already blocked in this scope
            $chk = $pdo->prepare("SELECT id, is_active FROM blocked_enrollment_ips WHERE ip_address = ? AND admin_id = ?");
            $chk->execute([$raw_ip, $scope_admin_id]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($existing['is_active']) {
                    $_SESSION['flash_error'] = "IP address '{$raw_ip}' is already in the blocklist.";
                } else {
                    $pdo->prepare("UPDATE blocked_enrollment_ips SET is_active = 1, reason = ?, notes = ?, blocked_by = ? WHERE id = ?")
                        ->execute([$reason, $notes ?: null, (int)$_SESSION['admin_id'], $existing['id']]);
                    $_SESSION['flash_success'] = "IP address '{$raw_ip}' has been re-activated in the blocklist.";
                }
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO blocked_enrollment_ips (ip_address, admin_id, reason, notes, blocked_by, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $ins->execute([$raw_ip, $scope_admin_id, $reason, $notes ?: null, (int)$_SESSION['admin_id']]);
                $_SESSION['flash_success'] = "IP address '{$raw_ip}' has been blocked successfully. Further registrations from this network are now restricted.";
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = "Error saving IP block: " . $e->getMessage();
        }

        header("Location: blocked_ips.php");
        exit;
    } elseif ($action === 'unblock_ip') {
        $block_id = (int)($_POST['block_id'] ?? 0);
        if ($block_id > 0) {
            try {
                if ($is_super) {
                    $pdo->prepare("DELETE FROM blocked_enrollment_ips WHERE id = ?")->execute([$block_id]);
                } else {
                    $pdo->prepare("DELETE FROM blocked_enrollment_ips WHERE id = ? AND admin_id = ?")->execute([$block_id, $current_admin_id]);
                }
                $_SESSION['flash_success'] = "IP address unblocked successfully. Registration access has been restored.";
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = "Error unblocking IP: " . $e->getMessage();
            }
        }
        header("Location: blocked_ips.php");
        exit;
    } elseif ($action === 'toggle_status') {
        $block_id = (int)($_POST['block_id'] ?? 0);
        if ($block_id > 0) {
            try {
                if ($is_super) {
                    $pdo->prepare("UPDATE blocked_enrollment_ips SET is_active = 1 - is_active WHERE id = ?")->execute([$block_id]);
                } else {
                    $pdo->prepare("UPDATE blocked_enrollment_ips SET is_active = 1 - is_active WHERE id = ? AND admin_id = ?")->execute([$block_id, $current_admin_id]);
                }
                $_SESSION['flash_success'] = "Block rule status updated.";
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = "Error updating status: " . $e->getMessage();
            }
        }
        header("Location: blocked_ips.php");
        exit;
    } elseif ($action === 'edit_block') {
        $block_id = (int)($_POST['block_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Fraudulent / Spam registration');
        $notes  = trim($_POST['notes'] ?? '');

        if ($block_id > 0) {
            try {
                if ($is_super) {
                    $pdo->prepare("UPDATE blocked_enrollment_ips SET reason = ?, notes = ? WHERE id = ?")->execute([$reason, $notes ?: null, $block_id]);
                } else {
                    $pdo->prepare("UPDATE blocked_enrollment_ips SET reason = ?, notes = ? WHERE id = ? AND admin_id = ?")->execute([$reason, $notes ?: null, $block_id, $current_admin_id]);
                }
                $_SESSION['flash_success'] = "Block rule notes updated successfully.";
            } catch (\Throwable $e) {
                $_SESSION['flash_error'] = "Error updating block: " . $e->getMessage();
            }
        }
        header("Location: blocked_ips.php");
        exit;
    }
}

// Diagnostics / IP Test
$test_ip = trim($_GET['test_ip'] ?? '');
$test_result = null;
if (!empty($test_ip)) {
    $test_result = check_enrollment_ip_block($test_ip, $current_admin_id, $pdo);
}

// Fetch all blocked IPs
$fetch_sql = $is_super ? "
    SELECT b.*, 
           a.institute_name, 
           adm.username as blocker_username
    FROM blocked_enrollment_ips b
    LEFT JOIN admins a ON b.admin_id = a.id
    LEFT JOIN admins adm ON b.blocked_by = adm.id
    ORDER BY b.created_at DESC
" : "
    SELECT b.*, 
           a.institute_name, 
           adm.username as blocker_username
    FROM blocked_enrollment_ips b
    LEFT JOIN admins a ON b.admin_id = a.id
    LEFT JOIN admins adm ON b.blocked_by = adm.id
    WHERE b.admin_id = ? OR b.admin_id IS NULL OR b.admin_id = 0
    ORDER BY b.created_at DESC
";
$fetch_params = $is_super ? [] : [$current_admin_id];
$stmt = $pdo->prepare($fetch_sql);
$stmt->execute($fetch_params);
$blocked_ips = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate metrics
$total_blocks = count($blocked_ips);
$active_blocks = 0;
$total_intercepted = 0;
foreach ($blocked_ips as $b) {
    if ($b['is_active']) $active_blocks++;
    $total_intercepted += (int)$b['blocked_attempts_count'];
}

// For Super Admin institute dropdown
$institutes = [];
if ($is_super) {
    $institutes = $pdo->query("SELECT id, institute_name, username FROM admins WHERE role != 'super_admin' AND is_active = 1 ORDER BY institute_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent p-0 mb-1">
                <li class="breadcrumb-item"><a href="links.php">Enrollment Links</a></li>
                <li class="breadcrumb-item active">Blocked IPs (Fraud Protection)</li>
            </ol>
        </nav>
        <h2 class="font-weight-bold text-dark mb-0">
            <i class="fas fa-shield-alt text-danger mr-2"></i>Blocked Registration IPs
        </h2>
        <p class="text-muted mb-0 small">Prevent fraudulent accounts and spam registrations by blacklisting suspicious IP addresses and subnets.</p>
    </div>
    <div class="mt-3 mt-md-0">
        <a href="links.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-3 shadow-sm mr-2">
            <i class="fas fa-arrow-left mr-1"></i> Back to Links
        </a>
        <button type="button" class="btn btn-danger font-weight-bold rounded-pill px-4 shadow-sm" data-toggle="modal" data-target="#addBlockModal">
            <i class="fas fa-ban mr-1"></i> Block New IP
        </button>
    </div>
</div>

<!-- Security Metric Cards -->
<div class="row mb-4">
    <div class="col-md-4 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white border-left-danger" style="border-left: 4px solid #dc3545 !important;">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-danger text-white rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-ban fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-dark"><?= $total_blocks ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Total Blocked IPs</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white border-left-success" style="border-left: 4px solid #28a745 !important;">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-shield-virus fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-success"><?= $active_blocks ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Active Enforcement Rules</small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-6 mb-3 mb-md-0">
        <div class="card border-0 shadow-sm rounded-lg bg-white border-left-warning" style="border-left: 4px solid #ffc107 !important;">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center mr-3" style="width: 50px; height: 50px;">
                    <i class="fas fa-user-slash fa-lg"></i>
                </div>
                <div>
                    <h3 class="font-weight-bold mb-0 text-dark"><?= $total_intercepted ?></h3>
                    <small class="text-muted text-uppercase font-weight-bold">Fraud Attempts Intercepted</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Diagnostics / Quick IP Test Card -->
<div class="card border-0 shadow-sm rounded-lg mb-4 bg-white">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 font-weight-bold text-dark">
            <i class="fas fa-search-location text-primary mr-2"></i>Test & Verify IP Access
        </h6>
        <small class="text-muted">Instantly verify whether a specific IP address is permitted or blocked by the system</small>
    </div>
    <div class="card-body p-4">
        <form method="GET" class="form-inline">
            <div class="input-group mr-2 mb-2" style="max-width: 400px; width: 100%;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-light border-right-0"><i class="fas fa-network-wired text-muted"></i></span>
                </div>
                <input type="text" name="test_ip" class="form-control border-left-0 font-weight-bold" placeholder="e.g. 103.45.67.89" value="<?= h($test_ip) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4 mb-2 shadow-sm">
                <i class="fas fa-check-circle mr-1"></i> Test IP Status
            </button>
            <?php if (!empty($test_ip)): ?>
                <a href="blocked_ips.php" class="btn btn-link text-muted mb-2 ml-2">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($test_ip)): ?>
            <div class="mt-3">
                <?php if ($test_result['blocked']): ?>
                    <div class="alert alert-danger mb-0 border-0 shadow-sm d-flex align-items-center">
                        <i class="fas fa-ban fa-2x mr-3 text-danger"></i>
                        <div>
                            <h6 class="font-weight-bold mb-1 text-danger">RESTRICTED: IP address '<?= h($test_ip) ?>' is currently BLOCKED.</h6>
                            <p class="mb-0 small text-dark">Matched Rule #<?= (int)$test_result['rule_id'] ?>: <strong><?= h($test_result['reason']) ?></strong>. Any student registration attempt from this network will be completely denied.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success mb-0 border-0 shadow-sm d-flex align-items-center">
                        <i class="fas fa-check-circle fa-2x mr-3 text-success"></i>
                        <div>
                            <h6 class="font-weight-bold mb-1 text-success">ALLOWED: IP address '<?= h($test_ip) ?>' is permitted to register.</h6>
                            <p class="mb-0 small text-dark">No active block rules match this IP address under this institute.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Blocked IPs Table -->
<div class="card border-0 shadow-sm rounded-lg bg-white">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold text-dark">
            <i class="fas fa-list text-danger mr-2"></i>Active Blocklist Records (<?= count($blocked_ips) ?>)
        </h5>
        <span class="badge badge-light border px-3 py-2 text-muted">Real-Time Protection</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($blocked_ips)): ?>
            <div class="text-center py-5">
                <div class="mb-3 text-muted opacity-50"><i class="fas fa-shield-alt fa-4x text-success"></i></div>
                <h5 class="font-weight-bold text-dark">No IPs Currently Blocked</h5>
                <p class="text-muted">All client networks are currently permitted to submit online admission forms.<br>If you notice suspicious or fake registrations, block their IP to prevent fraud.</p>
                <button type="button" class="btn btn-danger font-weight-bold rounded-pill px-4 shadow-sm" data-toggle="modal" data-target="#addBlockModal">
                    <i class="fas fa-ban mr-1"></i> Block Suspicious IP
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="blockedIpsTable">
                    <thead class="thead-light">
                        <tr>
                            <th class="border-0">IP Address / Pattern</th>
                            <th class="border-0">Scope / Institute</th>
                            <th class="border-0">Reason & Notes</th>
                            <th class="border-0 text-center">Fraud Attempts Blocked</th>
                            <th class="border-0">Blocked Date & Admin</th>
                            <th class="border-0 text-center">Status</th>
                            <th class="border-0 text-right pr-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked_ips as $row): 
                            $is_wildcard = strpos($row['ip_address'], '*') !== false || strpos($row['ip_address'], '/') !== false;
                            $can_manage = $is_super || ((int)$row['admin_id'] === $current_admin_id);
                        ?>
                        <tr>
                            <td>
                                <strong class="text-dark font-family-monospace h6 font-weight-bold d-block mb-1">
                                    <?= h($row['ip_address']) ?>
                                </strong>
                                <?php if ($is_wildcard): ?>
                                    <span class="badge badge-warning text-dark"><i class="fas fa-network-wired mr-1"></i>Subnet / Wildcard</span>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted"><i class="fas fa-desktop mr-1"></i>Exact IP</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (empty($row['admin_id'])): ?>
                                    <span class="badge badge-danger px-2 py-1"><i class="fas fa-globe mr-1"></i>Global (All Institutes)</span>
                                <?php else: ?>
                                    <span class="badge badge-info px-2 py-1"><i class="fas fa-university mr-1"></i><?= h($row['institute_name'] ?? 'Institute #' . $row['admin_id']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="text-dark d-block"><?= h($row['reason']) ?></strong>
                                <?php if (!empty($row['notes'])): ?>
                                    <small class="text-muted d-block"><?= h($row['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int)$row['blocked_attempts_count'] > 0): ?>
                                    <span class="badge badge-danger px-3 py-1 font-weight-bold">
                                        <i class="fas fa-shield-alt mr-1"></i><?= (int)$row['blocked_attempts_count'] ?> stopped
                                    </span>
                                    <?php if (!empty($row['last_blocked_at'])): ?>
                                        <small class="d-block text-muted mt-1">Last: <?= date('d M, h:i A', strtotime($row['last_blocked_at'])) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted">0 attempts</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="d-block font-weight-bold text-dark"><?= date('d M Y, h:i A', strtotime($row['created_at'])) ?></small>
                                <small class="text-muted"><i class="fas fa-user-shield mr-1"></i>By: <?= h($row['blocker_username'] ?? 'Admin') ?></small>
                            </td>
                            <td class="text-center">
                                <?php if ($row['is_active']): ?>
                                    <span class="badge badge-success px-3 py-1 font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary px-3 py-1 font-weight-bold"><i class="fas fa-pause-circle mr-1"></i>Paused</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right pr-4">
                                <?php if ($can_manage): ?>
                                    <div class="btn-group btn-group-sm">
                                        <!-- Pause / Resume Toggle -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="block_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-outline-secondary" title="<?= $row['is_active'] ? 'Pause Block Rule' : 'Activate Block Rule' ?>">
                                                <i class="fas <?= $row['is_active'] ? 'fa-pause' : 'fa-play' ?>"></i>
                                            </button>
                                        </form>

                                        <!-- Edit Modal Trigger -->
                                        <button type="button" class="btn btn-outline-primary" title="Edit Reason / Notes" onclick="openEditModal(<?= $row['id'] ?>, '<?= h(addslashes($row['ip_address'])) ?>', '<?= h(addslashes($row['reason'])) ?>', '<?= h(addslashes($row['notes'] ?? '')) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <!-- Delete / Unblock -->
                                        <form method="POST" class="d-inline ml-1" onsubmit="return confirm('Are you sure you want to completely unblock IP <?= h($row['ip_address']) ?>?');">
                                            <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                                            <input type="hidden" name="action" value="unblock_ip">
                                            <input type="hidden" name="block_id" value="<?= $row['id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Unblock / Remove Rule">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted"><i class="fas fa-lock mr-1"></i>Global Rule</span>
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

<!-- Modal: Add New Blocked IP -->
<div class="modal fade" id="addBlockModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="block_ip">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-ban mr-2"></i>Block IP from Enrollment</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small">Registrations from this IP address or subnet will be blocked immediately across all enrollment links.</p>

                    <div class="form-group">
                        <label class="font-weight-bold text-dark">IP Address or Pattern <span class="text-danger">*</span></label>
                        <input type="text" name="ip_address" class="form-control font-weight-bold" placeholder="e.g. 103.45.67.89 or 103.45.67.*" required>
                        <small class="text-muted d-block mt-1">Supports exact IPv4 (<code>103.45.67.89</code>), IPv6, wildcard (<code>103.45.67.*</code>), or CIDR (<code>103.45.67.0/24</code>).</small>
                    </div>

                    <?php if ($is_super): ?>
                        <div class="form-group">
                            <label class="font-weight-bold text-dark">Protection Scope</label>
                            <select name="scope_type" id="scopeTypeSelect" class="form-control mb-2" onchange="toggleScopeInstitute()">
                                <option value="global">Global (Blocks across ALL Institutes)</option>
                                <option value="institute">Specific Institute Only</option>
                            </select>
                            <div id="instituteSelectWrap" style="display: none;">
                                <select name="target_admin_id" class="form-control">
                                    <?php foreach ($institutes as $inst): ?>
                                        <option value="<?= $inst['id'] ?>"><?= h($inst['institute_name']) ?> (<?= h($inst['username']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="font-weight-bold text-dark">Reason for Block <span class="text-danger">*</span></label>
                        <select id="reasonPresetSelect" class="form-control mb-2" onchange="applyReasonPreset()">
                            <option value="Fraudulent / fake student registration">Fraudulent / fake student registration</option>
                            <option value="Repeated spam bot account creation">Repeated spam bot account creation</option>
                            <option value="Unauthorized multiple accounts">Unauthorized multiple accounts</option>
                            <option value="Suspicious automated traffic">Suspicious automated traffic</option>
                            <option value="custom">Other / Custom Reason...</option>
                        </select>
                        <input type="text" name="reason" id="reasonInput" class="form-control" value="Fraudulent / fake student registration" required>
                    </div>

                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark">Internal Security Notes <small class="text-muted">(Optional)</small></label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Generated 6 fake accounts with random names in 2 minutes"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger font-weight-bold rounded-pill px-4 shadow-sm">
                        <i class="fas fa-ban mr-1"></i> Confirm & Block IP
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Block Rule -->
<div class="modal fade" id="editBlockModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h(generate_csrf_token()) ?>">
                <input type="hidden" name="action" value="edit_block">
                <input type="hidden" name="block_id" id="editBlockId" value="0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-edit mr-2"></i>Edit Block Details</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="form-group">
                        <label class="font-weight-bold text-dark">IP Address</label>
                        <input type="text" id="editIpAddress" class="form-control font-weight-bold bg-light" readonly>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold text-dark">Reason</label>
                        <input type="text" name="reason" id="editReason" class="form-control" required>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold text-dark">Notes</label>
                        <textarea name="notes" id="editNotes" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">
                        <i class="fas fa-save mr-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleScopeInstitute() {
    var select = document.getElementById('scopeTypeSelect');
    var wrap = document.getElementById('instituteSelectWrap');
    if (select && wrap) {
        wrap.style.display = (select.value === 'institute') ? 'block' : 'none';
    }
}

function applyReasonPreset() {
    var preset = document.getElementById('reasonPresetSelect').value;
    var input = document.getElementById('reasonInput');
    if (preset !== 'custom') {
        input.value = preset;
    } else {
        input.value = '';
        input.focus();
    }
}

function openEditModal(id, ip, reason, notes) {
    document.getElementById('editBlockId').value = id;
    document.getElementById('editIpAddress').value = ip;
    document.getElementById('editReason').value = reason;
    document.getElementById('editNotes').value = notes;
    $('#editBlockModal').modal('show');
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

<?php
// admin/admins/index.php
require_once __DIR__ . '/../../includes/admin_header.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

// Strictly enforce Super Admin access
require_super_admin();

// Fetch summary metrics
$totalAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE is_active = 1")->fetchColumn();
$superAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn();
$distinctInstitutes = (int)$pdo->query("SELECT COUNT(DISTINCT institute_name) FROM admins WHERE institute_name IS NOT NULL AND institute_name != ''")->fetchColumn();

// Fetch all administrators with their resource counters
$stmtAdmins = $pdo->query("
    SELECT a.*,
           (SELECT COUNT(*) FROM students WHERE admin_id = a.id) as student_count,
           (SELECT COUNT(*) FROM exams WHERE admin_id = a.id) as exam_count,
           (SELECT COUNT(*) FROM questions WHERE admin_id = a.id) as question_count,
           creator.username as creator_username
    FROM admins a
    LEFT JOIN admins creator ON a.created_by = creator.id
    ORDER BY (a.role = 'super_admin') DESC, a.id ASC
");
$admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

$current_admin_id = (int)$_SESSION['admin_id'];
$csrf_token = generate_csrf_token();
$currency = '₹';
?>

<style>
.admin-card {
    border-radius: 14px;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.admin-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}
.feature-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.65rem;
    font-size: 0.78rem;
    font-weight: 600;
    border-radius: 6px;
    margin: 2px;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}
.feature-grid-item {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    background: #ffffff;
    transition: all 0.15s ease;
    cursor: pointer;
}
.feature-grid-item:hover {
    border-color: #3b82f6;
    background: #f8faff;
}
.feature-grid-item.active {
    border-color: #2563eb;
    background: #eff6ff;
}
.custom-switch .custom-control-label::before {
    height: 1.5rem;
    width: 2.75rem;
    border-radius: 2rem;
}
.custom-switch .custom-control-label::after {
    width: calc(1.5rem - 4px);
    height: calc(1.5rem - 4px);
    border-radius: 2rem;
}
.institute-pill {
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
    border-radius: 999px;
    padding: 0.3rem 0.75rem;
    font-size: 0.85rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-users-cog text-warning mr-2"></i>Admin Accounts & Multi-Tenancy
        </h2>
        <p class="text-muted mb-0 small">
            Super Administrator console to create individual admin accounts, allocate customized institute branding, and govern feature permissions.
        </p>
    </div>
    <div class="mt-3 mt-md-0 d-flex flex-wrap" style="gap: 10px;">
        <a href="<?php echo BASE_URL; ?>/admin/admins/register_institute.php" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-3 mr-2"><i class="fas fa-university mr-2"></i>Onboard New Institute</a>
        <a href="<?php echo BASE_URL; ?>/admin/admins/billing.php" class="btn btn-outline-success font-weight-bold shadow-sm rounded-pill px-3">
            <i class="fas fa-file-invoice-dollar mr-2"></i>Tenant Billing & Payments
        </a>
        <button type="button" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4" data-toggle="modal" data-target="#createAdminModal">
            <i class="fas fa-user-plus mr-2"></i>Create New Admin
        </button>
    </div>
</div>

<!-- Notification Container -->
<div id="pageNotificationArea"></div>

<!-- Quick Statistics KPI Row -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card admin-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.75rem;">Total Admins</span>
                    <h3 class="font-weight-bold text-dark mb-0 mt-1" id="kpiTotalAdmins"><?= $totalAdmins ?></h3>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-users-cog fa-lg text-primary"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card admin-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.75rem;">Active Accounts</span>
                    <h3 class="font-weight-bold text-success mb-0 mt-1" id="kpiActiveAdmins"><?= $activeAdmins ?></h3>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-check-circle fa-lg text-success"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card admin-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.75rem;">Super Admins</span>
                    <h3 class="font-weight-bold text-warning mb-0 mt-1"><?= $superAdmins ?></h3>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-crown fa-lg text-warning"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card admin-card border-0 bg-white p-3">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <span class="text-muted text-uppercase font-weight-bold" style="font-size: 0.75rem;">Distinct Institutes</span>
                    <h3 class="font-weight-bold text-info mb-0 mt-1"><?= $distinctInstitutes ?></h3>
                </div>
                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                    <i class="fas fa-university fa-lg text-info"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Administrators Data Table Card -->
<div class="card shadow-sm border-0 rounded-lg overflow-hidden mb-5">
    <div class="card-header bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
        <h5 class="font-weight-bold mb-0 text-dark">
            <i class="fas fa-id-card-alt mr-2 text-primary"></i>Configured Administrator Directory
        </h5>
        <span class="badge badge-light border px-3 py-2 rounded-pill font-weight-bold text-muted">
            <?= count($admins) ?> Accounts Registered
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="adminsTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="py-3 px-4">Administrator</th>
                        <th class="py-3">Role</th>
                        <th class="py-3">Institute Name</th>
                        <th class="py-3 text-center">Enrolled Students</th>
                        <th class="py-3 text-center">Monthly Billing</th>
                        <th class="py-3 text-center">Support Access</th>
                        <th class="py-3">Modular Permissions</th>
                        <th class="py-3 text-center">Status</th>
                        <th class="py-3 text-right px-4" style="min-width: 270px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $adm): 
                        $is_self = ((int)$adm['id'] === $current_admin_id);
                        $is_super = ($adm['role'] === 'super_admin');
                        $is_subadmin = ($adm['role'] === 'sub_admin' || (!empty($adm['parent_admin_id']) && (int)$adm['parent_admin_id'] !== (int)$adm['id']));
                        $is_active = ((int)$adm['is_active'] === 1);
                        $raw_features = !empty($adm['features']) ? json_decode($adm['features'], true) : [];
                        $features_arr = is_array($raw_features) ? $raw_features : [];
                        $features_count = $is_super ? count(ALL_ADMIN_FEATURES) : count($features_arr);
                        $adm_json_attr = htmlspecialchars(json_encode($adm), ENT_QUOTES, 'UTF-8');
                        $username_json_attr = htmlspecialchars(json_encode($adm['username']), ENT_QUOTES, 'UTF-8');
                        $features_json_attr = htmlspecialchars(json_encode($features_arr), ENT_QUOTES, 'UTF-8');

                        $billing = null;
                        if (!$is_super && !$is_subadmin) {
                            $billing = get_or_create_admin_billing($adm['id'], (int)date('n'), (int)date('Y'), $pdo);
                        }
                    ?>
                    <tr id="admin-row-<?= $adm['id'] ?>">
                        <td class="py-3 px-4">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle <?= $is_super ? 'bg-warning text-dark' : ($is_subadmin ? 'bg-info text-white' : 'bg-primary text-white') ?> d-flex align-items-center justify-content-center font-weight-bold shadow-sm mr-3" style="width: 40px; height: 40px; font-size: 1rem;">
                                    <?= strtoupper(substr($adm['username'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="font-weight-bold text-dark">
                                        <?= h($adm['username']) ?>
                                        <?php if ($is_self): ?>
                                            <span class="badge badge-secondary ml-1 small">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted font-family-monospace">
                                        <?= !empty($adm['email']) ? h($adm['email']) : '<span class="text-black-50">No email specified</span>' ?>
                                    </small>
                                </div>
                            </div>
                        </td>
                        <td class="py-3">
                            <?php if ($is_super): ?>
                                <span class="badge badge-warning text-dark px-3 py-2 rounded-pill font-weight-bold shadow-sm">
                                    <i class="fas fa-crown mr-1"></i> Super Admin
                                </span>
                            <?php elseif ($is_subadmin): ?>
                                <span class="badge badge-info px-3 py-2 rounded-pill font-weight-bold shadow-sm" title="Employee under <?= h($adm['institute_name'] ?? 'Institute') ?>">
                                    <i class="fas fa-id-badge mr-1"></i> Staff (<?= h(ucfirst($adm['sub_role'] ?? 'Employee')) ?>)
                                </span>
                            <?php else: ?>
                                <span class="badge badge-primary px-3 py-2 rounded-pill font-weight-bold shadow-sm">
                                    <i class="fas fa-user-shield mr-1"></i> Institute Admin
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">
                            <div class="institute-pill">
                                <i class="fas fa-university mr-2"></i>
                                <?= h(!empty($adm['institute_name']) ? $adm['institute_name'] : 'Main Campus') ?>
                            </div>
                        </td>
                        <td class="py-3 text-center">
                            <span class="badge badge-primary px-3 py-2 rounded-pill font-weight-bold shadow-xs" style="font-size: 0.82rem;" title="Total Enrolled Students under this Administrator">
                                <i class="fas fa-user-graduate mr-1"></i><?= (int)$adm['student_count'] ?> Enrolled
                            </span>
                            <div class="mt-1 small text-muted">
                                <i class="fas fa-file-alt mr-1"></i><?= (int)$adm['exam_count'] ?> Exams
                            </div>
                        </td>
                        <td class="py-3 text-center">
                            <?php if ($is_super): ?>
                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold">
                                    <i class="fas fa-shield-alt mr-1"></i>Owner
                                </span>
                            <?php elseif ($is_subadmin): ?>
                                <span class="badge badge-light border text-success px-2 py-1 font-weight-bold" title="Employees are on staff payroll and exempt from tenant billing">
                                    <i class="fas fa-user-tie mr-1"></i>Payroll (Exempt)
                                </span>
                            <?php else: 
                                $bStatus = $billing['status'] ?? 'pending';
                                $bDue = (float)($billing['amount_due'] ?? 0);
                                $bDelay = (int)($billing['delay_days'] ?? 0);
                            ?>
                                <?php if ($bStatus === 'paid'): ?>
                                    <span class="badge badge-success px-2 py-1 rounded-pill font-weight-bold" title="Paid on <?= h($billing['payment_date'] ?? '') ?>">
                                        <i class="fas fa-check-circle mr-1"></i> Paid
                                    </span>
                                <?php elseif ($bStatus === 'overdue'): ?>
                                    <span class="badge badge-danger px-2 py-1 rounded-pill font-weight-bold" title="Payment due on <?= h($billing['due_date']) ?>">
                                        <i class="fas fa-exclamation-triangle mr-1"></i> Overdue (<?= $bDelay ?>d)
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning text-dark px-2 py-1 rounded-pill font-weight-bold" title="Payment due on <?= h($billing['due_date']) ?>">
                                        <i class="fas fa-clock mr-1"></i> Pending
                                    </span>
                                <?php endif; ?>
                                <div class="small font-weight-bold text-muted mt-1">₹<?= number_format($bDue, 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-center">
                            <?php if ($is_super): ?>
                                <span class="badge badge-light border text-muted px-2 py-1 font-weight-bold" title="Super Administrator master account">
                                    <i class="fas fa-crown text-warning mr-1"></i>Master
                                </span>
                            <?php else: ?>
                                <?php if (!empty($adm['support_permission'])): ?>
                                    <span class="badge badge-success px-2 py-1 rounded-pill font-weight-bold" title="Support Allowed: Super Admin can troubleshoot settings & Cloudinary CDN">
                                        <i class="fas fa-lock-open mr-1"></i> Allowed
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-secondary px-2 py-1 rounded-pill font-weight-bold" title="Individual Ownership: Personal settings and keys locked against changes">
                                        <i class="fas fa-lock mr-1"></i> Private
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="py-3">
                            <?php if ($is_super): ?>
                                <span class="badge badge-warning text-dark px-2 py-1 font-weight-bold" title="Super Admins have universal access to all 12 modules">
                                    <i class="fas fa-unlock-alt mr-1"></i> All Features (12/12)
                                </span>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill font-weight-bold px-3 py-1" onclick='openFeaturesModal(<?= $username_json_attr ?>, <?= $features_json_attr ?>)'>
                                    <i class="fas fa-sliders-h mr-1 text-primary"></i> <?= $features_count ?> of <?= count(ALL_ADMIN_FEATURES) ?> Features
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-center">
                            <?php if ($is_active): ?>
                                <span class="badge badge-success px-3 py-2 rounded-pill font-weight-bold" id="status-badge-<?= $adm['id'] ?>">
                                    <i class="fas fa-check-circle mr-1"></i> Active
                                </span>
                            <?php else: ?>
                                <span class="badge badge-danger px-3 py-2 rounded-pill font-weight-bold" id="status-badge-<?= $adm['id'] ?>">
                                    <i class="fas fa-ban mr-1"></i> Suspended
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($adm['must_change_password'])): ?>
                                <div class="mt-1">
                                    <span class="badge badge-warning text-dark font-weight-bold px-2 py-1 shadow-xs" style="font-size: 0.68rem;" title="Administrator has not yet changed initial temporary password">
                                        <i class="fas fa-key mr-1"></i>Temp Password
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 text-right px-4">
                            <div class="d-flex justify-content-end align-items-center flex-wrap" style="gap: 5px;">
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-2 font-weight-bold shadow-xs" 
                                        onclick='openEditModal(<?= $adm_json_attr ?>)' title="Edit Administrator & Modular Permissions">
                                    <i class="fas fa-edit mr-1"></i> Edit
                                </button>
                                <?php if (!$is_super): ?>
                                    <a href="<?php echo BASE_URL; ?>/admin/messages.php?channel=admin&target_admin_id=<?= $adm['id'] ?>" 
                                       class="btn btn-sm btn-outline-info rounded-pill px-2 font-weight-bold shadow-xs" 
                                       title="Direct Chat with Administrator <?= h($adm['username']) ?>">
                                        <i class="fas fa-comment-dots mr-1"></i> Chat
                                    </a>
                                    <?php if ($is_subadmin): ?>
                                        <a href="<?php echo BASE_URL; ?>/admin/payroll/staff_ledger.php?sub_admin_id=<?= $adm['id'] ?>" 
                                           class="btn btn-sm btn-outline-success rounded-pill px-2 font-weight-bold shadow-xs" 
                                           title="View Staff Salary Ledger & Payroll">
                                            <i class="fas fa-money-check-alt mr-1"></i> Payroll
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo BASE_URL; ?>/admin/admins/billing.php?month=<?= (int)date('n') ?>&year=<?= (int)date('Y') ?>#admin-row-<?= $adm['id'] ?>" 
                                           class="btn btn-sm btn-outline-secondary rounded-pill px-2 font-weight-bold shadow-xs" 
                                           title="View Subscriptions & Billing Records">
                                            <i class="fas fa-file-invoice-dollar mr-1"></i> Billing
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-2 font-weight-bold shadow-xs" 
                                                title="Configure Custom Pricing & Per-Feature Billing"
                                                onclick='openBillingProfileModal(<?= $adm['id'] ?>, <?= $username_json_attr ?>, <?= json_encode($adm['institute_name']) ?>)'>
                                            <i class="fas fa-tags mr-1"></i> Pricing
                                        </button>
                                        <?php if (isset($bStatus) && $bStatus !== 'paid'): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 font-weight-bold shadow-xs" 
                                                    title="Send delayed payment reminder email to this admin"
                                                    onclick='quickSendReminder(<?= $adm['id'] ?>, <?= $username_json_attr ?>, <?= (int)$adm['student_count'] ?>, <?= $bDue ?>, <?= $bDelay ?>, this)'>
                                                <i class="fas fa-bell mr-1"></i> Remind
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!$is_self): ?>
                                    <?php if ($is_active): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-2 font-weight-bold shadow-xs" 
                                                id="btn-toggle-<?= $adm['id'] ?>"
                                                title="Deactivate and suspend this account"
                                                onclick='toggleStatus(<?= $adm['id'] ?>, <?= $username_json_attr ?>, 0)'>
                                            <i class="fas fa-ban mr-1"></i> Disable
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-2 font-weight-bold shadow-xs" 
                                                id="btn-toggle-<?= $adm['id'] ?>"
                                                title="Activate and restore this account"
                                                onclick='toggleStatus(<?= $adm['id'] ?>, <?= $username_json_attr ?>, 1)'>
                                            <i class="fas fa-check-circle mr-1"></i> Enable
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger rounded-pill px-2 font-weight-bold shadow-xs" 
                                            id="btn-delete-<?= $adm['id'] ?>"
                                            title="Permanently Delete Administrator"
                                            onclick='openDeleteModal(<?= $adm['id'] ?>, <?= $username_json_attr ?>, <?= (int)$adm['student_count'] ?>, <?= (int)$adm['exam_count'] ?>)'>
                                        <i class="fas fa-trash-alt mr-1"></i> Delete
                                    </button>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted px-2 py-1 rounded-pill font-weight-bold">
                                        <i class="fas fa-user-shield mr-1 text-primary"></i> You
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODAL: CREATE ADMIN ACCOUNT ================= -->
<div class="modal fade" id="createAdminModal" tabindex="-1" role="dialog" aria-labelledby="createAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <form id="createAdminForm" onsubmit="submitCreateAdmin(event)">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                
                <div class="modal-header bg-primary text-white py-3 px-4">
                    <h5 class="modal-title font-weight-bold" id="createAdminModalLabel">
                        <i class="fas fa-user-plus mr-2"></i>Create New Administrator Account
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                
                <div class="modal-body p-4">
                    <div id="createAdminAlert"></div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-user text-muted"></i></span></div>
                                <input type="text" name="username" class="form-control" placeholder="e.g. branch_admin, kota_lead" required autocomplete="off">
                            </div>
                            <small class="text-muted">Unique login handle (letters, numbers, underscores).</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Institute / Branch Name <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-university text-muted"></i></span></div>
                                <input type="text" name="institute_name" class="form-control" placeholder="e.g. Apex Academy, Kota IIT Wing" required>
                            </div>
                            <small class="text-muted">Branding displayed to students assigned to this admin.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Email Address</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span></div>
                                <input type="email" name="email" class="form-control" placeholder="admin@institute.org" autocomplete="off">
                            </div>
                            <small class="text-muted">Optional; used for admin notification digests.</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text bg-light"><i class="fas fa-lock text-muted"></i></span></div>
                                <input type="text" name="password" id="createPasswordInput" class="form-control" placeholder="Enter password (min 6 chars)" required minlength="6">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateRandomPass('createPasswordInput')">
                                        <i class="fas fa-dice mr-1"></i> Gen
                                    </button>
                                </div>
                            </div>
                            <div class="custom-control custom-checkbox mt-2">
                                <input type="checkbox" name="must_change_password" value="1" class="custom-control-input" id="createMustChangePwd" checked>
                                <label class="custom-control-label font-weight-bold text-dark" for="createMustChangePwd" style="font-size: 0.78rem;">
                                    <i class="fas fa-key text-warning mr-1"></i> Require admin to change password on first login
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Account Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-control" id="createRoleSelect" onchange="toggleFeatureSection('create')">
                                <option value="admin" selected>Institute Administrator (Standard Tenant)</option>
                                <option value="super_admin">Super Administrator (Full Platform Control)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Initial Account Status</label>
                            <select name="is_active" class="form-control">
                                <option value="1" selected>Active (Login Allowed)</option>
                                <option value="0">Suspended / Deactivated</option>
                            </select>
                        </div>
                    </div>

                    <!-- Modular Features Selection -->
                    <div id="createFeaturesContainer" class="mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="font-weight-bold text-dark mb-0">
                                    <i class="fas fa-shield-alt text-primary mr-1"></i> Feature Permissions
                                </h6>
                                <small class="text-muted">Choose which system features this administrator can access.</small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="setPresetFeatures('create', 'all')">All</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setPresetFeatures('create', 'teaching')">Teaching</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setPresetFeatures('create', 'qbank')">Questions Only</button>
                                <button type="button" class="btn btn-outline-danger" onclick="setPresetFeatures('create', 'none')">Clear</button>
                            </div>
                        </div>

                        <div class="row">
                            <?php foreach (ALL_ADMIN_FEATURES as $fKey => $fMeta): ?>
                            <div class="col-md-6 mb-2">
                                <label class="feature-grid-item d-flex align-items-start mb-0 w-100" for="feat_create_<?= $fKey ?>">
                                    <div class="custom-control custom-checkbox mr-3 mt-1">
                                        <input type="checkbox" name="features[]" value="<?= $fKey ?>" class="custom-control-input create-feat-check" id="feat_create_<?= $fKey ?>" checked>
                                        <label class="custom-control-label" for="feat_create_<?= $fKey ?>"></label>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="font-weight-bold text-dark small">
                                            <i class="<?= $fMeta['icon'] ?> text-primary mr-1"></i> <?= h($fMeta['label']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.76rem;"><?= h($fMeta['desc']) ?></div>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Super Admin Info Note (shown when role = super_admin) -->
                    <div id="createSuperAdminNotice" class="alert alert-warning mb-0 mt-3 d-none">
                        <i class="fas fa-crown text-warning mr-2"></i>
                        <strong>Super Administrator Role:</strong> Has universal, unconstrained access to all present and future platform features, as well as multi-tenant administrator governance.
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="createAdminSubmitBtn">
                        <i class="fas fa-check mr-2"></i>Create Administrator
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= MODAL: EDIT ADMIN & PERMISSIONS ================= -->
<div class="modal fade" id="editAdminModal" tabindex="-1" role="dialog" aria-labelledby="editAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <form id="editAdminForm" onsubmit="submitEditAdmin(event)">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <input type="hidden" name="admin_id" id="editAdminId">
                
                <div class="modal-header bg-dark text-white py-3 px-4">
                    <h5 class="modal-title font-weight-bold" id="editAdminModalLabel">
                        <i class="fas fa-user-edit mr-2 text-warning"></i>Edit Administrator & Permissions
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                
                <div class="modal-body p-4">
                    <div id="editAdminAlert"></div>
                    <div id="editAdminSupportStatus" class="mb-3"></div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="editUsername" class="form-control" required autocomplete="off">
                            <small class="text-muted">Unique login identifier.</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Institute / Branch Name <span class="text-danger">*</span></label>
                            <input type="text" name="institute_name" id="editInstituteName" class="form-control" required>
                            <small class="text-muted" id="editInstituteDesc">Branding displayed to students assigned to this admin.</small>
                            <small class="text-danger font-weight-bold d-none" id="editLockedNoteInstitute"><i class="fas fa-lock mr-1"></i>Locked by tenant (Support Permission disabled)</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Email Address</label>
                            <input type="email" name="email" id="editEmail" class="form-control" autocomplete="off">
                            <small class="text-danger font-weight-bold d-none" id="editLockedNoteEmail"><i class="fas fa-lock mr-1"></i>Locked by tenant (Support Permission disabled)</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">New Password (Optional)</label>
                            <div class="input-group">
                                <input type="text" name="password" id="editPasswordInput" class="form-control" placeholder="Leave blank to keep current">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary" id="editGenPassBtn" onclick="generateRandomPass('editPasswordInput')">
                                        <i class="fas fa-dice mr-1"></i> Gen
                                    </button>
                                </div>
                            </div>
                            <small class="text-danger font-weight-bold d-none" id="editLockedNotePassword"><i class="fas fa-lock mr-1"></i>Locked by tenant (Support Permission disabled)</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Account Role <span class="text-danger">*</span></label>
                            <select name="role" id="editRoleSelect" class="form-control" onchange="toggleFeatureSection('edit')">
                                <option value="admin">Institute Administrator (Standard Tenant)</option>
                                <option value="super_admin">Super Administrator (Full Platform Control)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Account Status</label>
                            <select name="is_active" id="editIsActive" class="form-control">
                                <option value="1">Active (Login Allowed)</option>
                                <option value="0">Suspended / Deactivated</option>
                            </select>
                        </div>
                    </div>

                    <!-- Modular Features Selection -->
                    <div id="editFeaturesContainer" class="mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h6 class="font-weight-bold text-dark mb-0">
                                    <i class="fas fa-shield-alt text-primary mr-1"></i> Feature Permissions
                                </h6>
                                <small class="text-muted">Grant or revoke specific functional modules for this tenant.</small>
                            </div>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" onclick="setPresetFeatures('edit', 'all')">All</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setPresetFeatures('edit', 'teaching')">Teaching</button>
                                <button type="button" class="btn btn-outline-secondary" onclick="setPresetFeatures('edit', 'qbank')">Questions Only</button>
                                <button type="button" class="btn btn-outline-danger" onclick="setPresetFeatures('edit', 'none')">Clear</button>
                            </div>
                        </div>

                        <div class="row">
                            <?php foreach (ALL_ADMIN_FEATURES as $fKey => $fMeta): ?>
                            <div class="col-md-6 mb-2">
                                <label class="feature-grid-item d-flex align-items-start mb-0 w-100" for="feat_edit_<?= $fKey ?>">
                                    <div class="custom-control custom-checkbox mr-3 mt-1">
                                        <input type="checkbox" name="features[]" value="<?= $fKey ?>" class="custom-control-input edit-feat-check" id="feat_edit_<?= $fKey ?>">
                                        <label class="custom-control-label" for="feat_edit_<?= $fKey ?>"></label>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="font-weight-bold text-dark small">
                                            <i class="<?= $fMeta['icon'] ?> text-primary mr-1"></i> <?= h($fMeta['label']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size: 0.76rem;"><?= h($fMeta['desc']) ?></div>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Super Admin Info Note -->
                    <div id="editSuperAdminNotice" class="alert alert-warning mb-0 mt-3 d-none">
                        <i class="fas fa-crown text-warning mr-2"></i>
                        <strong>Super Administrator Role:</strong> Has unrestricted universal permissions across all current and future modules.
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4 d-flex justify-content-between align-items-center">
                    <div id="editModalTenantActions" style="display: none;">
                        <button type="button" class="btn btn-outline-warning font-weight-bold rounded-pill px-3 mr-2" id="editModalToggleBtn" onclick="toggleStatusFromEditModal()">
                            <i class="fas fa-ban mr-1"></i> <span id="editModalToggleText">Disable Account</span>
                        </button>
                        <button type="button" class="btn btn-outline-danger font-weight-bold rounded-pill px-3" id="editModalDeleteBtn" onclick="deleteAdminFromEditModal()">
                            <i class="fas fa-trash-alt mr-1"></i> Delete Account
                        </button>
                    </div>
                    <div class="ml-auto">
                        <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4 mr-2" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="editAdminSubmitBtn">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= MODAL: VIEW GRANTED FEATURES ================= -->
<div class="modal fade" id="featuresModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-light py-3 px-4 border-bottom">
                <h5 class="modal-title font-weight-bold text-dark">
                    <i class="fas fa-sliders-h text-primary mr-2"></i>Permissions for <span id="viewFeaturesUsername" class="text-primary font-family-monospace"></span>
                </h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-4" id="viewFeaturesListContainer"></div>
            <div class="modal-footer bg-light py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm font-weight-bold rounded-pill px-3" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ================= MODAL: CONFIGURE CUSTOM PRICING & PER-FEATURE BILLING ================= -->
<div class="modal fade" id="configurePricingModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-tags text-warning mr-2"></i>Configure Institute Pricing & Billing - <span id="modalInstituteName" class="text-warning"></span>
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="billingProfileForm" onsubmit="saveBillingProfile(event)">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="admin_id" id="modalAdminId" value="">

                <div class="modal-body p-4">
                    <div id="modalBillingAlert"></div>

                    <div class="alert alert-info border-0 shadow-xs rounded-lg p-3 mb-4 d-flex align-items-center">
                        <i class="fas fa-info-circle fa-2x mr-3 text-primary"></i>
                        <div class="small">
                            Define custom subscription rates, per-feature add-on prices, or per-student fees for <strong><span id="modalAdminUsername"></span></strong>. Changes take effect immediately on current and future monthly invoices.
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark small text-uppercase">Billing Calculation Model</label>
                        <select name="billing_model" id="modalBillingModel" class="form-control custom-select" onchange="recalcModalPricing()">
                            <option value="standard">Standard Global Flat Subscription (<?= $currency ?>1,500/mo)</option>
                            <option value="custom_fixed">Custom Negotiated Fixed Monthly Fee</option>
                            <option value="per_feature">Per-Feature Based Pricing (Billed solely for enabled modules)</option>
                            <option value="hybrid">Hybrid (Base Fee + Feature Add-ons + Per-Student Tier)</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3" id="modalBaseFeeGroup">
                            <label class="font-weight-bold text-dark small text-uppercase">Base Monthly Fee (<?= $currency ?>)</label>
                            <input type="number" step="0.01" min="0" name="base_monthly_fee" id="modalBaseFee" class="form-control font-weight-bold" value="1500.00" oninput="recalcModalPricing()">
                        </div>
                        <div class="col-md-4 mb-3" id="modalPerStudentGroup">
                            <label class="font-weight-bold text-dark small text-uppercase">Per Student Fee (<?= $currency ?>)</label>
                            <input type="number" step="0.01" min="0" name="per_student_fee" id="modalPerStudent" class="form-control font-weight-bold" value="0.00" oninput="recalcModalPricing()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Monthly Payment Due Day</label>
                            <input type="number" min="1" max="28" name="billing_due_day" id="modalDueDay" class="form-control font-weight-bold" value="10">
                            <small class="text-muted">Day of month (1-28).</small>
                        </div>
                    </div>

                    <!-- Per-Feature Price Configuration -->
                    <div id="modalFeaturePricingSection" class="p-3 bg-light rounded border mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold text-dark small text-uppercase">
                                <i class="fas fa-tags text-primary mr-1"></i> Per-Feature Monthly Add-On Prices
                            </span>
                            <small class="text-muted">Only applies to features currently enabled for this tenant.</small>
                        </div>
                        <div class="row" id="modalFeaturePricesContainer">
                            <!-- Populated dynamically via JS -->
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Monthly Discount / Concession (<?= $currency ?>)</label>
                            <input type="number" step="0.01" min="0" name="discount_amount" id="modalDiscount" class="form-control font-weight-bold text-danger" value="0.00" oninput="recalcModalPricing()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Special Billing Notes / Terms</label>
                            <input type="text" name="notes" id="modalNotes" class="form-control" placeholder="e.g. Sibling discount, first quarter promo">
                        </div>
                    </div>

                    <!-- Live Calculation Card -->
                    <div class="card border-primary bg-primary bg-opacity-10 shadow-xs rounded-lg p-3 text-center">
                        <span class="small font-weight-bold text-primary text-uppercase">Projected Monthly Total Due</span>
                        <h2 class="font-weight-bold text-dark mb-0 mt-1" id="modalProjectedTotal"><?= $currency ?>1,500.00</h2>
                        <div class="small text-muted mt-1" id="modalProjectedBreakdown">Base: <?= $currency ?>1,500.00</div>
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 px-4">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="saveBillingBtn">
                        <i class="fas fa-save mr-1"></i> Save Pricing Profile
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ================= MODAL: CONFIRM PERMANENT DELETE ================= -->
<div class="modal fade" id="deleteAdminModal" tabindex="-1" role="dialog" aria-labelledby="deleteAdminModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-danger text-white py-3 px-4">
                <h5 class="modal-title font-weight-bold" id="deleteAdminModalLabel">
                    <i class="fas fa-exclamation-triangle mr-2"></i>Permanently Delete Administrator
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4">
                <div id="deleteModalAlert"></div>
                
                <p class="text-dark font-weight-bold mb-3" style="font-size: 1.05rem;">
                    Are you sure you want to permanently delete administrator <span class="badge badge-danger px-2 py-1 font-family-monospace" id="deleteModalUsername" style="font-size: 1rem;"></span>?
                </p>

                <div class="alert alert-warning border-warning">
                    <h6 class="font-weight-bold text-dark mb-1">
                        <i class="fas fa-shield-alt text-warning mr-1"></i> Data Preservation Guarantee:
                    </h6>
                    <p class="small text-muted mb-2">
                        To protect institutional data and avoid orphaned records, the following assets will be <strong>safely reassigned</strong> to your Super Administrator account:
                    </p>
                    <ul class="small text-muted mb-0 pl-3">
                        <li><strong><span id="deleteModalStudentCount">0</span></strong> Registered Students</li>
                        <li><strong><span id="deleteModalExamCount">0</span></strong> Exams (including all completed student attempts and marks)</li>
                        <li>All associated questions, exam releases, and direct messages</li>
                    </ul>
                </div>

                <div class="alert alert-danger bg-light text-danger border-0 small mb-0 py-2">
                    <i class="fas fa-info-circle mr-1"></i> This administrator account deletion cannot be undone.
                </div>
            </div>
            <div class="modal-footer bg-light py-3 px-4">
                <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger font-weight-bold rounded-pill px-4" id="confirmDeleteAdminBtn" onclick="confirmDeleteAdmin()">
                    <i class="fas fa-trash-alt mr-2"></i>Yes, Delete Account
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Available features catalog in JS
const ALL_FEATURES_CATALOG = <?= json_encode(ALL_ADMIN_FEATURES) ?>;

$(document).ready(function() {
    $('#adminsTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc'], [0, 'asc']],
        columnDefs: [
            { orderable: false, targets: [5, 7] }
        ],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search administrators...",
            lengthMenu: "Show _MENU_ admins"
        }
    });
});

function generateRandomPass(elementId) {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById(elementId).value = pass;
}

function toggleFeatureSection(prefix) {
    const roleSelect = document.getElementById(prefix + 'RoleSelect');
    const featuresContainer = document.getElementById(prefix + 'FeaturesContainer');
    const notice = document.getElementById(prefix + 'SuperAdminNotice');
    
    if (roleSelect.value === 'super_admin') {
        featuresContainer.classList.add('d-none');
        notice.classList.remove('d-none');
    } else {
        featuresContainer.classList.remove('d-none');
        notice.classList.add('d-none');
    }
}

function setPresetFeatures(prefix, preset) {
    const checkboxes = document.querySelectorAll('.' + prefix + '-feat-check');
    
    const teachingPreset = ['questions', 'exams', 'students', 'enrolments', 'messages', 'reports'];
    const qbankPreset = ['questions', 'upload_csv', 'ai_generation', 'subjects', 'image_storage'];

    checkboxes.forEach(cb => {
        if (preset === 'all') {
            cb.checked = true;
        } else if (preset === 'none') {
            cb.checked = false;
        } else if (preset === 'teaching') {
            cb.checked = teachingPreset.includes(cb.value);
        } else if (preset === 'qbank') {
            cb.checked = qbankPreset.includes(cb.value);
        }
    });
}

function openFeaturesModal(username, features) {
    $('#viewFeaturesUsername').text(username);
    let html = '<div class="list-group list-group-flush">';
    
    for (const [key, meta] of Object.entries(ALL_FEATURES_CATALOG)) {
        const isGranted = features.includes(key);
        html += `
            <div class="list-group-item d-flex align-items-center justify-content-between px-2 py-2">
                <div class="d-flex align-items-center">
                    <div class="mr-3 text-center" style="width: 24px;">
                        <i class="${meta.icon} ${isGranted ? 'text-primary' : 'text-muted'}"></i>
                    </div>
                    <div>
                        <div class="font-weight-bold ${isGranted ? 'text-dark' : 'text-muted'} small">${meta.label}</div>
                        <div class="text-muted" style="font-size: 0.72rem;">${meta.desc}</div>
                    </div>
                </div>
                <div>
                    ${isGranted 
                        ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Granted</span>' 
                        : '<span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-times mr-1"></i>Denied</span>'}
                </div>
            </div>
        `;
    }
    html += '</div>';
    $('#viewFeaturesListContainer').html(html);
    $('#featuresModal').modal('show');
}

let currentEditingAdmin = null;
let currentDeletingAdmin = null;

function showNotification(message, type = 'success') {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show shadow-sm border-0 mb-4" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'} mr-2"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    $('#pageNotificationArea').html(alertHtml);
    $('html, body').animate({ scrollTop: 0 }, 200);
}

function updateKpiCounters() {
    let active = 0;
    let total = 0;
    $('#adminsTable tbody tr').each(function() {
        if ($(this).find('.badge-success').length > 0) {
            active++;
        }
        total++;
    });
    $('#kpiActiveAdmins').text(active);
    $('#kpiTotalAdmins').text(total);
}

function openEditModal(admin) {
    currentEditingAdmin = admin;
    $('#editAdminId').val(admin.id);
    $('#editUsername').val(admin.username);
    $('#editInstituteName').val(admin.institute_name || 'Main Campus');
    $('#editEmail').val(admin.email || '');
    $('#editPasswordInput').val('');
    $('#editRoleSelect').val(admin.role);
    $('#editIsActive').val(admin.is_active);

    // Parse features
    let granted = [];
    if (admin.features) {
        try {
            granted = JSON.parse(admin.features);
        } catch(e) {
            granted = [];
        }
    }

    $('.edit-feat-check').each(function() {
        $(this).prop('checked', granted.includes($(this).val()));
    });

    toggleFeatureSection('edit');
    $('#editAdminAlert').html('');

    // Check support permission and individual ownership status
    const currentUserId = <?= (int)$current_admin_id ?>;
    const isTargetTenant = (parseInt(admin.id) !== currentUserId && admin.role !== 'super_admin');
    const hasSupport = (parseInt(admin.support_permission) === 1);

    if (isTargetTenant) {
        if (hasSupport) {
            $('#editAdminSupportStatus').html(`
                <div class="alert alert-success border-success p-3 rounded-lg mb-0 shadow-xs">
                    <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 10px;">
                        <div>
                            <i class="fas fa-lock-open fa-lg text-success mr-2"></i>
                            <strong>Authorized Support Mode:</strong> This administrator has granted support permission. You may adjust their personal credentials or fix Cloudinary CDN settings.
                        </div>
                        <div class="d-flex align-items-center" style="gap: 6px;">
                            <a href="../image_storage.php?fix_admin_id=${admin.id}" target="_blank" class="btn btn-sm btn-success font-weight-bold rounded-pill shadow-xs">
                                <i class="fas fa-tools mr-1"></i> Fix Cloudinary CDN
                            </a>
                            <a href="../ai_settings.php?fix_admin_id=${admin.id}" target="_blank" class="btn btn-sm btn-primary font-weight-bold rounded-pill shadow-xs">
                                <i class="fas fa-brain mr-1"></i> Fix OpenRouter AI
                            </a>
                        </div>
                    </div>
                </div>
            `);
            $('#editInstituteName, #editEmail, #editPasswordInput').prop('readonly', false).removeClass('bg-light').css('cursor', 'text');
            $('#editGenPassBtn').prop('disabled', false);
            $('#editLockedNoteInstitute, #editLockedNoteEmail, #editLockedNotePassword').addClass('d-none');
        } else {
            $('#editAdminSupportStatus').html(`
                <div class="alert alert-warning border-warning p-3 rounded-lg mb-0 shadow-xs">
                    <div class="d-flex align-items-start">
                        <i class="fas fa-user-lock fa-lg text-warning mr-2 mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="font-weight-bold text-dark">Individual Ownership Protected</div>
                            <small class="text-muted d-block">
                                This administrator has not enabled Support Permission. Personal credentials (institute branding, recovery email, password, and private Cloudinary keys) are strictly locked.
                            </small>
                            <div class="mt-2 d-flex flex-wrap align-items-center" style="gap: 8px;">
                                <button type="button" class="btn btn-sm btn-warning font-weight-bold shadow-xs rounded-pill px-3" id="btnRequestOtp" onclick="requestSupportCode(${admin.id})">
                                    <i class="fas fa-key mr-1"></i> Request Permission Code via Email
                                </button>
                                <a href="../messages.php?channel=admin&target_admin_id=${admin.id}" target="_blank" class="btn btn-sm btn-outline-dark font-weight-bold rounded-pill px-3">
                                    <i class="fas fa-comments mr-1"></i> Chat with Admin
                                </a>
                            </div>

                            <div id="supportOtpContainer" class="mt-3 p-3 bg-white border border-warning rounded-lg" style="display: none;">
                                <label class="font-weight-bold text-dark small text-uppercase mb-1">
                                    <i class="fas fa-envelope-open-text text-warning mr-1"></i> Enter 6-Digit Email Code from Administrator:
                                </label>
                                <div class="input-group" style="max-width: 320px;">
                                    <input type="text" id="supportOtpInput" maxlength="6" class="form-control font-weight-bold text-center" placeholder="6-digit code" style="letter-spacing: 4px; font-size: 1.15rem;" autocomplete="off">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-success font-weight-bold px-3" id="btnVerifyOtp" onclick="verifySupportCode(${admin.id})">
                                            <i class="fas fa-unlock mr-1"></i> Verify & Unlock
                                        </button>
                                    </div>
                                </div>
                                <div id="supportOtpFeedback" class="mt-2 small"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
            $('#editInstituteName, #editEmail, #editPasswordInput').prop('readonly', true).addClass('bg-light').css('cursor', 'not-allowed');
            $('#editGenPassBtn').prop('disabled', true);
            $('#editLockedNoteInstitute, #editLockedNoteEmail, #editLockedNotePassword').removeClass('d-none');
        }
    } else {
        $('#editAdminSupportStatus').html('');
        $('#editInstituteName, #editEmail, #editPasswordInput').prop('readonly', false).removeClass('bg-light').css('cursor', 'text');
        $('#editGenPassBtn').prop('disabled', false);
        $('#editLockedNoteInstitute, #editLockedNoteEmail, #editLockedNotePassword').addClass('d-none');
    }

    // Tenant danger actions inside modal footer
    if (parseInt(admin.id) !== currentUserId) {
        $('#editModalTenantActions').show();
        updateEditModalToggleBtn(admin.is_active);
    } else {
        $('#editModalTenantActions').hide();
    }

    $('#editAdminModal').modal('show');
}

function updateEditModalToggleBtn(isActive) {
    const btn = $('#editModalToggleBtn');
    const txt = $('#editModalToggleText');
    if (parseInt(isActive) === 1) {
        btn.removeClass('btn-outline-success').addClass('btn-outline-warning');
        btn.find('i').removeClass('fa-check-circle').addClass('fa-ban');
        txt.text('Disable Account');
    } else {
        btn.removeClass('btn-outline-warning').addClass('btn-outline-success');
        btn.find('i').removeClass('fa-ban').addClass('fa-check-circle');
        txt.text('Enable Account');
    }
}

function toggleStatusFromEditModal() {
    if (!currentEditingAdmin) return;
    const currentStatus = parseInt($('#editIsActive').val());
    const targetStatus = (currentStatus === 1) ? 0 : 1;
    toggleStatus(currentEditingAdmin.id, currentEditingAdmin.username, targetStatus);
}

function deleteAdminFromEditModal() {
    if (!currentEditingAdmin) return;
    const admin = currentEditingAdmin;
    $('#editAdminModal').modal('hide');
    openDeleteModal(admin.id, admin.username, admin.student_count || 0, admin.exam_count || 0);
}

function submitCreateAdmin(e) {
    e.preventDefault();
    const btn = $('#createAdminSubmitBtn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...');

    $.ajax({
        url: 'create_ajax.php',
        type: 'POST',
        data: $('#createAdminForm').serialize(),
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-check mr-2"></i>Create Administrator');
            if (res.success) {
                $('#createAdminAlert').html('<div class="alert alert-success">' + res.message + '</div>');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                $('#createAdminAlert').html('<div class="alert alert-danger">' + (res.error || 'Failed to create admin.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-check mr-2"></i>Create Administrator');
            let err = 'Server error occurred.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#createAdminAlert').html('<div class="alert alert-danger">' + err + '</div>');
        }
    });
}

function submitEditAdmin(e) {
    e.preventDefault();
    const btn = $('#editAdminSubmitBtn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...');

    $.ajax({
        url: 'edit_ajax.php',
        type: 'POST',
        data: $('#editAdminForm').serialize(),
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i>Save Changes');
            if (res.success) {
                $('#editAdminAlert').html('<div class="alert alert-success">' + res.message + '</div>');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                $('#editAdminAlert').html('<div class="alert alert-danger">' + (res.error || 'Failed to update admin.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i>Save Changes');
            let err = 'Server error occurred.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#editAdminAlert').html('<div class="alert alert-danger">' + err + '</div>');
        }
    });
}

function requestSupportCode(adminId) {
    const btn = $('#btnRequestOtp');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending Code...');
    $('#supportOtpContainer').slideDown(150);
    $('#supportOtpFeedback').html('<div class="text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Generating code and sending email...</div>');

    $.ajax({
        url: 'request_support_code_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            csrf_token: '<?= h($csrf_token) ?>'
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-redo mr-1"></i> Resend Code');
            if (res.success) {
                $('#supportOtpFeedback').html('<div class="text-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i> ' + res.message + '</div>');
                $('#supportOtpInput').val('').focus();
            } else {
                $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> ' + (res.error || 'Failed to dispatch code.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-key mr-1"></i> Request Permission Code');
            let err = 'Server error occurred while requesting authorization code.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> ' + err + '</div>');
        }
    });
}

function verifySupportCode(adminId) {
    const code = $('#supportOtpInput').val().trim();
    if (!code || code.length !== 6) {
        $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> Please enter the complete 6-digit code.</div>');
        return;
    }

    const btn = $('#btnVerifyOtp');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>');
    $('#supportOtpFeedback').html('<div class="text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Verifying authorization code...</div>');

    $.ajax({
        url: 'verify_support_code_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            code: code,
            csrf_token: '<?= h($csrf_token) ?>'
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-unlock mr-1"></i> Verify & Unlock');
            if (res.success) {
                if (currentEditingAdmin && parseInt(currentEditingAdmin.id) === parseInt(adminId)) {
                    currentEditingAdmin.support_permission = 1;
                }
                $('#editAdminSupportStatus').html(`
                    <div class="alert alert-success border-success p-3 rounded-lg mb-0 shadow-xs">
                        <div class="d-flex align-items-center justify-content-between flex-wrap" style="gap: 10px;">
                            <div>
                                <i class="fas fa-lock-open fa-lg text-success mr-2"></i>
                                <strong>Authorized Support Mode:</strong> Email authorization code verified! Unrestricted settings access unlocked.
                            </div>
                            <div class="d-flex align-items-center" style="gap: 6px;">
                                <a href="../image_storage.php?fix_admin_id=${adminId}" target="_blank" class="btn btn-sm btn-success font-weight-bold rounded-pill shadow-xs">
                                    <i class="fas fa-tools mr-1"></i> Fix Cloudinary CDN
                                </a>
                                <a href="../ai_settings.php?fix_admin_id=${adminId}" target="_blank" class="btn btn-sm btn-primary font-weight-bold rounded-pill shadow-xs">
                                    <i class="fas fa-brain mr-1"></i> Fix OpenRouter AI
                                </a>
                            </div>
                        </div>
                    </div>
                `);
                $('#editInstituteName, #editEmail, #editPasswordInput').prop('readonly', false).removeClass('bg-light').css('cursor', 'text');
                $('#editGenPassBtn').prop('disabled', false);
                $('#editLockedNoteInstitute, #editLockedNoteEmail, #editLockedNotePassword').addClass('d-none');
                showNotification(res.message, 'success');
            } else {
                $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> ' + (res.error || 'Verification failed.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-unlock mr-1"></i> Verify & Unlock');
            let err = 'Server error occurred while verifying code.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> ' + err + '</div>');
        }
    });
}

function toggleStatus(adminId, username, targetStatus) {
    const actionLabel = (targetStatus === 0) ? 'disable/deactivate' : (targetStatus === 1 ? 'enable/activate' : 'toggle');
    if (!confirm(`Are you sure you want to ${actionLabel} administrator account '${username}'?`)) {
        return;
    }

    const postData = {
        admin_id: adminId,
        csrf_token: '<?= h($csrf_token) ?>'
    };
    if (typeof targetStatus !== 'undefined' && targetStatus !== null) {
        postData.target_status = targetStatus;
    }

    $.ajax({
        url: 'toggle_status_ajax.php',
        type: 'POST',
        data: postData,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                const newStatus = res.new_status;

                // Update table status badge
                const badge = $(`#status-badge-${adminId}`);
                if (newStatus === 1) {
                    badge.removeClass('badge-danger').addClass('badge-success').html('<i class="fas fa-check-circle mr-1"></i> Active');
                } else {
                    badge.removeClass('badge-success').addClass('badge-danger').html('<i class="fas fa-ban mr-1"></i> Suspended');
                }

                // Update table toggle button
                const btn = $(`#btn-toggle-${adminId}`);
                if (newStatus === 1) {
                    btn.removeClass('btn-outline-success').addClass('btn-outline-warning')
                       .html('<i class="fas fa-ban mr-1"></i> Disable')
                       .attr('onclick', `toggleStatus(${adminId}, ${JSON.stringify(username)}, 0)`)
                       .attr('title', 'Deactivate and suspend this account');
                } else {
                    btn.removeClass('btn-outline-warning').addClass('btn-outline-success')
                       .html('<i class="fas fa-check-circle mr-1"></i> Enable')
                       .attr('onclick', `toggleStatus(${adminId}, ${JSON.stringify(username)}, 1)`)
                       .attr('title', 'Activate and restore this account');
                }

                // Update edit modal if open for this admin
                if (currentEditingAdmin && parseInt(currentEditingAdmin.id) === parseInt(adminId)) {
                    currentEditingAdmin.is_active = newStatus;
                    $('#editIsActive').val(newStatus);
                    updateEditModalToggleBtn(newStatus);
                }

                updateKpiCounters();
                showNotification(res.message, 'success');
            } else {
                alert(res.error || 'Failed to update account status.');
            }
        },
        error: function(xhr) {
            let err = 'Failed to contact server.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            alert(err);
        }
    });
}

function openDeleteModal(adminId, username, studentCount, examCount) {
    currentDeletingAdmin = { id: adminId, username: username };
    $('#deleteModalUsername').text(username);
    $('#deleteModalStudentCount').text(studentCount || 0);
    $('#deleteModalExamCount').text(examCount || 0);
    $('#deleteModalAlert').html('');
    $('#confirmDeleteAdminBtn').prop('disabled', false).html('<i class="fas fa-trash-alt mr-2"></i>Yes, Delete Account');
    $('#deleteAdminModal').modal('show');
}

function confirmDeleteAdmin() {
    if (!currentDeletingAdmin) return;
    const adminId = currentDeletingAdmin.id;
    const username = currentDeletingAdmin.username;
    const btn = $('#confirmDeleteAdminBtn');

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Deleting & Reassigning...');

    $.ajax({
        url: 'delete_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            csrf_token: '<?= h($csrf_token) ?>'
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-trash-alt mr-2"></i>Yes, Delete Account');
            if (res.success) {
                $('#deleteAdminModal').modal('hide');

                // Safely remove row from DataTables
                try {
                    const dataTable = $('#adminsTable').DataTable();
                    const rowNode = document.getElementById(`admin-row-${adminId}`);
                    if (rowNode) {
                        dataTable.row(rowNode).remove().draw(false);
                    }
                } catch(e) {
                    $(`#admin-row-${adminId}`).remove();
                }

                updateKpiCounters();
                showNotification(res.message, 'success');
            } else {
                $('#deleteModalAlert').html('<div class="alert alert-danger">' + (res.error || 'Failed to delete administrator.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-trash-alt mr-2"></i>Yes, Delete Account');
            let err = 'Server error occurred during deletion.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#deleteModalAlert').html('<div class="alert alert-danger">' + err + '</div>');
        }
    });
}

function quickSendReminder(adminId, username, enrolledStudents, amountDue, delayDays, btnElem) {
    const delayNotice = delayDays > 0 ? ` (Delayed by ${delayDays} days)` : '';
    const confirmMsg = `Send official payment reminder email to '${username}'?\n\n- Enrolled Students: ${enrolledStudents}\n- Amount Due: ₹${amountDue.toFixed(2)}${delayNotice}\n\nThe administrator will receive an email notice with UPI and bank details.`;

    if (!confirm(confirmMsg)) {
        return;
    }

    const $btn = $(btnElem);
    const origHtml = $btn.html();
    $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending...');

    $.ajax({
        url: 'send_reminder_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            billing_month: <?= (int)date('n') ?>,
            billing_year: <?= (int)date('Y') ?>,
            csrf_token: '<?= h($csrf_token) ?>'
        },
        dataType: 'json',
        success: function(res) {
            $btn.prop('disabled', false).html(origHtml);
            if (res.success) {
                showNotification(res.message, 'success');
            } else {
                alert(res.error || 'Failed to dispatch payment reminder.');
            }
        },
        error: function(xhr) {
            $btn.prop('disabled', false).html(origHtml);
            let err = 'Error communicating with server.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            alert(err);
        }
    });
}

// Open Billing Profile Modal
function openBillingProfileModal(adminId, username, instituteName) {
    $('#modalAdminId').val(adminId);
    $('#modalAdminUsername').text(username);
    $('#modalInstituteName').text(instituteName || username);
    $('#modalBillingAlert').empty();

    $.ajax({
        url: 'get_billing_profile_ajax.php',
        type: 'GET',
        data: { admin_id: adminId },
        dataType: 'json',
        success: function(res) {
            if (!res.success) {
                alert(res.error || 'Failed to fetch billing profile.');
                return;
            }

            const p = res.profile || {};
            const calc = res.calculation || {};
            const settings = res.settings || {};
            const enabledFeats = res.enabled_features || [];
            const allFeats = res.all_features || {};

            $('#modalBillingModel').val(p.billing_model || 'standard');
            $('#modalBaseFee').val(p.base_monthly_fee !== undefined ? p.base_monthly_fee : (settings.admin_default_monthly_fee || '1500.00'));
            $('#modalPerStudent').val(p.per_student_fee !== undefined ? p.per_student_fee : '0.00');
            $('#modalDueDay').val(p.billing_due_day !== undefined ? p.billing_due_day : (settings.admin_payment_due_day || 10));
            $('#modalDiscount').val(p.discount_amount !== undefined ? p.discount_amount : '0.00');
            $('#modalNotes').val(p.notes || '');

            // Build feature inputs
            let featHtml = '';
            const featPrices = p.feature_prices || {};
            
            // Standard recommended add-on defaults if not yet customized
            const defaults = { coding: '500.00', ai_generation: '300.00', homework: '200.00', messages: '100.00' };

            for (const [k, meta] of Object.entries(allFeats)) {
                const isEnabled = enabledFeats.includes(k);
                const currentVal = featPrices[k] !== undefined ? featPrices[k] : (defaults[k] || '0.00');

                featHtml += `
                    <div class="col-md-6 mb-2">
                        <div class="input-group input-group-sm">
                            <div class="input-group-prepend">
                                <span class="input-group-text font-weight-bold bg-white ${!isEnabled ? 'text-muted' : ''}">
                                    <i class="${meta.icon} text-primary mr-1"></i> ${meta.label}
                                    ${!isEnabled ? ' <small class="text-danger ml-1">(Off)</small>' : ''}
                                </span>
                            </div>
                            <input type="number" step="0.01" min="0" name="feature_prices[${k}]" 
                                   class="form-control text-right modal-feat-price font-weight-bold" 
                                   data-feat="${k}" data-enabled="${isEnabled ? '1' : '0'}"
                                   value="${currentVal}" placeholder="0.00" oninput="recalcModalPricing()">
                        </div>
                    </div>
                `;
            }

            $('#modalFeaturePricesContainer').html(featHtml);
            recalcModalPricing();
            $('#configurePricingModal').modal('show');
        },
        error: function() {
            alert('Unable to load billing profile from server.');
        }
    });
}

function recalcModalPricing() {
    const model = $('#modalBillingModel').val();
    const baseGroup = $('#modalBaseFeeGroup');
    const studentGroup = $('#modalPerStudentGroup');
    const featSection = $('#modalFeaturePricingSection');

    let base = parseFloat($('#modalBaseFee').val()) || 0;
    let perStudent = parseFloat($('#modalPerStudent').val()) || 0;
    let discount = parseFloat($('#modalDiscount').val()) || 0;

    if (model === 'standard') {
        baseGroup.show();
        studentGroup.hide();
        featSection.hide();
    } else if (model === 'custom_fixed') {
        baseGroup.show();
        studentGroup.hide();
        featSection.hide();
    } else if (model === 'per_feature') {
        baseGroup.hide();
        studentGroup.hide();
        featSection.show();
    } else { // hybrid
        baseGroup.show();
        studentGroup.show();
        featSection.show();
    }

    let featureTotal = 0;
    if (model === 'per_feature' || model === 'hybrid') {
        $('.modal-feat-price').each(function() {
            const isEnabled = $(this).data('enabled') === '1' || $(this).data('enabled') === 1;
            if (isEnabled) {
                featureTotal += (parseFloat($(this).val()) || 0);
            }
        });
    }

    let total = 0;
    let bText = '';
    const curr = '₹';

    if (model === 'standard') {
        total = base;
        bText = 'Standard Flat Fee: ' + curr + base.toFixed(2);
    } else if (model === 'custom_fixed') {
        total = Math.max(0, base - discount);
        bText = 'Fixed Rate: ' + curr + base.toFixed(2) + (discount > 0 ? ' - Discount: ' + curr + discount.toFixed(2) : '');
    } else if (model === 'per_feature') {
        total = Math.max(0, featureTotal - discount);
        bText = 'Feature Add-ons: ' + curr + featureTotal.toFixed(2) + (discount > 0 ? ' - Discount: ' + curr + discount.toFixed(2) : '');
    } else { // hybrid
        total = Math.max(0, base + featureTotal - discount);
        bText = 'Base: ' + curr + base.toFixed(2) + ' + Features: ' + curr + featureTotal.toFixed(2) + (discount > 0 ? ' - Discount: ' + curr + discount.toFixed(2) : '');
    }

    $('#modalProjectedTotal').text(curr + total.toFixed(2));
    $('#modalProjectedBreakdown').text(bText);
}

function saveBillingProfile(e) {
    e.preventDefault();
    const btn = $('#saveBillingBtn');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Saving...');

    $.ajax({
        url: 'save_billing_profile_ajax.php',
        type: 'POST',
        data: $('#billingProfileForm').serialize(),
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Pricing Profile');
            if (res.success) {
                $('#configurePricingModal').modal('hide');
                showNotification(res.message, 'success');
                // Reload row if needed or reload page
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                $('#modalBillingAlert').html('<div class="alert alert-danger py-2 px-3 small">' + (res.error || 'Failed to save.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-save mr-1"></i> Save Pricing Profile');
            $('#modalBillingAlert').html('<div class="alert alert-danger py-2 px-3 small">Server communication error.</div>');
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

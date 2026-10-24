<?php
// admin/sub_admins/index.php
require_once __DIR__ . '/../../includes/admin_header.php';

if (!can_manage_sub_admins()) {
    $_SESSION['flash_error'] = "Access Denied: Only Admins and Super Admins can manage sub-admin accounts.";
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit;
}

$current_admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();

// Filter inputs
$filterRole   = $_GET['sub_role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterParent = $_GET['parent_id'] ?? '';
$search       = trim($_GET['search'] ?? '');

// Current calendar month for salary status
$currMonth = (int)date('n');
$currYear  = (int)date('Y');
$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$fullMonthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// Fetch list of parent admins if Super Admin
$all_parents = $is_super ? $pdo->query("SELECT id, username, institute_name FROM admins WHERE role = 'admin' ORDER BY institute_name ASC")->fetchAll(PDO::FETCH_ASSOC) : [];

// Build sub-admin query
$sql = "
    SELECT a.*, 
           p.username as parent_username, p.institute_name as parent_institute,
           (SELECT COUNT(*) FROM batches WHERE teacher_id = a.id) as assigned_batches,
           pay.id as current_pay_id, pay.net_amount as current_pay_amount, pay.payment_date as current_pay_date, pay.receipt_no as current_pay_receipt
    FROM admins a
    LEFT JOIN admins p ON a.parent_admin_id = p.id
    LEFT JOIN payroll_payments pay ON (pay.sub_admin_id = a.id AND pay.salary_month = ? AND pay.salary_year = ?)
    WHERE a.role = 'sub_admin'
";
$params = [$currMonth, $currYear];

if (!$is_super) {
    $sql .= " AND a.parent_admin_id = ?";
    $params[] = $current_admin_id;
} elseif (!empty($filterParent)) {
    $sql .= " AND a.parent_admin_id = ?";
    $params[] = (int)$filterParent;
}

if ($filterRole !== '') {
    $sql .= " AND a.sub_role = ?";
    $params[] = $filterRole;
}

if ($filterStatus !== '') {
    $sql .= " AND a.is_active = ?";
    $params[] = (int)$filterStatus;
}

if ($search !== '') {
    $sql .= " AND (a.username LIKE ? OR a.full_name LIKE ? OR a.email LIKE ? OR a.phone LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY a.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sub_admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate metrics
$totalStaff = count($sub_admins);
$teachersCount = 0;
$accountantsCount = 0;
$totalSalaryCommitment = 0;
$totalPaidThisMonth = 0;

foreach ($sub_admins as $sa) {
    if ($sa['sub_role'] === 'teacher') $teachersCount++;
    if ($sa['sub_role'] === 'accountant') $accountantsCount++;
    $totalSalaryCommitment += (float)$sa['salary'];
    if (!empty($sa['current_pay_id'])) {
        $totalPaidThisMonth += (float)$sa['current_pay_amount'];
    }
}

$csrf_token = generate_csrf_token();
?>

<style>
.staff-card {
    border-radius: 14px;
    border: none;
    box-shadow: 0 4px 16px rgba(0,0,0,0.06);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.staff-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.1);
}
.subrole-badge {
    padding: 0.35rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 700;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
}
.role-teacher { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.role-accountant { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.role-custom { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

.feature-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.55rem;
    font-size: 0.72rem;
    font-weight: 600;
    border-radius: 6px;
    margin: 2px;
    background: #f8fafc;
    color: #334155;
    border: 1px solid #e2e8f0;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-user-shield text-primary mr-2"></i>Staff & Sub-Admin Accounts
        </h2>
        <p class="text-muted mb-0 small">
            Appoint Teachers to manage student batches, assign Accountants to handle fees & payments, set monthly salaries, and manage staff credentials.
        </p>
    </div>
    <div class="mt-2 mt-md-0 d-flex flex-wrap">
        <a href="<?php echo BASE_URL; ?>/admin/payroll/index.php" class="btn btn-outline-success font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2">
            <i class="fas fa-money-check-alt mr-1"></i>Staff Payroll Hub
        </a>
        <a href="<?php echo BASE_URL; ?>/admin/batches/index.php" class="btn btn-outline-info font-weight-bold shadow-sm rounded-pill px-3 mr-2 mb-2">
            <i class="fas fa-layer-group mr-1"></i>Teaching Batches
        </a>
        <button type="button" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4 mb-2" onclick="openAddSubAdminModal()">
            <i class="fas fa-user-plus mr-1"></i>Add Sub-Admin / Staff
        </button>
    </div>
</div>

<div id="ajaxAlertContainer"></div>

<!-- KPI Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-user-shield fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Total Staff</div>
                    <h3 class="font-weight-bold text-dark mb-0"><?= $totalStaff ?></h3>
                    <span class="small text-muted"><?= $teachersCount ?> Teachers &bull; <?= $accountantsCount ?> Accountants</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-chalkboard-teacher fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Teachers / Faculty</div>
                    <h3 class="font-weight-bold text-info mb-0"><?= $teachersCount ?></h3>
                    <span class="small text-muted">Batch & Exam Management</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-calculator fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Accountants</div>
                    <h3 class="font-weight-bold text-success mb-0"><?= $accountantsCount ?></h3>
                    <span class="small text-muted">Fees & Payroll Management</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-coins fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Monthly Payroll Budget</div>
                    <h4 class="font-weight-bold text-dark mb-0">₹<?= number_format($totalSalaryCommitment, 0) ?></h4>
                    <span class="small text-success font-weight-bold">₹<?= number_format($totalPaidThisMonth, 0) ?> Disbursed (<?= $fullMonthNames[$currMonth] ?>)</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card shadow-sm border-0 mb-4 bg-light rounded-lg">
    <div class="card-body py-3">
        <form method="GET" class="form-row align-items-center">
            <?php if ($is_super && !empty($all_parents)): ?>
            <div class="col-md-3 col-sm-6 mb-2">
                <select name="parent_id" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">-- All Institutes / Parent Admins --</option>
                    <?php foreach ($all_parents as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterParent == $p['id'] ? 'selected' : '' ?>>
                            <?= h($p['institute_name']) ?> (@<?= h($p['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-2 col-sm-6 mb-2">
                <select name="sub_role" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">-- All Roles --</option>
                    <option value="teacher" <?= $filterRole === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    <option value="accountant" <?= $filterRole === 'accountant' ? 'selected' : '' ?>>Accountant</option>
                    <option value="custom" <?= $filterRole === 'custom' ? 'selected' : '' ?>>Staff / Custom</option>
                </select>
            </div>

            <div class="col-md-2 col-sm-6 mb-2">
                <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                    <option value="">-- All Statuses --</option>
                    <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Suspended</option>
                </select>
            </div>

            <div class="col-md-4 col-sm-6 mb-2">
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control" placeholder="Search by name, username, email..." value="<?= h($search) ?>">
                    <div class="input-group-append">
                        <button class="btn btn-dark" type="submit"><i class="fas fa-search"></i></button>
                        <?php if ($search || $filterRole !== '' || $filterStatus !== '' || $filterParent !== ''): ?>
                            <a href="index.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Staff Table -->
<div class="card border-0 shadow-sm rounded-lg">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="subAdminsTable">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="pl-4">Staff Member</th>
                        <th>Sub-Role</th>
                        <th>Contact</th>
                        <th>Monthly Salary</th>
                        <th><?= $monthNames[$currMonth] ?> <?= $currYear ?> Salary Status</th>
                        <th>Status</th>
                        <th>Permissions</th>
                        <th class="text-right pr-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sub_admins)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-user-shield fa-3x text-muted mb-3 d-block opacity-50"></i>
                            <h5 class="font-weight-bold">No Sub-Admin Staff Found</h5>
                            <p class="small mb-3">You have not created any Teacher, Accountant, or Staff sub-admin accounts yet.</p>
                            <button type="button" class="btn btn-primary btn-sm rounded-pill px-3 font-weight-bold" onclick="openAddSubAdminModal()">
                                <i class="fas fa-plus mr-1"></i> Add First Sub-Admin
                            </button>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($sub_admins as $sa): 
                        $feats = !empty($sa['features']) ? json_decode($sa['features'], true) : [];
                        $isPaidThisMonth = !empty($sa['current_pay_id']);
                        $subRoleClass = 'role-custom';
                        $subRoleIcon = 'fa-user-tag';
                        if ($sa['sub_role'] === 'teacher') {
                            $subRoleClass = 'role-teacher';
                            $subRoleIcon = 'fa-chalkboard-teacher';
                        } elseif ($sa['sub_role'] === 'accountant') {
                            $subRoleClass = 'role-accountant';
                            $subRoleIcon = 'fa-calculator';
                        }
                    ?>
                    <tr id="row-subadmin-<?= $sa['id'] ?>">
                        <td class="pl-4">
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mr-3 font-weight-bold text-primary shadow-sm" style="width: 42px; height: 42px; font-size: 1.1rem;">
                                    <?= strtoupper(substr($sa['full_name'] ?: $sa['username'], 0, 1)) ?>
                                </div>
                                <div>
                                    <strong class="text-dark d-block"><?= h($sa['full_name'] ?: $sa['username']) ?></strong>
                                    <small class="text-muted"><i class="fas fa-at mr-1"></i><?= h($sa['username']) ?></small>
                                    <?php if ($is_super): ?>
                                        <div class="small text-primary"><i class="fas fa-university mr-1"></i><?= h($sa['parent_institute']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="subrole-badge <?= $subRoleClass ?>">
                                <i class="fas <?= $subRoleIcon ?> mr-1"></i> <?= ucfirst(h($sa['sub_role'])) ?>
                            </span>
                            <?php if ($sa['sub_role'] === 'teacher'): ?>
                                <div class="small text-muted mt-1"><i class="fas fa-layer-group mr-1"></i><?= (int)$sa['assigned_batches'] ?> Batches</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><i class="fas fa-envelope text-muted mr-1 small"></i> <a href="mailto:<?= h($sa['email']) ?>" class="text-dark"><?= h($sa['email'] ?: 'No email') ?></a></div>
                            <?php if (!empty($sa['phone'])): ?>
                                <div class="small text-muted"><i class="fas fa-phone text-muted mr-1 small"></i><?= h($sa['phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong class="text-dark">₹<?= number_format($sa['salary'], 2) ?></strong>
                            <div class="small text-muted">/ month</div>
                        </td>
                        <td>
                            <?php if ($isPaidThisMonth): ?>
                                <span class="badge badge-success font-weight-bold p-2">
                                    <i class="fas fa-check-circle mr-1"></i> PAID (₹<?= number_format($sa['current_pay_amount'], 0) ?>)
                                </span>
                                <div class="small text-muted mt-1">
                                    <a href="<?php echo BASE_URL; ?>/admin/payroll/payslip.php?id=<?= $sa['current_pay_id'] ?>" target="_blank" class="text-primary font-weight-bold">
                                        <i class="fas fa-file-invoice mr-1"></i>Slip #<?= h($sa['current_pay_receipt']) ?>
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="badge badge-danger font-weight-bold p-2">
                                    <i class="fas fa-clock mr-1"></i> UNPAID
                                </span>
                                <?php if (can_process_payroll()): ?>
                                    <div class="mt-1">
                                        <button type="button" class="btn btn-sm btn-outline-success font-weight-bold py-0 px-2 rounded-pill" onclick='openPaySalaryModal(<?= json_encode($sa) ?>)'>
                                            <i class="fas fa-hand-holding-usd mr-1"></i>Pay Now
                                        </button>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sa['is_active']): ?>
                                <span class="badge badge-pill badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Active</span>
                            <?php else: ?>
                                <span class="badge badge-pill badge-danger px-2 py-1"><i class="fas fa-ban mr-1"></i> Suspended</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="max-width: 200px;">
                                <?php if (empty($feats)): ?>
                                    <span class="text-muted small">No special features</span>
                                <?php else: ?>
                                    <?php foreach (array_slice($feats, 0, 3) as $f): ?>
                                        <span class="feature-pill"><?= h(ALL_ADMIN_FEATURES[$f]['label'] ?? $f) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($feats) > 3): ?>
                                        <span class="feature-pill text-muted font-weight-bold">+<?= count($feats) - 3 ?> more</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-right pr-4">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border shadow-sm rounded-pill px-3 font-weight-bold dropdown-toggle" type="button" data-toggle="dropdown">
                                    Manage
                                </button>
                                <div class="dropdown-menu dropdown-menu-right shadow-sm border-0">
                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick='openEditSubAdminModal(<?= json_encode($sa) ?>)'>
                                        <i class="fas fa-edit mr-2 text-primary"></i>Edit Profile & Permissions
                                    </a>
                                    <?php if (can_process_payroll()): ?>
                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick='openPaySalaryModal(<?= json_encode($sa) ?>)'>
                                        <i class="fas fa-money-check-alt mr-2 text-success"></i>Disburse Salary
                                    </a>
                                    <?php endif; ?>
                                    <a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/admin/payroll/staff_ledger.php?sub_admin_id=<?= $sa['id'] ?>">
                                        <i class="fas fa-history mr-2 text-info"></i>Salary History & Slips
                                    </a>
                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="resendCredentials(<?= $sa['id'] ?>, '<?= h($sa['username']) ?>', '<?= h($sa['email']) ?>')">
                                        <i class="fas fa-key mr-2 text-warning"></i>Reset & Email Credentials
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item py-2" href="javascript:void(0)" onclick="toggleSubAdminStatus(<?= $sa['id'] ?>, <?= $sa['is_active'] ? 0 : 1 ?>)">
                                        <i class="fas <?= $sa['is_active'] ? 'fa-user-slash text-warning' : 'fa-user-check text-success' ?> mr-2"></i>
                                        <?= $sa['is_active'] ? 'Suspend Account' : 'Activate Account' ?>
                                    </a>
                                    <a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="confirmDeleteSubAdmin(<?= $sa['id'] ?>, '<?= h($sa['username']) ?>')">
                                        <i class="fas fa-trash-alt mr-2"></i>Delete Account
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: ADD SUB-ADMIN                       -->
<!-- ========================================== -->
<div class="modal fade" id="addSubAdminModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-user-plus mr-2 text-primary"></i>Add Sub-Admin / Staff Member
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="addSubAdminForm" onsubmit="submitAddSubAdmin(event)">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="modal-body p-4">
                    <div id="addModalAlert"></div>

                    <?php if ($is_super && !empty($all_parents)): ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Assign Under Institute / Parent Admin</label>
                        <select name="parent_admin_id" class="form-control" required>
                            <?php foreach ($all_parents as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= h($p['institute_name']) ?> (@<?= h($p['username']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Full Name</label>
                            <input type="text" name="full_name" class="form-control" placeholder="e.g. Dr. Rajesh Sharma" required>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Username (Login ID)</label>
                            <input type="text" name="username" class="form-control" placeholder="e.g. rajesh.teacher" required>
                            <small class="form-text text-muted">Unique login identifier. Alphanumeric, dots, hyphens.</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. rajesh@example.com" required>
                            <small class="form-text text-muted">Login credentials and temporary password will be sent here.</small>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. +91 98765 43210">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Sub-Role Classification</label>
                            <select name="sub_role" id="addSubRoleSelect" class="form-control font-weight-bold" onchange="onSubRoleChange(this.value, 'add')">
                                <option value="teacher">Teacher (Batch & Curriculum Management)</option>
                                <option value="accountant">Accountant (Fee Collection & Payroll)</option>
                                <option value="custom">Staff / Custom (Custom Feature Set)</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Monthly Salary (₹)</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text font-weight-bold">₹</span></div>
                                <input type="number" step="0.01" name="salary" class="form-control font-weight-bold" placeholder="25000.00" value="25000.00" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Initial Password</label>
                        <div class="input-group">
                            <input type="text" name="password" id="addPasswordInput" class="form-control font-family-monospace" required minlength="6">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" onclick="generateRandomPassword('addPasswordInput')">
                                    <i class="fas fa-dice mr-1"></i> Generate
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="custom-control custom-checkbox mb-4">
                        <input type="checkbox" class="custom-control-input" id="addSendEmailCheck" name="send_email" value="1" checked>
                        <label class="custom-control-label font-weight-bold text-primary" for="addSendEmailCheck">
                            <i class="fas fa-paper-plane mr-1"></i> Send login credentials & setup link to staff email immediately
                        </label>
                    </div>

                    <label class="font-weight-bold small text-muted text-uppercase mb-2">Delegated Feature Permissions</label>
                    <div class="row" id="addFeaturesContainer">
                        <?php foreach (ALL_ADMIN_FEATURES as $k => $info): ?>
                        <div class="col-md-6 col-lg-4 mb-2">
                            <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                <input type="checkbox" class="custom-control-input add-feature-cb" id="add_feat_<?= $k ?>" name="features[]" value="<?= $k ?>">
                                <label class="custom-control-label small font-weight-bold text-dark" for="add_feat_<?= $k ?>">
                                    <i class="<?= $info['icon'] ?> text-muted mr-1"></i> <?= h($info['label']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-4" id="submitAddBtn">
                        <i class="fas fa-check-circle mr-1"></i> Create Sub-Admin Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: EDIT SUB-ADMIN                      -->
<!-- ========================================== -->
<div class="modal fade" id="editSubAdminModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-edit mr-2 text-warning"></i>Edit Staff / Sub-Admin Account
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="editSubAdminForm" onsubmit="submitEditSubAdmin(event)">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="sub_admin_id" id="editSubAdminId">
                <div class="modal-body p-4">
                    <div id="editModalAlert"></div>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Full Name</label>
                            <input type="text" name="full_name" id="editFullName" class="form-control" required>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Username</label>
                            <input type="text" id="editUsername" class="form-control bg-light" readonly>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Email Address</label>
                            <input type="email" name="email" id="editEmail" class="form-control" required>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Phone Number</label>
                            <input type="text" name="phone" id="editPhone" class="form-control">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Sub-Role</label>
                            <select name="sub_role" id="editSubRole" class="form-control font-weight-bold">
                                <option value="teacher">Teacher</option>
                                <option value="accountant">Accountant</option>
                                <option value="custom">Staff / Custom</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Monthly Salary (₹)</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text font-weight-bold">₹</span></div>
                                <input type="number" step="0.01" name="salary" id="editSalary" class="form-control font-weight-bold" required>
                            </div>
                        </div>
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Account Status</label>
                            <select name="is_active" id="editIsActive" class="form-control font-weight-bold">
                                <option value="1">Active</option>
                                <option value="0">Suspended</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-3 p-3 bg-light rounded border">
                        <label class="font-weight-bold small text-muted text-uppercase d-flex justify-content-between align-items-center mb-1">
                            <span><i class="fas fa-key text-warning mr-1"></i> Override Password (Optional)</span>
                            <span class="badge badge-secondary font-weight-normal">No staff permission needed</span>
                        </label>
                        <div class="input-group">
                            <input type="text" name="password" id="editSubAdminPassword" class="form-control font-family-monospace" placeholder="Leave blank to retain current password" minlength="6">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" onclick="generateRandomPassword('editSubAdminPassword')">
                                    <i class="fas fa-random mr-1"></i> Generate
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted">You have direct managerial authority. Enter a new password here to update it immediately without sending emails or requesting permissions.</small>
                    </div>

                    <label class="font-weight-bold small text-muted text-uppercase mb-2">Delegated Feature Permissions</label>
                    <div class="row">
                        <?php foreach (ALL_ADMIN_FEATURES as $k => $info): ?>
                        <div class="col-md-6 col-lg-4 mb-2">
                            <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                <input type="checkbox" class="custom-control-input edit-feature-cb" id="edit_feat_<?= $k ?>" name="features[]" value="<?= $k ?>">
                                <label class="custom-control-label small font-weight-bold text-dark" for="edit_feat_<?= $k ?>">
                                    <i class="<?= $info['icon'] ?> text-muted mr-1"></i> <?= h($info['label']) ?>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning font-weight-bold rounded-pill px-4 text-dark" id="submitEditBtn">
                        <i class="fas fa-save mr-1"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: PAY SALARY                          -->
<!-- ========================================== -->
<div class="modal fade" id="paySalaryModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-success text-white border-0 py-3">
                <h5 class="modal-title font-weight-bold">
                    <i class="fas fa-money-check-alt mr-2"></i>Disburse Staff Salary
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form id="paySalaryForm" onsubmit="submitPaySalary(event)">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="sub_admin_id" id="paySubAdminId">
                <div class="modal-body p-4">
                    <div id="payModalAlert"></div>

                    <div class="p-3 bg-light rounded-lg mb-3 d-flex align-items-center justify-content-between">
                        <div>
                            <strong class="d-block text-dark" id="payStaffName">Staff Name</strong>
                            <span class="small text-muted" id="payStaffRole">Role</span>
                        </div>
                        <div class="text-right">
                            <span class="badge badge-info font-weight-bold px-2 py-1" id="payMonthBadge"><?= $fullMonthNames[$currMonth] ?> <?= $currYear ?></span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Salary Month</label>
                            <select name="salary_month" id="paySalaryMonth" class="form-control font-weight-bold">
                                <?php foreach ($fullMonthNames as $mNum => $mName): ?>
                                    <option value="<?= $mNum ?>" <?= $mNum === $currMonth ? 'selected' : '' ?>><?= $mName ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Salary Year</label>
                            <select name="salary_year" id="paySalaryYear" class="form-control font-weight-bold">
                                <?php for ($y = $currYear - 1; $y <= $currYear + 1; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y === $currYear ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-4 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Base (₹)</label>
                            <input type="number" step="0.01" name="base_salary" id="payBaseSalary" class="form-control font-weight-bold" oninput="recalcNetPay()" required>
                        </div>
                        <div class="form-group col-4 mb-3">
                            <label class="font-weight-bold small text-success text-uppercase">+ Bonus (₹)</label>
                            <input type="number" step="0.01" name="bonus" id="payBonus" class="form-control font-weight-bold text-success" value="0.00" oninput="recalcNetPay()">
                        </div>
                        <div class="form-group col-4 mb-3">
                            <label class="font-weight-bold small text-danger text-uppercase">- Deductions (₹)</label>
                            <input type="number" step="0.01" name="deductions" id="payDeductions" class="form-control font-weight-bold text-danger" value="0.00" oninput="recalcNetPay()">
                        </div>
                    </div>

                    <div class="p-3 bg-light border border-success rounded-lg mb-3 d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold text-dark">Total Net Payable:</span>
                        <h4 class="font-weight-bold text-success mb-0" id="payNetDisplay">₹0.00</h4>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Payment Method</label>
                            <select name="payment_method" class="form-control font-weight-bold">
                                <option value="bank_transfer">Bank Transfer (NEFT/IMPS)</option>
                                <option value="upi">UPI / GPay / PhonePe</option>
                                <option value="cash">Cash in Hand</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="form-group col-6 mb-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Payment Date</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Transaction Reference / UTR / Cheque #</label>
                        <input type="text" name="reference_no" class="form-control" placeholder="e.g. UTR3849104928 or Cheque #10293">
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-muted text-uppercase">Notes / Voucher Remarks</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Optional comments on payslip..."></textarea>
                    </div>

                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="paySendEmailCheck" name="send_email" value="1" checked>
                        <label class="custom-control-label font-weight-bold text-primary" for="paySendEmailCheck">
                            <i class="fas fa-paper-plane mr-1"></i> Send Salary Payslip Email to staff immediately
                        </label>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3">
                    <button type="button" class="btn btn-secondary font-weight-bold rounded-pill px-4" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold rounded-pill px-4" id="submitPayBtn">
                        <i class="fas fa-check-circle mr-1"></i> Confirm & Disburse Salary
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function generateRandomPassword(targetId) {
    var chars = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%";
    var pass = "";
    for (var i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById(targetId).value = pass;
}

function onSubRoleChange(role, mode) {
    var prefix = (mode === 'add') ? 'add_feat_' : 'edit_feat_';
    var defaultFeatures = [];
    if (role === 'teacher') {
        defaultFeatures = ['batches', 'questions', 'exams', 'enrolments', 'reports', 'subjects'];
    } else if (role === 'accountant') {
        defaultFeatures = ['fees', 'payroll'];
    } else {
        defaultFeatures = ['batches', 'students'];
    }

    var checkboxes = document.querySelectorAll('.' + mode + '-feature-cb');
    checkboxes.forEach(function(cb) {
        cb.checked = defaultFeatures.indexOf(cb.value) !== -1;
    });
}

function openAddSubAdminModal() {
    document.getElementById('addSubAdminForm').reset();
    generateRandomPassword('addPasswordInput');
    onSubRoleChange('teacher', 'add');
    $('#addSubAdminModal').modal('show');
}

function submitAddSubAdmin(e) {
    e.preventDefault();
    var form = document.getElementById('addSubAdminForm');
    var btn = document.getElementById('submitAddBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Creating...';

    var formData = new FormData(form);

    fetch('create_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Create Sub-Admin Account';
        if (data.success) {
            $('#addSubAdminModal').modal('hide');
            location.reload();
        } else {
            document.getElementById('addModalAlert').innerHTML = '<div class="alert alert-danger shadow-sm">' + data.error + '</div>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Create Sub-Admin Account';
        document.getElementById('addModalAlert').innerHTML = '<div class="alert alert-danger shadow-sm">Server error: ' + err + '</div>';
    });
}

function openEditSubAdminModal(sa) {
    document.getElementById('editSubAdminId').value = sa.id;
    document.getElementById('editFullName').value = sa.full_name || '';
    document.getElementById('editUsername').value = sa.username;
    document.getElementById('editEmail').value = sa.email || '';
    document.getElementById('editPhone').value = sa.phone || '';
    document.getElementById('editSubRole').value = sa.sub_role || 'custom';
    document.getElementById('editSalary').value = sa.salary || '0.00';
    document.getElementById('editIsActive').value = sa.is_active;
    if (document.getElementById('editSubAdminPassword')) {
        document.getElementById('editSubAdminPassword').value = '';
    }
    if (document.getElementById('editModalAlert')) {
        document.getElementById('editModalAlert').innerHTML = '';
    }

    var feats = [];
    try { feats = JSON.parse(sa.features || '[]'); } catch(e) {}
    document.querySelectorAll('.edit-feature-cb').forEach(function(cb) {
        cb.checked = (feats.indexOf(cb.value) !== -1);
    });

    $('#editSubAdminModal').modal('show');
}

function submitEditSubAdmin(e) {
    e.preventDefault();
    var form = document.getElementById('editSubAdminForm');
    var btn = document.getElementById('submitEditBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';

    var formData = new FormData(form);

    fetch('update_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save Changes';
        if (data.success) {
            $('#editSubAdminModal').modal('hide');
            location.reload();
        } else {
            document.getElementById('editModalAlert').innerHTML = '<div class="alert alert-danger shadow-sm">' + data.error + '</div>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1"></i> Save Changes';
        document.getElementById('editModalAlert').innerHTML = '<div class="alert alert-danger shadow-sm">Server error: ' + err + '</div>';
    });
}

function toggleSubAdminStatus(id, newStatus) {
    var promptMsg = newStatus ? "Activate this sub-admin account?" : "Suspend this sub-admin account? They will not be able to log in.";
    if (!confirm(promptMsg)) return;

    var fd = new FormData();
    fd.append('csrf_token', '<?= $csrf_token ?>');
    fd.append('sub_admin_id', id);
    fd.append('status', newStatus);

    fetch('status_ajax.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error);
        }
    });
}

function confirmDeleteSubAdmin(id, username) {
    if (!confirm("Are you sure you want to permanently delete sub-admin account '" + username + "'? This action cannot be undone.")) return;

    var fd = new FormData();
    fd.append('csrf_token', '<?= $csrf_token ?>');
    fd.append('sub_admin_id', id);

    fetch('delete_ajax.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.error);
        }
    });
}

function resendCredentials(id, username, email) {
    if (!confirm("Reset temporary password and email new login credentials to '" + email + "'?")) return;

    var fd = new FormData();
    fd.append('csrf_token', '<?= $csrf_token ?>');
    fd.append('sub_admin_id', id);

    fetch('resend_credentials_ajax.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
        } else {
            alert("Error: " + data.error);
        }
    });
}

function openPaySalaryModal(sa) {
    document.getElementById('paySubAdminId').value = sa.id;
    document.getElementById('payStaffName').textContent = sa.full_name || sa.username;
    document.getElementById('payStaffRole').textContent = (sa.sub_role ? sa.sub_role.toUpperCase() : 'STAFF') + ' (Salary: ₹' + Number(sa.salary).toLocaleString() + '/mo)';
    document.getElementById('payBaseSalary').value = sa.salary || 0;
    document.getElementById('payBonus').value = '0.00';
    document.getElementById('payDeductions').value = '0.00';
    recalcNetPay();
    $('#paySalaryModal').modal('show');
}

function recalcNetPay() {
    var base = parseFloat(document.getElementById('payBaseSalary').value) || 0;
    var bonus = parseFloat(document.getElementById('payBonus').value) || 0;
    var ded = parseFloat(document.getElementById('payDeductions').value) || 0;
    var net = Math.max(0, base + bonus - ded);
    document.getElementById('payNetDisplay').textContent = '₹' + net.toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function submitPaySalary(e) {
    e.preventDefault();
    var form = document.getElementById('paySalaryForm');
    var btn = document.getElementById('submitPayBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Processing...';

    var formData = new FormData(form);

    fetch('<?= BASE_URL ?>/admin/payroll/pay_salary_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Confirm & Disburse Salary';
        if (data.success) {
            $('#paySalaryModal').modal('hide');
            alert(data.message);
            location.reload();
        } else {
            document.getElementById('payModalAlert').innerHTML = '<div class="alert alert-danger shadow-sm">' + data.error + '</div>';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Confirm & Disburse Salary';
        document.getElementById('payModalAlert').innerHTML = '<div class="alert alert-danger shadow-sm">Server error: ' + err + '</div>';
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

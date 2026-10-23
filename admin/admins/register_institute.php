<?php
// admin/admins/register_institute.php
// Dedicated Full Onboarding & Registration Form for New Partner Institutes
require_once __DIR__ . '/../../includes/admin_header.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

require_super_admin();

$csrf_token = generate_csrf_token();
$allFeatures = defined('ALL_ADMIN_FEATURES') ? ALL_ADMIN_FEATURES : [];
$billingSettings = get_admin_billing_settings($pdo);
$currency = $billingSettings['fee_currency_symbol'] ?? '₹';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $institute_name  = trim($_POST['institute_name'] ?? '');
    $institute_code  = strtoupper(trim($_POST['institute_code'] ?? ''));
    $contact_person  = trim($_POST['contact_person'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $city            = trim($_POST['city'] ?? '');
    $state           = trim($_POST['state'] ?? '');
    $pincode         = trim($_POST['pincode'] ?? '');
    $website         = trim($_POST['website'] ?? '');
    $username        = trim($_POST['username'] ?? '');
    $password        = $_POST['password'] ?? '';
    $must_change_pw  = isset($_POST['must_change_password']) ? 1 : 0;
    $features_input  = $_POST['features'] ?? [];

    // Billing config
    $billing_model     = trim($_POST['billing_model'] ?? 'standard');
    $base_monthly_fee  = (float)($_POST['base_monthly_fee'] ?? $billingSettings['admin_default_monthly_fee']);
    $per_student_fee   = (float)($_POST['per_student_fee'] ?? 0.00);
    $feature_prices_in = $_POST['feature_prices'] ?? [];
    $discount_amount   = (float)($_POST['discount_amount'] ?? 0.00);
    $billing_due_day   = min(28, max(1, (int)($_POST['billing_due_day'] ?? $billingSettings['admin_payment_due_day'])));
    $billing_notes     = trim($_POST['billing_notes'] ?? '');

    if (empty($institute_name)) {
        $error = 'Institute Name is required.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Administrator username and initial password are required.';
    } elseif (strlen($username) < 3 || strlen($username) > 50 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        $error = 'Username must be 3-50 characters containing only letters, numbers, dots, and underscores.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Uniqueness checks
        $stmtChk = $pdo->prepare("SELECT id FROM admins WHERE username = ?");
        $stmtChk->execute([$username]);
        if ($stmtChk->fetch()) {
            $error = "An administrator with username '{$username}' already exists. Please choose a different username.";
        }

        if (empty($error) && !empty($email)) {
            $stmtMail = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
            $stmtMail->execute([$email]);
            if ($stmtMail->fetch()) {
                $error = "An administrator account with email '{$email}' already exists.";
            }
        }

        if (empty($error)) {
            try {
                // Filter features
                $valid_keys = array_keys($allFeatures);
                $selected_features = [];
                if (is_array($features_input)) {
                    foreach ($features_input as $f) {
                        if (in_array($f, $valid_keys, true)) {
                            $selected_features[] = $f;
                        }
                    }
                }
                $features_json = json_encode(array_values(array_unique($selected_features)));

                $pwHash = password_hash($password, PASSWORD_DEFAULT);

                $stmtIns = $pdo->prepare("
                    INSERT INTO admins (
                        username, email, password_hash, role, institute_name, institute_code, contact_person,
                        phone, address, city, state, pincode, website, is_active, features,
                        must_change_password, created_by, created_at
                    ) VALUES (
                        ?, ?, ?, 'admin', ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, 1, ?,
                        ?, ?, NOW()
                    )
                ");
                $stmtIns->execute([
                    $username,
                    !empty($email) ? $email : null,
                    $pwHash,
                    $institute_name,
                    !empty($institute_code) ? $institute_code : null,
                    !empty($contact_person) ? $contact_person : null,
                    !empty($phone) ? $phone : null,
                    !empty($address) ? $address : null,
                    !empty($city) ? $city : null,
                    !empty($state) ? $state : null,
                    !empty($pincode) ? $pincode : null,
                    !empty($website) ? $website : null,
                    $features_json,
                    $must_change_pw,
                    (int)$_SESSION['admin_id']
                ]);

                $newAdminId = (int)$pdo->lastInsertId();

                // Clean feature prices
                $clean_feature_prices = [];
                if (is_array($feature_prices_in)) {
                    foreach ($feature_prices_in as $k => $val) {
                        $p = (float)$val;
                        if ($p > 0) {
                            $clean_feature_prices[$k] = $p;
                        }
                    }
                }

                // Save custom billing profile
                save_admin_billing_profile($newAdminId, [
                    'billing_model'    => $billing_model,
                    'base_monthly_fee' => $base_monthly_fee,
                    'per_student_fee'  => $per_student_fee,
                    'feature_prices'   => $clean_feature_prices,
                    'discount_amount'  => $discount_amount,
                    'billing_due_day'  => $billing_due_day,
                    'notes'            => $billing_notes
                ], $pdo);

                // Auto-create initial billing invoice for current month
                $currentMonth = (int)date('n');
                $currentYear  = (int)date('Y');
                get_or_create_admin_billing($newAdminId, $currentMonth, $currentYear, $pdo);

                $_SESSION['flash_success'] = "Institute '{$institute_name}' onboarded successfully! Administrator account '{$username}' is ready for login.";
                header("Location: index.php#admin-row-" . $newAdminId);
                exit;

            } catch (\PDOException $e) {
                $error = "Database error during onboarding: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb bg-transparent p-0 mb-1 small">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/admin/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/admin/admins/index.php">Administrators</a></li>
                <li class="breadcrumb-item active text-primary font-weight-bold" aria-current="page">Onboard Institute</li>
            </ol>
        </nav>
        <h2 class="font-weight-bold text-dark mb-0">Onboard Partner Institute</h2>
        <span class="text-muted small">Register a new institution, provision admin credentials, gate modular features & set customized billing.</span>
    </div>
    <div class="mt-3 mt-md-0">
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4 shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Back to Administrators
        </a>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm rounded-lg p-3 mb-4">
        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo h($error); ?>
    </div>
<?php endif; ?>

<form method="POST" id="onboardInstituteForm" class="needs-validation" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">

    <div class="row">
        <!-- Main Form Column -->
        <div class="col-lg-8">
            
            <!-- Section 1: Institution Details -->
            <div class="card shadow-sm border-0 rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-university text-primary mr-2"></i> 1. Institution Details
                    </h5>
                    <small class="text-muted">General campus information and public identification.</small>
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Institute Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="institute_name" id="institute_name" class="form-control" required placeholder="e.g. Apex Academy of Science & Commerce" value="<?php echo h($_POST['institute_name'] ?? ''); ?>">
                            <div class="invalid-feedback">Please enter the official institute name.</div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Institute Code / Abbrev.</label>
                            <input type="text" name="institute_code" id="institute_code" class="form-control" placeholder="e.g. APEX, SNCC" value="<?php echo h($_POST['institute_code'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Campus Address</label>
                            <input type="text" name="address" class="form-control" placeholder="Building, Street, Landmark" value="<?php echo h($_POST['address'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">City</label>
                            <input type="text" name="city" class="form-control" placeholder="e.g. Kolkata, Mumbai" value="<?php echo h($_POST['city'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">State / Province</label>
                            <input type="text" name="state" class="form-control" placeholder="e.g. West Bengal, Maharashtra" value="<?php echo h($_POST['state'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Pincode</label>
                            <input type="text" name="pincode" class="form-control" placeholder="6-digit ZIP / PIN" value="<?php echo h($_POST['pincode'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <label class="font-weight-bold text-dark small text-uppercase">Official Website (Optional)</label>
                            <input type="url" name="website" class="form-control" placeholder="https://www.example.edu.in" value="<?php echo h($_POST['website'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Administrator & Primary Contact -->
            <div class="card shadow-sm border-0 rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-user-shield text-primary mr-2"></i> 2. Administrator & Login Credentials
                    </h5>
                    <small class="text-muted">Primary administrator credentials for this institute.</small>
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Principal / Contact Person Full Name</label>
                            <input type="text" name="contact_person" class="form-control" placeholder="e.g. Dr. Rajesh Sharma" value="<?php echo h($_POST['contact_person'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Official Contact Phone</label>
                            <input type="tel" name="phone" class="form-control" placeholder="e.g. +91 9876543210" value="<?php echo h($_POST['phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Official Administrator Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required placeholder="admin@example.edu.in" value="<?php echo h($_POST['email'] ?? ''); ?>">
                            <small class="form-text text-muted">Used for login recovery, system security alerts & monthly payment notices.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Admin Portal Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control font-weight-bold" required placeholder="e.g. apex_admin" value="<?php echo h($_POST['username'] ?? ''); ?>">
                            <small class="form-text text-muted">Lowercase letters, numbers, dots & underscores only.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Initial Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="password" id="adminPasswordInput" class="form-control font-weight-bold font-monospace" required minlength="6" placeholder="Strong password" value="<?php echo h($_POST['password'] ?? ''); ?>">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary" onclick="generateStrongPassword()" title="Generate Random Password">
                                        <i class="fas fa-magic"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" name="must_change_password" class="custom-control-input" id="must_change_pw" value="1" checked>
                        <label class="custom-control-label font-weight-bold text-dark small" for="must_change_pw">
                            Require administrator to change password on first login
                        </label>
                    </div>
                </div>
            </div>

            <!-- Section 3: Modular Gated Features -->
            <div class="card shadow-sm border-0 rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h5 class="font-weight-bold text-dark mb-0">
                            <i class="fas fa-sliders-h text-primary mr-2"></i> 3. Modular Feature Permissions
                        </h5>
                        <small class="text-muted">Enable or disable specific platform modules for this institute.</small>
                    </div>
                    <div class="btn-group btn-group-sm mt-2 mt-md-0">
                        <button type="button" class="btn btn-outline-primary" onclick="setPreset('all')">Enable All</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="setPreset('teaching')">Teaching Focus</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="setPreset('stem')">STEM & Coding</button>
                        <button type="button" class="btn btn-outline-danger" onclick="setPreset('none')">Clear</button>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row">
                        <?php foreach ($allFeatures as $fKey => $fMeta): 
                            $isCoding = ($fKey === 'coding');
                        ?>
                        <div class="col-md-6 mb-3">
                            <div class="p-3 border rounded-lg h-100 <?php echo $isCoding ? 'border-primary bg-primary bg-opacity-10' : 'bg-light'; ?>">
                                <div class="custom-control custom-checkbox d-flex align-items-start">
                                    <input type="checkbox" name="features[]" value="<?php echo $fKey; ?>" 
                                           class="custom-control-input feature-checkbox" 
                                           id="feat_<?php echo $fKey; ?>" 
                                           checked 
                                           onchange="recalculateLiveSummary()">
                                    <label class="custom-control-label font-weight-bold text-dark small flex-grow-1" for="feat_<?php echo $fKey; ?>">
                                        <i class="<?php echo $fMeta['icon']; ?> text-primary mr-1"></i> <?php echo h($fMeta['label']); ?>
                                        <?php if ($isCoding): ?>
                                            <span class="badge badge-primary ml-1">Gated Feature</span>
                                        <?php endif; ?>
                                        <div class="text-muted font-weight-normal mt-1" style="font-size: 0.75rem;"><?php echo h($fMeta['desc']); ?></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Section 4: Custom Pricing & Billing -->
            <div class="card shadow-sm border-0 rounded-lg mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h5 class="font-weight-bold text-dark mb-0">
                        <i class="fas fa-file-invoice-dollar text-primary mr-2"></i> 4. Custom Pricing & Billing Configuration
                    </h5>
                    <small class="text-muted">Define the subscription model, per-feature add-on rates, and per-student fees for this institute.</small>
                </div>
                <div class="card-body p-4">
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark small text-uppercase">Billing Model</label>
                        <select name="billing_model" id="billing_model" class="form-control custom-select" onchange="onBillingModelChange()">
                            <option value="standard">Standard Global Flat Subscription (<?php echo h($currency); ?>1,500/mo)</option>
                            <option value="custom_fixed">Custom Negotiated Fixed Monthly Fee</option>
                            <option value="per_feature">Per-Feature Based Pricing (Billed solely for enabled modules)</option>
                            <option value="hybrid" selected>Hybrid (Base Subscription + Per-Feature Add-ons + Per-Student Tier)</option>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3" id="baseFeeGroup">
                            <label class="font-weight-bold text-dark small text-uppercase">Base Monthly Fee (<?php echo h($currency); ?>)</label>
                            <input type="number" step="0.01" min="0" name="base_monthly_fee" id="base_monthly_fee" class="form-control" value="1500.00" oninput="recalculateLiveSummary()">
                        </div>
                        <div class="col-md-4 mb-3" id="perStudentGroup">
                            <label class="font-weight-bold text-dark small text-uppercase">Per Active Student Fee (<?php echo h($currency); ?>)</label>
                            <input type="number" step="0.01" min="0" name="per_student_fee" id="per_student_fee" class="form-control" value="0.00" placeholder="e.g. 10.00" oninput="recalculateLiveSummary()">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Monthly Due Day</label>
                            <input type="number" min="1" max="28" name="billing_due_day" id="billing_due_day" class="form-control" value="10">
                            <small class="text-muted">Day of month (1-28).</small>
                        </div>
                    </div>

                    <div id="featurePricingSection" class="mt-3 p-3 bg-light rounded border">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="font-weight-bold text-dark small text-uppercase">
                                <i class="fas fa-tags text-primary mr-1"></i> Per-Feature Monthly Add-On Prices
                            </span>
                            <small class="text-muted">Set price additions for specific high-value modules.</small>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text font-weight-bold bg-white"><i class="fas fa-laptop-code text-primary mr-1"></i> Coding Arena & Contests</span>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="feature_prices[coding]" class="form-control text-right feature-price-input" value="500.00" placeholder="0.00" oninput="recalculateLiveSummary()">
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text font-weight-bold bg-white"><i class="fas fa-brain text-primary mr-1"></i> OpenRouter AI Generation</span>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="feature_prices[ai_generation]" class="form-control text-right feature-price-input" value="300.00" placeholder="0.00" oninput="recalculateLiveSummary()">
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text font-weight-bold bg-white"><i class="fas fa-book-reader text-warning mr-1"></i> Homework Module</span>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="feature_prices[homework]" class="form-control text-right feature-price-input" value="200.00" placeholder="0.00" oninput="recalculateLiveSummary()">
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="input-group input-group-sm">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text font-weight-bold bg-white"><i class="fas fa-envelope text-info mr-1"></i> Direct Messaging</span>
                                    </div>
                                    <input type="number" step="0.01" min="0" name="feature_prices[messages]" class="form-control text-right feature-price-input" value="100.00" placeholder="0.00" oninput="recalculateLiveSummary()">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-3">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Monthly Concession / Discount (<?php echo h($currency); ?>)</label>
                            <input type="number" step="0.01" min="0" name="discount_amount" id="discount_amount" class="form-control" value="0.00" oninput="recalculateLiveSummary()">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold text-dark small text-uppercase">Billing Notes / Terms</label>
                            <input type="text" name="billing_notes" class="form-control" placeholder="e.g. Introductory 20% discount applied for first quarter" value="<?php echo h($_POST['billing_notes'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Sidebar Summary Column -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 rounded-lg sticky-top" style="top: 85px;">
                <div class="card-header bg-dark text-white py-3">
                    <h5 class="font-weight-bold mb-0"><i class="fas fa-clipboard-check mr-2"></i> Onboarding Preview</h5>
                </div>
                <div class="card-body p-4">
                    <div class="text-center pb-3 border-bottom mb-3">
                        <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center font-weight-bold mb-2 shadow-sm" style="width: 58px; height: 58px; font-size: 24px;" id="previewAvatar">
                            I
                        </div>
                        <h5 class="font-weight-bold text-dark mb-0" id="previewInstName">Partner Institute</h5>
                        <div class="text-muted small" id="previewCode">Code: <span>Not Set</span></div>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Selected Modules:</span>
                            <strong class="text-dark" id="previewFeatCount"><?php echo count($allFeatures); ?> of <?php echo count($allFeatures); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Coding Arena:</span>
                            <strong class="text-success" id="previewCoding">Enabled</strong>
                        </div>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Billing Model:</span>
                            <strong class="text-dark" id="previewModel">Hybrid Plan</strong>
                        </div>
                    </div>

                    <!-- Live Pricing Card -->
                    <div class="p-3 bg-light rounded border text-center mb-4">
                        <span class="small font-weight-bold text-muted text-uppercase d-block">Estimated Monthly Subscription</span>
                        <h2 class="font-weight-bold text-primary mb-0 mt-1" id="previewPrice"><?php echo h($currency); ?>2,600.00</h2>
                        <small class="text-muted" id="previewBreakdown">Base: <?php echo h($currency); ?>1,500 + Add-ons: <?php echo h($currency); ?>1,100</small>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block font-weight-bold rounded-pill shadow-sm py-3" id="submitOnboardBtn">
                        <i class="fas fa-check-circle mr-2"></i> Complete Onboarding
                    </button>
                    <a href="index.php" class="btn btn-link btn-block text-muted font-weight-bold small mt-2">
                        Cancel & Return
                    </a>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function generateStrongPassword() {
    const chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    let pw = '';
    for (let i = 0; i < 12; i++) {
        pw += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('adminPasswordInput').value = pw;
}

function setPreset(type) {
    const checkboxes = document.querySelectorAll('.feature-checkbox');
    const teachingList = ['questions', 'exams', 'students', 'enrolments', 'messages', 'reports'];
    const stemList = ['questions', 'exams', 'students', 'coding', 'homework', 'reports'];

    checkboxes.forEach(cb => {
        if (type === 'all') {
            cb.checked = true;
        } else if (type === 'none') {
            cb.checked = false;
        } else if (type === 'teaching') {
            cb.checked = teachingList.includes(cb.value);
        } else if (type === 'stem') {
            cb.checked = stemList.includes(cb.value);
        }
    });
    recalculateLiveSummary();
}

function onBillingModelChange() {
    const model = document.getElementById('billing_model').value;
    const baseGroup = document.getElementById('baseFeeGroup');
    const studentGroup = document.getElementById('perStudentGroup');
    const featSection = document.getElementById('featurePricingSection');

    if (model === 'standard') {
        baseGroup.style.display = 'block';
        document.getElementById('base_monthly_fee').value = '1500.00';
        studentGroup.style.display = 'none';
        featSection.style.display = 'none';
    } else if (model === 'custom_fixed') {
        baseGroup.style.display = 'block';
        studentGroup.style.display = 'none';
        featSection.style.display = 'none';
    } else if (model === 'per_feature') {
        baseGroup.style.display = 'none';
        studentGroup.style.display = 'none';
        featSection.style.display = 'block';
    } else { // hybrid
        baseGroup.style.display = 'block';
        studentGroup.style.display = 'block';
        featSection.style.display = 'block';
    }
    recalculateLiveSummary();
}

function recalculateLiveSummary() {
    const name = document.getElementById('institute_name').value.trim();
    const code = document.getElementById('institute_code').value.trim();
    document.getElementById('previewInstName').textContent = name || 'Partner Institute';
    document.getElementById('previewAvatar').textContent = name ? name.charAt(0).toUpperCase() : 'I';
    document.getElementById('previewCode').querySelector('span').textContent = code || 'Not Set';

    const checkedFeatures = document.querySelectorAll('.feature-checkbox:checked');
    document.getElementById('previewFeatCount').textContent = checkedFeatures.length + ' of <?php echo count($allFeatures); ?>';

    const codingCb = document.getElementById('feat_coding');
    const codingActive = codingCb && codingCb.checked;
    const previewCoding = document.getElementById('previewCoding');
    if (codingActive) {
        previewCoding.textContent = 'Enabled';
        previewCoding.className = 'text-success';
    } else {
        previewCoding.textContent = 'Disabled';
        previewCoding.className = 'text-danger';
    }

    const model = document.getElementById('billing_model').value;
    let base = parseFloat(document.getElementById('base_monthly_fee').value) || 0;
    let discount = parseFloat(document.getElementById('discount_amount').value) || 0;

    let featureTotal = 0;
    if (model === 'per_feature' || model === 'hybrid') {
        document.querySelectorAll('.feature-price-input').forEach(inp => {
            const featName = inp.name.match(/\[(.*?)\]/)[1];
            const featCb = document.getElementById('feat_' + featName);
            if (featCb && featCb.checked) {
                featureTotal += (parseFloat(inp.value) || 0);
            }
        });
    }

    let total = 0;
    let breakdownText = '';
    const curr = '<?php echo $currency; ?>';

    if (model === 'standard') {
        total = base;
        breakdownText = 'Standard Flat Fee';
        document.getElementById('previewModel').textContent = 'Standard Plan';
    } else if (model === 'custom_fixed') {
        total = Math.max(0, base - discount);
        breakdownText = 'Fixed: ' + curr + base.toFixed(2);
        document.getElementById('previewModel').textContent = 'Custom Fixed Plan';
    } else if (model === 'per_feature') {
        total = Math.max(0, featureTotal - discount);
        breakdownText = 'Feature Add-ons: ' + curr + featureTotal.toFixed(2);
        document.getElementById('previewModel').textContent = 'Per-Feature Plan';
    } else { // hybrid
        total = Math.max(0, base + featureTotal - discount);
        breakdownText = 'Base: ' + curr + base.toFixed(2) + ' + Add-ons: ' + curr + featureTotal.toFixed(2);
        document.getElementById('previewModel').textContent = 'Hybrid Plan';
    }

    document.getElementById('previewPrice').textContent = curr + total.toFixed(2);
    document.getElementById('previewBreakdown').textContent = breakdownText;
}

document.getElementById('institute_name').addEventListener('input', recalculateLiveSummary);
document.getElementById('institute_code').addEventListener('input', recalculateLiveSummary);

// Form validation
(function() {
  'use strict';
  window.addEventListener('load', function() {
    onBillingModelChange();
    var form = document.getElementById('onboardInstituteForm');
    form.addEventListener('submit', function(event) {
      if (form.checkValidity() === false) {
        event.preventDefault();
        event.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  }, false);
})();
</script>

<?php require_once __DIR__ . '/../../includes/admin_footer.php'; ?>

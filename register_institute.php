<?php
// register_institute.php
// Public-Facing Institute Registration & Onboarding Application Form
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

send_security_headers();
start_secure_session('PUBLIC_SESSION');

$csrf_token = generate_csrf_token();
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $ip = get_client_ip();
    if (check_ip_rate_limit($ip, 'institute_registration', 5, 1800, $pdo)) {
        $error = "Too many registration attempts from this network. Please wait 30 minutes before submitting again.";
    } else {
        $institute_name = trim($_POST['institute_name'] ?? '');
        $institute_code = strtoupper(trim($_POST['institute_code'] ?? ''));
        $contact_person = trim($_POST['contact_person'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $phone          = trim($_POST['phone'] ?? '');
        $address        = trim($_POST['address'] ?? '');
        $city           = trim($_POST['city'] ?? '');
        $state          = trim($_POST['state'] ?? '');
        $pincode        = trim($_POST['pincode'] ?? '');
        $website        = trim($_POST['website'] ?? '');
        $notes          = trim($_POST['notes'] ?? '');
        $features_req   = $_POST['requested_features'] ?? [];

        if (empty($institute_name) || empty($contact_person) || empty($email) || empty($phone)) {
            $error = "Please fill in all required fields (Institute Name, Contact Person, Email & Phone).";
            record_ip_failed_attempt($ip, 'institute_registration', 5, 1800, $pdo);
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid official email address.";
            record_ip_failed_attempt($ip, 'institute_registration', 5, 1800, $pdo);
        } else {
            try {
                $reqJson = json_encode(is_array($features_req) ? array_values($features_req) : []);
                $stmt = $pdo->prepare("
                    INSERT INTO institute_registrations (
                        institute_name, institute_code, contact_person, email, phone,
                        address, city, state, pincode, website, requested_features, notes, status, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, ?, ?, 'pending', NOW()
                    )
                ");
                $stmt->execute([
                    $institute_name,
                    !empty($institute_code) ? $institute_code : null,
                    $contact_person,
                    $email,
                    $phone,
                    !empty($address) ? $address : null,
                    !empty($city) ? $city : null,
                    !empty($state) ? $state : null,
                    !empty($pincode) ? $pincode : null,
                    !empty($website) ? $website : null,
                    $reqJson,
                    !empty($notes) ? $notes : null
                ]);

                reset_ip_rate_limit($ip, 'institute_registration', $pdo);
                $success = true;
            } catch (\PDOException $e) {
                $error = "Unable to process application. Please contact administration directly.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Institute Onboarding & Registration - Online Examination Platform</title>
    <!-- PWA Progressive Web App Manifest & Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" href="/assets/icons/favicon.png">
    <link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
    <meta name="theme-color" content="#1e3a8a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="EduERP">
    <script src="/assets/js/pwa.js" defer></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="assets/css/custom.css?v=<?php echo time(); ?>">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #334155 100%);
            min-height: 100vh;
            color: #1e293b;
        }
        .registration-card {
            border-radius: 18px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
        }
    </style>
</head>
<body class="py-5">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card registration-card border-0 overflow-hidden bg-white">
                <div class="card-header text-white text-center py-5 border-0" style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important;">
                    <i class="fas fa-university fa-3x mb-3 text-warning"></i>
                    <h2 class="font-weight-bold text-white mb-1">Partner Institute Registration</h2>
                    <p class="mb-0 text-white-50">Onboard your school, academy, or coaching institute to the examination & coding platform.</p>
                </div>

                <div class="card-body p-4 p-md-5">
                    <?php if ($success): ?>
                        <div class="text-center py-5">
                            <div class="text-success mb-3">
                                <i class="fas fa-check-circle fa-5x"></i>
                            </div>
                            <h3 class="font-weight-bold text-dark mb-2">Onboarding Application Submitted!</h3>
                            <p class="text-muted mb-4 lead">
                                Thank you for registering <strong><?php echo h($institute_name); ?></strong>. Our Super Administrator will review your campus profile, configure your requested modules, and dispatch your primary login credentials via email to <strong><?php echo h($email); ?></strong>.
                            </p>
                            <a href="admin/login.php" class="btn btn-primary btn-lg rounded-pill px-5 font-weight-bold shadow-sm">
                                <i class="fas fa-arrow-right mr-2"></i>Go to Admin Login
                            </a>
                        </div>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger shadow-sm rounded-lg py-2 px-3 mb-4">
                                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo h($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="needs-validation" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">

                            <h5 class="font-weight-bold text-dark mb-3 border-bottom pb-2">
                                <i class="fas fa-school text-primary mr-2"></i> 1. Campus Information
                            </h5>

                            <div class="row">
                                <div class="col-md-8 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Institute / Academy Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="institute_name" class="form-control" required placeholder="e.g. Apex High School & Junior College" value="<?php echo h($_POST['institute_name'] ?? ''); ?>">
                                    <div class="invalid-feedback">Institute name is required.</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Institute Abbreviation</label>
                                    <input type="text" name="institute_code" class="form-control" placeholder="e.g. APEX" value="<?php echo h($_POST['institute_code'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Campus Address</label>
                                    <input type="text" name="address" class="form-control" placeholder="Building, Street, Area" value="<?php echo h($_POST['address'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">City</label>
                                    <input type="text" name="city" class="form-control" placeholder="City name" value="<?php echo h($_POST['city'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">State</label>
                                    <input type="text" name="state" class="form-control" placeholder="State / Region" value="<?php echo h($_POST['state'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Pincode</label>
                                    <input type="text" name="pincode" class="form-control" placeholder="PIN code" value="<?php echo h($_POST['pincode'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <label class="font-weight-bold text-dark small text-uppercase">Official Website (Optional)</label>
                                    <input type="url" name="website" class="form-control" placeholder="https://www.example.edu.in" value="<?php echo h($_POST['website'] ?? ''); ?>">
                                </div>
                            </div>

                            <h5 class="font-weight-bold text-dark mb-3 border-bottom pb-2">
                                <i class="fas fa-user-tie text-primary mr-2"></i> 2. Principal / Administrator Contact
                            </h5>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Primary Contact Person <span class="text-danger">*</span></label>
                                    <input type="text" name="contact_person" class="form-control" required placeholder="Full Name & Designation" value="<?php echo h($_POST['contact_person'] ?? ''); ?>">
                                    <div class="invalid-feedback">Contact person name is required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="font-weight-bold text-dark small text-uppercase">Contact Mobile / Phone <span class="text-danger">*</span></label>
                                    <input type="tel" name="phone" class="form-control" required placeholder="+91 9876543210" value="<?php echo h($_POST['phone'] ?? ''); ?>">
                                    <div class="invalid-feedback">Contact phone is required.</div>
                                </div>
                            </div>

                            <div class="row mb-4">
                                <div class="col-md-12">
                                    <label class="font-weight-bold text-dark small text-uppercase">Official Institute Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" required placeholder="contact@example.edu.in" value="<?php echo h($_POST['email'] ?? ''); ?>">
                                    <div class="invalid-feedback">Valid email address is required.</div>
                                    <small class="form-text text-muted">Admin credentials and onboarding statements will be delivered to this email.</small>
                                </div>
                            </div>

                            <h5 class="font-weight-bold text-dark mb-3 border-bottom pb-2">
                                <i class="fas fa-boxes text-primary mr-2"></i> 3. Desired Platform Modules
                            </h5>

                            <div class="row mb-4">
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                        <input type="checkbox" name="requested_features[]" value="exams" class="custom-control-input" id="rf_exams" checked>
                                        <label class="custom-control-label font-weight-bold small text-dark" for="rf_exams">
                                            <i class="fas fa-list-alt text-primary mr-1"></i> Online Examination Engine & Auto-Grading
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                        <input type="checkbox" name="requested_features[]" value="coding" class="custom-control-input" id="rf_coding" checked>
                                        <label class="custom-control-label font-weight-bold small text-dark" for="rf_coding">
                                            <i class="fas fa-laptop-code text-primary mr-1"></i> Coding Arena & Live Competitions
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                        <input type="checkbox" name="requested_features[]" value="ai_generation" class="custom-control-input" id="rf_ai">
                                        <label class="custom-control-label font-weight-bold small text-dark" for="rf_ai">
                                            <i class="fas fa-brain text-primary mr-1"></i> OpenRouter AI Question Generation
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                        <input type="checkbox" name="requested_features[]" value="fees" class="custom-control-input" id="rf_fees" checked>
                                        <label class="custom-control-label font-weight-bold small text-dark" for="rf_fees">
                                            <i class="fas fa-hand-holding-usd text-primary mr-1"></i> Student Fees & Payment Receipts
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                        <input type="checkbox" name="requested_features[]" value="homework" class="custom-control-input" id="rf_hw" checked>
                                        <label class="custom-control-label font-weight-bold small text-dark" for="rf_hw">
                                            <i class="fas fa-book-reader text-primary mr-1"></i> Homework & Assignments Portal
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <div class="custom-control custom-checkbox p-2 border rounded bg-light">
                                        <input type="checkbox" name="requested_features[]" value="messages" class="custom-control-input" id="rf_msg" checked>
                                        <label class="custom-control-label font-weight-bold small text-dark" for="rf_msg">
                                            <i class="fas fa-envelope text-primary mr-1"></i> Direct Student Messaging
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-4">
                                <label class="font-weight-bold text-dark small text-uppercase">Additional Requirements / Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Approximate number of students, specific academic boards, custom domain preferences, etc."><?php echo h($_POST['notes'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg btn-block font-weight-bold shadow-sm rounded-pill py-3">
                                <i class="fas fa-paper-plane mr-2"></i>Submit Onboarding Application
                            </button>

                            <div class="text-center mt-3">
                                <a href="admin/login.php" class="text-muted small font-weight-bold">
                                    <i class="fas fa-arrow-left mr-1"></i> Already registered? Sign in to Admin Portal
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
  'use strict';
  window.addEventListener('load', function() {
    var forms = document.getElementsByClassName('needs-validation');
    Array.prototype.filter.call(forms, function(form) {
      form.addEventListener('submit', function(event) {
        if (form.checkValidity() === false) {
          event.preventDefault();
          event.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    });
  }, false);
})();
</script>
</body>
</html>

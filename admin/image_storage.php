<?php
// admin/image_storage.php
// Admin configuration for Local Compressed Image Storage & Cloudinary CDN integration.

require_once '../includes/admin_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/image_storage.php';

require_admin_feature('image_storage');

// Handle AJAX actions BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_test_cloudinary') {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Please reload the page.']);
        exit;
    }

    $cName   = trim($_POST['cloudinary_name'] ?? '');
    $cKey    = trim($_POST['cloudinary_key'] ?? '');
    $cSecret = trim($_POST['cloudinary_secret'] ?? '');
    $cFolder = trim($_POST['cloudinary_folder'] ?? 'questions');

    $res = test_cloudinary_connection($cName, $cKey, $cSecret, $cFolder);
    echo json_encode($res);
    exit;
}

require_once '../includes/admin_header.php';

$error = '';
$success = '';

$current_admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();

$fix_admin_id = ($is_super && !empty($_REQUEST['fix_admin_id'])) ? (int)$_REQUEST['fix_admin_id'] : 0;
$targetAdmin = null;
$support_mode = false;
$support_blocked = false;

if ($fix_admin_id > 0) {
    $stmtTarget = $pdo->prepare("SELECT id, username, email, institute_name, role, support_permission FROM admins WHERE id = ?");
    $stmtTarget->execute([$fix_admin_id]);
    $targetAdmin = $stmtTarget->fetch(PDO::FETCH_ASSOC);

    if ($targetAdmin && $targetAdmin['role'] !== 'super_admin') {
        if (!empty($targetAdmin['support_permission'])) {
            $support_mode = true;
            $effective_admin_id = $fix_admin_id;
        } else {
            $support_blocked = true;
            $effective_admin_id = $fix_admin_id;
            $error = "Individual Ownership Policy Active: Administrator '{$targetAdmin['username']}' has not granted support permission from their Account Settings. You cannot view or modify their private Cloudinary configuration.";
        }
    } else {
        $fix_admin_id = 0;
        $effective_admin_id = $current_admin_id;
    }
} else {
    $effective_admin_id = $current_admin_id;
}

$config = get_image_storage_config($pdo, $effective_admin_id);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        if ($support_blocked) {
            $error = "Cannot save settings: Administrator has not granted support permission.";
        } else {
            $driver            = in_array($_POST['driver'] ?? '', ['local', 'cloudinary']) ? $_POST['driver'] : 'local';
            $quality           = max(10, min(100, (int)($_POST['quality'] ?? 75)));
            $maxWidth          = max(0, min(4000, (int)($_POST['max_width'] ?? 1200)));
            $cloudinaryName   = trim($_POST['cloudinary_name'] ?? '');
            $cloudinaryKey    = trim($_POST['cloudinary_key'] ?? '');
            $cloudinarySecret = trim($_POST['cloudinary_secret'] ?? '');
            $cloudinaryFolder = trim($_POST['cloudinary_folder'] ?? 'questions');

            // If driver is cloudinary, check credentials
            if ($driver === 'cloudinary' && (empty($cloudinaryName) || empty($cloudinaryKey) || empty($cloudinarySecret))) {
                $error = "Please provide Cloud Name, API Key, and API Secret to activate Cloudinary CDN.";
            } else {
                save_image_storage_config($pdo, [
                    'driver'            => $driver,
                    'quality'           => $quality,
                    'max_width'         => $maxWidth,
                    'cloudinary_name'   => $cloudinaryName,
                    'cloudinary_key'    => $cloudinaryKey,
                    'cloudinary_secret' => $cloudinarySecret,
                    'cloudinary_folder' => $cloudinaryFolder
                ], $effective_admin_id);
                $config = get_image_storage_config($pdo, $effective_admin_id);
                $success = $support_mode 
                    ? "Cloudinary settings for administrator '" . h($targetAdmin['username']) . "' updated successfully!" 
                    : "Image storage settings updated successfully!";
            }
        }
    } elseif ($action === 'migrate_db_images') {
        $migrationResult = migrate_db_questions_images($pdo);
        if ($migrationResult['migrated'] > 0) {
            $savedKB = round($migrationResult['bytes_saved'] / 1024, 1);
            $success = "Successfully migrated {$migrationResult['migrated']} question(s)! Saved approximately {$savedKB} KB of database storage space.";
        } else {
            $success = "No questions with legacy base64 images found. Database is already fully optimized!";
        }
        if (!empty($migrationResult['errors'])) {
            $error = "Some questions encountered errors: " . implode(', ', $migrationResult['errors']);
        }
    }
}

$admin_list = [];
if ($is_super) {
    $admin_list = $pdo->query("SELECT id, username, institute_name, role FROM admins ORDER BY (role = 'super_admin') DESC, username ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Diagnostics & Statistics - strictly partitioned per admin
$scopedAdminId = $current_admin_id;
if ($support_mode && $fix_admin_id) {
    $scopedAdminId = $fix_admin_id;
} elseif ($is_super && isset($_GET['inspect_admin']) && $_GET['inspect_admin'] !== '') {
    $scopedAdminId = (int)$_GET['inspect_admin'];
}

$adminFolder = 'admin_' . $scopedAdminId;
$uploadBaseDir = __DIR__ . '/../uploads';
$uploadDir = get_admin_question_upload_dir($scopedAdminId);

// Perform active write check
$isWritable = false;
$testFile = $uploadDir . '/.perm_check_' . uniqid();
if (@file_put_contents($testFile, '1') !== false) {
    $isWritable = true;
    @unlink($testFile);
} elseif (is_writable($uploadDir) || is_writable($uploadBaseDir)) {
    $isWritable = true;
}

// Build mapping of image filename -> questions (scoped per admin)
$questionsStmt = $pdo->prepare("
    SELECT q.id, q.question_text, q.marks, c.name as class_name, s.name as subject_name 
    FROM questions q
    LEFT JOIN classes c ON q.class_id = c.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    WHERE q.admin_id = ?
");
$questionsStmt->execute([$scopedAdminId]);
$allQuestions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

$imageToQuestions = [];
foreach ($allQuestions as $q) {
    if (empty($q['question_text'])) continue;
    
    if (preg_match_all('/(?:uploads\/questions\/(?:admin_\d+\/)?|["\'])(qimg_[a-zA-Z0-9_\.]+\.(?:webp|jpg|jpeg|png|gif))/i', $q['question_text'], $matches)) {
        foreach ($matches[1] as $fname) {
            $baseName = basename($fname);
            if (!isset($imageToQuestions[$baseName])) {
                $imageToQuestions[$baseName] = [];
            }
            $imageToQuestions[$baseName][] = [
                'id'           => $q['id'],
                'marks'        => $q['marks'],
                'class_name'   => $q['class_name'] ?? '',
                'subject_name' => $q['subject_name'] ?? '',
                'snippet'      => mb_substr(trim(strip_tags($q['question_text'])), 0, 80)
            ];
        }
    }
}

$storedImagesList = [];
$localImageCount = 0;
$localFolderSize = 0;
if (is_dir($uploadDir)) {
    $files = @scandir($uploadDir) ?: [];
    foreach ($files as $f) {
        if ($f !== '.' && $f !== '..' && $f !== '.gitkeep' && substr($f, 0, 1) !== '.') {
            $filePath = $uploadDir . '/' . $f;
            if (is_file($filePath)) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                $fsize = filesize($filePath);
                $mtime = filemtime($filePath);
                $localImageCount++;
                $localFolderSize += $fsize;
                
                $storedImagesList[] = [
                    'filename'   => $f,
                    'url'        => BASE_URL . '/uploads/questions/' . $adminFolder . '/' . $f,
                    'size_bytes' => $fsize,
                    'size_kb'    => round($fsize / 1024, 1),
                    'format'     => strtoupper($ext),
                    'date'       => date('M d, Y H:i', $mtime),
                    'timestamp'  => $mtime,
                    'questions'  => $imageToQuestions[$f] ?? []
                ];
            }
        }
    }
    usort($storedImagesList, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
}

// Database stats scoped strictly to the selected admin
$stmtLegacy = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE admin_id = ? AND question_text LIKE '%data:image/%'");
$stmtLegacy->execute([$scopedAdminId]);
$legacyBase64Count = (int)$stmtLegacy->fetchColumn();

$stmtCloud = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE admin_id = ? AND question_text LIKE '%res.cloudinary.com%'");
$stmtCloud->execute([$scopedAdminId]);
$cloudinaryCount = (int)$stmtCloud->fetchColumn();

$stmtLocal = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE admin_id = ? AND (question_text LIKE '%/uploads/questions/%' OR question_text LIKE '%uploads/questions/%')");
$stmtLocal->execute([$scopedAdminId]);
$localRefQuestionCount = (int)$stmtLocal->fetchColumn();

// GD Status
$hasGD = extension_loaded('gd');
$hasWebP = $hasGD && function_exists('imagewebp');
$hasJPEG = $hasGD && function_exists('imagejpeg');
$hasCurl = function_exists('curl_init');
?>

<style>
.local-storage-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 24px rgba(0,0,0,0.12) !important;
    border: 1px solid #0dcaf0 !important;
}
.image-grid-card .card {
    transition: transform 0.2s, box-shadow 0.2s;
}
.image-grid-card .card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.12) !important;
}
.thumb-checkerboard {
    background-color: #f8fafc;
    background-image: linear-gradient(45deg, #eef2f6 25%, transparent 25%), linear-gradient(-45deg, #eef2f6 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #eef2f6 75%), linear-gradient(-45deg, transparent 75%, #eef2f6 75%);
    background-size: 16px 16px;
    background-position: 0 0, 0 8px, 8px -8px, -8px 0px;
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark">Image Storage & Cloudinary Settings</h2>
        <p class="text-muted mb-0">Manage compressed local media storage and external Cloudinary CDN delivery.</p>
    </div>
    <div class="d-flex align-items-center" style="gap: 10px;">
        <?php if ($support_mode): ?>
            <span class="badge badge-success px-3 py-2 font-weight-bold shadow-xs">
                <i class="fas fa-tools mr-1"></i> Fixing: <?= h($targetAdmin['username']) ?>
            </span>
            <a href="image_storage.php" class="btn btn-outline-dark font-weight-bold shadow-sm rounded-pill px-3 btn-sm">
                <i class="fas fa-times mr-1"></i>Exit Support Mode
            </a>
        <?php elseif ($is_super && !$support_mode && !$support_blocked): ?>
            <form method="GET" class="d-inline-flex align-items-center m-0 mr-2">
                <label class="small text-muted font-weight-bold mr-2 mb-0 d-none d-md-inline"><i class="fas fa-folder-open text-primary mr-1"></i>Storage:</label>
                <select name="inspect_admin" class="custom-select custom-select-sm font-weight-bold border-primary shadow-xs" onchange="this.form.submit()">
                    <option value="1" <?= $scopedAdminId == 1 ? 'selected' : '' ?>>★ My Photos (Super Admin)</option>
                    <optgroup label="Tenant Admin Storages">
                        <?php foreach($admin_list as $adm): ?>
                            <?php if ($adm['id'] != 1): ?>
                                <option value="<?= $adm['id'] ?>" <?= $scopedAdminId == $adm['id'] ? 'selected' : '' ?>>
                                    <?= h($adm['username'] . ($adm['institute_name'] ? ' (' . $adm['institute_name'] . ')' : '')) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </form>
        <?php elseif (!$is_super): ?>
            <span class="badge badge-primary px-3 py-2 font-weight-bold shadow-xs">
                <i class="fas fa-user-shield mr-1"></i> Personal Institute CDN
            </span>
        <?php endif; ?>
        <a href="questions/index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-arrow-left mr-2"></i>Back to Question Bank
        </a>
    </div>
</div>

<?php if ($support_mode): ?>
    <div class="alert alert-success border-success shadow-sm rounded-lg mb-4 p-3">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <i class="fas fa-tools fa-lg mr-2 text-success"></i>
                <strong>Authorized Troubleshooting Mode:</strong> You are troubleshooting and configuring Cloudinary CDN on behalf of <strong><?= h($targetAdmin['username']) ?></strong> (<?= h($targetAdmin['institute_name']) ?>) under their active support permission.
            </div>
            <a href="image_storage.php" class="btn btn-sm btn-outline-dark rounded-pill font-weight-bold ml-3">
                <i class="fas fa-times mr-1"></i> Exit Support Mode
            </a>
        </div>
    </div>
<?php elseif ($support_blocked): ?>
    <div class="alert alert-warning border-warning shadow-sm rounded-lg mb-4 p-4">
        <div class="d-flex align-items-start justify-content-between flex-wrap" style="gap: 15px;">
            <div class="flex-grow-1">
                <h5 class="font-weight-bold text-dark mb-1">
                    <i class="fas fa-user-lock text-warning mr-2"></i>Individual Ownership Protected
                </h5>
                <p class="text-muted mb-3" style="max-width: 700px;">
                    Administrator <strong><?= h($targetAdmin['username']) ?></strong> (<?= h($targetAdmin['institute_name']) ?>) has kept Support Permission disabled in their Account Settings. 
                    To troubleshoot their private Cloudinary credentials on their behalf, request an authorization code below. Once entered, troubleshooting mode will be unlocked.
                </p>
                <div class="d-flex flex-wrap align-items-center" style="gap: 10px;">
                    <button type="button" class="btn btn-warning font-weight-bold rounded-pill px-4 shadow-xs" id="btnRequestOtp" onclick="requestSupportCode(<?= $targetAdmin['id'] ?>)">
                        <i class="fas fa-key mr-2"></i>Request Permission Code via Email
                    </button>
                    <a href="messages.php?channel=admin&target_admin_id=<?= $targetAdmin['id'] ?>" class="btn btn-outline-dark font-weight-bold rounded-pill px-3 shadow-xs">
                        <i class="fas fa-comments mr-1"></i> Chat with Admin
                    </a>
                    <a href="admins/index.php" class="btn btn-link text-muted font-weight-bold">
                        <i class="fas fa-arrow-left mr-1"></i> Back to Directory
                    </a>
                </div>

                <div id="supportOtpContainer" class="mt-3 p-3 bg-white border border-warning rounded-lg" style="display: none; max-width: 450px;">
                    <label class="font-weight-bold text-dark small text-uppercase mb-1">
                        <i class="fas fa-envelope-open-text text-warning mr-1"></i> Enter 6-Digit Email Code:
                    </label>
                    <div class="input-group">
                        <input type="text" id="supportOtpInput" maxlength="6" class="form-control font-weight-bold text-center" placeholder="e.g. 849201" style="letter-spacing: 4px; font-size: 1.15rem;" autocomplete="off">
                        <div class="input-group-append">
                            <button type="button" class="btn btn-success font-weight-bold px-3" id="btnVerifyOtp" onclick="verifySupportCode(<?= $targetAdmin['id'] ?>)">
                                <i class="fas fa-unlock mr-1"></i> Unlock & Fix
                            </button>
                        </div>
                    </div>
                    <div id="supportOtpFeedback" class="mt-2 small"></div>
                </div>
            </div>
        </div>
    </div>
<?php elseif (!$is_super): ?>
    <div class="alert alert-light border shadow-xs rounded-lg mb-4 py-2 px-3 small text-muted d-flex align-items-center">
        <i class="fas fa-shield-alt text-primary fa-lg mr-2"></i>
        <span><strong>Private Institute Media Configuration:</strong> You can configure your personal Cloudinary credentials below. Super-Administrator API keys are securely protected and isolated to maintain your complete ownership.</span>
    </div>
<?php endif; ?>

<?php if ($error && !$support_blocked): ?>
    <div class="alert alert-danger shadow-sm rounded border-0 border-left border-danger" style="border-width: 4px !important;">
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo h($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success shadow-sm rounded border-0 border-left border-success" style="border-width: 4px !important;">
        <i class="fas fa-check-circle mr-2"></i><?php echo h($success); ?>
    </div>
<?php endif; ?>

<!-- Diagnostic Cards Row -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 <?php echo $config['driver'] === 'cloudinary' ? 'bg-primary text-white' : 'bg-success text-white'; ?>">
                    <i class="fas <?php echo $config['driver'] === 'cloudinary' ? 'fa-cloud' : 'fa-hdd'; ?> fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Active Driver</div>
                    <h5 class="font-weight-bold mb-0 text-dark">
                        <?php echo $config['driver'] === 'cloudinary' ? 'Cloudinary CDN' : 'Local Compressed'; ?>
                    </h5>
                    <span class="badge badge-<?php echo $config['driver'] === 'cloudinary' ? 'primary' : 'success'; ?> mt-1">
                        <?php echo $config['driver'] === 'cloudinary' ? 'Cloud Hosted' : 'WebP Auto-Compress'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100 local-storage-card" style="cursor: pointer;" onclick="openImageGridModal()" title="Click to view all stored images and question numbers in a grid">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle bg-info text-white p-3 mr-3 shadow-sm">
                    <i class="fas fa-images fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Local Stored Images</div>
                    <h5 class="font-weight-bold mb-0 text-dark"><?php echo $localImageCount; ?> Files</h5>
                    <span class="badge badge-info mt-1 shadow-sm"><i class="fas fa-th-large mr-1"></i>Click to View Grid</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle <?php echo $hasWebP ? 'bg-success' : 'bg-warning'; ?> text-white p-3 mr-3">
                    <i class="fas fa-compress-arrows-alt fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">WebP Compression</div>
                    <h5 class="font-weight-bold mb-0 text-dark">
                        <?php echo $hasWebP ? 'Active (WebP)' : ($hasJPEG ? 'JPEG Only' : 'GD Missing'); ?>
                    </h5>
                    <span class="text-muted small">Quality: <?php echo $config['quality']; ?>% | Max: <?php echo $config['max_width']; ?>px</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle <?php echo $legacyBase64Count > 0 ? 'bg-danger' : 'bg-light text-success'; ?> p-3 mr-3">
                    <i class="fas fa-database fa-2x <?php echo $legacyBase64Count > 0 ? 'text-white' : 'text-success'; ?>"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Legacy DB Images</div>
                    <h5 class="font-weight-bold mb-0 <?php echo $legacyBase64Count > 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $legacyBase64Count; ?> In Database
                    </h5>
                    <span class="small <?php echo $legacyBase64Count > 0 ? 'text-danger font-weight-bold' : 'text-muted'; ?>">
                        <?php echo $legacyBase64Count > 0 ? 'Migration Recommended' : 'All Images Externalized'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Storage Settings Form -->
    <div class="col-lg-8 mb-4">
        <div class="card border-0 shadow-sm rounded-lg overflow-hidden">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-sliders-h mr-2 text-primary"></i>Storage Provider Settings
                </h5>
            </div>
            <div class="card-body px-4 pb-4">
                <form method="POST" action="image_storage.php" id="storageForm">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_settings">
                    <?php if ($fix_admin_id > 0): ?>
                        <input type="hidden" name="fix_admin_id" value="<?php echo (int)$fix_admin_id; ?>">
                    <?php endif; ?>

                    <!-- Provider Selection -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Default Image Storage Driver</label>
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <div class="custom-control custom-radio p-3 border rounded-lg <?php echo $config['driver'] === 'local' ? 'border-primary bg-light' : ''; ?>">
                                    <input type="radio" id="driver_local" name="driver" value="local" class="custom-control-input" <?php echo $config['driver'] === 'local' ? 'checked' : ''; ?> onchange="toggleDriverFields()" <?php echo $support_blocked ? 'disabled' : ''; ?>>
                                    <label class="custom-control-label font-weight-bold text-dark cursor-pointer" for="driver_local">
                                        <i class="fas fa-hdd mr-1 text-success"></i> Local Compressed Storage
                                    </label>
                                    <div class="text-muted small mt-1">
                                        Compresses images to modern WebP format and stores them in <code>uploads/questions/</code> on the server disk.
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <div class="custom-control custom-radio p-3 border rounded-lg <?php echo $config['driver'] === 'cloudinary' ? 'border-primary bg-light' : ''; ?>">
                                    <input type="radio" id="driver_cloudinary" name="driver" value="cloudinary" class="custom-control-input" <?php echo $config['driver'] === 'cloudinary' ? 'checked' : ''; ?> onchange="toggleDriverFields()" <?php echo $support_blocked ? 'disabled' : ''; ?>>
                                    <label class="custom-control-label font-weight-bold text-dark cursor-pointer" for="driver_cloudinary">
                                        <i class="fas fa-cloud mr-1 text-primary"></i> Cloudinary CDN
                                    </label>
                                    <div class="text-muted small mt-1">
                                        Uploads images to your Cloudinary cloud and delivers them globally via CDN with automatic responsiveness.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cloudinary Credentials Card -->
                    <div class="card border rounded-lg mb-4 <?php echo $config['driver'] === 'cloudinary' ? 'border-primary' : 'bg-light'; ?>" id="cloudinaryBox">
                        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                            <h6 class="font-weight-bold mb-0 text-dark">
                                <i class="fas fa-cloud-upload-alt mr-2 text-primary"></i>Cloudinary Account Credentials
                            </h6>
                            <?php if (!$support_blocked): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" id="btnTestCloudinary" onclick="testCloudinaryConnection()">
                                <i class="fas fa-plug mr-1"></i>Test Connection
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div id="testAlert" class="d-none alert py-2 px-3 small rounded mb-3"></div>

                            <?php if (!$is_super && !$support_mode): ?>
                                <div class="alert alert-light border small text-muted mb-3 py-2 px-3">
                                    <i class="fas fa-shield-alt text-primary mr-1"></i>
                                    <strong>Private Institute Credentials:</strong> Enter your personal Cloudinary credentials below. Super-Admin credentials are kept private and secure.
                                </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="small font-weight-bold text-muted">Cloud Name <span class="text-danger">*</span></label>
                                    <input type="text" name="cloudinary_name" id="cloudinary_name" class="form-control" placeholder="e.g. dxyz12345" value="<?php echo h($config['cloudinary_name']); ?>">
                                </div>
                                <div class="col-md-6 form-group">
                                    <label class="small font-weight-bold text-muted">API Key <span class="text-danger">*</span></label>
                                    <input type="text" name="cloudinary_key" id="cloudinary_key" class="form-control" placeholder="e.g. 123456789012345" value="<?php echo h($config['cloudinary_key']); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="small font-weight-bold text-muted">API Secret <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="password" name="cloudinary_secret" id="cloudinary_secret" class="form-control" placeholder="e.g. aBcDeFgHiJkLmNoPqRsTuVwXyZ" value="<?php echo h($config['cloudinary_secret']); ?>">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary" type="button" onclick="toggleSecretVisibility()">
                                                <i class="fas fa-eye" id="secretIcon"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label class="small font-weight-bold text-muted">Destination Folder</label>
                                    <input type="text" name="cloudinary_folder" id="cloudinary_folder" class="form-control" placeholder="questions" value="<?php echo h($config['cloudinary_folder']); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Compression Settings -->
                    <div class="card border rounded-lg mb-4">
                        <div class="card-header bg-transparent">
                            <h6 class="font-weight-bold mb-0 text-dark">
                                <i class="fas fa-sliders-h mr-2 text-muted"></i>Local Compression Parameters
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="small font-weight-bold text-muted">Compression Quality (10 - 100%)</label>
                                    <div class="d-flex align-items-center">
                                        <input type="range" class="custom-range flex-grow-1 mr-3" min="30" max="100" step="5" id="qualityRange" value="<?php echo $config['quality']; ?>" oninput="$('#qualityVal').text(this.value)">
                                        <span class="badge badge-primary px-3 py-2 font-weight-bold" style="font-size: 0.95rem;" id="qualityVal"><?php echo $config['quality']; ?></span>%
                                    </div>
                                    <input type="hidden" name="quality" id="qualityInput" value="<?php echo $config['quality']; ?>">
                                    <small class="text-muted">Recommended: 75% (produces 80-95% smaller files with uncompromised clarity for diagrams and mathematical equations).</small>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label class="small font-weight-bold text-muted">Max Width Boundary (Pixels)</label>
                                    <input type="number" name="max_width" class="form-control" value="<?php echo $config['max_width']; ?>" min="400" max="4000" step="100">
                                    <small class="text-muted">High-res photos or pastes wider than this width are automatically scaled down to save bandwidth.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow" <?php echo $support_blocked ? 'disabled' : ''; ?>>
                            <i class="fas fa-save mr-2"></i>Save Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Optimization & Migration Column -->
    <div class="col-lg-4">
        <!-- Database Migration Tool -->
        <div class="card border-0 shadow-sm rounded-lg mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-broom mr-2 text-warning"></i>Database Optimization
                </h5>
            </div>
            <div class="card-body px-4 pb-4">
                <p class="text-muted small">
                    When questions were created earlier, images were stored directly inside the database as inline base64 strings.
                </p>
                <div class="p-3 bg-light rounded mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small font-weight-bold text-dark">Uncompressed DB Images:</span>
                        <span class="badge <?php echo $legacyBase64Count > 0 ? 'badge-danger' : 'badge-success'; ?> px-2 py-1">
                            <?php echo $legacyBase64Count; ?> questions
                        </span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="small font-weight-bold text-dark">Local Image References:</span>
                        <span class="badge badge-info px-2 py-1"><?php echo $localRefQuestionCount; ?> questions</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="small font-weight-bold text-dark">Cloudinary References:</span>
                        <span class="badge badge-primary px-2 py-1"><?php echo $cloudinaryCount; ?> questions</span>
                    </div>
                </div>

                <form method="POST" action="image_storage.php" onsubmit="return confirm('Extract and compress all inline base64 images from the database now?');">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="migrate_db_images">
                    <button type="submit" class="btn btn-block <?php echo $legacyBase64Count > 0 ? 'btn-warning text-dark font-weight-bold' : 'btn-outline-secondary'; ?> rounded-pill py-2 shadow-sm" <?php echo $legacyBase64Count === 0 ? 'disabled' : ''; ?>>
                        <i class="fas fa-compress mr-2"></i>Migrate & Shrink Database
                    </button>
                </form>
            </div>
        </div>

        <!-- System Compatibility Checklist -->
        <div class="card border-0 shadow-sm rounded-lg">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2">
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-check-double mr-2 text-success"></i>System Capabilities
                </h6>
            </div>
            <div class="card-body px-4 pb-4">
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>PHP GD Library</span>
                        <?php if ($hasGD): ?>
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Loaded</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Missing</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>WebP Compression</span>
                        <?php if ($hasWebP): ?>
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Supported</span>
                        <?php else: ?>
                            <span class="badge badge-secondary">Not Supported</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>PHP cURL (Cloudinary)</span>
                        <?php if ($hasCurl): ?>
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Loaded</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Missing</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span>Writable Directory</span>
                        <?php if ($isWritable): ?>
                            <span class="badge badge-success"><i class="fas fa-check mr-1"></i>Writable</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-times mr-1"></i>Permission Denied</span>
                        <?php endif; ?>
                    </li>
                </ul>

                <?php if (!$hasGD || !$isWritable): ?>
                <div class="alert alert-info mt-3 p-3 small mb-0 rounded">
                    <strong><i class="fas fa-terminal mr-1"></i> Enable GD & Fix Permissions in Docker:</strong>
                    <div class="text-muted mt-1">Run this command in your PowerShell to install GD/WebP and unlock directory permissions:</div>
                    <code class="d-block mt-2 p-2 bg-dark text-white rounded text-break" style="font-size: 0.8rem; user-select: all;">
                        docker exec -u 0 shreyansir_app sh -c "apk add --no-cache php83-gd && chown -R apache:apache /var/www/html/uploads && chmod -R 777 /var/www/html/uploads" &amp;&amp; docker restart shreyansir_app
                    </code>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Local Stored Images Gallery Section (On-Page Grid) -->
<div class="card border-0 shadow-sm rounded-lg mb-4 mt-2" id="localGallerySection">
    <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h5 class="font-weight-bold text-dark mb-0">
                <i class="fas fa-th-large text-info mr-2"></i>Local Stored Images &amp; Question Mapping
            </h5>
            <p class="text-muted small mb-0">Browse all compressed images stored in <code>uploads/questions/</code> and their linked question numbers.</p>
        </div>
        <div class="d-flex align-items-center flex-wrap gap-2">
            <div class="input-group input-group-sm mr-2 shadow-sm" style="max-width: 300px;">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-light border-right-0"><i class="fas fa-search text-muted"></i></span>
                </div>
                <input type="text" id="pageFilterInput" class="form-control border-left-0" placeholder="Filter by Question # or filename..." onkeyup="filterPageImageGrid()">
            </div>
            <button type="button" class="btn btn-sm btn-outline-info font-weight-bold rounded-pill px-3 shadow-sm" onclick="openImageGridModal()">
                <i class="fas fa-expand mr-1"></i>Open Fullscreen Modal
            </button>
        </div>
    </div>
    
    <div class="card-body px-4 pb-4">
        <?php if (empty($storedImagesList)): ?>
            <div class="text-center py-5">
                <i class="fas fa-images fa-4x text-muted mb-3 opacity-50"></i>
                <h5 class="text-muted font-weight-bold">No Local Images Found</h5>
                <p class="text-muted small">Images uploaded or pasted in questions will be saved here in compressed format.</p>
            </div>
        <?php else: ?>
            <div class="row" id="pageImageGrid">
                <?php foreach ($storedImagesList as $img): 
                    $qIds = array_column($img['questions'], 'id');
                    $qNumbersStr = !empty($qIds) ? implode(', ', array_map(function($id) { return 'Q#' . $id; }, $qIds)) : 'Unassigned';
                    $filterKeyword = strtolower($img['filename'] . ' ' . $qNumbersStr . ' ' . implode(' ', $qIds));
                ?>
                <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 mb-4 page-image-card" data-keywords="<?php echo h($filterKeyword); ?>">
                    <div class="card h-100 border-0 shadow-sm rounded-lg overflow-hidden image-grid-item">
                        <!-- Question Badges Header -->
                        <div class="card-header bg-white border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                            <div class="text-truncate mr-2">
                                <?php if (!empty($img['questions'])): ?>
                                    <?php foreach ($img['questions'] as $q): ?>
                                        <a href="questions/index.php" target="_blank" class="badge badge-primary px-2 py-1 shadow-sm mr-1 font-weight-bold" title="Question #<?php echo $q['id']; ?>: <?php echo h($q['snippet']); ?>">
                                            <i class="fas fa-question-circle mr-1"></i>Question #<?php echo $q['id']; ?>
                                        </a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-unlink mr-1"></i>Unassigned</span>
                                <?php endif; ?>
                            </div>
                            <span class="badge badge-secondary px-2 py-1 small text-uppercase"><?php echo h($img['format']); ?></span>
                        </div>

                        <!-- Thumbnail Container with Checkered Background -->
                        <div class="thumb-checkerboard p-3 d-flex align-items-center justify-content-center position-relative" style="height: 190px;">
                            <img src="<?php echo h($img['url']); ?>" 
                                 alt="<?php echo h($img['filename']); ?>" 
                                 class="img-fluid rounded shadow-sm" 
                                 style="max-height: 170px; max-width: 100%; object-fit: contain; cursor: zoom-in;" 
                                 onclick="openZoomModal('<?php echo h($img['url']); ?>', '<?php echo h($qNumbersStr); ?>', '<?php echo h($img['filename']); ?>', '<?php echo $img['size_kb']; ?> KB')">
                        </div>

                        <!-- Card Body / Footer -->
                        <div class="card-body p-3 bg-white">
                            <div class="text-truncate font-weight-bold text-dark small mb-1" title="<?php echo h($img['filename']); ?>">
                                <?php echo h($img['filename']); ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center text-muted small mb-2">
                                <span><i class="fas fa-weight-hanging mr-1 text-primary"></i><?php echo $img['size_kb']; ?> KB</span>
                                <span><i class="fas fa-calendar-alt mr-1"></i><?php echo $img['date']; ?></span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" onclick="openZoomModal('<?php echo h($img['url']); ?>', '<?php echo h($qNumbersStr); ?>', '<?php echo h($img['filename']); ?>', '<?php echo $img['size_kb']; ?> KB')">
                                    <i class="fas fa-search-plus mr-1"></i>Zoom
                                </button>
                                <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" onclick="copyImageUrl('<?php echo h($img['url']); ?>', this)">
                                    <i class="fas fa-link mr-1"></i>Copy URL
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Local Storage Image Grid Modal -->
<div class="modal fade" id="localImagesModal" tabindex="-1" aria-labelledby="localImagesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-light border-0 pt-4 px-4 pb-3">
                <div>
                    <h5 class="modal-title font-weight-bold text-dark" id="localImagesModalLabel">
                        <i class="fas fa-th-large text-info mr-2"></i>Local Storage Images &amp; Question Numbers
                    </h5>
                    <p class="text-muted small mb-0">Browse all compressed images in a grid with corresponding question numbers.</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            
            <!-- Filter Toolbar -->
            <div class="px-4 py-3 bg-white border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center flex-grow-1 mr-3" style="max-width: 400px;">
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-light border-right-0"><i class="fas fa-search text-muted"></i></span>
                        </div>
                        <input type="text" id="modalFilterInput" class="form-control border-left-0" placeholder="Filter by Question # or filename..." onkeyup="filterModalImageGrid()">
                    </div>
                </div>
                <div>
                    <span class="badge badge-light border px-3 py-2 text-muted font-weight-bold" id="modalGridCounter">
                        Total: <?php echo count($storedImagesList); ?> Images
                    </span>
                </div>
            </div>

            <div class="modal-body p-4" style="background-color: #f8fafc;">
                <?php if (empty($storedImagesList)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-images fa-4x text-muted mb-3 opacity-50"></i>
                        <h5 class="text-muted font-weight-bold">No Local Images Found</h5>
                        <p class="text-muted small">Images uploaded or pasted in questions will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="row" id="modalImageGrid">
                        <?php foreach ($storedImagesList as $img): 
                            $qIds = array_column($img['questions'], 'id');
                            $qNumbersStr = !empty($qIds) ? implode(', ', array_map(function($id) { return 'Q#' . $id; }, $qIds)) : 'Unassigned';
                            $filterKeyword = strtolower($img['filename'] . ' ' . $qNumbersStr . ' ' . implode(' ', $qIds));
                        ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12 mb-4 modal-image-card" data-keywords="<?php echo h($filterKeyword); ?>">
                            <div class="card h-100 border-0 shadow-sm rounded-lg overflow-hidden image-grid-item">
                                <!-- Question Badges Header -->
                                <div class="card-header bg-white border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                                    <div class="text-truncate mr-2">
                                        <?php if (!empty($img['questions'])): ?>
                                            <?php foreach ($img['questions'] as $q): ?>
                                                <a href="questions/index.php" target="_blank" class="badge badge-primary px-2 py-1 shadow-sm mr-1 font-weight-bold" title="Question #<?php echo $q['id']; ?>: <?php echo h($q['snippet']); ?>">
                                                    <i class="fas fa-question-circle mr-1"></i>Question #<?php echo $q['id']; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted px-2 py-1"><i class="fas fa-unlink mr-1"></i>Unassigned</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="badge badge-secondary px-2 py-1 small text-uppercase"><?php echo h($img['format']); ?></span>
                                </div>

                                <!-- Thumbnail Container -->
                                <div class="thumb-checkerboard p-3 d-flex align-items-center justify-content-center position-relative" style="height: 190px;">
                                    <img src="<?php echo h($img['url']); ?>" 
                                         alt="<?php echo h($img['filename']); ?>" 
                                         class="img-fluid rounded shadow-sm" 
                                         style="max-height: 170px; max-width: 100%; object-fit: contain; cursor: zoom-in;" 
                                         onclick="openZoomModal('<?php echo h($img['url']); ?>', '<?php echo h($qNumbersStr); ?>', '<?php echo h($img['filename']); ?>', '<?php echo $img['size_kb']; ?> KB')">
                                </div>

                                <!-- Card Footer Details -->
                                <div class="card-body p-3 bg-white">
                                    <div class="text-truncate font-weight-bold text-dark small mb-1" title="<?php echo h($img['filename']); ?>">
                                        <?php echo h($img['filename']); ?>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center text-muted small mb-2">
                                        <span><i class="fas fa-weight-hanging mr-1 text-primary"></i><?php echo $img['size_kb']; ?> KB</span>
                                        <span><i class="fas fa-calendar-alt mr-1"></i><?php echo $img['date']; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                        <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" onclick="openZoomModal('<?php echo h($img['url']); ?>', '<?php echo h($qNumbersStr); ?>', '<?php echo h($img['filename']); ?>', '<?php echo $img['size_kb']; ?> KB')">
                                            <i class="fas fa-search-plus mr-1"></i>Zoom
                                        </button>
                                        <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" onclick="copyImageUrl('<?php echo h($img['url']); ?>', this)">
                                            <i class="fas fa-link mr-1"></i>Copy URL
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="modal-footer bg-light border-0 py-3 px-4">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Image Lightbox Zoom Modal -->
<div class="modal fade" id="imageZoomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-lg">
            <div class="modal-header bg-dark text-white border-0 py-3 px-4">
                <h6 class="modal-title font-weight-bold mb-0" id="zoomModalTitle">Image Preview</h6>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-4 text-center bg-light">
                <div class="p-3 bg-white rounded shadow-sm d-inline-block thumb-checkerboard" style="max-width: 100%;">
                    <img src="" id="zoomModalImage" class="img-fluid rounded" style="max-height: 70vh;">
                </div>
                <div class="mt-3 text-muted small" id="zoomModalMeta"></div>
            </div>
        </div>
    </div>
</div>

<script>
function openImageGridModal() {
    $('#localImagesModal').modal('show');
}

function filterPageImageGrid() {
    let query = $('#pageFilterInput').val().toLowerCase().trim();
    $('.page-image-card').each(function() {
        let keywords = ($(this).data('keywords') || '').toString();
        if (keywords.indexOf(query) !== -1) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
}

function filterModalImageGrid() {
    let query = $('#modalFilterInput').val().toLowerCase().trim();
    let visibleCount = 0;
    $('.modal-image-card').each(function() {
        let keywords = ($(this).data('keywords') || '').toString();
        if (keywords.indexOf(query) !== -1) {
            $(this).show();
            visibleCount++;
        } else {
            $(this).hide();
        }
    });
    $('#modalGridCounter').text('Showing ' + visibleCount + ' Images');
}

function openZoomModal(url, qTitle, filename, size) {
    $('#zoomModalImage').attr('src', url);
    $('#zoomModalTitle').html('<i class="fas fa-image mr-2 text-info"></i>' + qTitle + ' &mdash; ' + filename);
    $('#zoomModalMeta').html('<strong>Filename:</strong> ' + filename + ' | <strong>Size:</strong> ' + size + ' | <a href="' + url + '" target="_blank" class="text-primary font-weight-bold ml-1"><i class="fas fa-external-link-alt mr-1"></i>Open Full Size</a>');
    $('#imageZoomModal').modal('show');
}

function copyImageUrl(url, btn) {
    let fullUrl = window.location.origin + url;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(fullUrl).then(function() {
            let orig = $(btn).html();
            $(btn).html('<i class="fas fa-check text-success mr-1"></i>Copied!');
            setTimeout(() => $(btn).html(orig), 2000);
        }).catch(function() {
            prompt('Copy URL:', fullUrl);
        });
    } else {
        prompt('Copy URL:', fullUrl);
    }
}

function toggleDriverFields() {
    let isCloud = $('#driver_cloudinary').is(':checked');
    if (isCloud) {
        $('#cloudinaryBox').removeClass('bg-light').addClass('border-primary');
    } else {
        $('#cloudinaryBox').removeClass('border-primary').addClass('bg-light');
    }
}

function toggleSecretVisibility() {
    let input = $('#cloudinary_secret');
    let icon = $('#secretIcon');
    if (input.attr('type') === 'password') {
        input.attr('type', 'text');
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
    } else {
        input.attr('type', 'password');
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
    }
}

$('#qualityRange').on('input', function() {
    $('#qualityInput').val(this.value);
});

function testCloudinaryConnection() {
    let btn = $('#btnTestCloudinary');
    let origText = btn.html();
    let alertBox = $('#testAlert');

    let name = $('#cloudinary_name').val().trim();
    let key = $('#cloudinary_key').val().trim();
    let secret = $('#cloudinary_secret').val().trim();
    let folder = $('#cloudinary_folder').val().trim();

    if (!name || !key || !secret) {
        alertBox.removeClass('d-none alert-success').addClass('alert-danger').html('<i class="fas fa-exclamation-circle mr-1"></i> Please fill in Cloud Name, API Key, and API Secret first.');
        return;
    }

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Testing...');
    alertBox.addClass('d-none');

    $.ajax({
        url: 'image_storage.php',
        method: 'POST',
        data: {
            action: 'ajax_test_cloudinary',
            csrf_token: '<?php echo generate_csrf_token(); ?>',
            cloudinary_name: name,
            cloudinary_key: key,
            cloudinary_secret: secret,
            cloudinary_folder: folder
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html(origText);
            if (res.success) {
                alertBox.removeClass('d-none alert-danger').addClass('alert-success')
                    .html('<i class="fas fa-check-circle mr-1"></i> ' + res.message);
            } else {
                alertBox.removeClass('d-none alert-success').addClass('alert-danger')
                    .html('<i class="fas fa-times-circle mr-1"></i> ' + (res.message || 'Connection failed.'));
            }
        },
        error: function() {
            btn.prop('disabled', false).html(origText);
            alertBox.removeClass('d-none alert-success').addClass('alert-danger')
                .html('<i class="fas fa-exclamation-triangle mr-1"></i> Server communication error.');
        }
    });
}

function requestSupportCode(adminId) {
    const btn = $('#btnRequestOtp');
    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Sending Code...');
    $('#supportOtpContainer').slideDown(150);
    $('#supportOtpFeedback').html('<div class="text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> Generating code and sending email...</div>');

    $.ajax({
        url: 'admins/request_support_code_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            csrf_token: '<?php echo generate_csrf_token(); ?>'
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
            btn.prop('disabled', false).html('<i class="fas fa-key mr-1"></i> Request Permission Code via Email');
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
        url: 'admins/verify_support_code_ajax.php',
        type: 'POST',
        data: {
            admin_id: adminId,
            code: code,
            csrf_token: '<?php echo generate_csrf_token(); ?>'
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html('<i class="fas fa-unlock mr-1"></i> Unlock & Fix');
            if (res.success) {
                $('#supportOtpFeedback').html('<div class="text-success font-weight-bold"><i class="fas fa-check-circle mr-1"></i> Verified! Entering troubleshooting mode...</div>');
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            } else {
                $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> ' + (res.error || 'Verification failed.') + '</div>');
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html('<i class="fas fa-unlock mr-1"></i> Unlock & Fix');
            let err = 'Server error occurred while verifying code.';
            try {
                const j = JSON.parse(xhr.responseText);
                if (j.error) err = j.error;
            } catch(e) {}
            $('#supportOtpFeedback').html('<div class="text-danger font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i> ' + err + '</div>');
        }
    });
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>

<?php
// admin/ai_settings.php
// Configuration management for OpenRouter AI Question Generation.

require_once '../includes/admin_auth.php';
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/ai_generator.php';

require_admin_feature('ai_settings');

// Handle AJAX actions BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_test_connection') {
    header('Content-Type: application/json; charset=utf-8');

    // Verify CSRF
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'error' => 'CSRF verification failed. Please reload the page and try again.']);
        exit;
    }

    $testKey   = trim((string)($_POST['api_key'] ?? ''), " \t\n\r\0\x0B\"'");
    $testModel = trim($_POST['model'] ?? 'google/gemini-2.0-flash-001');

    $result = test_openrouter_connection($testKey, $testModel);
    echo json_encode($result);
    exit;
}

// Now include header for HTML page output
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
            $error = "Individual Ownership Policy Active: Administrator '{$targetAdmin['username']}' has not granted support permission from their Account Settings. You cannot view or modify their private OpenRouter configuration.";
        }
    } else {
        $fix_admin_id = 0;
        $effective_admin_id = $current_admin_id;
    }
} else {
    $effective_admin_id = $current_admin_id;
}

$config = get_openrouter_config($pdo, $effective_admin_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        if ($support_blocked) {
            $error = "Cannot save settings: Administrator has not granted support permission.";
        } else {
            $apiKey      = trim((string)($_POST['api_key'] ?? ''), " \t\n\r\0\x0B\"'");
            $model       = trim($_POST['model'] ?? 'google/gemini-2.0-flash-001');
            $temperature = max(0.0, min(1.0, (float)($_POST['temperature'] ?? 0.7)));

            save_openrouter_config($pdo, [
                'api_key'     => $apiKey,
                'model'       => $model,
                'temperature' => $temperature
            ], $effective_admin_id);

            $config = get_openrouter_config($pdo, $effective_admin_id);
            $success = $support_mode 
                ? "OpenRouter AI settings for administrator '" . h($targetAdmin['username']) . "' updated successfully!" 
                : "Your institute OpenRouter AI settings have been securely updated!";
        }
    }
}

$hasKey = !empty($config['api_key']);
?>

<nav aria-label="breadcrumb">
    <ol class="breadcrumb bg-transparent p-0 mb-1">
        <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
        <?php if ($support_mode): ?>
            <li class="breadcrumb-item"><a href="../superadmin/manage_admins.php">Admin Roster</a></li>
            <li class="breadcrumb-item active">Support: <?= h($targetAdmin['username']) ?></li>
        <?php else: ?>
            <li class="breadcrumb-item active">AI Settings</li>
        <?php endif; ?>
    </ol>
</nav>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark">
            <i class="fas fa-brain text-primary mr-2"></i>OpenRouter AI Configuration
            <?php if ($support_mode): ?>
                <span class="badge badge-warning text-dark ml-2 font-weight-bold" style="font-size: 0.9rem;">
                    <i class="fas fa-tools mr-1"></i>Support Mode: <?= h($targetAdmin['username']) ?>
                </span>
            <?php elseif (!$is_super): ?>
                <span class="badge badge-info ml-2 font-weight-bold" style="font-size: 0.9rem;">
                    <i class="fas fa-university mr-1"></i><?= h($_SESSION['institute_name'] ?? 'Your Institute') ?>
                </span>
            <?php endif; ?>
        </h2>
        <p class="text-muted mb-0">Manage OpenRouter API keys and models for on-demand AI question generation.</p>
    </div>
    <div>
        <a href="questions/create.php?ai=1" class="btn text-white font-weight-bold shadow-sm rounded-pill px-4" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);">
            <i class="fas fa-magic mr-2"></i>Generate Question Now
        </a>
    </div>
</div>

<?php if ($support_mode): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center justify-content-between" style="background-color: #fffbeb; border-left: 5px solid #f59e0b !important;">
        <div class="d-flex align-items-center">
            <i class="fas fa-tools fa-2x text-warning mr-3"></i>
            <div>
                <strong class="text-dark d-block">Super Administrator Support Mode Active</strong>
                <span class="small text-muted">
                    You are configuring OpenRouter AI settings on behalf of Administrator <strong><?= h($targetAdmin['username']) ?></strong> (<em><?= h($targetAdmin['institute_name']) ?></em>). This administrator has explicitly authorized support access.
                </span>
            </div>
        </div>
        <a href="../superadmin/manage_admins.php" class="btn btn-sm btn-outline-dark font-weight-bold rounded-pill px-3">
            <i class="fas fa-times mr-1"></i>Exit Support Mode
        </a>
    </div>
<?php elseif (!$is_super): ?>
    <div class="alert alert-info border-0 shadow-sm rounded-lg p-3 mb-4 d-flex align-items-center" style="background-color: #eff6ff; border-left: 5px solid #3b82f6 !important;">
        <i class="fas fa-shield-alt fa-2x text-primary mr-3"></i>
        <div>
            <strong class="text-dark d-block">Personal Institute AI Engine (Strictly Isolated)</strong>
            <span class="small text-muted">
                Your OpenRouter API key and model settings are private to your institute (<strong><?= h($_SESSION['institute_name'] ?? 'Your Institute') ?></strong>). All AI question generation tokens are billed directly to your own OpenRouter account without sharing or leaking across accounts.
            </span>
        </div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm rounded border-0 border-left border-danger" style="border-width: 4px !important;">
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo h($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success shadow-sm rounded border-0 border-left border-success" style="border-width: 4px !important;">
        <i class="fas fa-check-circle mr-2"></i><?php echo h($success); ?>
    </div>
<?php endif; ?>

<!-- Status Row -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 <?php echo $hasKey ? 'bg-success text-white' : 'bg-warning text-dark'; ?>">
                    <i class="fas <?php echo $hasKey ? 'fa-check-circle' : 'fa-key'; ?> fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">API Key Status</div>
                    <h5 class="font-weight-bold mb-0 text-dark">
                        <?php echo $hasKey ? 'Active & Configured' : 'Key Missing'; ?>
                    </h5>
                    <span class="small <?php echo $hasKey ? 'text-success' : 'text-danger'; ?>">
                        <?php echo $hasKey ? 'Ready for On-Demand Generation' : 'Enter API Key to enable AI'; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary text-white">
                    <i class="fas fa-brain fa-2x"></i>
                </div>
                <div class="text-truncate">
                    <div class="text-muted small text-uppercase font-weight-bold">Selected Model</div>
                    <h5 class="font-weight-bold mb-0 text-dark text-truncate" title="<?php echo h($config['model']); ?>">
                        <?php echo h($config['model']); ?>
                    </h5>
                    <span class="small text-muted">Temp: <?php echo $config['temperature']; ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4 mb-3">
        <div class="card border-0 shadow-sm rounded-lg h-100">
            <div class="card-body d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info text-white">
                    <i class="fas fa-bolt fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Integration Feature</div>
                    <h5 class="font-weight-bold mb-0 text-dark">Single Question AI</h5>
                    <span class="small text-muted">Auto-populates Summernote &amp; Options</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Configuration Form -->
    <div class="col-lg-8 mb-4">
        <div class="card border-0 shadow-sm rounded-lg overflow-hidden">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-cog mr-2 text-primary"></i>OpenRouter Credentials &amp; Model Selection
                </h5>
            </div>
            <div class="card-body px-4 pb-4">
                <form method="POST" action="ai_settings.php<?= $fix_admin_id > 0 ? '?fix_admin_id=' . $fix_admin_id : '' ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                    <input type="hidden" name="action" value="save_settings">
                    <?php if ($fix_admin_id > 0): ?>
                        <input type="hidden" name="fix_admin_id" value="<?php echo $fix_admin_id; ?>">
                    <?php endif; ?>

                    <fieldset <?= $support_blocked ? 'disabled' : '' ?>>
                    <!-- API Key Input -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">
                            OpenRouter API Key <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="password" name="api_key" id="api_key" class="form-control" placeholder="sk-or-v1-xxxxxxxxxxxxxxxx" value="<?php echo h($config['api_key']); ?>" required>
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleKeyVisibility()">
                                    <i class="fas fa-eye" id="keyIcon"></i>
                                </button>
                                <button class="btn btn-outline-primary font-weight-bold px-3" type="button" id="btnTestConn" onclick="testConnection()">
                                    <i class="fas fa-plug mr-1"></i>Test Connection
                                </button>
                            </div>
                        </div>
                        <small class="text-muted">
                            Obtain your key at <a href="https://openrouter.ai/keys" target="_blank" class="font-weight-bold">openrouter.ai/keys</a>. Keys are stored securely in your database.
                        </small>
                        <div id="testStatusAlert" class="alert d-none mt-2 py-2 px-3 small rounded"></div>
                    </div>

                    <!-- Model Selection -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Model Identifier</label>
                        <input type="text" name="model" id="modelInput" class="form-control mb-2 font-weight-bold" placeholder="e.g. google/gemini-2.0-flash-001" value="<?php echo h($config['model']); ?>" required>
                        <div class="small text-muted mb-2 font-weight-bold text-uppercase">Recommended Models (Click to Select):</div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill mb-1 mr-1" onclick="selectModel('google/gemini-2.0-flash-001')">
                                <i class="fas fa-bolt text-warning mr-1"></i>Gemini 2.0 Flash (Fast &amp; Cheap)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill mb-1 mr-1" onclick="selectModel('deepseek/deepseek-chat')">
                                <i class="fas fa-brain text-info mr-1"></i>DeepSeek V3 (Smart &amp; Economical)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill mb-1 mr-1" onclick="selectModel('meta-llama/llama-3.3-70b-instruct')">
                                <i class="fas fa-robot text-primary mr-1"></i>Llama 3.3 70B (High Reasoning)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill mb-1 mr-1" onclick="selectModel('openai/gpt-4o-mini')">
                                <i class="fas fa-check text-success mr-1"></i>GPT-4o Mini (OpenAI)
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill mb-1" onclick="selectModel('anthropic/claude-3.5-sonnet')">
                                <i class="fas fa-star text-warning mr-1"></i>Claude 3.5 Sonnet (Premium)
                            </button>
                        </div>
                    </div>

                    <!-- Temperature -->
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Creativity / Temperature (0.0 - 1.0)</label>
                        <div class="d-flex align-items-center">
                            <input type="range" class="custom-range flex-grow-1 mr-3" min="0.0" max="1.0" step="0.05" id="tempRange" value="<?php echo $config['temperature']; ?>" oninput="$('#tempVal').text(this.value); $('#tempInput').val(this.value)">
                            <span class="badge badge-primary px-3 py-2 font-weight-bold" id="tempVal"><?php echo $config['temperature']; ?></span>
                        </div>
                        <input type="hidden" name="temperature" id="tempInput" value="<?php echo $config['temperature']; ?>">
                        <small class="text-muted">Recommended: 0.7 for diverse, creative yet academically rigorous questions.</small>
                    </div>

                    <div class="text-right">
                        <button type="submit" class="btn btn-primary font-weight-bold rounded-pill px-5 shadow" <?= $support_blocked ? 'disabled' : '' ?>>
                            <i class="fas fa-save mr-2"></i>Save Configuration
                        </button>
                    </div>
                    </fieldset>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Guide Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-lg mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 px-4 pb-2">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-lightbulb text-warning mr-2"></i>How It Works
                </h5>
            </div>
            <div class="card-body px-4 pb-4">
                <ol class="small text-muted pl-3 mb-0" style="line-height: 1.8;">
                    <li class="mb-2"><strong>Get an API Key:</strong> Sign up at <a href="https://openrouter.ai" target="_blank">openrouter.ai</a> and generate an API key with $1–$5 credit.</li>
                    <li class="mb-2"><strong>Enter &amp; Save:</strong> Paste your API key here and select your preferred model (e.g. Gemini 2.0 Flash).</li>
                    <li class="mb-2"><strong>Generate on Demand:</strong> In the <strong>Add Single Question</strong> screen, click the <em>Generate with AI</em> button.</li>
                    <li class="mb-2"><strong>Automated Mapping:</strong> The AI constructs the question prompt, distractors, identifies the correct answer option, and populates your form instantly.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script>
function toggleKeyVisibility() {
    let input = $('#api_key');
    let icon = $('#keyIcon');
    if (input.attr('type') === 'password') {
        input.attr('type', 'text');
        icon.removeClass('fa-eye').addClass('fa-eye-slash');
    } else {
        input.attr('type', 'password');
        icon.removeClass('fa-eye-slash').addClass('fa-eye');
    }
}

function selectModel(modelName) {
    $('#modelInput').val(modelName);
}

function testConnection() {
    let btn = $('#btnTestConn');
    let origText = btn.html();
    let alertBox = $('#testStatusAlert');
    let key = $('#api_key').val().trim();
    let model = $('#modelInput').val().trim();

    if (!key) {
        alertBox.removeClass('d-none alert-success').addClass('alert-danger')
            .html('<i class="fas fa-exclamation-circle mr-1"></i> Please enter an OpenRouter API key first.');
        return;
    }

    btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i> Testing...');
    alertBox.addClass('d-none');

    $.ajax({
        url: 'ai_settings.php',
        method: 'POST',
        data: {
            action: 'ajax_test_connection',
            csrf_token: '<?php echo generate_csrf_token(); ?>',
            api_key: key,
            model: model
        },
        dataType: 'json',
        success: function(res) {
            btn.prop('disabled', false).html(origText);
            if (res.success) {
                alertBox.removeClass('d-none alert-danger').addClass('alert-success')
                    .html('<i class="fas fa-check-circle mr-1"></i> ' + res.message);
            } else {
                alertBox.removeClass('d-none alert-success').addClass('alert-danger')
                    .html('<i class="fas fa-times-circle mr-1"></i> ' + (res.error || 'Connection failed.'));
            }
        },
        error: function(xhr) {
            btn.prop('disabled', false).html(origText);
            var errMsg = 'Server communication error.';
            try {
                if (xhr.responseText) {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed.error) errMsg = parsed.error;
                }
            } catch (e) {
                if (xhr.status) errMsg += ' (HTTP ' + xhr.status + ')';
            }
            alertBox.removeClass('d-none alert-success').addClass('alert-danger')
                .html('<i class="fas fa-exclamation-triangle mr-1"></i> ' + errMsg);
        }
    });
}
</script>

<?php require_once '../includes/admin_footer.php'; ?>

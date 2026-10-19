<?php
// admin/fees/settings.php
// Class Fee Structure & Currency Configuration.

require_once '../../includes/admin_header.php';
require_once '../../includes/fee_manager.php';

$success = '';
$error = '';

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED) ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'save_fee_structures') {
        $currencySymbol = trim($_POST['fee_currency_symbol'] ?? '₹');
        $defaultMonthly = max(0, floatval($_POST['fee_default_monthly_amount'] ?? 1500));

        // Update settings
        $stmtSet = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value) 
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmtSet->execute(['fee_currency_symbol', $currencySymbol]);
        $stmtSet->execute(['fee_default_monthly_amount', (string)$defaultMonthly]);

        // Update per-class fee structures
        $classFees = $_POST['class_fee'] ?? [];
        foreach ($classes as $c) {
            $cId = (int)$c['id'];
            if (isset($classFees[$cId])) {
                $amt = max(0, floatval($classFees[$cId]));
                save_class_monthly_fee($cId, $amt, $pdo);
            }
        }

        $success = "Fee structures and currency configurations updated successfully!";
    }
}

// Fetch current fee structures
$stmtFees = $pdo->query("SELECT class_id, monthly_fee FROM fee_structures");
$feeStructures = $stmtFees->fetchAll(PDO::FETCH_KEY_PAIR);

$currency = get_fee_currency_symbol($pdo);
$stmtDef = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'fee_default_monthly_amount'");
$defaultFee = $stmtDef->fetchColumn() ?: 1500;
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Fee Structure & Settings</h2>
        <span class="text-muted small">Configure tuition fee rates per class, currency symbol, and automated reminder schedules.</span>
    </div>
    <div class="mt-2 mt-md-0">
        <a href="index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm rounded-pill px-4">
            <i class="fas fa-arrow-left mr-2"></i>Back to Fees Overview
        </a>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success shadow-sm rounded border-0 border-left border-success" style="border-width: 4px !important;">
        <i class="fas fa-check-circle mr-2"></i><?php echo h($success); ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm rounded border-0 border-left border-danger" style="border-width: 4px !important;">
        <i class="fas fa-exclamation-triangle mr-2"></i><?php echo h($error); ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 rounded-lg mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h5 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-tags text-primary mr-2"></i>Monthly Tuition Fees per Class
                </h5>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
                <input type="hidden" name="action" value="save_fee_structures">

                <div class="card-body p-4">
                    <p class="text-muted small mb-4">
                        Set the standard monthly tuition fee due from each student enrolled in the respective class. This amount is automatically populated when generating monthly ledgers and receipts.
                    </p>

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-4">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 35%;">Class / Grade</th>
                                    <th>Monthly Fee Rate (<?php echo h($currency); ?>)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($classes as $c): ?>
                                    <?php 
                                        $cId = (int)$c['id'];
                                        $currentRate = $feeStructures[$cId] ?? $defaultFee;
                                    ?>
                                    <tr>
                                        <td class="font-weight-bold text-dark align-middle">
                                            <span class="badge badge-light border text-primary px-3 py-2 mr-2 font-weight-bold">
                                                Class <?php echo h($c['name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="input-group shadow-sm">
                                                <div class="input-group-prepend">
                                                    <span class="input-group-text bg-light font-weight-bold"><?php echo h($currency); ?></span>
                                                </div>
                                                <input type="number" step="0.01" min="0" name="class_fee[<?php echo $cId; ?>]" 
                                                       class="form-control font-weight-bold text-dark" 
                                                       value="<?php echo number_format((float)$currentRate, 2, '.', ''); ?>" required>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <h6 class="font-weight-bold text-dark mt-4 mb-3 border-top pt-3">
                        <i class="fas fa-sliders-h text-muted mr-2"></i>Global Fee Preferences
                    </h6>

                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted text-uppercase">Currency Symbol</label>
                            <input type="text" name="fee_currency_symbol" class="form-control font-weight-bold" 
                                   value="<?php echo h($currency); ?>" placeholder="e.g. ₹ or $" maxlength="5" required>
                            <small class="text-muted">Displayed on receipts, emails, and student portals.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small text-muted text-uppercase">Default Fallback Fee</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text font-weight-bold"><?php echo h($currency); ?></span></div>
                                <input type="number" step="0.01" min="0" name="fee_default_monthly_amount" 
                                       class="form-control font-weight-bold" value="<?php echo number_format((float)$defaultFee, 2, '.', ''); ?>" required>
                            </div>
                            <small class="text-muted">Applied if a newly added class has no custom rate assigned.</small>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light border-top text-right py-3 px-4">
                    <button type="submit" class="btn btn-primary font-weight-bold shadow-sm rounded-pill px-4">
                        <i class="fas fa-save mr-2"></i>Save Fee Settings
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Automated Reminders Card & Cron Instructions -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 rounded-lg mb-4">
            <div class="card-header bg-white py-3 border-bottom">
                <h6 class="font-weight-bold text-dark mb-0">
                    <i class="fas fa-clock text-info mr-2"></i>Automated Reminders Setup
                </h6>
            </div>
            <div class="card-body p-4 font-size-sm">
                <div class="alert alert-info border-0 shadow-sm rounded-lg p-3 small mb-3">
                    <div class="font-weight-bold mb-1"><i class="fas fa-calendar-check mr-1"></i>Reminder Schedule:</div>
                    <ul class="pl-3 mb-0">
                        <li><strong>Middle of Month (15th):</strong> Summarizes fee collections received and accounts still pending mid-month.</li>
                        <li><strong>End of Month (Last Day):</strong> Complete end-of-month financial collection digest.</li>
                    </ul>
                </div>

                <h6 class="font-weight-bold text-dark small text-uppercase mb-2">Automated Execution via Cron</h6>
                <p class="text-muted small mb-2">
                    Schedule <code>cron/fee_reminders.php</code> to run daily. It automatically evaluates the calendar date and sends the report to the administrator when appropriate.
                </p>

                <div class="bg-dark text-white p-3 rounded small font-family-monospace mb-3" style="font-size: 11px; word-break: break-all;">
                    # Linux / Docker Cron (Runs daily at 09:00 AM)<br>
                    0 9 * * * php /var/www/html/cron/fee_reminders.php
                </div>

                <div class="bg-light border p-3 rounded small font-family-monospace mb-3" style="font-size: 11px; word-break: break-all;">
                    REM Windows Task Scheduler command:<br>
                    php c:\xampp\htdocs\shreyansir\cron\fee_reminders.php
                </div>

                <h6 class="font-weight-bold text-dark small text-uppercase mb-2">Manual Dispatch</h6>
                <p class="text-muted small mb-0">
                    You can also trigger both mid-month and month-end reminder reports on-demand at any time directly from the <strong>Fees Overview</strong> dashboard.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/admin_footer.php'; ?>

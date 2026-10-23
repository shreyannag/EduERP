<?php
// admin/admins/payment_receipt.php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_payment_manager.php';

$payment_id = (int)($_GET['id'] ?? 0);
$receipt_no = trim($_GET['receipt'] ?? '');

if ($payment_id <= 0 && empty($receipt_no)) {
    die("Invalid receipt parameter.");
}

if ($payment_id > 0) {
    $stmt = $pdo->prepare("SELECT p.*, a.username, a.institute_name, a.email as admin_email FROM admin_payments p JOIN admins a ON p.admin_id = a.id WHERE p.id = ?");
    $stmt->execute([$payment_id]);
} else {
    $stmt = $pdo->prepare("SELECT p.*, a.username, a.institute_name, a.email as admin_email FROM admin_payments p JOIN admins a ON p.admin_id = a.id WHERE p.receipt_number = ?");
    $stmt->execute([$receipt_no]);
}

$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die("Payment record not found.");
}

// Access check: Super Admin or the admin who paid
$current_user_id = (int)$_SESSION['admin_id'];
$is_super = (!empty($_SESSION['is_super_admin']));
if (!$is_super && (int)$payment['admin_id'] !== $current_user_id) {
    die("Unauthorized access to this receipt.");
}

$settings = get_admin_billing_settings($pdo);
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$billingPeriod = ($monthNames[$payment['billing_month']] ?? "Month {$payment['billing_month']}") . " " . $payment['billing_year'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official Subscription Receipt - <?= h($payment['receipt_number']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1e293b; }
        .receipt-container { max-width: 760px; margin: 40px auto; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; overflow: hidden; }
        .receipt-header { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; padding: 36px 40px; }
        .receipt-body { padding: 40px; }
        .receipt-badge-paid { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; font-weight: 800; font-size: 0.95rem; padding: 6px 16px; border-radius: 9999px; display: inline-block; }
        @media print {
            body { background: #ffffff; }
            .receipt-container { box-shadow: none; border: none; margin: 0; width: 100%; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="container py-3">
    <div class="text-right mb-3 no-print">
        <button type="button" class="btn btn-primary font-weight-bold shadow-sm px-4 mr-2" onclick="window.print()">
            <i class="fas fa-print mr-2"></i>Print Receipt
        </button>
        <button type="button" class="btn btn-secondary font-weight-bold shadow-sm px-4" onclick="window.close()">
            <i class="fas fa-times mr-2"></i>Close
        </button>
    </div>

    <div class="receipt-container">
        <!-- Header -->
        <div class="receipt-header d-flex justify-content-between align-items-center">
            <div>
                <h3 class="font-weight-bold mb-1"><i class="fas fa-shield-alt text-warning mr-2"></i>Online Examination Platform</h3>
                <p class="text-white-50 mb-0 small">Super Administrator Multi-Tenant Billing</p>
            </div>
            <div class="text-right">
                <span class="receipt-badge-paid"><i class="fas fa-check-circle mr-1"></i> PAYMENT RECEIVED</span>
                <div class="small text-white-50 mt-1">Receipt #: <span class="text-white font-weight-bold font-monospace"><?= h($payment['receipt_number']) ?></span></div>
            </div>
        </div>

        <div class="receipt-body">
            <!-- Details Grid -->
            <div class="row mb-4 pb-3 border-bottom">
                <div class="col-sm-6 mb-3 mb-sm-0">
                    <span class="text-muted text-uppercase font-weight-bold small">Billed Institute</span>
                    <h5 class="font-weight-bold text-dark mb-1 mt-1"><?= h($payment['institute_name']) ?></h5>
                    <p class="text-muted small mb-0">Admin Handle: <strong><?= h($payment['username']) ?></strong></p>
                    <?php if (!empty($payment['admin_email'])): ?>
                        <p class="text-muted small mb-0">Email: <?= h($payment['admin_email']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="col-sm-6 text-sm-right">
                    <span class="text-muted text-uppercase font-weight-bold small">Invoice Details</span>
                    <p class="small mb-1 mt-1"><strong>Billing Cycle:</strong> <?= h($billingPeriod) ?></p>
                    <p class="small mb-1"><strong>Payment Date:</strong> <?= $payment['payment_date'] ? date('d M Y, h:i A', strtotime($payment['payment_date'])) : 'N/A' ?></p>
                    <p class="small mb-0"><strong>Payment Method:</strong> <?= h($payment['payment_method']) ?></p>
                    <?php if (!empty($payment['transaction_ref'])): ?>
                        <p class="small text-muted mb-0"><strong>Ref / UTR:</strong> <?= h($payment['transaction_ref']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Enrolment Volume Callout -->
            <div class="alert alert-light border d-flex justify-content-between align-items-center mb-4 p-3 rounded-lg">
                <div class="d-flex align-items-center">
                    <i class="fas fa-user-graduate fa-2x text-primary mr-3"></i>
                    <div>
                        <div class="font-weight-bold text-dark">Platform Student Enrolment Capacity</div>
                        <div class="text-muted small">Registered students managed under this administrator account</div>
                    </div>
                </div>
                <div class="text-right">
                    <span class="badge badge-primary px-3 py-2 font-weight-bold rounded-pill" style="font-size: 1rem;">
                        <?= (int)$payment['enrolled_students'] ?> Students Enrolled
                    </span>
                </div>
            </div>

            <!-- Line Items Table -->
            <?php 
            $breakdown = !empty($payment['itemized_breakdown']) ? json_decode($payment['itemized_breakdown'], true) : null;
            $hasItemized = !empty($breakdown) && (!empty($breakdown['feature_breakdown']) || !empty($breakdown['students_total']) || !empty($breakdown['discount_amount']));
            ?>
            <table class="table table-bordered mb-4">
                <thead class="bg-light text-muted small text-uppercase">
                    <tr>
                        <th class="py-2">Description / Modular Subscription Items</th>
                        <th class="py-2 text-center">Billing Cycle</th>
                        <th class="py-2 text-center">Enrolled Students</th>
                        <th class="py-2 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($hasItemized): ?>
                        <?php if (!empty($breakdown['base_fee']) && $breakdown['base_fee'] > 0): ?>
                        <tr>
                            <td class="py-2">
                                <strong>Base Platform Infrastructure & Server Hosting</strong>
                                <div class="text-muted small">Multi-tenant server hosting, daily backups & security maintenance</div>
                            </td>
                            <td class="py-2 text-center align-middle font-weight-bold"><?= h($billingPeriod) ?></td>
                            <td class="py-2 text-center align-middle font-weight-bold"><?= (int)$payment['enrolled_students'] ?></td>
                            <td class="py-2 text-right align-middle font-weight-bold">
                                <?= $settings['fee_currency_symbol'] ?><?= number_format($breakdown['base_fee'], 2) ?>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($breakdown['feature_breakdown'] as $fItem): ?>
                        <tr>
                            <td class="py-2">
                                <strong><i class="<?= $fItem['feature_icon'] ?> text-primary mr-1"></i> <?= h($fItem['feature_label']) ?></strong>
                                <div class="text-muted small">Specialized add-on module subscription</div>
                            </td>
                            <td class="py-2 text-center align-middle"><?= h($billingPeriod) ?></td>
                            <td class="py-2 text-center align-middle">-</td>
                            <td class="py-2 text-right align-middle font-weight-bold text-primary">
                                <?= $settings['fee_currency_symbol'] ?><?= number_format($fItem['price'], 2) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <?php if (!empty($breakdown['students_total']) && $breakdown['students_total'] > 0): ?>
                        <tr>
                            <td class="py-2">
                                <strong>Student Capacity Fee (Tier Pricing)</strong>
                                <div class="text-muted small"><?= (int)$breakdown['enrolled_students'] ?> active students @ <?= $settings['fee_currency_symbol'] ?><?= number_format($breakdown['per_student_fee'], 2) ?>/student</div>
                            </td>
                            <td class="py-2 text-center align-middle"><?= h($billingPeriod) ?></td>
                            <td class="py-2 text-center align-middle font-weight-bold"><?= (int)$payment['enrolled_students'] ?></td>
                            <td class="py-2 text-right align-middle font-weight-bold">
                                <?= $settings['fee_currency_symbol'] ?><?= number_format($breakdown['students_total'], 2) ?>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if (!empty($breakdown['discount_amount']) && $breakdown['discount_amount'] > 0): ?>
                        <tr class="table-warning">
                            <td class="py-2" colspan="3">
                                <strong class="text-danger"><i class="fas fa-tag mr-1"></i> Promotional Discount / Concession</strong>
                            </td>
                            <td class="py-2 text-right align-middle font-weight-bold text-danger">
                                -<?= $settings['fee_currency_symbol'] ?><?= number_format($breakdown['discount_amount'], 2) ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php else: ?>
                        <tr>
                            <td class="py-3">
                                <strong><?= h($payment['plan_name']) ?></strong>
                                <div class="text-muted small">Cloud hosting, question banking, AI authoring, and student examination portal</div>
                            </td>
                            <td class="py-3 text-center align-middle font-weight-bold"><?= h($billingPeriod) ?></td>
                            <td class="py-3 text-center align-middle font-weight-bold"><?= (int)$payment['enrolled_students'] ?></td>
                            <td class="py-3 text-right align-middle font-weight-bold text-success" style="font-size: 1.1rem;">
                                <?= $settings['fee_currency_symbol'] ?><?= number_format($payment['amount_paid'], 2) ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-light">
                        <td colspan="3" class="text-right font-weight-bold text-uppercase py-3">Total Paid Amount:</td>
                        <td class="text-right font-weight-bold py-3 text-dark" style="font-size: 1.25rem;">
                            <?= $settings['fee_currency_symbol'] ?><?= number_format($payment['amount_paid'], 2) ?>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <?php if (!empty($payment['notes'])): ?>
                <div class="bg-light p-3 rounded mb-4 text-muted small">
                    <strong>Payment Notes:</strong> <?= nl2br(h($payment['notes'])) ?>
                </div>
            <?php endif; ?>

            <div class="text-center text-muted small pt-3 border-top">
                <p class="mb-1 font-weight-bold text-dark">Thank you for your partnership!</p>
                <p class="mb-0">This document serves as an official electronic receipt for platform services rendered.</p>
            </div>
        </div>
    </div>
</div>

</body>
</html>

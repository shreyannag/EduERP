<?php
// admin/questions/export_pdf.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request method.");
}
verify_csrf_token($_POST['csrf_token'] ?? '');

$ids = [];
if (isset($_POST['id']) && is_array($_POST['id'])) {
    foreach ($_POST['id'] as $id) {
        $ids[] = intval($id);
    }
}
if (empty($ids)) {
    die("No questions selected.");
}

$current_admin_id = (int)$_SESSION['admin_id'];
$is_super = is_super_admin();

$in = str_repeat('?,', count($ids) - 1) . '?';
$params = $ids;
$whereAuth = "";
if (!$is_super) {
    $whereAuth = " AND q.admin_id = ?";
    $params[] = $current_admin_id;
}

$stmt = $pdo->prepare("
    SELECT q.*, c.name as class_name, b.name as board_name, s.name as subject_name, t.name as topic_name 
    FROM questions q
    JOIN classes c ON q.class_id = c.id
    JOIN boards b ON q.board_id = b.id
    LEFT JOIN subjects s ON q.subject_id = s.id
    LEFT JOIN topics t ON q.topic_id = t.id
    WHERE q.id IN ($in) $whereAuth
    ORDER BY q.id ASC
");
$stmt->execute($params);
$questions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exported Question Paper</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #000; background: #fff; padding: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h2 { margin: 0; font-size: 24px; text-transform: uppercase; }
        .question-block { margin-bottom: 25px; page-break-inside: avoid; }
        .q-text { font-weight: bold; margin-bottom: 10px; }
        .q-text img { max-width: 100%; height: auto; display: block; margin: 8px 0; }
        .options-grid { display: flex; flex-wrap: wrap; margin-bottom: 5px; }
        .option { width: 50%; margin-bottom: 5px; }
        .meta { font-size: 11px; color: #555; text-align: right; font-style: italic; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
        .btn-print { background: #007bff; color: #fff; border: none; padding: 10px 20px; font-size: 16px; cursor: pointer; border-radius: 4px; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="no-print" style="text-align: center;">
    <button class="btn-print" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="header">
    <h2>Question Bank Export</h2>
    <p>Generated on <?php echo date('F j, Y'); ?></p>
</div>

<?php foreach($questions as $index => $q): ?>
    <div class="question-block">
        <div class="q-text">
            <?php echo htmlspecialchars(($index + 1) . ". ", ENT_QUOTES, 'UTF-8'); ?>
            <span style="font-weight: normal;"><?php echo clean_html($q['question_text']); ?></span>
        </div>
        <?php if (($q['question_type'] ?? 'mcq') === 'fill_blank'): ?>
            <div style="margin: 10px 0 15px 0; font-size: 13px;">
                <strong>Answer:</strong> __________________________________________________
            </div>
        <?php else: ?>
            <div class="options-grid">
                <div class="option">A. <?php echo htmlspecialchars($q['option1'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="option">B. <?php echo htmlspecialchars($q['option2'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="option">C. <?php echo htmlspecialchars($q['option3'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="option">D. <?php echo htmlspecialchars($q['option4'], ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        <?php endif; ?>
        <div class="meta">
            [Class: <?php echo htmlspecialchars($q['class_name'], ENT_QUOTES, 'UTF-8'); ?> | Board: <?php echo htmlspecialchars($q['board_name'], ENT_QUOTES, 'UTF-8'); ?> | Subject: <?php echo htmlspecialchars($q['subject_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?> | Marks: <?php echo htmlspecialchars($q['marks'], ENT_QUOTES, 'UTF-8'); ?>]
        </div>
    </div>
<?php endforeach; ?>

<script>
    // Automatically trigger print dialog
    window.onload = function() {
        window.print();
    }
</script>

</body>
</html>

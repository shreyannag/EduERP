<?php
// admin/reports/export_pdf.php
require_once '../../includes/admin_auth.php';
require_once '../../config/db.php';
require_once '../../includes/functions.php';

require_admin_feature('reports');

$exam_id = intval($_GET['exam_id'] ?? 0);
if (!$exam_id) die("Exam ID missing.");

$stmt = $pdo->prepare("
    SELECT e.*, c.name as class_name, b.name as board_name, a.institute_name 
    FROM exams e 
    JOIN classes c ON e.class_id = c.id 
    JOIN boards b ON e.board_id = b.id 
    LEFT JOIN admins a ON e.admin_id = a.id
    WHERE e.id = ? AND e.status = 'completed'
");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();

if (!$exam) die("Assessment context invalid or uncompleted against temporal bounds.");

$stmt_res = $pdo->prepare("
    SELECT s.full_name, s.username, a.score, a.total_questions,
    COALESCE(a.total_marks, (SELECT SUM(q.marks) FROM exam_questions eq JOIN questions q ON eq.question_id = q.id WHERE eq.exam_id = a.exam_id), a.total_questions) as total_marks
    FROM attempts a
    JOIN students s ON a.student_id = s.id
    WHERE a.exam_id = ? AND a.is_submitted = 1
    ORDER BY a.score DESC, s.full_name ASC
");
$stmt_res->execute([$exam_id]);
$results = $stmt_res->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Score Report Render: <?php echo h($exam['title']); ?></title>
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111827; margin: 0; padding: 30px; font-size: 14px; background-color: #f3f4f6; }
        .page { background: #fff; padding: 40px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-radius: 8px; max-width: 900px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2px solid #e5e7eb; padding-bottom: 25px; margin-bottom: 35px; }
        .header h1 { margin: 0 0 10px 0; font-size: 28px; text-transform: uppercase; color: #1f2937; letter-spacing: 1px; }
        .header p { margin: 0; font-size: 18px; font-weight: bold; color: #4b5563; }
        .meta { display: flex; justify-content: space-between; margin-bottom: 35px; background: #f9fafb; padding: 15px 20px; border-radius: 6px; border: 1px solid #e5e7eb; }
        .meta div { width: 48%; }
        .meta p { margin: 5px 0; color: #374151; font-size: 13px; }
        strong { color: #111827; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-bottom: 40px; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; }
        th, td { border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb; padding: 12px 15px; text-align: left; }
        th:last-child, td:last-child { border-right: none; }
        tr:last-child td { border-bottom: none; }
        th { background-color: #f3f4f6; font-weight: bold; text-transform: uppercase; font-size: 12px; color: #4b5563; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .pass { color: #059669; font-weight: bold; }
        .fail { color: #dc2626; font-weight: bold; }
        .footer { text-align: center; font-size: 11px; color: #6b7280; padding-top: 15px; border-top: 1px dashed #d1d5db; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; background: #fff; }
            .page { box-shadow: none; max-width: 100%; padding: 0; }
        }
        .btn { padding: 10px 24px; background: #2563eb; color: white; border: none; border-radius: 999px; cursor: pointer; font-weight: bold; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px; transition: all 0.2s; }
        .btn:hover { background: #1d4ed8; }
        .btn-sec { background: #4b5563; margin-left: 10px; }
        .btn-sec:hover { background: #374151; }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: right; margin-bottom: 25px; max-width: 900px; margin-left: auto; margin-right: auto;">
        <button class="btn" onclick="window.print()">Trigger PDF Render Canvas</button>
        <button class="btn btn-sec" onclick="window.close()">Close Window Context</button>
    </div>

    <div class="page">
        <div class="header">
            <div style="font-size: 16px; font-weight: bold; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;">
                <?php echo h(!empty($exam['institute_name']) ? $exam['institute_name'] : 'Online Examination Platform'); ?>
            </div>
            <h1>Official Results Output Manifest</h1>
            <p><?php echo h($exam['title']); ?></p>
        </div>

        <div class="meta">
            <div>
                <p><strong>Configured Focus Target:</strong> Class <?php echo h($exam['class_name']); ?></p>
                <p><strong>Affiliated Sequence Schema:</strong> <?php echo h($exam['board_name']); ?></p>
            </div>
            <div style="text-align: right;">
                <p><strong>Execution Marker Bound:</strong> <?php echo date('d M Y, H:i', strtotime($exam['scheduled_date'].' '.$exam['scheduled_time'])); ?></p>
                <p><strong>Submitted Finalizations Array:</strong> <?php echo count($results); ?> Valid Nodes</p>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width="8%" class="text-center">Pos</th>
                    <th width="35%">Verified Profile Node</th>
                    <th width="20%">Cryptographic Routing Hash</th>
                    <th width="15%" class="text-center">Scalar Output</th>
                    <th width="12%" class="text-center">Accuracy Index</th>
                    <th width="10%" class="text-center">Resolution</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = 1;
                foreach($results as $r): 
                    $total_marks = !empty($r['total_marks']) && $r['total_marks'] > 0 
                        ? (int)$r['total_marks'] 
                        : (!empty($r['total_questions']) ? (int)$r['total_questions'] : 1);
                    $pct = round(($r['score'] / $total_marks) * 100, 1);
                    $pct = min(100, max(0, $pct));
                    $passed = $pct >= 40;
                ?>
                <tr>
                    <td class="text-center text-muted"><strong><?php echo $rank++; ?></strong></td>
                    <td style="color: #111827; font-weight: 500;"><?php echo h($r['full_name']); ?></td>
                    <td style="font-family: monospace; color: #4b5563;"><?php echo h($r['username']); ?></td>
                    <td class="text-center" style="font-weight: 500;"><?php echo $r['score']; ?> / <?php echo $total_marks; ?></td>
                    <td class="text-center <?php echo $passed ? 'pass' : 'fail'; ?>"><?php echo $pct; ?>%</td>
                    <td class="text-center <?php echo $passed ? 'pass' : 'fail'; ?>"><?php echo $passed ? 'PASS' : 'FAIL'; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($results)): ?>
                <tr><td colspan="6" class="text-center" style="padding: 30px; color: #6b7280; font-style: italic;">No computational logic traces processed for this temporal evaluation bound.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="footer">
            Immutable trace auto-generated by Central Administrative Controller on <?php echo date('d M Y, H:i:s T'); ?>.
        </div>
    </div>

    <script>
        window.onload = function() { 
            setTimeout(() => { window.print(); }, 500);
        }
    </script>
</body>
</html>

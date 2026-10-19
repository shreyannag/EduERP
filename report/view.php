<?php
// report/view.php
require_once '../config/db.php';
require_once '../includes/functions.php';

$token = $_GET['token'] ?? '';
if (!$token) die("Invalid access trace point overlay.");

$stmt = $pdo->prepare("SELECT rl.*, e.title, e.scheduled_date, e.class_id, e.board_id, c.name as class_name, b.name as board_name 
                       FROM report_links rl 
                       JOIN exams e ON rl.exam_id = e.id 
                       JOIN classes c ON e.class_id = c.id
                       JOIN boards b ON e.board_id = b.id
                       WHERE rl.token = ? AND rl.expires_at > NOW()");
$stmt->execute([$token]);
$link_data = $stmt->fetch();

if (!$link_data) die("This secure portal link has expired or is invalid.");

$exam_id = $link_data['exam_id'];

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Exam Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-light" style="background-color: #f3f4f6 !important;">
    
<div class="container py-5">
    <div class="text-center mb-5">
        <div class="d-inline-block bg-white shadow-sm p-4 rounded-circle mb-3 border">
            <i class="fas fa-shield-alt fa-3x text-primary" style="background: linear-gradient(135deg, #0052d4, #4364f7); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
        </div>
        <h2 class="font-weight-bold text-dark" style="letter-spacing: -0.5px;">Public Exam Report</h2>
        <p class="text-muted font-weight-bold">Report Valid Until: <span class="bg-white px-2 py-1 rounded border shadow-sm"><?php echo date('d M Y, H:i T', strtotime($link_data['expires_at'])); ?></span></p>
    </div>
    
    <div class="card shadow-lg border-0 rounded-lg overflow-hidden">
        <div class="card-header bg-gradient-primary text-white p-5 border-0" style="background: linear-gradient(135deg, #1e3a8a, #3b82f6);">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <span class="badge badge-light text-primary font-weight-bold text-uppercase px-3 py-1 rounded-pill mb-3 shadow-sm border-0">Verified Result</span>
                    <h3 class="mb-2 font-weight-bold display-5"><?php echo h($link_data['title']); ?></h3>
                    <p class="mb-0 text-white-50 font-weight-bold" style="font-size: 1.1rem;">Class <?php echo h($link_data['class_name']); ?> • Board: <?php echo h($link_data['board_name']); ?></p>
                </div>
                <div class="col-md-4 text-md-right mt-4 mt-md-0">
                    <div class="bg-white bg-opacity-10 rounded-lg p-3 d-inline-block text-left border" style="border-color: rgba(255,255,255,0.2) !important;">
                        <span class="d-block text-white-50 small text-uppercase font-weight-bold mb-1"><i class="fas fa-calendar-check mr-2"></i>Date</span>
                        <span class="h5 font-weight-bold mb-0"><?php echo date('d M Y', strtotime($link_data['scheduled_date'])); ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card-body p-0 bg-white">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="bg-light text-muted small text-uppercase font-weight-bold">
                        <tr>
                            <th width="10%" class="text-center px-4 py-4 border-0 border-bottom border-top">Rank</th>
                            <th width="40%" class="py-4 border-0 border-bottom border-top px-4">Masked Username</th>
                            <th width="20%" class="text-center py-4 border-0 border-bottom border-top">Score</th>
                            <th width="30%" class="text-center py-4 border-0 border-bottom border-top">Status</th>
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
                            // Privacy constraints handler mappings
                            $masked = substr($r['username'], 0, 4) . '••••' . substr($r['username'], -3);
                            $passed = $pct >= 40;
                        ?>
                        <tr>
                            <td class="text-center align-middle font-weight-bold text-muted px-4 py-3 h6 mb-0 border-bottom border-light"><?php echo $rank++; ?></td>
                            <td class="align-middle font-family-monospace text-dark font-weight-bold py-3 px-4 border-bottom border-light" style="letter-spacing: 1px;"><i class="fas fa-user-secret mr-3 opacity-25 text-muted"></i><?php echo h($masked); ?></td>
                            <td class="text-center align-middle font-weight-bold border-bottom border-light <?php echo $passed ? 'text-success' : 'text-danger'; ?>" style="font-size: 1.1rem;"><?php echo $pct; ?>%</td>
                            <td class="text-center align-middle border-bottom border-light">
                                <?php if($passed): ?>
                                    <span class="badge badge-success px-4 py-2 shadow-sm rounded-pill text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-check mr-2"></i>Passed</span>
                                <?php else: ?>
                                    <span class="badge badge-danger px-4 py-2 shadow-sm rounded-pill text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-times mr-2"></i>Failed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($results)): ?>
                        <tr><td colspan="4" class="text-center py-5 text-muted font-weight-bold"><i class="fas fa-folder-open fa-3x mb-3 opacity-25 d-block"></i>No students found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card-footer bg-light border-top text-center py-4 text-muted small font-weight-bold opacity-75">
            <i class="fas fa-lock mr-2"></i>This report securely displays results while preserving student privacy. Usernames are masked to protect identities.
        </div>
    </div>
</div>

</body>
</html>

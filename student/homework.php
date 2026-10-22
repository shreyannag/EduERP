<?php
// student/homework.php
require_once __DIR__ . '/../includes/student_header.php';

$student_id = (int)$_SESSION['student_id'];

// Fetch current student profile
$stmtStu = $pdo->prepare("SELECT s.id, s.full_name, s.admin_id, s.class_id, s.board_id, a.institute_name FROM students s LEFT JOIN admins a ON s.admin_id = a.id WHERE s.id = ?");
$stmtStu->execute([$student_id]);
$student = $stmtStu->fetch(PDO::FETCH_ASSOC);

$admin_id = (int)$student['admin_id'];
$class_id = (int)$student['class_id'];
$board_id = (int)$student['board_id'];

// Fetch homework assigned to this student
$stmtHw = $pdo->prepare("
    SELECT 
        hw.*,
        sub.name as subject_name,
        bt.name as batch_name,
        cr.username as creator_username,
        cr.full_name as creator_fullname,
        cr.sub_role as creator_sub_role,
        s.id as submission_id,
        s.status as submission_status,
        s.grade_points,
        s.teacher_feedback,
        s.submitted_at
    FROM homework hw
    LEFT JOIN subjects sub ON hw.subject_id = sub.id
    LEFT JOIN batches bt ON hw.batch_id = bt.id
    LEFT JOIN admins cr ON hw.created_by = cr.id
    LEFT JOIN homework_submissions s ON s.homework_id = hw.id AND s.student_id = ?
    WHERE (hw.admin_id = ? OR hw.admin_id = 1)
      AND hw.is_active = 1
      AND (
          (hw.batch_id IS NOT NULL AND hw.batch_id IN (SELECT batch_id FROM batch_students WHERE student_id = ?))
          OR
          (hw.batch_id IS NULL AND (hw.class_id IS NULL OR hw.class_id = ?) AND (hw.board_id IS NULL OR hw.board_id = ?))
      )
    ORDER BY 
        CASE WHEN s.id IS NULL AND hw.due_date >= NOW() THEN 0
             WHEN s.id IS NULL AND hw.due_date < NOW() THEN 1
             ELSE 2 END ASC,
        hw.due_date ASC
");
$stmtHw->execute([$student_id, $admin_id, $student_id, $class_id, $board_id]);
$allHomework = $stmtHw->fetchAll(PDO::FETCH_ASSOC);

// Partition into To-Do and Completed
$toDoList = [];
$completedList = [];
$totalGradedPoints = 0;
$totalGradedMax = 0;
$gradedCount = 0;

$nowTime = time();

foreach ($allHomework as $hw) {
    if (empty($hw['submission_id'])) {
        $toDoList[] = $hw;
    } else {
        $completedList[] = $hw;
        if ($hw['submission_status'] === 'reviewed' && $hw['grade_points'] !== null) {
            $totalGradedPoints += (float)$hw['grade_points'];
            $totalGradedMax += (int)$hw['total_points'];
            $gradedCount++;
        }
    }
}

$pendingCount = count($toDoList);
$completedCount = count($completedList);
$overallTotal = count($allHomework);
?>

<style>
.hw-card {
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    border-radius: 12px;
}
.hw-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.08);
}
.badge-tech {
    padding: 0.25rem 0.55rem;
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 6px;
    margin-right: 4px;
}
.badge-code { background: #1e293b; color: #38bdf8; border: 1px solid #334155; }
.badge-latex { background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
.badge-pic { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4 pb-2 border-bottom">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">
            <i class="fas fa-book-reader text-primary mr-2"></i>My Homework Assignments
        </h2>
        <p class="text-muted mb-0 small">
            View coursework assigned by your teachers, inspect mathematical formulas, solve programming challenges, and submit your solutions.
        </p>
    </div>
</div>

<!-- Student Overview KPI Cards -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-warning bg-opacity-10 text-warning">
                    <i class="fas fa-clock fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">To-Do / Due</div>
                    <h3 class="font-weight-bold text-dark mb-0"><?= $pendingCount ?></h3>
                    <span class="small text-muted">Awaiting your solution</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-success bg-opacity-10 text-success">
                    <i class="fas fa-check-circle fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Completed</div>
                    <h3 class="font-weight-bold text-success mb-0"><?= $completedCount ?></h3>
                    <span class="small text-muted">Turned-in assignments</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-info bg-opacity-10 text-info">
                    <i class="fas fa-award fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Graded & Feedback</div>
                    <h3 class="font-weight-bold text-info mb-0"><?= $gradedCount ?></h3>
                    <span class="small text-muted">Reviewed by instructors</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm rounded-lg bg-white h-100">
            <div class="card-body p-3 d-flex align-items-center">
                <div class="rounded-circle p-3 mr-3 bg-primary bg-opacity-10 text-primary">
                    <i class="fas fa-chart-pie fa-2x"></i>
                </div>
                <div>
                    <div class="text-muted small text-uppercase font-weight-bold">Average Score</div>
                    <h3 class="font-weight-bold text-primary mb-0">
                        <?= ($totalGradedMax > 0) ? round(($totalGradedPoints / $totalGradedMax) * 100) : 0 ?>%
                    </h3>
                    <span class="small text-muted">Across evaluated tasks</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Tabs: To-Do vs Completed vs All -->
<ul class="nav nav-pills nav-pills-scrollable mb-4" id="homeworkTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active font-weight-bold rounded-pill px-4 mr-2" id="todo-tab" data-toggle="pill" href="#todoPane" role="tab">
            <i class="fas fa-hourglass-half mr-1"></i>To-Do / Due (<?= $pendingCount ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link font-weight-bold rounded-pill px-4 mr-2" id="completed-tab" data-toggle="pill" href="#completedPane" role="tab">
            <i class="fas fa-check-double mr-1"></i>Completed / Turned In (<?= $completedCount ?>)
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link font-weight-bold rounded-pill px-4" id="all-tab" data-toggle="pill" href="#allPane" role="tab">
            <i class="fas fa-list mr-1"></i>All Homework (<?= $overallTotal ?>)
        </a>
    </li>
</ul>

<div class="tab-content" id="homeworkTabsContent">
    <!-- PANE 1: TO-DO -->
    <div class="tab-pane fade show active" id="todoPane" role="tabpanel">
        <?php if (empty($toDoList)): ?>
            <div class="card border-0 shadow-sm rounded-lg p-5 text-center bg-white">
                <i class="fas fa-thumbs-up fa-3x text-success mb-3"></i>
                <h4 class="font-weight-bold text-dark mb-1">You are all caught up!</h4>
                <p class="text-muted mb-0">There are no pending homework assignments due at this time.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($toDoList as $hw): 
                    $isOverdue = (strtotime($hw['due_date']) < $nowTime);
                    $diff = strtotime($hw['due_date']) - $nowTime;
                    $days = floor($diff / 86400);
                    $hours = floor(($diff % 86400) / 3600);
                    $teacherName = $hw['creator_fullname'] ?: $hw['creator_username'];
                ?>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hw-card bg-white h-100">
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <?php if (!empty($hw['batch_name'])): ?>
                                        <span class="badge badge-primary px-3 py-1 font-weight-bold"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($hw['batch_name']) ?></span>
                                    <?php elseif (!empty($hw['subject_name'])): ?>
                                        <span class="badge badge-info px-3 py-1 font-weight-bold"><i class="fas fa-book mr-1"></i><?= htmlspecialchars($hw['subject_name']) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-3 py-1 font-weight-bold"><i class="fas fa-graduation-cap mr-1"></i>General</span>
                                    <?php endif; ?>

                                    <?php if ($isOverdue): ?>
                                        <span class="badge badge-danger px-2 py-1 font-weight-bold">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Past Deadline
                                        </span>
                                    <?php elseif ($days === 0): ?>
                                        <span class="badge badge-warning text-dark px-2 py-1 font-weight-bold">
                                            <i class="fas fa-stopwatch mr-1"></i>Due Today (<?= $hours ?>h left)
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-light border text-dark px-2 py-1 font-weight-bold">
                                            <i class="far fa-clock mr-1 text-primary"></i>Due in <?= $days ?> days
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h5 class="font-weight-bold text-dark mb-2">
                                    <a href="homework_view.php?id=<?= $hw['id'] ?>" class="text-dark text-decoration-none">
                                        <?= htmlspecialchars($hw['title']) ?>
                                    </a>
                                </h5>

                                <p class="text-muted small mb-3">
                                    Assigned by: <strong><?= htmlspecialchars($teacherName) ?></strong>
                                    <?php if (!empty($hw['creator_sub_role'])): ?>
                                        (<?= ucfirst($hw['creator_sub_role']) ?>)
                                    <?php endif; ?>
                                </p>

                                <!-- Date & Time Details Box -->
                                <div class="p-2 mb-3 bg-light rounded border text-muted small">
                                    <div class="mb-1">
                                        <i class="far fa-calendar-alt text-primary mr-1"></i>
                                        <strong>Assigned:</strong> <?= date('D, d M Y \a\t h:i A', strtotime($hw['assigned_date'])) ?>
                                    </div>
                                    <div>
                                        <i class="far fa-clock text-danger mr-1"></i>
                                        <strong>Deadline:</strong> <span class="font-weight-bold text-dark"><?= date('D, d M Y \a\t h:i A', strtotime($hw['due_date'])) ?></span>
                                    </div>
                                </div>

                                <!-- Feature Pills -->
                                <div class="mb-3">
                                    <?php if (!empty($hw['programming_lang']) && $hw['programming_lang'] !== 'none'): ?>
                                        <span class="badge-tech badge-code"><i class="fas fa-code mr-1"></i><?= ucfirst($hw['programming_lang']) ?> Problem</span>
                                    <?php endif; ?>
                                    <?php if (!empty($hw['attachment_path'])): ?>
                                        <span class="badge-tech badge-pic"><i class="fas fa-image mr-1"></i>Includes Diagram</span>
                                    <?php endif; ?>
                                    <?php if (strpos((string)$hw['description'], '$') !== false): ?>
                                        <span class="badge-tech badge-latex"><i class="fas fa-square-root-alt mr-1"></i>LaTeX Math</span>
                                    <?php endif; ?>
                                    <span class="badge badge-light border text-dark font-weight-bold"><?= (int)$hw['total_points'] ?> Marks</span>
                                </div>
                            </div>

                            <div class="pt-3 border-top d-flex justify-content-between align-items-center">
                                <span class="small font-weight-bold text-warning">
                                    <i class="fas fa-circle mr-1" style="font-size: 8px;"></i>Awaiting Turn-In
                                </span>
                                <a href="homework_view.php?id=<?= $hw['id'] ?>" class="btn btn-primary font-weight-bold rounded-pill px-4 shadow-sm">
                                    Solve & Submit <i class="fas fa-arrow-right ml-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- PANE 2: COMPLETED -->
    <div class="tab-pane fade" id="completedPane" role="tabpanel">
        <?php if (empty($completedList)): ?>
            <div class="card border-0 shadow-sm rounded-lg p-5 text-center bg-white">
                <i class="fas fa-tasks fa-3x text-muted mb-3 opacity-50"></i>
                <h4 class="font-weight-bold text-dark mb-1">No completed homework yet</h4>
                <p class="text-muted mb-0">Assignments you turn in will be listed here along with grades and instructor feedback.</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($completedList as $hw): 
                    $isReviewed = ($hw['submission_status'] === 'reviewed');
                    $teacherName = $hw['creator_fullname'] ?: $hw['creator_username'];
                ?>
                <div class="col-lg-6 mb-4">
                    <div class="card border-0 shadow-sm hw-card bg-white h-100">
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <?php if (!empty($hw['batch_name'])): ?>
                                        <span class="badge badge-primary px-3 py-1 font-weight-bold"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($hw['batch_name']) ?></span>
                                    <?php elseif (!empty($hw['subject_name'])): ?>
                                        <span class="badge badge-info px-3 py-1 font-weight-bold"><i class="fas fa-book mr-1"></i><?= htmlspecialchars($hw['subject_name']) ?></span>
                                    <?php endif; ?>

                                    <?php if ($isReviewed): ?>
                                        <span class="badge badge-success px-3 py-1 font-weight-bold" style="font-size: 0.85rem;">
                                            <i class="fas fa-check-double mr-1"></i>Score: <?= number_format((float)$hw['grade_points'], 1) ?> / <?= (int)$hw['total_points'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-info px-3 py-1 font-weight-bold">
                                            <i class="fas fa-check-circle mr-1"></i>Turned In (Pending Review)
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <h5 class="font-weight-bold text-dark mb-2">
                                    <a href="homework_view.php?id=<?= $hw['id'] ?>" class="text-dark text-decoration-none">
                                        <?= htmlspecialchars($hw['title']) ?>
                                    </a>
                                </h5>

                                <p class="text-muted small mb-3">
                                    Instructor: <strong><?= htmlspecialchars($teacherName) ?></strong>
                                </p>

                                <div class="p-2 mb-3 bg-light rounded border text-muted small">
                                    <div class="mb-1">
                                        <i class="fas fa-check-circle text-success mr-1"></i>
                                        <strong>Turned In:</strong> <?= date('D, d M Y \a\t h:i A', strtotime($hw['submitted_at'])) ?>
                                    </div>
                                    <div>
                                        <i class="far fa-clock text-muted mr-1"></i>
                                        <strong>Deadline:</strong> <?= date('D, d M Y \a\t h:i A', strtotime($hw['due_date'])) ?>
                                    </div>
                                </div>

                                <?php if (!empty($hw['teacher_feedback'])): ?>
                                <div class="p-3 bg-success bg-opacity-10 border border-success rounded mb-3">
                                    <h6 class="font-weight-bold text-success small mb-1"><i class="fas fa-comment-dots mr-1"></i>Teacher Remarks:</h6>
                                    <p class="small text-dark mb-0 font-italic">"<?= htmlspecialchars($hw['teacher_feedback']) ?>"</p>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="pt-3 border-top d-flex justify-content-between align-items-center">
                                <span class="small font-weight-bold text-success">
                                    <i class="fas fa-check mr-1"></i>Solution Submitted
                                </span>
                                <a href="homework_view.php?id=<?= $hw['id'] ?>" class="btn btn-outline-primary font-weight-bold rounded-pill px-4 shadow-sm">
                                    View Submission <i class="fas fa-eye ml-1"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- PANE 3: ALL -->
    <div class="tab-pane fade" id="allPane" role="tabpanel">
        <div class="row">
            <?php foreach ($allHomework as $hw): 
                $hasSubmitted = !empty($hw['submission_id']);
                $isReviewed = ($hw['submission_status'] === 'reviewed');
            ?>
            <div class="col-lg-6 mb-4">
                <div class="card border-0 shadow-sm hw-card bg-white h-100">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge badge-info px-3 py-1 font-weight-bold"><?= htmlspecialchars($hw['subject_name'] ?: 'General') ?></span>
                                <?php if ($hasSubmitted): ?>
                                    <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i>Turned In</span>
                                <?php elseif (strtotime($hw['due_date']) < $nowTime): ?>
                                    <span class="badge badge-danger px-2 py-1"><i class="fas fa-times-circle mr-1"></i>Past Due</span>
                                <?php else: ?>
                                    <span class="badge badge-warning text-dark px-2 py-1"><i class="fas fa-clock mr-1"></i>Due Soon</span>
                                <?php endif; ?>
                            </div>
                            <h5 class="font-weight-bold text-dark mb-2"><?= htmlspecialchars($hw['title']) ?></h5>
                            <div class="small text-muted mb-3">
                                <div><i class="far fa-calendar-alt text-primary mr-1"></i><strong>Assigned:</strong> <?= date('d M Y, h:i A', strtotime($hw['assigned_date'])) ?></div>
                                <div><i class="far fa-clock text-danger mr-1"></i><strong>Due:</strong> <?= date('d M Y, h:i A', strtotime($hw['due_date'])) ?></div>
                            </div>
                        </div>
                        <div class="pt-3 border-top text-right">
                            <a href="homework_view.php?id=<?= $hw['id'] ?>" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill px-4">
                                Open Assignment <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/student_footer.php'; ?>

<?php
// student/dashboard.php
require_once '../includes/student_header.php';

$student_id = $_SESSION['student_id'];

// Get all mapped exams for this student
$stmt = $pdo->prepare("
    SELECT e.*, 
    (SELECT COUNT(*) FROM attempts WHERE student_id = ? AND exam_id = e.id AND is_submitted = 1) as has_attempted,
    (SELECT COUNT(*) FROM answer_releases WHERE exam_id = e.id) as key_released
    FROM exams e
    JOIN enrolments en ON e.id = en.exam_id
    WHERE en.student_id = ? AND e.status != 'draft'
    ORDER BY e.scheduled_date ASC, e.scheduled_time ASC
");
$stmt->execute([$student_id, $student_id]);
$assigned_exams = $stmt->fetchAll();

$upcoming = [];
$active = [];
$completed = [];

$now = date('Y-m-d H:i:s');
foreach($assigned_exams as $ex) {
    if ($ex['has_attempted']) {
        $completed[] = $ex;
        continue;
    }
    
    $start_datetime = $ex['scheduled_date'] . ' ' . $ex['scheduled_time'];
    $end_datetime = date('Y-m-d H:i:s', strtotime($start_datetime . ' + ' . $ex['duration_minutes'] . ' minutes'));
    
    if ($ex['status'] === 'completed' || $now > $end_datetime) {
        $completed[] = $ex; // Missed exams default to trace sequence bounds
    } elseif ($now >= $start_datetime && $now <= $end_datetime) {
        $active[] = $ex; // Currently unrolling state
    } else {
        $upcoming[] = $ex; // Scheduled bound
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="font-weight-bold text-dark mb-1">Exam Dashboard</h2>
        <div class="text-muted"><i class="fas fa-university text-primary mr-1"></i> <?php echo h($current_student['institute_name'] ?? 'Main Campus'); ?></div>
    </div>
</div>

<style>
#dashboardTabs {
    background: #f1f5f9;
    padding: 6px;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
}
#dashboardTabs .nav-link {
    color: #475569 !important;
    font-weight: 600;
    border-radius: 999px;
    border: none;
    padding: 0.65rem 1.4rem !important;
    transition: all 0.2s ease;
    background: transparent;
}
#dashboardTabs .nav-link:hover {
    color: #0f172a !important;
    background: rgba(255, 255, 255, 0.8);
}
#dashboardTabs .nav-link.active {
    background: #1e3a8a !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(30, 58, 138, 0.25) !important;
    font-weight: 700;
}
#dashboardTabs .nav-link.active i {
    color: #ffffff !important;
}
#dashboardTabs .nav-link.active .badge {
    background-color: rgba(255, 255, 255, 0.25) !important;
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.4) !important;
}
@media (max-width: 767.98px) {
    #dashboardTabs {
        border-radius: 12px !important;
        padding: 4px;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        white-space: nowrap;
    }
    #dashboardTabs .nav-item {
        flex: 0 0 auto;
    }
    #dashboardTabs .nav-link {
        font-size: 0.85rem;
        padding: 8px 14px !important;
    }
}
</style>

<ul class="nav nav-pills nav-pills-scrollable nav-fill mb-4 rounded-pill shadow-sm" id="dashboardTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active font-weight-bold rounded-pill transition px-4" id="active-tab" data-toggle="pill" href="#active" role="tab">
            <i class="fas fa-play-circle mr-2 <?php echo count($active)>0 ? 'text-danger animate-pulse' : 'text-primary'; ?>"></i>Live Exams
            <?php if(count($active)>0): ?><span class="badge badge-danger ml-2 px-2 py-1 rounded-pill animate-pulse"><?php echo count($active); ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link font-weight-bold rounded-pill transition px-4" id="upcoming-tab" data-toggle="pill" href="#upcoming" role="tab">
            <i class="fas fa-calendar-alt mr-2 text-info opacity-75"></i>Upcoming Exams
            <span class="badge badge-light border text-muted ml-2 px-2 py-1 rounded-pill"><?php echo count($upcoming); ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link font-weight-bold rounded-pill transition px-4" id="completed-tab" data-toggle="pill" href="#completed" role="tab">
            <i class="fas fa-check-double mr-2 text-success opacity-75"></i>Completed Exams
        </a>
    </li>
</ul>

<div class="tab-content" id="dashboardTabContent">
    
    <!-- Active Exams Layer -->
    <div class="tab-pane fade show active" id="active" role="tabpanel">
        <?php if(empty($active)): ?>
            <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
                <div class="card-body py-5">
                    <i class="fas fa-coffee fa-4x text-light mb-4 text-primary opacity-25"></i>
                    <h4 class="font-weight-bold text-dark">No live exams available.</h4>
                    <p class="text-muted mb-0">Check the Upcoming Exams tab for your schedule.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($active as $ex): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card border-0 shadow rounded-lg h-100 border-top border-danger overflow-hidden" style="border-top-width: 5px !important; background: linear-gradient(180deg, #fff 0%, #fff0f2 100%);">
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <span class="badge badge-danger px-3 py-1 shadow-sm rounded-pill font-weight-bold text-uppercase" style="letter-spacing:1px;"><i class="fas fa-circle mr-1 text-white animate-pulse" style="font-size:8px;vertical-align:middle;"></i> LIVE</span>
                                <span class="text-danger font-weight-bold px-2 py-1 bg-white rounded shadow-sm"><i class="fas fa-stopwatch mr-1"></i><?php echo $ex['duration_minutes']; ?>m</span>
                            </div>
                            <h4 class="font-weight-bold text-dark mb-3"><?php echo h($ex['title']); ?></h4>
                            
                            <div class="bg-white rounded p-3 mb-4 mt-auto border shadow-sm">
                                <p class="mb-1 small font-weight-bold text-muted text-uppercase">Ends At</p>
                                <p class="mb-0 font-weight-bold text-dark">
                                    <?php 
                                    $end_dt = date('H:i', strtotime($ex['scheduled_date'].' '.$ex['scheduled_time'].' + '.$ex['duration_minutes'].' minutes'));
                                    echo date('d M Y', strtotime($ex['scheduled_date'])) . ' strictly at ' . $end_dt; 
                                    ?>
                                </p>
                            </div>
                            
                            <a href="exam.php?id=<?php echo $ex['id']; ?>" class="btn btn-danger btn-lg font-weight-bold px-4 py-3 shadow-sm rounded-pill btn-block transition" onclick="return confirm('Are you sure you want to start this exam now? The timer will begin immediately.');">
                                Start Exam <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Upcoming Exams Layer -->
    <div class="tab-pane fade" id="upcoming" role="tabpanel">
        <?php if(empty($upcoming)): ?>
            <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
                <div class="card-body py-5">
                    <i class="fas fa-calendar-check fa-4x text-light mb-4"></i>
                    <h4 class="font-weight-bold text-dark">No Upcoming Exams</h4>
                    <p class="text-muted mb-0">You have no upcoming exams scheduled at the moment.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach($upcoming as $ex): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card border-0 shadow-sm rounded-lg h-100 border-top border-primary bg-white" style="border-top-width: 4px !important;">
                        <div class="card-body p-4 d-flex flex-column">
                            <h5 class="font-weight-bold text-dark mb-4 border-bottom pb-3"><?php echo h($ex['title']); ?></h5>
                            
                            <div class="mb-4 text-muted bg-light p-3 rounded border">
                                <p class="mb-2"><i class="fas fa-calendar-alt mr-2 text-primary opacity-75 fa-fw"></i> Date: <strong class="text-dark"><?php echo date('d M Y', strtotime($ex['scheduled_date'])); ?></strong></p>
                                <p class="mb-2"><i class="fas fa-clock mr-2 text-primary opacity-75 fa-fw"></i> Time: <strong class="text-dark"><?php echo date('H:i', strtotime($ex['scheduled_time'])); ?></strong></p>
                                <p class="mb-0"><i class="fas fa-hourglass-start mr-2 text-primary opacity-75 fa-fw"></i> Duration: <strong class="text-dark"><?php echo $ex['duration_minutes']; ?> mins</strong></p>
                            </div>
                            
                            <button class="btn btn-light font-weight-bold px-4 py-3 border-2 rounded-pill btn-block text-muted disabled mt-auto shadow-sm" style="cursor:not-allowed;" title="You cannot start this exam yet.">
                                <i class="fas fa-lock mr-2"></i>Not Started
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Completed Exams Layer -->
    <div class="tab-pane fade" id="completed" role="tabpanel">
        <?php if(empty($completed)): ?>
            <div class="card shadow-sm border-0 rounded-lg p-5 text-center bg-white mb-4">
                <div class="card-body py-5">
                    <i class="fas fa-archive fa-4x text-light mb-4 opacity-50"></i>
                    <h4 class="font-weight-bold text-dark">No Completed Exams</h4>
                    <p class="text-muted mb-0">Your completed exams will appear here.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-lg overflow-hidden bg-white">
                <div class="table-responsive p-3">
                    <table class="table table-hover table-bordered mb-0">
                        <thead class="bg-light text-muted small text-uppercase">
                            <tr>
                                <th class="border-0 px-4 py-3 rounded-top-left">Exam Title</th>
                                <th class="border-0 text-center py-3">Date</th>
                                <th class="border-0 text-center py-3">Status</th>
                                <th class="border-0 text-right px-4 py-3 rounded-top-right">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($completed as $ex): ?>
                            <tr>
                                <td class="align-middle font-weight-bold text-dark px-4 py-3"><?php echo h($ex['title']); ?></td>
                                <td class="align-middle text-center text-muted font-weight-500 py-3"><i class="fas fa-calendar-check mr-2 opacity-50"></i><?php echo date('d M Y', strtotime($ex['scheduled_date'])); ?></td>
                                <td class="align-middle text-center py-3">
                                    <?php if($ex['has_attempted']): ?>
                                        <span class="badge badge-success px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-check-circle mr-1"></i>Completed</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-3 py-1 shadow-sm rounded-pill"><i class="fas fa-times-circle mr-1"></i>Missed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-right px-4 py-3">
                                    <?php if($ex['has_attempted']): ?>
                                        <?php if($ex['key_released']): ?>
                                            <a href="review.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-sm btn-outline-success font-weight-bold rounded-pill shadow-sm px-4 py-2 border-2 transition"><i class="fas fa-file-invoice mr-2"></i>View Report & Key</a>
                                        <?php else: ?>
                                            <a href="review.php?exam_id=<?php echo $ex['id']; ?>" class="btn btn-sm btn-outline-primary font-weight-bold rounded-pill shadow-sm px-4 py-2 border-2 transition"><i class="fas fa-hourglass-half mr-2"></i>View Submission (Result Awaited)</a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small font-weight-bold font-italic"><i class="fas fa-ban mr-1 opacity-50"></i>Missed Exam</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function(){
    $('#dashboardTabs a[data-toggle="pill"]').on('shown.bs.tab', function (e) {
        localStorage.setItem('studentDashboardTabNavMappingPoint', $(e.target).attr('href'));
    });
    
    var lastTab = localStorage.getItem('studentDashboardTabNavMappingPoint');
    if (lastTab && $('#dashboardTabs a[href="' + lastTab + '"]').length) {
        $('#dashboardTabs a[href="' + lastTab + '"]').tab('show');
    }
});
</script>

<?php require_once '../includes/student_footer.php'; ?>

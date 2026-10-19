<?php
// admin/students/create.php
require_once '../../includes/admin_header.php';

require_admin_feature('students');

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY CAST(name AS UNSIGNED)")->fetchAll();
$boards = $pdo->query("SELECT id, name FROM boards ORDER BY name")->fetchAll();
$all_admins = is_super_admin() ? $pdo->query("SELECT id, username, institute_name FROM admins ORDER BY institute_name ASC")->fetchAll() : [];

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $class_id = intval($_POST['class_id'] ?? 0);
    $board_id = intval($_POST['board_id'] ?? 0);
    $enrollment_date = trim($_POST['enrollment_date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $enrollment_date)) {
        $enrollment_date = date('Y-m-d');
    }
    $target_admin_id = (!empty($_POST['admin_id']) && is_super_admin()) ? (int)$_POST['admin_id'] : get_effective_admin_id();
    
    if (!$name || !$class_id || !$board_id) {
        $error = "Name, Class, and Board are required.";
    } else {
        $c_name = array_values(array_filter($classes, function($c) use($class_id) { return $c['id'] == $class_id; }))[0]['name'] ?? '';
        $b_name = array_values(array_filter($boards, function($b) use($board_id) { return $b['id'] == $board_id; }))[0]['name'] ?? '';
        
        $firstname = strtolower(explode(' ', $name)[0]);
        $b_abbr = preg_replace('/[^a-z]/', '', strtolower($b_name));
        $username = "";
        
        for($i=0; $i<10; $i++) {
            $rand = mt_rand(100, 999);
            $temp_user = "{$firstname}.{$c_name}{$b_abbr}{$rand}";
            $stmt = $pdo->prepare("SELECT id FROM students WHERE username = ?");
            $stmt->execute([$temp_user]);
            if(!$stmt->fetch()) {
                $username = $temp_user;
                break;
            }
        }
        
        if (!$username) $error = "Could not generate unique username.";
        else {
            $plain_password = generate_random_password(8);
            $hashed = password_hash($plain_password, PASSWORD_BCRYPT);
            
            $stmt = $pdo->prepare("INSERT INTO students (full_name, email, class_id, board_id, username, password_hash, plain_password, admin_id, enrollment_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$name, $email, $class_id, $board_id, $username, $hashed, $plain_password, $target_admin_id, $enrollment_date])) {
                $success = [
                    'name' => $name,
                    'username' => $username,
                    'password' => $plain_password,
                    'enrollment_date' => $enrollment_date
                ];
            } else {
                $error = "Database integrity error. Failed to save student profile.";
            }
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h2 class="font-weight-bold text-dark">Register New Student</h2>
    <a href="index.php" class="btn btn-outline-secondary font-weight-bold rounded-pill px-4 shadow-sm"><i class="fas fa-list mr-2"></i>Students Roster</a>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger shadow-sm py-3 rounded-lg"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo h($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success shadow-sm rounded-lg p-5 mb-4 border-success bg-gradient border-0" style="background-color: #f0fdf4 !important; border-left: 5px solid #22c55e !important;">
        <h4 class="alert-heading font-weight-bold text-success mb-3"><i class="fas fa-check-circle mr-2 fa-lg"></i>Registration Verified</h4>
        <p class="text-dark">The system generated encrypted credential arrays natively for <strong><?php echo h($success['name']); ?></strong>.</p>
        <hr class="border-success opacity-25 my-4">
        <div class="row bg-white p-4 rounded shadow-sm mb-4">
            <div class="col-sm-4 mb-3 mb-sm-0 bg-light py-3 px-4 rounded border-right">
                <small class="text-uppercase text-muted font-weight-bold d-block mb-1">Assigned Username (ID)</small>
                <strong class="h4 text-dark font-family-monospace" id="genUsername"><?php echo h($success['username']); ?></strong>
            </div>
            <div class="col-sm-4 mb-3 mb-sm-0 bg-light py-3 px-4 rounded border-right">
                <small class="text-uppercase text-muted font-weight-bold d-block mb-1">Generated Password</small>
                <strong class="h4 text-primary font-family-monospace" id="genPassword"><?php echo h($success['password']); ?></strong>
            </div>
            <div class="col-sm-4 bg-light py-3 px-4 rounded">
                <small class="text-uppercase text-muted font-weight-bold d-block mb-1">Fee Ledger Start Month</small>
                <strong class="h5 text-success font-weight-bold"><?php echo date('F Y', strtotime($success['enrollment_date'])); ?></strong>
                <small class="d-block text-muted"><?php echo date('d M Y', strtotime($success['enrollment_date'])); ?></small>
            </div>
        </div>
        <button type="button" class="btn btn-success btn-lg font-weight-bold shadow rounded-pill px-5 transition" id="copyBtn" onclick="copyCreds()">
            <i class="fas fa-copy mr-2"></i>Copy Access Credentials
        </button>
        <script>
        function copyCreds() {
            let u = document.getElementById('genUsername').innerText;
            let p = document.getElementById('genPassword').innerText;
            let text = "Online Exam Login Portal\nURL: " + window.location.origin + "/student/login.php\nUsername: " + u + "\nPassword: " + p;
            navigator.clipboard.writeText(text).then(function() {
                let current = $('#copyBtn').html();
                $('#copyBtn').html('<i class="fas fa-check mr-2"></i>Securely Copied!');
                setTimeout(() => $('#copyBtn').html(current), 2000);
            });
        }
        </script>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0 rounded-lg">
    <div class="card-body p-4 p-md-5">
        <form method="POST" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo h(generate_csrf_token()); ?>">
            
            <div class="row">
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Registrant Full Name</label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-id-card text-muted"></i></span></div>
                        <input type="text" name="full_name" class="form-control form-control-lg bg-light border-0" required autofocus>
                    </div>
                </div>
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Email Address <small>(Optional Notification Relay)</small></label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-envelope text-muted"></i></span></div>
                        <input type="email" name="email" class="form-control form-control-lg bg-light border-0">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Scholastic Class</label>
                    <select name="class_id" class="form-control custom-select form-control-lg bg-light border-0 shadow-sm" required>
                        <option value="">Choose designated class...</option>
                        <?php foreach($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>">Class <?php echo h($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Regional Board Code</label>
                    <select name="board_id" class="form-control custom-select form-control-lg bg-light border-0 shadow-sm" required>
                        <option value="">Choose assigned board...</option>
                        <?php foreach($boards as $b): ?>
                            <option value="<?php echo $b['id']; ?>"><?php echo h($b['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">
                        <i class="fas fa-calendar-alt text-primary mr-1"></i> Joining / Enrollment Date
                    </label>
                    <div class="input-group shadow-sm">
                        <div class="input-group-prepend"><span class="input-group-text bg-white border-0"><i class="fas fa-calendar-day text-muted"></i></span></div>
                        <input type="date" name="enrollment_date" class="form-control form-control-lg bg-light border-0" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <small class="form-text text-muted mt-2">
                        <i class="fas fa-history text-info mr-1"></i>
                        <strong>Offline Students Back-Dating:</strong> For existing offline students, select their original enrollment month/date. Fee tracking and dues will accurately start from this month.
                    </small>
                </div>
            </div>

            <?php if (is_super_admin()): ?>
            <div class="row">
                <div class="col-md-6 form-group mb-4">
                    <label class="font-weight-bold text-muted small text-uppercase">Assign to Institute / Admin</label>
                    <select name="admin_id" class="form-control custom-select form-control-lg bg-light border-0 shadow-sm">
                        <?php foreach($all_admins as $adm): ?>
                            <option value="<?php echo $adm['id']; ?>" <?php echo $adm['id'] == $_SESSION['admin_id'] ? 'selected' : ''; ?>>
                                <?php echo h(!empty($adm['institute_name']) ? $adm['institute_name'] : $adm['username']); ?> (<?= h($adm['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-light border shadow-sm rounded-lg py-2 px-3 mb-4 text-muted small">
                <i class="fas fa-university text-primary mr-2"></i>Student will be registered under your institute: <strong><?= h(get_current_admin_institute()) ?></strong>
            </div>
            <?php endif; ?>
            
            <div class="mt-4 border-top pt-4">
                <button type="submit" class="btn btn-primary font-weight-bold px-5 py-3 shadow rounded-pill btn-lg w-100 w-md-auto transition"><i class="fas fa-user-plus mr-2 opacity-75"></i>Provision Profile</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/admin_footer.php'; ?>

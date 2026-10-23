<?php
// docker/migrate.php
// Safe, non-destructive migration script that preserves all existing data.

require_once __DIR__ . '/../config/db.php';

echo "[Migration] Checking database schema for updates...\n";

// Auto-detect if base tables (students, exams) are missing. If so, bootstrap from docker/init.sql
$checkStmt = $pdo->query("SHOW TABLES LIKE 'students'");
$hasStudents = $checkStmt->fetch();

if (!$hasStudents) {
    echo "[Migration] Core base tables not detected. Initializing schema from docker/init.sql...\n";
    $initSqlPaths = [
        __DIR__ . '/init.sql',
        __DIR__ . '/../docker/init.sql',
        '/var/www/html/docker/init.sql'
    ];
    $loaded = false;
    foreach ($initSqlPaths as $path) {
        if (file_exists($path)) {
            $initContent = file_get_contents($path);
            if (!empty($initContent)) {
                try {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                    $pdo->exec($initContent);
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                    echo "[Migration] Base schema successfully imported from $path.\n";
                    $loaded = true;
                    break;
                } catch (\Throwable $e) {
                    echo "  [Warning] Direct import failed: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    if (!$loaded) {
        echo "  [Warning] Could not auto-import init.sql. Proceeding with migrations...\n";
    }
}

$migrations = [
    // Ensure core tables exist if not present
    "CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_type ENUM('admin', 'student') NOT NULL,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS topics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
    )",

    "CREATE TABLE IF NOT EXISTS answer_releases (
        id INT PRIMARY KEY AUTO_INCREMENT,
        exam_id INT UNIQUE,
        released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        released_by_admin_id INT,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
        FOREIGN KEY (released_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
    )",

    "CREATE TABLE IF NOT EXISTS report_links (
        id INT PRIMARY KEY AUTO_INCREMENT,
        exam_id INT,
        token VARCHAR(64) UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME,
        FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
    )",

    "CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(200) NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(token_hash),
        INDEX(email)
    )",

    // Non-destructive column additions
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS email VARCHAR(200) NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS two_factor_secret VARCHAR(64) NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) DEFAULT 0",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS two_factor_backup_codes TEXT NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS login_attempts INT DEFAULT 0",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) NULL",

    "UPDATE admins SET email = 'shreyan_sncc@zohomail.in' WHERE (email IS NULL OR email = '') AND username = 'admin'",

    "ALTER TABLE students ADD COLUMN IF NOT EXISTS login_attempts INT DEFAULT 0",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS session_token VARCHAR(64) NULL",

    "ALTER TABLE questions ADD COLUMN IF NOT EXISTS subject_id INT NULL",
    "ALTER TABLE questions ADD COLUMN IF NOT EXISTS topic_id INT NULL",
    "ALTER TABLE questions ADD COLUMN IF NOT EXISTS marks INT DEFAULT 1",

    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS exam_type ENUM('regular', 'weekly_mock') DEFAULT 'regular'",

    "ALTER TABLE attempts ADD COLUMN IF NOT EXISTS total_marks INT DEFAULT NULL",
    "UPDATE attempts a JOIN (SELECT eq.exam_id, COALESCE(SUM(q.marks), COUNT(eq.id)) as exam_total_marks FROM exam_questions eq JOIN questions q ON eq.question_id = q.id GROUP BY eq.exam_id) m ON a.exam_id = m.exam_id SET a.total_marks = m.exam_total_marks WHERE a.total_marks IS NULL",
    "UPDATE attempts SET total_marks = total_questions WHERE total_marks IS NULL",

    "CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES 
        ('image_storage_driver', 'local'),
        ('image_compress_quality', '75'),
        ('image_max_width', '1200'),
        ('cloudinary_cloud_name', ''),
        ('cloudinary_api_key', ''),
        ('cloudinary_api_secret', ''),
        ('cloudinary_folder', 'questions'),
        ('openrouter_api_key', ''),
        ('openrouter_model', 'google/gemini-2.0-flash-001'),
        ('openrouter_temperature', '0.7'),
        ('fee_default_monthly_amount', '1500'),
        ('fee_currency_symbol', '₹'),
        ('fee_last_midmonth_reminder', ''),
        ('fee_last_endmonth_reminder', '')",

    "CREATE TABLE IF NOT EXISTS fee_structures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL UNIQUE,
        monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    )",

    "INSERT IGNORE INTO fee_structures (class_id, monthly_fee)
     SELECT id, CASE 
         WHEN CAST(name AS UNSIGNED) <= 8 THEN 1000.00
         WHEN CAST(name AS UNSIGNED) = 9 THEN 1200.00
         WHEN CAST(name AS UNSIGNED) = 10 THEN 1500.00
         WHEN CAST(name AS UNSIGNED) = 11 THEN 1800.00
         WHEN CAST(name AS UNSIGNED) >= 12 THEN 2000.00
         ELSE 1200.00
     END FROM classes",

    "CREATE TABLE IF NOT EXISTS student_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        fee_month TINYINT NOT NULL,
        fee_year SMALLINT NOT NULL,
        amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('paid', 'unpaid', 'partial') NOT NULL DEFAULT 'unpaid',
        payment_date DATE NULL,
        payment_method VARCHAR(50) NULL DEFAULT 'Cash',
        transaction_ref VARCHAR(100) NULL,
        receipt_number VARCHAR(50) NULL,
        notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_student_month_year (student_id, fee_month, fee_year),
        INDEX idx_student (student_id),
        INDEX idx_status (status),
        INDEX idx_month_year (fee_year, fee_month),
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    )",

    // --- Super Admin & Multi-Tenant Admin Architecture Migrations ---
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS role ENUM('super_admin', 'admin') NOT NULL DEFAULT 'admin'",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS institute_name VARCHAR(200) NOT NULL DEFAULT 'Main Campus'",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS features TEXT NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS created_by INT NULL",
    "UPDATE admins SET role = 'super_admin', institute_name = 'Main Campus', is_active = 1 WHERE id = 1",

    "ALTER TABLE students ADD COLUMN IF NOT EXISTS admin_id INT NULL",
    "UPDATE students SET admin_id = 1 WHERE admin_id IS NULL",

    "ALTER TABLE exams ADD COLUMN IF NOT EXISTS admin_id INT NULL",
    "UPDATE exams SET admin_id = 1 WHERE admin_id IS NULL",

    "ALTER TABLE questions ADD COLUMN IF NOT EXISTS admin_id INT NOT NULL DEFAULT 1",
    "UPDATE questions SET admin_id = 1 WHERE admin_id IS NULL",
    "UPDATE questions SET question_text = REPLACE(question_text, '/uploads/questions/qimg_', '/uploads/questions/admin_1/qimg_') WHERE question_text LIKE '%/uploads/questions/qimg_%' AND (admin_id = 1 OR admin_id IS NULL)",

    "ALTER TABLE fee_structures ADD COLUMN IF NOT EXISTS admin_id INT NOT NULL DEFAULT 1",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS cloudinary_settings TEXT NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS openrouter_settings TEXT NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS support_permission TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS support_permission_updated_at DATETIME NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS support_otp VARCHAR(64) NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS support_otp_expires_at DATETIME NULL",

    // --- Integrated Super Admin & Admin Messaging ---
    "CREATE TABLE IF NOT EXISTS admin_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sender (sender_id),
        INDEX idx_receiver (receiver_id),
        INDEX idx_conversation (sender_id, receiver_id, created_at),
        FOREIGN KEY (sender_id) REFERENCES admins(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES admins(id) ON DELETE CASCADE
    )",

    // --- Tenant Admin Billing & Payments ---
    "CREATE TABLE IF NOT EXISTS admin_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        billing_month TINYINT NOT NULL,
        billing_year SMALLINT NOT NULL,
        enrolled_students INT NOT NULL DEFAULT 0,
        plan_name VARCHAR(100) NOT NULL DEFAULT 'Institute Platform Subscription',
        amount_due DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('paid', 'pending', 'overdue') NOT NULL DEFAULT 'pending',
        due_date DATE NOT NULL,
        payment_date DATETIME NULL,
        payment_method VARCHAR(50) NULL DEFAULT 'UPI',
        transaction_ref VARCHAR(100) NULL,
        receipt_number VARCHAR(50) NULL,
        reminder_sent_at DATETIME NULL,
        reminder_count INT NOT NULL DEFAULT 0,
        notes TEXT NULL,
        recorded_by_admin_id INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_admin_period (admin_id, billing_month, billing_year),
        INDEX idx_admin (admin_id),
        INDEX idx_status (status),
        INDEX idx_period (billing_year, billing_month),
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    )",

    "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
        ('admin_default_monthly_fee', '1500.00'),
        ('admin_payment_due_day', '10'),
        ('super_admin_upi_id', 'shreyansir@okaxis'),
        ('super_admin_bank_details', 'State Bank of India | A/C: 38291048591 | IFSC: SBIN0001234')",

    // --- Student Enrollment Date & Fee Scoping ---
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS enrollment_date DATE NULL",
    "UPDATE students SET enrollment_date = DATE(created_at) WHERE enrollment_date IS NULL",

    // --- Admin Personal SMTP & Private Subjects/Topics Scoping ---
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS smtp_settings TEXT NULL",
    "ALTER TABLE subjects ADD COLUMN IF NOT EXISTS admin_id INT NOT NULL DEFAULT 1",
    "ALTER TABLE topics ADD COLUMN IF NOT EXISTS admin_id INT NOT NULL DEFAULT 1",
    "UPDATE topics t JOIN subjects s ON t.subject_id = s.id SET t.admin_id = s.admin_id WHERE t.admin_id IS NULL OR t.admin_id = 1",

    // --- Authentication Security Hardening: IP Rate Limiting & Persistent 2FA Lockout ---
    "CREATE TABLE IF NOT EXISTS login_rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL,
        action VARCHAR(50) NOT NULL DEFAULT 'admin_login',
        attempts INT NOT NULL DEFAULT 1,
        last_attempt DATETIME NOT NULL,
        locked_until DATETIME NULL,
        UNIQUE KEY uq_ip_action (ip_address, action),
        INDEX idx_ip (ip_address),
        INDEX idx_locked (locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS two_factor_attempts INT DEFAULT 0",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS two_factor_locked_until DATETIME NULL",

    // --- Sub-Admin Roles & Hierarchy ---
    "ALTER TABLE admins MODIFY COLUMN role VARCHAR(30) NOT NULL DEFAULT 'admin'",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS sub_role VARCHAR(50) DEFAULT 'custom'",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS full_name VARCHAR(150) NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS salary DECIMAL(10, 2) NOT NULL DEFAULT 0.00",
    "ALTER TABLE admins ADD COLUMN IF NOT EXISTS parent_admin_id INT NULL",

    // --- Teaching Batches & Student Batch Assignments ---
    "CREATE TABLE IF NOT EXISTS batches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL DEFAULT 1,
        teacher_id INT NULL,
        name VARCHAR(150) NOT NULL,
        class_id INT NULL,
        board_id INT NULL,
        subject_id INT NULL,
        schedule_desc VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin (admin_id),
        INDEX idx_teacher (teacher_id),
        INDEX idx_class (class_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS batch_students (
        id INT AUTO_INCREMENT PRIMARY KEY,
        batch_id INT NOT NULL,
        student_id INT NOT NULL,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_batch_student (batch_id, student_id),
        INDEX idx_batch (batch_id),
        INDEX idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // --- Integrated Staff & Sub-Admin Payroll System ---
    "CREATE TABLE IF NOT EXISTS payroll_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sub_admin_id INT NOT NULL,
        admin_id INT NOT NULL,
        paid_by_id INT NOT NULL,
        salary_month TINYINT NOT NULL,
        salary_year SMALLINT NOT NULL,
        base_salary DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        bonus DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        deductions DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        net_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'bank_transfer',
        reference_no VARCHAR(100) NULL,
        payment_date DATE NOT NULL,
        receipt_no VARCHAR(60) NOT NULL,
        notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_subadmin_month_year (sub_admin_id, salary_month, salary_year),
        INDEX idx_subadmin (sub_admin_id),
        INDEX idx_admin (admin_id),
        INDEX idx_month_year (salary_year, salary_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // --- Homework & Assignments System ---
    "CREATE TABLE IF NOT EXISTS homework (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL DEFAULT 1,
        created_by INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description LONGTEXT NULL,
        class_id INT NULL,
        board_id INT NULL,
        subject_id INT NULL,
        batch_id INT NULL,
        assigned_date DATETIME NOT NULL,
        due_date DATETIME NOT NULL,
        total_points INT NOT NULL DEFAULT 100,
        attachment_path VARCHAR(255) NULL,
        programming_lang VARCHAR(50) NULL DEFAULT 'none',
        starter_code TEXT NULL,
        solution_guide TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_admin (admin_id),
        INDEX idx_created_by (created_by),
        INDEX idx_class (class_id),
        INDEX idx_board (board_id),
        INDEX idx_subject (subject_id),
        INDEX idx_batch (batch_id),
        INDEX idx_due (due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS homework_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        homework_id INT NOT NULL,
        student_id INT NOT NULL,
        submission_text LONGTEXT NULL,
        submitted_code LONGTEXT NULL,
        attachment_path VARCHAR(255) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'submitted',
        grade_points DECIMAL(5, 2) NULL,
        teacher_feedback TEXT NULL,
        submitted_at DATETIME NOT NULL,
        graded_at DATETIME NULL,
        graded_by INT NULL,
        UNIQUE KEY uq_homework_student (homework_id, student_id),
        INDEX idx_homework (homework_id),
        INDEX idx_student (student_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Ensure all existing full tenant admins get the new module features (batches, sub_admins, payroll, homework, coding)
    "UPDATE admins SET features = REPLACE(features, '\"ai_settings\"]', '\"ai_settings\",\"batches\",\"sub_admins\",\"payroll\",\"homework\",\"coding\"]') WHERE features LIKE '%ai_settings%' AND features NOT LIKE '%coding%'",
    "UPDATE admins SET features = REPLACE(features, '\"homework\"]', '\"homework\",\"coding\"]') WHERE features LIKE '%homework%' AND features NOT LIKE '%coding%'",

    // --- Online Coding Platform & Competitions ---
    "INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('coding_platform_enabled', '1')",

    "CREATE TABLE IF NOT EXISTS coding_problems (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL DEFAULT 1,
        created_by INT NOT NULL DEFAULT 1,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        difficulty ENUM('Easy', 'Medium', 'Hard') NOT NULL DEFAULT 'Easy',
        description LONGTEXT NOT NULL,
        input_format TEXT NULL,
        output_format TEXT NULL,
        constraints TEXT NULL,
        sample_input TEXT NULL,
        sample_output TEXT NULL,
        sample_explanation TEXT NULL,
        time_limit_ms INT NOT NULL DEFAULT 2000,
        memory_limit_mb INT NOT NULL DEFAULT 256,
        allowed_languages VARCHAR(255) NOT NULL DEFAULT 'python,java,c,cpp',
        starter_code_python TEXT NULL,
        starter_code_java TEXT NULL,
        starter_code_c TEXT NULL,
        starter_code_cpp TEXT NULL,
        total_points INT NOT NULL DEFAULT 100,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_admin (admin_id),
        INDEX idx_difficulty (difficulty),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS coding_test_cases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        problem_id INT NOT NULL,
        input_data LONGTEXT NULL,
        expected_output LONGTEXT NOT NULL,
        is_sample TINYINT(1) NOT NULL DEFAULT 0,
        is_hidden TINYINT(1) NOT NULL DEFAULT 1,
        points INT NOT NULL DEFAULT 10,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_problem (problem_id),
        FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS coding_competitions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL DEFAULT 1,
        created_by INT NOT NULL DEFAULT 1,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        rules TEXT NULL,
        class_id INT NULL,
        board_id INT NULL,
        batch_id INT NULL,
        start_time DATETIME NOT NULL,
        end_time DATETIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_admin (admin_id),
        INDEX idx_class (class_id),
        INDEX idx_batch (batch_id),
        INDEX idx_window (start_time, end_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS coding_competition_problems (
        id INT AUTO_INCREMENT PRIMARY KEY,
        competition_id INT NOT NULL,
        problem_id INT NOT NULL,
        order_index INT NOT NULL DEFAULT 0,
        points INT NOT NULL DEFAULT 100,
        UNIQUE KEY uq_comp_prob (competition_id, problem_id),
        INDEX idx_comp (competition_id),
        INDEX idx_prob (problem_id),
        FOREIGN KEY (competition_id) REFERENCES coding_competitions(id) ON DELETE CASCADE,
        FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS coding_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        competition_id INT NULL,
        problem_id INT NOT NULL,
        student_id INT NOT NULL,
        language VARCHAR(20) NOT NULL,
        source_code LONGTEXT NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
        score DECIMAL(5, 2) NOT NULL DEFAULT 0.00,
        passed_test_cases INT NOT NULL DEFAULT 0,
        total_test_cases INT NOT NULL DEFAULT 0,
        execution_time_ms INT NOT NULL DEFAULT 0,
        memory_kb INT NOT NULL DEFAULT 0,
        compile_output TEXT NULL,
        submitted_at DATETIME NOT NULL,
        INDEX idx_comp (competition_id),
        INDEX idx_prob (problem_id),
        INDEX idx_student (student_id),
        INDEX idx_status (status),
        INDEX idx_comp_student (competition_id, student_id),
        FOREIGN KEY (problem_id) REFERENCES coding_problems(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // High-Concurrency Index Optimizations (Prevents Table Scans & Locks Under Peak Student Load)
    "ALTER TABLE attempts ADD INDEX idx_student_exam (student_id, exam_id)",
    "ALTER TABLE attempts ADD INDEX idx_exam_submitted (exam_id, is_submitted)",
    "ALTER TABLE attempt_answers ADD INDEX idx_attempt_question (attempt_id, question_id)",
    "ALTER TABLE exam_questions ADD INDEX idx_exam_order (exam_id, order_no, question_id)",
    "ALTER TABLE exams ADD INDEX idx_status_schedule (status, scheduled_date, scheduled_time)",
    "ALTER TABLE exams ADD INDEX idx_admin_status (admin_id, status)",
    "ALTER TABLE questions ADD INDEX idx_admin_archived (admin_id, is_archived)",
    "ALTER TABLE homework ADD INDEX idx_admin_active (admin_id, is_active)",
    "ALTER TABLE homework ADD INDEX idx_batch_active (batch_id, is_active)",
    "ALTER TABLE batch_students ADD INDEX idx_student (student_id)",

    // --- Student Profile Expansion (Phone, Parent Info, Address, DOB, Gender) ---
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS phone VARCHAR(25) NULL",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS parent_name VARCHAR(150) NULL",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(25) NULL",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS dob DATE NULL",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS address TEXT NULL",
    "ALTER TABLE students ADD COLUMN IF NOT EXISTS gender VARCHAR(20) NULL",

    // --- Temporary Student Enrollment Links & Submissions ---
    "CREATE TABLE IF NOT EXISTS enrollment_links (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(64) NOT NULL UNIQUE,
        title VARCHAR(200) NOT NULL,
        admin_id INT NOT NULL DEFAULT 1,
        class_id INT NULL,
        board_id INT NULL,
        batch_id INT NULL,
        max_uses INT NOT NULL DEFAULT 0,
        used_count INT NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        require_approval TINYINT(1) NOT NULL DEFAULT 0,
        collect_parent_info TINYINT(1) NOT NULL DEFAULT 1,
        collect_address TINYINT(1) NOT NULL DEFAULT 0,
        custom_welcome_message TEXT NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_admin_status (admin_id, is_active),
        INDEX idx_expires (expires_at),
        FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
        FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE SET NULL,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    "CREATE TABLE IF NOT EXISTS enrollment_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        link_id INT NOT NULL,
        student_id INT NOT NULL,
        ip_address VARCHAR(45) NULL,
        status ENUM('approved', 'pending', 'rejected') NOT NULL DEFAULT 'approved',
        submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_link (link_id),
        INDEX idx_student (student_id),
        FOREIGN KEY (link_id) REFERENCES enrollment_links(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

    // Clean up erroneous admin_payments generated for employees/subadmins
    "DELETE FROM admin_payments WHERE admin_id IN (SELECT id FROM admins WHERE role = 'sub_admin' OR (parent_admin_id IS NOT NULL AND parent_admin_id != id))"
];

$successCount = 0;
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
} catch (\Throwable $e) {}

foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        $successCount++;
    } catch (\PDOException $e) {
        echo "  [Notice] " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
} catch (\Throwable $e) {}

echo "[Migration] Completed successfully ($successCount checks executed). Existing data preserved.\n";

CREATE DATABASE IF NOT EXISTS online_exam;
USE online_exam;

CREATE TABLE boards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL
);

CREATE TABLE classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(10) NOT NULL
);

CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    login_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE questions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT,
    board_id INT,
    question_text TEXT,
    option1 VARCHAR(500),
    option2 VARCHAR(500),
    option3 VARCHAR(500),
    option4 VARCHAR(500),
    correct_option TINYINT(1),
    is_archived TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
);

CREATE TABLE exams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    class_id INT,
    board_id INT,
    status ENUM('draft', 'published', 'completed') DEFAULT 'draft',
    scheduled_date DATE,
    scheduled_time TIME,
    duration_minutes INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    published_at DATETIME NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
);

CREATE TABLE exam_questions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    exam_id INT,
    question_id INT,
    order_no INT,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(150),
    email VARCHAR(200),
    class_id INT,
    board_id INT,
    username VARCHAR(80) UNIQUE,
    password_hash VARCHAR(255),
    plain_password VARCHAR(50),
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
);

CREATE TABLE enrolments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    exam_id INT,
    student_id INT,
    enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (exam_id, student_id),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    exam_id INT,
    student_id INT,
    started_at DATETIME,
    submitted_at DATETIME NULL,
    score INT DEFAULT 0,
    total_questions INT,
    is_submitted TINYINT(1) DEFAULT 0,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE attempt_answers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    attempt_id INT,
    question_id INT,
    chosen_option TINYINT(1),
    FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE answer_releases (
    id INT PRIMARY KEY AUTO_INCREMENT,
    exam_id INT UNIQUE,
    released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    released_by_admin_id INT,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (released_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

CREATE TABLE report_links (
    id INT PRIMARY KEY AUTO_INCREMENT,
    exam_id INT,
    token VARCHAR(64) UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

-- Seed Data --
INSERT INTO boards (name) VALUES ('WB Board'), ('CBSE'), ('ICSE'), ('ISC');
INSERT INTO classes (name) VALUES ('8'), ('9'), ('10'), ('11'), ('12');

INSERT INTO admins (username, password_hash) VALUES ('admin', '$2y$10$nBAT.IGTSIKfceyQYBxydefXK2W90KMUQ1U5Dy.xMP.E3BKwKc3V2');


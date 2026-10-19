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

INSERT INTO students (full_name, email, class_id, board_id, username, password_hash, plain_password) VALUES
('Rahul Sharma', 'rahul@example.com', 3, 2, 'rahul.10cbse101', '$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6', 'Pass123'),
('Priya Singh', 'priya@example.com', 3, 2, 'priya.10cbse102', '$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6', 'Pass123'),
('Amit Patel', 'amit@example.com', 3, 2, 'amit.10cbse103', '$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6', 'Pass123'),
('Sneha Roy', 'sneha@example.com', 3, 2, 'sneha.10cbse104', '$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6', 'Pass123'),
('Vikram Gupta', 'vikram@example.com', 3, 2, 'vikram.10cbse105', '$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6', 'Pass123');

INSERT INTO questions (class_id, board_id, question_text, option1, option2, option3, option4, correct_option) VALUES
(3, 2, 'Which of the following is a non-metal that remains liquid at room temperature?', 'Phosphorus', 'Bromine', 'Chlorine', 'Helium', 2),
(3, 2, 'The SI unit of electric current is:', 'Volt', 'Ohm', 'Ampere', 'Coulomb', 3),
(3, 2, 'What is the chemical name of baking soda?', 'Sodium carbonate', 'Calcium carbonate', 'Sodium hydrogen carbonate', 'Calcium hydroxide', 3),
(3, 2, 'A solution turns red litmus blue, its pH is likely to be:', '1', '4', '5', '10', 4),
(3, 2, 'Which of the following gases is used for the storage of fresh sample of an oil for a long time?', 'Carbon dioxide or oxygen', 'Nitrogen or oxygen', 'Carbon dioxide or helium', 'Helium or nitrogen', 4),
(3, 2, 'Which instrument is used to measure electric voltage?', 'Ammeter', 'Galvanometer', 'Voltmeter', 'Potentiometer', 3),
(3, 2, 'An object is placed at a distance of 10 cm from a convex mirror of focal length 15 cm. Find the position of the image.', '6 cm behind the mirror', '10 cm behind the mirror', '6 cm in front of the mirror', '10 cm in front of the mirror', 1),
(3, 2, 'The human eye forms the image of an object at its:', 'Cornea', 'Iris', 'Pupil', 'Retina', 4),
(3, 2, 'The least distance of distinct vision for a young adult with normal vision is about:', '25 m', '2.5 cm', '25 cm', '2.5 m', 3),
(3, 2, 'Which of the following is a plant hormone?', 'Insulin', 'Thyroxin', 'Oestrogen', 'Cytokinin', 4),
(3, 2, 'The gap between two neurons is called a:', 'Dendrite', 'Synapse', 'Axon', 'Impulse', 2),
(3, 2, 'In a food chain, the third trophic level is always occupied by:', 'Carnivores', 'Herbivores', 'Decomposers', 'Producers', 1),
(3, 2, 'Which of the following is an example of a homologous organ?', 'Our arm and a dog''s fore-leg', 'Our teeth and an elephant''s tusks', 'Potato and runners of grass', 'All of the above', 4),
(3, 2, 'The male reproductive parts of a flower are called:', 'Sepals', 'Petals', 'Stamens', 'Carpels', 3),
(3, 2, 'An alloy of copper and zinc is called:', 'Bronze', 'Brass', 'Solder', 'Steel', 2);

INSERT INTO exams (title, class_id, board_id, status, scheduled_date, scheduled_time, duration_minutes, published_at) VALUES
('Class 10 CBSE Science Mock Test', 3, 2, 'published', DATE(DATE_ADD(NOW(), INTERVAL 2 HOUR)), TIME(DATE_ADD(NOW(), INTERVAL 2 HOUR)), 60, NOW());

INSERT INTO exam_questions (exam_id, question_id, order_no)
SELECT 1, id, id FROM questions WHERE class_id = 3 AND board_id = 2;

INSERT INTO enrolments (exam_id, student_id)
SELECT 1, id FROM students WHERE class_id = 3 AND board_id = 2;
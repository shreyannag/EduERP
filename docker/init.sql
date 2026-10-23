SET FOREIGN_KEY_CHECKS = 0;
-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: online_exam
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `online_exam`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `online_exam` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `online_exam`;

--
-- Table structure for table `admins`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `email` varchar(200) DEFAULT 'shreyan_sncc@zohomail.in',
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'admin',
  `sub_role` varchar(50) DEFAULT 'custom',
  `full_name` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `parent_admin_id` int(11) DEFAULT NULL,
  `institute_name` varchar(200) NOT NULL DEFAULT 'Main Campus',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `features` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `session_token` varchar(64) DEFAULT NULL,
  `two_factor_secret` varchar(64) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `two_factor_backup_codes` text DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `cloudinary_settings` text DEFAULT NULL,
  `openrouter_settings` text DEFAULT NULL,
  `support_permission` tinyint(1) NOT NULL DEFAULT 0,
  `support_permission_updated_at` datetime DEFAULT NULL,
  `support_otp` varchar(64) DEFAULT NULL,
  `support_otp_expires_at` datetime DEFAULT NULL,
  `smtp_settings` text DEFAULT NULL,
  `two_factor_attempts` int(11) DEFAULT 0,
  `two_factor_locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_parent_admin` (`parent_admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_messages`
--

CREATE TABLE IF NOT EXISTS `admin_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_receiver` (`receiver_id`),
  KEY `idx_conversation` (`sender_id`, `receiver_id`, `created_at`),
  CONSTRAINT `fk_admin_msg_sender` FOREIGN KEY (`sender_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_msg_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT IGNORE INTO `admins` (`id`, `username`, `email`, `password_hash`, `role`, `institute_name`, `is_active`, `features`, `created_by`, `login_attempts`, `locked_until`, `created_at`, `session_token`) VALUES (1,'admin','shreyan_sncc@zohomail.in','$2y$10$DGKnZ8.nvBxKhcFCykZTHOk4egvOtOQSoRggpqte2izOBYXrr095a','super_admin','Main Campus',1,NULL,NULL,0,NULL,'2026-03-07 21:13:16','41193058cb8d8a4999cad7165b5fcc7ebce8e94c60366345aee15302b5e8c6d1');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `answer_releases`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `answer_releases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) DEFAULT NULL,
  `released_at` datetime DEFAULT current_timestamp(),
  `released_by_admin_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_id` (`exam_id`),
  KEY `released_by_admin_id` (`released_by_admin_id`),
  CONSTRAINT `answer_releases_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `answer_releases_ibfk_2` FOREIGN KEY (`released_by_admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `attempt_answers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `attempt_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) DEFAULT NULL,
  `question_id` int(11) DEFAULT NULL,
  `chosen_option` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `attempt_id` (`attempt_id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `attempt_answers_ibfk_1` FOREIGN KEY (`attempt_id`) REFERENCES `attempts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attempt_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `attempts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `score` int(11) DEFAULT 0,
  `total_questions` int(11) DEFAULT NULL,
  `total_marks` int(11) DEFAULT NULL,
  `is_submitted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `exam_id` (`exam_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `attempts_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `attempts_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `boards`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `boards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `boards`
--

LOCK TABLES `boards` WRITE;
/*!40000 ALTER TABLE `boards` DISABLE KEYS */;
INSERT IGNORE INTO `boards` VALUES (1,'WB Board'),(2,'CBSE'),(3,'ICSE'),(4,'ISC');
/*!40000 ALTER TABLE `boards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(10) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT IGNORE INTO `classes` VALUES (1,'8'),(2,'9'),(3,'10'),(4,'11'),(5,'12');
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrolments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `enrolments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `enrolled_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `exam_id` (`exam_id`,`student_id`),
  KEY `student_id` (`student_id`),
  CONSTRAINT `enrolments_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `enrolments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `exam_questions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `exam_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) DEFAULT NULL,
  `question_id` int(11) DEFAULT NULL,
  `order_no` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exam_id` (`exam_id`),
  KEY `question_id` (`question_id`),
  CONSTRAINT `exam_questions_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_questions_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `exams`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `status` enum('draft','published','completed') DEFAULT 'draft',
  `exam_type` enum('regular','weekly_mock') DEFAULT 'regular',
  `scheduled_date` date DEFAULT NULL,
  `scheduled_time` time DEFAULT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `published_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  KEY `board_id` (`board_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `exams_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exams_ibfk_2` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `messages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_type` enum('admin','student') NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `password_resets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(200) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `token_hash` (`token_hash`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `questions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `topic_id` int(11) DEFAULT NULL,
  `question_text` text DEFAULT NULL,
  `option1` varchar(500) DEFAULT NULL,
  `option2` varchar(500) DEFAULT NULL,
  `option3` varchar(500) DEFAULT NULL,
  `option4` varchar(500) DEFAULT NULL,
  `correct_option` tinyint(1) DEFAULT NULL,
  `marks` int(11) DEFAULT 1,
  `is_archived` tinyint(1) DEFAULT 0,
  `admin_id` int(11) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `class_id` (`class_id`),
  KEY `board_id` (`board_id`),
  KEY `subject_id` (`subject_id`),
  KEY `topic_id` (`topic_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questions_ibfk_2` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `questions_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `questions_ibfk_4` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `report_links`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `report_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) DEFAULT NULL,
  `token` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `exam_id` (`exam_id`),
  CONSTRAINT `report_links_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `students`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) DEFAULT NULL,
  `email` varchar(200) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `username` varchar(80) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `plain_password` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `enrollment_date` date DEFAULT current_date(),
  `login_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `session_token` varchar(64) DEFAULT NULL,
  `admin_id` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `class_id` (`class_id`),
  KEY `board_id` (`board_id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `students_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `students_ibfk_2` FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `subjects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;


--
-- Table structure for table `topics`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `topics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `subject_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  KEY `subject_id` (`subject_id`),
  CONSTRAINT `topics_ibfk_1` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT IGNORE INTO `system_settings` VALUES 
('image_storage_driver','local',NOW()),
('image_compress_quality','75',NOW()),
('image_max_width','1200',NOW()),
('cloudinary_cloud_name','',NOW()),
('cloudinary_api_key','',NOW()),
('cloudinary_api_secret','',NOW()),
('cloudinary_folder','questions',NOW()),
('openrouter_api_key','',NOW()),
('openrouter_model','google/gemini-2.0-flash-001',NOW()),
('openrouter_temperature','0.7',NOW()),
('fee_default_monthly_amount','1500',NOW()),
('fee_currency_symbol','₹',NOW()),
('fee_last_midmonth_reminder','',NOW()),
('fee_last_endmonth_reminder','',NOW());
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fee_structures`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `fee_structures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `class_id` int(11) NOT NULL,
  `monthly_fee` decimal(10,2) NOT NULL DEFAULT 1000.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_class` (`admin_id`, `class_id`),
  CONSTRAINT `fee_structures_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

LOCK TABLES `fee_structures` WRITE;
/*!40000 ALTER TABLE `fee_structures` DISABLE KEYS */;
INSERT IGNORE INTO `fee_structures` (`id`, `class_id`, `monthly_fee`, `created_at`, `updated_at`) VALUES 
(1,1,1000.00,NOW(),NOW()),
(2,2,1200.00,NOW(),NOW()),
(3,3,1500.00,NOW(),NOW()),
(4,4,1800.00,NOW(),NOW()),
(5,5,2000.00,NOW(),NOW());
/*!40000 ALTER TABLE `fee_structures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_fees`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `student_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `fee_month` tinyint(4) NOT NULL,
  `fee_year` smallint(6) NOT NULL,
  `amount_due` decimal(10,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('paid','unpaid','partial') NOT NULL DEFAULT 'unpaid',
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'Cash',
  `transaction_ref` varchar(100) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_student_month_year` (`student_id`,`fee_month`,`fee_year`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_month_year` (`fee_year`,`fee_month`),
  CONSTRAINT `student_fees_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `admin_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE IF NOT EXISTS `admin_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `billing_month` tinyint(4) NOT NULL,
  `billing_year` smallint(6) NOT NULL,
  `enrolled_students` int(11) NOT NULL DEFAULT 0,
  `plan_name` varchar(100) NOT NULL DEFAULT 'Institute Platform Subscription',
  `amount_due` decimal(10,2) NOT NULL DEFAULT 1500.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('paid','pending','overdue') NOT NULL DEFAULT 'pending',
  `due_date` date NOT NULL,
  `payment_date` datetime DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'UPI',
  `transaction_ref` varchar(100) DEFAULT NULL,
  `receipt_number` varchar(50) DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `recorded_by_admin_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_admin_period` (`admin_id`,`billing_month`,`billing_year`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_status` (`status`),
  KEY `idx_period` (`billing_year`,`billing_month`),
  CONSTRAINT `admin_payments_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_rate_limits`
--

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `action` varchar(50) NOT NULL DEFAULT 'admin_login',
  `attempts` int(11) NOT NULL DEFAULT 1,
  `last_attempt` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_action` (`ip_address`, `action`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_locked` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `batches`
--

CREATE TABLE IF NOT EXISTS `batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `teacher_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `schedule_desc` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_class` (`class_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `batch_students`
--

CREATE TABLE IF NOT EXISTS `batch_students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_student` (`batch_id`, `student_id`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `payroll_payments`
--

CREATE TABLE IF NOT EXISTS `payroll_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sub_admin_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `paid_by_id` int(11) NOT NULL,
  `salary_month` tinyint(4) NOT NULL,
  `salary_year` smallint(6) NOT NULL,
  `base_salary` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus` decimal(10,2) NOT NULL DEFAULT 0.00,
  `deductions` decimal(10,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) NOT NULL DEFAULT 'bank_transfer',
  `reference_no` varchar(100) DEFAULT NULL,
  `payment_date` date NOT NULL,
  `receipt_no` varchar(60) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subadmin_month_year` (`sub_admin_id`, `salary_month`, `salary_year`),
  KEY `idx_subadmin` (`sub_admin_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_month_year` (`salary_year`, `salary_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `homework` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` longtext DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `assigned_date` datetime NOT NULL,
  `due_date` datetime NOT NULL,
  `total_points` int(11) NOT NULL DEFAULT 100,
  `attachment_path` varchar(255) DEFAULT NULL,
  `programming_lang` varchar(50) DEFAULT 'none',
  `starter_code` text DEFAULT NULL,
  `solution_guide` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_class` (`class_id`),
  KEY `idx_board` (`board_id`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_due` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `homework_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `homework_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `submission_text` longtext DEFAULT NULL,
  `submitted_code` longtext DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'submitted',
  `grade_points` decimal(5,2) DEFAULT NULL,
  `teacher_feedback` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL,
  `graded_at` datetime DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_homework_student` (`homework_id`, `student_id`),
  KEY `idx_homework` (`homework_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coding_problems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `difficulty` enum('Easy','Medium','Hard') NOT NULL DEFAULT 'Easy',
  `description` longtext NOT NULL,
  `input_format` text DEFAULT NULL,
  `output_format` text DEFAULT NULL,
  `constraints` text DEFAULT NULL,
  `sample_input` text DEFAULT NULL,
  `sample_output` text DEFAULT NULL,
  `sample_explanation` text DEFAULT NULL,
  `time_limit_ms` int(11) NOT NULL DEFAULT 2000,
  `memory_limit_mb` int(11) NOT NULL DEFAULT 256,
  `allowed_languages` varchar(255) NOT NULL DEFAULT 'python,java,c,cpp',
  `starter_code_python` text DEFAULT NULL,
  `starter_code_java` text DEFAULT NULL,
  `starter_code_c` text DEFAULT NULL,
  `starter_code_cpp` text DEFAULT NULL,
  `total_points` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_difficulty` (`difficulty`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coding_test_cases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `problem_id` int(11) NOT NULL,
  `input_data` longtext DEFAULT NULL,
  `expected_output` longtext NOT NULL,
  `is_sample` tinyint(1) NOT NULL DEFAULT 0,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 1,
  `points` int(11) NOT NULL DEFAULT 10,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_problem` (`problem_id`),
  CONSTRAINT `fk_test_case_problem` FOREIGN KEY (`problem_id`) REFERENCES `coding_problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coding_competitions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL DEFAULT 1,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `rules` text DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_class` (`class_id`),
  KEY `idx_batch` (`batch_id`),
  KEY `idx_window` (`start_time`,`end_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coding_competition_problems` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `competition_id` int(11) NOT NULL,
  `problem_id` int(11) NOT NULL,
  `order_index` int(11) NOT NULL DEFAULT 0,
  `points` int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comp_prob` (`competition_id`,`problem_id`),
  KEY `idx_comp` (`competition_id`),
  KEY `idx_prob` (`problem_id`),
  CONSTRAINT `fk_comp_prob_comp` FOREIGN KEY (`competition_id`) REFERENCES `coding_competitions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comp_prob_prob` FOREIGN KEY (`problem_id`) REFERENCES `coding_problems` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `coding_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `competition_id` int(11) DEFAULT NULL,
  `problem_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `language` varchar(20) NOT NULL,
  `source_code` longtext NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'PENDING',
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `passed_test_cases` int(11) NOT NULL DEFAULT 0,
  `total_test_cases` int(11) NOT NULL DEFAULT 0,
  `execution_time_ms` int(11) NOT NULL DEFAULT 0,
  `memory_kb` int(11) NOT NULL DEFAULT 0,
  `compile_output` text DEFAULT NULL,
  `submitted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comp` (`competition_id`),
  KEY `idx_prob` (`problem_id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_comp_student` (`competition_id`,`student_id`),
  CONSTRAINT `fk_sub_prob` FOREIGN KEY (`problem_id`) REFERENCES `coding_problems` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-14  6:13:05

--
-- Table structure for table `enrollment_links`
--
CREATE TABLE IF NOT EXISTS `enrollment_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `title` varchar(200) NOT NULL,
  `admin_id` int(11) NOT NULL DEFAULT 1,
  `class_id` int(11) DEFAULT NULL,
  `board_id` int(11) DEFAULT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `max_uses` int(11) NOT NULL DEFAULT 0,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `require_approval` tinyint(1) NOT NULL DEFAULT 0,
  `collect_parent_info` tinyint(1) NOT NULL DEFAULT 1,
  `collect_address` tinyint(1) NOT NULL DEFAULT 0,
  `custom_welcome_message` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_token` (`token`),
  KEY `idx_admin_status` (`admin_id`,`is_active`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `enrollment_submissions`
--
CREATE TABLE IF NOT EXISTS `enrollment_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `link_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('approved','pending','rejected') NOT NULL DEFAULT 'approved',
  `submitted_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_link` (`link_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

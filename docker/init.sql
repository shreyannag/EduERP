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
-- Dumping data for table `answer_releases`
--

LOCK TABLES `answer_releases` WRITE;
/*!40000 ALTER TABLE `answer_releases` DISABLE KEYS */;
INSERT IGNORE INTO `answer_releases` VALUES (4,9,'2026-05-22 20:29:23',1),(5,10,'2026-05-22 22:27:15',1);
/*!40000 ALTER TABLE `answer_releases` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `attempt_answers`
--

LOCK TABLES `attempt_answers` WRITE;
/*!40000 ALTER TABLE `attempt_answers` DISABLE KEYS */;
INSERT IGNORE INTO `attempt_answers` VALUES (1,2,18,1),(2,2,17,4),(3,2,16,2),(4,2,12,1);
/*!40000 ALTER TABLE `attempt_answers` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `attempts`
--

LOCK TABLES `attempts` WRITE;
/*!40000 ALTER TABLE `attempts` DISABLE KEYS */;
INSERT IGNORE INTO `attempts` (`id`, `exam_id`, `student_id`, `started_at`, `submitted_at`, `score`, `total_questions`, `is_submitted`) VALUES (1,9,12,NULL,'2026-05-22 17:32:47',0,4,1),(2,10,12,NULL,'2026-05-22 20:49:58',4,4,1);
/*!40000 ALTER TABLE `attempts` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `enrolments`
--

LOCK TABLES `enrolments` WRITE;
/*!40000 ALTER TABLE `enrolments` DISABLE KEYS */;
INSERT IGNORE INTO `enrolments` VALUES (9,9,12,'2026-05-22 17:20:15'),(10,10,12,'2026-05-22 20:42:33');
/*!40000 ALTER TABLE `enrolments` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `exam_questions`
--

LOCK TABLES `exam_questions` WRITE;
/*!40000 ALTER TABLE `exam_questions` DISABLE KEYS */;
INSERT IGNORE INTO `exam_questions` VALUES (62,9,18,1),(63,9,17,2),(64,9,16,3),(65,9,12,4),(70,10,18,1),(71,10,17,2),(72,10,16,3),(73,10,12,4);
/*!40000 ALTER TABLE `exam_questions` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
INSERT IGNORE INTO `exams` (`id`, `title`, `class_id`, `board_id`, `status`, `exam_type`, `scheduled_date`, `scheduled_time`, `duration_minutes`, `created_at`, `published_at`) VALUES (9,'Test',1,2,'completed','regular','2026-05-22','17:25:00',60,'2026-05-22 17:19:04','2026-05-22 17:19:28'),(10,'Copy of Test',1,2,'completed','weekly_mock','2026-05-22','20:47:00',10,'2026-05-22 20:41:23','2026-05-22 20:42:21');
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `messages`
--

LOCK TABLES `messages` WRITE;
/*!40000 ALTER TABLE `messages` DISABLE KEYS */;
INSERT IGNORE INTO `messages` VALUES (1,'student',11,1,'How to give exam?',1,'2026-05-22 14:51:37'),(2,'admin',1,2,'Are giving exams on Friday?',0,'2026-05-22 14:52:21'),(3,'admin',1,11,'We will let you know',1,'2026-05-22 14:52:33'),(4,'student',11,1,'Ok testing',1,'2026-05-22 17:04:51'),(5,'admin',1,11,'ok working',1,'2026-05-22 17:04:59'),(6,'student',12,1,'Testing messages',1,'2026-05-22 17:09:33'),(7,'student',12,1,'Pagol chagol admin',1,'2026-05-22 20:37:33'),(8,'admin',1,12,'ja bero ekhan theke',1,'2026-05-22 20:37:46'),(9,'student',12,1,'jai sree allah',1,'2026-05-22 20:51:35'),(10,'student',12,1,'testing 1 2 3',1,'2026-05-23 18:10:25'),(11,'student',12,1,'Hello123',1,'2026-05-23 23:29:38');
/*!40000 ALTER TABLE `messages` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `questions`
--

LOCK TABLES `questions` WRITE;
/*!40000 ALTER TABLE `questions` DISABLE KEYS */;
INSERT IGNORE INTO `questions` (`id`, `class_id`, `board_id`, `subject_id`, `topic_id`, `question_text`, `option1`, `option2`, `option3`, `option4`, `correct_option`, `marks`, `is_archived`, `created_at`, `updated_at`) VALUES (1,3,2,NULL,NULL,'Which of the following is a non-metal that remains liquid at room temperature?','Phosphorus','Bromine','Chlorine','Helium',2,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(2,3,2,NULL,NULL,'The SI unit of electric current is:','Volt','Ohm','Ampere','Coulomb',3,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(3,3,2,NULL,NULL,'What is the chemical name of baking soda?','Sodium carbonate','Calcium carbonate','Sodium hydrogen carbonate','Calcium hydroxide',3,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(4,3,2,NULL,NULL,'A solution turns red litmus blue, its pH is likely to be:','1','4','5','10',4,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(5,3,2,NULL,NULL,'Which of the following gases is used for the storage of fresh sample of an oil for a long time?','Carbon dioxide or oxygen','Nitrogen or oxygen','Carbon dioxide or helium','Helium or nitrogen',4,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(6,3,2,NULL,NULL,'Which instrument is used to measure electric voltage?','Ammeter','Galvanometer','Voltmeter','Potentiometer',3,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(7,3,2,NULL,NULL,'An object is placed at a distance of 10 cm from a convex mirror of focal length 15 cm. Find the position of the image.','6 cm behind the mirror','10 cm behind the mirror','6 cm in front of the mirror','10 cm in front of the mirror',1,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(8,3,2,NULL,NULL,'The human eye forms the image of an object at its:','Cornea','Iris','Pupil','Retina',4,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(9,3,2,NULL,NULL,'The least distance of distinct vision for a young adult with normal vision is about:','25 m','2.5 cm','25 cm','2.5 m',3,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(10,3,2,NULL,NULL,'Which of the following is a plant hormone?','Insulin','Thyroxin','Oestrogen','Cytokinin',4,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(11,3,2,NULL,NULL,'The gap between two neurons is called a:','Dendrite','Synapse','Axon','Impulse',2,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(12,1,2,NULL,NULL,'In a food chain, the third trophic level is always occupied by:','Carnivores','Herbivores','Decomposers','Producers',1,1,0,'2026-03-07 21:13:16','2026-05-22 17:18:08'),(13,3,2,NULL,NULL,'Which of the following is an example of a homologous organ?','Our arm and a dog\'s fore-leg','Our teeth and an elephant\'s tusks','Potato and runners of grass','All of the above',4,1,1,'2026-03-07 21:13:16','2026-03-12 23:44:17'),(14,1,1,NULL,NULL,'The male reproductive parts of a flower are called:','Sepals','Petals','Stamens','Carpels',3,1,0,'2026-03-07 21:13:16','2026-03-12 23:50:17'),(15,3,2,NULL,NULL,'An alloy of copper and zinc is called:','Bronze','Brass','Solder','Steel',2,1,1,'2026-03-07 21:13:16','2026-03-12 23:37:51'),(16,1,2,NULL,NULL,'Arrange the following java statements in the correct order of execution to accept values into the array a[]:\r\n\r\n(i) a[i]=sc.nextInt( );\r\n(ii) int a[]=new int[10];\r\n(iii) Scanner sc=new Scanner(System.in);\r\n(iv) for(i=0;i&lt;10;i++)','(i), (ii), (iii), (iv)','(ii), (iii), (iv), (i)','(iv), (iii), (ii), (i)','(iii), (i), (iv), (ii)',2,1,0,'2026-03-12 23:43:46','2026-05-22 17:17:54'),(17,1,2,NULL,NULL,'<p><span style=\"color: rgb(60, 60, 60); font-family: &quot;Open Sans&quot;, Arial, sans-serif; font-size: 19px; letter-spacing: 0.14994px;\">State which access specifier is less restrictive.</span></p><p><img src=\"data:image/png;base64,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\" data-filename=\"image.png\" style=\"width: 217px;\"><span style=\"color: rgb(60, 60, 60); font-family: &quot;Open Sans&quot;, Arial, sans-serif; font-size: 19px; letter-spacing: 0.14994px;\"><br></span></p>','private','protected','default','public',4,1,0,'2026-03-12 23:52:28','2026-05-22 17:18:01'),(18,1,2,1,1,'<p>Identify the output of the following code snippet:</p><p>text = \"PYTHONPROGRAM\"</p><p>text=text.replace(\'PY\',\'#\')</p><p>print(text)</p>','#THONPROGRAM','##THON#ROGRAM','#THON#ROGRAM','#YTHON#ROGRAM',1,1,0,'2026-03-13 18:18:47','2026-05-22 17:17:47'),(19,3,2,NULL,NULL,'What is the SI unit of force?','Newton','Joule','Pascal','Watt',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(20,3,2,NULL,NULL,'What is the chemical formula for water?','H2O','CO2','O2','NaCl',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(21,3,2,NULL,NULL,'Which planet is known as the Red Planet?','Venus','Mars','Jupiter','Saturn',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(22,3,3,NULL,NULL,'The output of the java statement SOLDIER\".compareTo(\"SOLUTE\"); is\"','-4','-17','17','0',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(23,3,3,NULL,NULL,'Sam performs bubble sort on the following array to organise the elements in ascending order:\n{5, 1, 2, 3}\n\nAfter the first comparison the array is:\n{1, 5, 2, 3}\n\nWhat would be the array after the next comparison?','{1, 2, 5, 3}','{1, 5, 3, 2}','{1, 3, 5, 2}','{1, 3, 2, 5}',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(24,3,3,NULL,NULL,'The AMB hotel gives the amount to be paid by the customer as an integer, which of the following Math method rounds off the bill in decimals to an integer?','Math.round()','Math.pow()','Math.ceil()','Math.abs()',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(25,3,3,NULL,NULL,'What is the type of error, if any, when two methods have the same method signature?','Runtime error','Logical error','Syntax error','No error',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(26,3,3,NULL,NULL,'<p>The advantage/s of user-defined methods are/is:</p><p><br></p><p>(i) Reusability</p><p>(ii) Complexity</p><p>(iii) Modularity</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','Only (i)','(ii) and (iii)','Only (iii)','(i) and (iii)',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(27,3,3,NULL,NULL,'<p>Parameters which receive the values from the called method are termed as ............... parameters.</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','actual','formal','reference','class',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(28,3,3,NULL,NULL,'<p>Which of the following is a valid method prototype?</p><p><br></p><p><br></p><p><br></p><p><br></p>','public int perform (int a;int b)','public perform (int a, int b)','public int perform (int a, int b)','public perform int (int a, int b)',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(29,3,3,NULL,NULL,'<p>Which of the following is the CORRECT statement to invoke the method with the prototype int display(int a, char ch)?</p><p><br></p><p><br></p><p><br></p><p><br></p><p>;</p>','int m = display(\'A\', 45);','int m = display( );','int m = display(A,45);','int m = display(45,\'A\')',4,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(30,3,3,NULL,NULL,'<p>Which of the following is the CORRECT java statement to convert the word RESPECT to lowercase?</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','RESPECT\".tolowercase( );\"','RESPECT\".toLowerCase( );\"','toLowerCase(RESPECT\");\"','String.toLowerCase(RESPECT\");\"',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(31,3,3,NULL,NULL,'<p>Which of the following are Wrapper classes?</p><p><br></p><p>(i) Boolean</p><p>(ii) boolean</p><p>(iii) character</p><p>(iv) Character</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','(i) and (iv)','(ii) and (iv)','(i) and (iii)','(ii) and (iii)',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(32,3,3,NULL,NULL,'<p>Conversion of a wrapper class to its corresponding primitive type is known as :</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','unboxing','autoboxing','type casting','parsing',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(33,3,3,NULL,NULL,'<p>The String method, which results only in a positive integer, is :</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','indexOf','lastIndexOf','compareTo','length',4,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(34,3,3,NULL,NULL,'<p>The method to convert a String to double is:</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','String.toDouble()','Double.Parsedouble()','Double.parseDouble(String)','Double.parseDouble()',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(35,3,3,NULL,NULL,'<p>The java statement System.out.println(x[x.length]) results in:</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','logical error','syntax error','run time error','no error',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(36,3,3,NULL,NULL,'<p>Which of the following is a valid array declaration statement to store the Gender of 80 employees [ \'M\'-male, \'F\'-female, \'T\'-transgender ]?</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','char gender = new char[80];','char gender[] = new char[80];','char gender[80];','char gender[80] = new char[ ];',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(37,3,3,NULL,NULL,'<p>Arrange the following java statements in the correct order of execution to accept values into the array a[]:</p><p><br></p><p>(i) a[i]=sc.nextInt( );</p><p>(ii) int a[]=new int[10];</p><p>(iii) Scanner sc=new Scanner(System.in);</p><p>(iv) for(i=0;i&lt;10;i++)</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','(i), (ii), (iii), (iv)','(ii), (iii), (iv), (i)','(iv), (iii), (ii), (i)','(iii), (i), (iv), (ii)',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(38,3,3,NULL,NULL,'<p>In the bubble sort technique, during each iteration of the inner loop, two adjacent elements are ............... and ............... .</p><p><br></p><p>(i) compared</p><p>(ii) swapped</p><p>(iii) selected</p><p>(iv) deleted</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','(i) and (ii)','(ii) and (iii)','(iii) and (iv)','(ii) and (iv)',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(39,3,3,NULL,NULL,'<p>What is the highest index of any array with 100 elements?</p><p><br></p><p><br></p>','100','101','99','98',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(40,3,3,NULL,NULL,'<p>Mr. Sanjay is an event manager, he plans and allots duties to each of his subordinates to handle different events. In a program, a task is divided into simple methods. Name the feature used in these contexts.</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','Complexity','Modularity','Reusability','Monolithic',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(41,3,3,NULL,NULL,'<p>Raj was asked to accept the phone number which has 10 digits, using the appropriate method of Scanner class. Which of the following statement he must choose?</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','Ob.nextDouble()','Ob.nextInt()','Ob.nextLong()','Ob.next().chatAt(0)',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(42,3,3,NULL,NULL,'<p>Sham was asked to encode a string S, by replacing the letter E with #. Select the appropriate statement:</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','S.replace(\'#\',\'E\')','S.replace(\'E\')','S.replace(\'E\', \'#\')','S.replace(\'#\')',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(43,3,3,NULL,NULL,'<p>When the String m is compared to n, the result obtained is greater than zero. Which of the following is true?</p><p><br></p><p><br></p><p><br></p><p><br></p>','m comes before n in the dictionary.','n comes before m in the dictionary.','m and n are equal.','m and n have the same length.',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(44,3,3,NULL,NULL,'<p>Which of the following String methods has integer argument?</p><p><br></p><p>(i) length</p><p>(ii) substring</p><p>(iii) indexOf</p><p>(iv) charAt</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','Only (ii)','(i) and (iv)','(ii) and (iv)','(iii) and (iii)',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(45,3,3,NULL,NULL,'<p>Assertion: Property by virtue of which one class acquires the properties of another class is termed as Inheritance.</p><p><br></p><p>Reason: Inheritance promotes reusability.</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','Assertion is true, Reason is false.','Assertion is true, Reason is true.','Assertion is false, Reason is false.','Assertion is false, Reason is true.',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(46,3,3,NULL,NULL,'<p>Which of the following is NOT true for polymorphism?</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','All methods have the same name.','Methods are invoked based on the arguments.','Methods should have the same number and the same type of arguments.','It is a principle of OOPs.',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(47,3,3,NULL,NULL,'<p>Which of the following is a valid initialisation statement?</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','int x = GOOD\";\"','int y = 45.0/2;','int z = (int)\'x\';','int m = false ;',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(48,3,3,NULL,NULL,'<p>A girl wanted to calculate the sum of two numbers stored as a and b multiplied by 7. Select the appropriate Java expression.</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','a + b*7','7*a + b','(a + b)*7','a + 7*b',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(49,3,3,NULL,NULL,'The sum of a[1] and a[3] in the array int a[] = {20, 40, 60, 80, 100} is:','80','100','120','60',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(50,3,3,NULL,NULL,'<p>Identify which of the following leads to an infinite loop.</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','for(i = 10; i != 0; i--)','for(i = 3; i <= 30; i += 3)','for(i = 1; i >= 1; i++)','for(i = 1; i >= 0; i--)',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(51,3,3,NULL,NULL,'<p>Assertion: An array can store elements of different data types.</p><p><br></p><p>Reason: An array is a user-defined data type with multiple values of the same data type but a different memory index.</p><p><br></p><p><br></p><p><br></p><p><br></p>','Assertion is true, Reason is false.','Both assertion and Reason are false.','Both assertion and Reason are true.','Assertion is false, Reason is true.',4,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(52,3,3,NULL,NULL,'<p>Which of the following statement is an example for explicit typecasting?</p><p><br></p><p><br></p><p><br></p>','int amount = 45;','int amount = Integer.parseInt(45\")\"','int amount = 24*24;;','int amount = (int)45.75;',4,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(53,3,3,NULL,NULL,'<p>The output of the statement Math.ceil.(89.9) + Math.floor(90.5) is:</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','0','180','180','180.4',3,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(54,3,3,NULL,NULL,'<p>The two Java statement are used to check for the equality of the strings COP\" and \"cop\" are as follows:</p><p><br></p><p>\"COP\".equals(\"cop\")&nbsp;</p><p>\"COP\".equalsIgnoreCase(\"cop\")</p><p>The output of the above statements is:</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>\"','false, true','true, false','false, false','true, true',1,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11'),(55,3,3,NULL,NULL,'<p>Arrange the following in the descending order of number of bytes occupied.</p><p><br></p><p>(i) char x[20]</p><p>(ii) int a[4][2]</p><p>(iii) double b[6]</p><p><br></p><p><br></p><p><br></p><p><br></p><p><br></p>','(iii), (ii), (i)','(iii), (i), (ii)','(ii), (iii), (i)','(i), (ii), (iii)',2,1,0,'2026-05-23 18:03:11','2026-05-23 18:03:11');
/*!40000 ALTER TABLE `questions` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `report_links`
--

LOCK TABLES `report_links` WRITE;
/*!40000 ALTER TABLE `report_links` DISABLE KEYS */;
INSERT IGNORE INTO `report_links` VALUES (1,9,'7ef29e9f8434b47c0c6f4a8ea89bbe8eb2b90d3ebeec8085a27f263eb0e8e301','2026-05-22 20:27:32','2026-05-29 20:27:32'),(2,10,'337431f0921b4c503aa156c21c8c77025ccec9fa8d5885f7ded65fa42c6d6a6a','2026-05-22 22:30:18','2026-05-29 22:30:18');
/*!40000 ALTER TABLE `report_links` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT IGNORE INTO `students` (`id`, `full_name`, `email`, `class_id`, `board_id`, `username`, `password_hash`, `plain_password`, `is_active`, `created_at`, `login_attempts`, `locked_until`, `session_token`) VALUES (3,'Amit Patel','amit@example.com',3,2,'amit.10cbse103','$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6','Pass123',0,'2026-03-07 21:13:16',0,NULL,NULL),(4,'Sneha Roy','sneha@example.com',3,2,'sneha.10cbse104','$2y$10$sxzVvSrHMYFtRA9l4G73A.6Sv7QNOJ0DoGm3c3lPGEd3DXNRtwCT6','Pass123',1,'2026-03-07 21:13:16',0,NULL,NULL),(5,'Vikram Gupta','vikram@example.com',3,2,'vikram.10cbse105','$2y$10$bwwE7yAloq4uduuahXRcquu2jVqS9udw/xWYf3udLzm9LePfzlxsm','aerMWQFH',1,'2026-03-07 21:13:16',0,NULL,NULL),(7,'Abir Shee','',1,1,'abir.8wbboard817','$2y$10$WhUbTueVmvjs0APP8m56k.oRSPC36zHjgyt2ut5deZl3YbYBIJZSK','VgcUAsO1',1,'2026-03-08 08:00:31',0,NULL,NULL),(8,'Aditya Das','',2,3,'aditya.9icse856','$2y$10$PRjoYEr9ZL2LOZBhMKIqzOPs6jIyFWIBDMvWVrLJJtdP6wIt.DXlm','1vuK2F6j',1,'2026-03-13 18:19:56',0,NULL,NULL),(9,'Shibanjan Basu','basushibanjanx@gmail.com',5,1,'shibanjan.12wbboard981','$2y$10$uRK2N0K6JxzAp8cFkpJGx.XcfI2mT/Wp6mFMUG0lNaKMjktVtuvXS','GfOQTR1j',1,'2026-03-15 18:26:21',0,NULL,NULL),(10,'Arijit Das','info4arijit08@gmail.com',5,1,'arijit.12wbboard132','$2y$10$YF/2WhRZ9wa5lz8vlTH9NuczZtPyz4squ6ByL2BARo.V64j/wDxvG','zpjcgu0V',1,'2026-03-15 18:26:57',0,NULL,NULL),(11,'Aditya Chatterjee','aditya.c.ssa@gmail.com',5,2,'aditya.12cbse191','$2y$10$wf1/BM/58DMRFsRaAdCJpO4mh.qFgcFPhuRS/TBtMezgUykpAFaWy','f5pz40eF',1,'2026-03-15 18:28:12',0,NULL,NULL),(12,'Shreyan Nag','nagshreyan@gmail.com',1,2,'shreyan.11cbse527','$2y$10$43.7EdYTZ5qyt4cMc5Qbsu1p/WGlhcKe08D92.oA35HZDWz8PQ/wy','oTBshCw4',1,'2026-05-22 16:59:10',0,NULL,'bbf6ff6cc567d35ee3008d8084746a61ed5bb84ee60cf94b2d0a5cf4137ef5fb'),(13,'Sourish Roy','sourishroy.pkt@gmail.com',4,2,'sourish.11cbse751','$2y$10$A6/EFnIu3XOZOImeyOXhjubWmYinXkEEMzP8823AWSvWy3KBcbwnW','WgsCVPs7',1,'2026-05-22 20:32:12',0,NULL,NULL),(14,'Kaushiki Banerjee','kaushikibanerjee152@gmail.com',3,3,'kaushiki.10icse203','$2y$10$FnA72r.T81HdUvOBzML7Cecv1t7MqHrKRhFbZ8BuJnvJIJSCJcrRK','1u8wJ64N',1,'2026-05-23 18:11:08',0,NULL,NULL),(15,'Sneha Bera','snehabera412@gmail.com',3,3,'sneha.10icse680','$2y$10$KWp5DxhUPXNMz9FHrgN0XO9x.VK3Kp.AeJhuuo.nfDaPQWtQd/ryO','ahPjHcwQ',1,'2026-05-23 18:15:36',0,NULL,NULL),(16,'Anwesha Basak','anweshab2011@gmail.com',3,3,'anwesha.10icse777','$2y$10$6EVDh27fmSKemSz6.VJu1OCYCss26iRF2pRDjMCNugrmU8wmicE1m','T7nVUMIN',1,'2026-05-23 18:19:05',0,NULL,NULL),(17,'Samriddho Chatterjee','samriddhochatterjee77@gmail.com',1,3,'samriddho.8icse664','$2y$10$vK0M9XIc97cpPW5WkPpZeOfY5nbvaw.OGIDQ.4EV8VoPi4xZUFH9C','7fsMjY6n',1,'2026-05-23 21:23:52',0,NULL,NULL),(18,'Dipayan Bhowmick','dipayan.bhowmick0711@gmail.com',2,2,'dipayan.9cbse464','$2y$10$ci5x15Ssk4TyFlDLka14JeFY5CF6yl5kcrijYlmV5NrBaZsdAvZSC','xVCN4bQh',1,'2026-05-23 21:25:58',0,NULL,NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT IGNORE INTO `subjects` VALUES (1,1,'Artificial Intelligence','2026-05-22 17:10:07');
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

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
-- Dumping data for table `topics`
--

LOCK TABLES `topics` WRITE;
/*!40000 ALTER TABLE `topics` DISABLE KEYS */;
INSERT IGNORE INTO `topics` (`id`, `subject_id`, `name`, `created_at`) VALUES (1,1,'Full Syllabus','2026-05-22 17:10:14');
/*!40000 ALTER TABLE `topics` ENABLE KEYS */;
UNLOCK TABLES;
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

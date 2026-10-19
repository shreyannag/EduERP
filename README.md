# 🎓 EduERP: Enterprise Educational Resource Planning & Assessment System

> **Developed by SN Technologies (Shreyan Nag Technologies)**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/MariaDB-10.11%20%7C%20MySQL%208.0-003545?style=flat-square&logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker&logoColor=white)](https://www.docker.com/)
[![Frontend](https://img.shields.io/badge/Frontend-Bootstrap%204.6-7952B3?style=flat-square&logo=bootstrap&logoColor=white)](https://getbootstrap.com/)
[![Developed by](https://img.shields.io/badge/Developed%20by-SN%20Technologies-ff69b4?style=flat-square&logo=github)](https://github.com/shreyannag)
[![License](https://img.shields.io/badge/License-MIT-green.svg?style=flat-square)](LICENSE)
[![High Concurrency](https://img.shields.io/badge/Architecture-High--Concurrency%20Resilient-blueviolet?style=flat-square)](#-high-concurrency-performance--resilience)

**EduERP** is an enterprise-grade, multi-tenant Educational Resource Planning (ERP) and Assessment Platform tailored for coaching institutes, schools, tutoring networks, and STEM academies. 

Engineered for reliability, ultra-fast load times, and high concurrency on cost-effective hardware, EduERP consolidates **academic scheduling, student information management, online MCQ examinations, competitive coding arenas, digital homework grading, fee ledgers, and staff payroll** into a single cohesive system.

---

## 📑 Table of Contents

- [Overview & Core Value](#-overview--core-value)
- [System Architecture & Multi-Tenancy](#-system-architecture--multi-tenancy)
- [Role-Based Access Control (RBAC)](#-role-based-access-control-rbac)
- [Comprehensive Module Directory](#-comprehensive-module-directory)
  - [1. Academic Hierarchy & Batch Scheduling](#1-academic-hierarchy--batch-scheduling)
  - [2. Student Information System (SIS)](#2-student-information-system-sis)
  - [3. Online Examination & Assessment Engine (MCQ)](#3-online-examination--assessment-engine-mcq)
  - [4. Competitive Coding Arena & Automated Judge](#4-competitive-coding-arena--automated-judge)
  - [5. Homework & Digital Assignment Management](#5-homework--digital-assignment-management)
  - [6. Student Fee Management & Financial Ledgers](#6-student-fee-management--financial-ledgers)
  - [7. Staff Payroll System](#7-staff-payroll-system)
  - [8. Question Bank & AI Generator](#8-question-bank--ai-generator)
  - [9. Academic Reporting & Batch Percentile Analytics](#9-academic-reporting--batch-percentile-analytics)
  - [10. Exam Integrity & Anti-Cheat Monitoring](#10-exam-integrity--anti-cheat-monitoring)
- [High-Concurrency Performance & Resilience](#-high-concurrency-performance--resilience)
- [Technology Stack](#-technology-stack)
- [Repository Directory Structure](#-repository-directory-structure)
- [Quick Start with Docker](#-quick-start-with-docker-recommended)
- [Manual / Local XAMPP Setup](#-manual--local-xampp-setup)
- [Default Login Credentials](#-default-login-credentials)
- [Automated Backups & Hostinger VPS Upgrades](#-automated-backups--hostinger-vps-upgrades)
- [Automated Testing & Verification](#-automated-testing--verification)
- [Security Features](#-security-features)
- [License](#-license)

---

## 🌟 Overview & Core Value

Modern educational institutes frequently struggle with fragmented software stacks: one tool for fee receipts, a third-party platform for examinations, disconnected Google Forms for homework, and manual spreadsheets for batch management. 

EduERP unites all institute operations under **one unified codebase**:
- **Zero Bloat & Peak Speed:** Built with optimized native PHP 8.x PDO and clean Bootstrap 4.6 components—delivering sub-50ms query responses without bulky runtime framework overhead.
- **True Multi-Tenancy:** Host dozens of independent coaching centers or franchises with distinct branding, isolated question banks, independent student enrollments, and dedicated fee ledgers.
- **Hardware-Friendly Concurrency:** Designed to sustain high student loads during timed examinations through ACID transactions, lock-free status caching, composite indexing, and Gzip wire compression.
- **Built-in STEM Coding Judge:** Integrated multi-language code compiler and execution judge supporting Python, Java, C++, C, JavaScript, and PHP with automated test case evaluation.

---

## 🏛 System Architecture & Multi-Tenancy

EduERP enforces a 4-tier hierarchical access structure with strict data isolation:

```mermaid
flowchart TD
    SA["👑 Super Administrator<br/>(Platform Master & Tenant Provisioning)"]
    TA["🏫 Tenant Administrators<br/>(Coaching Institutes / Campuses)"]
    TEA["👨‍🏫 Teacher Sub-Admins<br/>(Batches, Homework, Coding, Exams)"]
    ACC["💳 Accountant Sub-Admins<br/>(Student Fees, Payments, Receipts)"]
    STU["🎓 Students<br/>(Exams, Coding Arena, Homework, Result Cards)"]

    SA -->|Provisions & Audits| TA
    TA -->|Manages Staff & Access| TEA
    TA -->|Manages Staff & Access| ACC
    TA -->|Enrolls & Brands| STU
    TEA -->|Assigns & Grades| STU
    ACC -->|Collects Fees & Invoices| STU
```

### Tenant Branding & Customization
Each Tenant Administrator customizes their institute branding (display name, logos, institute contact details). When students log in, navigation bars, examination portals, PDF scorecards, and receipt printouts dynamically adopt that institute's visual identity.

---

## 🔐 Role-Based Access Control (RBAC)

The system maintains a granular permissions matrix to protect administrative privileges and student privacy:

| Capability / Module | Super Admin | Tenant Admin | Teacher Sub-Admin | Accountant Sub-Admin | Student |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Manage Multiple Institutes** | ✅ Yes | ❌ No | ❌ No | ❌ No | ❌ No |
| **Global Coding Platform Master Switch** | ✅ Yes | ❌ No | ❌ No | ❌ No | ❌ No |
| **Tenant Subscriptions & Billing** | ✅ Yes | ❌ No | ❌ No | ❌ No | ❌ No |
| **Class, Board & Subject Management** | ✅ Yes | ✅ Yes | 👁️ View Only | 👁️ View Only | ❌ No |
| **Batch Management & Student Mapping** | ✅ Yes | ✅ Yes | 👁️ Assigned Batches | ❌ No | 👁️ View Own Batch |
| **Student Enrollment & CSV Bulk Import**| ✅ Yes | ✅ Yes | 🔒 Assigned Scope | 🔒 Assigned Scope | ❌ No |
| **Create Exams & Question Banks** | ✅ Universal | ✅ Institute Scope | ✅ Assigned Scope | ❌ No | ❌ No |
| **Score Gating & Result Publishing** | ✅ Yes | ✅ Yes | ✅ Assigned Scope | ❌ No | ❌ No |
| **Coding Problem & Contest Management** | ✅ Full Access| ✅ Full Access| ✅ Create & Grade | ❌ No | 💻 Solve & Compete |
| **Homework Assignment & Grading** | ✅ Full Access| ✅ Full Access| ✅ Create & Grade | ❌ No | 📝 Turn In Work |
| **Record Student Fee Payments** | ✅ Yes | ✅ Yes | ❌ No | ✅ Yes | 👁️ View Own Receipts |
| **Institute Total Revenue Privacy Guard** | ✅ Visible | ✅ Visible | ❌ Hidden | 🔒 **Hidden (Guarded)** | ❌ Hidden |
| **Staff Payroll & Payslips** | ✅ Yes | ✅ Yes | 👁️ View Own Slip | 🔒 Assigned Scope | ❌ No |
| **Take Examinations & Code in Contests** | 👁️ Preview | 👁️ Preview | 👁️ Preview | ❌ No | ✅ Yes |

---

## 📦 Comprehensive Module Directory

### 1. Academic Hierarchy & Batch Scheduling
* **Structure:** Define Education Boards (CBSE, ICSE, State Boards), Classes (Class 1 to 12, Competitive Droppers), and Academic Subjects.
* **Batch Segmentation:** Create morning, evening, and weekend batches per class with maximum seat caps and scheduled timing.
* **Timetable & Schedule:** Assign batches to designated teachers with date and time slots to avoid scheduling clashes.

### 2. Student Information System (SIS)
* **Automated Student ID Generation:** System dynamically generates memorable, collision-resistant student IDs (e.g., `rahul.10cbse101`).
* **Bulk Enrollment:** Import hundreds of students instantly via standard CSV upload with pre-validation and rollback safeguards.
* **Multi-Batch Enrolment:** Assign a student to multiple specialized batches (e.g., *Physics Mains*, *Chemistry Advanced*).
* **Self-Service Profile:** Students can update contact numbers, view active courses, and change their login passwords.

### 3. Online Examination & Assessment Engine (MCQ)
* **Timed Assessments:** Configurable start date, start time, and duration countdown timer with automated submission on zero.
* **Randomization:** Dynamic question shuffling and answer option permutation (`A, B, C, D`) to prevent peer copying.
* **Negative Marking:** Flexible scoring models (e.g., +4 for correct, -1 for incorrect, 0 for unattempted).
* **Score Gating (Result Embargo):** Choose between *Instant Result Mode* (students view scores immediately) or *Score Gated Mode* (results and answer explanations remain hidden until the teacher officially publishes them).
* **Student Review & Answer Keys:** Post-exam student review page providing color-coded correct answers, chosen options, and question-level explanations.

### 4. Competitive Coding Arena & Automated Judge
* **Multi-Language Support:** In-browser compilation and test case execution for **Python 3, Java, C++, C, JavaScript (Node.js), and PHP**.
* **Problem Management:** Create coding challenges with problem descriptions, memory/time constraints, input/output specifications, sample test cases, and hidden judge test cases.
* **Execution Engine:** Flexible backend integration with **Judge0 REST API** or isolated local execution container.
* **Contests & Real-Time Leaderboards:** Host live coding competitions with automated submission grading, penalty time tracking for incorrect attempts, and dynamic leaderboard rankings.
* **Master Switch:** Institute admins can enable or disable the coding module across their institute with one click.

### 5. Homework & Digital Assignment Management
* **Rich Task Creation:** Teachers assign homework to specific batches with due dates, instructions, and multiple attachment files (PDFs, DOCX, images).
* **Student Work Submission:** Students submit written answers and upload homework files directly via their portal.
* **Grading Dashboard:** Teachers review submissions with status badges (*Submitted, Late, Graded, Resubmission Requested*), award marks, and provide inline feedback.

### 6. Student Fee Management & Financial Ledgers
* **Configurable Fee Structures:** Define monthly fees, admission fees, exam fees, and installment milestones per batch.
* **Discount Management:** Apply student-specific scholarships, concession vouchers, and fee adjustments.
* **Payment Recording:** Process payments via Cash, UPI, NetBanking, Card, or Cheque with instant balance calculation.
* **Digital Invoice Receipts:** Automatically generates printable and downloadable branded payment receipts.
* **Accountant Privacy Guard:** Specialized security boundary preventing accountant sub-admins from viewing the institute's total aggregate revenue while allowing them to process individual student dues.
* **Automated Fee Reminders:** Integrated cron script (`cron/fee_reminders.php`) to identify overdue accounts and dispatch reminders.

### 7. Staff Payroll System
* **Monthly Salary Processing:** Generate monthly payroll records for teaching and non-teaching personnel.
* **Allowances & Deductions:** Itemize basic pay, travel allowances, performance incentives, tax deductions, and unpaid leave penalties.
* **Printable Payslips:** Downloadable pay vouchers for staff members with transparent payment status tracking (*Pending, Approved, Disbursed*).

### 8. Question Bank & AI Generator
* **Topic-Wise Repository:** Categorize questions by Subject, Class, Chapter, Difficulty Level (Easy, Medium, Hard), and Marks.
* **Bulk Question Import:** Upload hundreds of questions with multiple-choice options and explanations using CSV templates.
* **AI Prompt Generation:** Built-in AI prompt templates that format curriculum questions into system-compatible CSV structures.
* **Question Archiving:** Soft-delete and archive outdated questions without corrupting historical exam attempts.

### 9. Academic Reporting & Batch Percentile Analytics
* **Printable Report Cards:** Detailed student academic transcripts with subject-level breakdowns, maximum marks, scored marks, and percentage calculations.
* **Batch Analytics:** Comparative percentile calculation, batch rank, class highest score, and class average score to identify student performance trends.
* **Audit Trail:** History of exam submissions, fee payments, and login activities.

### 10. Exam Integrity & Anti-Cheat Monitoring
* **Tab-Switch Detection:** Automatically tracks when a student navigates away from the exam tab, logging incident counters in real time.
* **Fullscreen Lock Enforcement:** Prompts students into fullscreen examination mode to minimize background application usage.
* **Copy/Paste Prevention:** Context menu and clipboard inspection prevention during active assessment sessions.

---

## ⚡ High-Concurrency Performance & Resilience

EduERP is engineered to deliver high throughput and stability during peak traffic spikes (e.g., hundreds of students starting or submitting exams simultaneously).

```
┌────────────────────────────────────────────────────────┐
│                   HTTP Requests                        │
└──────────────────────────┬─────────────────────────────┘
                           ▼
┌────────────────────────────────────────────────────────┐
│          Apache (mod_deflate Gzip + Expired Cache)     │  <-- 65%-80% Egress Bandwidth Reduction
└──────────────────────────┬─────────────────────────────┘
                           ▼
┌────────────────────────────────────────────────────────┐
│       Transient Query Throttle (30s Lock-Free)         │  <-- 99.9% DB Lock Elimination
└──────────────────────────┬─────────────────────────────┘
                           ▼
┌────────────────────────────────────────────────────────┐
│      ACID Transactions + UPSERT Answer Deduplication   │  <-- Zero Corrupted Submissions
└──────────────────────────┬─────────────────────────────┘
                           ▼
┌────────────────────────────────────────────────────────┐
│      MariaDB / MySQL (10 Composite Performance Indexes)│  <-- O(log N) Indexed Lookups
└────────────────────────────────────────────────────────┘
```

### 1. Transient Query Throttling ([`includes/functions.php`](includes/functions.php))
Background exam auto-completion queries previously ran on every student page request. A 30-second lock-free transient file throttle eliminates database table locks, reducing background status update queries by **99.9%** during simultaneous exam starts.

### 2. ACID Transactions & Submission Deduplication ([`student/submit.php`](student/submit.php))
Submissions are wrapped inside an ACID transaction (`$pdo->beginTransaction()` ... `$pdo->commit()`). Multiple answer rows are processed with `ON DUPLICATE KEY UPDATE` to safely handle double-submits or flaky network retries without database deadlocks or duplicate key exceptions.

### 3. Race-Condition Safe Exam Starts ([`student/exam.php`](student/exam.php))
Attempt record creation uses protected `try...catch (\PDOException $e)` blocks with automatic fallback recovery. If a student rapidly double-clicks "Start Exam" or opens multiple tabs, the system gracefully binds to the existing attempt without failing.

### 4. High-Performance Composite Indexes ([`docker/migrate.php`](docker/migrate.php))
The database features 10 specialized composite indexes:
- `attempts`: `(student_id, exam_id)`, `(exam_id, is_submitted)`
- `attempt_answers`: `(attempt_id, question_id)`
- `exam_questions`: `(exam_id, order_no, question_id)`
- `exams`: `(status, scheduled_date, scheduled_time)`, `(admin_id, status)`
- `questions`: `(admin_id, is_archived)`
- `homework`: `(admin_id, is_active)`, `(batch_id, is_active)`
- `batch_students`: `(student_id)`

### 5. Network Wire Compression & Browser Caching ([`.htaccess`](.htaccess))
Apache `mod_deflate` compresses all text, HTML, JSON, CSS, and JS payloads, cutting egress bandwidth consumption by **65%–80%**. Static CSS, JS, fonts, and images are cached in the student's browser for 30 days, optimizing performance on standard 100–150 Mbps network uplinks.

---

## 🛠 Technology Stack

| Layer | Technology | Purpose |
| :--- | :--- | :--- |
| **Backend Language** | PHP 8.2 / 8.3 | High-speed server-side processing with strict typing |
| **Database Engine** | MariaDB 10.11 / MySQL 8.0 | InnoDB storage engine with `utf8mb4_general_ci` |
| **Web Server** | Apache 2.4 | Configured with `mod_rewrite`, `mod_deflate`, `mod_headers`, `mod_expires` |
| **Frontend UI** | Bootstrap 4.6 & Custom CSS | Responsive layout optimized for desktop, tablet, and mobile |
| **Client Scripts** | jQuery 3.6, Chart.js, SweetAlert | Interactive AJAX submissions, charts, and modal dialogues |
| **Code Judge** | Judge0 API / CLI Runner | Isolated sandboxed code compilation and execution |
| **Containerization**| Docker & Docker Compose | Containerized application, database, and phpMyAdmin services |
| **Utilities** | Composer, PHPMailer | Secure SMTP email notifications and password resets |

---

## 📁 Repository Directory Structure

```text
EduERP/
├── .htaccess                  # Apache Gzip compression, browser caching, & security headers
├── docker-compose.yml         # Multi-container orchestration (App, MariaDB, phpMyAdmin)
├── Dockerfile                 # PHP 8.2 Apache production image with required PHP extensions
├── README.md                  # Comprehensive project documentation & architecture guide
├── admin/                     # Admin & Sub-Admin portal modules
│   ├── admins/                # Super admin tenant provisioning & subscription tracking
│   ├── batches/               # Academic batch setup and student-batch mapping
│   ├── coding/                # Coding problem management, test cases & competitions
│   ├── enrolments/            # Student admissions & batch assignment
│   ├── exams/                 # Exam creation, score gating, timer controls & analytics
│   ├── fees/                  # Student fee ledgers, voucher discounts & payment receipts
│   ├── homework/              # Homework distribution, attachment uploads & grading
│   ├── payroll/               # Staff salary generation, allowance tracking & payslips
│   ├── questions/             # Question bank, CSV bulk import & AI prompt generation
│   ├── reports/               # Student report cards, batch percentiles & attendance
│   ├── schedule/              # Teacher timetables and class slot planning
│   ├── students/              # Student directory, profile management & password resets
│   ├── subjects/              # Board, class, and academic subject configurations
│   └── sub_admins/            # Teacher & Accountant role delegation and permissions
├── config/
│   └── db.php                 # Environment-aware PDO database connection (Docker/Local)
├── cron/
│   └── fee_reminders.php      # CLI automated cron script for overdue fee notifications
├── docker/
│   ├── apache.conf            # Custom Apache virtual host configuration
│   ├── init.sql               # Complete initial database schema and seed data
│   ├── migrate.php            # Non-destructive database migration runner
│   └── seed_coding.php        # Initial coding challenge questions seed data
├── includes/
│   ├── admin_header.php       # Administrative navigation layout and session guard
│   ├── functions.php          # Global utility functions, status throttling & helpers
│   └── student_header.php     # Student portal navigation layout and session guard
├── scripts/
│   ├── backup.sh / .bat       # Automated database backup scripts
│   ├── restore.sh             # Database restore utility from backup SQL files
│   └── update.sh / .bat       # Zero-downtime Hostinger VPS update automation
├── student/                   # Student portal modules
│   ├── coding/                # Coding practice arena, code editor & contest leaderboard
│   ├── dashboard.php          # Student dashboard (active exams, homework, fee balance)
│   ├── exam.php               # Live timed examination interface with anti-cheat hooks
│   ├── fees.php               # Personal fee ledger and downloadable invoice receipts
│   ├── homework.php           # Homework assignments view and submission modal
│   ├── report_card.php        # Printable student academic performance transcripts
│   ├── review.php             # Post-exam question-by-question review & explanations
│   └── submit.php             # Atomic ACID exam submission processing
└── uploads/                   # User-uploaded files (homework attachments, student avatars)
```

---

## 🚀 Quick Start with Docker (Recommended)

Run the entire system in an isolated containerized environment in **less than 60 seconds**.

### 1. Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running.
- [Git](https://git-scm.com/) installed on your machine.

### 2. Clone and Launch
```bash
# Clone the repository
git clone https://github.com/shreyannag/EduERP.git
cd EduERP

# Start all services in the background
docker compose up -d --build
```

Docker will:
1. Build the PHP 8.2 Apache web container with necessary extensions (`pdo_mysql`, `curl`, `mbstring`, `gd`, `zip`).
2. Start the MariaDB 10.11 database container and automatically execute [`docker/init.sql`](docker/init.sql) to seed the initial schema.
3. Start phpMyAdmin for database administration.

### 3. Application Access Points

| Service | Local URL | Credentials / Notes |
| :--- | :--- | :--- |
| **Web Portal** | [http://localhost:8080/shreyansir](http://localhost:8080/shreyansir) | Main application home |
| **Admin Portal** | [http://localhost:8080/shreyansir/admin/login.php](http://localhost:8080/shreyansir/admin/login.php) | **User:** `admin`<br>**Pass:** `admin123` |
| **Student Portal**| [http://localhost:8080/shreyansir/student/login.php](http://localhost:8080/shreyansir/student/login.php) | **User:** `rahul.10cbse101`<br>**Pass:** `Pass123` |
| **phpMyAdmin** | [http://localhost:8081](http://localhost:8081) | **Server:** `db`<br>**User:** `root`<br>**Pass:** `root_secret` |
| **MariaDB Port** | `localhost:3307` | Compatible with DBeaver, TablePlus, DataGrip |

---

## 💻 Manual / Local XAMPP Setup

If you prefer running on a local Apache/MySQL stack (e.g., XAMPP, WampServer, or LAMP):

1. **Clone to Web Root:**
   ```bash
   cd c:/xampp/htdocs/
   git clone https://github.com/shreyannag/EduERP.git shreyansir
   ```
2. **Start Apache & MySQL** in the XAMPP Control Panel.
3. **Create Database:**
   - Open phpMyAdmin at `http://localhost/phpmyadmin`.
   - Create a new database named `online_exam` with collation `utf8mb4_general_ci`.
4. **Import Database Schema:**
   - Import [`docker/init.sql`](docker/init.sql) (or [`setup/install.sql`](setup/install.sql)) into `online_exam`.
5. **Run Migrations:**
   Ensure all composite indexes and latest schema tables are active:
   ```bash
   php docker/migrate.php
   ```
6. **Configure Database Connection (Optional):**
   The [`config/db.php`](config/db.php) file automatically reads from environment variables or defaults to `localhost:3308` (or `3306`), user `root`, and empty password. Customize via `.env` or system environment variables if your local MySQL settings differ:
   ```php
   $host = getenv('DB_HOST') ?: 'localhost';
   $port = getenv('DB_PORT') ?: '3306';
   $db   = getenv('DB_NAME') ?: 'online_exam';
   $user = getenv('DB_USER') ?: 'root';
   $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
   ```
7. **Access the System:**
   - Admin Login: `http://localhost/shreyansir/admin/login.php`
   - Student Login: `http://localhost/shreyansir/student/login.php`

---

## 🔑 Default Login Credentials

### 1. Platform Master (Super Admin)
* **URL:** `/admin/login.php`
* **Username:** `admin`
* **Password:** `admin123`
*(Note: Change immediately upon production deployment via Settings)*

### 2. Sample Student Account
* **URL:** `/student/login.php`
* **Student ID:** `rahul.10cbse101`
* **Password:** `Pass123`

---

## 🔄 Automated Backups & Hostinger VPS Upgrades

The repository includes pre-built deployment and maintenance scripts located in `scripts/`:

### Automated One-Click VPS Update
To upgrade your Docker container on a live VPS without interrupting database data:
```bash
bash scripts/update.sh
```
* **Step 1:** Automatically dumps a timestamped snapshot of the live database into `backups/`.
* **Step 2:** Pulls the newest commit (`git pull origin main`).
* **Step 3:** Rebuilds application image layers (`docker compose build app`).
* **Step 4:** Recreates web container with zero volume data disruption (`docker compose up -d --no-deps app`).
* **Step 5:** Executes [`docker/migrate.php`](docker/migrate.php) to safely apply schema updates.

### Manual Database Snapshot & Restore
- **Backup:**
  ```bash
  # Linux / macOS
  bash scripts/backup.sh
  # Windows
  scripts\backup.bat
  ```
- **Restore:**
  ```bash
  bash scripts/restore.sh backups/backup_YYYYMMDD_HHMMSS.sql
  ```

---

## 🧪 Automated Testing & Verification

EduERP includes regression test suites to guarantee code stability, database integrity, and score calculation accuracy:

```bash
# Run Core Bug Regression & Examination Logic Verification
php scratch/test_bugs_verification.php

# Run Coding Platform & Automated Judge Test Suite
php scratch/test_coding_full_system.php
```

All test suites execute end-to-end assertions against the active database and report detailed green pass metrics.

---

## 🛡️ Security Features

- **Prepared SQL Statements:** 100% of database interactions utilize PDO prepared statements with strict parameter binding to eliminate SQL injection vulnerabilities.
- **XSS Protection:** Output sanitization through HTML entity encoding ([`h()`](includes/functions.php)) and purified input strings.
- **Session Integrity & Hijacking Prevention:** Session fixation protection with periodic regeneration of session IDs and strict HTTP cookie parameters (`httponly`, `samesite`).
- **Accountant Privacy Guard:** Accountants cannot see total institutional collections, keeping institute financial health confidential from non-administrative staff.
- **Strict File Upload Validation:** Homework and question image uploads enforce strict MIME-type whitelists, random file hash renaming, and directory execution disallow rules.
- **Anti-Cheat Audit Log:** Student exam sessions record window blur events, copy-paste attempts, and submission timestamps.

---

## 👨‍💻 Author & Credits

**EduERP** is proudly developed and maintained by **SN Technologies (Shreyan Nag Technologies)**.

* **Developer & Lead Architect:** Shreyan Nag
* **GitHub Organization / Profile:** [@shreyannag](https://github.com/shreyannag)
* **Official Repository:** [https://github.com/shreyannag/EduERP](https://github.com/shreyannag/EduERP)

---

## 📄 License

This project is licensed under the **MIT License**. You are free to use, customize, and deploy this software for commercial or private educational institutions.

---

<p align="center">
  <b>Developed by SN Technologies (Shreyan Nag Technologies)</b><br>
  <i>Built with ❤️ for Educators, Students, and Engineers • Empowering coaching institutes with modern, resilient digital infrastructure.</i>
</p>

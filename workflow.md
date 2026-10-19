# 📋 SYSTEM DEVELOPMENT PROMPT
## Online Examination Management System — v2.0
**Stack:** PHP (no framework) · MySQL · Vanilla JavaScript · Bootstrap 4.6
**Security Model:** Fully separated Admin and Student login portals
**Audience:** High school students, Classes 8–12, Multi-board support

---

## 🎯 PROJECT OVERVIEW

Build a full-stack, responsive **Online Examination Management System**. The system has two completely separate roles — **Admin** and **Student** — each with their own dedicated login URL, session namespace, and access controls. No shared login page exists between the two roles.

---

## 🔐 AUTHENTICATION — FULLY SEPARATED PORTALS

### Why Separate Logins
A single shared login endpoint is a common attack surface: user enumeration, credential stuffing, and privilege escalation all become easier when admin and student credentials go through the same form. Separate portals mitigate these risks by:
- Keeping admin login off the default URL (obscurity + access control layered together)
- Applying stricter brute-force protection on the admin portal
- Using entirely separate PHP session namespaces so a compromised student session can never escalate to admin

---

### Admin Login (`/admin/login.php`)

- Accessible only via a non-obvious path, e.g. `/admin/login.php` (document this path internally; do not link it from the public homepage).
- Fields: Username, Password, CSRF hidden token.
- On POST:
  - Validate CSRF token.
  - Look up username in `admins` table only.
  - Verify password with `password_verify()`.
  - On success: start session with `session_name('ADMIN_SESSION')` before `session_start()`, set `$_SESSION['admin_id']` and `$_SESSION['role'] = 'admin'`.
  - On failure: increment `login_attempts` counter in DB for that username. After 5 failures in 15 minutes, lock the account and alert admin via a flag.
  - Redirect to `/admin/dashboard.php`.
- No "Forgot password" link (admin password reset done manually via CLI/setup script).

### Student Login (`/student/login.php`)

- Publicly accessible via `/student/login.php` or from the homepage.
- Fields: Username (Student ID), Password, CSRF hidden token.
- On POST:
  - Validate CSRF token.
  - Look up username in `students` table only — never queries `admins` table.
  - Verify password with `password_verify()`.
  - On success: start session with `session_name('STUDENT_SESSION')` before `session_start()`, set `$_SESSION['student_id']` and `$_SESSION['role'] = 'student'`.
  - On failure: show generic "Invalid credentials" error (never reveal whether username or password was wrong).
  - Redirect to `/student/dashboard.php`.

### Session Guards
- Every admin page: call `require_once 'includes/admin_auth.php'` which verifies `session_name('ADMIN_SESSION')`, `session_start()`, and checks `$_SESSION['role'] === 'admin'`. On failure: destroy session, redirect to `/admin/login.php`.
- Every student page: call `require_once 'includes/student_auth.php'` which verifies `session_name('STUDENT_SESSION')`, `session_start()`, and checks `$_SESSION['role'] === 'student'`. On failure: destroy session, redirect to `/student/login.php`.
- Admin pages are completely unreachable from a valid student session, and vice versa.

### Logout
- `/admin/logout.php`: destroys `ADMIN_SESSION`, redirects to `/admin/login.php`.
- `/student/logout.php`: destroys `STUDENT_SESSION`, redirects to `/student/login.php`.

---

## 🗄️ DATABASE SCHEMA

```sql
-- Lookup tables
boards   (id INT PK AUTO_INCREMENT, name VARCHAR(50))
         -- Seed: 'WB Board', 'CBSE', 'ICSE', 'ISC'

classes  (id INT PK AUTO_INCREMENT, name VARCHAR(10))
         -- Seed: '8', '9', '10', '11', '12'

-- Admin accounts
admins   (id, username, password_hash, login_attempts INT DEFAULT 0,
          locked_until DATETIME NULL, created_at)

-- Persistent Question Bank (never deleted, reused across exams)
questions (id, class_id FK, board_id FK, question_text TEXT,
           option1 VARCHAR(500), option2 VARCHAR(500),
           option3 VARCHAR(500), option4 VARCHAR(500),
           correct_option TINYINT(1),   -- 1, 2, 3, or 4
           created_at, updated_at)

-- Exams
exams (id, title VARCHAR(255), class_id FK, board_id FK,
       status ENUM('draft','published','completed'),
       scheduled_date DATE, scheduled_time TIME,
       duration_minutes INT,
       created_at, published_at DATETIME NULL)

-- Exam ↔ Question mapping (ordered)
exam_questions (id, exam_id FK, question_id FK, order_no INT)

-- Students
students (id, full_name VARCHAR(150), email VARCHAR(200),
          class_id FK, board_id FK,
          username VARCHAR(80) UNIQUE,
          password_hash VARCHAR(255),
          plain_password VARCHAR(50),    -- admin display only
          is_active TINYINT(1) DEFAULT 1,
          created_at)

-- Enrolments
enrolments (id, exam_id FK, student_id FK, enrolled_at,
            UNIQUE KEY (exam_id, student_id))

-- Attempt header
attempts (id, exam_id FK, student_id FK,
          started_at DATETIME, submitted_at DATETIME NULL,
          score INT DEFAULT 0, total_questions INT,
          is_submitted TINYINT(1) DEFAULT 0)

-- Per-question answers
attempt_answers (id, attempt_id FK, question_id FK,
                 chosen_option TINYINT(1))   -- 1–4, NULL if skipped

-- Answer key publication log
answer_releases (id, exam_id FK UNIQUE,
                 released_at DATETIME,
                 released_by_admin_id FK)

-- Shareable report tokens
report_links (id, exam_id FK, token VARCHAR(64) UNIQUE,
              created_at, expires_at DATETIME)
```

---

## 🛠️ ADMIN MODULE

### Layout
Fixed left sidebar (Bootstrap `d-none d-md-block col-md-2`) with navigation sections:
- **Questions** → Upload CSV, View/Edit Bank
- **Exams** → Create Exam, All Exams, Schedule
- **Students** → Add Student, Manage Students, Enrolments
- **Reports** → Attendance, Results, Generate Report
- **Account** → Logout

Top navbar: Admin name on right, hamburger toggle for mobile.

---

### Page 1 — Dashboard (`/admin/dashboard.php`)

Bootstrap 4 card row showing live counts:
- Total questions in bank
- Total active students
- Upcoming exams (scheduled, not yet started)
- Completed exams

Recent activity feed (last 5 exams with status badges).

---

### Page 2 — Upload Questions via CSV (`/admin/questions/upload.php`)

**CSV format (strict — first row must be exact header):**
```
Class,Board,Question,Option 1,Option 2,Option 3,Option 4,Correct Option
10,CBSE,What is the SI unit of force?,Newton,Joule,Pascal,Watt,1
```

**Field rules:**
- `Class`: integer 8–12 only
- `Board`: exactly one of `WB Board`, `CBSE`, `ICSE`, `ISC`
- `Question`: non-empty string
- `Option 1–4`: non-empty strings
- `Correct Option`: integer 1, 2, 3, or 4

**UI flow:**
1. File input (`accept=".csv"`, max 2 MB) + **Upload & Preview** button.
2. Server-side parse with PHP `fgetcsv()`.
3. Show a **preview table** (Bootstrap `table-striped table-hover`) of all rows before final commit. Each invalid row highlighted in red with inline error note.
4. Two buttons: **Confirm Import** (imports only valid rows) and **Cancel**.
5. After import: success summary "X questions imported, Y rows skipped."
6. **Download Sample CSV** link always visible on this page.

**Duplicate handling:** Skip rows where `class_id + board_id + TRIM(question_text)` already exists in `questions` table. Report skipped duplicates separately.

---

### Page 3 — View & Edit Question Bank (`/admin/questions/index.php`)

- Filter bar: **Class** dropdown (8–12) + **Board** dropdown + Search box (searches question text).
- DataTables.js table with columns: #, Class, Board, Question (truncated 80 chars), Correct Option, Last Updated, Actions.
- **Edit** (pencil icon): opens Bootstrap modal with full form — question textarea, 4 option inputs, correct option select. On save: AJAX POST to `questions/update_ajax.php` → updates DB → refreshes row.
- **Delete** (trash icon): confirmation modal → hard delete only if question is not linked to any exam; otherwise soft-mark as `archived`.
- **View Full Question** (eye icon): modal showing complete question with all options and correct answer highlighted.

---

### Page 4 — Create Exam (`/admin/exams/create.php`)

**Step 1 — Exam Settings (Bootstrap card form):**
- Exam Title
- Class selector (dropdown: Class 8 → Class 12)
- Board selector (dropdown: WB Board / CBSE / ICSE / ISC)
- Scheduled Date (Flatpickr date picker, must be today or future)
- Scheduled Time (Flatpickr time picker, 24-hour format)
- Duration (minutes, number input, min 10)
- Save as Draft button

**Step 2 — Select Questions (`/admin/exams/select_questions.php?exam_id=X`):**
- Auto-filtered to the exam's class + board.
- Searchable question list with checkboxes.
- Live counter: "X questions selected."
- Select All / Deselect All controls.
- Questions can be reordered via drag-and-drop (JS `dragstart`/`dragover`/`drop`).
- Save Selection button.

**Step 3 — Preview (`/admin/exams/preview.php?exam_id=X`):**
- Exam metadata banner (title, class, board, scheduled time, duration).
- All questions rendered in numbered Bootstrap cards.
- All 4 options shown; correct option highlighted with a green badge `✓ Correct`.
- **Publish Exam** button → confirmation modal → POST to `exams/publish.php` → sets `status = 'published'` and records `published_at`.
- **Edit Questions** button → back to Step 2.
- **Edit Settings** button → back to Step 1.

---

### Page 5 — All Exams (`/admin/exams/index.php`)

Table: Title, Class, Board, Scheduled Date, Scheduled Time, Duration, Status (badge), Questions Count, Enrolled Students, Actions.

Status badges: `draft` (secondary), `published` (primary), `completed` (success).

Actions per exam:
- Preview | Edit (draft only) | View Attendance | View Results | Publish Answers (completed only, if not yet released) | Clone | Delete (draft only)

**Clone exam**: copies exam settings + question selection as a new draft with a new title prefix "Copy of …"

---

### Page 6 — Schedule View (`/admin/schedule/index.php`)

Table sorted by date ascending. Columns: Date, Time, Exam Title, Class, Board, Duration, Status, Enrolled Count.

Color-coded rows: Yellow = today, Blue = future, Green = completed.

---

### Page 7 — Student Management (`/admin/students/index.php`)

**Add Student (`/admin/students/create.php`):**
- Full Name, Email (optional), Class (8–12), Board.
- Auto-generate username: lowercase `firstname` + `.` + `class` + `board_abbreviation` + random 3-digit number. Example: `rahul.10cbse847`.
- Auto-generate password: random 8-character alphanumeric (mix of upper, lower, digits). Example: `Kx3mP9qZ`.
- On save: show credentials in a styled green alert box with a **Copy Credentials** button (JS `navigator.clipboard`).
- Store `password_hash(password)` in DB; also store raw password in `plain_password` for admin display.

**Manage Students (`/admin/students/index.php`):**
- DataTables table: #, Name, Username, Class, Board, Password (hidden `●●●●●●●●` with eye toggle), Status, Actions.
- Filter by Class and Board.
- **Edit** → modal form: Name, Email, Class, Board, Active toggle, **Reset Password** button (generates new password, shows it in modal, saves hash + plain).
- **Delete** → confirmation modal → if student has no attempt records: hard delete. If they do: soft-delete (`is_active = 0`).
- **View** → modal showing student detail + list of exams enrolled.

---

### Page 8 — Enrolments (`/admin/enrolments/index.php`)

- Select exam from dropdown.
- Show all students whose class + board match the exam.
- Checkbox list for individual enrolment.
- **Enrol All Matching** button.
- **Remove All** button.
- Real-time enrolled count badge.
- Students already enrolled shown with filled checkbox and enrolled timestamp.

---

### Page 9 — Attendance Tracker (`/admin/reports/attendance.php`)

- Exam selector dropdown.
- Once exam selected, show:
  - Summary bar: Total Enrolled | Attempted | Not Attempted | Participation % (Bootstrap progress bar).
  - **Attempted** tab (Bootstrap nav-tab): table with Name, Username, Score, Total Q, Percentage, Time Submitted.
  - **Not Attempted** tab: table with Name, Username, Class, Enrolled (Yes confirmed).
- Export attendance list as CSV download button (PHP `fputcsv` output with `Content-Type: text/csv` header).

---

### Page 10 — Publish Answer Key (`/admin/exams/release_answers.php?exam_id=X`)

- Only displayed for exams where current datetime ≥ `scheduled_date + scheduled_time + duration_minutes`.
- Shows exam title, date, number of students who attempted.
- **Publish Answer Key** button (large, Bootstrap `btn-success`).
- On click: confirmation modal "Once published, all enrolled students who attempted this exam will be able to see their answers and the correct answers. This cannot be undone." → POST → insert into `answer_releases`.
- After publish: button replaced with green "✓ Answer Key Published on [datetime]" badge.
- Enrolled students who attempted will now see a **Review My Answers** button on their dashboard.

---

### Page 11 — Results & Reports (`/admin/reports/results.php`)

Select exam → show:

**Summary card row:**
- Total attempted, Average score (%), Highest score, Lowest score, Pass count (score ≥ 40%), Fail count.

**Student results table:**
- Name, Username, Score (X/Y), Percentage, Result (Pass/Fail badge), Time Taken (minutes).

**Export options:**
1. **Download PDF**: PHP mPDF or TCPDF library generates a formatted PDF with school header placeholder, exam title, date, summary stats, and student results table. File: `report_[exam_title]_[date].pdf`.
2. **Generate Shareable Link**: POST to `reports/generate_link.php` → creates 64-char random token → stores in `report_links` with `expires_at = NOW() + 7 DAYS` → returns full URL `/report/view.php?token=XXXX` → shown in a copyable input box. Clicking the link requires no login.

---

### Page 12 — Previous Exams Archive (`/admin/exams/history.php`)

Table of all `status = 'completed'` exams. Columns: Title, Class, Board, Date, Attempted / Enrolled, Avg %, Answer Key Released (Yes/No badge), Actions (View Report, View Attendance, Publish Answers if not yet done).

---

## 🎓 STUDENT MODULE

### Layout
Top navbar: Logo left, Student name + Class + Board center, Logout right. No sidebar (clean single-column layout for exam focus).

---

### Page 1 — Student Dashboard (`/student/dashboard.php`)

Three Bootstrap tabs:

---

**Tab 1: Upcoming Exams 🔒**

Shows all published exams the student is enrolled in where `scheduled_date + scheduled_time > NOW()`.

Each exam shown as a Bootstrap card with:
- Exam Title, Class, Board
- Scheduled Date and Time
- Duration
- **Locked** badge (Bootstrap `badge-secondary`) + padlock icon
- Countdown timer (JS, format `Xd Xh Xm Xs`, updated every second via `setInterval`)
- Start button: disabled and greyed out
- No questions or options are visible to the student

JS checks every 30 seconds: if countdown reaches zero, re-enables the Start button without requiring a full page reload (move exam card to Tab 2 dynamically, or at minimum show an alert "Your exam is now available — click to start.").

---

**Tab 2: Available Exams ▶️**

Shows exams where:
- Student is enrolled
- `scheduled_date + scheduled_time <= NOW()`
- Student has NOT yet submitted (`is_submitted = 0` or no attempt record)

Each card shows: Exam Title, Class, Board, Duration, **Start Exam** button (Bootstrap `btn-primary`).

If an attempt exists but `is_submitted = 0` (started but not submitted / browser crashed): show **Resume Exam** button instead.

---

**Tab 3: Completed Exams ✅**

Shows all exams the student has submitted.

Each card shows:
- Exam Title, Date Taken
- Score: `X / Y` and percentage
- Time taken (minutes)
- If `answer_releases` record exists for this exam:
  → **Review My Answers** button (Bootstrap `btn-outline-info`)
- If not yet released:
  → Grey badge "Answer key not yet published"

---

### Page 2 — Take Exam (`/student/exam.php?exam_id=X`)

**Access guards (server-side):**
1. Student is enrolled in this exam.
2. `scheduled_date + scheduled_time <= NOW()` (time has arrived).
3. Student has not already submitted.
4. Exam status is `published`.

**If any guard fails:** show a friendly error page (not a raw error).

**UI:**

- Full-width top bar (sticky): Exam title left | **Countdown timer** center (red when < 5 minutes) | "Questions Answered: X / Y" right.
- All questions displayed on a single scrollable page (no pagination — simpler and avoids lost-progress on navigation).
- Each question in a Bootstrap card:
  - Question number + text
  - 4 Bootstrap custom radio buttons (one per option)
  - Radio selection saves to `localStorage` as backup on every change (key: `exam_[exam_id]_q_[question_id]`)
- **Right sidebar (Bootstrap col-lg-3, collapsible on mobile):** numbered question buttons. Green = answered, white = unanswered. Click a number → smooth scroll to that question.
- **Submit Exam** button (sticky bottom bar, Bootstrap `fixed-bottom`): triggers confirmation modal.
  - Modal text: "You have answered X of Y questions. Once submitted, you cannot change your answers. Are you sure?"
  - **Confirm Submit** button → POST to `/student/submit.php`.
- **Auto-submit:** When countdown hits zero, JS triggers the submit form automatically.

---

### Page 3 — Submit (`/student/submit.php`)

POST handler (not a rendered page):
1. Validate CSRF token.
2. Verify all access guards again server-side.
3. Insert `attempts` record with `submitted_at = NOW()`, `is_submitted = 1`.
4. Insert one `attempt_answers` row per question (use submitted POST values; NULL for unanswered).
5. Calculate `score` (count questions where `chosen_option = correct_option`).
6. Update `attempts.score`.
7. Clear `localStorage` keys for this exam (JS redirect with a `#clear` hash triggers JS cleanup on next page).
8. Redirect to `/student/result.php?attempt_id=X`.

---

### Page 4 — Result Summary (`/student/result.php?attempt_id=X`)

Show immediately after submission:
- Exam title, date
- Score: large display `X / Y`
- Percentage and Pass/Fail badge
- Time taken
- "Return to Dashboard" button
- Note: "Your detailed answer review will be available once the teacher publishes the answer key."

---

### Page 5 — Answer Review (`/student/review.php?attempt_id=X`)

**Access guard:** `answer_releases` record must exist for this exam.

For each question:
- Question number and text
- 4 options displayed as list-group items:
  - Student's chosen option: highlighted blue (`list-group-item-primary`) with label "Your Answer"
  - Correct option: highlighted green (`list-group-item-success`) with label "✓ Correct Answer"
  - If student's answer = correct answer: show green only with "✓ Your Answer (Correct)"
  - If student's answer ≠ correct answer: show blue (wrong) + green (correct) separately
  - Unanswered questions: all options grey, correct option shown in green, label "You skipped this question"
- Score summary at top of page.

---

## 🎨 UI / UX REQUIREMENTS

- **Bootstrap 4.6** (jsDelivr or cdnjs CDN).
- **jQuery 3.6** (required for DataTables and Bootstrap JS components).
- **DataTables 1.13** (CDN) on all admin listing tables — sorting, searching, pagination built-in.
- **Flatpickr** (CDN) for all date and time inputs.
- **Font Awesome 5** (CDN) for all icons (lock, eye, trash, pencil, check, etc.)
- **Bootstrap toasts** for AJAX save confirmations (bottom-right, auto-dismiss 3s).
- **Bootstrap modals** for all confirmations (delete, publish, submit exam, reset password).
- **Bootstrap badges** for status labels throughout.
- All forms: client-side validation via Bootstrap's built-in `was-validated` class + `required` attributes before POST.
- Mobile: exam-taking page must be fully functional on 320px+ screens. Sidebar question navigator collapses to a scrollable horizontal chip row on mobile.

---

## 📁 FOLDER & FILE STRUCTURE

```
/                               ← Web root
├── index.php                   ← Redirects to /student/login.php
│
├── admin/
│   ├── login.php               ← Admin-only login portal
│   ├── logout.php
│   ├── dashboard.php
│   ├── questions/
│   │   ├── index.php           ← View/Edit/Delete question bank
│   │   ├── upload.php          ← CSV upload + preview
│   │   ├── update_ajax.php     ← AJAX edit handler
│   │   └── delete.php
│   ├── exams/
│   │   ├── index.php           ← All exams list
│   │   ├── create.php          ← Step 1: exam settings
│   │   ├── select_questions.php← Step 2: pick questions
│   │   ├── preview.php         ← Step 3: preview + publish
│   │   ├── publish.php         ← POST handler
│   │   ├── release_answers.php ← Publish answer key
│   │   ├── clone.php           ← Clone exam as draft
│   │   ├── history.php         ← Past exams archive
│   │   └── delete.php
│   ├── students/
│   │   ├── index.php           ← Student list (CRUD)
│   │   ├── create.php
│   │   ├── edit.php
│   │   ├── delete.php
│   │   └── reset_password.php  ← AJAX password reset
│   ├── enrolments/
│   │   └── index.php
│   ├── schedule/
│   │   └── index.php
│   └── reports/
│       ├── attendance.php
│       ├── results.php
│       ├── generate_link.php   ← Token generator
│       └── export_pdf.php      ← mPDF/TCPDF handler
│
├── student/
│   ├── login.php               ← Student-only login portal
│   ├── logout.php
│   ├── dashboard.php
│   ├── exam.php                ← Take exam
│   ├── submit.php              ← POST handler
│   ├── result.php              ← Score summary
│   └── review.php              ← Answer review
│
├── report/
│   └── view.php                ← Public shareable report (token-gated)
│
├── config/
│   └── db.php                  ← PDO connection singleton
│
├── includes/
│   ├── admin_auth.php          ← Admin session guard
│   ├── student_auth.php        ← Student session guard
│   ├── admin_header.php        ← Admin layout top + sidebar
│   ├── admin_footer.php
│   ├── student_header.php      ← Student layout navbar
│   ├── student_footer.php
│   └── functions.php           ← Shared helpers (CSRF, password gen, etc.)
│
├── assets/
│   ├── css/
│   │   └── custom.css
│   └── js/
│       └── app.js
│
├── uploads/
│   └── csv/                    ← Temp CSV storage (gitignored)
│
└── setup/
    ├── install.sql             ← Full schema + seed data
    └── README.md               ← Setup instructions
```

---

## 🔒 SECURITY REQUIREMENTS (FULL LIST)

| Requirement | Implementation |
|---|---|
| Separate login portals | Separate URLs, separate session names, separate DB tables queried |
| CSRF protection | Random token in `$_SESSION`, hidden field in every form, verified on every POST |
| SQL injection prevention | 100% PDO prepared statements, zero raw string concatenation in SQL |
| Password storage | `password_hash()` (bcrypt, cost 12) for all stored passwords |
| Admin brute-force protection | Lock account after 5 failed logins in 15 min; unlock via setup script |
| Session fixation prevention | `session_regenerate_id(true)` after every successful login |
| Session namespace isolation | `session_name('ADMIN_SESSION')` vs `session_name('STUDENT_SESSION')` |
| XSS prevention | All output wrapped in `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` |
| File upload safety | Validate MIME type server-side (`finfo_file`), check extension, reject non-CSV |
| Role enforcement | Every page checks role from session; no role can access the other's pages |
| Exam access enforcement | Student can only start exam they are enrolled in, at or after scheduled time, if not already submitted |
| Shareable report security | Token is 64 random hex chars; expires in 7 days; shows read-only data only |
| Error handling | Custom error pages (`404.php`, `403.php`); never expose PHP errors in production (`display_errors = 0`) |

---

## ⚙️ BUSINESS LOGIC RULES

1. **Question bank is permanent.** Questions uploaded via CSV persist forever in the `questions` table for future exam reuse. Deleting an exam does NOT delete its questions from the bank.
2. **Exam status flow:** `draft` → `published` → `completed`. Transitions are one-way. A published exam cannot be reverted to draft.
3. **Auto-complete logic.** On any page load, run a check: update all published exams to `completed` where `DATETIME(scheduled_date, scheduled_time) + INTERVAL duration_minutes MINUTE < NOW()`. This is a lightweight SQL UPDATE run on every admin page load (no cron required for small deployments).
4. **Answer key publish guard.** The "Publish Answer Key" option is only available on the admin side once the exam is `completed`. Students cannot see answers before the admin explicitly publishes.
5. **Resume exam.** If a student opened an exam but did not submit (e.g., browser closed), their radio selections are partially saved in `localStorage`. On re-entry, JS reloads saved answers from `localStorage` and re-checks radio buttons automatically.
6. **Unanswered questions.** Unanswered questions score 0. No negative marking.
7. **Duplicate enrolment prevention.** DB `UNIQUE KEY (exam_id, student_id)` on `enrolments` table; PHP checks before insert and shows friendly "already enrolled" message.
8. **Password display.** Plain passwords stored in `plain_password` column are only visible in the admin panel with an eye-toggle. Never exposed to students via any page or API endpoint.
9. **Shareable report link.** Publicly accessible without login. Shows exam summary + student results table. Does NOT show individual student answer breakdowns — only Name, Score, Percentage.
10. **CSV sample file.** A static file `/uploads/csv/sample_questions.csv` with 3 example rows is always available for download from the upload page.

---

## 📦 THIRD-PARTY DEPENDENCIES

| Library | Version | Source | Purpose |
|---|---|---|---|
| Bootstrap | 4.6.2 | CDN | UI framework |
| jQuery | 3.6.4 | CDN | DOM + AJAX |
| DataTables | 1.13 | CDN | Admin tables |
| Flatpickr | 4.6 | CDN | Date/time pickers |
| Font Awesome | 5.15 | CDN | Icons |
| mPDF or TCPDF | latest | Composer | PDF generation |

> **No npm, no webpack, no build step.** All JS/CSS via CDN `<script>` and `<link>` tags. PHP dependencies via Composer only for PDF library.

---

## 🧪 SEED DATA (`setup/install.sql`)

- 4 boards: WB Board, CBSE, ICSE, ISC
- 5 classes: 8, 9, 10, 11, 12
- 1 admin: username `admin`, password `Admin@1234` (hashed with bcrypt)
- 5 sample students (Class 10, CBSE) with pre-generated usernames and passwords
- 15 sample questions (Class 10, CBSE, mix of topics)
- 1 sample exam: title "Class 10 CBSE Science Mock Test", scheduled 2 hours from install time, all 15 questions included, all 5 students enrolled, status `published`

---

## ✅ DELIVERABLES CHECKLIST

**Database**
- [ ] `setup/install.sql` with full schema, foreign keys, indexes, and seed data

**Admin Portal**
- [ ] Separate login page at `/admin/login.php` with brute-force protection
- [ ] Dashboard with summary cards
- [ ] CSV upload with server-side parse, row-level validation, and preview table
- [ ] Sample CSV download link
- [ ] Question bank view with DataTables, inline edit via AJAX modal, delete with guard
- [ ] Exam creation: 3-step flow (settings → question selection → preview → publish)
- [ ] Exam clone functionality
- [ ] All exams list with status badges and per-exam actions
- [ ] Schedule view
- [ ] Student CRUD with auto-generated credentials and password visibility toggle
- [ ] Password reset per student
- [ ] Enrolment management per exam
- [ ] Attendance tracker (attempted vs not attempted) with CSV export
- [ ] Results page with summary stats and student table
- [ ] PDF report download
- [ ] Shareable report link with 7-day expiry token
- [ ] Answer key publish (gated to completed exams only)
- [ ] Previous exams archive

**Student Portal**
- [ ] Separate login page at `/student/login.php`
- [ ] Dashboard with 3 tabs: Upcoming (locked + countdown), Available, Completed
- [ ] Exam page with sticky countdown, question navigator, all-on-one-page layout, auto-submit
- [ ] localStorage answer backup + resume on re-entry
- [ ] Submit handler with score calculation
- [ ] Result summary page
- [ ] Answer review page (gated to answer-key-published exams)

**Security**
- [ ] Fully isolated session namespaces (ADMIN_SESSION vs STUDENT_SESSION)
- [ ] CSRF token on every form
- [ ] 100% PDO prepared statements
- [ ] Role guard on every page
- [ ] Exam access guards (enrolment + time + not-submitted checks)
- [ ] XSS output escaping throughout
- [ ] File upload MIME + extension validation

# Docker Setup for Online Exam System

This project is fully containerized using **Docker** and **Docker Compose**, providing a complete environment with PHP 8.2 (Apache), MariaDB 10.11, and phpMyAdmin.

---

## 🚀 Quick Start

### 1. Prerequisites
- Install and launch **Docker Desktop** on your machine.
- Ensure Docker is running (check via Docker Desktop icon in your system tray).

### 2. Start the Application
Open a terminal in the project directory (`c:\xampp\htdocs\shreyansir`) and run:

```bash
docker compose up -d --build
```

Docker will:
1. Build the PHP 8.2 Apache web container and configure Apache modules (`rewrite`, `headers`).
2. Pull and start the MariaDB 10.11 database container.
3. Automatically run `docker/init.sql` on the first startup to initialize the complete database schema, classes, boards, admin, and test data.
4. Launch phpMyAdmin for visual database administration.

---

## 🌐 URLs & Access Points

| Service | URL | Credentials / Notes |
| :--- | :--- | :--- |
| **Web Portal** | [http://localhost:8080/shreyansir](http://localhost:8080/shreyansir) <br> *(or http://localhost:8080/)* | Main site entry |
| **Admin Login** | [http://localhost:8080/shreyansir/admin/login.php](http://localhost:8080/shreyansir/admin/login.php) | **Username:** `admin`<br>**Password:** `admin123` |
| **Student Login** | [http://localhost:8080/shreyansir/student/login.php](http://localhost:8080/shreyansir/student/login.php) | Pre-seeded accounts (e.g. `rahul.10cbse101` / `Pass123`) |
| **phpMyAdmin** | [http://localhost:8081](http://localhost:8081) | **Server:** `db`<br>**User:** `root`<br>**Password:** `root_secret` |
| **Direct MySQL Port** | `localhost:3307` | Use TablePlus, DBeaver, or MySQL Workbench |

---

## 🛠️ Common Docker Commands

- **Stop containers:**
  ```bash
  docker compose down
  ```
- **Stop containers and delete volumes (resets database to initial seed):**
  ```bash
  docker compose down -v
  ```
- **View live container logs:**
  ```bash
  docker compose logs -f
  ```
- **View logs for web application only:**
  ```bash
  docker compose logs -f app
  ```
- **Execute commands inside the PHP container:**
  ```bash
  docker compose exec app bash
  ```

---

## 🔒 Preserving Database & Uploads on Hostinger Updates

When deploying to or updating Docker on **Hostinger** (Hostinger VPS or Cloud Docker):

### How Data is Preserved:
1. **Named Volumes (`shreyansir_db_data` & `shreyansir_uploads_data`)**:
   - The database files (`/var/lib/mysql`) are mapped to the dedicated volume `shreyansir_db_data`.
   - All uploaded CSVs and student assets (`/var/www/html/uploads`) are mapped to `shreyansir_uploads_data`.
   - **Docker NEVER deletes named volumes** during image updates or container rebuilds.
2. **Selective Container Rebuild (`--no-deps`)**:
   - The database container (`shreyansir_db`) does NOT need to be rebuilt or stopped when you update your application code.
3. **Automatic Pre-Update Backups**:
   - Before applying updates, an automated SQL dump is taken into the `backups/` directory.
4. **Safe Schema Migrations**:
   - The [`docker/migrate.php`](file:///c:/xampp/htdocs/shreyansir/docker/migrate.php) script automatically applies new columns or tables using `ADD COLUMN IF NOT EXISTS`, preserving all existing rows and student accounts.

---

### 🚀 One-Command Safe Update on Hostinger VPS:

On your Hostinger VPS, simply run:

```bash
bash scripts/update.sh
```

This single command automatically:
1. Takes a timestamped backup of your live database to `backups/`.
2. Pulls the latest code (`git pull`).
3. Rebuilds the updated application image (`docker compose build app`).
4. Replaces the web app container (`docker compose up -d --no-deps app`) without interrupting the database.
5. Runs schema migrations to add any new columns or tables safely.
6. Cleans up old Docker image layers.

### 🔄 Manual Database Backup & Restore:

- **Create a manual backup:**
  ```bash
  bash scripts/backup.sh
  ```
- **Restore from a backup:**
  ```bash
  bash scripts/restore.sh backups/backup_YYYYMMDD_HHMMSS.sql.gz
  ```


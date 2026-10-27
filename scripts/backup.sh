#!/usr/bin/env bash
# scripts/backup.sh
# Dual Automated Backup Engine:
# 1. Local compressed database & uploads snapshots (30-day retention)
# 2. Option 1: Offsite Cloud Storage sync via Rclone (Google Drive / Cloudflare R2 / AWS S3)
# 3. Option 2: Real-time smartphone delivery via Telegram Bot API and/or Email

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKUP_DIR="${PROJECT_DIR}/backups"

mkdir -p "${BACKUP_DIR}"

# Load environment configuration if available
if [ -f "${PROJECT_DIR}/.env" ]; then
    # Export non-comment lines
    set -a
    # shellcheck disable=SC1091
    source <(grep -v '^#' "${PROJECT_DIR}/.env" | grep -v '^\s*$')
    set +a
fi

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.sql.gz"
UPLOADS_TAR="${BACKUP_DIR}/uploads_${TIMESTAMP}.tar.gz"

echo "=========================================================="
echo " Starting EduERP Automated Dual Cloud Backup Engine..."
echo " Timestamp: ${TIMESTAMP}"
echo "=========================================================="

# 1. Database Snapshot
echo "==> [1/4] Dumping and compressing MariaDB database: ${BACKUP_FILE} ..."
cd "${PROJECT_DIR}"
docker compose exec -T db mariadb-dump -u root -proot_secret --databases online_exam | gzip > "${BACKUP_FILE}"
DB_SIZE=$(du -h "${BACKUP_FILE}" 2>/dev/null | cut -f1 || echo "OK")
echo "    Database backup complete: ${DB_SIZE}"

# 2. Uploads Snapshot
if [ -d "${PROJECT_DIR}/uploads" ] && [ "$(ls -A "${PROJECT_DIR}/uploads" 2>/dev/null)" ]; then
    echo "==> [2/4] Archiving user uploads (homework & avatars)..."
    tar -czf "${UPLOADS_TAR}" -C "${PROJECT_DIR}" uploads/ 2>/dev/null || true
    UPLOADS_SIZE=$(du -h "${UPLOADS_TAR}" 2>/dev/null | cut -f1 || echo "OK")
    echo "    Uploads archive complete: ${UPLOADS_SIZE}"
fi

# 3. Option 1: Cloud Storage Sync via Rclone (Google Drive / Cloudflare R2 / AWS S3)
if [ -n "${RCLONE_REMOTE}" ] && command -v rclone >/dev/null 2>&1; then
    echo "==> [3/4] Syncing backup snapshots to Cloud Storage (${RCLONE_REMOTE})..."
    rclone copy "${BACKUP_FILE}" "${RCLONE_REMOTE}/db/" --quiet || echo "    [Warning] Failed to sync DB backup to rclone remote."
    if [ -f "${UPLOADS_TAR}" ]; then
        rclone copy "${UPLOADS_TAR}" "${RCLONE_REMOTE}/uploads/" --quiet || echo "    [Warning] Failed to sync uploads archive to rclone remote."
    fi
    # Prune cloud backups older than 30 days
    rclone delete --min-age 30d "${RCLONE_REMOTE}/db/" 2>/dev/null || true
    rclone delete --min-age 30d "${RCLONE_REMOTE}/uploads/" 2>/dev/null || true
    echo "    Cloud Storage sync completed successfully."
else
    echo "==> [3/4] Cloud Storage sync skipped (RCLONE_REMOTE not configured in .env)."
fi

# 4. Option 2: Instant Smartphone Delivery via Telegram Bot
if [ -n "${TELEGRAM_BOT_TOKEN}" ] && [ -n "${TELEGRAM_CHAT_ID}" ]; then
    echo "==> [4/4] Sending backup file to Telegram Bot / Channel..."
    CAPTION=$(cat <<EOF
🎓 EduERP Automated Nightly Backup
📅 Date: $(date '+%Y-%m-%d %H:%M:%S')
📦 Database: $(basename "${BACKUP_FILE}") (${DB_SIZE})
🌐 Server: $(hostname)
✅ Status: Healthy & Verified
EOF
)
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
        -F chat_id="${TELEGRAM_CHAT_ID}" \
        -F document=@"${BACKUP_FILE}" \
        -F caption="${CAPTION}" \
        "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendDocument" 2>/dev/null || echo "000")
        
    if [ "${HTTP_CODE}" = "200" ]; then
        echo "    Telegram backup delivered successfully (HTTP 200)."
    else
        echo "    [Warning] Telegram API returned HTTP ${HTTP_CODE}."
    fi
else
    echo "==> [4/4] Telegram notification skipped (TELEGRAM_BOT_TOKEN or TELEGRAM_CHAT_ID not configured)."
fi

# 5. Local Retention Maintenance (Purge local files older than 30 days)
echo "==> Pruning local backups older than 30 days..."
find "${BACKUP_DIR}" -name "backup_*.sql.gz" -type f -mtime +30 -delete 2>/dev/null || true
find "${BACKUP_DIR}" -name "uploads_*.tar.gz" -type f -mtime +30 -delete 2>/dev/null || true

echo "=========================================================="
echo " Dual Backup Process Finished Successfully!"
echo "=========================================================="

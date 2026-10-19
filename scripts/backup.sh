#!/usr/bin/env bash
# scripts/backup.sh
# Creates a timestamped, compressed database backup from the running db container.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKUP_DIR="${PROJECT_DIR}/backups"

mkdir -p "${BACKUP_DIR}"

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.sql.gz"

echo "==> Creating database backup: ${BACKUP_FILE} ..."

cd "${PROJECT_DIR}"
docker compose exec -T db mariadb-dump -u root -proot_secret --databases online_exam | gzip > "${BACKUP_FILE}"

echo "==> Backup completed successfully ($(du -h "${BACKUP_FILE}" 2>/dev/null | cut -f1 || echo "OK"))"

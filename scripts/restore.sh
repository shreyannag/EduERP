#!/usr/bin/env bash
# scripts/restore.sh
# Restores a database backup into the active MariaDB container.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [ -z "$1" ]; then
    echo "Usage: bash scripts/restore.sh <path_to_backup_file>"
    echo "Example: bash scripts/restore.sh backups/backup_20261014_120000.sql.gz"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "${BACKUP_FILE}" ]; then
    echo "Error: Backup file '${BACKUP_FILE}' not found!"
    exit 1
fi

echo "WARNING: This will overwrite data in the active 'online_exam' database."
read -p "Are you sure you want to proceed? (yes/no): " CONFIRM
if [ "${CONFIRM}" != "yes" ]; then
    echo "Restore cancelled."
    exit 0
fi

cd "${PROJECT_DIR}"

echo "Restoring database from ${BACKUP_FILE}..."
if [[ "${BACKUP_FILE}" == *.gz ]]; then
    gunzip -c "${BACKUP_FILE}" | docker compose exec -T db mariadb -u root -proot_secret online_exam
else
    docker compose exec -T db mariadb -u root -proot_secret online_exam < "${BACKUP_FILE}"
fi

echo "Database restored successfully!"

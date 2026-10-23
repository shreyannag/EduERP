#!/usr/bin/env bash
# scripts/publish.sh
# Fast, safe, zero-data-loss publisher for new updates.
# NEVER touches or deletes the database container or persistent storage.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_DIR}"

echo "=========================================================="
echo " Publishing EduERP Updates Safely..."
echo "=========================================================="

# 1. Always create a pre-update safety backup first
echo "[1/4] Creating pre-update database backup..."
bash "${SCRIPT_DIR}/backup.sh"

# 2. Pull latest code from repository
echo "[2/4] Pulling latest code from git..."
if [ -d ".git" ]; then
    git pull origin main || git pull
else
    echo "No .git directory found, skipping git pull."
fi

# 3. Reload application container (db container is untouched and stays running)
echo "[3/4] Reloading application container..."
docker compose restart app

# 4. Run safe schema migrations (only adds new columns/tables without touching existing data)
echo "[4/4] Applying database migrations..."
docker compose exec -T app php docker/migrate.php

echo "=========================================================="
echo " Update successfully published! Database data preserved."
echo "=========================================================="

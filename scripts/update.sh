#!/usr/bin/env bash
# scripts/update.sh
# Production Safe Update Script for Hostinger VPS
# Ensures:
# 1. Database is backed up before touching anything.
# 2. Only the application container is rebuilt/recreated.
# 3. Database container and persistent volumes remain active and untouched.
# 4. Safe non-destructive migrations are executed.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${PROJECT_DIR}"

echo "=========================================================="
echo " Starting Safe Application Update on Hostinger..."
echo "=========================================================="

# Step 1: Create Database Backup
echo "[1/6] Backing up database..."
bash "${SCRIPT_DIR}/backup.sh"

# Step 2: Pull latest git changes
if [ -d ".git" ]; then
    echo "[2/6] Pulling latest changes from repository..."
    git pull origin main || git pull
else
    echo "[2/6] No .git directory detected, skipping git pull."
fi

# Step 3: Rebuild ONLY the app container
echo "[3/6] Building updated application image..."
docker compose build app

# Step 4: Recreate application container (db container is untouched)
echo "[4/6] Restarting application container (--no-deps)..."
docker compose up -d --no-deps app

# Step 5: Run schema migrations safely
echo "[5/6] Applying database migrations..."
docker compose exec -T app php docker/migrate.php

# Step 6: Clean up dangling images
echo "[6/6] Cleaning up outdated Docker images..."
docker image prune -f

echo "=========================================================="
echo " Update Complete! Application is live and data is preserved."
echo "=========================================================="

@echo off
echo ==========================================================
echo  Publishing EduERP Updates Safely (Windows)
echo ==========================================================

echo [1/4] Creating pre-update database backup...
call scripts\backup.bat

echo [2/4] Pulling latest code from git...
git pull origin main

echo [3/4] Reloading application container...
docker compose restart app

echo [4/4] Applying database migrations...
docker compose exec app php docker/migrate.php

echo ==========================================================
echo  Update Complete! Database data preserved.
echo ==========================================================

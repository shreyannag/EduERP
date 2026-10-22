@echo off
echo ==========================================================
echo  Starting Safe Application Update (Local Windows Test)
echo ==========================================================

echo [1/5] Backing up database...
call scripts\backup.bat

echo [2/5] Building updated application image (pulling latest security patches)...
docker compose build --pull --no-cache app

echo [3/5] Restarting application container (--no-deps)...
docker compose up -d --no-deps app

echo [4/5] Applying database migrations...
docker compose exec app php docker/migrate.php

echo [5/5] Cleaning up old images...
docker image prune -f

echo ==========================================================
echo  Update Complete! Database preserved.
echo ==========================================================

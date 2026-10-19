@echo off
setlocal

for /f %%a in ('powershell -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set TIMESTAMP=%%a
set BACKUP_FILE=backups\backup_%TIMESTAMP%.sql

echo Creating database backup: %BACKUP_FILE% ...
if not exist backups mkdir backups
docker compose exec -T db mariadb-dump -u root -proot_secret --databases online_exam > "%BACKUP_FILE%"
echo Backup complete: %BACKUP_FILE%

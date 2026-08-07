@echo off
setlocal

set "SCRIPT_DIR=%~dp0"
set "APP_DIR=%SCRIPT_DIR%.."

cd /d "%APP_DIR%"

if not exist "storage\logs" mkdir "storage\logs"

php scripts\sync_cardoil_alimentari.php >> storage\logs\cardoil_sync.log 2>&1

endlocal

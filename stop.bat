@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

echo 停止 Laravel 服务（8080）...
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":8080" ^| findstr "LISTENING"') do taskkill /PID %%P /F >nul 2>&1

echo 停止 Vite 服务（5173）...
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":5173" ^| findstr "LISTENING"') do taskkill /PID %%P /F >nul 2>&1

echo 停止 MariaDB...
"runtime\mariadb\bin\mariadb-admin.exe" --host=127.0.0.1 --port=3307 --user=root --password=root_dev_pw shutdown >nul 2>&1

echo 已停止服务。
pause

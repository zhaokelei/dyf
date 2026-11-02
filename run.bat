@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

if not exist "runtime\php\php.exe" (
    echo [错误] 未找到 runtime\php，请确认运行时目录完整。
    pause
    exit /b 1
)

netstat -ano | findstr ":3307" | findstr "LISTENING" >nul
if errorlevel 1 (
    echo [1/2] 启动 MariaDB...
    start "DYF-MariaDB" /min "runtime\mariadb\bin\mariadbd.exe" --basedir="%CD%\runtime\mariadb" --datadir="%CD%\runtime\mariadb-data" --port=3307 --bind-address=127.0.0.1 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --console
    "%SystemRoot%\System32\ping.exe" -n 7 127.0.0.1 >nul
) else (
    echo [1/2] MariaDB 已在运行。
)

netstat -ano | findstr ":8080" | findstr "LISTENING" >nul
if not errorlevel 1 (
    echo [提示] 8080 端口已被占用，系统可能已经启动。
    echo 访问地址: http://localhost:8080
    pause
    exit /b 0
)

echo [2/2] 启动 Laravel 服务...
echo 本机访问: http://localhost:8080
echo 局域网访问: http://本机IP:8080
echo 按 Ctrl+C 停止 Web 服务；数据库请运行 stop.bat 停止。
echo.
"runtime\php\php.exe" artisan serve --host=0.0.0.0 --port=8080
pause

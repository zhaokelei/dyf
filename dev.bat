@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

netstat -ano | findstr ":3307" | findstr "LISTENING" >nul
if errorlevel 1 (
    echo 启动 MariaDB...
    start "DYF-MariaDB" /min "runtime\mariadb\bin\mariadbd.exe" --basedir="%CD%\runtime\mariadb" --datadir="%CD%\runtime\mariadb-data" --port=3307 --bind-address=127.0.0.1 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --console
    "%SystemRoot%\System32\ping.exe" -n 7 127.0.0.1 >nul
)

echo 打开 Vite 开发服务器窗口...
start "DYF-Vite" cmd /k "cd /d %CD% && npm run dev"

echo 启动 Laravel 服务...
echo 前端热更新地址以 Vite 窗口输出为准，页面地址: http://localhost:8080
"runtime\php\php.exe" artisan serve --host=0.0.0.0 --port=8080
pause

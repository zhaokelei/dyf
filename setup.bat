@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"

if not exist "runtime\php\php.exe" (
    echo [错误] 未找到 runtime\php，请确认运行时目录完整。
    pause
    exit /b 1
)

if not exist "runtime\mariadb-data\mysql" (
    echo 初始化 MariaDB 数据目录...
    "runtime\mariadb\bin\mariadb-install-db.exe" -d "%CD%\runtime\mariadb-data" -p root_dev_pw -P 3307
)

netstat -ano | findstr ":3307" | findstr "LISTENING" >nul
if errorlevel 1 (
    echo 启动 MariaDB...
    start "DYF-MariaDB" /min "runtime\mariadb\bin\mariadbd.exe" --basedir="%CD%\runtime\mariadb" --datadir="%CD%\runtime\mariadb-data" --port=3307 --bind-address=127.0.0.1 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --console
    "%SystemRoot%\System32\ping.exe" -n 7 127.0.0.1 >nul
)

echo 创建数据库与应用账号...
"runtime\mariadb\bin\mariadb.exe" -h 127.0.0.1 -P 3307 -u root -proot_dev_pw -e "CREATE DATABASE IF NOT EXISTS dyf_moral CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'dyf'@'127.0.0.1' IDENTIFIED BY 'dyf_dev_pw_2026'; GRANT ALL PRIVILEGES ON dyf_moral.* TO 'dyf'@'127.0.0.1'; FLUSH PRIVILEGES;"

if not exist ".env" (
    copy /Y ".env.example" ".env" >nul
    echo 已从 .env.example 创建 .env
)

findstr /B /C:"APP_KEY=base64:" ".env" >nul
if errorlevel 1 (
    echo 生成 APP_KEY...
    "runtime\php\php.exe" artisan key:generate --force
)

if not exist "vendor\autoload.php" (
    echo 安装 Composer 依赖...
    "runtime\php\php.exe" composer.phar install --no-interaction
)

if not exist "node_modules" (
    echo 安装前端依赖...
    call npm install
)

echo 执行数据库迁移与种子导入...
"runtime\php\php.exe" artisan migrate --force
"runtime\php\php.exe" artisan db:seed --force

echo 构建前端资源...
call npm run build

echo.
echo 初始化完成。双击 run.bat 启动系统。
pause

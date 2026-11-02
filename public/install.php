<?php

declare(strict_types=1);

/**
 * 郑州工商学院德育分管理系统 · Web 安装向导
 *
 * 访问 /install.php 完成环境检测、数据库配置、数据初始化与管理员创建。
 * 安装成功后写入 storage/installed.lock，向导会自动锁定。
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
@set_time_limit(300);
@ini_set('memory_limit', '512M');

define('APP_ROOT', dirname(__DIR__));
define('ENV_FILE', APP_ROOT.'/.env');
define('LOCK_FILE', APP_ROOT.'/storage/installed.lock');
define('PROGRESS_FILE', APP_ROOT.'/storage/install.in-progress');
define('APP_TITLE', '郑州工商学院德育分管理系统');
define('MIN_PHP_VERSION', '8.3.0');

final class DyfInstallException extends RuntimeException
{
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_https(): bool
{
    if (! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function script_url(): string
{
    return (string) ($_SERVER['SCRIPT_NAME'] ?? 'install.php');
}

function detect_app_url(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    if ($host === '' || preg_match('/[\s<>"\']/', $host)) {
        $host = 'localhost';
    }

    $base = str_replace('\\', '/', dirname(script_url()));
    $base = rtrim($base, '/');

    if ($base === '.' || $base === '/') {
        $base = '';
    }

    return (is_https() ? 'https' : 'http').'://'.$host.$base;
}

function prepare_runtime_directories(): void
{
    $directories = [
        APP_ROOT.'/storage/framework/cache/data',
        APP_ROOT.'/storage/framework/sessions',
        APP_ROOT.'/storage/framework/views',
        APP_ROOT.'/storage/logs',
        APP_ROOT.'/storage/app/public',
        APP_ROOT.'/bootstrap/cache',
    ];

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }
}

function read_env_values(): array
{
    if (! is_file(ENV_FILE)) {
        return [];
    }

    $values = [];
    $lines = @file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if (! is_array($lines)) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if ($key === '') {
            continue;
        }

        $value = trim($value);

        if (strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}

function installation_state(): array
{
    if (is_file(LOCK_FILE)) {
        $lock = json_decode((string) @file_get_contents(LOCK_FILE), true);

        return [
            'installed' => true,
            'reason' => 'lock',
            'installed_at' => is_array($lock) ? ($lock['installed_at'] ?? null) : null,
            'app_url' => is_array($lock) ? ($lock['app_url'] ?? null) : null,
        ];
    }

    if (is_file(PROGRESS_FILE)) {
        return ['installed' => false, 'resumable' => true];
    }

    $env = read_env_values();

    if (($env['APP_KEY'] ?? '') === '' || ! extension_loaded('pdo_mysql')) {
        return ['installed' => false];
    }

    try {
        $pdo = pdo_from_env($env);
        $database = (string) ($env['DB_DATABASE'] ?? '');

        if ($database === '') {
            return ['installed' => false];
        }

        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $statement->execute([$database, 'users']);

        if ((int) $statement->fetchColumn() === 0) {
            return ['installed' => false];
        }

        $statement = $pdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'");

        if ((int) $statement->fetchColumn() > 0) {
            return ['installed' => true, 'reason' => 'database'];
        }
    } catch (Throwable) {
        // 数据库暂时不可用时仍允许进入安装向导。
    }

    return ['installed' => false];
}

function pdo_from_env(array $env, int $timeout = 2): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) ($env['DB_HOST'] ?? '127.0.0.1'),
        (int) ($env['DB_PORT'] ?? 3306),
        (string) ($env['DB_DATABASE'] ?? '')
    );

    return new PDO(
        $dsn,
        (string) ($env['DB_USERNAME'] ?? ''),
        (string) ($env['DB_PASSWORD'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => $timeout,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function check_item(string $label, string $value, string $status, string $hint): array
{
    return compact('label', 'value', 'status', 'hint');
}

function environment_groups(): array
{
    $runtime = [];

    $runtime[] = check_item(
        'PHP 版本',
        PHP_VERSION,
        version_compare(PHP_VERSION, MIN_PHP_VERSION, '>=') ? 'ok' : 'fail',
        '需要 PHP '.MIN_PHP_VERSION.' 或更高版本'
    );

    $extensions = [
        'pdo' => ['数据库访问', true],
        'pdo_mysql' => ['MySQL 驱动', true],
        'mbstring' => ['中文与字符串处理', true],
        'openssl' => ['加密与签名', true],
        'ctype' => ['字符检测', true],
        'filter' => ['数据过滤', true],
        'hash' => ['密码哈希', true],
        'session' => ['会话支持', true],
        'tokenizer' => ['代码解析', true],
        'dom' => ['XML / DOM 处理', true],
        'libxml' => ['XML 解析', true],
        'simplexml' => ['Excel 导入导出', true],
        'xml' => ['XML 处理', true],
        'xmlreader' => ['Excel 读取', true],
        'xmlwriter' => ['Excel 导出', true],
        'zip' => ['Excel 文件处理', true],
        'zlib' => ['压缩支持', true],
        'fileinfo' => ['上传文件识别', true],
        'gd' => ['图片处理', true],
        'iconv' => ['字符编码转换', true],
        'curl' => ['网络请求', false],
        'intl' => ['国际化支持', false],
    ];

    $missingRequired = [];
    $missingRecommended = [];
    $requiredTotal = 0;
    $recommendedTotal = 0;

    foreach ($extensions as $extension => [$purpose, $required]) {
        $loaded = extension_loaded($extension);

        if ($required) {
            $requiredTotal++;

            if (! $loaded) {
                $missingRequired[] = $extension.'（'.$purpose.'）';
            }
        } else {
            $recommendedTotal++;

            if (! $loaded) {
                $missingRecommended[] = $extension.'（'.$purpose.'）';
            }
        }
    }

    $runtime[] = check_item(
        '必需扩展',
        $missingRequired === [] ? $requiredTotal.' / '.$requiredTotal.' 已启用' : '缺少 '.count($missingRequired).' 个',
        $missingRequired === [] ? 'ok' : 'fail',
        $missingRequired === []
            ? '数据库、Excel、图片与上传文件处理所需扩展均已启用'
            : '请在 PHP 中启用：'.implode('、', $missingRequired)
    );

    $runtime[] = check_item(
        '建议扩展',
        $missingRecommended === [] ? $recommendedTotal.' / '.$recommendedTotal.' 已启用' : '缺少 '.count($missingRecommended).' 个',
        $missingRecommended === [] ? 'ok' : 'warn',
        $missingRecommended === []
            ? 'curl 与 intl 均已启用'
            : '建议启用：'.implode('、', $missingRecommended)
    );

    $maxExecutionTime = (int) ini_get('max_execution_time');
    $runtime[] = check_item(
        '最大执行时间',
        $maxExecutionTime === 0 ? '不限制' : $maxExecutionTime.' 秒',
        $maxExecutionTime === 0 || $maxExecutionTime >= 120 ? 'ok' : 'warn',
        '安装与数据库迁移建议不少于 120 秒'
    );

    $memoryLimit = ini_get('memory_limit');
    $memoryBytes = memory_limit_bytes((string) $memoryLimit);
    $runtime[] = check_item(
        '内存限制',
        $memoryLimit === false ? '未知' : (string) $memoryLimit,
        $memoryBytes === -1 || $memoryBytes >= 128 * 1024 * 1024 ? 'ok' : 'warn',
        '建议至少 128M'
    );

    $groups = ['运行环境' => $runtime];

    $directories = [
        ['配置文件 .env', is_file(ENV_FILE) ? ENV_FILE : APP_ROOT, is_file(ENV_FILE) ? is_writable(ENV_FILE) : is_writable(APP_ROOT), '安装程序需要写入数据库与站点配置'],
        ['storage 目录', APP_ROOT.'/storage', is_dir(APP_ROOT.'/storage') && is_writable(APP_ROOT.'/storage'), '日志、会话与上传文件存储'],
        ['storage/framework', APP_ROOT.'/storage/framework', is_dir(APP_ROOT.'/storage/framework') && is_writable(APP_ROOT.'/storage/framework'), '缓存、会话与编译视图'],
        ['bootstrap/cache', APP_ROOT.'/bootstrap/cache', is_dir(APP_ROOT.'/bootstrap/cache') && is_writable(APP_ROOT.'/bootstrap/cache'), '框架缓存目录'],
    ];

    $permissions = [];

    foreach ($directories as [$label, $path, $writable, $hint]) {
        $permissions[] = check_item(
            $label,
            $writable ? '可写' : '不可写',
            $writable ? 'ok' : 'fail',
            $hint.'：'.str_replace(APP_ROOT, '项目根目录', $path)
        );
    }

    $groups['目录权限'] = $permissions;

    $files = [
        ['依赖文件', APP_ROOT.'/vendor/autoload.php', 'vendor/autoload.php', '如果缺失，请先执行 composer install 并上传 vendor 目录'],
        ['应用入口', APP_ROOT.'/bootstrap/app.php', 'bootstrap/app.php', 'Laravel 应用引导文件'],
        ['前端构建产物', APP_ROOT.'/public/build/manifest.json', 'public/build/manifest.json', '如果缺失，请在本机执行 npm run build 后上传 public/build'],
        ['数据库迁移', APP_ROOT.'/database/migrations', 'database/migrations', '数据库结构定义目录'],
    ];

    $projectFiles = [];

    foreach ($files as [$label, $path, $displayPath, $hint]) {
        $exists = is_dir($path) ? true : is_file($path);

        $projectFiles[] = check_item(
            $label,
            $exists ? '已就绪' : '缺失',
            $exists ? 'ok' : 'fail',
            $hint.'（'.$displayPath.'）'
        );
    }

    $configCache = APP_ROOT.'/bootstrap/cache/config.php';

    if (is_file($configCache)) {
        $writable = is_writable($configCache);

        $projectFiles[] = check_item(
            '配置缓存',
            $writable ? '将自动清除' : '无法清除',
            $writable ? 'warn' : 'fail',
            '检测到 bootstrap/cache/config.php，安装时会清除旧配置缓存'
        );
    }

    $groups['项目文件'] = $projectFiles;

    return $groups;
}

function memory_limit_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '' || $value === '-1') {
        return -1;
    }

    $unit = strtolower(substr($value, -1));
    $number = (int) $value;

    return match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function checks_pass(array $groups): bool
{
    foreach ($groups as $items) {
        foreach ($items as $item) {
            if ($item['status'] === 'fail') {
                return false;
            }
        }
    }

    return true;
}

function count_checks(array $groups): array
{
    $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];

    foreach ($groups as $items) {
        foreach ($items as $item) {
            $counts[$item['status']] = ($counts[$item['status']] ?? 0) + 1;
        }
    }

    return $counts;
}

function env_quote(string $value): string
{
    $value = str_replace(["\r", "\n", "\0"], '', $value);

    if ($value === '') {
        return '""';
    }

    if (preg_match('/^[A-Za-z0-9_.:\/@+,\-]+$/', $value) === 1) {
        return $value;
    }

    return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
}

function build_env_content(array $config): string
{
    $lines = [
        'APP_NAME='.env_quote(APP_TITLE),
        'APP_ENV=production',
        'APP_KEY='.env_quote($config['app_key']),
        'APP_DEBUG=false',
        'APP_URL='.env_quote($config['app_url']),
        'APP_TIMEZONE=Asia/Shanghai',
        '',
        'APP_LOCALE=zh_CN',
        'APP_FALLBACK_LOCALE=zh_CN',
        'APP_FAKER_LOCALE=zh_CN',
        '',
        'APP_MAINTENANCE_DRIVER=file',
        '',
        'BCRYPT_ROUNDS=12',
        '',
        'LOG_CHANNEL=stack',
        'LOG_STACK=single',
        'LOG_DEPRECATIONS_CHANNEL=null',
        'LOG_LEVEL=debug',
        '',
        'DB_CONNECTION=mysql',
        'DB_HOST='.env_quote($config['db_host']),
        'DB_PORT='.$config['db_port'],
        'DB_DATABASE='.env_quote($config['db_database']),
        'DB_USERNAME='.env_quote($config['db_username']),
        'DB_PASSWORD='.env_quote($config['db_password']),
        '',
        'SESSION_DRIVER=database',
        'SESSION_LIFETIME=120',
        'SESSION_ENCRYPT=false',
        'SESSION_PATH=/',
        'SESSION_DOMAIN=null',
        '',
        'BROADCAST_CONNECTION=log',
        'FILESYSTEM_DISK=local',
        'QUEUE_CONNECTION=database',
        '',
        'CACHE_STORE=database',
        '',
        'MAIL_MAILER=log',
        '',
        'VITE_APP_NAME="${APP_NAME}"',
        '',
    ];

    return implode("\n", $lines);
}

function safe_detail(string $text): string
{
    $text = str_replace(["\r\n", "\0"], "\n", $text);
    $text = preg_replace('/教师注册邀请码[:：].*/u', '教师注册邀请码：[已隐藏]', $text) ?? $text;
    $text = preg_replace('/^(DB_PASSWORD=).*$/m', '$1[已隐藏]', $text) ?? $text;

    if (mb_strlen($text) > 4000) {
        $text = mb_substr($text, 0, 4000).'…';
    }

    return trim($text);
}

function friendly_db_error(PDOException $exception): string
{
    $code = (int) ($exception->errorInfo[1] ?? 0);

    return match ($code) {
        1045 => '数据库账号或密码不正确。',
        1044 => '数据库账号无权访问该数据库。',
        1049 => '目标数据库不存在，请勾选“数据库不存在时自动创建”或先手动创建。',
        2002, 2003 => '无法连接到数据库服务器，请检查主机、端口以及 MySQL 服务是否启动。',
        1130 => '数据库服务器不允许当前主机连接。',
        1142 => '数据库账号缺少建表或写入权限。',
        1698 => '数据库账号的认证方式不受支持，请改用密码认证的账号。',
        default => '数据库操作失败。',
    };
}

function status_icon(string $status): string
{
    $attributes = 'viewBox="0 0 20 20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"';

    return match ($status) {
        'ok' => '<svg '.$attributes.'><path d="M5 10.4l3.1 3.1L15 6.8"/></svg>',
        'fail' => '<svg '.$attributes.'><path d="M6 6l8 8M14 6l-8 8"/></svg>',
        default => '<svg '.$attributes.'><path d="M10 5.8v5.4"/><path d="M10 14.4h.01"/></svg>',
    };
}

function render_page_start(string $title, ?int $step): void
{
    $crest = is_file(__DIR__.'/brand/crest-168.png') ? 'brand/crest-168.png' : null;
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> · <?= e(APP_TITLE) ?></title>
    <style>
        :root {
            --brand: #337ecc;
            --brand-strong: #2b6cb0;
            --brand-soft: #ecf5ff;
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --ok: #15803d;
            --ok-bg: #f0fdf4;
            --warn: #b45309;
            --warn-bg: #fffbeb;
            --bad: #b91c1c;
            --bad-bg: #fef2f2;
        }
        * { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            padding: 36px 18px 56px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1100px 520px at 8% -12%, rgba(51, 126, 204, .42), transparent 60%),
                radial-gradient(920px 480px at 96% 2%, rgba(21, 59, 112, .55), transparent 58%),
                linear-gradient(158deg, #0b1220 0%, #101c2f 52%, #0a101b 100%);
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }
        .shell { width: 100%; max-width: 980px; animation: pageIn .55s cubic-bezier(.22, .8, .28, 1) both; }
        @keyframes pageIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        .brandbar { display: flex; align-items: center; gap: 13px; padding: 0 6px 20px; color: #fff; flex-wrap: wrap; }
        .brand-logo {
            width: 46px; height: 46px; border-radius: 13px; object-fit: contain; padding: 5px;
            background: rgba(255, 255, 255, .08); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .12);
        }
        .brand-fallback { display: grid; place-items: center; font-size: 11px; font-weight: 700; letter-spacing: .06em; color: #cbe2ff; }
        .brand-name { margin: 0; font-size: 15px; font-weight: 600; letter-spacing: .04em; }
        .brand-sub { margin: 3px 0 0; font-size: 11px; letter-spacing: .14em; color: rgba(255, 255, 255, .52); }
        .steps { margin-left: auto; display: flex; gap: 4px; padding: 4px; border-radius: 999px; background: rgba(255, 255, 255, .06); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .08); list-style: none; }
        .steps li { display: flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 11px; color: rgba(255, 255, 255, .42); white-space: nowrap; }
        .steps li.done { color: rgba(255, 255, 255, .7); }
        .steps li.active { color: #fff; background: rgba(255, 255, 255, .14); box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .12); }
        .step-dot { width: 18px; height: 18px; border-radius: 50%; display: grid; place-items: center; font-size: 10px; background: rgba(255, 255, 255, .1); }
        .steps li.active .step-dot { background: #409eff; }
        .steps li.done .step-dot { background: rgba(64, 158, 255, .28); color: #c6e2ff; }
        .card { background: #fff; border-radius: 26px; overflow: hidden; box-shadow: 0 32px 80px -20px rgba(2, 8, 20, .62), 0 0 0 1px rgba(255, 255, 255, .08); }
        .card-inner { padding: 34px 38px 30px; }
        .page-head { margin-bottom: 24px; }
        .eyebrow { margin: 0 0 8px; font-size: 10px; font-weight: 700; letter-spacing: .22em; color: var(--brand); }
        h1 { margin: 0; font-size: 28px; line-height: 1.25; letter-spacing: -.02em; }
        .lead { margin: 10px 0 0; font-size: 13.5px; line-height: 1.75; color: var(--muted); }
        .alert { margin: 0 0 18px; padding: 15px 17px; border-radius: 16px; border: 1px solid transparent; font-size: 13px; line-height: 1.7; }
        .alert:last-child { margin-bottom: 0; }
        .alert p { margin: 4px 0 0; }
        .alert ul { margin: 6px 0 0; padding-left: 18px; }
        .alert strong { display: block; font-size: 13px; }
        .alert.bad { background: var(--bad-bg); border-color: #fecaca; color: #7f1d1d; }
        .alert.warn { background: var(--warn-bg); border-color: #fde68a; color: #78350f; }
        .alert.info { background: var(--brand-soft); border-color: #c6e2ff; color: #1d4f91; }
        .alert.small { padding: 12px 14px; font-size: 12px; }
        .alert-head { display: flex; align-items: center; gap: 8px; }
        .stage-tag { display: inline-flex; align-items: center; height: 21px; padding: 0 8px; border-radius: 999px; background: rgba(185, 28, 28, .1); font-size: 10.5px; font-weight: 600; }
        .alert-note { margin-top: 8px !important; font-size: 12px; opacity: .8; }
        .env-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 24px; }
        .metric { padding: 14px 16px; border-radius: 16px; background: #f8fafc; border: 1px solid var(--line); }
        .metric strong { display: block; font-size: 24px; line-height: 1; letter-spacing: -.02em; color: var(--ink); }
        .metric span { display: block; margin-top: 6px; font-size: 11.5px; color: var(--muted); }
        .metric.ok.has strong { color: var(--ok); }
        .metric.warn.has strong { color: var(--warn); }
        .metric.fail.has strong { color: var(--bad); }
        .check-group { margin-bottom: 22px; }
        .check-group h2 { margin: 0 0 9px; font-size: 12px; font-weight: 600; letter-spacing: .08em; color: var(--muted); }
        .checks { margin: 0; padding: 0; list-style: none; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
        .check { display: flex; align-items: center; gap: 12px; padding: 11px 15px; border-top: 1px solid #eef2f7; background: #fff; }
        .check:first-child { border-top: 0; }
        .check-icon { flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; }
        .check-icon svg { width: 15px; height: 15px; }
        .check.ok .check-icon { color: var(--ok); background: var(--ok-bg); }
        .check.warn .check-icon { color: var(--warn); background: var(--warn-bg); }
        .check.fail .check-icon { color: var(--bad); background: var(--bad-bg); }
        .check-body { display: flex; min-width: 0; flex: 1; flex-direction: column; gap: 2px; }
        .check-label { font-size: 13px; font-weight: 500; }
        .check-hint { font-size: 11.5px; color: var(--muted); }
        .check-value { flex: 0 0 auto; max-width: 40%; font-size: 11.5px; font-weight: 600; color: #334155; text-align: right; word-break: break-all; }
        .check.fail .check-value { color: var(--bad); }
        .check.warn .check-value { color: var(--warn); }
        .actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 26px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 44px; padding: 0 20px; border: 0; border-radius: 13px;
            font-family: inherit; font-size: 13.5px; font-weight: 600; line-height: 1;
            text-decoration: none; cursor: pointer; transition: transform .16s ease, background-color .16s ease, box-shadow .16s ease;
        }
        .btn:active { transform: scale(.985); }
        .btn:focus-visible { outline: 3px solid rgba(51, 126, 204, .28); outline-offset: 2px; }
        .btn.primary { color: #fff; background: var(--brand); box-shadow: 0 10px 24px -10px rgba(51, 126, 204, .75); }
        .btn.primary:hover { background: var(--brand-strong); }
        .btn.ghost { color: #475569; background: #f1f5f9; }
        .btn.ghost:hover { background: #e2e8f0; }
        .btn.disabled { color: #94a3b8; background: #f1f5f9; cursor: not-allowed; box-shadow: none; }
        .btn.loading { opacity: .8; cursor: progress; }
        .spinner { display: none; width: 13px; height: 13px; border-radius: 50%; border: 2px solid rgba(255, 255, 255, .45); border-top-color: #fff; animation: spin .8s linear infinite; }
        .btn.loading .spinner { display: inline-block; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .footnote { margin: 14px 0 0; font-size: 11.5px; line-height: 1.7; color: var(--muted); }
        .panel { margin: 0 0 18px; padding: 20px; border: 1px solid var(--line); border-radius: 18px; background: #fff; }
        .panel-head { display: flex; gap: 11px; margin-bottom: 16px; }
        .panel-num { flex: 0 0 30px; height: 30px; border-radius: 10px; display: grid; place-items: center; background: var(--brand-soft); color: var(--brand-strong); font-size: 11px; font-weight: 700; letter-spacing: .02em; }
        .panel-head h2 { margin: 1px 0 0; font-size: 15px; letter-spacing: -.01em; }
        .panel-head p { margin: 4px 0 0; font-size: 11.5px; line-height: 1.65; color: var(--muted); }
        .grid { display: grid; gap: 14px; }
        .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .span-2 { grid-column: span 2; }
        .field { display: flex; flex-direction: column; gap: 6px; }
        .field-label { font-size: 12px; font-weight: 600; color: #334155; }
        .input {
            width: 100%; height: 43px; padding: 0 13px;
            border: 1px solid #dbe3ec; border-radius: 12px; background: #fff; color: var(--ink);
            font-family: inherit; font-size: 13px; outline: none; transition: border-color .16s ease, box-shadow .16s ease;
        }
        .input::placeholder { color: #a3afc0; }
        .input:focus { border-color: var(--brand); box-shadow: 0 0 0 4px rgba(51, 126, 204, .1); }
        .input.invalid { border-color: #f87171; box-shadow: 0 0 0 4px rgba(248, 113, 113, .08); }
        .field-hint { font-size: 11px; line-height: 1.6; color: var(--muted); }
        .field-error { font-size: 11.5px; font-weight: 500; color: var(--bad); }
        .checkline { display: flex; align-items: flex-start; gap: 9px; margin-top: 14px; font-size: 12.5px; line-height: 1.6; color: #475569; cursor: pointer; }
        .checkline input { width: 16px; height: 16px; margin: 2px 0 0; accent-color: var(--brand); }
        .detail { margin-top: 10px; }
        .detail summary { cursor: pointer; font-size: 12px; font-weight: 600; color: inherit; opacity: .85; }
        .detail pre { margin: 9px 0 0; padding: 12px; max-height: 260px; overflow: auto; border-radius: 10px; background: rgba(15, 23, 42, .06); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 11px; line-height: 1.6; white-space: pre-wrap; word-break: break-word; }
        .done-hero { padding: 8px 0 20px; text-align: center; }
        .done-icon { width: 62px; height: 62px; margin: 0 auto 16px; border-radius: 50%; display: grid; place-items: center; color: #fff; background: linear-gradient(145deg, #22c55e, #15803d); box-shadow: 0 16px 34px -12px rgba(21, 128, 61, .55); }
        .done-icon.lock { background: linear-gradient(145deg, #64748b, #334155); box-shadow: 0 16px 34px -12px rgba(51, 65, 85, .5); }
        .done-icon svg { width: 30px; height: 30px; }
        .done-hero h1 { font-size: 30px; }
        .done-hero .lead { max-width: 560px; margin-left: auto; margin-right: auto; }
        .summary { margin: 0 0 20px; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
        .summary div { display: flex; align-items: flex-start; gap: 18px; padding: 11px 16px; border-top: 1px solid #eef2f7; font-size: 12.5px; }
        .summary div:first-child { border-top: 0; }
        .summary dt { flex: 0 0 92px; color: var(--muted); }
        .summary dd { margin: 0; min-width: 0; word-break: break-all; }
        .summary a { color: var(--brand-strong); text-decoration: none; }
        .summary a:hover { text-decoration: underline; }
        .tips { margin: 4px 0 0; padding-left: 18px; }
        .tips li { margin-top: 5px; }
        .page-footer { display: flex; justify-content: space-between; gap: 16px; padding: 14px 38px 20px; border-top: 1px solid #eef2f7; font-size: 10.5px; color: #94a3b8; }
        code { padding: 1px 5px; border-radius: 5px; background: rgba(15, 23, 42, .06); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: .94em; }
        @media (max-width: 720px) {
            body { padding: 20px 12px 36px; }
            .card { border-radius: 22px; }
            .card-inner { padding: 26px 20px 24px; }
            .page-footer { padding: 13px 20px 18px; }
            h1 { font-size: 24px; }
            .done-hero h1 { font-size: 26px; }
            .grid.two { grid-template-columns: 1fr; }
            .span-2 { grid-column: auto; }
            .steps { width: 100%; margin-left: 0; justify-content: space-between; }
            .step-label { display: none; }
            .steps li { flex: 1; justify-content: center; }
            .env-summary { gap: 8px; }
            .metric { padding: 12px; }
            .metric strong { font-size: 20px; }
            .check { align-items: flex-start; }
            .check-value { max-width: 34%; font-size: 11px; }
            .summary dt { flex-basis: 76px; }
            .actions { justify-content: stretch; }
            .actions .btn { flex: 1; }
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="brandbar">
        <?php if ($crest !== null): ?>
            <img class="brand-logo" src="<?= e($crest) ?>" alt="郑州工商学院校徽" width="168" height="168">
        <?php else: ?>
            <span class="brand-logo brand-fallback">ZZTBU</span>
        <?php endif; ?>
        <div>
            <p class="brand-name">郑州工商学院</p>
            <p class="brand-sub">德育分管理系统 · 安装向导</p>
        </div>
        <?php if ($step !== null) {
            render_steps($step);
        } ?>
    </header>
    <main class="card">
        <div class="card-inner">
<?php
}

function render_steps(int $current): void
{
    $steps = [1 => '环境检测', 2 => '数据库与管理员', 3 => '完成安装'];

    echo '<ol class="steps">';

    foreach ($steps as $number => $label) {
        $class = $number < $current ? 'done' : ($number === $current ? 'active' : '');
        $dot = $number < $current ? '✓' : (string) $number;
        echo '<li class="'.e($class).'"><span class="step-dot">'.e($dot).'</span><span class="step-label">'.e($label).'</span></li>';
    }

    echo '</ol>';
}

function render_page_end(): void
{
    ?>
        </div>
        <footer class="page-footer">
            <span>德育分管理系统 · Web 安装向导</span>
            <span>PHP <?= e(PHP_VERSION) ?></span>
        </footer>
    </main>
</div>
</body>
</html>
<?php
}

function render_environment(array $groups, array $options = []): void
{
    $counts = count_checks($groups);
    $pass = $counts['fail'] === 0;
    $notices = $options['notices'] ?? [];

    render_page_start('环境检测', 1);
    ?>
    <div class="page-head">
        <p class="eyebrow">STEP 1 / 3</p>
        <h1>环境检测</h1>
        <p class="lead">安装前会检查服务器环境、目录权限与项目文件，全部通过后即可继续。</p>
    </div>

    <?php foreach ($notices as $notice): ?>
        <div class="alert bad"><strong>请先处理以下问题</strong><p><?= e($notice) ?></p></div>
    <?php endforeach; ?>

    <?php if (! empty($options['resumable'])): ?>
        <div class="alert warn">
            <strong>检测到未完成的安装</strong>
            <p>上一次安装没有执行完毕。可以继续填写配置并重试，已经完成的数据库迁移不会重复执行。</p>
        </div>
    <?php endif; ?>

    <div class="env-summary">
        <div class="metric ok<?= $counts['ok'] > 0 ? ' has' : '' ?>"><strong><?= e((string) $counts['ok']) ?></strong><span>项通过</span></div>
        <div class="metric warn<?= $counts['warn'] > 0 ? ' has' : '' ?>"><strong><?= e((string) $counts['warn']) ?></strong><span>项提醒</span></div>
        <div class="metric fail<?= $counts['fail'] > 0 ? ' has' : '' ?>"><strong><?= e((string) $counts['fail']) ?></strong><span>项未通过</span></div>
    </div>

    <?php foreach ($groups as $groupName => $items): ?>
        <section class="check-group">
            <h2><?= e($groupName) ?></h2>
            <ul class="checks">
                <?php foreach ($items as $item): ?>
                    <li class="check <?= e($item['status']) ?>">
                        <span class="check-icon"><?= status_icon($item['status']) ?></span>
                        <span class="check-body">
                            <span class="check-label"><?= e($item['label']) ?></span>
                            <span class="check-hint"><?= e($item['hint']) ?></span>
                        </span>
                        <span class="check-value"><?= e($item['value']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <div class="actions">
        <a class="btn ghost" href="<?= e(script_url()) ?>">重新检测</a>
        <?php if ($pass): ?>
            <a class="btn primary" href="<?= e(script_url().'?step=config') ?>">下一步：配置安装</a>
        <?php else: ?>
            <span class="btn disabled" aria-disabled="true">请先处理未通过项</span>
        <?php endif; ?>
    </div>

    <?php if (! $pass): ?>
        <p class="footnote">处理后点击“重新检测”。如果无法启用某个扩展，请联系服务器管理员。</p>
    <?php endif; ?>

    <?php if (! is_https()): ?>
        <div class="alert warn small" style="margin-top: 18px;">
            <strong>HTTPS 建议</strong>
            <p>当前通过 HTTP 访问。正式环境建议启用 HTTPS 后再安装和登录，避免管理员密码在传输过程中被窃听。</p>
        </div>
    <?php endif; ?>
    <?php
    render_page_end();
    exit;
}

function default_form_values(): array
{
    return [
        'db_host' => '127.0.0.1',
        'db_port' => '3306',
        'db_database' => 'dyf_moral',
        'db_username' => '',
        'db_password' => '',
        'create_database' => true,
        'app_url' => detect_app_url(),
        'admin_name' => '系统管理员',
        'admin_username' => '',
        'admin_password' => '',
        'admin_password_confirmation' => '',
    ];
}

function render_config_page(array $options = []): void
{
    $values = array_merge(default_form_values(), $options['values'] ?? []);
    $values['db_password'] = '';
    $values['admin_password'] = '';
    $values['admin_password_confirmation'] = '';

    $errors = $options['errors'] ?? [];
    $error = $options['error'] ?? null;
    $resumable = ! empty($options['resumable']);
    $envWritten = ! empty($options['env_written']);

    render_page_start('配置安装', 2);
    ?>
    <div class="page-head">
        <p class="eyebrow">STEP 2 / 3</p>
        <h1>配置数据库与管理员</h1>
        <p class="lead">安装程序会创建数据表、初始化基础数据，并建立第一个管理员账号。</p>
    </div>

    <?php if ($resumable): ?>
        <div class="alert warn">
            <strong>检测到未完成的安装</strong>
            <p>可以修正以下配置后重新提交。数据库迁移会自动跳过已完成的部分。</p>
        </div>
    <?php endif; ?>

    <?php if (is_array($error)): ?>
        <div class="alert bad">
            <div class="alert-head">
                <span class="stage-tag"><?= e((string) ($error['stage'] ?? '安装')) ?></span>
                <strong>安装未完成</strong>
            </div>
            <p><?= e((string) ($error['message'] ?? '请检查配置后重试。')) ?></p>
            <?php if (! empty($error['detail'])): ?>
                <details class="detail">
                    <summary>查看技术详情</summary>
                    <pre><?= e((string) $error['detail']) ?></pre>
                </details>
            <?php endif; ?>
            <?php if ($envWritten): ?>
                <p class="alert-note">应用配置文件已写入，可修正后直接重试；重复执行数据库迁移是安全的。</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form id="install-form" method="post" action="<?= e(script_url().'?action=install') ?>" autocomplete="off">
        <input type="hidden" name="_token" value="<?= e((string) ($_SESSION['csrf'] ?? '')) ?>">

        <section class="panel">
            <div class="panel-head">
                <span class="panel-num">01</span>
                <div>
                    <h2>数据库连接</h2>
                    <p>请使用具备建库、建表权限的 MySQL 账号。数据表将使用 utf8mb4 字符集。</p>
                </div>
            </div>

            <div class="grid two">
                <label class="field">
                    <span class="field-label">数据库主机</span>
                    <input class="input<?= isset($errors['db_host']) ? ' invalid' : '' ?>" type="text" name="db_host" value="<?= e((string) $values['db_host']) ?>" placeholder="127.0.0.1" maxlength="255" required>
                    <span class="field-hint">通常为 127.0.0.1、localhost 或数据库服务器地址</span>
                    <?php if (isset($errors['db_host'])): ?><span class="field-error"><?= e($errors['db_host']) ?></span><?php endif; ?>
                </label>

                <label class="field">
                    <span class="field-label">端口</span>
                    <input class="input<?= isset($errors['db_port']) ? ' invalid' : '' ?>" type="number" name="db_port" value="<?= e((string) $values['db_port']) ?>" min="1" max="65535" inputmode="numeric" required>
                    <span class="field-hint">MySQL 默认端口为 3306</span>
                    <?php if (isset($errors['db_port'])): ?><span class="field-error"><?= e($errors['db_port']) ?></span><?php endif; ?>
                </label>

                <label class="field">
                    <span class="field-label">数据库名</span>
                    <input class="input<?= isset($errors['db_database']) ? ' invalid' : '' ?>" type="text" name="db_database" value="<?= e((string) $values['db_database']) ?>" placeholder="dyf_moral" maxlength="64" required>
                    <span class="field-hint">仅允许字母、数字与下划线，最长 64 个字符</span>
                    <?php if (isset($errors['db_database'])): ?><span class="field-error"><?= e($errors['db_database']) ?></span><?php endif; ?>
                </label>

                <label class="field">
                    <span class="field-label">数据库账号</span>
                    <input class="input<?= isset($errors['db_username']) ? ' invalid' : '' ?>" type="text" name="db_username" value="<?= e((string) $values['db_username']) ?>" placeholder="数据库用户名" maxlength="80" required>
                    <span class="field-hint">建议使用单独的应用账号，不要使用 root</span>
                    <?php if (isset($errors['db_username'])): ?><span class="field-error"><?= e($errors['db_username']) ?></span><?php endif; ?>
                </label>

                <label class="field span-2">
                    <span class="field-label">数据库密码</span>
                    <input class="input" type="password" name="db_password" value="" maxlength="255" autocomplete="new-password" placeholder="留空表示空密码">
                    <span class="field-hint">密码仅用于写入服务器端 .env 文件，不会显示在页面或日志中；提交错误后需要重新输入。</span>
                </label>
            </div>

            <label class="checkline">
                <input type="checkbox" name="create_database" value="1" <?= ! empty($values['create_database']) ? 'checked' : '' ?>>
                <span>数据库不存在时自动创建（需要数据库账号具备建库权限）</span>
            </label>
        </section>

        <section class="panel">
            <div class="panel-head">
                <span class="panel-num">02</span>
                <div>
                    <h2>站点地址</h2>
                    <p>用于生成系统内的链接与跳转地址，请填写用户实际访问的地址。</p>
                </div>
            </div>

            <label class="field">
                <span class="field-label">站点地址</span>
                <input class="input<?= isset($errors['app_url']) ? ' invalid' : '' ?>" type="url" name="app_url" value="<?= e((string) $values['app_url']) ?>" placeholder="https://dy.iqiq.cn" maxlength="255" required>
                <span class="field-hint">例如 https://dy.iqiq.cn；已启用 HTTPS 时请使用 https://</span>
                <?php if (isset($errors['app_url'])): ?><span class="field-error"><?= e($errors['app_url']) ?></span><?php endif; ?>
            </label>
        </section>

        <section class="panel">
            <div class="panel-head">
                <span class="panel-num">03</span>
                <div>
                    <h2>管理员账号</h2>
                    <p>该账号拥有系统全部权限，请设置足够强的密码并妥善保管。</p>
                </div>
            </div>

            <div class="grid two">
                <label class="field">
                    <span class="field-label">管理员姓名</span>
                    <input class="input<?= isset($errors['admin_name']) ? ' invalid' : '' ?>" type="text" name="admin_name" value="<?= e((string) $values['admin_name']) ?>" placeholder="例如 张老师" maxlength="50" required>
                    <?php if (isset($errors['admin_name'])): ?><span class="field-error"><?= e($errors['admin_name']) ?></span><?php endif; ?>
                </label>

                <label class="field">
                    <span class="field-label">登录账号</span>
                    <input class="input<?= isset($errors['admin_username']) ? ' invalid' : '' ?>" type="text" name="admin_username" value="<?= e((string) $values['admin_username']) ?>" placeholder="3-50 位字母、数字或 _ . @ -" maxlength="50" required>
                    <span class="field-hint">登录时使用此账号</span>
                    <?php if (isset($errors['admin_username'])): ?><span class="field-error"><?= e($errors['admin_username']) ?></span><?php endif; ?>
                </label>

                <label class="field">
                    <span class="field-label">登录密码</span>
                    <input class="input<?= isset($errors['admin_password']) ? ' invalid' : '' ?>" type="password" name="admin_password" value="" minlength="8" maxlength="100" autocomplete="new-password" required>
                    <span class="field-hint">至少 8 位，建议包含大小写字母、数字与符号</span>
                    <?php if (isset($errors['admin_password'])): ?><span class="field-error"><?= e($errors['admin_password']) ?></span><?php endif; ?>
                </label>

                <label class="field">
                    <span class="field-label">确认密码</span>
                    <input class="input<?= isset($errors['admin_password_confirmation']) ? ' invalid' : '' ?>" type="password" name="admin_password_confirmation" value="" minlength="8" maxlength="100" autocomplete="new-password" required>
                    <?php if (isset($errors['admin_password_confirmation'])): ?><span class="field-error"><?= e($errors['admin_password_confirmation']) ?></span><?php endif; ?>
                </label>
            </div>

            <div class="alert info small" style="margin-top: 16px; margin-bottom: 0;">
                <strong>安全提示</strong>
                <p>请勿使用 admin123、12345678 等弱口令。安装完成后，任何用户修改密码都会退出所有设备并要求重新登录。</p>
            </div>
        </section>

        <?php if (! is_https()): ?>
            <div class="alert warn small">
                <strong>当前使用 HTTP</strong>
                <p>正式环境建议先启用 HTTPS 再安装；否则管理员密码可能在传输过程中被窃听。</p>
            </div>
        <?php endif; ?>

        <div class="actions">
            <a class="btn ghost" href="<?= e(script_url()) ?>">返回环境检测</a>
            <button id="install-btn" class="btn primary" type="submit">
                <span class="btn-text">开始安装</span>
                <span class="spinner" aria-hidden="true"></span>
            </button>
        </div>
        <p class="footnote">点击“开始安装”会创建数据表并写入基础数据。请确认目标数据库为空，或确实是上一次未完成的安装。</p>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('install-form');
            if (!form) return;
            form.addEventListener('submit', function () {
                var button = document.getElementById('install-btn');
                if (!button) return;
                button.disabled = true;
                button.classList.add('loading');
                var text = button.querySelector('.btn-text');
                if (text) text.textContent = '正在安装，请稍候…';
            });
        });
    </script>
    <?php
    render_page_end();
    exit;
}

function render_success(array $result): void
{
    $appUrl = rtrim((string) ($result['app_url'] ?? detect_app_url()), '/');

    render_page_start('安装完成', 3);
    ?>
    <div class="done-hero">
        <div class="done-icon">
            <svg viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="24" cy="24" r="21" stroke-width="2.6"/>
                <path d="M14 24.5l7 7 13-15"/>
            </svg>
        </div>
        <p class="eyebrow">STEP 3 / 3</p>
        <h1>安装完成</h1>
        <p class="lead"><?= e(APP_TITLE) ?> 已初始化完成，请使用刚刚创建的管理员账号登录。</p>
    </div>

    <dl class="summary">
        <div><dt>站点地址</dt><dd><a href="<?= e($appUrl) ?>" target="_blank" rel="noopener"><?= e($appUrl) ?></a></dd></div>
        <div><dt>管理员账号</dt><dd><?= e((string) ($result['username'] ?? '')) ?></dd></div>
        <div><dt>安装时间</dt><dd><?= e((string) ($result['installed_at'] ?? '')) ?></dd></div>
        <div><dt>安装锁</dt><dd><?= ! empty($result['lock_written']) ? 'storage/installed.lock 已写入' : '安装锁未写入，已通过数据库管理员检测锁定' ?></dd></div>
    </dl>

    <div class="alert bad">
        <strong>请立即删除 install.php</strong>
        <p>安装向导已自动锁定，但删除 <code>public/install.php</code> 可以从根本上避免安装程序被再次执行。</p>
    </div>

    <div class="alert info">
        <strong>后续建议</strong>
        <ul class="tips">
            <li>登录后检查基础分、学期、申报类别与教师注册邀请码等系统设置。</li>
            <li>为站点启用 HTTPS，并定期备份数据库与上传文件。</li>
            <li>为每位教师分配独立账号，不要共用管理员账号。</li>
        </ul>
    </div>

    <div class="actions">
        <a class="btn primary" href="<?= e($appUrl.'/login') ?>">进入登录页</a>
        <a class="btn ghost" href="<?= e($appUrl.'/') ?>">打开首页</a>
    </div>
    <?php
    render_page_end();
    exit;
}

function render_locked(array $state): void
{
    $installedAt = '';
    $lockReason = ($state['reason'] ?? '') === 'lock';

    if (! empty($state['installed_at'])) {
        $timestamp = strtotime((string) $state['installed_at']);
        $installedAt = $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : (string) $state['installed_at'];
    }

    $appUrl = rtrim((string) ($state['app_url'] ?? detect_app_url()), '/');

    render_page_start('系统已安装', null);
    ?>
    <div class="done-hero">
        <div class="done-icon lock">
            <svg viewBox="0 0 48 48" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <rect x="11" y="21" width="26" height="19" rx="4"/>
                <path d="M17 21v-5.5a7 7 0 0 1 14 0V21"/>
                <path d="M24 28.5v4"/>
            </svg>
        </div>
        <p class="eyebrow">INSTALLER LOCKED</p>
        <h1>系统已安装</h1>
        <p class="lead">检测到<?= $lockReason ? '安装锁' : '已初始化的数据库' ?>，安装向导已自动锁定，不会重复执行安装。</p>
    </div>

    <dl class="summary">
        <?php if ($installedAt !== ''): ?>
            <div><dt>安装时间</dt><dd><?= e($installedAt) ?></dd></div>
        <?php endif; ?>
        <div><dt>锁定方式</dt><dd><?= $lockReason ? 'storage/installed.lock' : '数据库管理员检测' ?></dd></div>
    </dl>

    <div class="alert warn">
        <strong>安全提示</strong>
        <p>请从服务器删除 <code>public/install.php</code>。如需重新安装，请先备份并清空数据库，再删除 <code>storage/installed.lock</code>。</p>
    </div>

    <div class="actions">
        <a class="btn primary" href="<?= e($appUrl.'/login') ?>">进入登录页</a>
    </div>
    <?php
    render_page_end();
    exit;
}

function validate_install_input(array $input): array
{
    $errors = [];

    if ($input['db_host'] === '') {
        $errors['db_host'] = '请填写数据库主机。';
    } elseif (strlen($input['db_host']) > 255) {
        $errors['db_host'] = '数据库主机过长。';
    } elseif (preg_match('/[\s;=]/', $input['db_host']) === 1) {
        $errors['db_host'] = '数据库主机格式不正确。';
    }

    if ($input['db_port'] === '' || ! ctype_digit($input['db_port'])) {
        $errors['db_port'] = '端口必须是数字。';
    } elseif ((int) $input['db_port'] < 1 || (int) $input['db_port'] > 65535) {
        $errors['db_port'] = '端口范围应为 1-65535。';
    }

    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $input['db_database']) !== 1) {
        $errors['db_database'] = '数据库名只能包含字母、数字与下划线，最长 64 个字符。';
    }

    if ($input['db_username'] === '') {
        $errors['db_username'] = '请填写数据库账号。';
    } elseif (strlen($input['db_username']) > 80) {
        $errors['db_username'] = '数据库账号过长。';
    }

    if (mb_strlen($input['db_password']) > 255) {
        $errors['db_password'] = '数据库密码过长。';
    }

    if ($input['app_url'] === '' || filter_var($input['app_url'], FILTER_VALIDATE_URL) === false) {
        $errors['app_url'] = '请填写有效的站点地址，例如 https://dy.iqiq.cn。';
    } else {
        $scheme = strtolower((string) parse_url($input['app_url'], PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            $errors['app_url'] = '站点地址必须以 http:// 或 https:// 开头。';
        }
    }

    if (mb_strlen($input['admin_name']) < 1 || mb_strlen($input['admin_name']) > 50) {
        $errors['admin_name'] = '管理员姓名长度应为 1-50 个字符。';
    }

    if (preg_match('/^[A-Za-z0-9_.@\-]{3,50}$/', $input['admin_username']) !== 1) {
        $errors['admin_username'] = '登录账号应为 3-50 位字母、数字或 _ . @ -。';
    }

    $password = $input['admin_password'];
    $weakPasswords = ['admin123', '12345678', '123456789', 'password', 'admin@123', 'qwerty123'];

    if (mb_strlen($password) < 8) {
        $errors['admin_password'] = '登录密码至少需要 8 位。';
    } elseif (mb_strlen($password) > 100) {
        $errors['admin_password'] = '登录密码不能超过 100 位。';
    } elseif ($password === $input['admin_username']) {
        $errors['admin_password'] = '登录密码不能与登录账号相同。';
    } elseif (in_array(strtolower($password), $weakPasswords, true)) {
        $errors['admin_password'] = '登录密码过于常见，请更换更强的密码。';
    }

    if ($password !== $input['admin_password_confirmation']) {
        $errors['admin_password_confirmation'] = '两次输入的密码不一致。';
    }

    return $errors;
}

function handle_install(array $state): void
{
    if (! hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
        render_config_page([
            'error' => ['stage' => '安全校验', 'message' => '页面已过期，请刷新页面后重新提交。'],
            'values' => array_map(
                static fn ($value) => is_scalar($value) ? (string) $value : '',
                array_filter($_POST, static fn ($value) => is_scalar($value))
            ),
            'resumable' => ! empty($state['resumable']),
        ]);
    }

    if (! empty($state['installed'])) {
        render_locked($state);
    }

    $input = [
        'db_host' => trim((string) ($_POST['db_host'] ?? '')),
        'db_port' => trim((string) ($_POST['db_port'] ?? '')),
        'db_database' => trim((string) ($_POST['db_database'] ?? '')),
        'db_username' => trim((string) ($_POST['db_username'] ?? '')),
        'db_password' => (string) ($_POST['db_password'] ?? ''),
        'create_database' => ! empty($_POST['create_database']),
        'app_url' => rtrim(trim((string) ($_POST['app_url'] ?? '')), '/'),
        'admin_name' => trim((string) ($_POST['admin_name'] ?? '')),
        'admin_username' => trim((string) ($_POST['admin_username'] ?? '')),
        'admin_password' => (string) ($_POST['admin_password'] ?? ''),
        'admin_password_confirmation' => (string) ($_POST['admin_password_confirmation'] ?? ''),
    ];

    $errors = validate_install_input($input);

    if ($errors !== []) {
        render_config_page([
            'errors' => $errors,
            'values' => $input,
            'resumable' => ! empty($state['resumable']),
        ]);
    }

    $environment = environment_groups();

    if (! checks_pass($environment)) {
        render_config_page([
            'error' => [
                'stage' => '环境检测',
                'message' => '运行环境或目录权限发生变化，请返回环境检测处理后重试。',
            ],
            'values' => $input,
            'resumable' => ! empty($state['resumable']),
        ]);
    }

    $stage = '连接数据库';
    $envWritten = false;
    $progressHandle = null;
    $progressCreated = false;

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $input['db_host'], (int) $input['db_port']),
            $input['db_username'],
            $input['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec('SET NAMES utf8mb4');

        $statement = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$input['db_database']]);
        $databaseExists = (bool) $statement->fetchColumn();

        if (! $databaseExists) {
            if (! $input['create_database']) {
                throw new DyfInstallException('数据库 “'.$input['db_database'].'” 不存在。请先创建数据库，或勾选“数据库不存在时自动创建”。');
            }

            $stage = '创建数据库';
            $pdo->exec(sprintf(
                'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $input['db_database']
            ));
        }

        $pdo->exec(sprintf('USE `%s`', $input['db_database']));

        $stage = '检查数据库权限';
        $probeTable = '__dyf_install_probe_'.bin2hex(random_bytes(4));
        $pdo->exec(sprintf('CREATE TABLE `%s` (`id` INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB', $probeTable));
        $pdo->exec(sprintf('DROP TABLE `%s`', $probeTable));

        $stage = '检查安装进度';
        $progressCreated = ! is_file(PROGRESS_FILE);
        $progressHandle = @fopen(PROGRESS_FILE, 'c+');

        if ($progressHandle === false) {
            throw new DyfInstallException('无法写入 storage/install.in-progress，请检查 storage 目录权限。');
        }

        if (! flock($progressHandle, LOCK_EX | LOCK_NB)) {
            fclose($progressHandle);

            if ($progressCreated) {
                @unlink(PROGRESS_FILE);
            }

            throw new DyfInstallException('另一个安装进程正在运行，请稍后再试。');
        }

        ftruncate($progressHandle, 0);
        fwrite($progressHandle, (string) json_encode([
            'started_at' => date('c'),
            'database' => $input['db_database'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($progressHandle);

        $stage = '检查目标数据库';
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        if ($tables !== [] && empty($state['resumable'])) {
            flock($progressHandle, LOCK_UN);
            fclose($progressHandle);
            $progressHandle = null;

            if ($progressCreated) {
                @unlink(PROGRESS_FILE);
            }

            throw new DyfInstallException('目标数据库中已存在数据表。为避免覆盖现有数据，安装已停止。请使用一个空数据库，或确认这是上一次未完成的安装后重试。');
        }

        $appKey = 'base64:'.base64_encode(random_bytes(32));

        $stage = '写入配置文件';
        $envContent = build_env_content([
            'app_key' => $appKey,
            'app_url' => $input['app_url'],
            'db_host' => $input['db_host'],
            'db_port' => (int) $input['db_port'],
            'db_database' => $input['db_database'],
            'db_username' => $input['db_username'],
            'db_password' => $input['db_password'],
        ]);

        $temporaryEnv = ENV_FILE.'.tmp';

        if (@file_put_contents($temporaryEnv, $envContent) === false) {
            throw new DyfInstallException('无法写入 .env 配置文件，请检查项目根目录权限。');
        }

        if (! @rename($temporaryEnv, ENV_FILE)) {
            @unlink($temporaryEnv);
            throw new DyfInstallException('无法更新 .env 配置文件，请检查项目根目录权限。');
        }

        @chmod(ENV_FILE, 0644);
        $envWritten = true;

        $stage = '清理配置缓存';
        $configCache = APP_ROOT.'/bootstrap/cache/config.php';

        if (is_file($configCache) && ! @unlink($configCache)) {
            throw new DyfInstallException('无法删除 bootstrap/cache/config.php，请手动删除该文件后重试。');
        }

        $stage = '启动应用';
        require_once APP_ROOT.'/vendor/autoload.php';

        /** @var \Illuminate\Foundation\Application $app */
        $app = require APP_ROOT.'/bootstrap/app.php';

        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        $stage = '创建数据表';
        $migrateCode = $kernel->call('migrate', ['--force' => true]);
        $migrateOutput = (string) $kernel->output();

        if ($migrateCode !== 0) {
            throw new DyfInstallException("数据库迁移失败。\n".safe_detail($migrateOutput));
        }

        $stage = '校验数据表';
        $requiredTables = [
            'users',
            'score_applications',
            'score_application_evidences',
            'score_records',
            'group_applications',
            'group_application_members',
            'group_application_evidences',
        ];

        foreach ($requiredTables as $table) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                throw new DyfInstallException('数据表 “'.$table.'” 未创建，数据库迁移可能不完整，请检查 database/migrations 后重试。');
            }
        }

        $stage = '创建管理员';
        /** @var class-string<\App\Models\User> $userClass */
        $userClass = \App\Models\User::class;
        $existingUser = $userClass::query()->where('username', $input['admin_username'])->first();

        if ($existingUser !== null && $existingUser->role !== 'admin') {
            throw new DyfInstallException('登录账号 “'.$input['admin_username'].'” 已被其他用户占用，请更换管理员账号后重试。');
        }

        $admin = $userClass::query()->updateOrCreate(
            ['username' => $input['admin_username']],
            [
                'name' => $input['admin_name'],
                'role' => 'admin',
                'password' => $input['admin_password'],
                'is_active' => true,
            ]
        );

        $admin->forceFill(['remember_token' => null])->save();

        $stage = '初始化基础数据';
        $seedCode = $kernel->call('db:seed', ['--force' => true]);
        $seedOutput = (string) $kernel->output();

        if ($seedCode !== 0) {
            throw new DyfInstallException("初始化基础数据失败。\n".safe_detail($seedOutput));
        }

        $stage = '校验管理员账号';
        $admin->refresh();

        if (! $admin->is_active || ! \Illuminate\Support\Facades\Hash::check($input['admin_password'], (string) $admin->password)) {
            throw new DyfInstallException('管理员账号校验失败，请重试安装。');
        }

        $stage = '清理缓存';

        try {
            $kernel->call('optimize:clear');
        } catch (Throwable) {
            // 缓存清理失败不影响安装结果。
        }

        $stage = '写入安装锁';
        $lockWritten = @file_put_contents(LOCK_FILE, (string) json_encode([
            'installed_at' => date('c'),
            'app_url' => $input['app_url'],
            'php' => PHP_VERSION,
            'laravel' => $app->version(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;

        if ($progressHandle !== null) {
            flock($progressHandle, LOCK_UN);
            fclose($progressHandle);
            $progressHandle = null;
        }

        @unlink(PROGRESS_FILE);

        $_SESSION['install_result'] = [
            'app_url' => $input['app_url'],
            'username' => $input['admin_username'],
            'installed_at' => date('Y-m-d H:i:s'),
            'lock_written' => $lockWritten,
        ];

        header('Location: '.script_url().'?done=1', true, 303);
        exit;
    } catch (PDOException $exception) {
        render_config_page([
            'error' => [
                'stage' => $stage,
                'message' => friendly_db_error($exception),
                'detail' => safe_detail($exception->getMessage()),
            ],
            'values' => $input,
            'env_written' => $envWritten,
            'resumable' => ! empty($state['resumable']),
        ]);
    } catch (Throwable $exception) {
        render_config_page([
            'error' => [
                'stage' => $stage,
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : '安装过程中出现未知错误。',
                'detail' => safe_detail($exception::class."\n".$exception->getMessage()."\n".$exception->getTraceAsString()),
            ],
            'values' => $input,
            'env_written' => $envWritten,
            'resumable' => ! empty($state['resumable']),
        ]);
    }
}

prepare_runtime_directories();

$sessionStarted = session_status() === PHP_SESSION_ACTIVE;

if (! $sessionStarted) {
    session_name('dyf_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $sessionStarted = @session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'");

if (! $sessionStarted) {
    http_response_code(500);
    render_page_start('无法启动会话', null);
    ?>
    <div class="page-head">
        <p class="eyebrow">INSTALLER ERROR</p>
        <h1>无法启动 PHP 会话</h1>
        <p class="lead">安装向导需要 PHP 会话来保护表单。请检查服务器的 session.save_path 配置与目录权限后重试。</p>
    </div>
    <?php
    render_page_end();
    exit;
}

if (empty($_SESSION['csrf']) || ! is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$state = installation_state();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'install') {
    handle_install($state);
}

if (($_GET['done'] ?? '') === '1' && ! empty($_SESSION['install_result']) && is_array($_SESSION['install_result'])) {
    $result = $_SESSION['install_result'];
    unset($_SESSION['install_result']);
    render_success($result);
}

if (! empty($state['installed'])) {
    render_locked($state);
}

$groups = environment_groups();
$pass = checks_pass($groups);

if (($_GET['step'] ?? '') === 'config') {
    if (! $pass) {
        render_environment($groups, [
            'notices' => ['环境检测存在未通过项，请先处理后再继续。'],
            'resumable' => ! empty($state['resumable']),
        ]);
    }

    render_config_page(['resumable' => ! empty($state['resumable'])]);
}

render_environment($groups, ['resumable' => ! empty($state['resumable'])]);

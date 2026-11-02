# 安装说明（面向 AI / 运维执行）

> 本文件是**唯一权威的安装指南**。把整个仓库交给 AI 时，请让它先读本文件，再动手安装。
>
> - 当前版本：仓库根目录（Laravel 13 + PHP 8.3/8.4 + MySQL/MariaDB + Vue 3）。
> - 历史 Flask 版本已归档，不再随项目发布，也不安装或迁移其数据。
> - 安装脚本：`public/install.php`。本文档与安装脚本必须保持同步（见第 9 节）。

---

## 0. 给 AI 的执行原则

1. 先确认目标环境：**Linux 生产服务器**（宝塔/Nginx + PHP-FPM + MySQL）或 **Windows 本地便携版**（自带 runtime 目录）。两者安装方式不同。
2. **禁止**在未确认、未备份的情况下执行 `migrate:fresh`、`db:wipe`、`DROP DATABASE`、删除 `storage/` 等破坏性操作。
3. 不要读取、输出、提交或截图 `.env` 中的数据库密码、APP_KEY；不要把默认密码写进公开页面。
4. 安装完成后**不要删除** `storage/installed.lock`（它负责锁定安装向导）。
5. 安装失败时，保留 `storage/install.in-progress` 以便断点续装，先读页面错误信息再处理。
6. 每个安装步骤都要**实际验证**（HTTP 状态、数据库表、登录接口），不能只看命令返回码。
7. 生产环境只操作目标站点（当前为 `dy.iqiq.cn`），共享服务器上不要改动其他站点。

---

## 1. 项目概览

| 项目 | 值 |
|---|---|
| 应用目录 | `` |
| 站点根目录（必须） | `public/` |
| 后端 | Laravel 13.17，PHP `^8.3`（实测 8.4） |
| 前端 | Vue 3.5 + Inertia 3 + Vite 8 + Tailwind CSS 4 |
| 数据库 | MySQL 5.7+ / MySQL 8 / MariaDB（utf8mb4） |
| 时区 | Asia/Shanghai |
| 会话 | 默认 `SESSION_DRIVER=database`，有效期 120 分钟（单机也可改为 `file`） |
| 关键依赖 | `phpoffice/phpspreadsheet`（Excel 导入导出） |
| 安装入口 | `https://站点域名/install.php` |

**部署时不需要** Python、Flask、SQLite；这些只属于旧版。

---

## 2. 安装前检查

### 2.1 服务器软件

- Web 服务器：Nginx 或 Apache，站点根目录指向 `public`，并开启 PHP 重写（Laravel 路由）。
- PHP：8.3 及以上（推荐 8.4），PHP-FPM 模式。
- 数据库：MySQL 5.7+/8.0+ 或 MariaDB 10.6+，字符集 `utf8mb4`。
- 需要预先上传/安装的目录（安装器**不会**替你安装依赖）：
  - `vendor/`（否则先在本地或服务器执行 `composer install --no-dev --optimize-autoloader`）
  - `public/build/`（否则先执行 `npm ci && npm run build`，或上传构建产物）
  - `database/migrations/`
  - `bootstrap/app.php`

### 2.2 PHP 扩展

必需扩展（缺一即无法继续安装）：

```text
pdo, pdo_mysql, mbstring, openssl, ctype, filter, hash, session, tokenizer,
dom, libxml, simplexml, xml, xmlreader, xmlwriter, zip, zlib, fileinfo, gd, iconv
```

建议扩展（缺失只警告）：

```text
curl, intl
```

宝塔面板可在「软件商店 → PHP 8.4 → 安装扩展」中启用；改完后必须重载 PHP-FPM。

### 2.3 目录权限

Web 运行用户（如 `www`）必须可写：

```text
.env                  （文件不存在时需可写  目录）
storage/
storage/framework/
bootstrap/cache/
```

Linux 参考命令（把 `www` 换成实际运行用户）：

```bash
chown -R www:www storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### 2.4 其他要求

- 安装过程建议 `max_execution_time >= 120`、`memory_limit >= 128M`；安装器自身会临时放宽到 300 秒 / 512M。
- 生产环境必须具备 HTTPS 后再安装，否则管理员密码可能在传输中被窃听（安装页会提示）。
- 目标数据库应**为空库**（安装器会建表并写入初始数据；非空库会被拒绝）。
- 数据库账号需要 `CREATE / ALTER / INDEX / INSERT / UPDATE / DELETE / DROP` 权限；若勾选“自动创建数据库”，还需要建库权限。

---

## 3. 方式 A：浏览器向导安装（生产推荐）

### 3.1 准备代码

1. 将项目文件上传到服务器，例如 `/www/wwwroot/dy.iqiq.cn/`。
2. 宝塔站点根目录设置为 `/www/wwwroot/dy.iqiq.cn/public`。
3. 确认 `vendor/`、`public/build/`、`bootstrap/cache/` 存在，`.env` **不要**从本地直接复制（向导会生成生产配置）。
4. 如存在 `bootstrap/cache/config.php`、`routes-*.php`、`events.php` 等旧缓存，安装器会自动清除配置缓存；为避免旧配置生效，也可手动删除 `bootstrap/cache/*.php`。

### 3.2 打开向导

浏览器访问：

```text
https://你的域名/install.php
```

安装器会自动检测入口地址并填入“站点地址”。

### 3.3 第 1 步：环境检测

页面逐项检查并显示通过/警告/失败：

- PHP 版本是否 ≥ 8.3；
- 必需扩展 20/20、建议扩展 2/2；
- 最大执行时间、内存限制；
- `.env`、`storage`、`storage/framework`、`bootstrap/cache` 是否可写；
- `vendor/autoload.php`、`bootstrap/app.php`、`public/build/manifest.json`、`database/migrations` 是否存在。

**出现红色“失败”时不允许继续安装**，按提示修复后点“重新检测”。

### 3.4 第 2 步：填写配置

三个面板：

1. **数据库连接**
   - 主机：通常是 `127.0.0.1`（或数据库服务器地址）。
   - 端口：MySQL 默认 `3306`；本项目 Windows 便携版为 `3307`。
   - 数据库名：只能包含字母、数字、下划线，最长 64 位。
   - 账号 / 密码：建议使用独立低权限账号，不要用 root。
   - 可勾选“数据库不存在时自动创建”（需要账号有建库权限）。
2. **站点地址**
   - 必须是 `http://` 或 `https://` 开头的完整地址，例如 `https://dy.iqiq.cn`。
   - 该值会写入 `APP_URL`，影响系统内链接、导出与通知，安装后改地址需要同步改 `.env`。
3. **管理员账号**
   - 姓名 1–50 字符；
   - 登录账号 3–50 位，仅限字母、数字及 `_ . @ -`；
   - 密码至少 8 位，不能与账号相同，不能是 `admin123`、`12345678`、`password` 等常见弱口令；
   - 两次密码必须一致。
   - **不会创建默认 `admin/admin123`**；此处填写的账号就是唯一初始管理员。

提交后密码字段不会回显，失败重填时需要重新输入。

### 3.5 安装器做了什么

按以下顺序执行（全部在 PHP 进程内调用 Laravel，**不依赖 exec/shell**）：

1. 校验 CSRF 与表单；
2. 再跑一次环境检测；
3. 连接数据库；必要时创建数据库（`utf8mb4_unicode_ci`）；
4. 建/删临时探针表，验证建表权限；
5. 写入 `storage/install.in-progress` 并加锁，防止并发安装；
6. 校验目标库为空（断点续装时允许已有表）；
7. 生成 `APP_KEY`，先写 `.env.tmp` 再原子替换为 `.env`；
8. 清除旧配置缓存，引导 Laravel Console Kernel；
9. 执行 `migrate --force`；
10. 创建/更新管理员账号并清空 remember token；
11. 执行 `db:seed --force`（检测到已有管理员时不会创建默认 admin）；
12. 用 `Hash::check` 验证管理员密码；
13. 执行 `optimize:clear`；
14. 写入 `storage/installed.lock`，删除进度文件，303 跳转到成功页。

生成的 `.env` 关键项：

```env
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Shanghai
DB_CONNECTION=mysql
SESSION_DRIVER=database
SESSION_LIFETIME=120
QUEUE_CONNECTION=database
CACHE_STORE=database
MAIL_MAILER=log
```

### 3.6 完成与验证

成功页显示站点地址、管理员账号与安装时间（**不显示密码**）。

必须完成以下验证：

```bash

# 1) 迁移全部执行
php artisan migrate:status

# 2) 表数量应为 28 张（随迁移增加，与 database/migrations 同步）
php artisan tinker --execute="echo count(DB::select('SHOW TABLES'));"

# 3) 学生集体申请相关表必须存在
php artisan tinker --execute="foreach (['group_applications','group_application_members','group_application_evidences'] as \$t) { echo \$t.': '.(\Illuminate\Support\Facades\Schema::hasTable(\$t) ? 'ok' : 'missing').PHP_EOL; }"

# 4) 管理员存在且启用
php artisan tinker --execute="echo \App\Models\User::where('role','admin')->count();"

# 5) 登录页可访问
curl -I https://你的域名/login
```

然后用刚设置的管理员账号实际登录一次，确认能进入工作台。

> `Hash::check` 已在安装器内执行；如果登录失败，先检查 `APP_URL`、会话表与 Cookie 域名，见第 8 节。

本次构建需额外验收的流程（用对应角色账号逐项操作）：

1. 学生端“我的申请 → 发起集体申请”：只能选本班同学，发起人自动加入，提交 1–3 张图片；
2. 管理员/学院管理员审核集体申请通过后，发起人与每位成员各生成一条集体成绩；
3. 辅导员 `/admin/review`、班干部 `/officer/review` 显示本班排行榜，并可点击“导出”下载当前班级排行榜（排行榜、加减分明细、学期汇总 3 个工作表，格式与系统管理员导出一致），且只能导出授权班级；
4. 系统管理员、学院管理员、辅导员均可在审核页录入扣分，扣分范围以各自管理权限为限；班干部只能为本班同学扣分，不能给本人扣分；
5. 审核页驳回原因可留空；已通过的个人/集体申请可“取消通过并扣回分值”，取消后申请退回待审核；
6. 已通过的个人申请可由辅导员、班干部（系统管理员、学院管理员亦可）修改核定分值，学生成绩记录与总分同步更新，审计日志记录修改前后分值；
7. 所有列表与详情显示核定分值（`final_score`），与成绩记录严格一致；
8. 学生“我的申请”中，待审核/已驳回/已撤回的个人与集体申请可点“修改”，只能改标题、说明、日期与证明材料（1–3 张）；已驳回的修改后重新进入待审核；审核通过后修改入口消失且接口返回 403；
9. 管理员、学院管理员、辅导员可在审核页删除权限范围内的申请，班干部可在 `/officer/review` 删除同班同学的申请（不能删除本人）；删除已通过的个人申请会撤销对应成绩并扣回分值，删除已通过的集体申请会撤销全部成员成绩；待审核/已驳回/已撤回申请直接删除；
10. 删除已通过志愿服务申请导致本学期通过次数不足 4 次时，自动加的 6 分同步撤销；
11. 个人/集体申请详情页的证明材料保持 4:3 等比显示，多图不会撑变形页面；
12. 学生班干部在 `/officer/review` 可见“本班申请 / 本班集体”双标签，只能查看成员全部属于其管辖班级的集体申请及详情，不能查看跨班集体申请，也不能进入 `/admin/review`；辅导员在 `/admin/review` 同样只能查看成员全部属于其管辖班级的集体申请；
13. 学生、班干部、辅导员、管理员的证明材料统一为预览卡片：点击缩略图打开大幅预览弹窗（支持多图切换、上一张/下一张、键盘方向键），弹窗内与卡片右上角均可下载原图；缩略图固定 4:3，不随图片尺寸改变页面布局。
14. 学生端“我的申请 → 手工录入”展示系统管理员手工录入的本人成绩记录，已撤销记录保留并显示“已撤销”和撤销原因；不会显示其他同学的记录；
15. 班干部 `/officer/review`、辅导员/学院管理员/系统管理员 `/admin/review` 的“手工录入”标签可查看权限范围内的记录：班干部只看本班同学（不含本人），辅导员/学院管理员只看管辖范围；支持按状态（有效/已撤销）、学期、类别、姓名学号筛选；
16. 只有系统管理员可以在“成绩管理”手工录入、修改成绩，其他管理角色只读；撤销为软撤销并显示“已撤销”；系统管理员可对 `source_type=manual` 的记录执行“永久删除”，删除后学生端与各审核端均不再显示且不可恢复；个人/集体申请审核生成的成绩记录不能永久删除。
17. 集体申请在教师端“集体申请”列表、审核中心“集体申请”标签及学生/教师/审核三端详情页均显示分值：成员分值一致时显示“每人 +X 分”，存在差异时显示“每人 +X ~ +Y 分”；待审核取提交分值，已通过取核定分值。
18. 学生、教师、管理员工作台及数据统计、列表、详情、表单等页面在手机宽度（320–430px）下，页头标题与卡片、内容区左右边缘对齐，页面无横向滚动，长标题自动截断显示。

### 3.7 安装状态与重装规则

安装器按以下优先级判断状态：

1. 存在 `storage/installed.lock` → 已安装，显示锁定页；
2. 存在 `storage/install.in-progress` → 上次中断，显示“继续安装”，允许在已有表上续装；
3. `.env` 有 APP_KEY，且数据库中 `users` 表存在至少一个 `admin` → 视为已安装；
4. 其他情况 → 显示安装向导。

**重装**：仅当确实要清空数据时，先备份数据库，再由人工确认后删除 `storage/installed.lock` 与 `.env`，并使用空数据库重新安装。`dyf_moral` 等生产库禁止直接删除。

### 3.8 安装完成后（建议）

为防止安装入口被再次访问，可在确认安装成功后二选一（**不要删除锁文件**）：

- 保留 `install.php`：已安装状态下只显示锁定页，无安装表单；
- 或重命名/删除 `public/install.php`（日后升级需要重新上传）。

同时确认 `.env` 权限（如 `640`）、站点已强制 HTTPS、`APP_DEBUG=false`。

---

## 4. 方式 B：命令行安装（无浏览器 / 自动化）

适用于 CI、容器或无法访问浏览器的情况。**必须使用空数据库**。

```bash

# 1) 依赖与前端构建（如仓库已包含 vendor/ 和 public/build/ 可跳过）
composer install --no-dev --optimize-autoloader
npm ci && npm run build
# 服务器未全局安装 composer 时，改用项目自带：php composer.phar install --no-dev --optimize-autoloader

# 2) 生成配置
cp .env.example .env
# 编辑 .env：APP_ENV=production、APP_DEBUG=false、APP_URL=https://你的域名、DB_* 指向目标库
php artisan key:generate --force

# 3) 建表
php artisan migrate --force

# 4) 先创建自定义管理员（避免种子创建 admin/admin123）
php artisan tinker --execute="\App\Models\User::updateOrCreate(['username'=>'你的管理员账号'], ['name'=>'系统管理员','role'=>'admin','password'=>'你的强密码','is_active'=>true]);"

# 5) 初始化基础数据
php artisan db:seed --force

# 6) 清缓存并写入缓存（生产）
php artisan optimize:clear
php artisan optimize
```

注意：

- 第 4 步必须在第 5 步之前，否则种子会创建默认 `admin/admin123`（仅当库中还没有任何管理员）。
- User 模型对 `password` 有 `hashed` 转换，第 4 步直接传明文即可，框架会自动哈希；不要重复 `Hash::make()` 后再传（重复哈希会导致无法登录）。
- 命令行方式**不会**生成 `storage/installed.lock`。如需锁定网页安装器，手工创建：

```bash
php -r "file_put_contents('storage/installed.lock', json_encode(['installed_at'=>date('c'),'app_url'=>'https://你的域名'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));"
```

- 安装后仍建议访问一次 `/install.php`，确认显示“系统已安装”而不是安装表单。

---

## 5. 方式 C：Windows 本地便携版

仓库的 `` 自带 PHP 8.4 与 MariaDB 运行时（`runtime/`，不纳入 Git）：

1. 确保 `runtime/php/php.exe`、`runtime/mariadb/` 存在（Windows 10/11 x64，需 VC++ 2015–2022 运行库）。
2. 双击 `setup.bat`：初始化 MariaDB 数据目录、建库建账号、生成 `.env`/APP_KEY、安装依赖、迁移+种子、构建前端。
3. 双击 `run.bat`，访问 <http://localhost:8080>；`stop.bat` 停止数据库。
4. 开发模式（Vite 热更新）双击 `dev.bat`。
5. 此方式创建的默认管理员为 `admin / admin123`（`setup.bat` 走标准种子）。**仅限本机开发**，首次登录后立即改密；生产环境请使用方式 A 或 B。

局域网访问：`http://本机IP:8080`，需放行防火墙；不要用 PHP 内置服务器对外提供服务。

---

## 6. 安装后的初始数据

种子会写入（重复执行不会重置已有管理员密码）：

| 数据 | 内容 |
|---|---|
| 系统设置 | 基础分 40、系统名称、学生注册开关、教师注册开关、教师注册邀请码（随机生成） |
| 学期 | 2026-2027 学年第一、第二学期，第一学期设为当前学期 |
| 类别 | 校级活动、学院级活动、志愿服务、扣分 |
| 学院/专业 | 7 个学院、36 个专业（信息工程、工学、商学、财税、文法、艺术、体育） |
| 管理员 | 安装向导填写的自定义管理员；若无管理员才创建 `admin/admin123` |

**教师注册邀请码**在“系统设置”中查看/修改；种子执行时也会打印到控制台。上线前必须：

1. 修改管理员密码（后台修改密码会使该账号所有设备退出）；
2. 更换教师注册邀请码；
3. 按需关闭/开启学生注册与注册审核；
4. 在“学期管理”中确认当前学期；
5. 在“组织管理/用户管理”中导入真实班级与学生（模板在页面内下载）。

---

## 7. 生产环境配置要点

1. 站点根目录必须是 `public`，禁止直接暴露 `storage/`、`runtime/`、`.env`、`vendor/`。
2. `.env` 必须：`APP_ENV=production`、`APP_DEBUG=false`、`APP_URL=https://域名`、独立数据库账号。
3. 使用 HTTPS；Nginx/Apache 给 `public/build` 设置长期缓存，PHP 开启 OPcache。
4. 单机可保持 `SESSION_DRIVER=database`、`CACHE_STORE=database`、`QUEUE_CONNECTION=database`；多机部署改用 Redis。
5. `storage/app/private/evidence`、`storage/app/private/appeals` 存放证明材料，需随数据库一起定期备份。
6. 上传目录禁止执行 PHP 脚本。
7. 修改 `.env` 后执行 `php artisan optimize:clear && php artisan optimize`；若已开启配置缓存，改环境变量不会自动生效。

---

## 8. 故障排查

| 现象 | 可能原因 | 处理 |
|---|---|---|
| 访问 `/install.php` 404 | 站点根目录不是 `public` | 修正 Nginx/Apache 站点根目录与重写规则 |
| 页面空白 / 500 | 安装器关闭了页面错误显示；若是 Laravel 异常查 `storage/logs/laravel.log`，若是 PHP 致命错误查 PHP-FPM 错误日志 | 查看日志；确认扩展与权限 |
| “必需扩展缺失” | PHP 未启用对应扩展 | 宝塔启用扩展并重载 PHP-FPM |
| “配置文件 .env 不可写” | 目录/文件权限不足 | 给 `` 或 `.env` 写权限 |
| “数据库账号或密码不正确” (1045) | 账号密码错误 | 核对 `.env`/表单；不要用 root 远程账号 |
| “无权访问该数据库” (1044) | 账号未授权该库 | 授权或改用有权限账号 |
| “目标数据库不存在” (1049) | 未建库且未勾选自动创建 | 建库或勾选自动创建 |
| “无法连接到数据库服务器” (2002/2003) | 服务未启动/端口错误/防火墙 | 启动 MySQL，检查端口（便携版 3307） |
| “目标数据库中已存在数据表” | 非空库且非续装 | 换空库；若确为上次中断，保留进度文件后重试 |
| “另一个安装进程正在运行” | 并发安装 | 等待另一进程结束，或检查 `install.in-progress` 锁 |
| “页面已过期” | CSRF 超时/重复提交 | 刷新页面重新填写 |
| 安装成功但登录 419/CSRF | APP_URL、Cookie 域名或会话表异常 | 检查 `APP_URL`/HTTPS；确认 `sessions` 表存在；清缓存 |
| 页面白屏但接口 200 | `public/build` 缺失或版本不匹配 | 重新 `npm run build` 并上传 |
| 重复访问仍显示安装向导 | 未生成锁文件且检测不到管理员 | 检查 `.env`/数据库连接；成功页是否真的出现 |

数据库错误码对照（安装器已内置友好提示）：`1045` 密码错误、`1044` 无库权限、`1049` 库不存在、`2002/2003` 连不上服务、`1130` 主机未授权、`1142` 缺建表/写入权限、`1698` 认证方式不支持。

---

## 9. 维护约定（强制）

**任何功能更新，必须在同一次改动中同步更新以下两个文件：**

1. `public/install.php`（安装脚本）
2. `INSTALL.md`（本文件）

触发同步的改动包括但不限于：

| 改动类型 | 安装脚本要改什么 | 本文件要改什么 |
|---|---|---|
| 新增/修改数据库迁移 | 通常自动执行，无需改代码 | 更新第 3.6 节“表数量”与验证命令 |
| 新增 `.env` 配置项 | `build_env_content()` 增加默认值 | 第 3.5 节 `.env` 示例、第 7 节说明 |
| 新增 PHP 扩展依赖 | `environment_groups()` 扩展列表 | 第 2.2 节扩展清单 |
| 修改最低 PHP/Laravel 版本 | `MIN_PHP_VERSION`、`composer.json` | 第 1、2 节版本要求 |
| 新增种子/初始数据 | 确认 seeder 幂等 | 第 6 节初始数据表 |
| 修改管理员规则/密码策略 | `validate_install_input()`、创建逻辑 | 第 3.4 节表单规则 |
| 修改安装流程、锁文件、成功校验 | 对应函数 | 第 3.5–3.7 节、第 8 节 |
| 修改前端构建产物路径 | 项目文件检测项 | 第 2.1、8 节 |
| 新增安装后必做配置 | 成功页提示（如适用） | 第 6、7 节 |

**同步验收清单**（每次改完安装相关逻辑都要跑）：

- [ ] `php -l public/install.php` 无语法错误；
- [ ] 在**空测试库**上完整走通安装向导（含管理员创建、迁移、种子、登录）；
- [ ] 弱密码、两次密码不一致、错误数据库密码、非空数据库四类失败分支提示正确；
- [ ] 中断后用 `install.in-progress` 能续装，成功后进度文件被清理、锁文件生成；
- [ ] 已安装状态再次访问只显示锁定页；
- [ ] `INSTALL.md` 与脚本中的版本、扩展、字段、验证数字一致；
- [ ] 不在文档或页面中出现真实密码、APP_KEY、生产数据库凭据。

---

## 10. 关键路径速查

```text
public/install.php              # 网页安装向导
public/build/manifest.json      # 前端构建产物入口（必须存在）
.env                            # 安装后生成，建议权限 640，禁止提交
storage/installed.lock          # 安装锁，成功后生成，不要删除
storage/install.in-progress     # 中断标记，用于断点续装
bootstrap/cache/config.php      # 旧配置缓存，安装时自动清除
database/migrations/            # 数据库结构
database/seeders/DatabaseSeeder.php  # 初始数据与默认管理员逻辑
README.md                       # 功能与业务规则说明
SELF_TEST.md                    # 自检与测试说明
```

# 郑州工商学院德育分管理系统 — 开发移交文档

> 用途：把当前项目完整移交给新的开发人员或 AI 开发平台。
> 读者应能在不阅读历史对话的情况下，理解系统做了什么、怎么做的、代码在哪里、数据怎么存、线上怎么部署、哪些地方有风险。
>
> 配套文档：
> - `README.md`：项目简介、角色权限、快速开始
> - `INSTALL.md`：服务器要求、安装向导、验收清单、维护约定
> - `docs/README-detail.md`：功能与实现细节的较长版本
> - `SELF_TEST.md`：历次自测记录

---

## 1. 项目概览

### 1.1 基本信息

| 项 | 值 |
| --- | --- |
| 项目名称 | 基于郑州工商学院德育分统计办法的德育分后台管理系统 |
| 生产域名 | <https://dy.iqiq.cn> |
| 代码根目录 | 仓库根目录即 Laravel 根目录（原 `php-app` 子目录已提升为根） |
| 主要用途 | 学生德育分申请、审核、集体活动计分、扣分与申诉、排行榜、统计导出 |
| 默认基础分 | 40 分（系统设置可改），总分 = 基础分 + 加分 − 扣分，最低 0，不设上限 |
| 数据规模 | 全校学生、学院/专业/年级/班级、逐学期成绩与审计日志 |

### 1.2 角色

| 角色值 | 名称 | 核心范围 |
| --- | --- | --- |
| `student` | 学生 | 本人申请、成绩、申诉、涉及本人的集体申请 |
| `teacher` | 教师 | 发起集体申请、只读排行榜（可看全校排行与学生明细） |
| `class_admin` | 辅导员 | 授权年级/班级范围内的学生、个人申请审核、集体申请审核、成绩、申诉、排行榜与统计 |
| `college_admin` | 学院管理员 | 授权学院内全部业务，含教师、专业、班级、集体审核、辅导员班级配置 |
| `admin` | 系统管理员 | 全校组织、用户、类别、学期、公告、设置、全部审核与成绩 |

另有两类“附加身份”（不改变主角色）：

- **班干部**：仍是 `student`，通过 `class_officer_user` 授权；可审核同班同学（不能审核本人）的申请、查看本班集体申请（只读）、为本班同学扣分。
- **年级辅导员授权**：通过 `grade_admin_user` 给 `class_admin` 增加“学院 + 专业 + 年级”范围，与其直接班级授权取并集。

### 1.3 已实现功能总览

**学生端**
- 登录、退出、修改密码（改密后所有设备会话失效）
- 工作台：总分、待审申请、待处理申诉、公告
- 我的德育分：学期/学年切换、类别汇总、明细、已撤销标记、Excel 导出
- 个人申请：创建、编辑未通过申请、撤回、删除未通过申请、图片证明 1–3 张
- 学生集体申请：只能选本班同学，发起人自动加入，1–3 张图片
- 扣分申诉：填写理由、上传图片、查看处理结果
- 公告查看（支持“登录后弹窗”公告）

**教师端**
- 集体申请：Excel/CSV 导入成员、模板下载、证明材料、成员明细、撤回
- 排行榜只读查看；点击学生可查看其德育分明细

**审核端（系统管理员 / 学院管理员 / 辅导员 / 班干部）**
- 审核中心三个标签：个人申请、集体申请、手工录入
- 个人申请：通过并计分、驳回（原因可空）、修改核定分值、取消通过并扣回、删除
- 集体申请：辅导员与学院管理员可审核、逐成员核定分值、取消通过、删除
- 批量通过/驳回（逐条仍做范围校验）
- 审核列表按状态、学期、类别、**班级**、姓名/学号筛选
- 审核页内嵌本班/范围内排行榜并导出（3 个工作表）
- 扣分申诉审核：通过后自动撤销扣分；驳回需填写原因
- 扣分录入：按当前管理范围选择学生，支持按姓名/学号搜索

**管理端**
- 组织管理：学院、专业、班级、学院管理员、年级/班级辅导员、班干部
- 用户管理：新增、编辑、启禁用、重置密码、删除（管理员）、Excel/CSV 导入
- 成绩管理：手工录入、修改、软撤销、永久删除手工记录
- 数据统计、排行榜与导出、类别管理、学期管理、公告管理、审计日志、系统设置
- 安装向导 `public/install.php`

### 1.4 最近一轮需求（本次移交重点）

1. **辅导员可审核管辖班级的集体申请**
2. **所有审核列表增加班级筛选**（个人、集体、手工录入、扣分申诉）
3. **排行榜学生详情**：任何能看排行榜的角色都可点击学生查看其详细德育分记录
4. **编写本移交文档**

实现细节见第 7 章。

---

## 2. 技术架构

### 2.1 技术栈

| 层 | 技术 |
| --- | --- |
| 后端框架 | Laravel 13（PHP 8.4） |
| 前端 | Vue 3.5 + Inertia.js 3 + Vite 8 + Tailwind CSS 4 |
| 图标 | lucide-vue-next |
| 数据库 | 生产 MySQL 5.7；本地便携 MariaDB 11.4（端口 3307） |
| Excel | phpoffice/phpspreadsheet |
| 会话 | 生产 `file`，本地 `file`/`database` 视 .env |
| 队列/缓存 | `sync` / `file` |
| 测试 | PHPUnit 12 + Laravel `RefreshDatabase` + Inertia 断言 |
| 部署 | 宝塔面板 + Nginx + php-fpm-84；Paramiko Python 部署脚本 |

### 2.2 请求链路

```
浏览器
  → Nginx（dy.iqiq.cn，enable-php-84.conf）
  → public/index.php（Laravel 入口）
  → 路由 routes/web.php
  → 中间件 auth / role / HandleInertiaRequests
  → Controller（校验、权限、业务）
  → Service（ScoreService / AppealService / ExportService …）
  → Eloquent Model → MySQL
  → Inertia::render(页面组件, props)
  → resources/js/pages/*.vue 在浏览器渲染
```

- 无独立 API 层：页面数据通过 Inertia props 下发，表单通过 Inertia `router.post/put` 提交，返回重定向 + flash。
- 全局共享数据由 `app/Http/Middleware/HandleInertiaRequests.php` 注入：`auth.user`、`permissions`、`scopeInfo`、`appSettings`、`semesters`、`periodOptions`、`flash`。

### 2.3 本地运行

项目自带便携运行时：

```bat
run.bat    :: 启动 MariaDB 3307 + Laravel 8080
stop.bat   :: 停止两个服务与 MariaDB
setup.bat  :: 新目录首次安装（建库、迁移、种子、npm 构建）
```

手动命令：

```bat
runtime\mariadb\bin\mariadbd.exe --basedir=%CD%\runtime\mariadb --datadir=%CD%\runtime\mariadb-data --port=3307 --bind-address=127.0.0.1 --console
runtime\php\php.exe artisan serve --host=0.0.0.0 --port=8080
```

- 本地地址：<http://localhost:8080>
- 本地数据库：`dyf_moral`（端口 3307）
- `.env` 不纳入版本控制；生产 `.env` 只存在于服务器。

### 2.4 目录结构

```
/（仓库根 = Laravel 根）
├─ app/
│  ├─ Http/
│  │  ├─ Controllers/        业务控制器
│  │  └─ Middleware/         EnsureRole、HandleInertiaRequests
│  ├─ Models/                Eloquent 模型
│  ├─ Services/              计分、申诉、导入导出等业务服务
│  └─ Support/               范围、周期、学期、设置、净化等纯支撑类
├─ bootstrap/                Laravel 启动与中间件注册
├─ config/                   配置
├─ database/
│  ├─ migrations/            全部表结构与数据迁移
│  ├─ seeders/               初始数据
│  └─ factories/
├─ public/
│  ├─ index.php              Web 入口
│  ├─ install.php            安装向导
│  └─ build/                 Vite 构建产物（不入 Git）
├─ resources/
│  ├─ js/
│  │  ├─ app.js              Inertia 启动
│  │  ├─ layouts/            认证/访客布局
│  │  ├─ pages/              页面组件（与控制器一一对应）
│  │  ├─ components/         通用 UI 与业务组件
│  │  ├─ composables/        组合式函数
│  │  └─ lib/                格式化、图片、HTML 工具
│  ├─ css/
│  └─ views/app.blade.php    根模板
├─ routes/web.php            全部路由
├─ storage/                  日志、会话、上传证明材料
├─ tests/                    PHPUnit 测试
├─ runtime/                  便携 PHP/MariaDB（不入 Git）
├─ vendor/                   Composer 依赖（不入 Git）
├─ deploy_*.py               历史生产发布脚本
├─ deploy_php.sh             生产 PHP 8.4 切换脚本
├─ deploy_db.sh              生产首装建库脚本
├─ INSTALL.md / README.md / SELF_TEST.md
└─ docs/                     详细文档与本移交文档
```

---

## 3. 后端文件职责

### 3.1 控制器

| 文件 | 职责 |
| --- | --- |
| `Auth/AuthController.php` | 登录、退出、登录节流与失败原因反馈 |
| `Auth/RegisterController.php` | 学生注册、注册开关 |
| `Auth/TeacherRegisterController.php` | 教师注册、邀请码校验 |
| `DashboardController.php` | 工作台数据（按角色与范围） |
| `ApplicationController.php` | 个人申请 CRUD、撤回、图片证明预览/下载、我的成绩导出 |
| `GroupApplicationController.php` | 教师/学生集体申请 CRUD、成员导入、证明预览/下载 |
| `ReviewController.php` | 审核中心列表、个人/集体审核、批量审核、核定分值、删除、审核端排行榜导出 |
| `AppealController.php` | 学生申诉、管理端申诉列表/审核、证明下载 |
| `ScoreController.php` | 排行榜、排行榜导出、成绩管理、手工录入、扣分、学生详情 |
| `StatisticsController.php` | 数据统计 |
| `UserController.php` | 用户 CRUD、启禁用、重置密码、导入、模板 |
| `OrganizationController.php` | 学院/专业/班级、学院管理员、年级/班级辅导员、班干部授权 |
| `CategoryController.php` | 德育类别 |
| `SemesterController.php` | 学期创建、设为当前、删除 |
| `AnnouncementController.php` | 公告 CRUD（含弹窗公告） |
| `AuditLogController.php` | 审计日志查询 |
| `SettingController.php` | 系统设置 |
| `ProfileController.php` | 修改密码（改后清空其他会话） |

### 3.2 服务与支撑类

| 文件 | 职责 |
| --- | --- |
| `Services/ScoreService.php` | 核心计分：申请通过/驳回/取消、集体成员计分、手工记录、撤销、志愿服务规则、学生汇总 |
| `Services/AppealService.php` | 申诉通过/驳回及扣分撤销 |
| `Services/ExportService.php` | 个人成绩/排行榜 Excel 导出 |
| `Services/LeaderboardExportService.php` | 排行榜 + 加减分明细 + 学期汇总三表导出 |
| `Services/ImportService.php` | 用户 Excel/CSV 导入、角色识别、班级匹配 |
| `Support/AccessScope.php` | **权限范围核心**：学院/班级/学生范围、集体可见与可审核、学生详情可见、班干部范围、管理用户范围 |
| `Support/PeriodResolver.php` | 排行榜周期解析（总排行/本周/本月/自定义/学期/学年） |
| `Support/SemesterStore.php` | 学期列表与当前学期缓存 |
| `Support/SettingsStore.php` | 系统设置读写 |
| `Support/VolunteerRule.php` | 志愿服务每 4 次自动 +6 分与学期上限 |
| `Support/ClassNaming.php` | 班级显示名规范化 |
| `Support/HtmlSanitizer.php` | 公告 HTML 安全净化 |
| `Support/PageSize.php` | 统一分页大小（10/20/50/100/全部） |
| `Http/Middleware/EnsureRole.php` | 角色中间件，未授权或停用返回 403 |
| `Http/Middleware/HandleInertiaRequests.php` | Inertia 共享权限、范围、学期、设置 |

### 3.3 模型

`User`、`College`、`Major`、`SchoolClass`、`GradeAdminScope`、`Semester`、`ScoreCategory`、`ScoreApplication`、`ScoreApplicationEvidence`、`ScoreRecord`、`GroupApplication`、`GroupApplicationMember`、`GroupApplicationEvidence`、`Appeal`、`Announcement`、`AuditLog`、`Setting`。

模型主要承担关系定义与少量状态方法（如 `User::isAdmin()`、`GroupApplication::isStudentOrigin()`、`ScoreRecord::isRevoked()`）。

---

## 4. 前端文件职责

### 4.1 布局与基础

| 文件 | 职责 |
| --- | --- |
| `layouts/AuthenticatedLayout.vue` | 深色可折叠侧栏、顶部栏、面包屑、标签页、主题色、移动端底部导航、导航项按角色生成 |
| `layouts/GuestLayout.vue` | 登录/注册页布局 |
| `app.js` | Inertia 应用启动、全局错误处理 |
| `components/ui/*` | Card、Button、Badge、Modal、ConfirmDialog、Tabs、Toggle、Pagination、PeriodSelect、EvidenceGallery、UserPicker 等 |
| `components/ScoreSummary.vue` | 总分/加分/扣分/基础分汇总卡 |
| `lib/format.js` | 日期、分值、来源标签 |
| `lib/evidence.js` | 证明材料 URL 与预览 |
| `lib/html.js` | 公告 HTML 安全处理 |

### 4.2 页面

| 页面 | 路由 | 说明 |
| --- | --- | --- |
| `Dashboard.vue` | `/dashboard` | 工作台（按角色展示） |
| `Auth/Login.vue` | `/login` | 登录 |
| `Auth/RegisterChoice.vue` | `/register` | 注册身份选择 |
| `Auth/Register.vue` | `/register/student` | 学生注册 |
| `Auth/TeacherRegister.vue` | `/register/teacher` | 教师注册 |
| `Applications/Index.vue` | `/applications` | 学生我的申请 |
| `Applications/Create.vue` | `/applications/create` | 个人申请 |
| `Applications/Edit.vue` | `/applications/{id}/edit` | 修改未通过申请 |
| `Applications/GroupCreate.vue` | `/applications/group/create` | 学生集体申请 |
| `Applications/GroupEdit.vue` | `/applications/group/{id}/edit` | 修改集体申请 |
| `Applications/GroupShow.vue` | `/applications/group/{id}` | 学生查看集体申请 |
| `GroupApplications/Index.vue` | `/teacher/group-applications` | 教师集体申请列表 |
| `GroupApplications/Create.vue` | `/teacher/group-applications/create` | 教师发起集体申请 |
| `GroupApplications/Show.vue` | `/teacher/group-applications/{id}` | 教师查看集体申请 |
| `Review/Index.vue` | `/admin/review`、`/officer/review` | 审核中心（三标签 + 班级筛选 + 班内排行榜 + 扣分） |
| `Review/GroupShow.vue` | `/admin/review/groups/{id}` | 集体申请审核详情 |
| `Appeals/Index.vue` | `/appeals` | 学生申诉 |
| `Appeals/AdminIndex.vue` | `/admin/appeals` | 申诉审核（含班级筛选） |
| `Leaderboard/Index.vue` | `/leaderboard` | 排行榜（周期/范围/专业/搜索，姓名可点击） |
| `Students/Show.vue` | `/leaderboard/students/{id}`、`/admin/students/{id}` | 学生德育分明细（周期切换、记录表） |
| `Scores/My.vue` | `/my-scores` | 学生我的德育分 |
| `Records/Index.vue` | `/admin/records` | 成绩管理 |
| `Statistics/Index.vue` | `/admin/statistics` | 数据统计 |
| `Users/Index.vue` | `/admin/users` | 用户管理 |
| `Organization/Index.vue` | `/admin/organization` | 组织与授权管理 |
| `Categories/Index.vue` | `/admin/categories` | 类别管理 |
| `Semesters/Index.vue` | `/admin/semesters` | 学期管理 |
| `Announcements/Index.vue` | `/announcements` | 公告 |
| `Audit/Index.vue` | `/admin/audit-logs` | 审计日志 |
| `Settings/Index.vue` | `/admin/settings` | 系统设置 |
| `Profile/Password.vue` | `/profile/password` | 修改密码 |

---

## 5. 权限模型

### 5.1 路由层

- `routes/web.php` 用 `role:...` 中间件分组：
  - 学生：`role:student`（含班干部接口 `/officer/*`，班干部资格在控制器内判断）
  - 教师：`role:teacher`
  - 排行榜：`role:admin,teacher,college_admin,class_admin`
  - 审核/成绩/统计/用户：`role:admin,college_admin,class_admin`
  - 组织管理、创建用户：`role:admin,college_admin`
  - 类别、学期、导入、设置、审计、学院 CRUD：`role:admin`

### 5.2 数据范围层（`AccessScope`）

关键方法：

| 方法 | 语义 |
| --- | --- |
| `collegeIds($user)` | `admin` 返回 `null`（全部）；学院管理员取授权学院；辅导员取其班级所属学院 |
| `classIds($user)` | `admin` 返回 `null`；辅导员 = 直接班级授权 + 年级授权覆盖的班级；学院管理员 = 授权学院全部班级 |
| `studentIds($user)` | 范围内学生 ID |
| `scopeStudents` / `scopePersonalApplications` / `scopeScoreRecords` / `scopeAppeals` | 列表范围过滤 |
| `scopeGroupApplications` | 集体申请可见性：成员必须全部在范围内（辅导员/学院管理员）；教师只看自己发起 |
| `canAccessGroup` | 单条集体申请是否全部成员在范围内 |
| `canReviewGroup`（本次修改） | `admin` 全通过；`college_admin`/`class_admin` 且 `canAccessGroup` 才可审核 |
| `canViewStudentProfile`（本次新增） | 学生本人资料须为 `student`；`admin` 全部；管理角色按 `canAccessStudent`；教师可看全部学生 |
| `canAccessStudent` | 管理员全部；辅导员按班级；学院管理员按学院；其他仅本人 |
| `officerClassIds` / `officerStudentIds` | 班干部授权班级与可审核同学（排除本人） |
| `canManageUser` | 用户管理中的可操作范围 |
| `canCreateRole` | 管理员可建学生/教师/管理员；学院管理员可建学生/教师 |

**重要约定**：所有“列表可见”与“单条可操作”必须同时校验。列表用 `scope*`，详情/写操作再用 `can*`，避免只改前端筛选造成越权。

### 5.3 前端权限

`HandleInertiaRequests` 注入 `permissions`（`can_review`、`can_review_groups`、`can_manage_scores`、`can_manage_manual` 等）与 `scopeInfo`（学院名、班级名、年级授权标签）。导航菜单、按钮、筛选选项据此渲染。**前端权限只影响展示，最终以后端为准。**

---

## 6. 数据库架构

### 6.1 组织与账号

#### `users`
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint PK | |
| `username` | varchar(80) unique | 登录账号（学生=学号，教师=工号） |
| `name` | varchar(100) | 姓名 |
| `email` | varchar unique nullable | |
| `email_verified_at` | timestamp nullable | |
| `password` | varchar | bcrypt |
| `role` | varchar(20) | `student`/`teacher`/`class_admin`/`college_admin`/`admin` |
| `student_id` | varchar(30) unique nullable | |
| `employee_id` | varchar(30) unique nullable | |
| `class_name` | varchar(50) nullable | 冗余显示名（历史字段） |
| `college` | varchar(100) nullable | 冗余显示名（历史字段） |
| `grade` | varchar(20) nullable | |
| `teacher_college` | varchar(100) nullable | 教师学院（文本，历史字段） |
| `college_id` | FK colleges nullable | 结构化学院 |
| `major_id` | FK majors nullable | 专业（仅查询维度） |
| `class_id` | FK classes nullable | 学生班级 |
| `teacher_college_id` | FK colleges nullable | 教师所属学院 |
| `is_active` | boolean | 停用后不能登录/访问 |
| `last_login_at` | timestamp nullable | |
| `remember_token` | varchar | |
| `created_at/updated_at` | | |

> `class_name`/`college`/`teacher_college` 为早期文本字段，保留兼容；新逻辑以 `class_id`/`college_id`/`major_id` 为准。

#### `colleges`
`id`、`name`(unique)、`code`、`description`、`is_active`、timestamps。

#### `majors`
`id`、`college_id` FK、`name`、`code`、`is_active`、timestamps；唯一 `(college_id, name)`。

#### `classes`
`id`、`college_id` FK、`major_id` FK nullable、`name`、`grade`、`is_active`、timestamps；唯一 `(college_id, name, grade)`。

#### 授权关系表
| 表 | 字段 | 说明 |
| --- | --- | --- |
| `college_admin_user` | `id`、`user_id`、`college_id`、`granted_by`、timestamps | 学院管理员授权；唯一 `(user_id, college_id)` |
| `class_admin_user` | `id`、`user_id`、`class_id`、`granted_by`、timestamps | 辅导员直接班级授权；唯一 `(user_id, class_id)` |
| `grade_admin_user` | `id`、`user_id`、`college_id`、`major_id`、`grade`、`granted_by`、timestamps | 辅导员年级授权；唯一 `(user_id, college_id, major_id, grade)` |
| `class_officer_user` | `id`、`user_id`、`class_id`、`title`、`granted_by`、timestamps | 班干部授权；唯一 `(user_id, class_id)` |

### 6.2 学期、类别与设置

#### `semesters`
`id`、`academic_year`、`term`(1/2)、`name` unique、`start_date`、`end_date`、`is_current`、timestamps；唯一 `(academic_year, term)`。

#### `score_categories`
`id`、`name`、`type`(`add`/`deduct`)、`code` unique nullable、`description`、`default_score`、`max_score`、`sort_order`、`is_active`、timestamps。
`code='volunteer'` 表示志愿服务类别，触发“每 4 次 +6 分”规则。

#### `settings`
`id`、`key` unique、`value` text、timestamps。
常用键：`system_name`、`base_score`、`registration_enabled`、`teacher_registration_enabled`、`teacher_invite_code`。

### 6.3 申请与成绩

#### `score_applications`（个人申请）
`id`、`user_id` FK、`category_id` FK、`semester_id` FK、`activity_name`、`applied_on`、`description`(500)、`score`、`final_score` nullable、`status`(`pending`/`approved`/`rejected`/`withdrawn`)、`reviewer_id`、`review_comment`、`reviewed_at`、`evidence_path`、`evidence_name`、timestamps。
索引：`(status, created_at)`、`(user_id, semester_id)`。

#### `score_application_evidences`
`id`、`score_application_id` FK cascade、`path`、`name`、`sort_order`、timestamps。

#### `group_applications`（集体申请）
`id`、`teacher_id` FK、`category_id` FK、`semester_id` FK、`title`(200)、`description`、`status`、`reviewer_id`、`review_comment`、`reviewed_at`、`evidence_path`、`evidence_name`、`origin`(`teacher`/`student`)、`initiator_id` nullable、`applied_on` nullable、timestamps。
索引：`(status, created_at)`、`(origin, status)`、`initiator_id`。

#### `group_application_members`
`id`、`group_application_id` FK cascade、`student_id` FK、`score`、`final_score` nullable、timestamps；唯一 `(group_application_id, student_id)`。

#### `group_application_evidences`
`id`、`group_application_id` FK cascade、`path`、`name`、`sort_order`、timestamps。

#### `score_records`（最终成绩）
| 字段 | 说明 |
| --- | --- |
| `id` | |
| `user_id` | 学生 |
| `category_id` | 类别，可空（类别删除后 null） |
| `semester_id` | 学期 |
| `score` | 分值，支持 0.5（decimal 语义由应用层保证） |
| `applied_on` | 上报/计入日期（排行榜周期按它过滤） |
| `source_type` | `personal` / `group` / `manual` / `volunteer_bonus` |
| `source_id` | 对应申请/集体申请 ID，手工为 null |
| `description` | |
| `created_by` | 操作人 |
| `revoked_at` | 软撤销时间，非空即不计分 |
| `revoked_by` | 撤销人 |
| `revoke_reason` | |
| timestamps | |

索引：`(user_id, semester_id)`、`(category_id, semester_id)`、`(source_type, source_id)`、`(revoked_at, created_at)`、`applied_on`。

#### `appeals`（扣分申诉）
`id`、`score_record_id` FK cascade、`user_id` FK、`reason` text、`evidence_path`、`evidence_name`、`status`(`pending`/`approved`/`rejected`)、`reviewer_id`、`review_comment`、`reviewed_at`、timestamps。

### 6.4 公告、审计与系统表

#### `announcements`
`id`、`title`(200)、`content` longText（净化后 HTML）、`is_important`、`is_popup`、`author_id`、`published_at`、timestamps。

#### `audit_logs`
`id`、`user_id` nullable、`action`(50)、`target_type`、`target_id`、`meta` JSON、`ip`、`created_at`。所有审核、成绩、申诉、用户、类别、学期、公告操作写入。

#### Laravel 基础表
`sessions`（生产 file 驱动时基本不写入）、`password_reset_tokens`、`cache`、`cache_locks`、`jobs`、`job_batches`、`failed_jobs`。

### 6.5 关系摘要

```
colleges 1─n majors 1─n classes 1─n users(student)
colleges 1─n classes
users(student/teacher) 1─n score_applications 1─n score_application_evidences
users(teacher/student) 1─n group_applications 1─n group_application_members n─1 users(student)
group_applications 1─n group_application_evidences
users(student) 1─n score_records n─1 score_categories / semesters
score_records 1─n appeals（一条扣分可多次申诉，历史保留）
users 1─n announcements / audit_logs
users n─n colleges（college_admin_user）
users n─n classes（class_admin_user、class_officer_user）
users n─n (college, major, grade)（grade_admin_user）
```

### 6.6 计分规则

- 有效记录：`revoked_at IS NULL`。
- 总分：`max(0, base_score + SUM(正分) - ABS(SUM(负分)))`。
- 个人申请通过：按 `final_score`（未填则 `score`）生成一条 `source_type=personal`。
- 集体申请通过：每位成员按各自 `final_score`（未填则 `score`）生成 `source_type=group`。
- 志愿服务：同一学生同一学期第 4 次通过时自动生成 `source_type=volunteer_bonus` +6；后续每次 +6，但有学期上限（见 `VolunteerRule`）。
- 撤销/取消通过：写 `revoked_at`，不物理删除；手工记录可由管理员永久删除。
- 修改已通过个人申请的分值：同步更新对应成绩记录，并写审计日志。

---

## 7. 关键流程与实现步骤

### 7.1 登录与会话

1. `AuthController::store` 校验账号、密码、`is_active`。
2. 失败时给出具体原因（账号不存在/密码错误/已停用），不暴露默认账号。
3. 登录成功后写 `last_login_at`；支持“记住我”。
4. 修改密码：`ProfileController` 校验旧密码，更新后清理当前用户其他会话/记住令牌，实现“所有设备强制退出”。
5. 生产 session 驱动 `file`，有效期 120 分钟；记住我约 400 天。

### 7.2 个人申请

1. 学生提交：类别、分值、活动日期、说明、1–3 张图片。
2. 图片存 `storage/app/private/evidence`，数据库记录相对路径。
3. 审核端通过：锁定申请行 → 校验 `pending` → 写 `final_score`、`reviewer`、生成成绩记录 → 审计。
4. 驳回：状态 `rejected`，原因可空；学生可修改后重新提交。
5. 取消通过：成绩记录软撤销，申请回到 `pending`。
6. 删除：待审/驳回/撤回直接删；已通过则先撤销成绩再删（并写审计）。

### 7.3 集体申请

1. 教师端：导入成员（Excel/CSV）、填写标题/类别/分值/日期、上传证明。
2. 学生端：只能选本班同学，发起人自动加入；成员必须全部属于本人班级。
3. 可见范围：成员的学院/班级必须全部落在当前管理范围内（`scopeGroupApplications`）。
4. 审核：`ReviewController::approveGroup` 逐成员写 `final_score` 并生成成绩；并发用行锁。
5. 批量审核：`bulk()` 对每条调用同一权限判断，避免批量绕过范围。
6. 删除已通过集体申请：撤销全部成员成绩。

### 7.4 本次需求 1：辅导员审核集体申请

修改点：

- `app/Support/AccessScope.php`
  ```php
  public function canReviewGroup(User $user, GroupApplication $application): bool
  {
      if ($this->isSuper($user)) return true;

      return ($this->isCollegeAdmin($user) || $this->isClassAdmin($user))
          && $this->canAccessGroup($user, $application);
  }
  ```
- `routes/web.php`：集体申请 `approve/reject/cancel/destroy` 从仅管理员组移入 `role:admin,college_admin,class_admin` 的 `/admin` 组。
- `app/Http/Middleware/HandleInertiaRequests.php`：`can_review_groups => $user->isManager()`，辅导员显示审核按钮。
- `app/Http/Controllers/ReviewController.php::bulk`：集体批量审核允许 `class_admin`，但逐条仍校验 `canReviewGroup`。
- 前端 `Review/GroupShow.vue`：只读提示只在班干部/教师等无审核权限时显示。

验收：辅导员可见本班集体申请、可进入详情、可通过/驳回；跨范围详情和接口返回 403；班干部仍只读。

### 7.5 本次需求 2：审核列表班级筛选

修改点：

- `ReviewController::index`
  - 新增 `filterClassOptions($user, $officerMode)`：管理员全部班级；辅导员/学院管理员按 `classIds()`；班干部按 `officerClassIds()`。
  - `class_id` 非法或越权时静默置空，不报错也不泄露。
  - 个人申请/手工记录：`whereHas('user', fn ($q) => $q->where('class_id', $classId))`
  - 集体申请：`whereHas('members.student', fn ($q) => $q->where('class_id', $classId))`
  - props 增加 `classes`、`filters.class_id`。
- `AppealController::adminIndex`：同样的班级选项与 `whereHas('user')` 过滤。
- 前端：
  - `resources/js/pages/Review/Index.vue` 增加班级下拉（类别之后）。
  - `resources/js/pages/Appeals/AdminIndex.vue` 增加班级下拉（学期之后）。
  - 筛选表单提交时保留 `tab`，重置回到无筛选。

语义：个人/手工/申诉按“申请人班级”过滤；集体申请按“包含该班成员”过滤。

### 7.6 本次需求 3：排行榜学生详情

修改点：

- `routes/web.php`：新增
  ```php
  Route::get('/leaderboard/students/{student}', [ScoreController::class, 'studentDetail'])
      ->name('leaderboard.students.show');
  ```
  放在 `role:admin,teacher,college_admin,class_admin` 组内。
- `AccessScope::canViewStudentProfile`：
  - 目标必须是 `student`；
  - 管理员全部；
  - 辅导员/学院管理员按 `canAccessStudent`；
  - 教师放行（因为教师本来就能看全校排行榜）。
- `ScoreController::studentDetail`：
  - 路由 `/leaderboard/students/{id}` 时默认 `period=all`（全部学期）；
  - 管理端 `/admin/students/{id}` 保持原有周期行为；
  - 根据路由生成 `detailUrl`，前端切换周期不跳错地址；
  - `summary` 由 `ScoreService::studentSummary` 生成（全部记录，含已撤销）。
- `resources/js/components/ui/PeriodSelect.vue`：新增 `includeAll` 与“全部时间（所有学期）”。
- `resources/js/pages/Students/Show.vue`：新增必填 `detailUrl`，周期切换用 `router.get(detailUrl, { period })`；记录数同时显示已撤销数量。
- `resources/js/pages/Leaderboard/Index.vue`：前三名卡片、桌面/移动端榜单姓名全部链接到 `/leaderboard/students/{id}`（不再只对管理员开放）。

权限结论：

| 查看者 | 可看学生详情范围 |
| --- | --- |
| `admin` | 全校 |
| `teacher` | 全校（与排行榜可见范围一致） |
| `college_admin` | 授权学院 |
| `class_admin` | 授权年级/班级 |
| `student` | 无排行榜路由权限，直接访问 403 |

### 7.7 排行榜与导出

- `PeriodResolver` 解析周期：总排行、本周、本月、自定义、学期、学年汇总。
- 排行范围：全校/学院/年级/班级；专业仅作为筛选维度。
- 周期排行按 `applied_on` 落在区间内的净分排序；总排行按累计总分。
- 导出 `LeaderboardExportService` 生成 3 个工作表：排行榜、加减分明细、学期汇总。
- 审核端导出自动限定当前班级/范围，并用 `AccessScope` 校验。

### 7.8 申诉

1. 学生只能对本人“扣分”且未撤销的记录发起申诉。
2. 管理端按范围列出，班级筛选可用。
3. 通过：申诉状态 `approved`，对应扣分记录软撤销，总分恢复；写审计。
4. 驳回：必须填写原因。

### 7.9 组织管理与导入

- 学院 → 专业 → 班级层级维护；删除前校验下级数据。
- 辅导员授权两种方式：直接班级、专业+年级批量。
- 用户导入：模板列包含角色识别字段；学生按“学院+专业+年级+班级”匹配班级；教师只需学院；导入后按角色启用。
- 教师注册：邀请码在系统设置中配置，校验通过即启用。

### 7.10 安装器

`public/install.php`：
1. 检查 PHP 扩展、目录可写、数据库连接。
2. 写入 `.env`、生成 `APP_KEY`。
3. 执行迁移与种子，创建管理员。
4. 写 `storage/installed.lock`，之后显示锁定页。
5. 中断可续装；重装必须人工删除锁文件与 `.env` 并确认清库。

> 每次功能更新必须同步 `INSTALL.md` 验收清单；`install.php` 仅在安装步骤/环境要求变化时修改。

---

## 8. 测试与构建

### 8.1 后端测试

```bash
runtime/php/php.exe artisan test
```

- 使用 SQLite/测试库（见 `phpunit.xml`）与 `RefreshDatabase`。
- 最近一次全量：**151 passed / 1122 assertions**。
- 本轮新增：`tests/Feature/ClassAdminGroupReviewAndLeaderboardDetailTest.php`
  - 辅导员集体审核范围与越权 403
  - 批量审核范围
  - 审核/申诉列表班级筛选
  - 排行榜学生详情角色与范围
  - 默认全部记录与学期筛选

### 8.2 前端构建

```bash
npm run build
```

- 产物在 `public/build/`。
- 本地验收需实际登录各角色，检查桌面和 390px 左右窄屏无横向溢出。

### 8.3 代码风格

```bash
runtime/php/php.exe vendor/bin/pint
```

---

## 9. 线上部署

### 9.1 生产环境

| 项 | 值 |
| --- | --- |
| 域名 | <https://dy.iqiq.cn> |
| 面板 | 宝塔面板（共享服务器，**只允许修改本站点**） |
| 站点根 | `/www/wwwroot/dy.iqiq.cn`（Laravel 根，Web 入口为 `public/`） |
| PHP | 8.4，CLI `/www/server/php/84/bin/php`，FPM 服务 `php-fpm-84` |
| Nginx vhost | `/www/server/panel/vhost/nginx/dy.iqiq.cn.conf`，引用 `enable-php-84.conf` |
| 数据库 | MySQL 5.7，库 `dyf_moral`，应用账号 `dyf`（密码只在服务器 `.env`） |
| 会话/缓存/队列 | `file` / `file` / `sync` |
| 上传文件 | `storage/app/private/evidence`（必须随站点目录一起备份） |
| 备份目录 | `/root/dyf_backups/dy.iqiq.cn_YYYYmmdd_HHMMSS.tar.gz` |
| 安装锁 | `storage/installed.lock` |
| 日志 | `/www/wwwlogs/dy.iqiq.cn.error.log`、`storage/logs/laravel.log` |

### 9.2 凭据管理

- SSH、MySQL root、应用数据库密码**不写入仓库、文档、回复或记忆**。
- 历史发布脚本 `deploy_*.py` 会从指定会话记录行读取 SSH 凭据，凭据由项目所有者保管。
- 生产数据库密码只存在于服务器站点 `.env`；如需轮换，先备份并在宝塔/SSH 内同时更新数据库账号与 `.env`。
- 公开页面与文档不得展示默认管理员密码或初始密码规则。

### 9.3 发布流程（标准）

1. 本地：
   ```bash
   runtime/php/php.exe artisan test
   npm run build
   ```
2. 打包变更文件（通常为 `app/ routes/ resources/ public/build/`，必要时含 `database/migrations/`、`INSTALL.md`）为 `tar.gz`。
3. SSH 到服务器：
   ```bash
   mkdir -p /root/dyf_backups
   tar -czf /root/dyf_backups/dy.iqiq.cn_$(date +%Y%m%d_%H%M%S).tar.gz -C /www/wwwroot dy.iqiq.cn
   ```
4. 上传并解压到 `/www/wwwroot/dy.iqiq.cn`。
5. 如有迁移：
   ```bash
   cd /www/wwwroot/dy.iqiq.cn
   /www/server/php/84/bin/php artisan migrate --force
   ```
6. 权限与缓存：
   ```bash
   chown -R www:www app routes resources public/build storage database
   /www/server/php/84/bin/php artisan optimize:clear
   /etc/init.d/php-fpm-84 reload
   ```
7. 验收：
   - `curl -I https://dy.iqiq.cn/login` 返回 200
   - 本地与远端 `public/build/manifest.json` MD5 一致
   - `/admin/review`、`/leaderboard`、`/admin/appeals`、`/admin/organization` 可访问
   - 错误日志无新增 fatal
   - 用真实角色账号走一遍本次改动流程

### 9.4 回滚

- 回滚整个站点：
  ```bash
  cd /www/wwwroot
  tar -xzf /root/dyf_backups/dy.iqiq.cn_<时间戳>.tar.gz
  /www/server/php/84/bin/php artisan optimize:clear
  /etc/init.d/php-fpm-84 reload
  ```
- 若本轮有数据库迁移，回滚代码前先确认迁移是否可逆；不可逆时应同时从数据库备份恢复，禁止只回代码不回数据。

### 9.5 一次性/环境脚本

| 文件 | 用途 |
| --- | --- |
| `deploy_db.sh` | 首次建库、建应用账号、导入 SQL、写 `.env`；仅空库可执行 |
| `deploy_php.sh` | 安装/启用 fileinfo、opcache，将站点 PHP 从 8.2 切到 8.4 |
| `deploy_release_*.py` | 历史逐次发布脚本（备份、上传、解压、清缓存、HTTP/MD5 校验） |
| `check_release_*.py` | 对应版本的线上检查脚本 |
| `cleanup_backups_*.py` | 备份清理 |
| `verify_release_*.py` | 发布后数据/页面校验 |

新 AI 接手时**不要直接重跑历史发布脚本**（其中包含旧版本路径与凭据读取方式）；按 9.3 的步骤执行，并保留备份。

---

## 10. 风险、约束与遗留事项

### 10.1 必须遵守

1. **共享服务器**：只能修改 `dy.iqiq.cn` 站点及其数据库，禁止修改同服务器其他站点、Nginx 全局配置或其他 PHP 版本。
2. **破坏性操作需文字确认**：删除文件、删除数据、清库、覆盖备份前必须得到项目所有者明确文字确认。
3. **不得输出密码**：任何回复、脚本文本、文档、记忆中都不得写入 SSH/MySQL/应用数据库密码。
4. **公开页面**：登录页等不得显示默认管理员账号/密码或初始密码规则。
5. **生产库 `dyf_moral` 禁止直接删除**；重装必须人工确认并先备份。
6. **前端确认弹窗**：使用站内玻璃弹窗（`ConfirmDialog`），破坏性按钮二次确认；禁止原生 `confirm/prompt`。
7. **设置开关**使用玻璃滑块（`Toggle`），语义不可反。
8. 每次功能更新同一轮同步 `INSTALL.md`（及必要时 `install.php`），并跑验收清单。

### 10.2 技术风险

| 风险 | 说明与建议 |
| --- | --- |
| MySQL 5.7 兼容 | 生产为 5.7，避免使用 8.0 专属语法/函数；本地 MariaDB 验证后仍需线上回归 |
| 学生详情权限 | 教师当前可看全校学生明细（与排行榜一致）。若产品收紧，改 `AccessScope::canViewStudentProfile` 并补测试 |
| 集体申请范围定义 | 采用“成员全部在范围内”，跨班/跨学院成员会导致记录对所有辅导员不可见；产品变更需同步列表、详情、审核、导出 |
| 班级筛选静默忽略 | 非法/越权 `class_id` 被置空，不返回错误；若前端需要提示需另加 |
| 记录数含义 | `summary.record_count` 只统计有效记录，已撤销单独计数；页面已同时展示 |
| 证明材料 | 存 `storage/app/private`，不在 `public`；迁移/换服务器必须连同 `storage` 一起复制，否则历史图片 404 |
| 会话强制失效 | 依赖 file session 清理；若切换到数据库/Redis 驱动需重写清理逻辑 |
| 志愿规则 | `VolunteerRule` 有学期上限与自动 +6，改动时必须补回归测试（撤销/删除/申诉联动） |
| 部署凭据脚本 | 历史 `deploy_*.py` 含凭据读取逻辑，不应提交新密码，也不应把 `.env` 纳入仓库 |
| 旧仓库历史 | 旧公开提交中曾出现敏感 SQL/xlsx；根目录已重写，但旧 GitHub 提交 SHA 在 GC 前仍可能可访问，建议所有者轮换相关密钥 |
| 备份完整性 | 备份只打包站点目录；数据库需另行 `mysqldump`。恢复时站点文件与数据库时间点必须匹配 |
| 时区 | 生产 `APP_TIMEZONE=Asia/Shanghai`；排行榜“本周/本月”依赖它，改动需谨慎 |

### 10.3 待办/可改进

- 学生详情页对“已撤销记录”可增加撤销原因列。
- 班级筛选可改为可搜索下拉（当前为原生 select，班级很多时体验一般）。
- 排行榜导出与详情页的“记录数”口径可统一成一个字段说明。
- 集体申请跨班场景尚未提供“部分成员审核”，当前整单通过/驳回。

---

## 11. 新接手 AI 的上手顺序

1. 阅读本文件第 1、2、5、6 章，建立角色、范围、数据模型心智图。
2. 阅读 `routes/web.php`，按路由分组定位控制器。
3. 阅读 `app/Support/AccessScope.php`，这是所有越权问题的根源与防线。
4. 阅读 `app/Services/ScoreService.php`，理解分数如何产生、撤销、汇总。
5. 本地启动：
   ```bat
   run.bat
   ```
   然后访问 <http://localhost:8080>。
6. 用测试账号分别登录学生、教师、辅导员、学院管理员、系统管理员，走一遍：
   - 学生提交个人/集体申请
   - 审核端审核、驳回、取消通过
   - 排行榜点击学生查看明细
   - 审核列表班级筛选
7. 修改前先跑 `artisan test` 与 `npm run build` 建立基线。
8. 任何涉及权限/计分/删除的改动，必须同时补 Feature 测试，并验证“列表可见 + 单条操作 + 越权 403”三层。
9. 发布前备份，发布后核对 manifest MD5、HTTP 状态与错误日志。

---

## 12. 本轮改动文件清单（便于 code review）

后端：
- `app/Support/AccessScope.php`
- `app/Http/Controllers/ReviewController.php`
- `app/Http/Controllers/AppealController.php`
- `app/Http/Controllers/ScoreController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `routes/web.php`

前端：
- `resources/js/pages/Review/Index.vue`
- `resources/js/pages/Review/GroupShow.vue`
- `resources/js/pages/Appeals/AdminIndex.vue`
- `resources/js/pages/Leaderboard/Index.vue`
- `resources/js/pages/Students/Show.vue`
- `resources/js/components/ui/PeriodSelect.vue`

测试与文档：
- `tests/Feature/ClassAdminGroupReviewAndLeaderboardDetailTest.php`
- `INSTALL.md`
- `README.md`
- `docs/README-detail.md`
- `docs/HANDOFF.md`（本文件）

# 基于郑州工商学院德育分统计办法的德育分后台管理系统

**Moral Education Score Administration System — built on the moral education scoring rules of Zhengzhou Technology and Business University**

[简体中文](README.md) | **English**

A web-based platform for recording, reviewing, and reporting students' moral education scores. The project source lives directly at the repository root and is built with **Laravel 13 + PHP 8.4 + MariaDB/MySQL + Vue 3 + Inertia + Vite + Tailwind CSS 4**. The legacy Flask implementation has been archived and is no longer part of the repository.

> For installation, deployment, and maintenance, read [INSTALL.md](INSTALL.md) (Chinese). The built-in web installer is available at `/install.php`.

---

## Highlights

- **Semester model** — every academic year contains two semesters. Applications and score records are archived per semester, while leaderboards, personal scores, and statistics can be aggregated by semester or by academic year.
- **Full application workflow** — individual applications from students and group applications from teachers, with review approval/rejection, score adjustment, withdrawal, deletion, and score rollback.
- **Evidence uploads** — multiple images (JPG / PNG / WebP) per application, with an in-app gallery and lightbox preview for reviewers.
- **Deduction appeals** — students can appeal deduction records with a reason and evidence; approved appeals mark the record as withdrawn without deleting the audit trail.
- **Leaderboards** — school-wide, college, grade, and class rankings; weekly, monthly, custom range, semester, and academic-year views; filtering by major, name, or student ID; Excel export with the active scope and period.
- **Statistics** — category distribution, college distribution, 12-month application trend, top students, and period switching.
- **Organization hierarchy** — school → college → major → grade → class. Majors are a query/statistics dimension only; they do not have their own administrators.
- **Graded permissions** — students, teachers, counselors (`class_admin`), college administrators, and system administrators each see only the data within their authorized scope.
- **Announcements** — rich-text announcements with an optional student dashboard popup.
- **System settings** — configurable system name, base score, registration switches, and other operational options.
- **Responsive UI** — collapsible dark sidebar on desktop, bottom navigation and card layouts on mobile, with light motion effects and a reduced-motion setting.

## Scoring Model

- The default **base score** is 40 and can be changed in system settings.
- **Total score = base score + additions − deductions**, with a minimum of 0 and no upper cap.
- **Main categories** (e.g. ideological and political theory, academic research, social service, awards, collective activities, sports and arts, student leadership) and **sub-categories** have their own score caps.
- **Student leadership scores** count only the highest single item instead of accumulating.
- **Volunteer service** follows a dedicated rule: every four qualifying services grant an automatic bonus, subject to the semester cap.
- Half-point (0.5) scores are supported by backend validation and the frontend forms.

## Repository Layout

```text
.
├── app/                     # Controllers, services, models, middleware
├── database/                # Migrations, seeders, and factories
├── public/                  # Entry point and web installer (install.php)
├── resources/js/            # Vue 3 pages, layouts, and UI components
├── resources/views/         # Blade shell for Inertia
├── routes/                  # Web and console routes
├── tests/                   # Feature and unit tests
├── docs/README-detail.md    # Detailed implementation notes (Chinese)
├── INSTALL.md               # Installation, deployment, acceptance checklist (Chinese)
├── README.md                # Chinese documentation (primary)
└── README.en.md             # This English document
```

## Requirements

- PHP **8.4** with the extensions required by Laravel (`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`, etc.)
- MySQL **5.7+** or MariaDB **10.3+**
- Composer 2
- Node.js 20+ and npm (for building frontend assets)

## Quick Start

### Option A — Web installer (recommended for shared hosting)

1. Upload the project so that the document root points to `public/`.
2. Make `storage/` and `bootstrap/cache/` writable by the web server.
3. Visit `https://your-domain/install.php` and follow the wizard: environment check → database configuration → data initialization → administrator creation.
4. The installer writes `storage/installed.lock` and locks itself when finished.

### Option B — Command line

```bash
composer install
cp .env.example .env
php artisan key:generate

# Configure DB_* in .env, then:
php artisan migrate --seed
npm ci
npm run build

php artisan serve
```

No default administrator account or default password is shipped. Create the first administrator during installation.

## Testing and Build

```bash
php artisan test      # backend feature/unit tests
npm run build         # production frontend build
```

## Roles and Permissions

| Role | Scope |
|------|-------|
| **Student** | Own applications, scores, deductions, appeals, group applications involving themselves, announcements |
| **Teacher** | Group applications, member details, own category management, read-only leaderboard, announcements |
| **Counselor (`class_admin`)** | Students, individual application review, scores, appeals, leaderboard, and statistics within authorized grades/classes; no user creation and no group application review |
| **College administrator** | All of the above within authorized colleges, plus teachers, majors, classes, group application review, and counselor class assignments |
| **System administrator** | Organization-wide configuration, college administrator authorization, user management, all reviews, statistics, and system settings |

Every list, statistic, export, review, and appeal is filtered by the caller's authorized scope; cross-scope access returns **403**.

## Documentation

- [INSTALL.md](INSTALL.md) — server requirements, web/CLI installation, production deployment, acceptance checklist, and troubleshooting (Chinese)
- [docs/README-detail.md](docs/README-detail.md) — detailed implementation, features, and conventions (Chinese)
- [README.md](README.md) — primary project documentation (Chinese)

## License

This project is released under the **GNU General Public License v3.0**. See [LICENSE](LICENSE) for the full text.

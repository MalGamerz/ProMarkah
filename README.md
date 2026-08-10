# ProMarkah

ProMarkah is a lightweight scoring and attendance management system for silat competitions. It provides judge scoring, public QR-based attendance, PIC (person-in-charge) management, and admin tools for results and exports.

Status: Imported (sanitized). Secrets and server configuration must be supplied during deployment.

## Key features

- Judge scoring workflow with draft autosaves and locking
- QR-based public attendance (no login required for scanning)
- PIC admin pages for competition structure and results
- Export attendance and results (CSV/PDF)
- Minimal dependencies: plain PHP + vanilla JS; Composer used only for OAuth token verification

## Quickstart (development)

1. Clone the repo:
   git clone https://github.com/MalGamerz/ProMarkah.git
2. Install PHP dependencies (if required):
   composer install
3. Configure secrets outside the web root (see "Secrets and deployment")
4. Serve with PHP built-in server for local testing:
   php -S localhost:8000 -t public_html

## Deployment notes

- Secrets (DB credentials, QR HMAC key, Telegram bot token) must live outside the web root and never be committed. See `secrets.sample.php` for required keys.
- `security_bootstrap.php` must be able to load `bootstrap_secrets.php` to configure error reporting and alerting.
- Top-level filenames (e.g., `judge.php`, `attendance.php`, `save_attendance.php`) are load-bearing URLs and should not be renamed.
- Ensure file permissions prevent web access to any secrets stored on disk.

## What was sanitized

This repository was imported with sensitive files removed. If you need the original README or other docs restored, provide confirmation and the exact files to restore.

## Code map (high level)

- Shared/core: `db.php`, `auth_check.php`, `security_bootstrap.php`, `layout.php`, `layout.js`
- Attendance: `attendance.php`, `save_attendance.php`, `attendance_student.php`, export pages
- Judge: `judge.php`, `judge_ajax.php`, `judge_marking.js`
- PIC/admin: `pic_*.php`, `admin.php`, `admin_data.php`

## Contributing

- Open issues or PRs for bug fixes and refactors.
- Avoid committing secrets. Use `secrets.sample.php` as a template.

## License

No LICENSE file is included in this repository. This project is currently unlicensed — do not assume permission to reuse, redistribute, or modify the code until a proper license is added. To add a license or request reuse permissions, contact the maintainer: MalGamerz.

---

Maintainer: MalGamerz


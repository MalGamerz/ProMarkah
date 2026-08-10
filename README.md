# ProMarkah

ProMarkah is a scoring and attendance management system for silat competitions. This repository contains the application code only; all sensitive files were removed during import.

Status

- Repository state: sanitized import. Secrets and server configuration have been removed and must be provided at deployment time.

Key features

- Judge scoring workflow with draft autosaves and locking
- Public QR-based attendance (no login required for scanning)
- PIC (person-in-charge) admin for competition structure and results
- Exports: CSV and PDF
- Minimal runtime dependencies: PHP (mysqli), vanilla JavaScript, CSS

Requirements

- PHP 7.4 or newer (mysqli extension)
- Composer (for optional dependency management)
- A web server or PHP built-in server for local testing

Quickstart (development)

1. Clone the repository:
   git clone https://github.com/MalGamerz/ProMarkah.git
2. Install dependencies:
   composer install
3. Provision secrets (mandatory):
   - Copy `secrets.sample.php` to a file outside the web root (example: `../secrets.php`).
   - Edit `../secrets.php` with production credentials (DB, QR HMAC key, Telegram token).
   - Ensure the secrets file is not web-accessible and set file permissions to restrict access (chmod 600).
4. Start a local dev server (from repo root):
   php -S localhost:8000 -t public_html
5. Open http://localhost:8000 in a browser to test the public pages.

Deployment checklist

- Place `secrets.php` outside the web root (one directory above `public_html`) and do not commit it.
- Confirm `security_bootstrap.php` can load `bootstrap_secrets.php`.
- Disable display_errors in production; log errors to a secure, non-web-accessible path.
- Set correct file ownership and permissions for the web server user.

Security

- No secrets or credentials are stored in this repository.
- Do not commit any file that contains passwords, private keys, tokens, or credentials.
- The repository has been sanitized to remove sensitive files; if any secret remains, report it immediately.

Code map (high level)

- Shared/core: `db.php`, `auth_check.php`, `security_bootstrap.php`, `layout.php`, `layout.js`
- Attendance: `attendance.php`, `save_attendance.php`, `attendance_student.php`, export pages
- Judge: `judge.php`, `judge_ajax.php`, `judge_marking.js`, judge assets
- PIC/admin: `pic_*.php`, `admin.php`, `admin_data.php`

Contributing

- Open issues or PRs for bug fixes, security reports, or refactors.
- Do not add secrets to commits. Use `secrets.sample.php` as a template and never commit the real secrets file.

License

- No LICENSE file is included. This project is unlicensed. Do not reuse, redistribute, or modify the code without explicit permission from the maintainer.

Maintainer

- MalGamerz (contact via the repository owner account)

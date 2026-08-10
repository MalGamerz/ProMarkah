# ProMarkah

ProMarkah is a scoring and attendance management system for silat competitions. This README maps the codebase for new maintainers.

## Tech stack

Plain PHP (mysqli) + vanilla JS + CSS. One Composer dependency is used for OAuth token verification.

## Important constraints

- Top-level PHP filenames are load-bearing URLs — do not rename them.
- Public attendance pages are intentionally accessible without login.

## Request/bootstrap order

Most authenticated pages follow:

```php
session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
```

`security_bootstrap.php` orchestrates error handling and secret loading; secrets are not included in this repository and must be supplied securely on the server.

## Map of the codebase

- Shared/core: `db.php`, `auth_check.php`, `security_bootstrap.php`, `layout.php`, `layout.js`, `attendance_helpers.php`, `oauth_helpers.php`.
- Public attendance flow: `attendance.php`, `save_attendance.php`, export pages, and related view files.
- Judge scoring: `judge.php`, `judge_ajax.php`, `judge_marking.js`, and related assets.
- PIC management: `pic_*.php` family for competition structure and data.
- Admin and login: `admin.php`, `admin_data.php`, `login.php`, `oauth_*` files.

## Secrets and deployment

Real credentials are NOT included in this repository. Follow the deployment docs for secure secret provisioning.


# ProMarkah

ProMarkah is a scoring and attendance management system for silat (martial
arts) competitions — judges score students on live rubric criteria, PICs
(persons-in-charge) configure the competition structure and review results,
and attendance is taken by scanning a QR code, with no login required for
that part of the flow.

This README is a living map of the codebase for a new maintainer. It's being
filled in phase-by-phase as the app is documented and reorganized for
readability — it does not yet cover every file, but everything it does
describe should be accurate.

## Tech stack

Plain PHP (mysqli, no framework) + vanilla JS + hand-written CSS. One
Composer dependency, `firebase/php-jwt`, used only to verify Google/Apple
OAuth ID tokens (see `oauth_helpers.php`). Deployed on Hostinger shared
hosting.

## Two constraints every change must respect

**Top-level `.php` filenames are load-bearing URLs — never rename them.**
Several are referenced from outside this repo and renaming breaks things
silently:
- `oauth_apple_callback.php` and `oauth_google_callback.php` are registered
  as fixed redirect URIs in the Google/Apple OAuth app consoles. Rename
  either one and login breaks until those external configs are updated too.
- Every AJAX endpoint (`keepalive.php`, `check_notifications.php`,
  `save_attendance.php`, `save_scores.php`, …) is called by exact filename
  from `fetch()`/form actions scattered across the app.

Refactoring for readability should split a file's *internals* (functions,
variables, or new `require`d/`include`d helper files) — not its top-level
name or URL.

**Public attendance pages have no login by design — this is a client
requirement, not an oversight.** `attendance.php`, `save_attendance.php`,
`attendance_toggle.php`, `attendance_student.php`, the `export_attendance_*`
and `attendance_view_*` files must all stay usable by an anonymous visitor
scanning a QR code. `auth_check.php`'s idle-timeout logic is a harmless
no-op for a session that never logged in (`$_SESSION['last_activity']` is
never set for one), which is why these pages can safely include it without
gaining a login requirement. The only page in this family that legitimately
requires login is `manage_attendance.php` (PIC role) — the internal
management view, as opposed to the public scan-in flow.

## Request bootstrap & load order

Most authenticated pages start the same way:

```php
session_start();
require __DIR__ . '/auth_check.php';   // idle-timeout guard (see below)
include 'db.php';                       // pulls in security_bootstrap.php
```

- **`security_bootstrap.php`** is the entry point for error handling and
  secret loading. It's a thin orchestrator (idempotent — safe to load twice)
  that sets the timezone, then requires, in this exact order:
  1. `bootstrap_secrets.php` — loads `secrets.php` from outside the web
     root and points PHP's error log at the configured path.
  2. `bootstrap_error_handling.php` — Telegram alerting, the central error
     reporter, the branded error page, and the error/exception/shutdown
     handler registrations. This has to come second because its Telegram
     alerter reads the secrets the first file just loaded.

  See `SECURITY_ERROR_HANDLING.md` for the full deployment write-up
  (Hostinger setup steps, what visitors vs. you see on an error, the
  Telegram alert rate-limiting rules).

- **`db.php`** requires `security_bootstrap.php`, then defines `getDB()` —
  a singleton mysqli connection. If secrets never loaded (no `secrets.php`
  found), it fails closed with an HTTP 503 rather than falling back to any
  built-in credential.

- **`auth_check.php`** is included after `session_start()` on every
  authenticated page. It enforces a 15-minute idle timeout: AJAX callers get
  a `401 {"error":"session_expired"}` JSON response (see `layout.php`'s
  `pmFetch()` wrapper, which every same-origin `fetch()` call should use so
  that response is handled instead of silently treated as real data);
  normal navigation gets redirected to `login.php`.

- **`layout.php`** is included by every dashboard page to render the shared
  `<head>`, header, and sidebar (the page then closes
  `</main></body></html>` itself). It pulls in `layout_icons.php` (the
  static SVG icon lookup table behind `pm_icon()`) and `layout.js` (the
  static parts of what used to be one large inline `<script>` block:
  `pmFetch`, idle keep-alive, theme toggle, sidebar, dropdowns, and alert
  auto-dismiss). The PIC-only notification/toast script is still inline in
  `layout.php` itself — it embeds the CSRF token directly, which blocks
  moving it to a static file for now.

## Secrets

Real credentials (DB, the QR-signing HMAC key, Telegram bot token) live in
`secrets.php`, one directory **above** `public_html` — never web-reachable,
even if PHP itself stopped executing. `secrets.sample.php` (safe to keep in
the web root — no real values) documents every key and the exact deployment
steps. See `SECURITY_ERROR_HANDLING.md` for the full setup walkthrough and
`SETUP_GOOGLE_APPLE_LOGIN.md` for the OAuth-specific configuration.

## Map of the codebase

- **Shared/core** (used by nearly every page): `db.php`, `auth_check.php`,
  `security_bootstrap.php` (+ `bootstrap_secrets.php`,
  `bootstrap_error_handling.php`), `layout.php` (+ `layout_icons.php`,
  `layout.js`), `attendance_helpers.php` (+ `expired_qr_page.php`),
  `oauth_helpers.php`, `oauth_config.php`, `error_page.php`.
- **Public attendance flow** (no login — see constraint above):
  `attendance.php`, `save_attendance.php`, `attendance_toggle.php`,
  `attendance_student.php` (+ `attendance_student.css/.js`),
  `attendance_view_all.php`, `attendance_view_dashboard.php`,
  `attendance_view_session.php` (+ `attendance_view_session.css/.js`),
  `attendance_view_school.php` (+ `attendance_view_school.css/.js`),
  `export_attendance_*.php`. `manage_attendance.php` is the one exception —
  correctly PIC-login-gated (internal management view, not the public flow).

  **Flagged, not fixed** (surfaced during the Phase 4 split, worth a
  deliberate decision rather than a silent change):
  - `attendance_toggle.php` looks like an orphaned duplicate of
    `save_attendance.php` — it rolls its own `INSERT ... ON DUPLICATE KEY
    UPDATE` upsert instead of reusing the canonical endpoint, has no
    `auth_check.php` include at all, and a looser status whitelist (anything
    that isn't exactly `'Present'` silently becomes `'Absent'` instead of
    being rejected). Nothing in the front-end calls it — only
    `test_suite.php` does. Left alone pending a decision on whether it's
    still needed for something outside this repo.
  - `attendance_view_dashboard.php` interpolates `$active_siri_id` directly
    into a raw SQL string rather than a prepared statement, unlike every
    other file in this family. Low risk in practice (the value is cast
    `(int)` first), but inconsistent with the rest of the codebase's
    `bind_param` convention.
  - `attendance_view_all.php`, `export_attendance_excel.php`, and
    `export_attendance_pdf.php` each hand-duplicate the same ~50-line
    UNION/COALESCE filter query rather than sharing it — a real
    consolidation candidate for a future pass.
- **Judge scoring**: `judge.php` — the judge's scoring workflow (session/
  group selection, the marking table, submit/lock rules), split into:
  `judge_ajax.php` (the small-form-dropdown AJAX endpoints, required
  inline — same URL, `judge.php?ajax_levels=1&...`), `judge.css` (page
  styles), `judge_dashboard.js` (session-selection screen: cascade
  dropdowns, accordion summary, live clock), and `judge_marking.js`
  (marking-table screen: draft autosave, keypad UI, add-ujian modal,
  missing-marks validation — reads its few required PHP values off
  `window.pmJudgeMarkingData`, set by a small inline bootstrap snippet,
  rather than interpolating PHP directly into the file).
  `judge_view_marks.php`, `judge_settings.php`, `silibus.php`.
- **PIC management**: the `pic_*.php` family — competition structure
  (`pic_levels.php`, `pic_tests.php`, `pic_criteria.php`, `pic_sessions.php`,
  `pic_siri.php`, `pic_schools.php`, `pic_judges.php`, `pic_groups.php`),
  data (`pic_students.php`, `pic_directory.php`, `pic_master_list.php`,
  `pic_roster_check.php`), and results
  (`pic_manual_marks.php`, `pic_view_marks.php`, `pic_medal_settings.php`,
  `pic_cawangan_summary.php`, `manage_attendance.php`). Each page still
  follows its own long-standing shape (auth/role check → query/POST-handling
  block → inline HTML → inline `<script>` at the bottom) — see the
  cross-file duplication note below before splitting any one of them
  further. `upload_students.php` (+ `upload_students.css/.js`) is the
  student-roster import wizard (Excel/PDF → upload → select_sheets →
  review → process) — its ~900 lines of multi-stage import/matching logic
  are left untouched (data-import correctness is not something to risk in
  a readability pass); only the inline CSS/JS were extracted, same pattern
  as judge.php.
- **Admin**: `admin.php`, `admin_data.php`, `admin_logs.php`.
- **Login**: `login.php`, `oauth_apple_start.php`,
  `oauth_apple_callback.php`, `oauth_google_callback.php`.

### Cross-file duplication in the `pic_*.php` family

A survey found several patterns hand-duplicated across many `pic_*.php`
files rather than shared. One has been consolidated so far:

- **`pagination_helpers.php`** — `pic_view_marks.php`'s `page_url()` and
  `silibus.php`'s `silibus_page_url()` were byte-identical; both are now
  thin wrappers around one `pm_page_url()`.

**Identified but deliberately deferred** (verified in more depth than the
one-line summary below suggests — each turned out to have real per-file
differences that make a blanket consolidation riskier than it first looked,
so they're left alone until each can get its own careful pass):
- The searchable-dropdown JS trio (`ddToggle`/`ddFilter`/`ddSelect`, or
  renamed variants) is reimplemented in ~14 files. `ddToggle` bodies are
  identical; `ddFilter`/`ddSelect` and especially the single-field variants
  in `pic_schools.php`/`pic_roster_check.php` are not pure copies.
- The server-side dropdown-option renderers (`renderMedalDD`, `renderMMDD`,
  `renderVMDD`, `renderDirDD`, `renderMasterDD`, `renderSilibusDD` — 6
  files) look like two signature shapes, but `renderMMDD` (the
  disabled-capable shape) skips rendering the "clear selection" empty-option
  row entirely, unlike the others — a real functional difference, not just
  an optional parameter.
- The `updateXPagination()`/`xGoToPage()` JS pair is duplicated across 9
  files. Most are plain top-level functions with parallel logic (differing
  only in element-id prefixes and a label noun), but at least two
  (`pic_master_list.php`, `pic_medal_settings.php`) assign theirs as
  `window.xGoToPage = function(){}` inside a different scoping structure —
  consolidating safely needs each file's surrounding structure checked
  individually, not assumed identical.
- The self-healing `SHOW COLUMNS` / `ALTER TABLE ADD COLUMN` migration
  boilerplate appears in 6+ places with a different table/column/session-flag
  each time; `pic_judges.php`'s version has no session-flag gate at all and
  wraps the query in its own try/catch/fallback-message, so it doesn't fit
  the same shape as the other five.
- The CSRF check-and-reject block (`hash_equals(...)` + reject) is
  near-identical in the check itself, but what happens on failure varies
  per file (JSON+exit, set-`$error`-and-continue-rendering,
  `http_response_code`+JSON, reading the token from a JSON body instead of
  `$_POST`) — a shared helper needs to return a bool rather than perform the
  reject itself.

*(This map will grow as later refactor phases document the judge, PIC, and
attendance-flow files in more depth.)*

# ProMarkah

ProMarkah is a scoring and attendance management system for silat (martial
arts) competitions — judges score students on live rubric criteria, PICs
(persons-in-charge) configure the competition structure and review results,
and attendance is taken by scanning a QR code, with no login required for
that part of the flow.

This README is a living map of the codebase for a new maintainer. It's being
filled in phase-by-phase as the app is documented and reorganized for
readability — it does not yet cover every file, but everything it does
describe should be accurate. It deliberately stops short of documenting
exact secret values, exact security-check internals, or copy-pasteable
exploit detail — see [Secrets & security posture](#secrets--security-posture)
for what's kept out and why.

## Tech stack

Plain PHP (mysqli, no framework) + vanilla JS + hand-written CSS. One
Composer dependency, `firebase/php-jwt` (`^7.1`), used only to verify
Google/Apple OAuth ID tokens (see `oauth_helpers.php`) — no autoloading
config beyond Composer's default, no dev dependencies, no test framework.
Deployed on Hostinger shared hosting (Apache + `mod_rewrite`/`.htaccess`,
mysqli + curl + zip PHP extensions). Locally this just needs a standard
Apache/PHP/MySQL stack (Laragon, XAMPP, etc.) — there's no Docker/Vagrant
config, no `.env` file, and no build step; static assets are plain `.css`/
`.js` files served as-is.

## Domain glossary

The competition structure nests like this:

**Siri → Sidang → Peringkat → Ujian → Kriteria**

- **Siri** — a competition "series"/edition (e.g. one year's tournament).
  A PIC picks an "Siri Aktif" that scopes most PIC screens to that edition
  (a "Semua Siri" / all-series option is also available).
- **Sidang** (session) — one sitting/round of the competition within a
  siri. A siri has many sidang; a sidang has many peringkat.
- **Peringkat** (level/grade) — a belt/grade level scoped to one sidang. A
  sidang has many peringkat; a peringkat has many ujian, and also owns the
  kumpulan built under it.
- **Ujian** (test) — a specific test/exercise under a peringkat. A
  peringkat has many ujian; a ujian has many kriteria.
- **Kriteria** (criteria) — a single scoring sub-component of a ujian, with
  its own maximum mark. Judges/PICs enter one mark per student per
  kriteria.

Orthogonal to that hierarchy:

- **Cawangan** (school/branch) — the training branch a student belongs to.
  Cawangan are opted into a siri and, separately, into individual sidang.
- **Kumpulan** (group) — a set of students competing together within one
  peringkat, assigned to exactly one judge. Built by dragging students
  (who are not yet in any group) into a group; ordering within the group
  is preserved for the marking-table UI.
- **Juri** (judge) — scores kumpulan. A judge account is a separate record
  type from PIC/admin/recorder accounts (see [User roles](#user-roles) and
  [Login](#login--auth-model)).
- **PIC** ("person in charge") — the organizer role: configures siri,
  sidang, peringkat, ujian, kriteria, cawangan, kumpulan, and judges, and
  reviews results/exports. Distinct from `admin`, which is a narrower,
  separate account type for system-level pages (`admin.php`,
  `admin_data.php`, `admin_logs.php`).

## End-to-end system flow

This walks through a full competition lifecycle in the order things
actually happen, tying the domain glossary above to the pages that
implement each step. It's the "how does this app get used" view; see
[Map of the codebase](#map-of-the-codebase) for the "which file does
what" view of the same territory.

### 1. PIC sets up the competition structure

All of this is `pic`-role, reachable from `pic.php` (the PIC dashboard)
and its sidebar:

1. **Create a Siri** (`pic_siri.php`) — a competition edition. The PIC
   marks one Siri "Aktif," which scopes most PIC screens by default (a
   "Semua Siri" view is also available). Cawangan (schools) are opted
   into a Siri here.
2. **Create Sidang** under that Siri (`pic_sessions.php`) — one sitting/
   round. Cawangan are separately opted into individual Sidang (a school
   can be in the Siri but skip a given Sidang).
3. **Create Peringkat** under a Sidang (`pic_levels.php`) — belt/grade
   levels contestants compete at.
4. **Create Ujian** under a Peringkat (`pic_tests.php`), then **Kriteria**
   under each Ujian (`pic_criteria.php`) — the rubric a judge actually
   scores against, each Kriteria carrying its own max mark.
5. **Manage Cawangan** (`pic_schools.php`) and their **student rosters**
   — one at a time (`pic_students.php`) or in bulk via the import wizard
   (`upload_students.php`: Excel/PDF → upload → select sheet → review →
   process). Students are scoped to a Cawangan + Peringkat.
6. **Build Kumpulan** (`pic_groups.php`) — drag ungrouped students from a
   Peringkat into groups; each group is assigned to exactly one judge and
   member order is preserved for the marking-table UI.
7. **Register judges** (`pic_judges.php`) — creates the judge-code+PIN (or
   OAuth-email-matched) accounts judges will log in with; see
   [Login & auth model](#login--auth-model).
8. Optionally set **medal quotas** (`pic_medal_settings.php`, per level or
   per session) and review the **live syllabus preview**
   (`test_preview.php`, `silibus_baru.php`) before the event runs.

### 2. Attendance (public, no login — QR scan)

Independent of the scoring flow, and open to anonymous visitors by
design (see [the constraint below](#two-constraints-every-change-must-respect)):

1. The PIC/recorder opens `manage_attendance.php` (the only login-gated
   page in this family) and generates a signed, time-limited QR link per
   Cawangan + Sidang via `makeQrUrl()`.
2. A student scans the QR and lands on `attendance.php` /
   `attendance_student.php`, which validates the signature and expiry
   (`verifyQrToken()`) before showing the check-in form — an expired or
   tampered link renders `expired_qr_page.php` instead.
3. Marking present/absent posts to `save_attendance.php`.
4. Recorders/PICs review results live via `attendance_view_session.php`,
   `attendance_view_school.php`, `attendance_view_dashboard.php`, and
   `attendance_view_all.php`, and can export via
   `export_attendance_excel.php` / `export_attendance_pdf.php`.

This flow never touches the scoring tables — a student can be marked
present without any Kumpulan/judge assignment existing yet, and vice
versa.

### 3. Judges score

1. A judge logs in (judge-code+PIN or Google/Apple OAuth — see
   [Login & auth model](#login--auth-model)) and lands on `judge.php`'s
   session-selection screen (`judge_dashboard.js`): cascading Sidang →
   Peringkat → Kumpulan dropdowns populated via `judge_ajax.php`, showing
   only the groups assigned to that judge.
2. Picking a group opens the marking table (`judge_marking.js`): one row
   per student, one column per Kriteria for the group's Ujian, entering a
   mark up to each Kriteria's max.
3. Marks autosave as drafts via `save_draft_score.php` (`status = draft`
   in `scores`) — excluded from totals/leaderboard until finalized. A
   missing-marks check runs before submit is allowed.
4. Final **Submit** posts to `save_scores.php`, which flips the group's
   scores to final status and **locks** the group (no further edits from
   the judge side).
5. Judges can review their own submitted marks (`judge_view_marks.php`),
   check the reference rubric (`silibus.php` / `silibus_baru.php`), and,
   if a fresh round is needed, a judge-gated `reset_system.php` soft-
   archives existing scores and clears judge→group assignments (see
   [Secrets & security posture](#secrets--security-posture) — this does
   not delete data, but deserves care).

### 4. PIC reviews, corrects, and closes out results

1. **Manual review/correction** (`pic_manual_marks.php` →
   `pic_save_scores.php`) — a PIC can enter or correct marks directly, or
   mark a student "Abai" (skip), for cases a judge couldn't score in the
   room.
2. **Results views**: `pic_view_marks.php` (per-session/level marks),
   `pic_master_list.php` / `pic_roster_check.php` (roster/consistency
   views), `leaderboard.php` (ranked results — shared by pic and judge
   roles, medal-quota-aware) with `export_leaderboard.php` for the same
   filtered view as a download.
3. **Cawangan-level reporting**: `pic_cawangan_summary.php`, backed by
   the shared, non-web-facing `cawangan_report_data.php` helper so its
   three export variants (`export_cawangan_summary.php` — Excel via
   `ZipArchive`, `export_cawangan_summary_pdf.php` — print-friendly HTML,
   `export_cawangan_summary_word.php` — HTML served as `.doc`) never
   drift out of sync with the on-screen report.
4. **In-app notifications**: PIC-facing toasts/alerts
   (`check_notifications.php` / `mark_notifications_read.php`, rendered
   by `layout_notifications.css/.js`) surface events like new submissions
   needing attention, polled client-side rather than pushed.

### 5. Admin (separate, narrower role)

`admin.php` / `admin_data.php` / `admin_logs.php` are system-level pages
(distinct from PIC — see [User roles](#user-roles)) for cross-cutting
account/log management rather than competition content.

### Everything is scoped by the same session/keep-alive layer

Every step above, for every logged-in role, rides on the same
[request bootstrap](#request-bootstrap--load-order): `auth_check.php`'s
15-minute idle timeout (kept alive by `keepalive.php` while the tab is
active), `pmFetch()` handling a `401 session_expired` on any AJAX call
instead of silently misinterpreting it as data, and CSRF tokens on
state-changing POSTs (pattern varies per file — see the
[cross-file duplication note](#cross-file-duplication-in-the-pic_php-family)).
None of that is repeated per-step above to avoid redundancy.

## User roles

Four role strings live in `$_SESSION['role']`: `pic`, `judge`, `recorder`,
`admin`. Access is checked per-page, near the top of each file (a role
whitelist, not a central router) — when adding a new page, copy the
pattern from a sibling file of the same role rather than inventing a new
check style.

| Role | Typical area |
|---|---|
| `pic` | Every `pic_*.php` configuration/report page, `manage_attendance.php`, `leaderboard.php` (shared with judge), `db_health_check.php` / `test_suite.php` / `page_smoke_test.php` (diagnostics, shared with admin) |
| `judge` | `judge.php` (the scoring workflow) and its AJAX/JS support files, `judge_view_marks.php`, `judge_settings.php`, `silibus.php` / `silibus_baru.php`, `reset_system.php`, `save_scores.php` / `save_draft_score.php`, `leaderboard.php` (shared with pic) |
| `recorder` | The attendance-management area (`attendance.php`'s internal, logged-in side) |
| `admin` | `admin.php`, `admin_data.php`, `admin_logs.php`, plus the diagnostic pages shared with pic |

Public, no-login pages are the QR attendance flow — see the constraint
below.

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
management view, as opposed to the public scan-in flow. Each QR code is
signed (not just a plain link) so a visitor can't guess or tamper with
another session/school's attendance URL — see
[Secrets & security posture](#secrets--security-posture).

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
  normal navigation gets redirected to `login.php`. Idle time is tracked
  via `keepalive.php`, pinged by client-side JS whenever the user is
  actively moving the mouse/keyboard.

- **`layout.php`** is included by every dashboard page to render the shared
  `<head>`, header, and sidebar (the page then closes
  `</main></body></html>` itself). It pulls in `layout_icons.php` (the
  static SVG icon lookup table behind `pm_icon()`) and `layout.js` (the
  static parts of what used to be one large inline `<script>` block:
  `pmFetch`, idle keep-alive, theme toggle, sidebar, dropdowns, and alert
  auto-dismiss). The PIC-only notification/toast system (rendered only
  when `$pm_role === "pic"`) is now `layout_notifications.css/.js` (Phase
  16) — its one PHP-interpolated value, the CSRF token, is exposed as a
  plain `const PM_LAYOUT_CSRF` in a bootstrap `<script>` right before the
  `<script src>` tag, same mechanism as `pic_groups.js`'s
  `PM_GROUPS_CSRF`.

## Login & auth model

All login traffic goes through one file, `login.php`, which forces HTTPS,
hardens the session cookie (`HttpOnly`, `Strict` SameSite, `Secure` on
HTTPS) before `session_start()`, and applies the same anti-abuse layer to
every role before any credential check runs:

- A CSRF token (`$_SESSION['csrf_token']`) is required on the POST.
- A hidden honeypot field and a minimum-elapsed-time check (`form_load_time`)
  reject same-request bot submissions.
- IP-based rate limiting is enforced from a self-managed `login_throttle`
  table (persisted in the DB, not the session, so it survives an
  attacker dropping cookies between attempts) — 5 failed attempts from
  one IP locks that IP out for 5 minutes, regardless of which role or
  username was being tried.
- A successful login always calls `session_regenerate_id(true)` before
  writing `$_SESSION`, and always inserts one row into the `audit_log`
  table (user id, role, IP, timestamp) — the same audit path for every
  role, whether the credential was a password, a PIN, or an OAuth token
  (see `oauth_helpers.php` for the OAuth insert).

From there the flow forks by role, but only in how the identity is
proven and where it redirects — the surrounding session/CSRF/throttle
machinery above is identical for all four:

| Role | How they authenticate | Table checked | Redirect on success |
|---|---|---|---|
| `pic` | Username + password (`password_verify` against a bcrypt/argon hash) | `users` | `pic.php` |
| `recorder` | Username + password, same form/step as pic | `users` | `attendance.php` (the internal, logged-in attendance-management entry point — see [Attendance flow](#2-attendance-public-no-login--qr-scan)) |
| `admin` | Username + password, same form/step as pic | `users` | `admin.php` |
| `judge` | Two-step: username+password (step 1) sets `$_SESSION['step']=2` and reloads `login.php`, which then shows a judge-code dropdown + PIN form (step 2), checked against `password_verify($pin, ...)` — **or** Google/Apple Sign-In, matched to a judge record by the email a PIC has recorded on that judge's account | `judges` (password/PIN path); `judges.email` (OAuth path) | `judge.php` |

Notes that apply across roles:

- pic/recorder/admin all authenticate the same way and share the
  `users` table — the only difference between them post-login is the
  `role` string stored in session and, therefore, which per-page role
  checks let them through (see [User roles](#user-roles)). There is no
  separate registration flow in this app for any of the four roles —
  accounts are provisioned directly in the DB / by a PIC (for judges,
  via `pic_judges.php`).
- The judge PIN-check step deliberately returns the **same** error
  message ("Kelayakan tidak sah") whether the judge name or the PIN was
  wrong, so a wrong PIN attempt can't be used to enumerate which judge
  names exist.
- OAuth (Google/Apple) is judge-only — pic/recorder/admin have no OAuth
  path and are unaffected by that config. See
  `SETUP_GOOGLE_APPLE_LOGIN.md` for the OAuth app setup steps and
  `oauth_google_callback.php` / `oauth_apple_callback.php` /
  `oauth_apple_start.php` for the callback handlers. A judge who signs
  in via OAuth whose email isn't linked to any judge record gets a
  dedicated "no_judge_linked" error rather than being silently denied or
  auto-created.
- `logout.php` is the single logout path for all four roles — it
  destroys the session rather than performing any role-specific cleanup.
- Once logged in, every role rides the same
  [idle-timeout/keep-alive layer](#request-bootstrap--load-order)
  (`auth_check.php`, `keepalive.php`, `pmFetch()`) — nothing about idle
  handling differs by role either.

## Secrets & security posture

Real credentials (DB, the QR-signing key, Telegram bot token, a couple of
narrow diagnostic-endpoint tokens) live in `secrets.php`, one directory
**above** `public_html` — never web-reachable, even if PHP itself stopped
executing. `secrets.sample.php` (safe to keep in the web root — no real
values) documents every key name and the exact deployment steps. See
`SECURITY_ERROR_HANDLING.md` for the full setup walkthrough and
`SETUP_GOOGLE_APPLE_LOGIN.md` for the OAuth-specific configuration.

This README intentionally does **not** restate secret values, exact
signing/validation algorithms, or other detail that would only be useful
for attacking rather than maintaining the live site. A few things a new
maintainer should still know, at the level that's safe to write down:

- Public QR attendance links are cryptographically signed, not just
  obscure URLs — tampering with the URL invalidates the signature rather
  than granting access to a different session/school.
- A couple of diagnostic/ops endpoints (a cron heartbeat check, and — see
  below — a couple of pages meant to be deleted after initial setup) are
  gated by a static shared-secret query-string token rather than a login,
  because they're designed to be hit by a cron job or a developer without
  a session. Treat any file whose own comments say "delete this when
  done" as exactly that.
- **Housekeeping flag for the incoming maintainer**: `test_suite.php` and
  (to a lesser extent) `telegram_test.php` are diagnostic tools that were
  left in place with real, hardcoded access tokens after initial setup —
  both explicitly say "delete when done" in their own header comments.
  Confirm they're either removed from production or that their tokens
  have been rotated before treating the site as fully locked down; this
  README does not reproduce those tokens.
- `reset_system.php` is judge-role-gated and requires an explicit
  confirmation query parameter before it does anything. It does **not**
  delete data — it soft-archives existing scoring records (flips an
  `archived` column rather than running `DELETE`) and clears judge→group
  assignments, effectively "start a fresh judging round." Still,
  something this consequential deserves a confirmation *screen*, not just
  a confirmation *query parameter* — worth hardening if you're touching
  this file.
- A handful of small AJAX dropdown-population endpoints
  (`load_levels_group.php`, `load_schools_for_session.php`,
  `load_students_group.php`) don't currently check `$_SESSION['role']` at
  all before returning data — they only leak dropdown option lists
  (names/IDs), not scores or credentials, but they're worth bringing in
  line with the rest of the app's per-file role checks.

## Database schema overview

There's no single canonical `schema.sql` — most tables are created and
altered lazily, inline, in the PHP pages that first need them
(`CREATE TABLE IF NOT EXISTS`, or a `SHOW COLUMNS` / `ALTER TABLE ADD
COLUMN` self-healing check guarded by a per-session flag so it only runs
once). Treat the PHP files below as the source of truth for the current
schema, not a migrations folder (only one exists:
`migrations/2026_08_add_scores_status.sql`, which added the
draft-vs-final `status` column on `scores`).

High-level table map (names simplified — check the relevant PHP file for
exact columns before writing a query against one):

| Table | Roughly stores | Created/altered in |
|---|---|---|
| `siri` / `siri_schools` | Competition series, and which cawangan opted into each | `pic_siri.php` |
| `sessions` / `session_schools` | Sidang, and which cawangan opted into each | `pic_sessions.php` |
| `levels` | Peringkat, scoped to a sidang | `pic_levels.php` |
| `tests` | Ujian, scoped to a peringkat | `pic_tests.php` |
| `criteria` | Kriteria (with per-criteria max mark), scoped to a ujian | `pic_criteria.php` |
| `schools` | Cawangan | `pic_schools.php` |
| `students` | Student roster, scoped to a cawangan + peringkat | `pic_students.php`, `upload_students.php` |
| `groups` / `group_students` | Kumpulan, its assigned judge, and its member students (with display order) | `pic_groups.php` |
| `judges` | Judge accounts (name, PIN hash, judge code, optional email/photo for OAuth) | `pic_judges.php` |
| `users` | pic/recorder/admin login accounts | — |
| `scores` | One row per student × criteria mark, with draft/final and scored/skipped status | `save_scores.php`, `save_draft_score.php`, `pic_save_scores.php` |
| `medal_quotas` / `medal_quotas_session` | Gold/silver/bronze award caps, per level or per session | `pic.php`, `pic_medal_settings.php` |
| Attendance tables | QR check-in state, keyed off student/session membership | `attendance_helpers.php` and the `attendance_*.php` family |

A prior cleanup deliberately removed a couple of denormalized columns
(e.g. a redundant session reference on `groups`) in favor of deriving
that value via joins through `levels`/`criteria` — `db_health_check.php`
documents the reasoning if you're ever tempted to re-add a shortcut
column like that.

## Testing & diagnostics

There's no automated test suite (no PHPUnit/Pest, no CI config) — QA is
manual, via three purpose-built diagnostic pages that each cover a
different layer:

- **`db_health_check.php`** — re-runs the actual query *shapes* the app
  depends on (the joins/inserts/updates each page performs) to catch
  broken joins or orphaned rows after a schema change. Has a write-path
  section that performs real `INSERT`/`UPDATE`/`DELETE` but always inside
  a transaction that gets rolled back. Usable from a browser (pic/admin
  gated) or the CLI (no gate — safe to wire into a cron/CI job later).
- **`page_smoke_test.php`** — has your *current logged-in browser session*
  fetch every page relevant to your role and checks for HTTP 200 plus a
  well-formed closing `</main></body></html>`, which catches a PHP fatal
  error mid-render (but not silent logic bugs — those only surface in the
  error log). Has an opt-in, admin-only deeper check that exercises the
  real score-saving/locking/leaderboard-filtering logic against the DB,
  again inside a rolled-back transaction.
- **`test_suite.php`** — a self-contained HTTP integration tester: it
  makes real requests against the site's own live URLs, grouped by role,
  and checks status codes/content. See the housekeeping flag above — this
  one carries a hardcoded access token and should be removed or re-gated
  before considering the site production-hardened.

## Map of the codebase

- **Shared/core** (used by nearly every page): `db.php`, `auth_check.php`,
  `security_bootstrap.php` (+ `bootstrap_secrets.php`,
  `bootstrap_error_handling.php`), `layout.php` (+ `layout_icons.php`,
  `layout.js`, `layout_notifications.css/.js`), `attendance_helpers.php` (+
  `expired_qr_page.php`), `oauth_helpers.php`, `oauth_config.php`,
  `error_page.php`, `pagination_helpers.php`, `keepalive.php`,
  `check_notifications.php` / `mark_notifications_read.php`, `lang.php`
  (a bilingual en/ms string dictionary — appears only partially wired up;
  most UI text is hardcoded Malay directly in the page files rather than
  routed through it), `index.php` (a one-line wrapper that requires
  `login.php` directly, avoiding an extra redirect for "/").
- **Public attendance flow** (no login — see constraint above):
  `attendance.php`, `save_attendance.php`, `attendance_toggle.php`,
  `attendance_student.php` (+ `attendance_student.css/.js`),
  `attendance_view_all.php`, `attendance_view_dashboard.php`,
  `attendance_view_session.php` (+ `attendance_view_session.css/.js`),
  `attendance_view_school.php` (+ `attendance_view_school.css/.js`),
  `export_attendance_excel.php`, `export_attendance_pdf.php`.
  `manage_attendance.php` is the one exception —
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
  - `export_attendance_excel.php` did not have a visible auth/role check
    at last read, unlike its PDF sibling — worth double-checking and
    aligning before relying on it for anything non-public.
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
  `judge_view_marks.php` (results, judge's own view), `judge_settings.php`
  (profile settings, e.g. photo upload), `silibus.php` (filterable/
  historical syllabus reference), `silibus_baru.php` (always-current
  syllabus reference, built live from the current levels/tests/criteria),
  `save_scores.php` (final submit — locks the group), `save_draft_score.php`
  (autosave while marking, excluded from totals/leaderboard until final),
  `reset_system.php` (judge-gated "start a fresh judging round" — see
  [Secrets & security posture](#secrets--security-posture)).
- **PIC management**: the `pic_*.php` family — competition structure
  (`pic_levels.php`, `pic_tests.php` (+ `pic_tests.css/.js`, same
  plain-file-move pattern and the same `loadSidangOptions()` ARIA gap as
  `pic_students.php` — its PHP-interpolated toast-trigger `<script>`
  stayed inline too), `pic_criteria.php` (+
  `pic_criteria.css/.js` — same plain-file-move pattern as
  `pic_directory.php`; the one PHP-interpolated bit, a `spawnPmToast(...
  $_GET['msg'])` call, stayed inline rather than move into the static
  file), `pic_sessions.php`,
  `pic_siri.php`, `pic_schools.php`, `pic_judges.php` (+
  `pic_judges.css/.js` — same plain-file-move pattern as
  `pic_medal_settings.php` below — no PHP interpolation in either block),
  `pic_groups.php` (+
  `pic_groups.css/.js` — its one PHP-interpolated value, the CSRF token,
  is exposed as a plain `const PM_GROUPS_CSRF` in a bootstrap `<script>`
  right before the `<script src>` tag, relying on top-level `let`/`const`
  sharing one lexical scope across sequential classic `<script>` tags —
  same mechanism already used for `criteriaByTest` between
  judge_dashboard.js/judge_marking.js)), `test_preview.php` (PIC-only
  cascading-dropdown preview of a test's level→test structure before
  assigning it),
  data (`pic_students.php` (+ `pic_students.css/.js`, same plain-file-move
  pattern — no PHP interpolation in either block; note left in that commit
  that `loadSidangOptions()`'s `document.createElement()`-built dd-opt
  rows never got `role="option"`/`tabindex`, unlike the PHP-echoed ones
  the accessibility pass covered), `pic_directory.php` (+ `pic_directory.css/.js`,
  same plain-file-move pattern as `pic_medal_settings.php` below — no PHP
  interpolation in either block), `pic_master_list.php`,
  `pic_roster_check.php`, `load_levels_group.php`, `load_schools_for_session.php`,
  `load_students_group.php` (small dropdown/roster AJAX endpoints backing
  the pages above — see the auth-check gap flagged above)), and results
  (`pic_manual_marks.php` (+ `pic_manual_marks.css/.js` — same
  plain-file-move pattern as `pic_medal_settings.php` below — no PHP
  interpolation in either block), `pic_view_marks.php`, `pic_save_scores.php`
  (manual score entry/correction handler, also supports "Abai"/skip),
  `pic_medal_settings.php` (+
  `pic_medal_settings.css/.js` — neither block had any PHP interpolation,
  so this was a plain file move, same pattern as `upload_students.php`),
  `pic_cawangan_summary.php` (+ `cawangan_report_data.php`, a shared,
  non-web-accessible helper library so this page and its three export
  variants below never drift out of sync), `manage_attendance.php`), and
  `leaderboard.php` (+ `leaderboard.css/.js` — same plain-file-move
  pattern; the one PHP-interpolated bit, `leaderboardData`, is set by
  a small inline bootstrap `<script>` before the `<script src>` tag,
  same mechanism as `pic_groups.php`'s CSRF const), `export_leaderboard.php`
  (mirrors `leaderboard.php`'s own filters; shared by pic and judge),
  `export_cawangan_summary.php` / `export_cawangan_summary_pdf.php` /
  `export_cawangan_summary_word.php` (three export formats of the same
  report — real `.xlsx` via `ZipArchive`, a print-friendly HTML page for
  "PDF", and an HTML document served with a `.doc` extension for Word).
  Each page still
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
  `oauth_apple_callback.php`, `oauth_google_callback.php`, `logout.php`.
- **Ops/diagnostics** (see [Testing & diagnostics](#testing--diagnostics)
  and the housekeeping flag above before relying on any of these in
  production): `db_health_check.php`, `page_smoke_test.php`,
  `test_suite.php`, `heartbeat.php` (daily cron "system OK" ping to
  Telegram — DB connectivity + recent error-log count), `telegram_test.php`
  (one-off alert-credential check — delete after use, per its own
  comment), `fetch_options.php` (generic session-gated dropdown-option
  AJAX endpoint, distinct from the ungated `load_*.php` trio above).

### Cross-file duplication in the `pic_*.php` family

A survey found several patterns hand-duplicated across many `pic_*.php`
files rather than shared. Two have been consolidated so far:

- **`pagination_helpers.php`** — `pic_view_marks.php`'s `page_url()` and
  `silibus.php`'s `silibus_page_url()` were byte-identical; both are now
  thin wrappers around one `pm_page_url()`. The same file also gained
  `pm_render_pagination()` for the handful of server-rendered pagination
  bars; every client-rendered one now calls the `pmRenderPagination()` JS
  helper in `layout.js` instead. This fully resolved the
  `updateXPagination()`/`xGoToPage()` duplication noted below — every page
  that had it, including the two with the differently-scoped
  `window.xGoToPage` assignment, now shares one renderer.

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

## Refactor-phase convention

The file-splitting work described throughout this README follows a
numbered "Phase N" convention, visible in the git log — each phase takes
one large `.php` file's inline `<style>`/`<script>` blocks and extracts
the parts with **zero PHP interpolation** into standalone `.css`/`.js`
files, linked back in via `<link>`/`<script src>` with a
`filemtime()`-based cache-busting query string
(`?v=<?= @filemtime(__DIR__.'/file.css') ?: time() ?>`). Where a block
*does* need a PHP value (most often a CSRF token, or one page's bootstrap
data object), the convention is a small inline `<script>` that sets a
plain top-level `const`/`let` global right before the external
`<script src>` tag — classic `<script>` tags share one lexical scope, so
the external file can reference that global directly. This is a pure,
behavior-preserving mechanical refactor; it never touches PHP business
logic. See the git log for the full list of completed phases and which
file each one covers.

*(This map will grow as later refactor phases document the remaining
files in more depth.)*

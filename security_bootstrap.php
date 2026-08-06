<?php
/**
 * security_bootstrap.php — central error handling + secret loading + alerts.
 *
 * WHAT IT DOES
 *   • Loads secrets (DB creds, QR key, Telegram token) from OUTSIDE the web
 *     root, so no real secret ever sits in a downloadable file.
 *   • Forces display_errors OFF and log_errors ON — visitors never see a
 *     stack trace / file path; you still get the full detail in the log.
 *   • Catches every uncaught warning, exception and fatal error, logs it,
 *     pushes a short alert to your Telegram bot (rate-limited), and shows the
 *     user a clean branded page with only a random incident id — no leak.
 *
 * HOW TO LOAD IT (pick one)
 *   A) Global, zero per-page edits (recommended). Create a `.user.ini` in
 *      public_html containing:
 *          auto_prepend_file = /home/uXXXXXXXX/public_html/security_bootstrap.php
 *      (use the real absolute path; see user.ini.sample). PHP then runs this
 *      file before every request automatically — nothing can bypass it.
 *   B) Explicit: `require __DIR__ . '/security_bootstrap.php';` as the very
 *      first line of a page. db.php already does this, so any page that hits
 *      the database is covered even without (A).
 *
 * It is idempotent — loading it twice does nothing the second time.
 *
 * This file is just a thin orchestrator: it fixes the timezone, then hands
 * off in a fixed order to two included files that do the real work.
 * bootstrap_secrets.php MUST run first — bootstrap_error_handling.php's
 * promarkah_telegram_alert() reads $GLOBALS['__PROMARKAH_SECRETS'], which
 * bootstrap_secrets.php populates. Neither file is meant to be required on
 * its own; always come in through this one so the order is guaranteed.
 */

if (defined('PROMARKAH_BOOTSTRAP')) { return; }
define('PROMARKAH_BOOTSTRAP', true);

// Hostinger's PHP default timezone is UTC, so every date()/time() call (this
// file's own incident timestamps included) came out 8 hours behind local
// Malaysia time unless the individual page happened to call
// date_default_timezone_set() itself (only a handful did — attendance.php,
// judge.php, pic_siri.php, pic_judges.php, save_attendance.php). Setting it
// once here, in the file that's auto-prepended to every request, fixes it
// app-wide instead of relying on each page to remember.
date_default_timezone_set('Asia/Kuala_Lumpur');

require __DIR__ . '/bootstrap_secrets.php';
require __DIR__ . '/bootstrap_error_handling.php';

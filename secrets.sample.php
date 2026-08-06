<?php
/**
 * secrets.sample.php  —  TEMPLATE ONLY.
 *
 * This file is safe to keep in the web root: it contains NO real values,
 * only empty placeholders. The REAL file must live OUTSIDE the web root.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DEPLOYMENT ON HOSTINGER (do this once):
 *
 *   1. In the File Manager, go UP one level from `public_html` — the folder
 *      that CONTAINS public_html (the same view where you see `public_html`,
 *      `.trash`, `promarkah (1).sql`). That folder is on disk but is NOT
 *      reachable by any URL, so nothing in it can ever be downloaded by a
 *      visitor — even if PHP itself ever stopped executing.
 *
 *   2. Create a new file there named exactly:  secrets.php
 *      Paste the array below into it and fill in the REAL values.
 *
 *   3. Set its permissions to 600 (owner read/write only) if the host allows.
 *
 *   4. NEVER put the real secrets.php (with values filled in) inside
 *      public_html. Only this empty *.sample.php belongs in the web root.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * security_bootstrap.php loads this file and hands the values to db.php,
 * attendance_helpers.php, and the Telegram alerter. If the file is missing,
 * the app still runs (Telegram alerts simply stay off and db.php falls back
 * to its built-in values) — so a bad deploy never takes the site down.
 */

return [
    // ── Database ──────────────────────────────────────────────────────────
    'DB_HOST'    => 'localhost',
    'DB_USER'    => '',           // e.g. u985209750_promarkah
    'DB_PASS'    => '',           // the real DB password
    'DB_NAME'    => '',           // e.g. u985209750_promarkah
    'DB_CHARSET' => 'utf8mb4',

    // ── QR attendance HMAC signing key ────────────────────────────────────
    // Any long random string (40+ chars). Signs the attendance QR tokens.
    // Changing it invalidates every QR code already printed/handed out.
    // Generate one, e.g.:  php -r "echo bin2hex(random_bytes(32));"
    'QR_SECRET_KEY' => '',

    // ── Telegram error alerts ─────────────────────────────────────────────
    // 1. In Telegram, message @BotFather → /newbot → copy the token it gives.
    // 2. Message your new bot once (say "hi") so it is allowed to message you.
    // 3. Open:  https://api.telegram.org/bot<TOKEN>/getUpdates
    //    and copy the numeric "chat":{"id": ... } value.
    // Leave TELEGRAM_BOT_TOKEN empty to turn alerts OFF entirely.
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_CHAT_ID'   => '',

    // ── Heartbeat endpoint token ──────────────────────────────────────────
    // Only needed if you schedule heartbeat.php via a URL (wget/curl) instead
    // of CLI. Any long random string. Leave empty if you use the CLI cron form.
    // Generate one:  php -r "echo bin2hex(random_bytes(24));"
    'HEARTBEAT_TOKEN' => '',

    // ── PHP error-log location ────────────────────────────────────────────
    // Absolute path to a file OUTSIDE public_html where full error detail is
    // written. Leave empty to use the system temp dir (also non-web-readable).
    // e.g. '/home/u985209750/promarkah-error.log'
    'ERROR_LOG_PATH' => '',
];

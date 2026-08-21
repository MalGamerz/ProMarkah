# Error handling, secrets & Telegram alerts — how it works and how to deploy

This document explains the error-handling / secret-protection system added to
ProMarkah and the exact steps to activate it on Hostinger.

> **Goal:** visitors never see a raw error (no stack traces, no file paths, no
> credentials), you get an instant Telegram alert with an incident id when
> anything breaks, and no real secret ever sits in a file that can be
> downloaded over the web.

---

## Files involved

| File | Where it lives | Purpose |
|------|----------------|---------|
| `secrets.php` | **OUTSIDE** `public_html` (e.g. `/home/u985209750/secrets.php`) | The real DB password, QR key, Telegram token. Never web-reachable. |
| `secrets.sample.php` | `public_html` (safe – empty placeholders) | Template to copy from. |
| `security_bootstrap.php` | `public_html` | Loads secrets, forces errors off-screen, logs + alerts, shows the friendly page. |
| `error_page.php` | `public_html` | The branded "Ralat Sistem" page the visitor sees. |
| `user.ini.sample` | `public_html` | Template for `.user.ini` (optional global auto-load). |
| `.htaccess` | `public_html` | Blocks direct download of secrets/logs/dumps/backups + include-only PHP. |
| `heartbeat.php` | `public_html` | Daily "system OK" ping + health check to Telegram (run by cron). |
| `telegram_test.php` | `public_html` (**delete after setup**) | One-off diagnostic to confirm alerts reach your chat. |

`db.php` and `attendance_helpers.php` read their secrets from the loaded
`secrets.php` and keep **no** credential of their own (see step 5).

---

## What the visitor vs. you see when an error happens

- **Visitor:** a clean dark "Maaf, sistem menghadapi masalah" page with a
  random reference like `Rujukan: 3F9A2C71`. Nothing else.
- **You (server log):** a full detailed block under that same
  `incident=3F9A2C71` — error + file:line, the request method & URL, referer,
  client IP, user agent, the logged-in user (id/role/name), and the **GET/POST
  bodies**, plus the stack trace. Sensitive fields (password, pin, csrf, token,
  credential, …) are automatically **[REDACTED]** so real passwords are never
  written to the log.
- **You (Telegram):** an instant message with the id, the error, where it
  happened, the request, the logged-in user, and the **names** of the POST
  fields involved (values stay in the log).

Alerts are **rate-limited**: the same error fires at most once per 5 minutes,
and no more than 20 alerts per hour total — so a crash loop or an attacker
hammering a broken URL can never flood your phone or Telegram's API.

---

## Deployment steps (Hostinger, one time)

> **ORDER MATTERS.** `db.php` no longer contains any fallback password — it
> reads the DB credentials **only** from `secrets.php`. So create `secrets.php`
> on the server **first** (step 1), *then* upload the updated code (step 2). If
> you upload the new `db.php` while `secrets.php` is missing, the site returns a
> clean 503 "Service unavailable" until the secrets file exists — no data leaks,
> but the site is down, so do it in this order.

### 1. Create the real secrets file OUTSIDE the web root  ← do this FIRST
In File Manager, go **up one level** from `public_html` — the folder that
*contains* `public_html` (the one with `DO_NOT_UPLOAD_HERE` in it). That folder
is **not** web-accessible, which is exactly why secrets belong there;
`DO_NOT_UPLOAD_HERE` only means "don't put your website files here", it does not
forbid a private config file.

Create a file there named exactly **`secrets.php`**. Copy the array from
`secrets.sample.php` into it and fill in the real values:

- `DB_USER`, `DB_PASS`, `DB_NAME` – your live database credentials.
- `QR_SECRET_KEY` – keep it identical to the current key
  (`promarkah_secure_qr_2026`) **unless** you want to rotate it; changing it
  invalidates QR codes already handed out. To rotate later, generate a new one:
  `php -r "echo bin2hex(random_bytes(32));"`
- `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` – see the next section.
- `HEARTBEAT_TOKEN` – only needed for the URL form of the heartbeat cron
  (step 5). Fine to leave empty if you use the CLI cron form.
- `ERROR_LOG_PATH` – optional, e.g. `/home/u985209750/promarkah-error.log`.

If your host allows it, set the file permissions to **600**.

### 2. Upload the new/updated code
Now upload these into `public_html` (overwriting where they already exist):
`security_bootstrap.php`, `error_page.php`, `secrets.sample.php`,
`user.ini.sample`, `heartbeat.php`, `telegram_test.php`, `.htaccess`, `db.php`,
`attendance_helpers.php`, and the pages whose `display_errors` were turned off.
With `secrets.php` already in place (step 1), the site keeps working normally —
now with errors hidden, logged, and (once step 3 is done) alerted.

### 3. Set up the Telegram bot
1. In Telegram, open **@BotFather** → send `/newbot` → follow prompts → copy the
   **token** it gives you (looks like `123456789:AAE...`).
2. Open a chat with your new bot and send it any message (e.g. "hi"). A bot
   can't message you until you've messaged it first.
3. Visit `https://api.telegram.org/bot<TOKEN>/getUpdates` in your browser
   (replace `<TOKEN>`). Find `"chat":{"id":<number>...}` and copy that number —
   that's your `TELEGRAM_CHAT_ID`.
4. Put both into `secrets.php`.

> Leaving `TELEGRAM_BOT_TOKEN` empty simply turns alerts off — everything else
> (hidden errors, logging, friendly page) still works.

### 4. (Recommended) Turn on global auto-loading
Copy `user.ini.sample` to a file named **`.user.ini`** inside `public_html`,
and make sure the path in it matches your server, e.g.:

```
auto_prepend_file = /home/u985209750/public_html/security_bootstrap.php
```

This makes the protection run before *every* request automatically — no page
can skip it. Allow ~5 minutes for PHP to pick up the change.

(Without this step you're still protected on every page that touches the
database, because `db.php` loads the bootstrap itself. The `.user.ini` just
closes the gap for any page that doesn't.)

### 5. (Recommended) Schedule the daily heartbeat
`heartbeat.php` sends a once-a-day "system OK" ping so you know the app **and**
the alerting itself are alive even when nothing breaks. It reports DB status
(and round-trip time), how many errors were logged in the last 24h, and the PHP
version.

In hPanel → **Advanced → Cron Jobs**, add a job that runs once a day (e.g. 08:00):

- **Preferred — CLI (no web exposure):**
  ```
  /usr/bin/php /home/u985209750/public_html/heartbeat.php
  ```
- **If your cron only fetches URLs:** set `HEARTBEAT_TOKEN` in `secrets.php`
  first, then use:
  ```
  wget -qO- "https://www.prosilat.net/heartbeat.php?token=YOUR_HEARTBEAT_TOKEN"
  ```
  The URL form **requires** the token; without it (or with the wrong one) the
  page returns 404, so nobody else can trigger heartbeats.

### 6. (Already done) No credentials remain in the web root
`db.php` and `attendance_helpers.php` no longer contain any password or signing
key — they read everything from `secrets.php`. So once step 1 is in place there
is nothing left to blank out: **no real credential exists anywhere inside
`public_html`.** If `secrets.php` is ever missing, the DB layer returns a clean
503 and QR signing fails closed (an ephemeral random key, so no forged code can
validate) — neither path exposes anything.

---

## Test that alerts work

Log in as admin/PIC, then visit **`https://www.prosilat.net/telegram_test.php`**.
It shows whether the token/chat id are set, sends a real test message, and
prints Telegram's raw reply so any failure is self-explanatory. On success you
get the message in Telegram. **Then delete `telegram_test.php`.**

To also see the full error→alert pipeline, create a throwaway
`public_html/_alert_test.php`:

```php
<?php require __DIR__ . '/security_bootstrap.php';
throw new RuntimeException('Test alert from ProMarkah');
```

Visit it once → you should see the branded "Ralat Sistem" page **and** get a
Telegram alert with the same incident id. **Delete `_alert_test.php` afterwards.**

---

## Security properties (why this "doesn't leak / doesn't get hacked")

- **No secret in the web root.** Real values live one directory above
  `public_html`, which has no URL. `.htaccess` additionally denies any
  `secrets*.php`, `*.sql`, `*.log`, `*.env`, backups and dotfiles as a second
  layer.
- **Detailed logs, but no credentials in them.** The log records the full
  request (method, URL, user, GET/POST bodies, trace), but any field whose name
  looks sensitive (`password`, `pin`, `csrf`, `token`, `credential`, …) is
  written as `[REDACTED]` — so the detailed logging can never capture a user's
  real password, even though the login form POSTs one.
- **No error detail to the client.** `display_errors` is forced off app-wide;
  the visitor only ever sees a random incident id that maps to full detail in
  your private log.
- **Telegram channel can't be abused.** Token lives only in `secrets.php`;
  messages are plain-text (so user input can't inject Telegram markup); sends
  are rate-limited and time-boxed (3s) so they can't hang requests or flood.
- **Fail-safe.** If `secrets.php` is missing the site still runs; if Telegram is
  down the page still loads; a failed alert can never itself raise an error.

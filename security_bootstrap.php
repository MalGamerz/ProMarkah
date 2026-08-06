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

// ─────────────────────────────────────────────────────────────────────────
// 1. LOAD SECRETS FROM OUTSIDE THE WEB ROOT
// ─────────────────────────────────────────────────────────────────────────
// Candidate locations, most-preferred first. DOCUMENT_ROOT is the reliable
// anchor on a real request (points at public_html no matter which sub-folder
// the running script lives in); the __DIR__ variants cover CLI / edge cases.
$__candidates = [];
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $__candidates[] = dirname($_SERVER['DOCUMENT_ROOT']) . '/secrets.php'; // one level above public_html
}
$__candidates[] = __DIR__ . '/../secrets.php';        // relative: bootstrap in public_html root
$__candidates[] = __DIR__ . '/../../secrets.php';     // relative: bootstrap in a sub-folder copy
$__candidates[] = __DIR__ . '/secrets.local.php';     // local dev only (git-ignored + .htaccess-denied)

$__SECRETS = [];
foreach ($__candidates as $__c) {
    if (is_file($__c)) {
        $__loaded = require $__c;
        if (is_array($__loaded)) { $__SECRETS = $__loaded; }
        break;
    }
}
unset($__candidates, $__c, $__loaded);

// Make the whole set available to db.php / attendance_helpers.php, and expose
// the QR key as the constant the rest of the app already expects.
$GLOBALS['__PROMARKAH_SECRETS'] = $__SECRETS;
if (!defined('QR_SECRET_KEY') && !empty($__SECRETS['QR_SECRET_KEY'])) {
    define('QR_SECRET_KEY', $__SECRETS['QR_SECRET_KEY']);
}

// ─────────────────────────────────────────────────────────────────────────
// 2. ERROR REPORTING: log everything, show the visitor nothing
// ─────────────────────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$__logPath = $__SECRETS['ERROR_LOG_PATH'] ?? '';
if ($__logPath === '') {
    $__logPath = rtrim(sys_get_temp_dir(), "/\\") . '/promarkah-error.log';
}
ini_set('error_log', $__logPath);
unset($__logPath);

// ─────────────────────────────────────────────────────────────────────────
// 3. TELEGRAM ALERT — rate-limited, timeout-bounded, injection-safe
// ─────────────────────────────────────────────────────────────────────────
/**
 * Sends a plain-text alert to the configured Telegram chat. Designed so it
 * can NEVER cause a problem of its own:
 *   • disabled unless both token + chat id are set;
 *   • deduped (same error at most once / 5 min) and globally capped
 *     (max 20 alerts / hour) via temp files, so an error loop or an attacker
 *     hammering a broken endpoint can't flood the API or your phone;
 *   • hard 3s timeout so a Telegram outage never slows a page;
 *   • plain text (no parse_mode) so nothing in the message — including
 *     user-supplied URLs — can be interpreted as markup or injected;
 *   • every failure is swallowed.
 */
function promarkah_telegram_alert(string $text, bool $rateLimit = true): void {
    $s      = $GLOBALS['__PROMARKAH_SECRETS'] ?? [];
    $token  = (string)($s['TELEGRAM_BOT_TOKEN'] ?? '');
    $chatId = (string)($s['TELEGRAM_CHAT_ID'] ?? '');
    if ($token === '' || $chatId === '') { return; }

    try {
        // Rate limiting protects the error path from flooding; the heartbeat
        // passes $rateLimit=false so its once-a-day ping is never suppressed.
        if ($rateLimit) {
            $dir = rtrim(sys_get_temp_dir(), "/\\");
            $now = time();

            // Per-error throttle: skip if this exact message fired < 5 min ago.
            $sig    = substr(hash('sha256', $text), 0, 16);
            $sigFsf = $dir . '/pm_tg_' . $sig;
            if (is_file($sigFsf) && ($now - (int)@filemtime($sigFsf)) < 300) { return; }

            // Global cap: at most 20 alerts in any rolling hour.
            $capFile = $dir . '/pm_tg_cap';
            $stamps  = [];
            if (is_file($capFile)) {
                $stamps = array_values(array_filter(
                    array_map('intval', explode(',', (string)@file_get_contents($capFile))),
                    static fn($t) => $t > $now - 3600
                ));
            }
            if (count($stamps) >= 20) { return; }
            $stamps[] = $now;
            @file_put_contents($capFile, implode(',', $stamps), LOCK_EX);
            @touch($sigFsf);
        }

        $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
        $fields   = http_build_query([
            'chat_id'                  => $chatId,
            'text'                     => mb_substr($text, 0, 3900), // Telegram caps ~4096
            'disable_web_page_preview' => 'true',
        ]);

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $fields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            @curl_exec($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $fields,
                'timeout'       => 3,
                'ignore_errors' => true,
            ]]);
            @file_get_contents($endpoint, false, $ctx);
        }
    } catch (\Throwable $e) {
        // An alert must never break the request it is reporting on.
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 4. CENTRAL REPORTER — full detail to the log, condensed to Telegram
// ─────────────────────────────────────────────────────────────────────────
/**
 * Redacts values whose KEY looks sensitive (password, pin, token, csrf, …) so
 * the detailed request logging below can never record a credential in plain
 * text — critical because the login/PIN forms POST real passwords. Long values
 * are truncated; nested arrays are handled recursively.
 */
function promarkah_redact($data): array {
    static $sensitive = ['pass', 'pwd', 'pin', 'csrf', 'token', 'credential', 'secret', 'otp', 'auth', 'apikey', 'api_key'];
    $out = [];
    foreach ((array)$data as $k => $v) {
        $lk  = strtolower((string)$k);
        $hit = false;
        foreach ($sensitive as $needle) { if (strpos($lk, $needle) !== false) { $hit = true; break; } }
        if ($hit) {
            $out[$k] = '[REDACTED]';
        } elseif (is_array($v)) {
            $out[$k] = promarkah_redact($v);
        } else {
            $s = (string)$v;
            $out[$k] = strlen($s) > 300 ? substr($s, 0, 300) . '…[' . strlen($s) . ' chars]' : $s;
        }
    }
    return $out;
}

/**
 * Everything useful for debugging one request: method, URL, referer, client,
 * user agent, the logged-in user, and the (redacted) GET/POST bodies.
 */
function promarkah_context(): array {
    $sess = $_SESSION ?? [];
    $user = 'id=' . ($sess['user_id'] ?? '-') . ' role=' . ($sess['role'] ?? '-');
    if (isset($sess['username']))   { $user .= ' username=' . $sess['username']; }
    if (isset($sess['judge_name'])) { $user .= ' judge=' . $sess['judge_name']; }
    return [
        'method'  => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'uri'     => $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '-'),
        'referer' => $_SERVER['HTTP_REFERER'] ?? '-',
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? '-',
        'xff'     => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '-',
        'agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '-',
        'user'    => $user,
        'get'     => promarkah_redact($_GET ?? []),
        'post'    => promarkah_redact($_POST ?? []),
    ];
}

/**
 * Records one problem and returns a short random incident id. FULL detail
 * (redacted GET/POST + stack trace + who/where) goes to the private log; a
 * compact summary goes to Telegram. The incident id is the ONLY thing shown to
 * the visitor, so nothing sensitive ever leaves the server.
 */
function promarkah_report(string $type, string $message, string $file, int $line, ?string $trace = null): string {
    try {
        $incident = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars, unguessable
    } catch (\Throwable $e) {
        $incident = strtoupper(dechex(mt_rand(0x10000000, 0x7fffffff)));
    }

    $c        = promarkah_context();
    $getJson  = json_encode($c['get'],  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $postJson = json_encode($c['post'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // ── FULL detail → private server log ──────────────────────────────────
    // The header keeps the ISO timestamp AND the incident id on ONE line so
    // tools (e.g. heartbeat.php's 24h error count) can reliably match each
    // incident; the rest of the block is the detailed, human-readable context.
    $rule   = str_repeat('=', 40);
    $block  = "\n[" . date('c') . "] incident={$incident} {$rule}\n";
    $block .= "  {$type}: {$message}\n";
    $block .= "  in      : {$file}:{$line}\n";
    $block .= "  request : {$c['method']} {$c['uri']}\n";
    $block .= "  referer : {$c['referer']}\n";
    $block .= "  client  : {$c['ip']}  (XFF: {$c['xff']})\n";
    $block .= "  agent   : {$c['agent']}\n";
    $block .= "  user    : {$c['user']}\n";
    $block .= "  GET     : {$getJson}\n";
    $block .= "  POST    : {$postJson}\n";
    if ($trace) { $block .= "  trace   :\n{$trace}\n"; }
    $block .= '  ' . $rule;
    error_log($block);

    // ── Compact summary → Telegram ────────────────────────────────────────
    // Field NAMES of the POST (values stay in the log) so you can see what the
    // failing request was doing without bloating the chat message.
    $postKeys = $c['post'] ? implode(', ', array_keys($c['post'])) : '(none)';
    promarkah_telegram_alert(
        "🚨 ProMarkah error\n" .
        "id: {$incident}\n" .
        "{$type}: " . mb_substr($message, 0, 300) . "\n" .
        "at: {$file}:{$line}\n" .
        "req: {$c['method']} {$c['uri']}\n" .
        "user: {$c['user']}\n" .
        "POST fields: {$postKeys}\n" .
        "ip: {$c['ip']}\n" .
        'time: ' . date('Y-m-d H:i:s')
    );

    return $incident;
}

// ─────────────────────────────────────────────────────────────────────────
// 5. BRANDED ERROR PAGE — shown once, contains only the incident id
// ─────────────────────────────────────────────────────────────────────────
function promarkah_render_error_page(string $incident, int $status = 500): void {
    static $rendered = false;
    if ($rendered) { return; }     // never render twice (exception → shutdown)
    $rendered = true;

    $safeId = htmlspecialchars($incident, ENT_QUOTES, 'UTF-8');

    // If output already began mid-page we can't send a clean page or status —
    // just leave an unobtrusive marker and stop.
    if (headers_sent()) {
        echo "\n<!-- ref:{$safeId} -->";
        return;
    }

    http_response_code($status);

    // JSON for API / AJAX callers; branded HTML for browsers.
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $isXhr  = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    if ($isXhr || strpos($accept, 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Ralat sistem', 'incident' => $incident], JSON_UNESCAPED_UNICODE);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $page = __DIR__ . '/error_page.php';
    if (is_file($page)) {
        include $page; // consumes $safeId
    } else {
        echo "<!doctype html><meta charset='utf-8'><title>Ralat</title>"
           . "<div style='font-family:sans-serif;text-align:center;padding:60px'>"
           . "<h1>Ralat Sistem</h1><p>Maaf, sistem menghadapi masalah teknikal. "
           . "Sila cuba lagi sebentar nanti.</p><p>Rujukan: <b>{$safeId}</b></p></div>";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// 6. REGISTER HANDLERS
// ─────────────────────────────────────────────────────────────────────────
// Non-fatal PHP errors: warnings and above get logged + alerted; notices /
// deprecations are logged only (kept quiet so the channel stays signal).
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; } // respect the @ operator
    $loud = in_array($severity, [E_WARNING, E_USER_WARNING, E_RECOVERABLE_ERROR, E_USER_ERROR], true);
    try {
        if ($loud) {
            promarkah_report('PHP', $message, $file, (int)$line);
        } else {
            error_log(sprintf('[%s] NOTICE: %s in %s:%d', date('c'), $message, $file, (int)$line));
        }
    } catch (\Throwable $e) { /* never let logging throw */ }
    return true; // handled — suppresses default output (already off anyway)
});

// Uncaught exceptions.
set_exception_handler(function (\Throwable $e) {
    try {
        $incident = promarkah_report('Uncaught', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    } catch (\Throwable $inner) {
        $incident = 'UNKNOWN';
    }
    promarkah_render_error_page($incident, 500);
});

// Fatal errors (E_ERROR / parse / etc.) — only reachable via shutdown.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        try {
            $incident = promarkah_report('Fatal', $err['message'], $err['file'], (int)$err['line']);
        } catch (\Throwable $inner) {
            $incident = 'UNKNOWN';
        }
        promarkah_render_error_page($incident, 500);
    }
});

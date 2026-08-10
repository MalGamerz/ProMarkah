<?php
/**
 * bootstrap_error_handling.php — Telegram alerting, the central error
 * reporter, the branded error page, and the PHP error/exception/shutdown
 * handler registrations.
 *
 * Included only by security_bootstrap.php, after bootstrap_secrets.php has
 * populated $GLOBALS['__PROMARKAH_SECRETS'] (promarkah_telegram_alert()
 * below reads that global, so load order matters — don't include this file
 * on its own).
 */

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

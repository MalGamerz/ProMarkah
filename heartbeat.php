<?php
/**
 * heartbeat.php — daily "system OK" ping + light health check to Telegram.
 *
 * Proves the app (and the alerting itself) is alive even on days nothing
 * breaks, and reports DB status + how many errors were logged in the last 24h.
 *
 * ── Schedule it (Hostinger hPanel → Advanced → Cron Jobs) ──────────────────
 *   Preferred (CLI — no web exposure):
 *       /usr/bin/php /home/u985209750/public_html/heartbeat.php
 *   If your cron can only fetch a URL, use:
 *       wget -qO- "https://www.prosilat.net/heartbeat.php?token=YOUR_HEARTBEAT_TOKEN"
 *   and set HEARTBEAT_TOKEN in secrets.php (the URL form REQUIRES the token).
 *   Set it to run once a day, e.g. at 08:00.
 */

// db.php pulls in security_bootstrap.php (secrets + handlers) and defines
// getDB(). Requiring it only DEFINES things — it does not open a DB connection
// until getDB() is actually called below, so the token gate still runs first.
require __DIR__ . '/db.php';

$isCli   = (PHP_SAPI === 'cli');
$secrets = $GLOBALS['__PROMARKAH_SECRETS'] ?? [];

// Web callers MUST present the secret token (constant-time compared). CLI/cron
// is trusted. A wrong/absent token returns 404 so the endpoint stays invisible.
if (!$isCli) {
    $expected = (string)($secrets['HEARTBEAT_TOKEN'] ?? '');
    $given    = (string)($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(404);
        exit;
    }
}

$ok    = true;
$lines = [];

// ── DB connectivity + round-trip time ──────────────────────────────────────
$t0 = microtime(true);
try {
    $conn = getDB();
    $conn->query('SELECT 1');
    $lines[] = 'DB: connected (' . round((microtime(true) - $t0) * 1000) . ' ms)';
} catch (\Throwable $e) {
    $ok = false;
    $lines[] = 'DB: FAIL — ' . $e->getMessage();
}

// ── Errors logged in the last 24h (parsed from the error log) ──────────────
$logPath      = (string)ini_get('error_log');
$errCount     = 0;
$lastIncident = '';
if ($logPath !== '' && is_file($logPath)) {
    $cutoff = time() - 86400;
    foreach (file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
        if (strpos($ln, 'incident=') === false) { continue; }
        // Match the single header line that carries both the ISO timestamp and
        // the incident id (see the log format in security_bootstrap.php).
        if (preg_match('/\[(\d{4}-\d{2}-\d{2}T[\d:.+\-]+)\]\s+incident=([A-F0-9]+)/', $ln, $m)
            && strtotime($m[1]) >= $cutoff) {
            $errCount++;
            $lastIncident = $m[2];
        }
    }
}
$lines[] = "Errors (24h): {$errCount}" . ($lastIncident ? " — last id {$lastIncident}" : '');

// ── Environment ─────────────────────────────────────────────────────────────
$lines[] = 'PHP: ' . PHP_VERSION;
$lines[] = 'Host: ' . ($_SERVER['HTTP_HOST'] ?? gethostname());

// ── Send (bypassing the per-error rate-limiter; heartbeat is once a day) ───
$msg = ($ok ? '💚' : '❤️‍🩹') . ' ProMarkah heartbeat — ' . ($ok ? 'OK' : 'DEGRADED') . "\n"
     . date('Y-m-d H:i:s') . "\n" . implode("\n", $lines);
promarkah_telegram_alert($msg, false);

if ($isCli) {
    echo $msg . "\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo "heartbeat sent\n";
}

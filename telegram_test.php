<?php
/**
 * telegram_test.php — TEMPORARY setup diagnostic. DELETE this file when done.
 *
 * Visit it in your browser (while logged in as admin/PIC) after filling in
 * TELEGRAM_BOT_TOKEN + TELEGRAM_CHAT_ID in secrets.php. It sends one test
 * message straight to your chat and prints Telegram's raw reply, so if it
 * fails you can see exactly why (wrong chat id, bad token, etc.).
 *
 * It is gated behind an admin/PIC login so it can't be abused to spam you if
 * you forget to remove it — but you should still DELETE it after testing.
 */

session_start();
require __DIR__ . '/security_bootstrap.php';
require __DIR__ . '/auth_check.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'pic'], true)) {
    http_response_code(403);
    exit('Log in as admin/PIC first, then reload this page.');
}

header('Content-Type: text/plain; charset=utf-8');

$s     = $GLOBALS['__PROMARKAH_SECRETS'] ?? [];
$token = (string)($s['TELEGRAM_BOT_TOKEN'] ?? '');
$chat  = (string)($s['TELEGRAM_CHAT_ID'] ?? '');

echo "ProMarkah — Telegram alert test\n";
echo "===============================\n";
echo 'secrets.php loaded : ' . (!empty($s) ? 'yes' : 'NO (file not found)') . "\n";
// The part before ":" in a bot token is the bot's public id, not the secret.
echo 'bot token set      : ' . ($token !== '' ? 'yes (bot id ' . strtok($token, ':') . ')' : 'NO') . "\n";
echo 'chat id set        : ' . ($chat  !== '' ? 'yes (' . $chat . ')' : 'NO') . "\n\n";

if ($token === '' || $chat === '') {
    echo "→ Fill TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID in secrets.php, then reload.\n";
    exit;
}

// Call Telegram directly (bypassing the app's rate-limiter) so the test always
// fires and we can surface the exact API response.
$msg    = "✅ ProMarkah Telegram test — " . date('Y-m-d H:i:s')
        . "\nIf you can read this in Telegram, error alerts are working.";
$url    = "https://api.telegram.org/bot{$token}/sendMessage";
$fields = http_build_query(['chat_id' => $chat, 'text' => $msg]);

$resp = '';
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) { $resp = 'cURL error: ' . curl_error($ch); }
    curl_close($ch);
} else {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $fields,
        'timeout'       => 8,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx) ?: 'request failed';
}

echo "Telegram API response:\n" . $resp . "\n\n";

if (strpos($resp, '"ok":true') !== false) {
    echo "→ SUCCESS. Open Telegram — the test message should be waiting for you.\n";
} else {
    echo "→ FAILED. Most common causes:\n";
    echo "   • \"chat not found\"  → wrong chat id, or you never messaged the bot first.\n";
    echo "   • \"Unauthorized\"     → wrong or incomplete bot token.\n";
    echo "   • \"bot was blocked\"  → you blocked the bot; unblock and retry.\n";
}

echo "\n⚠️  DELETE this file (telegram_test.php) now that you're finished.\n";

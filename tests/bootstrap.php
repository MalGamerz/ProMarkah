<?php
// ══════════════════════════════════════════════════════════════════
//  tests/bootstrap.php — PHPUnit entry point
// ══════════════════════════════════════════════════════════════════
//
// Loads the same DB/secrets bootstrap every real page uses (db.php ->
// security_bootstrap.php -> bootstrap_secrets.php), so Database tests get
// getDB() with zero special-cased test config: they use the exact same
// secrets.php as your local dev site. If secrets.php isn't set up yet,
// getDB() fails the same way it would for any other page — see README.md's
// "Secrets & security posture" section.
//
// Also loads tests/.env.testing (gitignored, optional) for the Http suite's
// TEST_BASE_URL / TEST_*_USERNAME / TEST_*_PASSWORD variables, without
// adding a dotenv dependency for four lines of parsing.

require_once __DIR__ . '/../db.php';

$envFile = __DIR__ . '/.env.testing';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

<?php

// Ensure secrets + error handling are loaded before we touch the database.
// (Harmless/idempotent if a page already required it or it was auto-prepended.)
require_once __DIR__ . '/security_bootstrap.php';

// Credentials come ONLY from the out-of-web-root secrets file (see
// secrets.sample.php / SECURITY_ERROR_HANDLING.md). No password is kept in
// this file, so nothing sensitive lives inside public_html. If secrets.php is
// missing, getDB() fails cleanly with a 503 below — it never falls back to a
// hardcoded credential. (DB_HOST / DB_CHARSET defaults are not secrets.)
$__s = $GLOBALS['__PROMARKAH_SECRETS'] ?? [];
define('DB_HOST',    $__s['DB_HOST']    ?? 'localhost');
define('DB_USER',    $__s['DB_USER']    ?? '');
define('DB_PASS',    $__s['DB_PASS']    ?? '');
define('DB_NAME',    $__s['DB_NAME']    ?? '');
define('DB_CHARSET', $__s['DB_CHARSET'] ?? 'utf8mb4');
unset($__s);

function getDB(): mysqli {
    static $conn = null;

    if ($conn !== null) {
        return $conn;
    }

    // No hardcoded fallback exists any more — if the credentials are empty it
    // means secrets.php was not found/loaded. Fail cleanly (and log why)
    // rather than emitting a confusing connection error.
    if (DB_USER === '' || DB_NAME === '') {
        error_log('getDB(): DB credentials empty — secrets.php missing or not loaded '
                . '(expected at "<web root>/../secrets.php").');
        http_response_code(503);
        exit('Service unavailable. Please try again later.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset(DB_CHARSET);
    } catch (mysqli_sql_exception $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Service unavailable. Please try again later.');
    }

    return $conn;
}
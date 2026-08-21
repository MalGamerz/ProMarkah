<?php
/**
 * bootstrap_secrets.php — loads secrets from outside the web root and points
 * error logging at the configured log file.
 *
 * Included only by security_bootstrap.php (not meant to be required
 * directly) — see that file for the full load order and how to wire this
 * app up via auto_prepend_file or an explicit require.
 */

// ─────────────────────────────────────────────────────────────────────────
// 1. LOAD SECRETS FROM OUTSIDE THE WEB ROOT
// ─────────────────────────────────────────────────────────────────────────
// Candidate locations, most-preferred first. DOCUMENT_ROOT is the reliable
// anchor on a real request (points at public_html no matter which sub-folder
// the running script lives in); the __DIR__ variants cover CLI / edge cases.
$secret_file_candidates = [];
if (!empty($_SERVER['DOCUMENT_ROOT'])) {
    $secret_file_candidates[] = dirname($_SERVER['DOCUMENT_ROOT']) . '/secrets.php'; // one level above public_html
}
$secret_file_candidates[] = __DIR__ . '/../secrets.php';        // relative: bootstrap in public_html root
$secret_file_candidates[] = __DIR__ . '/../../secrets.php';     // relative: bootstrap in a sub-folder copy
$secret_file_candidates[] = __DIR__ . '/secrets.local.php';     // local dev only (git-ignored + .htaccess-denied)

$pm_secrets = [];
foreach ($secret_file_candidates as $candidate_path) {
    if (is_file($candidate_path)) {
        $loaded_secrets = require $candidate_path;
        if (is_array($loaded_secrets)) { $pm_secrets = $loaded_secrets; }
        break;
    }
}
unset($secret_file_candidates, $candidate_path, $loaded_secrets);

// Make the whole set available to db.php / attendance_helpers.php, and expose
// the QR key as the constant the rest of the app already expects.
$GLOBALS['__PROMARKAH_SECRETS'] = $pm_secrets;
if (!defined('QR_SECRET_KEY') && !empty($pm_secrets['QR_SECRET_KEY'])) {
    define('QR_SECRET_KEY', $pm_secrets['QR_SECRET_KEY']);
}

// ─────────────────────────────────────────────────────────────────────────
// 2. ERROR REPORTING: log everything, show the visitor nothing
// ─────────────────────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$error_log_path = $pm_secrets['ERROR_LOG_PATH'] ?? '';
if ($error_log_path === '') {
    $error_log_path = rtrim(sys_get_temp_dir(), "/\\") . '/promarkah-error.log';
}
ini_set('error_log', $error_log_path);
unset($error_log_path, $pm_secrets);

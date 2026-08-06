<?php
/**
 * oauth_apple_callback.php — verifies the ID token Apple posts back after
 * "Sign in with Apple" and logs the matching judge in.
 *
 * Apple POSTs here (response_mode=form_post, see oauth_apple_start.php)
 * with `state`, `id_token`, and `code`. Only `state` and `id_token` are
 * used — `code` is ignored since this app only needs identity, not an
 * ongoing API session with Apple.
 */

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();
require_once __DIR__ . '/oauth_helpers.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id_token'])) {
    oauth_fail('invalid_request');
}

if (APPLE_CLIENT_ID === '') {
    oauth_fail('not_configured');
}

// ── CSRF: state must match what we generated before redirecting to Apple ──
$expectedState = $_SESSION['apple_oauth_state'] ?? null;
unset($_SESSION['apple_oauth_state']);
if (!$expectedState || empty($_POST['state']) || !hash_equals($expectedState, $_POST['state'])) {
    oauth_fail('invalid_state');
}

try {
    $keysJson = file_get_contents('https://appleid.apple.com/auth/keys');
    if ($keysJson === false) {
        oauth_fail('apple_unreachable');
    }
    $keys = JWK::parseKeySet(json_decode($keysJson, true));

    $payload = JWT::decode($_POST['id_token'], $keys);

    if ($payload->aud !== APPLE_CLIENT_ID) {
        oauth_fail('wrong_audience');
    }
    if ($payload->iss !== 'https://appleid.apple.com') {
        oauth_fail('wrong_issuer');
    }
    // Apple's id_token exposes email_verified as either a bool or the
    // string "true"/"false" depending on flow — normalize both.
    $emailVerified = filter_var($payload->email_verified ?? false, FILTER_VALIDATE_BOOLEAN);
    if (empty($payload->email) || !$emailVerified) {
        oauth_fail('email_not_verified');
    }

    $email = strtolower($payload->email);

} catch (Throwable $e) {
    error_log('Apple OAuth verify failed: ' . $e->getMessage());
    oauth_fail('invalid_token');
}

if (!oauth_login_judge_by_email($conn, $email)) {
    oauth_fail('no_judge_linked');
}

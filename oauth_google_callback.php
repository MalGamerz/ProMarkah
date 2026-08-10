<?php
/**
 * oauth_google_callback.php — verifies a Google Identity Services ID token
 * and logs the matching judge in.
 *
 * Flow: login.php renders Google's Sign-In button (Google Identity
 * Services JS). On success, Google POSTs the signed ID token here directly
 * (via the button's built-in form-post data-callback), as `credential`.
 * We verify its signature against Google's public keys, check it was
 * actually issued for OUR app and has a verified email, then hand off to
 * oauth_login_judge_by_email().
 */

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
$conn = getDB();
require_once __DIR__ . '/oauth_helpers.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
    oauth_fail('invalid_request');
}

if (GOOGLE_CLIENT_ID === '') {
    // Not configured yet — fail loudly to whoever's testing this, rather
    // than silently pretending sign-in is available.
    oauth_fail('not_configured');
}

try {
    // Google's public signing keys, fetched fresh each time. (A production
    // app under real load would cache this per Google's Cache-Control
    // header instead of fetching every login — fine for this app's volume.)
    $keysJson = file_get_contents('https://www.googleapis.com/oauth2/v3/certs');
    if ($keysJson === false) {
        oauth_fail('google_unreachable');
    }
    $keys = JWK::parseKeySet(json_decode($keysJson, true));

    $payload = JWT::decode($_POST['credential'], $keys);

    // ── Verify the token was actually meant for THIS app ──
    if ($payload->aud !== GOOGLE_CLIENT_ID) {
        oauth_fail('wrong_audience');
    }
    if (!in_array($payload->iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        oauth_fail('wrong_issuer');
    }
    if (empty($payload->email) || empty($payload->email_verified)) {
        oauth_fail('email_not_verified');
    }

    // Nonce check — login.php generates a fresh nonce per page load and
    // wires it into the Google button config; this stops a captured/replayed
    // token from a different browsing session being reused here.
    $expectedNonce = $_SESSION['google_oauth_nonce'] ?? null;
    unset($_SESSION['google_oauth_nonce']);
    if (!$expectedNonce || empty($payload->nonce) || !hash_equals($expectedNonce, $payload->nonce)) {
        oauth_fail('invalid_nonce');
    }

    $email = strtolower($payload->email);

} catch (Throwable $e) {
    error_log('Google OAuth verify failed: ' . $e->getMessage());
    oauth_fail('invalid_token');
}

if (!oauth_login_judge_by_email($conn, $email)) {
    oauth_fail('no_judge_linked');
}

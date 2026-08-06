<?php
/**
 * oauth_apple_start.php — kicks off "Sign in with Apple" for judges.
 * Linked from login.php's "Log Masuk dengan Apple" button (a plain link,
 * not a form — Apple's flow is a full-page redirect).
 */

session_start();
require __DIR__ . '/auth_check.php';
require_once __DIR__ . '/oauth_config.php';

if (APPLE_CLIENT_ID === '') {
    header('Location: login.php?oauth_error=not_configured');
    exit();
}

// CSRF protection for the OAuth redirect round-trip — verified back in
// oauth_apple_callback.php against the `state` Apple echoes back to us.
$state = bin2hex(random_bytes(24));
$_SESSION['apple_oauth_state'] = $state;

$params = [
    'client_id'     => APPLE_CLIENT_ID,
    'redirect_uri'  => OAUTH_BASE_URL . '/oauth_apple_callback.php',
    // "code id_token" + form_post gives us a signed id_token directly in
    // the callback — no server-to-server token exchange (and therefore no
    // private key / client secret JWT) needed for a pure identity check.
    'response_type' => 'code id_token',
    'response_mode' => 'form_post',
    'scope'         => 'email',
    'state'         => $state,
];

header('Location: https://appleid.apple.com/auth/authorize?' . http_build_query($params));
exit();

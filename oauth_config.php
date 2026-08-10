<?php
/**
 * oauth_config.php — Google / Apple Sign-In credentials for judge login.
 *
 * Nothing works until these are filled in. See SETUP_GOOGLE_APPLE_LOGIN.md
 * for exactly what to create in Google Cloud Console / Apple Developer and
 * where each value below comes from.
 *
 * Keep this file OUT of version control once real secrets are filled in.
 * The empty placeholders below are safe to commit as-is.
 */

// ── Google ──────────────────────────────────────────────────────────────
// Google Cloud Console → APIs & Services → Credentials → OAuth client ID
// (type: Web application). Only the Client ID is needed — the judge login
// flow uses Google Identity Services' ID-token sign-in, which never touches
// the Client Secret.
define('GOOGLE_CLIENT_ID', '');

// ── Apple ───────────────────────────────────────────────────────────────
// Apple Developer → Certificates, Identifiers & Profiles → Identifiers →
// Services IDs. Create a Services ID with "Sign in with Apple" enabled and
// this app's domain + redirect URL registered.
//
// This is the ONLY Apple value needed. This app only asks "who is this
// person" (identity), not ongoing API access on their behalf — so it uses
// Apple's id_token-only sign-in, which needs no private key, no Key ID, and
// no Team ID. (Those three are only required if you later need the
// authorization-code token exchange, e.g. for a refresh token — not needed
// here.)
define('APPLE_CLIENT_ID', ''); // the Services ID, e.g. net.prosilat.signin

// Full HTTPS URL this app is reachable at, no trailing slash — used to build
// the redirect_uri sent to Google/Apple. Must exactly match what's registered
// in each provider's console (including scheme and path).
define('OAUTH_BASE_URL', 'https://www.prosilat.net');

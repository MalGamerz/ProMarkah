<?php
/**
 * auth_check.php — shared idle-timeout enforcement.
 * Include immediately after session_start() on every authenticated page.
 * (Previously this logic only lived in login.php and never ran again
 * after a user was authenticated, so sessions never actually expired.)
 *
 * No-op for anonymous/guest sessions: $_SESSION['last_activity'] is only
 * ever set by this file (see the bottom), so on a session that never
 * logged in, the isset() check below never trips and nothing happens.
 * This is why the public attendance pages (attendance.php and friends —
 * see the "no login by design" note in README.md) can safely include this
 * file without gaining a login requirement.
 *
 * AJAX callers depend on the exact 401 {"error":"session_expired"} contract
 * below (see layout.php's pmFetch()) — don't change its shape without
 * updating every caller.
 */

$idle_timeout = 900; // 15 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $idle_timeout)) {
    session_unset();
    session_destroy();

    // AJAX callers (jQuery $.get/$.ajax and fetch() with the same header set
    // manually) must NOT get the redirect treatment — a Location header just
    // gets silently followed by the XHR/fetch, so the caller ends up with
    // login.php's HTML as its "response" (e.g. dumped into a <select> as
    // useless non-<option> markup, quietly rendering an empty dropdown with
    // no error anywhere). Answer those with a plain 401 the caller can
    // actually detect instead.
    $is_ajax = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    );
    if ($is_ajax) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']);
        exit();
    }

    header('Location: login.php');
    exit();
}
$_SESSION['last_activity'] = time();

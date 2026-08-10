<?php
/**
 * oauth_helpers.php — shared logic for the Google/Apple judge login
 * callbacks. Both callbacks end up here once they've verified a signed
 * ID token and extracted a trusted, provider-verified email address.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/oauth_config.php';

/**
 * Look up a judge by verified email and, if found, establish the same
 * session state login.php's PIN flow does — so every downstream page
 * (judge.php, auth_check.php, etc.) sees an identical, indistinguishable
 * session regardless of which login method was used.
 *
 * Returns true and redirects to judge.php on success. Returns false (does
 * NOT redirect) if no judge is linked to this email, so the caller can show
 * a tailored error message.
 */
function oauth_login_judge_by_email(mysqli $conn, string $verified_email): bool
{
    $stmt = $conn->prepare("SELECT id, name FROM judges WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $verified_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows !== 1) {
        $stmt->close();
        return false;
    }

    $judge = $result->fetch_assoc();
    $stmt->close();

    session_regenerate_id(true);
    $_SESSION['user_id']    = $judge['id'];
    $_SESSION['role']       = 'judge';
    $_SESSION['judge_name'] = $judge['name'];
    unset($_SESSION['step']);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Self-healing: creates audit_log on first use so login works even before
    // that table's migration has run against a given environment. Deliberately
    // duplicated here rather than centralized — low-frequency, idempotent
    // (CREATE TABLE IF NOT EXISTS), and login must not depend on migration order.
    $conn->query("CREATE TABLE IF NOT EXISTS audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        role VARCHAR(20) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        login_time DATETIME NOT NULL
    )");
    $stmtAudit = $conn->prepare("INSERT INTO audit_log (user_id, role, ip, login_time) VALUES (?, 'judge', ?, NOW())");
    $stmtAudit->bind_param("is", $judge['id'], $ip);
    $stmtAudit->execute();
    $stmtAudit->close();

    header('Location: judge.php');
    exit();
}

/**
 * Redirect back to the login page with an error the login form knows how
 * to display (see login.php's handling of ?oauth_error=...).
 */
function oauth_fail(string $reason): never
{
    header('Location: login.php?oauth_error=' . urlencode($reason));
    exit();
}

<?php
// ══════════════════════════════════════════════════════════════════
//  attendance_helpers.php — Shared helpers for attendance pages
// ══════════════════════════════════════════════════════════════════

// QR_SECRET_KEY comes ONLY from security_bootstrap.php (loaded from the
// out-of-web-root secrets file). No signing key is kept in this file.
require_once __DIR__ . '/security_bootstrap.php';
if (!defined('QR_SECRET_KEY')) {
    // secrets.php missing/empty — fail CLOSED: use an ephemeral random key so
    // no forged QR token can ever validate, rather than a weak/known fallback.
    define('QR_SECRET_KEY', bin2hex(random_bytes(32)));
    error_log('QR_SECRET_KEY missing — secrets.php not loaded; QR signing disabled (fail-closed).');
}
define('QR_TTL_SECONDS', 2 * 3600); // 2 hours

// ── Generate a signed QR URL ─────────────────────────────────────
function makeQrUrl(int $school_id, int $session_id): string {
    $exp = time() + QR_TTL_SECONDS;
    $sig = hash_hmac('sha256', "$school_id-$session_id-$exp", QR_SECRET_KEY);
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    return "$base?mode=student&school_id=$school_id&session_id=$session_id&exp=$exp&sig=$sig";
}

// ── Verify a signed QR token ─────────────────────────────────────
function verifyQrToken(int $school_id, int $session_id, int $exp, string $sig): bool {
    if (time() > $exp) return false;
    $expected = hash_hmac('sha256', "$school_id-$session_id-$exp", QR_SECRET_KEY);
    return hash_equals($expected, $sig);
}

// ── Render an expired/invalid QR page and exit ───────────────────
function renderExpiredPage(): never {
    http_response_code(403);
    include __DIR__ . '/expired_qr_page.php';
    exit;
}

// ── Fetch session name by ID ──────────────────────────────────────
function getSessionName(mysqli $conn, int $session_id): string {
    $stmt = $conn->prepare("SELECT session_name FROM sessions WHERE session_id = ? LIMIT 1");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['session_name'] ?? 'Sidang';
}

// ── Fetch the siri name a session belongs to, by session ID ───────
function getSessionSiriName(mysqli $conn, int $session_id): string {
    $stmt = $conn->prepare("
        SELECT si.siri_name FROM sessions se
        LEFT JOIN siri si ON se.siri_id = si.siri_id
        WHERE se.session_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['siri_name'] ?? '';
}

// ── Fetch school name by ID ───────────────────────────────────────
function getSchoolName(mysqli $conn, int $school_id): string {
    $stmt = $conn->prepare("SELECT school_name FROM schools WHERE school_id = ? LIMIT 1");
    $stmt->bind_param("i", $school_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['school_name'] ?? 'Cawangan';
}

// ── Subquery: student_ids actually belonging to a session, via level
//    OR group assignment (not just "school registered for session") ──
const STUDENT_SESSION_MEMBERSHIP_SQL = "
    SELECT DISTINCT st2.student_id
    FROM students st2
    JOIN levels l ON st2.level_id = l.level_id
    WHERE l.session_id = ?
    UNION
    SELECT DISTINCT gs.student_id
    FROM group_students gs
    JOIN `groups` g ON gs.group_id = g.group_id
    JOIN levels gl ON g.level_id = gl.level_id
    WHERE gl.session_id = ?
";

// ── Fetch schools linked to a session ────────────────────────────
function getSessionSchools(mysqli $conn, int $session_id): array {
    $stmt = $conn->prepare("
        SELECT s.school_id, s.school_name,
               (SELECT COUNT(*) FROM students st
                JOIN (" . STUDENT_SESSION_MEMBERSHIP_SQL . ") stu ON stu.student_id = st.student_id
                WHERE st.school_id = s.school_id) AS total_students,
               (SELECT COUNT(DISTINCT a.student_id)
                FROM attendance a
                JOIN students st ON a.student_id = st.student_id
                JOIN (" . STUDENT_SESSION_MEMBERSHIP_SQL . ") stu2 ON stu2.student_id = st.student_id
                WHERE st.school_id = s.school_id AND a.status = 'Present') AS present_students
        FROM schools s
        JOIN session_schools ss ON s.school_id = ss.school_id
        WHERE ss.session_id = ?
        ORDER BY s.school_name ASC
    ");
    $stmt->bind_param("iiiii", $session_id, $session_id, $session_id, $session_id, $session_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch students for a specific school, with attendance status ──
// Scoped to students who actually belong to this session (via level or
// group), not just "their school is registered for this session" —
// a school can have students across multiple siri.
function getStudentsForSchool(mysqli $conn, int $session_id, int $school_id): array {
    $stmt = $conn->prepare("
        SELECT st.student_id, st.student_name, st.gender,
               (SELECT status FROM attendance
                WHERE student_id = st.student_id AND session_id = ? AND status = 'Present'
                LIMIT 1) AS is_present
        FROM students st
        JOIN (" . STUDENT_SESSION_MEMBERSHIP_SQL . ") stu ON stu.student_id = st.student_id
        WHERE st.school_id = ?
        ORDER BY st.student_name ASC
    ");
    $stmt->bind_param("iiii", $session_id, $session_id, $session_id, $school_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Fetch all students grouped by school (global QR) ─────────────
function getAllStudentsBySchool(mysqli $conn, int $session_id): array {
    $stmt = $conn->prepare("
        SELECT st.student_id, st.student_name, st.school_id,
               (SELECT status FROM attendance
                WHERE student_id = st.student_id AND session_id = ? AND status = 'Present'
                LIMIT 1) AS is_present
        FROM students st
        JOIN (" . STUDENT_SESSION_MEMBERSHIP_SQL . ") stu ON stu.student_id = st.student_id
        ORDER BY st.student_name ASC
    ");
    $stmt->bind_param("iii", $session_id, $session_id, $session_id);
    $stmt->execute();

    $grouped = [];
    $result  = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $grouped[(int)$row['school_id']][] = $row;
    }
    return $grouped;
}

<?php
// ══════════════════════════════════════════════════════════════════
//  attendance.php — Attendance controller
//  Routes between: Student self-reg (QR) | Admin dashboard |
//                  Admin session/school/student views
// ══════════════════════════════════════════════════════════════════

ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
require __DIR__ . '/auth_check.php';
include 'db.php';
include 'attendance_helpers.php';
$conn = getDB();
date_default_timezone_set('Asia/Kuala_Lumpur');

// ── URL state persistence ─────────────────────────────────────────
// On a bare attendance.php with no params, reset everything → dashboard
$qs = $_SERVER['QUERY_STRING'];
$is_bare_dashboard = ($qs === '' || $qs === false);

if ($is_bare_dashboard) {
    unset(
        $_SESSION['current_view'],
        $_SESSION['current_session_id'],
        $_SESSION['current_school_id']
    );
} else {
    // Save whatever is in the URL to session
    if (isset($_GET['view'])) {
        $_SESSION['current_view'] = $_GET['view'];
        // view=all_present doesn't need session/school
        unset($_SESSION['current_session_id'], $_SESSION['current_school_id']);
    } elseif (isset($_SESSION['current_view']) && !isset($_GET['session_id']) && !isset($_GET['school_id'])) {
        // Restore view if nothing else is set
        $_GET['view'] = $_SESSION['current_view'];
    }

    if (isset($_GET['session_id'])) {
        $_SESSION['current_session_id'] = (int)$_GET['session_id'];
        // Explicitly visiting ?session_id without school_id = user wants the school picker
        // Clear saved school so it doesn't get injected and skip the picker
        if (!isset($_GET['school_id'])) {
            unset($_SESSION['current_school_id']);
        }
    } elseif (isset($_SESSION['current_session_id']) && !isset($_GET['view'])) {
        $_GET['session_id'] = $_SESSION['current_session_id'];
    }

    if (isset($_GET['school_id'])) {
        $_SESSION['current_school_id'] = (int)$_GET['school_id'];
    } elseif (isset($_SESSION['current_school_id']) && isset($_GET['session_id'])) {
        $_GET['school_id'] = $_SESSION['current_school_id'];
    }
}

// ══════════════════════════════════════════════════════════════════
//  ROUTE 1 — Student self-registration (standalone, no layout)
// ══════════════════════════════════════════════════════════════════
if (isset($_GET['mode']) && $_GET['mode'] === 'student') {

    $school_id  = (int)($_GET['school_id']  ?? 0);
    $session_id = (int)($_GET['session_id'] ?? 0);
    $exp        = (int)($_GET['exp']        ?? 0);
    $sig        =       $_GET['sig']        ?? '';

    if (!verifyQrToken($school_id, $session_id, $exp, $sig)) {
        renderExpiredPage();
    }

    $is_global          = ($school_id === 0);
    $school_name        = $is_global ? 'Semua Cawangan' : getSchoolName($conn, $school_id);
    $session_name       = getSessionName($conn, $session_id);
    $siri_name          = getSessionSiriName($conn, $session_id);
    $student_list       = [];
    $schools_list       = [];
    $students_by_school = [];

    if ($is_global) {
        $sch_stmt = $conn->prepare("
            SELECT s.school_id, s.school_name
            FROM schools s
            JOIN session_schools ss ON s.school_id = ss.school_id
            WHERE ss.session_id = ?
            ORDER BY s.school_name ASC
        ");
        $sch_stmt->bind_param("i", $session_id);
        $sch_stmt->execute();
        $schools_list = $sch_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $students_by_school = getAllStudentsBySchool($conn, $session_id);
    } else {
        $student_list = getStudentsForSchool($conn, $session_id, $school_id);
    }

    include 'attendance_student.php'; // renders and calls exit()
}

// ══════════════════════════════════════════════════════════════════
//  ROUTE 2 — Admin views (require layout)
// ══════════════════════════════════════════════════════════════════
$pm_page = 'attendance';
include 'layout.php';

// ── Sub-route: Full attendance list ──────────────────────────────
if (isset($_GET['view']) && $_GET['view'] === 'all_present'):
    include 'attendance_view_all.php';

// ── Sub-route: Session -> school picker ──────────────────────────
elseif (isset($_GET['session_id']) && !isset($_GET['school_id'])):
    include 'attendance_view_session.php';

// ── Sub-route: School -> student table ───────────────────────────
elseif (isset($_GET['school_id'])):
    include 'attendance_view_school.php';

// ── Sub-route: Dashboard ─────────────────────────────────────────
else:
    include 'attendance_view_dashboard.php';
endif;
?>

</main>
</body>
</html>
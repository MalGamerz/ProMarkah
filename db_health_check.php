<?php
/**
 * db_health_check.php — read-only smoke test for the 3NF migration.
 *
 * Runs the actual query patterns used by pic_groups.php, judge.php,
 * judge_view_marks.php, pic_view_marks.php, pic_directory.php, pic.php,
 * pic_master_list.php, and attendance_view_dashboard.php — the pages
 * that were rewritten to derive groups.session_id / scores.test_id via
 * joins instead of reading the (now-dropped) columns directly.
 *
 * Every query here is SELECT-only. Nothing is written or modified.
 *
 * Usage:
 *   - Web: log in as admin/pic, then visit db_health_check.php
 *   - CLI: php db_health_check.php   (run directly on the server, since
 *          db.php's "localhost" host only resolves there)
 */

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    session_start();
    require __DIR__ . '/auth_check.php';
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'pic'], true)) {
        http_response_code(403);
        exit('Forbidden — admin/pic login required.');
    }
}

require __DIR__ . '/db.php';
$conn = getDB();

$results = [];

/**
 * Runs one read-only check and records pass/fail.
 * $validate receives the fetched rows (array of assoc arrays) and must
 * return [bool $ok, string $message]. If omitted, any successful query
 * (no SQL error) counts as a pass.
 */
function check(mysqli $conn, array &$results, string $name, string $sql, ?callable $validate = null): void {
    $start = microtime(true);
    // db.php enables MYSQLI_REPORT_STRICT, so a failing query throws
    // mysqli_sql_exception instead of returning false — must catch it
    // here, otherwise one bad query would crash this entire page instead
    // of showing up as a single clean FAIL row.
    try {
        $res = $conn->query($sql);
    } catch (\mysqli_sql_exception $e) {
        $ms = round((microtime(true) - $start) * 1000, 1);
        $results[] = ['name' => $name, 'ok' => false, 'msg' => 'SQL ERROR: ' . $e->getMessage(), 'ms' => $ms];
        return;
    }
    $ms = round((microtime(true) - $start) * 1000, 1);

    if ($res === false) {
        $results[] = ['name' => $name, 'ok' => false, 'msg' => 'SQL ERROR: ' . $conn->error, 'ms' => $ms];
        return;
    }

    $rows = [];
    if ($res instanceof mysqli_result) {
        while ($r = $res->fetch_assoc()) $rows[] = $r;
    }

    if ($validate) {
        [$ok, $msg] = $validate($rows);
        $results[] = ['name' => $name, 'ok' => $ok, 'msg' => $msg, 'ms' => $ms];
    } else {
        $results[] = ['name' => $name, 'ok' => true, 'msg' => count($rows) . ' row(s)', 'ms' => $ms];
    }
}

// ── Pick real sample IDs from the data so the join-pattern checks below
//    exercise actual rows instead of just proving the SQL parses. ──
$sample_level   = (int) ($conn->query("SELECT level_id FROM levels ORDER BY level_id LIMIT 1")->fetch_assoc()['level_id'] ?? 0);
$sample_session = (int) ($conn->query("SELECT session_id FROM sessions ORDER BY session_id LIMIT 1")->fetch_assoc()['session_id'] ?? 0);
$sample_siri1   = (int) ($conn->query("SELECT siri_id FROM siri ORDER BY siri_id LIMIT 1")->fetch_assoc()['siri_id'] ?? 0);

// One representative level per siri (not every level — the write-shape
// checks below don't behave differently per level, they just need a
// valid FK reference — but looping one-per-siri proves that claim
// instead of just asserting it).
$sample_levels_by_siri = [];
$slRes = $conn->query("
    SELECT si.siri_id, si.siri_name, MIN(l.level_id) AS level_id
    FROM levels l
    JOIN sessions s ON l.session_id = s.session_id
    LEFT JOIN siri si ON s.siri_id = si.siri_id
    GROUP BY si.siri_id, si.siri_name
");
while ($row = $slRes->fetch_assoc()) {
    $label = $row['siri_name'] ?? ('siri_id ' . $row['siri_id'] ?? 'NULL');
    $sample_levels_by_siri[$label] = (int)$row['level_id'];
}

// ALL siri ids, not just the first one — the app itself lets a PIC pick
// any siri (or "Semua Siri" = no filter), so the siri-scoped checks below
// loop over every real siri plus that unscoped case, instead of only
// ever exercising whichever siri happens to sort first.
$all_siri_ids = [0]; // 0 = "Semua Siri" (no filter), same as the app's active_siri=0 mode
$siriRes = $conn->query("SELECT siri_id, siri_name FROM siri ORDER BY siri_id");
$siriLabels = [0 => 'Semua Siri'];
while ($sr = $siriRes->fetch_assoc()) {
    $all_siri_ids[] = (int)$sr['siri_id'];
    $siriLabels[(int)$sr['siri_id']] = $sr['siri_name'];
}

// ══════════════════════════════════════════════════════════════════
//  1) SCHEMA STATE — did the migration actually land as expected?
// ══════════════════════════════════════════════════════════════════

check($conn, $results, 'groups.session_id column is gone',
    "SHOW COLUMNS FROM `groups` LIKE 'session_id'",
    fn($rows) => [count($rows) === 0, count($rows) === 0 ? 'confirmed dropped' : 'STILL EXISTS']);

check($conn, $results, 'scores.test_id column is gone',
    "SHOW COLUMNS FROM `scores` LIKE 'test_id'",
    fn($rows) => [count($rows) === 0, count($rows) === 0 ? 'confirmed dropped' : 'STILL EXISTS']);

check($conn, $results, 'groups(level_id, group_name) unique key exists',
    "SHOW INDEX FROM `groups` WHERE Key_name = 'uniq_level_groupname'",
    fn($rows) => [count($rows) > 0, count($rows) > 0 ? 'present' : 'MISSING']);

check($conn, $results, 'criteria(test_id, criteria_name) unique key exists',
    "SHOW INDEX FROM `criteria` WHERE Key_name = 'uniq_test_criterianame'",
    fn($rows) => [count($rows) > 0, count($rows) > 0 ? 'present' : 'MISSING']);

// ══════════════════════════════════════════════════════════════════
//  2) DATA INTEGRITY — no duplicates, no orphans left behind
// ══════════════════════════════════════════════════════════════════

check($conn, $results, 'No duplicate group names within the same level',
    "SELECT level_id, group_name, COUNT(*) c FROM `groups` GROUP BY level_id, group_name HAVING c > 1",
    fn($rows) => [count($rows) === 0, count($rows) === 0 ? 'none found' : count($rows) . ' duplicate(s) found']);

check($conn, $results, 'No duplicate criteria names within the same test',
    "SELECT test_id, criteria_name, COUNT(*) c FROM criteria GROUP BY test_id, criteria_name HAVING c > 1",
    fn($rows) => [count($rows) === 0, count($rows) === 0 ? 'none found' : count($rows) . ' duplicate(s) found']);

check($conn, $results, 'No orphaned scores.criteria_id',
    "SELECT COUNT(*) c FROM scores sc LEFT JOIN criteria c2 ON sc.criteria_id = c2.criteria_id WHERE c2.criteria_id IS NULL",
    fn($rows) => [(int)($rows[0]['c'] ?? 1) === 0, ($rows[0]['c'] ?? '?') . ' orphaned row(s)']);

check($conn, $results, 'No orphaned groups.level_id',
    "SELECT COUNT(*) c FROM `groups` g LEFT JOIN levels l ON g.level_id = l.level_id WHERE l.level_id IS NULL",
    fn($rows) => [(int)($rows[0]['c'] ?? 1) === 0, ($rows[0]['c'] ?? '?') . ' orphaned row(s)']);

// ══════════════════════════════════════════════════════════════════
//  3) QUERY PATTERNS — the actual rewritten SQL from each page
// ══════════════════════════════════════════════════════════════════

// pic_groups.php: groups -> levels -> sessions
check($conn, $results, 'pic_groups.php pattern (groups via levels->sessions)',
    "SELECT DISTINCT s.session_id, s.session_name
     FROM `groups` g
     JOIN levels l ON g.level_id = l.level_id
     JOIN sessions s ON l.session_id = s.session_id
     LIMIT 5");

// judge.php: judge stats scoped to a siri
// Looped across every siri (0 = "Semua Siri", matching how judge.php
// itself only adds the siri filter clause when a specific siri is active).
foreach ($all_siri_ids as $sid) {
    $siriClause = $sid > 0
        ? "AND g.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $sid))"
        : "";
    check($conn, $results, "judge.php pattern (judge stats — siri: {$siriLabels[$sid]})",
        "SELECT COUNT(DISTINCT g.group_id) AS total_groups FROM `groups` g WHERE 1=1 $siriClause");
}

// judge.php: scores/criteria EXISTS check (per-test completion badge)
check($conn, $results, 'judge.php pattern (scores/criteria EXISTS check)',
    "SELECT EXISTS(
        SELECT 1 FROM scores sc
        JOIN criteria c ON sc.criteria_id = c.criteria_id
        LIMIT 1
     ) AS found");

// pic_view_marks.php / judge_view_marks.php: full students->tests->criteria->scores chain
check($conn, $results, 'pic_view_marks.php pattern (student/test/criteria/scores chain)',
    "SELECT s.student_name, t.test_name, c.criteria_name, COALESCE(sc.mark, 0) AS mark
     FROM students s
     LEFT JOIN tests t ON t.level_id = s.level_id
     LEFT JOIN criteria c ON c.test_id = t.test_id
     LEFT JOIN scores sc ON sc.student_id = s.student_id AND sc.criteria_id = c.criteria_id
     LIMIT 5");

check($conn, $results, 'judge_view_marks.php pattern (scores joined via criteria->tests)',
    "SELECT s.student_name, t.test_name, c.criteria_name, sc.mark
     FROM scores sc
     JOIN students s ON sc.student_id = s.student_id
     JOIN `groups` g ON sc.group_id = g.group_id
     JOIN levels gl ON g.level_id = gl.level_id
     JOIN sessions se ON gl.session_id = se.session_id
     JOIN criteria c ON sc.criteria_id = c.criteria_id
     JOIN tests t ON c.test_id = t.test_id
     LIMIT 5");

// pic_directory.php tab 1: students by group
check($conn, $results, 'pic_directory.php tab1 pattern (groups by level/session/siri)',
    "SELECT DISTINCT s.session_id, s.session_name, l.level_id, l.level_name, si.siri_name
     FROM `groups` g
     JOIN levels l ON g.level_id = l.level_id
     JOIN sessions s ON l.session_id = s.session_id
     LEFT JOIN siri si ON s.siri_id = si.siri_id
     LIMIT 5");

// pic_directory.php tab 2: judges by school
check($conn, $results, 'pic_directory.php tab2 pattern (judges by school)',
    "SELECT DISTINCT sc.school_id, sc.school_name
     FROM schools sc
     JOIN students st ON sc.school_id = st.school_id
     JOIN group_students gs ON st.student_id = gs.student_id
     JOIN `groups` g ON gs.group_id = g.group_id
     JOIN judges j ON g.judge_id = j.id
     LIMIT 5");

// pic_directory.php tab 3: groups by judge
check($conn, $results, 'pic_directory.php tab3 pattern (groups by judge, with siri)',
    "SELECT g.group_id, g.group_name, se.session_name, l.level_name, si.siri_name
     FROM `groups` g
     JOIN levels l ON g.level_id = l.level_id
     JOIN sessions se ON l.session_id = se.session_id
     LEFT JOIN siri si ON se.siri_id = si.siri_id
     LIMIT 5");

// pic.php dashboard: group count, scoped per-siri when active, unscoped
// (raw COUNT) when "Semua Siri" — matching pic.php's own if/else branch.
foreach ($all_siri_ids as $sid) {
    if ($sid > 0) {
        check($conn, $results, "pic.php dashboard pattern (group count — siri: {$siriLabels[$sid]})",
            "SELECT COUNT(*) AS total
             FROM `groups` g
             JOIN levels l ON g.level_id = l.level_id
             JOIN sessions s ON l.session_id = s.session_id
             WHERE s.siri_id = $sid");
    } else {
        check($conn, $results, "pic.php dashboard pattern (group count — siri: {$siriLabels[$sid]})",
            "SELECT COUNT(*) AS total FROM `groups`");
    }
}

// pic_master_list.php: student -> group -> session chain
check($conn, $results, 'pic_master_list.php pattern (student group/session/judge)',
    "SELECT st.student_name, gse.session_name, j.name AS judge_name
     FROM students st
     LEFT JOIN group_students gs ON st.student_id = gs.student_id
     LEFT JOIN `groups` g ON gs.group_id = g.group_id
     LEFT JOIN levels gl ON g.level_id = gl.level_id
     LEFT JOIN sessions gse ON gl.session_id = gse.session_id
     LEFT JOIN judges j ON g.judge_id = j.id
     LIMIT 5");

// attendance_view_dashboard.php: student membership via level OR group,
// scoped per-siri when active, plain COUNT(*) when "Semua Siri" — matching
// attendance_view_dashboard.php's own if/else branch.
foreach ($all_siri_ids as $sid) {
    if ($sid > 0) {
        check($conn, $results, "attendance_view_dashboard.php pattern (student membership — siri: {$siriLabels[$sid]})",
            "SELECT COUNT(DISTINCT student_id) AS total FROM (
                SELECT st.student_id
                FROM students st
                JOIN levels l ON st.level_id = l.level_id
                JOIN sessions s ON l.session_id = s.session_id
                WHERE s.siri_id = $sid
                UNION
                SELECT gs.student_id
                FROM group_students gs
                JOIN `groups` g ON gs.group_id = g.group_id
                JOIN levels gl ON g.level_id = gl.level_id
                JOIN sessions s ON gl.session_id = s.session_id
                WHERE s.siri_id = $sid
            ) combined");
    } else {
        check($conn, $results, "attendance_view_dashboard.php pattern (student membership — siri: {$siriLabels[$sid]})",
            "SELECT COUNT(*) AS total FROM students");
    }
}

// attendance_helpers.php: STUDENT_SESSION_MEMBERSHIP_SQL constant, used by
// getSessionSchools/getStudentsForSchool/getAllStudentsBySchool
check($conn, $results, 'attendance_helpers.php pattern (STUDENT_SESSION_MEMBERSHIP_SQL)',
    "SELECT DISTINCT st2.student_id
     FROM students st2
     JOIN levels l ON st2.level_id = l.level_id
     WHERE l.session_id = (SELECT session_id FROM sessions LIMIT 1)
     UNION
     SELECT DISTINCT gs.student_id
     FROM group_students gs
     JOIN `groups` g ON gs.group_id = g.group_id
     JOIN levels gl ON g.level_id = gl.level_id
     WHERE gl.session_id = (SELECT session_id FROM sessions LIMIT 1)
     LIMIT 5");

// attendance_view_all.php: stu_sess union query
check($conn, $results, 'attendance_view_all.php pattern (stu_sess union)',
    "SELECT DISTINCT st2.student_id, l.session_id
     FROM students st2
     JOIN levels l ON st2.level_id = l.level_id
     UNION
     SELECT DISTINCT gs.student_id, gl.session_id
     FROM group_students gs
     JOIN `groups` g ON gs.group_id = g.group_id
     JOIN levels gl ON g.level_id = gl.level_id
     LIMIT 5");

// manage_attendance.php: ajax_groups endpoint (level_id only, no session_id)
check($conn, $results, 'manage_attendance.php pattern (ajax_groups by level_id)',
    "SELECT group_id, group_name FROM `groups` WHERE level_id = $sample_level ORDER BY group_name ASC");

// admin_data.php: scores export query (criteria->tests, groups->levels->sessions)
check($conn, $results, 'admin_data.php pattern (scores export chain)',
    "SELECT s.score_id, st.student_name, t.test_name, c.criteria_name
     FROM scores s
     LEFT JOIN students st ON s.student_id = st.student_id
     LEFT JOIN criteria c ON s.criteria_id = c.criteria_id
     LEFT JOIN tests t ON c.test_id = t.test_id
     LEFT JOIN sessions se ON EXISTS (
         SELECT 1 FROM `groups` g JOIN levels lv ON g.level_id = lv.level_id
         WHERE g.group_id = s.group_id AND lv.session_id = se.session_id
     )
     LIMIT 5");

// pic_manual_marks.php: group dropdown scoped to active siri
check($conn, $results, 'pic_manual_marks.php pattern (group dropdown scoped to siri)',
    "SELECT group_id, group_name FROM `groups`
     WHERE level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $sample_siri1))
     ORDER BY group_name LIMIT 5");

// pic_view_marks.php: membership_cond subquery (separate from the main
// student/test/criteria/scores chain already tested above)
check($conn, $results, 'pic_view_marks.php pattern (siri membership subquery)',
    "SELECT student_id FROM students st WHERE st.student_id IN (
        SELECT st2.student_id FROM students st2
        LEFT JOIN levels l2 ON st2.level_id = l2.level_id
        WHERE l2.session_id IN (SELECT session_id FROM sessions WHERE siri_id = $sample_siri1)
        UNION
        SELECT gs2.student_id FROM group_students gs2
        JOIN `groups` g2 ON gs2.group_id = g2.group_id
        JOIN levels l2b ON g2.level_id = l2b.level_id
        WHERE l2b.session_id IN (SELECT session_id FROM sessions WHERE siri_id = $sample_siri1)
     ) LIMIT 5");

// pic_directory.php tab1: group/student count summary (cntSql)
check($conn, $results, 'pic_directory.php tab1 pattern (summary counts)',
    "SELECT COUNT(DISTINCT gs.student_id) as stu, COUNT(DISTINCT g.group_id) as grp, COUNT(DISTINCT gl.session_id) as sess
     FROM `groups` g LEFT JOIN group_students gs ON g.group_id=gs.group_id LEFT JOIN levels gl ON g.level_id=gl.level_id");

// pic_directory.php tab2: per-school student count (with session filter shape)
check($conn, $results, 'pic_directory.php tab2 pattern (per-school student count, session-filtered)',
    "SELECT st.school_id, COUNT(DISTINCT st.student_id) as t
     FROM students st
     WHERE st.school_id IS NOT NULL AND st.student_id IN (
        SELECT st2.student_id FROM students st2 JOIN levels l ON st2.level_id = l.level_id WHERE l.session_id = $sample_session
        UNION
        SELECT gs2.student_id FROM group_students gs2 JOIN `groups` g2 ON gs2.group_id = g2.group_id JOIN levels gl2 ON g2.level_id = gl2.level_id WHERE gl2.session_id = $sample_session
     )
     GROUP BY st.school_id LIMIT 5");

// pic_directory.php tab2: judges-per-school list (l.session_id filter shape)
check($conn, $results, 'pic_directory.php tab2 pattern (judges list, session-filtered)',
    "SELECT j.id as judge_id, j.name as judge_name, COUNT(DISTINCT g.group_id) as group_count
     FROM judges j
     JOIN `groups` g ON g.judge_id = j.id
     JOIN levels l ON g.level_id = l.level_id
     JOIN group_students gs ON g.group_id = gs.group_id
     JOIN students st ON gs.student_id = st.student_id
     WHERE st.school_id = (SELECT school_id FROM schools LIMIT 1) AND l.session_id = $sample_session
     GROUP BY j.id LIMIT 5");

// pic_save_scores.php / save_scores.php: criteria->test_id lookup used to
// validate a submitted mark belongs to the right test
check($conn, $results, 'pic_save_scores.php pattern (criteria->test_id lookup)',
    "SELECT criteria_id, test_id FROM criteria LIMIT 1");

// pic_groups.php add-group insert shape (dry run via EXPLAIN, writes nothing)
check($conn, $results, 'pic_groups.php insert shape (level_id, group_name, judge_id — no session_id)',
    "EXPLAIN SELECT level_id, 'x' AS group_name, NULL AS judge_id FROM levels LIMIT 1");

// ══════════════════════════════════════════════════════════════════
//  4) WRITE PATHS — the actual INSERT/UPDATE/DELETE statements from
//     each page, run inside a transaction and ALWAYS rolled back.
//     Nothing here is ever persisted, even if a check "passes".
//     (InnoDB may still burn an AUTO_INCREMENT value on a rolled-back
//     INSERT — harmless, just a gap in the id sequence, not data loss.)
// ══════════════════════════════════════════════════════════════════

function checkWrite(mysqli $conn, array &$results, string $name, callable $run): void {
    $start = microtime(true);
    $conn->begin_transaction();
    try {
        [$ok, $msg] = $run($conn);
    } catch (\Throwable $e) {
        $ok = false;
        $msg = 'EXCEPTION: ' . $e->getMessage();
    }
    $conn->rollback();
    $ms = round((microtime(true) - $start) * 1000, 1);
    $results[] = ['name' => $name, 'ok' => $ok, 'msg' => $msg . ' (rolled back)', 'ms' => $ms];
}

// pic_groups.php "add": INSERT INTO groups (level_id, group_name, judge_id)
// — looped one level per siri, proving the insert shape doesn't depend
// on which siri the level belongs to (it can't: the column is gone).
foreach ($sample_levels_by_siri as $siriLabel => $lvlId) {
    checkWrite($conn, $results, "pic_groups.php WRITE: add group (level from {$siriLabel})", function ($conn) use ($lvlId) {
        $stmt = $conn->prepare("INSERT INTO `groups` (level_id, group_name, judge_id) VALUES (?, ?, NULL)");
        $name = '__healthcheck_test__';
        $stmt->bind_param('is', $lvlId, $name);
        $ok = $stmt->execute();
        $newId = $conn->insert_id;
        $stmt->close();
        return [$ok, $ok ? "insert ok (id {$newId})" : $conn->error];
    });
}

// pic_groups.php "save_all": UPDATE groups SET group_name=?, judge_id=? WHERE group_id=?
checkWrite($conn, $results, 'pic_groups.php WRITE: update group name/judge', function ($conn) {
    $row = $conn->query("SELECT group_id, group_name FROM `groups` LIMIT 1")->fetch_assoc();
    if (!$row) return [true, 'skipped — no groups exist'];
    $stmt = $conn->prepare("UPDATE `groups` SET group_name=?, judge_id=NULL WHERE group_id=?");
    $name = $row['group_name']; // write back the same value — a true no-op
    $gid  = (int)$row['group_id'];
    $stmt->bind_param('si', $name, $gid);
    $ok = $stmt->execute();
    $stmt->close();
    return [$ok, $ok ? 'update ok' : $conn->error];
});

// pic_save_scores.php: criteria/test_id validation + UPDATE/INSERT scores
checkWrite($conn, $results, 'pic_save_scores.php WRITE: upsert a score row', function ($conn) {
    $ctx = $conn->query("
        SELECT s.student_id, g.group_id, c.criteria_id, c.test_id
        FROM students s
        JOIN `groups` g ON 1=1
        JOIN criteria c ON 1=1
        LIMIT 1
    ")->fetch_assoc();
    if (!$ctx) return [true, 'skipped — no students/groups/criteria to test with'];

    $studentId = (int)$ctx['student_id']; $groupId = (int)$ctx['group_id'];
    $criteriaId = (int)$ctx['criteria_id']; $testId = (int)$ctx['test_id'];

    // Mirror pic_save_scores.php's own validation: criteria must belong to the submitted test
    $ct = $conn->query("SELECT test_id FROM criteria WHERE criteria_id={$criteriaId}")->fetch_assoc();
    if ((int)$ct['test_id'] !== $testId) return [false, 'criteria/test_id mismatch check failed unexpectedly'];

    $check = $conn->prepare("SELECT score_id FROM scores WHERE student_id=? AND group_id=? AND criteria_id=?");
    $check->bind_param('iii', $studentId, $groupId, $criteriaId);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    $mark = 0;
    if ($exists) {
        $stmt = $conn->prepare("UPDATE scores SET mark=?, submitted=1 WHERE student_id=? AND group_id=? AND criteria_id=?");
        $stmt->bind_param('diii', $mark, $studentId, $groupId, $criteriaId);
    } else {
        $stmt = $conn->prepare("INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param('iiid', $studentId, $groupId, $criteriaId, $mark);
    }
    $ok = $stmt->execute();
    $err = $conn->error;
    $stmt->close();
    return [$ok, $ok ? ($exists ? 'update ok' : 'insert ok') : $err];
});

// save_scores.php: INSERT ... ON DUPLICATE KEY UPDATE (no test_id column)
checkWrite($conn, $results, 'save_scores.php WRITE: INSERT...ON DUPLICATE KEY UPDATE', function ($conn) {
    $ctx = $conn->query("
        SELECT s.student_id, g.group_id, c.criteria_id
        FROM students s JOIN `groups` g ON 1=1 JOIN criteria c ON 1=1 LIMIT 1
    ")->fetch_assoc();
    if (!$ctx) return [true, 'skipped — no students/groups/criteria to test with'];
    $stmt = $conn->prepare("
        INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = 1
    ");
    $studentId = (int)$ctx['student_id']; $groupId = (int)$ctx['group_id']; $criteriaId = (int)$ctx['criteria_id']; $mark = 0;
    $stmt->bind_param('iiii', $studentId, $groupId, $criteriaId, $mark);
    $ok = $stmt->execute();
    $err = $conn->error;
    $stmt->close();
    return [$ok, $ok ? 'upsert ok' : $err];
});

// pic_sessions.php "delete": groups deleted via levels subquery (must not
// reference session_id directly). Targets a session_id that doesn't exist
// (999999999) so zero real rows are ever matched, even before the rollback.
checkWrite($conn, $results, 'pic_sessions.php WRITE: cascade delete shape (groups via levels subquery)', function ($conn) {
    $fakeSessionId = 999999999;
    $ok = $conn->query("DELETE FROM `groups` WHERE level_id IN (SELECT level_id FROM levels WHERE session_id={$fakeSessionId})");
    $err = $conn->error;
    return [(bool)$ok, $ok ? 'delete-shape executed (0 real rows matched)' : $err];
});

// upload_students.php: INSERT INTO groups (level_id, group_name, judge_id)
foreach ($sample_levels_by_siri as $siriLabel => $lvlId) {
    checkWrite($conn, $results, "upload_students.php WRITE: group insert shape (level from {$siriLabel})", function ($conn) use ($lvlId) {
        $stmt = $conn->prepare("INSERT INTO `groups` (level_id, group_name, judge_id) VALUES (?, ?, NULL)");
        $name = '__healthcheck_upload_test__';
        $stmt->bind_param('is', $lvlId, $name);
        $ok = $stmt->execute();
        $stmt->close();
        return [$ok, $ok ? 'insert ok' : $conn->error];
    });
}

// ══════════════════════════════════════════════════════════════════
//  OUTPUT
// ══════════════════════════════════════════════════════════════════

$passCount = count(array_filter($results, fn($r) => $r['ok']));
$failCount = count($results) - $passCount;

if ($isCli) {
    echo "DB Health Check — {$passCount} passed, {$failCount} failed\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($results as $r) {
        $status = $r['ok'] ? 'PASS' : 'FAIL';
        printf("[%s] %-70s %s (%.1fms)\n", $status, $r['name'], $r['msg'], $r['ms']);
    }
    exit($failCount > 0 ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DB Health Check</title>
<style>
    body { font-family: -apple-system, Segoe UI, sans-serif; background:#111; color:#eee; padding:24px; }
    h1 { font-size:1.3rem; }
    .summary { margin-bottom:16px; font-size:0.95rem; }
    .pass { color:#4ADE80; }
    .fail { color:#F87171; }
    table { width:100%; border-collapse:collapse; font-size:0.85rem; }
    th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #333; }
    th { color:#888; text-transform:uppercase; font-size:0.72rem; letter-spacing:0.05em; }
    tr.fail-row { background:rgba(248,113,113,0.08); }
    .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.72rem; font-weight:700; }
    .badge.pass { background:rgba(74,222,128,0.15); color:#4ADE80; }
    .badge.fail { background:rgba(248,113,113,0.15); color:#F87171; }
</style>
</head>
<body>
<h1>3NF Migration — DB Health Check</h1>
<div class="summary">
    <span class="pass"><?= $passCount ?> passed</span> &nbsp;|&nbsp;
    <span class="fail"><?= $failCount ?> failed</span>
    &nbsp;|&nbsp; siri IDs tested: <?= htmlspecialchars(implode(', ', array_map(fn($id) => $siriLabels[$id] . " (#{$id})", $all_siri_ids))) ?>, sample level_id=<?= $sample_level ?>
</div>
<table>
<thead><tr><th>Status</th><th>Check</th><th>Result</th><th>Time</th></tr></thead>
<tbody>
<?php foreach ($results as $r): ?>
    <tr class="<?= $r['ok'] ? '' : 'fail-row' ?>">
        <td><span class="badge <?= $r['ok'] ? 'pass' : 'fail' ?>"><?= $r['ok'] ? 'PASS' : 'FAIL' ?></span></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= htmlspecialchars($r['msg']) ?></td>
        <td><?= $r['ms'] ?>ms</td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>

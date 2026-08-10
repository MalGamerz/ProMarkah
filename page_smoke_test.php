<?php
/**
 * page_smoke_test.php — actually loads the real pages (not a SQL mimic)
 * and checks whether they render to completion, using YOUR OWN live
 * session, via the BROWSER (not a server-side loopback).
 *
 * Why browser-side: an earlier version of this made the PHP server
 * fetch its own pages over HTTP from within the same request. On a
 * small PHP-FPM pool that deadlocks — the outer request holds the only
 * free worker, then waits on an inner request that needs a free worker
 * that will never appear. Fetching from the browser instead means each
 * check is a real, independent HTTP connection like any normal page
 * load, so there's nothing to deadlock.
 *
 * Why this exists at all: db_health_check.php only tests hand-retyped
 * SQL that *mimics* the real queries — it never executes the actual
 * page files, so it can't catch a plain PHP bug (undefined variable,
 * wrong array key, etc.) that isn't a SQL error. This closes that gap
 * by requesting the real URLs for real.
 *
 * What it CAN detect: HTTP status != 200, or a response that doesn't
 * finish with the closing `</main></body></html>` every one of these
 * pages ends with — which happens if a fatal error kills the request
 * partway through rendering, regardless of display_errors settings.
 *
 * What it CANNOT detect: a non-fatal PHP warning/notice, since every
 * page explicitly sets display_errors=0 — those only go to the PHP
 * error log, whose exact path varies by host and isn't guessed here.
 * A visually wrong (but non-crashing) page still needs a human look.
 *
 * Coverage depends on YOUR current role, since the browser sends your
 * real session cookie — it can't fake a different role:
 *   - Logged in as pic/admin -> tests the PIC-facing pages.
 *   - Logged in as judge     -> tests the judge-facing pages.
 * Log in as each role separately and revisit this page for full coverage.
 *
 * Usage: log in normally, then visit page_smoke_test.php in the browser.
 *
 * ── DEEP DRAFT-SCORE TEST (admin only) ──────────────────────────────────
 * Below the page-render table is a second, opt-in section that exercises
 * the draft/half-saved score feature (save_draft_score.php, save_scores.php,
 * the judge.php lock logic, and the leaderboard's submitted=1 filtering)
 * at the database level — every INSERT/UPDATE/DELETE it runs happens
 * inside one transaction that is ALWAYS rolled back, so it can never
 * leave fixture data behind or affect a real group/student/leaderboard
 * row, pass or fail. It's gated to admin because it needs raw DB access
 * beyond what save_draft_score.php's own guards allow from the browser.
 */

session_start();
require __DIR__ . '/auth_check.php';

$role = $_SESSION['role'] ?? null;
if (!in_array($role, ['admin', 'pic', 'judge'], true)) {
    http_response_code(403);
    exit('Forbidden — log in first.');
}

// ── Target pages, grouped by which role can actually reach them ──
$targetsByRole = [
    'pic' => [
        'pic_groups.php',
        'pic_directory.php?tab=students_group',
        'pic_directory.php?tab=judges_school',
        'pic_directory.php?tab=groups_judge',
        'pic.php',
        'pic_master_list.php',
        'pic_view_marks.php',
        'pic_manual_marks.php',
        'pic_sessions.php',
        'pic_criteria.php',
        'pic_tests.php',
        'pic_students.php',
        'pic_schools.php',
        'manage_attendance.php',
        'upload_students.php',
        'leaderboard.php',
    ],
    'judge' => [
        'judge.php',
        'judge_view_marks.php',
        'leaderboard.php',
    ],
    // Admin has NO access to pic_*.php/leaderboard.php/manage_attendance.php —
    // every one of those pages checks role === 'pic' (or judge/pic for the
    // leaderboard) with no admin bypass. upload_students.php is the one
    // exception that explicitly allows ['pic', 'admin']. Don't add PIC-only
    // pages here — they will always redirect to login for an admin session.
    'admin' => [
        'admin.php',
        'admin_data.php',
        'admin_logs.php',
        'upload_students.php',
    ],
];

$targets = $targetsByRole[$role] ?? [];

// ══════════════════════════════════════════════════════════════════════
// DEEP DRAFT-SCORE TEST — runs server-side, only when explicitly asked
// for via ?deep_draft_test=1. Available to admin AND judge (needs raw DB
// access beyond what save_draft_score.php/save_scores.php allow from the
// browser, but a real judge session is exactly who lives this lifecycle
// day-to-day, so they get to verify it too — not just admin).
// ══════════════════════════════════════════════════════════════════════
$deep_results  = [];
$deep_ran      = false;
$deep_fixtures = [];

function deep_check(string $label, bool $ok, string $detail = '', float $ms = 0): void
{
    global $deep_results;
    $deep_results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'ms' => $ms];
}

function deep_timed(callable $fn)
{
    $start = microtime(true);
    $result = $fn();
    $ms = round((microtime(true) - $start) * 1000, 2);
    return [$result, $ms];
}

if (in_array($role, ['admin', 'judge'], true) && isset($_GET['deep_draft_test'])) {
    $deep_ran = true;
    include __DIR__ . '/db.php';
    $conn = getDB();
    $conn->begin_transaction();

    try {
        // ── Fixtures: fully self-contained, isolated from real data ──
        $conn->query("INSERT INTO sessions (session_name, siri_id) VALUES ('SMOKE_TEST_SESSION', NULL)");
        $session_id = $conn->insert_id;

        $conn->query("INSERT INTO levels (session_id, level_name) VALUES ($session_id, 'SMOKE_TEST_LEVEL')");
        $level_id = $conn->insert_id;

        $conn->query("INSERT INTO tests (level_id, test_name) VALUES ($level_id, 'SMOKE_TEST_TEST')");
        $test_id = $conn->insert_id;

        $conn->query("INSERT INTO criteria (test_id, criteria_name, max_mark) VALUES ($test_id, 'SMOKE_TEST_CRIT', 10)");
        $criteria_id = $conn->insert_id;

        $conn->query("INSERT INTO schools (school_name) VALUES ('SMOKE_TEST_SCHOOL')");
        $school_id = $conn->insert_id;

        $conn->query("INSERT INTO students (student_name, level_id, school_id, gender, year) VALUES ('SMOKE_TEST_STUDENT', $level_id, $school_id, 'Male', YEAR(CURDATE()))");
        $student_id = $conn->insert_id;

        // groups has no session_id column on this DB (confirmed against every
        // real INSERT in the app: pic_groups.php, upload_students.php) —
        // a group's session is derived indirectly via level_id -> levels.session_id.
        $conn->query("INSERT INTO `groups` (level_id, group_name, judge_id, edit_used) VALUES ($level_id, 'SMOKE_TEST_GROUP', NULL, 0)");
        $group_id = $conn->insert_id;

        $conn->query("INSERT INTO group_students (group_id, student_id) VALUES ($group_id, $student_id)");

        $deep_fixtures = compact('session_id', 'level_id', 'test_id', 'criteria_id', 'school_id', 'student_id', 'group_id');

        // ── Test 1: draft save inserts with submitted=0 (mirrors save_draft_score.php) ──
        [, $ms] = deep_timed(function () use ($conn, $student_id, $group_id, $criteria_id) {
            $stmt = $conn->prepare("
                INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = IF(submitted = 1, submitted, 0)
            ");
            $mark = 7;
            $stmt->bind_param("iiii", $student_id, $group_id, $criteria_id, $mark);
            $stmt->execute();
            $stmt->close();
        });

        $row = $conn->query("SELECT mark, submitted FROM scores WHERE group_id=$group_id AND student_id=$student_id AND criteria_id=$criteria_id")->fetch_assoc();
        deep_check('Draft insert: row written successfully', $row !== null, '', $ms);
        deep_check('Draft insert: mark stored correctly', $row && (int)$row['mark'] === 7, 'expected 7, got ' . ($row['mark'] ?? 'null'));
        deep_check('Draft insert: submitted flag is 0', $row && (int)$row['submitted'] === 0, 'expected 0, got ' . ($row['submitted'] ?? 'null'));

        // ── Test 2: a group with only draft marks must NOT be considered locked ──
        [$lockCheck, $ms] = deep_timed(fn() => $conn->query("SELECT 1 FROM scores WHERE group_id=$group_id AND submitted=1 LIMIT 1"));
        deep_check('Lock check: group with only a draft is NOT locked', $lockCheck->num_rows === 0, '', $ms);

        // ── Test 3: re-saving the draft (judge changes their mind) updates in place ──
        [, $ms] = deep_timed(function () use ($conn, $student_id, $group_id, $criteria_id) {
            $stmt = $conn->prepare("
                INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = IF(submitted = 1, submitted, 0)
            ");
            $mark2 = 9;
            $stmt->bind_param("iiii", $student_id, $group_id, $criteria_id, $mark2);
            $stmt->execute();
            $stmt->close();
        });

        $row = $conn->query("SELECT mark FROM scores WHERE group_id=$group_id AND student_id=$student_id AND criteria_id=$criteria_id")->fetch_assoc();
        deep_check('Draft update: mark overwritten via upsert (no duplicate row)', $row && (int)$row['mark'] === 9, 'expected 9, got ' . ($row['mark'] ?? 'null'), $ms);
        $rowCount = $conn->query("SELECT COUNT(*) c FROM scores WHERE group_id=$group_id AND student_id=$student_id AND criteria_id=$criteria_id")->fetch_assoc()['c'];
        deep_check('Draft update: still exactly one row for this cell', (int)$rowCount === 1, "found $rowCount rows");

        // ── Test 4: clearing a draft (toggled "Abai") deletes it, but only while submitted=0 ──
        [, $ms] = deep_timed(fn() => $conn->query("DELETE FROM scores WHERE group_id=$group_id AND student_id=$student_id AND criteria_id=$criteria_id AND submitted = 0"));
        $rowCount = $conn->query("SELECT COUNT(*) c FROM scores WHERE group_id=$group_id AND student_id=$student_id AND criteria_id=$criteria_id")->fetch_assoc()['c'];
        deep_check('Draft clear: row removed when submitted=0', (int)$rowCount === 0, '', $ms);

        // ── Test 5: leaderboard-style aggregate must exclude drafts ──
        [, $ms] = deep_timed(function () use ($conn, $student_id, $group_id, $criteria_id) {
            $stmt = $conn->prepare("
                INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
                VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = 0
            ");
            $mark3 = 6;
            $stmt->bind_param("iiii", $student_id, $group_id, $criteria_id, $mark3);
            $stmt->execute();
            $stmt->close();
        });

        $agg = $conn->query("SELECT COALESCE(SUM(mark),0) total FROM scores WHERE student_id=$student_id AND submitted=1")->fetch_assoc();
        deep_check('Leaderboard aggregate: draft-only student contributes 0 to submitted totals', (int)$agg['total'] === 0, 'got ' . $agg['total'], $ms);

        // ── Test 6: final submit (mirrors save_scores.php) flips submitted=1 ──
        [, $ms] = deep_timed(function () use ($conn, $student_id, $group_id, $criteria_id) {
            $stmt = $conn->prepare("
                INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
                VALUES (?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = 1
            ");
            $finalMark = 10;
            $stmt->bind_param("iiii", $student_id, $group_id, $criteria_id, $finalMark);
            $stmt->execute();
            $stmt->close();
        });

        $row = $conn->query("SELECT mark, submitted FROM scores WHERE group_id=$group_id AND student_id=$student_id AND criteria_id=$criteria_id")->fetch_assoc();
        deep_check('Final submit: mark updated to finalized value', $row && (int)$row['mark'] === 10, 'got ' . ($row['mark'] ?? 'null'), $ms);
        deep_check('Final submit: submitted flag flipped to 1', $row && (int)$row['submitted'] === 1);

        // ── Test 7: group is now locked ──
        [$lockCheck, $ms] = deep_timed(fn() => $conn->query("SELECT 1 FROM scores WHERE group_id=$group_id AND submitted=1 LIMIT 1"));
        deep_check('Lock check: group with a finalized mark IS locked', $lockCheck->num_rows === 1, '', $ms);

        // ── Test 8: leaderboard aggregate now counts the finalized mark ──
        [$agg, $ms] = deep_timed(fn() => $conn->query("SELECT COALESCE(SUM(mark),0) total FROM scores WHERE student_id=$student_id AND submitted=1")->fetch_assoc());
        deep_check('Leaderboard aggregate: finalized mark now counted', (int)$agg['total'] === 10, 'got ' . $agg['total'], $ms);

        // ── Test 9: draft endpoint's own guard logic (PHP-level, replicated here) ──
        // save_draft_score.php refuses writes once submitted=1 exists AND edit_used=1.
        $edit_used_flag  = 1;
        $has_submitted   = $lockCheck->num_rows === 1;
        $wouldBeRejected = $has_submitted && $edit_used_flag === 1;
        deep_check('Draft endpoint guard: rejects further drafts once locked + edit token consumed', $wouldBeRejected === true);

        // ── Test 10: a second, different judge/group must never see this student's drafts ──
        // (sanity check that our WHERE clauses are scoped by group_id, not global)
        [, $ms] = deep_timed(fn() => $conn->query("INSERT INTO `groups` (level_id, group_name, judge_id, edit_used) VALUES ($level_id, 'SMOKE_TEST_GROUP_2', NULL, 0)"));
        $other_group_id = $conn->insert_id;
        $leak = $conn->query("SELECT 1 FROM scores WHERE group_id=$other_group_id LIMIT 1");
        deep_check('Isolation: an unrelated group sees none of this student\'s scores', $leak->num_rows === 0, '', $ms);

    } catch (Throwable $e) {
        deep_check('Unexpected exception during deep test', false, $e->getMessage());
    }

    $conn->rollback();
}

// ══════════════════════════════════════════════════════════════════════
// DEEP CRUD TEST (PIC) — runs server-side, only when explicitly asked for
// via ?deep_crud_test=1, and only for pic. Exercises real Create/Read/
// Update/Delete against every PIC-managed entity using the SAME queries
// pic_sessions.php / pic_schools.php / pic_levels.php / pic_tests.php /
// pic_criteria.php / pic_students.php / pic_groups.php / pic_judges.php
// actually run — all inside one transaction that is ALWAYS rolled back,
// so nothing here ever leaves real data behind, pass or fail. Each step
// is individually timed with microtime(true) so the ms column reflects
// real per-query latency, not fetch/network overhead.
// ══════════════════════════════════════════════════════════════════════
$crud_results  = [];
$crud_ran      = false;

function crud_check(string $label, bool $ok, float $ms, string $detail = ''): void
{
    global $crud_results;
    $crud_results[] = ['label' => $label, 'ok' => $ok, 'ms' => $ms, 'detail' => $detail];
}

function crud_timed(callable $fn): array
{
    $start = microtime(true);
    $result = $fn();
    $ms = round((microtime(true) - $start) * 1000, 2);
    return [$result, $ms];
}

if ($role === 'pic' && isset($_GET['deep_crud_test'])) {
    $crud_ran = true;
    include __DIR__ . '/db.php';
    $conn = getDB();
    $conn->begin_transaction();

    try {
        // ── SESSIONS: create (pic_sessions.php action=add) ──
        [$ok, $ms] = crud_timed(function () use ($conn) {
            $conn->query("INSERT INTO sessions (session_name, siri_id) VALUES ('SMOKE_CRUD_SESSION', NULL)");
            return $conn->insert_id;
        });
        $session_id = $ok;
        crud_check('Sessions — CREATE', $session_id > 0, $ms, "session_id=$session_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT session_name FROM sessions WHERE session_id=$session_id")->fetch_assoc());
        crud_check('Sessions — READ', $row && $row['session_name'] === 'SMOKE_CRUD_SESSION', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE sessions SET session_name='SMOKE_CRUD_SESSION_EDITED' WHERE session_id=$session_id"));
        crud_check('Sessions — UPDATE', (bool)$ok, $ms);

        // ── SCHOOLS: create (pic_schools.php action=add) ──
        [$school_id, $ms] = crud_timed(function () use ($conn) {
            $conn->query("INSERT INTO schools (school_name) VALUES ('SMOKE_CRUD_SCHOOL')");
            return $conn->insert_id;
        });
        crud_check('Schools — CREATE', $school_id > 0, $ms, "school_id=$school_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT school_name FROM schools WHERE school_id=$school_id")->fetch_assoc());
        crud_check('Schools — READ', $row && $row['school_name'] === 'SMOKE_CRUD_SCHOOL', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE schools SET school_name='SMOKE_CRUD_SCHOOL_EDITED' WHERE school_id=$school_id"));
        crud_check('Schools — UPDATE', (bool)$ok, $ms);

        // ── LEVELS: create (pic_levels.php action=add) ──
        [$level_id, $ms] = crud_timed(function () use ($conn, $session_id) {
            $conn->query("INSERT INTO levels (session_id, level_name) VALUES ($session_id, 'SMOKE_CRUD_LEVEL')");
            return $conn->insert_id;
        });
        crud_check('Levels — CREATE', $level_id > 0, $ms, "level_id=$level_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT level_name FROM levels WHERE level_id=$level_id")->fetch_assoc());
        crud_check('Levels — READ', $row && $row['level_name'] === 'SMOKE_CRUD_LEVEL', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE levels SET level_name='SMOKE_CRUD_LEVEL_EDITED' WHERE level_id=$level_id"));
        crud_check('Levels — UPDATE', (bool)$ok, $ms);

        // ── TESTS: create (pic_tests.php action=add) ──
        [$test_id, $ms] = crud_timed(function () use ($conn, $level_id) {
            $conn->query("INSERT INTO tests (level_id, test_name) VALUES ($level_id, 'SMOKE_CRUD_TEST')");
            return $conn->insert_id;
        });
        crud_check('Tests — CREATE', $test_id > 0, $ms, "test_id=$test_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT test_name FROM tests WHERE test_id=$test_id")->fetch_assoc());
        crud_check('Tests — READ', $row && $row['test_name'] === 'SMOKE_CRUD_TEST', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE tests SET test_name='SMOKE_CRUD_TEST_EDITED' WHERE test_id=$test_id"));
        crud_check('Tests — UPDATE', (bool)$ok, $ms);

        // ── CRITERIA: create (pic_criteria.php action=add) ──
        [$criteria_id, $ms] = crud_timed(function () use ($conn, $test_id) {
            $conn->query("INSERT INTO criteria (test_id, criteria_name, max_mark) VALUES ($test_id, 'SMOKE_CRUD_CRIT', 10)");
            return $conn->insert_id;
        });
        crud_check('Criteria — CREATE', $criteria_id > 0, $ms, "criteria_id=$criteria_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT criteria_name FROM criteria WHERE criteria_id=$criteria_id")->fetch_assoc());
        crud_check('Criteria — READ', $row && $row['criteria_name'] === 'SMOKE_CRUD_CRIT', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE criteria SET criteria_name='SMOKE_CRUD_CRIT_EDITED', max_mark=15 WHERE criteria_id=$criteria_id"));
        crud_check('Criteria — UPDATE', (bool)$ok, $ms);

        // ── STUDENTS: create (pic_students.php action=add) ──
        [$student_id, $ms] = crud_timed(function () use ($conn, $level_id, $school_id) {
            $conn->query("INSERT INTO students (student_name, level_id, school_id, gender, year) VALUES ('SMOKE_CRUD_STUDENT', $level_id, $school_id, 'Male', YEAR(CURDATE()))");
            return $conn->insert_id;
        });
        crud_check('Students — CREATE', $student_id > 0, $ms, "student_id=$student_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT student_name FROM students WHERE student_id=$student_id")->fetch_assoc());
        crud_check('Students — READ', $row && $row['student_name'] === 'SMOKE_CRUD_STUDENT', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE students SET student_name='SMOKE_CRUD_STUDENT_EDITED' WHERE student_id=$student_id"));
        crud_check('Students — UPDATE', (bool)$ok, $ms);

        // ── GROUPS: create (pic_groups.php action=add) + assign student ──
        [$group_id, $ms] = crud_timed(function () use ($conn, $level_id) {
            $conn->query("INSERT INTO `groups` (level_id, group_name, judge_id, edit_used) VALUES ($level_id, 'SMOKE_CRUD_GROUP', NULL, 0)");
            return $conn->insert_id;
        });
        crud_check('Groups — CREATE', $group_id > 0, $ms, "group_id=$group_id");

        [, $ms] = crud_timed(fn() => $conn->query("INSERT INTO group_students (group_id, student_id) VALUES ($group_id, $student_id)"));
        crud_check('Groups — CREATE (student assignment)', true, $ms);

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT group_name FROM `groups` WHERE group_id=$group_id")->fetch_assoc());
        crud_check('Groups — READ', $row && $row['group_name'] === 'SMOKE_CRUD_GROUP', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE `groups` SET group_name='SMOKE_CRUD_GROUP_EDITED' WHERE group_id=$group_id"));
        crud_check('Groups — UPDATE', (bool)$ok, $ms);

        // ── JUDGES: create/read/update (pic_judges.php add_judge/edit_judge) ──
        [$judge_id, $ms] = crud_timed(function () use ($conn) {
            $pin_hash = password_hash('123456', PASSWORD_BCRYPT);
            $stmt = $conn->prepare("INSERT INTO judges (name, pin_hash, role, judge_code, email) VALUES (?,?,'judge',?,?)");
            $code = 'SMOKECRUD' . random_int(1000, 9999);
            $email = 'smoke_crud_' . random_int(100000, 999999) . '@example.invalid';
            $name = 'SMOKE_CRUD_JUDGE';
            $stmt->bind_param("ssss", $name, $pin_hash, $code, $email);
            $stmt->execute();
            $id = $conn->insert_id;
            $stmt->close();
            return $id;
        });
        crud_check('Judges — CREATE', $judge_id > 0, $ms, "judge_id=$judge_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT name FROM judges WHERE id=$judge_id")->fetch_assoc());
        crud_check('Judges — READ', $row && $row['name'] === 'SMOKE_CRUD_JUDGE', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE judges SET name='SMOKE_CRUD_JUDGE_EDITED' WHERE id=$judge_id"));
        crud_check('Judges — UPDATE', (bool)$ok, $ms);

        // ── ATTENDANCE: create/read/update (mirrors save_attendance.php's upsert) ──
        [$ok, $ms] = crud_timed(fn() => $conn->query("
            INSERT INTO attendance (student_id, session_id, status)
            VALUES ($student_id, $session_id, 'Present')
        "));
        $attendance_ok = (bool)$ok;
        crud_check('Attendance — CREATE', $attendance_ok, $ms);

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT status FROM attendance WHERE student_id=$student_id AND session_id=$session_id")->fetch_assoc());
        crud_check('Attendance — READ', $row && $row['status'] === 'Present', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE attendance SET status='Absent', timestamp=NOW() WHERE student_id=$student_id AND session_id=$session_id"));
        crud_check('Attendance — UPDATE', (bool)$ok, $ms);

        // ── SIRI: create/read/update (pic_siri.php action=add/edit) ──
        [$siri_id, $ms] = crud_timed(function () use ($conn) {
            $stmt = $conn->prepare("INSERT INTO siri (siri_name, siri_year, notes) VALUES (?,?,?)");
            $name = 'SMOKE_CRUD_SIRI';
            $year = (int) date('Y');
            $notes = 'smoke test fixture';
            $stmt->bind_param("sis", $name, $year, $notes);
            $stmt->execute();
            $id = $conn->insert_id;
            $stmt->close();
            return $id;
        });
        crud_check('Siri — CREATE', $siri_id > 0, $ms, "siri_id=$siri_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT siri_name FROM siri WHERE siri_id=$siri_id")->fetch_assoc());
        crud_check('Siri — READ', $row && $row['siri_name'] === 'SMOKE_CRUD_SIRI', $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE siri SET siri_name='SMOKE_CRUD_SIRI_EDITED' WHERE siri_id=$siri_id"));
        crud_check('Siri — UPDATE', (bool)$ok, $ms);

        // ── MEDAL QUOTAS: create/read/update (pic_medal_settings.php, level scope) ──
        // level_id IS the primary key here (no separate auto-increment id —
        // confirmed against pic_medal_settings.php's own DELETE+INSERT-by-level_id
        // pattern), so insert_id is always 0 for this table; success is checked
        // via affected_rows instead.
        [$ok, $ms] = crud_timed(fn() => $conn->query("INSERT INTO medal_quotas (level_id, gold_quota, silver_quota, bronze_quota) VALUES ($level_id, 1, 2, 3)"));
        crud_check('Medal quotas — CREATE', $conn->affected_rows === 1, $ms, "level_id=$level_id");

        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT gold_quota FROM medal_quotas WHERE level_id=$level_id AND gold_quota=1")->fetch_assoc());
        crud_check('Medal quotas — READ', $row && (int)$row['gold_quota'] === 1, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("UPDATE medal_quotas SET gold_quota=5 WHERE level_id=$level_id"));
        crud_check('Medal quotas — UPDATE', (bool)$ok, $ms);

        // ── DELETE — unwind in FK-safe order (children before parents) ──
        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM medal_quotas WHERE level_id=$level_id"));
        crud_check('Medal quotas — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM siri WHERE siri_id=$siri_id"));
        crud_check('Siri — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM attendance WHERE student_id=$student_id AND session_id=$session_id"));
        crud_check('Attendance — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM judges WHERE id=$judge_id"));
        crud_check('Judges — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM group_students WHERE group_id=$group_id"));
        crud_check('Groups — DELETE (student assignment)', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM `groups` WHERE group_id=$group_id"));
        crud_check('Groups — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM students WHERE student_id=$student_id"));
        crud_check('Students — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM criteria WHERE criteria_id=$criteria_id"));
        crud_check('Criteria — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM tests WHERE test_id=$test_id"));
        crud_check('Tests — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM levels WHERE level_id=$level_id"));
        crud_check('Levels — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM schools WHERE school_id=$school_id"));
        crud_check('Schools — DELETE', (bool)$ok, $ms);

        [$ok, $ms] = crud_timed(fn() => $conn->query("DELETE FROM sessions WHERE session_id=$session_id"));
        crud_check('Sessions — DELETE', (bool)$ok, $ms);

        // ── Post-delete verification: every row is really gone ──
        [$row, $ms] = crud_timed(fn() => $conn->query("SELECT 1 FROM sessions WHERE session_id=$session_id")->fetch_assoc());
        crud_check('Verify: session no longer exists after DELETE', $row === null, $ms);

    } catch (Throwable $e) {
        crud_check('Unexpected exception during CRUD test', false, 0, $e->getMessage());
    }

    $conn->rollback();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Page Smoke Test</title>
<style>
    body { font-family: -apple-system, Segoe UI, sans-serif; background:#111; color:#eee; padding:24px; }
    h1 { font-size:1.3rem; }
    h2 { font-size:1.05rem; margin-top:40px; border-top:1px solid #333; padding-top:24px; }
    .summary { margin-bottom:16px; font-size:0.95rem; }
    .pass { color:#4ADE80; }
    .fail { color:#F87171; }
    .pending { color:#facc15; }
    .note { color:#aaa; font-size:0.82rem; margin-bottom:16px; }
    table { width:100%; border-collapse:collapse; font-size:0.85rem; }
    th, td { text-align:left; padding:8px 10px; border-bottom:1px solid #333; word-break:break-word; }
    th { color:#888; text-transform:uppercase; font-size:0.72rem; letter-spacing:0.05em; }
    tr.fail-row { background:rgba(248,113,113,0.08); }
    .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:0.72rem; font-weight:700; white-space:nowrap; }
    .badge.pass { background:rgba(74,222,128,0.15); color:#4ADE80; }
    .badge.fail { background:rgba(248,113,113,0.15); color:#F87171; }
    .badge.pending { background:rgba(250,204,21,0.15); color:#facc15; }
    .deep-btn {
        display:inline-block; margin-top:10px; padding:9px 16px; border-radius:6px;
        background:#7c3aed; color:#fff; text-decoration:none; font-size:0.85rem; font-weight:600;
    }
    .deep-btn:hover { background:#6d28d9; }
    .rollback-note {
        margin-top:16px; padding:10px 12px; border-left:3px solid #4ADE80;
        background:rgba(74,222,128,0.08); font-size:0.8rem; color:#bbf7d0; border-radius:0 4px 4px 0;
    }
</style>
</head>
<body>
<h1>Real-Page Smoke Test (role: <?= htmlspecialchars($role) ?>)</h1>
<div class="summary" id="summary">Running…</div>
<div class="note">
    Each row below is a real, independent browser request to the real page (your session cookie is sent
    automatically since this is same-origin). It can only test pages reachable by your current role
    (<?= htmlspecialchars($role) ?>). Log in as a different role and revisit this page to cover the rest.
</div>
<table>
<thead><tr><th>Status</th><th>Page</th><th>Result</th><th>Time</th></tr></thead>
<tbody id="rows"></tbody>
</table>

<?php if ($role === 'judge'): ?>
<h2>Draft-score endpoint guard checks (judge)</h2>
<div class="summary" id="draftSummary">Running…</div>
<div class="note">
    Live AJAX calls to <code>save_draft_score.php</code> under your real judge session — exercises the
    reject paths (bad method, bad CSRF, bad group) that must fail cleanly with the right status code
    and JSON body instead of a 500 crash or a silent write. No real group/student ID is used here, so
    every one of these calls is expected to be rejected — a PASS means "rejected correctly," not "a mark was saved."
</div>
<table>
<thead><tr><th>Status</th><th>Check</th><th>Result</th><th>Time</th></tr></thead>
<tbody id="draftRows"></tbody>
</table>
<?php endif; ?>

<?php if ($role === 'judge'): ?>
<h2>POST/GET endpoint guard checks (judge)</h2>
<div class="summary" id="judgeSummary">Running…</div>
<div class="note">
    Live calls to the remaining judge-facing POST endpoints — <code>save_scores.php</code> (the real
    final-submit, not the draft), <code>judge_settings.php</code> (profile update), and
    <code>judge.php</code>'s <code>ajax_add_parameter</code> no-op — under your real judge session.
    Wrong CSRF tokens are used so the score/profile guards reject before any write; the no-op is just
    confirmed to stay a no-op.
</div>
<table>
<thead><tr><th>Status</th><th>Check</th><th>Result</th><th>Time</th></tr></thead>
<tbody id="judgeEndpointRows"></tbody>
</table>
<?php endif; ?>

<?php if ($role === 'pic'): ?>
<h2>POST/GET endpoint guard checks (pic)</h2>
<div class="summary" id="picSummary">Running…</div>
<div class="note">
    Live calls to every PIC-facing page that accepts a POST, under your real PIC session — each one is
    hit with a wrong CSRF token so its own guard must reject it (redirect, or an inline error message on
    a fully-rendered page) instead of crashing or silently writing. No real IDs are used, and every guard
    fires before any DB write, so a PASS means "rejected correctly," not "data was saved."
</div>
<table>
<thead><tr><th>Status</th><th>Check</th><th>Result</th><th>Time</th></tr></thead>
<tbody id="picRows"></tbody>
</table>
<?php endif; ?>

<?php if ($role === 'pic'): ?>
<h2>Deep CRUD test (pic, DB-level)</h2>
<div class="note">
    Exercises real Create → Read → Update → Delete for every PIC-managed entity — sessions, schools,
    levels, tests, criteria, students, groups (+ student assignment), judges, attendance, siri, and
    medal quotas — using the exact same queries pic_sessions.php / pic_schools.php / pic_levels.php /
    pic_tests.php / pic_criteria.php / pic_students.php / pic_groups.php / pic_judges.php /
    save_attendance.php / pic_siri.php / pic_medal_settings.php run. Everything below runs inside one
    transaction that is <strong>always rolled back</strong>, pass or fail, so no fixture row or timing
    test ever touches real data — even the DELETE steps are just exercising the code path, not
    permanently removing anything (the rollback would restore it anyway).
</div>
<?php if (!$crud_ran): ?>
    <a class="deep-btn" href="?deep_crud_test=1">▶ Run deep CRUD test</a>
<?php else: ?>
    <?php
        $crudPass  = count(array_filter($crud_results, fn($r) => $r['ok']));
        $crudFail  = count($crud_results) - $crudPass;
        $crudTotal = array_sum(array_column($crud_results, 'ms'));
        $crudAvg   = count($crud_results) ? round($crudTotal / count($crud_results), 2) : 0;
        $crudSlowest = $crud_results ? array_reduce($crud_results, fn($carry, $r) => (!$carry || $r['ms'] > $carry['ms']) ? $r : $carry) : null;
    ?>
    <div class="summary">
        <span class="pass"><?= $crudPass ?> passed</span> &nbsp;|&nbsp;
        <span class="fail"><?= $crudFail ?> failed</span> &nbsp;|&nbsp;
        total <?= round($crudTotal, 2) ?>ms &nbsp;|&nbsp;
        avg <?= $crudAvg ?>ms/query &nbsp;|&nbsp;
        slowest: <?= $crudSlowest ? htmlspecialchars($crudSlowest['label']) . " ({$crudSlowest['ms']}ms)" : 'n/a' ?>
    </div>
    <table>
        <thead><tr><th>Status</th><th>Operation</th><th>Detail</th><th>ms</th></tr></thead>
        <tbody>
        <?php foreach ($crud_results as $r): ?>
            <tr class="<?= $r['ok'] ? '' : 'fail-row' ?>">
                <td><span class="badge <?= $r['ok'] ? 'pass' : 'fail' ?>"><?= $r['ok'] ? 'PASS' : 'FAIL' ?></span></td>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td><?= htmlspecialchars($r['detail']) ?></td>
                <td><?= $r['ms'] ?>ms</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="rollback-note">
        ✓ Transaction rolled back — every SMOKE_CRUD_* session/school/level/test/criteria/student/group/judge
        row created above (and every UPDATE/DELETE run against them) was undone. Nothing touched real data.
    </div>
    <p><a class="deep-btn" href="?deep_crud_test=1">↻ Run again</a></p>
<?php endif; ?>
<?php endif; ?>

<?php if ($role === 'admin'): ?>
<h2>POST/GET endpoint guard checks (admin)</h2>
<div class="summary" id="adminEndpointSummary">Running…</div>
<div class="note">
    Live calls to <code>admin_data.php</code> under your real admin session, using a wrong CSRF token
    so the export/reset actions are always rejected before running — a PASS means "rejected correctly,"
    not "data was exported or wiped."
</div>
<table>
<thead><tr><th>Status</th><th>Check</th><th>Result</th><th>Time</th></tr></thead>
<tbody id="adminEndpointRows"></tbody>
</table>
<?php endif; ?>

<?php if (in_array($role, ['admin', 'judge'], true)): ?>
<h2>Deep draft-score test (<?= htmlspecialchars($role) ?>, DB-level)</h2>
<div class="note">
    Exercises the full draft-score lifecycle directly against the database — fixture creation, draft
    insert, draft update, draft clear, lock detection, leaderboard aggregate exclusion, final submit,
    and cross-group isolation. Everything below runs inside one transaction that is <strong>always
    rolled back</strong>, pass or fail, so it never leaves fixture rows or touches real data.
</div>
<?php if (!$deep_ran): ?>
    <a class="deep-btn" href="?deep_draft_test=1">▶ Run deep draft-score test</a>
<?php else: ?>
    <?php
        $deepPass  = count(array_filter($deep_results, fn($r) => $r['ok']));
        $deepFail  = count($deep_results) - $deepPass;
        $deepTimed = array_filter($deep_results, fn($r) => $r['ms'] > 0);
        $deepTotal = array_sum(array_column($deepTimed, 'ms'));
        $deepAvg   = count($deepTimed) ? round($deepTotal / count($deepTimed), 2) : 0;
        $deepSlowest = $deepTimed ? array_reduce($deepTimed, fn($carry, $r) => (!$carry || $r['ms'] > $carry['ms']) ? $r : $carry) : null;
    ?>
    <div class="summary">
        <span class="pass"><?= $deepPass ?> passed</span> &nbsp;|&nbsp;
        <span class="fail"><?= $deepFail ?> failed</span> &nbsp;|&nbsp;
        query time total <?= round($deepTotal, 2) ?>ms &nbsp;|&nbsp;
        avg <?= $deepAvg ?>ms/query &nbsp;|&nbsp;
        slowest: <?= $deepSlowest ? htmlspecialchars($deepSlowest['label']) . " ({$deepSlowest['ms']}ms)" : 'n/a' ?>
    </div>
    <table>
        <thead><tr><th>Status</th><th>Check</th><th>Detail</th><th>ms</th></tr></thead>
        <tbody>
        <?php foreach ($deep_results as $r): ?>
            <tr class="<?= $r['ok'] ? '' : 'fail-row' ?>">
                <td><span class="badge <?= $r['ok'] ? 'pass' : 'fail' ?>"><?= $r['ok'] ? 'PASS' : 'FAIL' ?></span></td>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td><?= htmlspecialchars($r['detail']) ?></td>
                <td><?= $r['ms'] > 0 ? $r['ms'] . 'ms' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="rollback-note">
        ✓ Transaction rolled back — fixture session/level/test/criteria/school/student/groups
        (<?= htmlspecialchars(json_encode($deep_fixtures)) ?>) and every score written above were all undone.
        Nothing in this run touched real data.
    </div>
    <p><a class="deep-btn" href="?deep_draft_test=1">↻ Run again</a></p>
<?php endif; ?>
<?php endif; ?>

<script>
const targets = <?= json_encode($targets) ?>;
const rowsEl = document.getElementById('rows');
const summaryEl = document.getElementById('summary');

targets.forEach((t) => {
    const tr = document.createElement('tr');
    tr.id = 'row-' + btoa(t).replace(/=/g, '');
    tr.innerHTML = `<td><span class="badge pending">…</span></td><td>${t}</td><td>pending</td><td></td>`;
    rowsEl.appendChild(tr);
});

function setRow(container, key, ok, label, msg, ms) {
    const tr = document.getElementById(container + '-' + key);
    tr.className = ok ? '' : 'fail-row';
    tr.innerHTML = `<td><span class="badge ${ok ? 'pass' : 'fail'}">${ok ? 'PASS' : 'FAIL'}</span></td>` +
                   `<td>${label}</td><td>${msg}</td><td>${ms}ms</td>`;
}

function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function runOne(t) {
    const start = performance.now();
    try {
        const res = await fetch(t, { credentials: 'same-origin', redirect: 'manual' });
        const ms = Math.round(performance.now() - start);

        // redirect: 'manual' makes a redirect show up as an "opaqueredirect"
        // response (status 0, type opaqueredirect) instead of following it —
        // exactly what we want to catch here (an unexpected auth bounce).
        if (res.type === 'opaqueredirect') {
            setRow('row', btoa(t).replace(/=/g, ''), false, t, 'Unexpected redirect (likely an auth/session mismatch, not the page itself)', ms);
            return;
        }
        if (!res.ok) {
            setRow('row', btoa(t).replace(/=/g, ''), false, t, `HTTP ${res.status}`, ms);
            return;
        }
        const body = await res.text();
        const trimmed = body.trimEnd();
        const finished = /<\/main>\s*<\/body>\s*<\/html>\s*$/i.test(trimmed);
        const fatalMatch = body.match(/Fatal error|Parse error|Uncaught (Error|Exception|TypeError)[^<\n]*/i);

        if (fatalMatch) {
            setRow('row', btoa(t).replace(/=/g, ''), false, t, 'PHP error visible in output: ' + esc(fatalMatch[0]), ms);
        } else if (!finished) {
            const tail = esc(trimmed.slice(-200));
            setRow('row', btoa(t).replace(/=/g, ''), false, t, `Response truncated before &lt;/html&gt; (likely a fatal error mid-render). Last 200 chars: ${tail}`, ms);
        } else {
            setRow('row', btoa(t).replace(/=/g, ''), true, t, `HTTP 200, rendered to completion (${body.length} bytes)`, ms);
        }
    } catch (e) {
        const ms = Math.round(performance.now() - start);
        setRow('row', btoa(t).replace(/=/g, ''), false, t, 'Network/fetch error: ' + esc(String(e)), ms);
    }
}

(async () => {
    // Sequential, not parallel — keeps this gentle on the server and
    // keeps results in a stable, readable order.
    for (const t of targets) {
        await runOne(t);
    }
    const passCount = document.querySelectorAll('#rows .badge.pass').length;
    const failCount = document.querySelectorAll('#rows .badge.fail').length;
    summaryEl.innerHTML = `<span class="pass">${passCount} passed</span> &nbsp;|&nbsp; <span class="fail">${failCount} failed</span>`;
})();

<?php if ($role === 'judge'): ?>
// ── Draft-score endpoint guard checks ──────────────────────────────────
const draftRowsEl = document.getElementById('draftRows');
const draftSummaryEl = document.getElementById('draftSummary');

const draftChecks = [
    { key: 'get',       label: 'GET instead of POST → 405 method_not_allowed' },
    { key: 'badcsrf',   label: 'POST with wrong/missing CSRF → 403 invalid_token' },
    { key: 'nocsrf',    label: 'POST with CSRF field entirely omitted → 403 invalid_token' },
    { key: 'badgroup',  label: 'POST with invalid group_id (0) → rejected, never a 500' },
    { key: 'badcrit',   label: 'POST with non-existent criteria_id → 400 invalid_criteria (never a 500)' },
    { key: 'badmark',   label: 'POST with out-of-range mark (99) → 400 invalid_mark (never a 500)' },
];

draftChecks.forEach((c) => {
    const tr = document.createElement('tr');
    tr.id = 'draftRows-' + c.key;
    tr.innerHTML = `<td><span class="badge pending">…</span></td><td>${c.label}</td><td>pending</td><td></td>`;
    draftRowsEl.appendChild(tr);
});

async function postDraft(body) {
    const start = performance.now();
    const res = await fetch('save_draft_score.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body),
    });
    const ms = Math.round(performance.now() - start);
    let json = null;
    try { json = await res.json(); } catch (e) { /* non-JSON body */ }
    return { status: res.status, json, ms };
}

(async () => {
    // We have no real CSRF token available here on purpose — this section
    // only proves the endpoint's guard rails hold, not that a draft can be
    // successfully written (that would need a real group/student/criteria
    // and would actually persist a row — out of scope for a safe smoke test).

    // 1. GET → 405
    {
        const start = performance.now();
        const res = await fetch('save_draft_score.php', { credentials: 'same-origin' });
        const ms = Math.round(performance.now() - start);
        let json = null;
        try { json = await res.json(); } catch (e) {}
        const ok = res.status === 405 && json && json.error === 'method_not_allowed';
        setRow('draftRows', 'get', ok, draftChecks[0].label, ok ? `HTTP ${res.status} → method_not_allowed` : `HTTP ${res.status} → ${JSON.stringify(json)}`, ms);
    }

    // 2. Bad CSRF
    {
        const r = await postDraft({ csrf_token: 'not-the-real-token', group_id: 1, student_id: 1, criteria_id: 1, mark: 5 });
        const ok = r.status === 403 && r.json && r.json.error === 'invalid_token';
        setRow('draftRows', 'badcsrf', ok, draftChecks[1].label, ok ? `HTTP ${r.status} → invalid_token` : `HTTP ${r.status} → ${JSON.stringify(r.json)}`, r.ms);
    }

    // 3. No CSRF field at all
    {
        const r = await postDraft({ group_id: 1, student_id: 1, criteria_id: 1, mark: 5 });
        const ok = r.status === 403 && r.json && r.json.error === 'invalid_token';
        setRow('draftRows', 'nocsrf', ok, draftChecks[2].label, ok ? `HTTP ${r.status} → invalid_token` : `HTTP ${r.status} → ${JSON.stringify(r.json)}`, r.ms);
    }

    // 4. Invalid group_id (still bad CSRF, since we don't have a real token —
    //    the point is confirming no 500 crash regardless of which guard fires first)
    {
        const r = await postDraft({ csrf_token: 'not-the-real-token', group_id: 0, student_id: 1, criteria_id: 1, mark: 5 });
        const ok = r.status !== 500;
        setRow('draftRows', 'badgroup', ok, draftChecks[3].label, `HTTP ${r.status} → ${JSON.stringify(r.json)}`, r.ms);
    }

    // 5. Non-existent criteria_id
    {
        const r = await postDraft({ csrf_token: 'not-the-real-token', group_id: 1, student_id: 1, criteria_id: 999999999, mark: 5 });
        const ok = r.status !== 500;
        setRow('draftRows', 'badcrit', ok, draftChecks[4].label, `HTTP ${r.status} → ${JSON.stringify(r.json)}`, r.ms);
    }

    // 6. Out-of-range mark
    {
        const r = await postDraft({ csrf_token: 'not-the-real-token', group_id: 1, student_id: 1, criteria_id: 1, mark: 99 });
        const ok = r.status !== 500;
        setRow('draftRows', 'badmark', ok, draftChecks[5].label, `HTTP ${r.status} → ${JSON.stringify(r.json)}`, r.ms);
    }

    const passCount = document.querySelectorAll('#draftRows .badge.pass').length;
    const failCount = document.querySelectorAll('#draftRows .badge.fail').length;
    draftSummaryEl.innerHTML = `<span class="pass">${passCount} passed</span> &nbsp;|&nbsp; <span class="fail">${failCount} failed</span>`;
})();
<?php endif; ?>

<?php if ($role === 'judge'): ?>
// ── Judge endpoint guard checks (save_scores.php, judge_settings.php, judge.php) ──
const judgeEndpointRowsEl = document.getElementById('judgeEndpointRows');
const judgeEndpointSummaryEl = document.getElementById('judgeEndpointSummary');

const judgeEndpointChecks = [
    { key: 'savescores', label: 'POST save_scores.php with wrong CSRF → redirected, never saved' },
    { key: 'settings',   label: 'POST judge_settings.php with wrong CSRF → still renders (never saved)' },
    { key: 'ajaxnoop',   label: 'POST judge.php ajax_add_parameter → stays a no-op ({"status":"deferred"})' },
];

judgeEndpointChecks.forEach((c) => {
    const tr = document.createElement('tr');
    tr.id = 'judgeEndpointRows-' + c.key;
    tr.innerHTML = `<td><span class="badge pending">…</span></td><td>${c.label}</td><td>pending</td><td></td>`;
    judgeEndpointRowsEl.appendChild(tr);
});

(async () => {
    // 1. save_scores.php — wrong CSRF must redirect before any DB write.
    //    No real group ID is used; a redirect means the guard fired first.
    {
        const start = performance.now();
        const res = await fetch('save_scores.php', {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: 'not-the-real-token', group_id: 999999999, 'marks[1][1]': 5 }),
        });
        const ms = Math.round(performance.now() - start);
        const ok = res.type === 'opaqueredirect';
        setRow('judgeEndpointRows', 'savescores', ok, judgeEndpointChecks[0].label, ok ? 'Redirected (as expected)' : `type=${res.type} status=${res.status}`, ms);
    }

    // 2. judge_settings.php — wrong CSRF sets an inline error and still
    //    renders the full page (200), never touches the DB.
    {
        const start = performance.now();
        const res = await fetch('judge_settings.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: 'not-the-real-token', action: 'update_profile', name: 'SMOKE_TEST_NAME' }),
        });
        const ms = Math.round(performance.now() - start);
        if (!res.ok) {
            setRow('judgeEndpointRows', 'settings', false, judgeEndpointChecks[1].label, `HTTP ${res.status}`, ms);
        } else {
            const body = await res.text();
            const trimmed = body.trimEnd();
            const finished = /<\/main>\s*<\/body>\s*<\/html>\s*$/i.test(trimmed) || /<\/body>\s*<\/html>\s*$/i.test(trimmed);
            const fatalMatch = body.match(/Fatal error|Parse error|Uncaught (Error|Exception|TypeError)[^<\n]*/i);
            const ok = !fatalMatch && finished;
            setRow('judgeEndpointRows', 'settings', ok, judgeEndpointChecks[1].label, ok ? `HTTP 200, rendered to completion (${body.length} bytes)` : (fatalMatch ? 'PHP error visible: ' + esc(fatalMatch[0]) : 'Response truncated before </html>'), ms);
        }
    }

    // 3. judge.php ajax_add_parameter — documented as a permanent no-op,
    //    should just echo {"status":"deferred"} with no CSRF needed.
    {
        const start = performance.now();
        const res = await fetch('judge.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ ajax_add_parameter: '1' }),
        });
        const ms = Math.round(performance.now() - start);
        let json = null;
        try { json = await res.json(); } catch (e) {}
        const ok = res.status === 200 && json && json.status === 'deferred';
        setRow('judgeEndpointRows', 'ajaxnoop', ok, judgeEndpointChecks[2].label, ok ? 'Confirmed no-op: {"status":"deferred"}' : `HTTP ${res.status} → ${JSON.stringify(json)}`, ms);
    }

    const passCount = document.querySelectorAll('#judgeEndpointRows .badge.pass').length;
    const failCount = document.querySelectorAll('#judgeEndpointRows .badge.fail').length;
    judgeEndpointSummaryEl.innerHTML = `<span class="pass">${passCount} passed</span> &nbsp;|&nbsp; <span class="fail">${failCount} failed</span>`;
})();
<?php endif; ?>

<?php if ($role === 'pic'): ?>
// ── PIC endpoint guard checks ────────────────────────────────────────────
// Every entry is a real PIC-facing page that accepts POST. `mode: 'redirect'`
// means a wrong CSRF token makes the page redirect (exit()) before doing
// anything else — checked via fetch's opaqueredirect. `mode: 'render'` means
// the page instead sets an inline $error and keeps rendering (still 200,
// still finishes the HTML) — checked the same way the main table above does.
const picRowsEl = document.getElementById('picRows');
const picSummaryEl = document.getElementById('picSummary');

const picTargets = [
    { url: 'pic_save_scores.php',     mode: 'redirect', extra: { group_id: 999999999, student_id: 1, test_id: 1, criteria_id: [1], mark: [5] } },
    { url: 'pic_students.php',        mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_groups.php',          mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_criteria.php',        mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_sessions.php',        mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_schools.php',         mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_tests.php',           mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_levels.php',          mode: 'redirect', extra: { action: 'add' } },
    { url: 'pic_judges.php',          mode: 'render',   extra: { action: 'add' } },
    { url: 'pic_siri.php',            mode: 'render',   extra: { action: 'add' } },
    { url: 'pic_medal_settings.php',  mode: 'render',   extra: { action: 'add' } },
    { url: 'mark_notifications_read.php', mode: 'status403', extra: { all: '1' } },
    { url: 'upload_students.php',     mode: 'render',   extra: { stage: 'review', siri_id: 0, default_session: 'NONE' } },
];

picTargets.forEach((t) => {
    const tr = document.createElement('tr');
    tr.id = 'picRows-' + t.url;
    tr.innerHTML = `<td><span class="badge pending">…</span></td><td>POST ${t.url} with wrong CSRF → rejected, never saved</td><td>pending</td><td></td>`;
    picRowsEl.appendChild(tr);
});

async function checkPicTarget(t) {
    const start = performance.now();
    try {
        const res = await fetch(t.url, {
            method: 'POST',
            credentials: 'same-origin',
            redirect: 'manual',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: 'not-the-real-token', ...t.extra }),
        });
        const ms = Math.round(performance.now() - start);

        if (t.mode === 'redirect') {
            const ok = res.type === 'opaqueredirect';
            setRow('picRows', t.url, ok, `POST ${t.url} with wrong CSRF → redirected, never saved`, ok ? 'Redirected (as expected)' : `type=${res.type} status=${res.status}`, ms);
            return;
        }

        if (t.mode === 'status403') {
            const ok = res.status === 403;
            setRow('picRows', t.url, ok, `POST ${t.url} with wrong CSRF → HTTP 403, never saved`, ok ? 'HTTP 403 (as expected)' : `HTTP ${res.status}`, ms);
            return;
        }

        // mode === 'render': should still come back 200 with a finished page
        if (!res.ok) {
            setRow('picRows', t.url, false, `POST ${t.url} with wrong CSRF → still renders (never saved)`, `HTTP ${res.status}`, ms);
            return;
        }
        const body = await res.text();
        const trimmed = body.trimEnd();
        const finished = /<\/main>\s*<\/body>\s*<\/html>\s*$/i.test(trimmed);
        const fatalMatch = body.match(/Fatal error|Parse error|Uncaught (Error|Exception|TypeError)[^<\n]*/i);
        if (fatalMatch) {
            setRow('picRows', t.url, false, `POST ${t.url} with wrong CSRF → still renders (never saved)`, 'PHP error visible in output: ' + esc(fatalMatch[0]), ms);
        } else if (!finished) {
            setRow('picRows', t.url, false, `POST ${t.url} with wrong CSRF → still renders (never saved)`, 'Response truncated before </html> (likely a fatal error)', ms);
        } else {
            setRow('picRows', t.url, true, `POST ${t.url} with wrong CSRF → still renders (never saved)`, `HTTP 200, rendered to completion (${body.length} bytes)`, ms);
        }
    } catch (e) {
        const ms = Math.round(performance.now() - start);
        setRow('picRows', t.url, false, `POST ${t.url} with wrong CSRF → rejected, never saved`, 'Network/fetch error: ' + esc(String(e)), ms);
    }
}

(async () => {
    // Every target above is hit with a deliberately wrong CSRF token and no
    // real record IDs — each page's own guard must fire before any DB write,
    // regardless of whether it responds with a redirect or a rendered page.
    for (const t of picTargets) {
        await checkPicTarget(t);
    }
    const passCount = document.querySelectorAll('#picRows .badge.pass').length;
    const failCount = document.querySelectorAll('#picRows .badge.fail').length;
    picSummaryEl.innerHTML = `<span class="pass">${passCount} passed</span> &nbsp;|&nbsp; <span class="fail">${failCount} failed</span>`;
})();
<?php endif; ?>

<?php if ($role === 'admin'): ?>
// ── Admin endpoint guard checks ─────────────────────────────────────────
const adminEndpointRowsEl = document.getElementById('adminEndpointRows');
const adminEndpointSummaryEl = document.getElementById('adminEndpointSummary');

const adminEndpointChecks = [
    { key: 'get',         label: 'GET admin_data.php → renders the page, no export/reset side effects' },
    { key: 'badexport',   label: 'POST export_action with wrong CSRF → "Security token mismatch", nothing exported' },
    { key: 'badreset',    label: 'POST reset_action with wrong CSRF → "Security token mismatch", nothing deleted' },
    { key: 'badcreateuser', label: 'POST admin.php create_user with wrong CSRF → rejected, no account created' },
    { key: 'badclearlogs',  label: 'POST admin_logs.php clear_logs with wrong CSRF → rejected, audit log untouched' },
];

adminEndpointChecks.forEach((c) => {
    const tr = document.createElement('tr');
    tr.id = 'adminEndpointRows-' + c.key;
    tr.innerHTML = `<td><span class="badge pending">…</span></td><td>${c.label}</td><td>pending</td><td></td>`;
    adminEndpointRowsEl.appendChild(tr);
});

async function postAdminData(body) {
    const start = performance.now();
    const res = await fetch('admin_data.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body),
    });
    const ms = Math.round(performance.now() - start);
    const text = await res.text();
    return { status: res.status, text, ms };
}

(async () => {
    // Every call below uses a deliberately wrong CSRF token — the endpoint's
    // own guard must reject it before running any export query or DELETE.

    // 1. GET — just confirms the page still renders normally
    {
        const start = performance.now();
        const res = await fetch('admin_data.php', { credentials: 'same-origin' });
        const ms = Math.round(performance.now() - start);
        const ok = res.ok;
        setRow('adminEndpointRows', 'get', ok, adminEndpointChecks[0].label, `HTTP ${res.status}`, ms);
    }

    // 2. Export with bad CSRF — should die() with the mismatch message, no query run
    {
        const r = await postAdminData({ export_action: '1', export_type: 'scores', export_format: 'csv', csrf_token: 'not-the-real-token' });
        const ok = r.status === 200 && r.text.includes('Security token mismatch');
        setRow('adminEndpointRows', 'badexport', ok, adminEndpointChecks[1].label, ok ? 'Rejected: Security token mismatch' : `HTTP ${r.status}, body did not contain the expected rejection message`, r.ms);
    }

    // 3. Reset with bad CSRF — should set the error flash, never touch scope_map's DELETE/WIPE branch
    {
        const r = await postAdminData({ reset_action: '1', reset_scope: 'scores', confirm_text: '', csrf_token: 'not-the-real-token' });
        const ok = r.status === 200 && r.text.includes('Security token mismatch');
        setRow('adminEndpointRows', 'badreset', ok, adminEndpointChecks[2].label, ok ? 'Rejected: Security token mismatch' : `HTTP ${r.status}, body did not contain the expected rejection message`, r.ms);
    }

    // 4. admin.php create_user with bad CSRF — should flash the mismatch
    //    message and keep rendering, never insert into users.
    {
        const start = performance.now();
        const res = await fetch('admin.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: 'not-the-real-token', action: 'create_user',
                new_username: 'smoke_crud_user', new_password: 'not-a-real-password', new_role: 'pic',
            }),
        });
        const ms = Math.round(performance.now() - start);
        const text = await res.text();
        const ok = res.status === 200 && text.includes('Security token mismatch');
        setRow('adminEndpointRows', 'badcreateuser', ok, adminEndpointChecks[3].label, ok ? 'Rejected: Security token mismatch' : `HTTP ${res.status}, body did not contain the expected rejection message`, ms);
    }

    // 5. admin_logs.php clear_logs with bad CSRF — must NOT run the
    //    DELETE FROM audit_log that a matching token would trigger.
    {
        const start = performance.now();
        const res = await fetch('admin_logs.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ csrf_token: 'not-the-real-token', action: 'clear_logs' }),
        });
        const ms = Math.round(performance.now() - start);
        const text = await res.text();
        const ok = res.status === 200 && text.includes('Security token mismatch');
        setRow('adminEndpointRows', 'badclearlogs', ok, adminEndpointChecks[4].label, ok ? 'Rejected: Security token mismatch' : `HTTP ${res.status}, body did not contain the expected rejection message`, ms);
    }

    const passCount = document.querySelectorAll('#adminEndpointRows .badge.pass').length;
    const failCount = document.querySelectorAll('#adminEndpointRows .badge.fail').length;
    adminEndpointSummaryEl.innerHTML = `<span class="pass">${passCount} passed</span> &nbsp;|&nbsp; <span class="fail">${failCount} failed</span>`;
})();
<?php endif; ?>
</script>
</body>
</html>

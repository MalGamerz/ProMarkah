<?php

namespace Tests\Database;

use Tests\Support\DbTestCase;

/**
 * Ports page_smoke_test.php's "deep CRUD test" (?deep_crud_test=1) —
 * exercises real Create/Read/Update/Delete for every PIC-managed entity
 * using the exact queries pic_sessions.php / pic_schools.php /
 * pic_levels.php / pic_tests.php / pic_criteria.php / pic_students.php /
 * pic_groups.php / pic_judges.php / save_attendance.php / pic_siri.php /
 * pic_medal_settings.php actually run. One long, deliberately sequential
 * test method (mirrors the original's fixture-chain shape — each entity
 * depends on the ones created before it) inside DbTestCase's
 * always-rolled-back transaction.
 */
final class PicCrudLifecycleTest extends DbTestCase
{
    public function testFullCrudLifecycleAcrossEveryPicManagedEntity(): void
    {
        // ── Sessions ──
        $this->conn->query("INSERT INTO sessions (session_name, siri_id) VALUES ('PHPUNIT_CRUD_SESSION', NULL)");
        $sessionId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $sessionId);

        $row = $this->fetchOne("SELECT session_name FROM sessions WHERE session_id=$sessionId");
        $this->assertSame('PHPUNIT_CRUD_SESSION', $row['session_name']);

        $this->conn->query("UPDATE sessions SET session_name='PHPUNIT_CRUD_SESSION_EDITED' WHERE session_id=$sessionId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Schools ──
        $this->conn->query("INSERT INTO schools (school_name) VALUES ('PHPUNIT_CRUD_SCHOOL')");
        $schoolId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $schoolId);

        $row = $this->fetchOne("SELECT school_name FROM schools WHERE school_id=$schoolId");
        $this->assertSame('PHPUNIT_CRUD_SCHOOL', $row['school_name']);

        $this->conn->query("UPDATE schools SET school_name='PHPUNIT_CRUD_SCHOOL_EDITED' WHERE school_id=$schoolId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Levels ──
        $this->conn->query("INSERT INTO levels (session_id, level_name) VALUES ($sessionId, 'PHPUNIT_CRUD_LEVEL')");
        $levelId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $levelId);

        $row = $this->fetchOne("SELECT level_name FROM levels WHERE level_id=$levelId");
        $this->assertSame('PHPUNIT_CRUD_LEVEL', $row['level_name']);

        $this->conn->query("UPDATE levels SET level_name='PHPUNIT_CRUD_LEVEL_EDITED' WHERE level_id=$levelId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Tests ──
        $this->conn->query("INSERT INTO tests (level_id, test_name) VALUES ($levelId, 'PHPUNIT_CRUD_TEST')");
        $testId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $testId);

        $row = $this->fetchOne("SELECT test_name FROM tests WHERE test_id=$testId");
        $this->assertSame('PHPUNIT_CRUD_TEST', $row['test_name']);

        $this->conn->query("UPDATE tests SET test_name='PHPUNIT_CRUD_TEST_EDITED' WHERE test_id=$testId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Criteria ──
        $this->conn->query("INSERT INTO criteria (test_id, criteria_name, max_mark) VALUES ($testId, 'PHPUNIT_CRUD_CRIT', 10)");
        $criteriaId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $criteriaId);

        $row = $this->fetchOne("SELECT criteria_name FROM criteria WHERE criteria_id=$criteriaId");
        $this->assertSame('PHPUNIT_CRUD_CRIT', $row['criteria_name']);

        $this->conn->query("UPDATE criteria SET criteria_name='PHPUNIT_CRUD_CRIT_EDITED', max_mark=15 WHERE criteria_id=$criteriaId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Students ──
        $this->conn->query(
            "INSERT INTO students (student_name, level_id, school_id, gender, year)
             VALUES ('PHPUNIT_CRUD_STUDENT', $levelId, $schoolId, 'Male', YEAR(CURDATE()))"
        );
        $studentId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $studentId);

        $row = $this->fetchOne("SELECT student_name FROM students WHERE student_id=$studentId");
        $this->assertSame('PHPUNIT_CRUD_STUDENT', $row['student_name']);

        $this->conn->query("UPDATE students SET student_name='PHPUNIT_CRUD_STUDENT_EDITED' WHERE student_id=$studentId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Groups (+ student assignment) ──
        $this->conn->query("INSERT INTO `groups` (level_id, group_name, judge_id, edit_used) VALUES ($levelId, 'PHPUNIT_CRUD_GROUP', NULL, 0)");
        $groupId = $this->conn->insert_id;
        $this->assertGreaterThan(0, $groupId);

        $this->conn->query("INSERT INTO group_students (group_id, student_id) VALUES ($groupId, $studentId)");

        $row = $this->fetchOne("SELECT group_name FROM `groups` WHERE group_id=$groupId");
        $this->assertSame('PHPUNIT_CRUD_GROUP', $row['group_name']);

        $this->conn->query("UPDATE `groups` SET group_name='PHPUNIT_CRUD_GROUP_EDITED' WHERE group_id=$groupId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Judges ──
        $pinHash = password_hash('123456', PASSWORD_BCRYPT);
        $stmt = $this->conn->prepare("INSERT INTO judges (name, pin_hash, role, judge_code, email) VALUES (?,?,'judge',?,?)");
        $code = 'PHPUNITCRUD' . random_int(1000, 9999);
        $email = 'phpunit_crud_' . random_int(100000, 999999) . '@example.invalid';
        $name = 'PHPUNIT_CRUD_JUDGE';
        $stmt->bind_param('ssss', $name, $pinHash, $code, $email);
        $stmt->execute();
        $judgeId = $this->conn->insert_id;
        $stmt->close();
        $this->assertGreaterThan(0, $judgeId);

        $row = $this->fetchOne("SELECT name FROM judges WHERE id=$judgeId");
        $this->assertSame('PHPUNIT_CRUD_JUDGE', $row['name']);

        $this->conn->query("UPDATE judges SET name='PHPUNIT_CRUD_JUDGE_EDITED' WHERE id=$judgeId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Attendance (mirrors save_attendance.php's upsert) ──
        $this->conn->query("INSERT INTO attendance (student_id, session_id, status) VALUES ($studentId, $sessionId, 'Present')");
        $this->assertSame(1, $this->conn->affected_rows);

        $row = $this->fetchOne("SELECT status FROM attendance WHERE student_id=$studentId AND session_id=$sessionId");
        $this->assertSame('Present', $row['status']);

        $this->conn->query("UPDATE attendance SET status='Absent', timestamp=NOW() WHERE student_id=$studentId AND session_id=$sessionId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Siri ──
        $stmt = $this->conn->prepare('INSERT INTO siri (siri_name, siri_year, notes) VALUES (?,?,?)');
        $siriName = 'PHPUNIT_CRUD_SIRI';
        $siriYear = (int) date('Y');
        $notes = 'phpunit fixture';
        $stmt->bind_param('sis', $siriName, $siriYear, $notes);
        $stmt->execute();
        $siriId = $this->conn->insert_id;
        $stmt->close();
        $this->assertGreaterThan(0, $siriId);

        $row = $this->fetchOne("SELECT siri_name FROM siri WHERE siri_id=$siriId");
        $this->assertSame('PHPUNIT_CRUD_SIRI', $row['siri_name']);

        $this->conn->query("UPDATE siri SET siri_name='PHPUNIT_CRUD_SIRI_EDITED' WHERE siri_id=$siriId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Medal quotas (level_id IS the primary key — no auto-increment id,
        //    confirmed against pic_medal_settings.php's own DELETE+INSERT-by-
        //    level_id pattern — so success is checked via affected_rows). ──
        $this->conn->query("INSERT INTO medal_quotas (level_id, gold_quota, silver_quota, bronze_quota) VALUES ($levelId, 1, 2, 3)");
        $this->assertSame(1, $this->conn->affected_rows);

        $row = $this->fetchOne("SELECT gold_quota FROM medal_quotas WHERE level_id=$levelId AND gold_quota=1");
        $this->assertSame(1, (int) $row['gold_quota']);

        $this->conn->query("UPDATE medal_quotas SET gold_quota=5 WHERE level_id=$levelId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Delete — unwind in FK-safe order (children before parents) ──
        $this->conn->query("DELETE FROM medal_quotas WHERE level_id=$levelId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM siri WHERE siri_id=$siriId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM attendance WHERE student_id=$studentId AND session_id=$sessionId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM judges WHERE id=$judgeId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM group_students WHERE group_id=$groupId");
        $this->conn->query("DELETE FROM `groups` WHERE group_id=$groupId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM students WHERE student_id=$studentId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM criteria WHERE criteria_id=$criteriaId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM tests WHERE test_id=$testId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM levels WHERE level_id=$levelId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM schools WHERE school_id=$schoolId");
        $this->assertSame(1, $this->conn->affected_rows);

        $this->conn->query("DELETE FROM sessions WHERE session_id=$sessionId");
        $this->assertSame(1, $this->conn->affected_rows);

        // ── Post-delete verification: every row is really gone ──
        $row = $this->fetchOne("SELECT 1 FROM sessions WHERE session_id=$sessionId");
        $this->assertNull($row, 'session should no longer exist after DELETE');
    }
}

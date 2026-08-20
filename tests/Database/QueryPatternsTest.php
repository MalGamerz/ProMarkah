<?php

namespace Tests\Database;

use Tests\Support\DbTestCase;

/**
 * Ports db_health_check.php section 3 — the actual (read-only) query
 * shapes each page below depends on, run against real data so a broken
 * join surfaces as a SQL error here instead of on the live page. Every
 * test just needs its query to execute without throwing; db.php enables
 * MYSQLI_REPORT_STRICT, so a broken join throws mysqli_sql_exception and
 * PHPUnit fails the test with that exception as the cause.
 */
final class QueryPatternsTest extends DbTestCase
{
    /** @return int[] every real siri_id in the DB, plus 0 for "Semua Siri" (no filter) */
    private function allSiriIds(): array
    {
        $ids = [0];
        foreach ($this->fetchAll('SELECT siri_id FROM siri ORDER BY siri_id') as $row) {
            $ids[] = (int) $row['siri_id'];
        }
        return $ids;
    }

    private function sampleLevelId(): int
    {
        $row = $this->fetchOne('SELECT level_id FROM levels ORDER BY level_id LIMIT 1');
        return (int) ($row['level_id'] ?? 0);
    }

    private function sampleSessionId(): int
    {
        $row = $this->fetchOne('SELECT session_id FROM sessions ORDER BY session_id LIMIT 1');
        return (int) ($row['session_id'] ?? 0);
    }

    private function sampleSiriId(): int
    {
        $row = $this->fetchOne('SELECT siri_id FROM siri ORDER BY siri_id LIMIT 1');
        return (int) ($row['siri_id'] ?? 0);
    }

    public function testPicGroupsPatternGroupsViaLevelsSessions(): void
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT s.session_id, s.session_name
             FROM `groups` g
             JOIN levels l ON g.level_id = l.level_id
             JOIN sessions s ON l.session_id = s.session_id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testJudgePagePatternJudgeStatsPerSiri(): void
    {
        foreach ($this->allSiriIds() as $sid) {
            $siriClause = $sid > 0
                ? "AND g.level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $sid))"
                : '';
            $row = $this->fetchOne("SELECT COUNT(DISTINCT g.group_id) AS total_groups FROM `groups` g WHERE 1=1 $siriClause");
            $this->assertArrayHasKey('total_groups', $row, "judge.php stats pattern failed for siri_id=$sid");
        }
    }

    public function testJudgePagePatternScoresCriteriaExistsCheck(): void
    {
        $row = $this->fetchOne(
            "SELECT EXISTS(SELECT 1 FROM scores sc JOIN criteria c ON sc.criteria_id = c.criteria_id LIMIT 1) AS found"
        );
        $this->assertArrayHasKey('found', $row);
    }

    public function testPicViewMarksPatternStudentTestCriteriaScoresChain(): void
    {
        $rows = $this->fetchAll(
            "SELECT s.student_name, t.test_name, c.criteria_name, COALESCE(sc.mark, 0) AS mark
             FROM students s
             LEFT JOIN tests t ON t.level_id = s.level_id
             LEFT JOIN criteria c ON c.test_id = t.test_id
             LEFT JOIN scores sc ON sc.student_id = s.student_id AND sc.criteria_id = c.criteria_id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testJudgeViewMarksPatternScoresViaCriteriaTests(): void
    {
        $rows = $this->fetchAll(
            "SELECT s.student_name, t.test_name, c.criteria_name, sc.mark
             FROM scores sc
             JOIN students s ON sc.student_id = s.student_id
             JOIN `groups` g ON sc.group_id = g.group_id
             JOIN levels gl ON g.level_id = gl.level_id
             JOIN sessions se ON gl.session_id = se.session_id
             JOIN criteria c ON sc.criteria_id = c.criteria_id
             JOIN tests t ON c.test_id = t.test_id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicDirectoryTab1PatternGroupsByLevelSessionSiri(): void
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT s.session_id, s.session_name, l.level_id, l.level_name, si.siri_name
             FROM `groups` g
             JOIN levels l ON g.level_id = l.level_id
             JOIN sessions s ON l.session_id = s.session_id
             LEFT JOIN siri si ON s.siri_id = si.siri_id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicDirectoryTab2PatternJudgesBySchool(): void
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT sc.school_id, sc.school_name
             FROM schools sc
             JOIN students st ON sc.school_id = st.school_id
             JOIN group_students gs ON st.student_id = gs.student_id
             JOIN `groups` g ON gs.group_id = g.group_id
             JOIN judges j ON g.judge_id = j.id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicDirectoryTab3PatternGroupsByJudgeWithSiri(): void
    {
        $rows = $this->fetchAll(
            "SELECT g.group_id, g.group_name, se.session_name, l.level_name, si.siri_name
             FROM `groups` g
             JOIN levels l ON g.level_id = l.level_id
             JOIN sessions se ON l.session_id = se.session_id
             LEFT JOIN siri si ON se.siri_id = si.siri_id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicDashboardPatternGroupCountPerSiri(): void
    {
        foreach ($this->allSiriIds() as $sid) {
            $row = $sid > 0
                ? $this->fetchOne(
                    "SELECT COUNT(*) AS total FROM `groups` g
                     JOIN levels l ON g.level_id = l.level_id
                     JOIN sessions s ON l.session_id = s.session_id
                     WHERE s.siri_id = $sid"
                )
                : $this->fetchOne('SELECT COUNT(*) AS total FROM `groups`');
            $this->assertArrayHasKey('total', $row, "pic.php dashboard pattern failed for siri_id=$sid");
        }
    }

    public function testPicMasterListPatternStudentGroupSessionJudgeChain(): void
    {
        $rows = $this->fetchAll(
            "SELECT st.student_name, gse.session_name, j.name AS judge_name
             FROM students st
             LEFT JOIN group_students gs ON st.student_id = gs.student_id
             LEFT JOIN `groups` g ON gs.group_id = g.group_id
             LEFT JOIN levels gl ON g.level_id = gl.level_id
             LEFT JOIN sessions gse ON gl.session_id = gse.session_id
             LEFT JOIN judges j ON g.judge_id = j.id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testAttendanceViewDashboardPatternMembershipPerSiri(): void
    {
        foreach ($this->allSiriIds() as $sid) {
            $row = $sid > 0
                ? $this->fetchOne(
                    "SELECT COUNT(DISTINCT student_id) AS total FROM (
                        SELECT st.student_id FROM students st
                        JOIN levels l ON st.level_id = l.level_id
                        JOIN sessions s ON l.session_id = s.session_id
                        WHERE s.siri_id = $sid
                        UNION
                        SELECT gs.student_id FROM group_students gs
                        JOIN `groups` g ON gs.group_id = g.group_id
                        JOIN levels gl ON g.level_id = gl.level_id
                        JOIN sessions s ON gl.session_id = s.session_id
                        WHERE s.siri_id = $sid
                    ) combined"
                )
                : $this->fetchOne('SELECT COUNT(*) AS total FROM students');
            $this->assertArrayHasKey('total', $row, "attendance_view_dashboard.php pattern failed for siri_id=$sid");
        }
    }

    public function testAttendanceHelpersStudentSessionMembershipSql(): void
    {
        $sessionId = $this->sampleSessionId();
        $rows = $this->fetchAll(
            "SELECT DISTINCT st2.student_id
             FROM students st2
             JOIN levels l ON st2.level_id = l.level_id
             WHERE l.session_id = $sessionId
             UNION
             SELECT DISTINCT gs.student_id
             FROM group_students gs
             JOIN `groups` g ON gs.group_id = g.group_id
             JOIN levels gl ON g.level_id = gl.level_id
             WHERE gl.session_id = $sessionId
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testAttendanceViewAllPatternStuSessUnion(): void
    {
        $rows = $this->fetchAll(
            "SELECT DISTINCT st2.student_id, l.session_id
             FROM students st2
             JOIN levels l ON st2.level_id = l.level_id
             UNION
             SELECT DISTINCT gs.student_id, gl.session_id
             FROM group_students gs
             JOIN `groups` g ON gs.group_id = g.group_id
             JOIN levels gl ON g.level_id = gl.level_id
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testManageAttendancePatternAjaxGroupsByLevelId(): void
    {
        $levelId = $this->sampleLevelId();
        $rows = $this->fetchAll("SELECT group_id, group_name FROM `groups` WHERE level_id = $levelId ORDER BY group_name ASC");
        $this->assertIsArray($rows);
    }

    public function testAdminDataPatternScoresExportChain(): void
    {
        $rows = $this->fetchAll(
            "SELECT s.score_id, st.student_name, t.test_name, c.criteria_name
             FROM scores s
             LEFT JOIN students st ON s.student_id = st.student_id
             LEFT JOIN criteria c ON s.criteria_id = c.criteria_id
             LEFT JOIN tests t ON c.test_id = t.test_id
             LEFT JOIN sessions se ON EXISTS (
                 SELECT 1 FROM `groups` g JOIN levels lv ON g.level_id = lv.level_id
                 WHERE g.group_id = s.group_id AND lv.session_id = se.session_id
             )
             LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicManualMarksPatternGroupDropdownScopedToSiri(): void
    {
        $siriId = $this->sampleSiriId();
        $rows = $this->fetchAll(
            "SELECT group_id, group_name FROM `groups`
             WHERE level_id IN (SELECT level_id FROM levels WHERE session_id IN (SELECT session_id FROM sessions WHERE siri_id = $siriId))
             ORDER BY group_name LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicViewMarksPatternSiriMembershipSubquery(): void
    {
        $siriId = $this->sampleSiriId();
        $rows = $this->fetchAll(
            "SELECT student_id FROM students st WHERE st.student_id IN (
                SELECT st2.student_id FROM students st2
                LEFT JOIN levels l2 ON st2.level_id = l2.level_id
                WHERE l2.session_id IN (SELECT session_id FROM sessions WHERE siri_id = $siriId)
                UNION
                SELECT gs2.student_id FROM group_students gs2
                JOIN `groups` g2 ON gs2.group_id = g2.group_id
                JOIN levels l2b ON g2.level_id = l2b.level_id
                WHERE l2b.session_id IN (SELECT session_id FROM sessions WHERE siri_id = $siriId)
             ) LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicDirectoryTab1PatternSummaryCounts(): void
    {
        $row = $this->fetchOne(
            "SELECT COUNT(DISTINCT gs.student_id) as stu, COUNT(DISTINCT g.group_id) as grp, COUNT(DISTINCT gl.session_id) as sess
             FROM `groups` g LEFT JOIN group_students gs ON g.group_id=gs.group_id LEFT JOIN levels gl ON g.level_id=gl.level_id"
        );
        $this->assertArrayHasKey('stu', $row);
    }

    public function testPicDirectoryTab2PatternPerSchoolStudentCountSessionFiltered(): void
    {
        $sessionId = $this->sampleSessionId();
        $rows = $this->fetchAll(
            "SELECT st.school_id, COUNT(DISTINCT st.student_id) as t
             FROM students st
             WHERE st.school_id IS NOT NULL AND st.student_id IN (
                SELECT st2.student_id FROM students st2 JOIN levels l ON st2.level_id = l.level_id WHERE l.session_id = $sessionId
                UNION
                SELECT gs2.student_id FROM group_students gs2 JOIN `groups` g2 ON gs2.group_id = g2.group_id JOIN levels gl2 ON g2.level_id = gl2.level_id WHERE gl2.session_id = $sessionId
             )
             GROUP BY st.school_id LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicDirectoryTab2PatternJudgesListSessionFiltered(): void
    {
        $sessionId = $this->sampleSessionId();
        $rows = $this->fetchAll(
            "SELECT j.id as judge_id, j.name as judge_name, COUNT(DISTINCT g.group_id) as group_count
             FROM judges j
             JOIN `groups` g ON g.judge_id = j.id
             JOIN levels l ON g.level_id = l.level_id
             JOIN group_students gs ON g.group_id = gs.group_id
             JOIN students st ON gs.student_id = st.student_id
             WHERE st.school_id = (SELECT school_id FROM schools LIMIT 1) AND l.session_id = $sessionId
             GROUP BY j.id LIMIT 5"
        );
        $this->assertIsArray($rows);
    }

    public function testPicSaveScoresPatternCriteriaTestIdLookup(): void
    {
        $rows = $this->fetchAll('SELECT criteria_id, test_id FROM criteria LIMIT 1');
        $this->assertIsArray($rows);
    }

    public function testPicGroupsInsertShapeExplainOnly(): void
    {
        // EXPLAIN only — proves the shape parses without inserting anything.
        $rows = $this->fetchAll("EXPLAIN SELECT level_id, 'x' AS group_name, NULL AS judge_id FROM levels LIMIT 1");
        $this->assertIsArray($rows);
    }
}

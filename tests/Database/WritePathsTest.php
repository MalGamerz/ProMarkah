<?php

namespace Tests\Database;

use Tests\Support\DbTestCase;

/**
 * Ports db_health_check.php section 4 — the actual INSERT/UPDATE/DELETE
 * statements each listed page runs. DbTestCase already wraps every test
 * method in one transaction that's rolled back in tearDown(), so unlike
 * the original script's hand-rolled checkWrite() helper, nothing here
 * needs its own begin_transaction()/rollback() — a passing OR failing
 * test leaves the database exactly as it found it.
 */
final class WritePathsTest extends DbTestCase
{
    private function sampleLevelId(): int
    {
        $row = $this->fetchOne('SELECT level_id FROM levels ORDER BY level_id LIMIT 1');
        return (int) ($row['level_id'] ?? 0);
    }

    public function testPicGroupsWriteAddGroup(): void
    {
        $levelId = $this->sampleLevelId();
        if ($levelId === 0) {
            $this->markTestSkipped('No levels exist to attach a test group to.');
        }
        $stmt = $this->conn->prepare('INSERT INTO `groups` (level_id, group_name, judge_id) VALUES (?, ?, NULL)');
        $name = '__phpunit_test_group__';
        $stmt->bind_param('is', $levelId, $name);
        $this->assertTrue($stmt->execute());
        $this->assertGreaterThan(0, $this->conn->insert_id);
        $stmt->close();
    }

    public function testPicGroupsWriteUpdateGroupNameJudge(): void
    {
        $row = $this->fetchOne('SELECT group_id, group_name FROM `groups` LIMIT 1');
        if ($row === null) {
            $this->markTestSkipped('No groups exist to update.');
        }
        $stmt = $this->conn->prepare('UPDATE `groups` SET group_name=?, judge_id=NULL WHERE group_id=?');
        $name = $row['group_name']; // write back the same value — a true no-op
        $gid = (int) $row['group_id'];
        $stmt->bind_param('si', $name, $gid);
        $this->assertTrue($stmt->execute());
        $stmt->close();
    }

    public function testPicSaveScoresWriteUpsertsAScoreRow(): void
    {
        $ctx = $this->fetchOne(
            'SELECT s.student_id, g.group_id, c.criteria_id, c.test_id
             FROM students s JOIN `groups` g ON 1=1 JOIN criteria c ON 1=1 LIMIT 1'
        );
        if ($ctx === null) {
            $this->markTestSkipped('No students/groups/criteria exist to test with.');
        }

        $studentId = (int) $ctx['student_id'];
        $groupId = (int) $ctx['group_id'];
        $criteriaId = (int) $ctx['criteria_id'];
        $testId = (int) $ctx['test_id'];

        // Mirror pic_save_scores.php's own validation: criteria must belong to the submitted test.
        $ct = $this->fetchOne("SELECT test_id FROM criteria WHERE criteria_id=$criteriaId");
        $this->assertSame($testId, (int) $ct['test_id'], 'criteria/test_id mismatch check failed unexpectedly');

        $check = $this->conn->prepare('SELECT score_id FROM scores WHERE student_id=? AND group_id=? AND criteria_id=?');
        $check->bind_param('iii', $studentId, $groupId, $criteriaId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        $mark = 0;
        if ($exists) {
            $stmt = $this->conn->prepare('UPDATE scores SET mark=?, submitted=1 WHERE student_id=? AND group_id=? AND criteria_id=?');
            $stmt->bind_param('diii', $mark, $studentId, $groupId, $criteriaId);
        } else {
            $stmt = $this->conn->prepare('INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted) VALUES (?, ?, ?, ?, 1)');
            $stmt->bind_param('iiid', $studentId, $groupId, $criteriaId, $mark);
        }
        $this->assertTrue($stmt->execute());
        $stmt->close();
    }

    public function testSaveScoresWriteInsertOnDuplicateKeyUpdate(): void
    {
        $ctx = $this->fetchOne(
            'SELECT s.student_id, g.group_id, c.criteria_id
             FROM students s JOIN `groups` g ON 1=1 JOIN criteria c ON 1=1 LIMIT 1'
        );
        if ($ctx === null) {
            $this->markTestSkipped('No students/groups/criteria exist to test with.');
        }
        $stmt = $this->conn->prepare(
            'INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = 1'
        );
        $studentId = (int) $ctx['student_id'];
        $groupId = (int) $ctx['group_id'];
        $criteriaId = (int) $ctx['criteria_id'];
        $mark = 0;
        $stmt->bind_param('iiii', $studentId, $groupId, $criteriaId, $mark);
        $this->assertTrue($stmt->execute());
        $stmt->close();
    }

    public function testPicSessionsWriteCascadeDeleteShape(): void
    {
        // Targets a session_id that can't exist, so zero real rows are ever
        // matched — this only proves the DELETE...subquery shape is valid
        // SQL (groups has no session_id column to delete on directly).
        $fakeSessionId = 999999999;
        $ok = $this->conn->query("DELETE FROM `groups` WHERE level_id IN (SELECT level_id FROM levels WHERE session_id=$fakeSessionId)");
        $this->assertNotFalse($ok);
        $this->assertSame(0, $this->conn->affected_rows);
    }

    public function testUploadStudentsWriteGroupInsertShape(): void
    {
        $levelId = $this->sampleLevelId();
        if ($levelId === 0) {
            $this->markTestSkipped('No levels exist to attach a test group to.');
        }
        $stmt = $this->conn->prepare('INSERT INTO `groups` (level_id, group_name, judge_id) VALUES (?, ?, NULL)');
        $name = '__phpunit_upload_test_group__';
        $stmt->bind_param('is', $levelId, $name);
        $this->assertTrue($stmt->execute());
        $stmt->close();
    }
}

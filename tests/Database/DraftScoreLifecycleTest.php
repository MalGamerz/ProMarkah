<?php

namespace Tests\Database;

use Tests\Support\DbTestCase;

/**
 * Ports page_smoke_test.php's "deep draft-score test" (?deep_draft_test=1) —
 * exercises the draft/final score lifecycle (save_draft_score.php,
 * save_scores.php, judge.php's lock logic, and the leaderboard's
 * submitted=1 filtering) at the database level, using fully self-contained
 * SMOKE_TEST_* fixtures. DbTestCase's rolled-back transaction replaces the
 * original's manual begin_transaction()/rollback() pair.
 */
final class DraftScoreLifecycleTest extends DbTestCase
{
    private int $sessionId;
    private int $levelId;
    private int $criteriaId;
    private int $studentId;
    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn->query("INSERT INTO sessions (session_name, siri_id) VALUES ('PHPUNIT_DRAFT_SESSION', NULL)");
        $this->sessionId = $this->conn->insert_id;

        $this->conn->query("INSERT INTO levels (session_id, level_name) VALUES ({$this->sessionId}, 'PHPUNIT_DRAFT_LEVEL')");
        $this->levelId = $this->conn->insert_id;

        $this->conn->query("INSERT INTO tests (level_id, test_name) VALUES ({$this->levelId}, 'PHPUNIT_DRAFT_TEST')");
        $testId = $this->conn->insert_id;

        $this->conn->query("INSERT INTO criteria (test_id, criteria_name, max_mark) VALUES ($testId, 'PHPUNIT_DRAFT_CRIT', 10)");
        $this->criteriaId = $this->conn->insert_id;

        $this->conn->query("INSERT INTO schools (school_name) VALUES ('PHPUNIT_DRAFT_SCHOOL')");
        $schoolId = $this->conn->insert_id;

        $this->conn->query(
            "INSERT INTO students (student_name, level_id, school_id, gender, year)
             VALUES ('PHPUNIT_DRAFT_STUDENT', {$this->levelId}, $schoolId, 'Male', YEAR(CURDATE()))"
        );
        $this->studentId = $this->conn->insert_id;

        // groups has no session_id column — a group's session is derived
        // indirectly via level_id -> levels.session_id (see README's 3NF note).
        $this->conn->query("INSERT INTO `groups` (level_id, group_name, judge_id, edit_used) VALUES ({$this->levelId}, 'PHPUNIT_DRAFT_GROUP', NULL, 0)");
        $this->groupId = $this->conn->insert_id;

        $this->conn->query("INSERT INTO group_students (group_id, student_id) VALUES ({$this->groupId}, {$this->studentId})");
    }

    private function upsertDraft(int $mark, int $submittedIfNew = 0): void
    {
        $stmt = $this->conn->prepare(
            'INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = IF(submitted = 1, submitted, VALUES(submitted))'
        );
        $stmt->bind_param('iiiii', $this->studentId, $this->groupId, $this->criteriaId, $mark, $submittedIfNew);
        $stmt->execute();
        $stmt->close();
    }

    private function scoreRow(): ?array
    {
        return $this->fetchOne(
            "SELECT mark, submitted FROM scores
             WHERE group_id={$this->groupId} AND student_id={$this->studentId} AND criteria_id={$this->criteriaId}"
        );
    }

    public function testDraftInsertWritesMarkWithSubmittedFlagZero(): void
    {
        $this->upsertDraft(7);
        $row = $this->scoreRow();
        $this->assertNotNull($row, 'draft insert should have written a row');
        $this->assertSame(7, (int) $row['mark']);
        $this->assertSame(0, (int) $row['submitted']);
    }

    public function testGroupWithOnlyADraftIsNotLocked(): void
    {
        $this->upsertDraft(7);
        $lockCheck = $this->conn->query("SELECT 1 FROM scores WHERE group_id={$this->groupId} AND submitted=1 LIMIT 1");
        $this->assertSame(0, $lockCheck->num_rows, 'a group with only a draft mark must not be considered locked');
    }

    public function testResavingADraftUpdatesInPlaceWithoutADuplicateRow(): void
    {
        $this->upsertDraft(7);
        $this->upsertDraft(9);

        $row = $this->scoreRow();
        $this->assertSame(9, (int) $row['mark'], 'the upsert should overwrite the mark, not add a second row');

        $count = $this->fetchOne(
            "SELECT COUNT(*) c FROM scores WHERE group_id={$this->groupId} AND student_id={$this->studentId} AND criteria_id={$this->criteriaId}"
        );
        $this->assertSame(1, (int) $count['c']);
    }

    public function testClearingADraftDeletesItOnlyWhileUnsubmitted(): void
    {
        $this->upsertDraft(7);
        $this->conn->query(
            "DELETE FROM scores WHERE group_id={$this->groupId} AND student_id={$this->studentId}
             AND criteria_id={$this->criteriaId} AND submitted = 0"
        );
        $count = $this->fetchOne(
            "SELECT COUNT(*) c FROM scores WHERE group_id={$this->groupId} AND student_id={$this->studentId} AND criteria_id={$this->criteriaId}"
        );
        $this->assertSame(0, (int) $count['c']);
    }

    public function testLeaderboardAggregateExcludesDraftOnlyMarks(): void
    {
        $this->upsertDraft(6);
        $agg = $this->fetchOne("SELECT COALESCE(SUM(mark),0) total FROM scores WHERE student_id={$this->studentId} AND submitted=1");
        $this->assertSame(0, (int) $agg['total'], 'a draft-only student must contribute 0 to submitted totals');
    }

    public function testFinalSubmitFlipsSubmittedFlagToOne(): void
    {
        $this->upsertDraft(6);
        $stmt = $this->conn->prepare(
            'INSERT INTO scores (student_id, group_id, criteria_id, mark, submitted)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE mark = VALUES(mark), submitted = 1'
        );
        $finalMark = 10;
        $stmt->bind_param('iiii', $this->studentId, $this->groupId, $this->criteriaId, $finalMark);
        $stmt->execute();
        $stmt->close();

        $row = $this->scoreRow();
        $this->assertSame(10, (int) $row['mark']);
        $this->assertSame(1, (int) $row['submitted']);
    }

    public function testGroupWithAFinalizedMarkIsLocked(): void
    {
        $this->testFinalSubmitFlipsSubmittedFlagToOne();
        $lockCheck = $this->conn->query("SELECT 1 FROM scores WHERE group_id={$this->groupId} AND submitted=1 LIMIT 1");
        $this->assertSame(1, $lockCheck->num_rows);
    }

    public function testLeaderboardAggregateCountsTheFinalizedMark(): void
    {
        $this->testFinalSubmitFlipsSubmittedFlagToOne();
        $agg = $this->fetchOne("SELECT COALESCE(SUM(mark),0) total FROM scores WHERE student_id={$this->studentId} AND submitted=1");
        $this->assertSame(10, (int) $agg['total']);
    }

    public function testAnUnrelatedGroupSeesNoneOfThisStudentsScores(): void
    {
        $this->upsertDraft(6);
        $this->conn->query("INSERT INTO `groups` (level_id, group_name, judge_id, edit_used) VALUES ({$this->levelId}, 'PHPUNIT_DRAFT_GROUP_2', NULL, 0)");
        $otherGroupId = $this->conn->insert_id;
        $leak = $this->conn->query("SELECT 1 FROM scores WHERE group_id=$otherGroupId LIMIT 1");
        $this->assertSame(0, $leak->num_rows, 'scores must be scoped by group_id, never leak to an unrelated group');
    }
}

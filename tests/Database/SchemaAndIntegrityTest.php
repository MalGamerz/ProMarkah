<?php

namespace Tests\Database;

use Tests\Support\DbTestCase;

/**
 * Ports db_health_check.php sections 1 ("did the 3NF migration land?") and
 * 2 ("no duplicates, no orphans left behind"). Every query here is
 * read-only, but it still runs inside DbTestCase's rolled-back transaction
 * for consistency with the rest of the Database suite.
 */
final class SchemaAndIntegrityTest extends DbTestCase
{
    public function testGroupsSessionIdColumnWasDropped(): void
    {
        $rows = $this->fetchAll("SHOW COLUMNS FROM `groups` LIKE 'session_id'");
        $this->assertCount(0, $rows, 'groups.session_id should have been dropped by the 3NF migration');
    }

    public function testScoresTestIdColumnWasDropped(): void
    {
        $rows = $this->fetchAll("SHOW COLUMNS FROM `scores` LIKE 'test_id'");
        $this->assertCount(0, $rows, 'scores.test_id should have been dropped by the 3NF migration');
    }

    public function testGroupsHasUniqueLevelGroupNameKey(): void
    {
        $rows = $this->fetchAll("SHOW INDEX FROM `groups` WHERE Key_name = 'uniq_level_groupname'");
        $this->assertNotEmpty($rows, 'groups(level_id, group_name) unique key is missing');
    }

    public function testCriteriaHasUniqueTestCriteriaNameKey(): void
    {
        $rows = $this->fetchAll("SHOW INDEX FROM `criteria` WHERE Key_name = 'uniq_test_criterianame'");
        $this->assertNotEmpty($rows, 'criteria(test_id, criteria_name) unique key is missing');
    }

    public function testNoDuplicateGroupNamesWithinTheSameLevel(): void
    {
        $rows = $this->fetchAll(
            "SELECT level_id, group_name, COUNT(*) c FROM `groups`
             GROUP BY level_id, group_name HAVING c > 1"
        );
        $this->assertCount(0, $rows, 'Found duplicate group names within the same level');
    }

    public function testNoDuplicateCriteriaNamesWithinTheSameTest(): void
    {
        $rows = $this->fetchAll(
            "SELECT test_id, criteria_name, COUNT(*) c FROM criteria
             GROUP BY test_id, criteria_name HAVING c > 1"
        );
        $this->assertCount(0, $rows, 'Found duplicate criteria names within the same test');
    }

    public function testNoOrphanedScoresCriteriaId(): void
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) c FROM scores sc
             LEFT JOIN criteria c2 ON sc.criteria_id = c2.criteria_id
             WHERE c2.criteria_id IS NULL"
        );
        $this->assertSame(0, (int) ($row['c'] ?? 1), 'Found scores rows referencing a missing criteria_id');
    }

    public function testNoOrphanedGroupsLevelId(): void
    {
        $row = $this->fetchOne(
            "SELECT COUNT(*) c FROM `groups` g
             LEFT JOIN levels l ON g.level_id = l.level_id
             WHERE l.level_id IS NULL"
        );
        $this->assertSame(0, (int) ($row['c'] ?? 1), 'Found groups rows referencing a missing level_id');
    }
}

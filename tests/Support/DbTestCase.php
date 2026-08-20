<?php

namespace Tests\Support;

use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * Base class for every Database-suite test.
 *
 * Wraps EVERY test method in one transaction that is always rolled back in
 * tearDown() — regardless of pass/fail/exception — the same guarantee
 * db_health_check.php's checkWrite() and page_smoke_test.php's deep tests
 * made by hand around each block. Here it's automatic: write whatever
 * INSERT/UPDATE/DELETE the real page under test would run, assert on it,
 * and never think about cleanup.
 *
 * (InnoDB can still burn an AUTO_INCREMENT value on a rolled-back INSERT —
 * harmless, just a gap in the id sequence, not data loss.)
 */
abstract class DbTestCase extends TestCase
{
    protected mysqli $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = getDB();
        $this->conn->begin_transaction();
    }

    protected function tearDown(): void
    {
        $this->conn->rollback();
        parent::tearDown();
    }

    /** First row of a query, or null. */
    protected function fetchOne(string $sql): ?array
    {
        $res = $this->conn->query($sql);
        if (!($res instanceof \mysqli_result)) {
            return null;
        }
        $row = $res->fetch_assoc();
        return $row ?: null;
    }

    /** All rows of a query, as a list of assoc arrays. */
    protected function fetchAll(string $sql): array
    {
        $res = $this->conn->query($sql);
        if (!($res instanceof \mysqli_result)) {
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }
}

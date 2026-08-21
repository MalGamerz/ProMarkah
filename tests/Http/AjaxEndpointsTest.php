<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/**
 * Covers pic_students.php's two read-only AJAX endpoints added for the
 * "Tambah Pelajar Baru" paste-a-name-list feature (see README.md's Map of
 * the codebase entry for pic_students.php):
 *
 *   - ajax_levels_for_siri — repopulates the Peringkat <select> when a PIC
 *     with more than one Siri switches the form's Siri picker.
 *   - ajax_existing_names  — same-Peringkat/Cawangan duplicate-name check,
 *     used to flag (not block) a likely-duplicate row before submit.
 *
 * Both sit behind pic_students.php's page-level `$_SESSION['role'] !==
 * 'pic'` redirect, which runs unconditionally before either AJAX branch —
 * same gate as every other GET on this page, not a per-endpoint check.
 */
final class AjaxEndpointsTest extends HttpTestCase
{
    public function testAjaxLevelsForSiriIsBlockedForAnAnonymousVisitor(): void
    {
        [$code, $headers] = $this->http->get('pic_students.php?ajax_levels_for_siri=1&siri=1');
        $location = HttpClient::extractLocation($headers);
        $this->assertTrue(
            $code === 302 && stripos($location, 'login') !== false,
            "ajax_levels_for_siri must redirect an anonymous visitor to login, got HTTP $code"
        );
    }

    public function testAjaxExistingNamesIsBlockedForAnAnonymousVisitor(): void
    {
        [$code, $headers] = $this->http->get('pic_students.php?ajax_existing_names=1&level_id=1&school_id=1');
        $location = HttpClient::extractLocation($headers);
        $this->assertTrue(
            $code === 302 && stripos($location, 'login') !== false,
            "ajax_existing_names must redirect an anonymous visitor to login, got HTTP $code"
        );
    }

    public function testAjaxLevelsForSiriReturnsLevelsForARealSiri(): void
    {
        $this->assertTrue($this->loginAs('pic'));

        $conn = getDB();
        $row = $conn->query('SELECT siri_id FROM siri ORDER BY siri_id LIMIT 1')->fetch_assoc();
        if (!$row) {
            $this->markTestSkipped('No siri exist in the DB to test with.');
        }
        $siriId = (int) $row['siri_id'];

        [$code, , $body] = $this->http->get("pic_students.php?ajax_levels_for_siri=1&siri={$siriId}");
        $this->assertSame(200, $code);
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        foreach ($json as $level) {
            $this->assertArrayHasKey('level_id', $level);
            $this->assertArrayHasKey('level_name', $level);
        }
    }

    public function testAjaxLevelsForSiriReturnsEmptyArrayForNoSiri(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        [$code, , $body] = $this->http->get('pic_students.php?ajax_levels_for_siri=1');
        $this->assertSame(200, $code);
        $this->assertSame([], json_decode($body, true));
    }

    public function testAjaxLevelsForSiriReturnsEmptyArrayForANonExistentSiri(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        [$code, , $body] = $this->http->get('pic_students.php?ajax_levels_for_siri=1&siri=999999999');
        $this->assertSame(200, $code);
        $this->assertSame([], json_decode($body, true));
    }

    public function testAjaxExistingNamesReturnsNamesForARealLevelAndSchool(): void
    {
        $this->assertTrue($this->loginAs('pic'));

        $conn = getDB();
        $row = $conn->query('SELECT level_id, school_id FROM students LIMIT 1')->fetch_assoc();
        if (!$row) {
            $this->markTestSkipped('No students exist in the DB to test with.');
        }

        [$code, , $body] = $this->http->get(
            "pic_students.php?ajax_existing_names=1&level_id={$row['level_id']}&school_id={$row['school_id']}"
        );
        $this->assertSame(200, $code);
        $json = json_decode($body, true);
        $this->assertIsArray($json);
        foreach ($json as $name) {
            $this->assertIsString($name);
        }
    }

    public function testAjaxExistingNamesReturnsEmptyArrayWithoutLevelOrSchool(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        [$code, , $body] = $this->http->get('pic_students.php?ajax_existing_names=1');
        $this->assertSame(200, $code);
        $this->assertSame([], json_decode($body, true));
    }
}

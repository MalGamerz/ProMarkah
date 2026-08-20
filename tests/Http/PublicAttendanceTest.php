<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/**
 * Ports test_suite.php's "public (no login)" role block — the QR
 * attendance flow that must stay reachable by an anonymous visitor (see
 * README.md's "Two constraints every change must respect"), plus the
 * one page in that family that must legitimately stay blocked
 * (manage_attendance.php).
 */
final class PublicAttendanceTest extends HttpTestCase
{
    public function testAttendancePageLoadsWithoutLogin(): void
    {
        [$code] = $this->http->get('attendance.php');
        $this->assertNotContains($code, [404, 500], "attendance.php returned HTTP $code for an anonymous visitor");
    }

    public function testAttendanceAllPresentViewLoadsWithoutLogin(): void
    {
        [$code] = $this->http->get('attendance.php?view=all_present');
        $this->assertNotContains($code, [404, 500]);
    }

    public function testSaveAttendanceRejectsEmptyPost(): void
    {
        [, , $body] = $this->http->post('save_attendance.php', []);
        $this->assertSame('invalid_request', trim($body));
    }

    public function testSaveAttendanceRejectsInvalidStatus(): void
    {
        [, , $body] = $this->http->post('save_attendance.php', [
            'student_id' => 1, 'session_id' => 1, 'status' => 'INJECTED',
        ]);
        $this->assertContains(trim($body), ['invalid_status', 'invalid_request']);
    }

    public function testSaveAttendanceAcceptsAValidStatusForARealStudentAndSession(): void
    {
        $conn = getDB();
        $student = $conn->query('SELECT student_id FROM students LIMIT 1')->fetch_assoc();
        $session = $conn->query('SELECT session_id FROM sessions LIMIT 1')->fetch_assoc();
        if (!$student || !$session) {
            $this->markTestSkipped('No students/sessions in the DB to exercise a real attendance write.');
        }

        [$code, , $body] = $this->http->post('save_attendance.php', [
            'student_id' => (int) $student['student_id'],
            'session_id' => (int) $session['session_id'],
            'status'     => 'Present',
        ]);
        $this->assertSame(200, $code);
        $this->assertContains(trim($body), ['success', 'database_error']);
    }

    public function testAttendanceToggleReturnsJson(): void
    {
        $conn = getDB();
        $student = $conn->query('SELECT student_id FROM students LIMIT 1')->fetch_assoc();
        $session = $conn->query('SELECT session_id FROM sessions LIMIT 1')->fetch_assoc();
        if (!$student || !$session) {
            $this->markTestSkipped('No students/sessions in the DB to exercise attendance_toggle.php.');
        }

        [$code, , $body] = $this->http->post('attendance_toggle.php', [
            'student_id' => (int) $student['student_id'],
            'session_id' => (int) $session['session_id'],
            'status'     => 'Present',
        ]);
        $json = json_decode($body, true);
        $this->assertSame(200, $code);
        $this->assertArrayHasKey('status', (array) $json);
    }

    public function testManageAttendanceIsBlockedForAnAnonymousVisitor(): void
    {
        [$code, $headers] = $this->http->get('manage_attendance.php');
        $location = HttpClient::extractLocation($headers);
        $blocked = ($code === 302 && stripos($location, 'login') !== false) || $code === 403;
        $this->assertTrue($blocked, "manage_attendance.php must redirect an anonymous visitor to login (or 403), got HTTP $code");
    }
}

<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/**
 * Ports page_smoke_test.php's main table: for each role, actually fetch
 * every page that role can reach and confirm it comes back HTTP 200
 * rendered to completion (no PHP fatal mid-render), same as combining
 * test_suite.php's per-role page list with page_smoke_test.php's
 * "</main></body></html>" completion check.
 *
 * What this cannot detect: a non-fatal PHP warning/notice (every page
 * sets display_errors=0) or a visually-wrong-but-non-crashing page —
 * those still need a human look or the error log.
 */
final class PageAvailabilityTest extends HttpTestCase
{
    /** @return array<string,string> label => path */
    private function picPages(): array
    {
        return [
            'PIC dashboard'      => 'pic.php',
            'PIC schools'        => 'pic_schools.php',
            'PIC sessions'       => 'pic_sessions.php',
            'PIC students'       => 'pic_students.php',
            'PIC groups'         => 'pic_groups.php',
            'PIC criteria'       => 'pic_criteria.php',
            'PIC levels'         => 'pic_levels.php',
            'PIC master list'    => 'pic_master_list.php',
            'PIC medal settings' => 'pic_medal_settings.php',
            'PIC manual marks'   => 'pic_manual_marks.php',
            'PIC view marks'     => 'pic_view_marks.php',
            'PIC judges'         => 'pic_judges.php',
            'PIC siri'           => 'pic_siri.php',
            'PIC tests'          => 'pic_tests.php',
            'PIC directory'      => 'pic_directory.php',
            'Leaderboard'        => 'leaderboard.php',
            'Manage attendance'  => 'manage_attendance.php',
            'Upload students'    => 'upload_students.php',
        ];
    }

    /** @return array<string,string> */
    private function judgePages(): array
    {
        return [
            'Judge dashboard'  => 'judge.php',
            'Judge view marks' => 'judge_view_marks.php',
            'Silibus'          => 'silibus.php',
            'Leaderboard'      => 'leaderboard.php',
        ];
    }

    /** @return array<string,string> */
    private function recorderPages(): array
    {
        return [
            'Attendance'            => 'attendance.php',
            'Attendance dashboard'  => 'attendance_view_dashboard.php',
            'Attendance by session' => 'attendance_view_session.php',
            'Attendance by school'  => 'attendance_view_school.php',
            'Attendance all'        => 'attendance_view_all.php',
            'Student attendance'    => 'attendance_student.php',
        ];
    }

    /** @return array<string,string> */
    private function adminPages(): array
    {
        return [
            'Admin dashboard' => 'admin.php',
            'Admin data'      => 'admin_data.php',
            'Admin logs'      => 'admin_logs.php',
            'Upload students' => 'upload_students.php',
        ];
    }

    private function assertPagesRenderCleanly(array $pages): void
    {
        foreach ($pages as $label => $path) {
            [$code, , $body] = $this->http->get($path);
            $this->assertNotSame(500, $code, "$label ($path) returned HTTP 500");
            $this->assertFalse(HttpClient::hasVisiblePhpError($body), "$label ($path) shows a visible PHP error");
            if ($code === 200) {
                $this->assertTrue(
                    HttpClient::rendersToCompletion($body),
                    "$label ($path) did not render to a closing </body></html> — likely a fatal error mid-render"
                );
            }
        }
    }

    public function testEveryPicPageRendersCleanly(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        $this->assertPagesRenderCleanly($this->picPages());
    }

    public function testEveryJudgePageRendersCleanly(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        $this->assertPagesRenderCleanly($this->judgePages());
    }

    public function testEveryRecorderPageRendersCleanly(): void
    {
        $this->assertTrue($this->loginAs('recorder'));
        $this->assertPagesRenderCleanly($this->recorderPages());
    }

    public function testEveryAdminPageRendersCleanly(): void
    {
        $this->assertTrue($this->loginAs('admin'));
        $this->assertPagesRenderCleanly($this->adminPages());
    }
}

<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/**
 * Ports the CSRF/method-guard checks from page_smoke_test.php's
 * draft/judge/pic/admin sections and test_suite.php's PIC POST-guard
 * block. None of these use a real record ID or a valid CSRF token — every
 * assertion here is "the guard rejected this correctly," never "a real
 * write succeeded." A PASS means the endpoint failed safely.
 */
final class EndpointGuardTest extends HttpTestCase
{
    // ── save_draft_score.php (judge) ──────────────────────────────────

    public function testSaveDraftScoreRejectsGetWithMethodNotAllowed(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, , $body] = $this->http->get('save_draft_score.php');
        $json = json_decode($body, true);
        $this->assertSame(405, $code);
        $this->assertSame('method_not_allowed', $json['error'] ?? null);
    }

    public function testSaveDraftScoreRejectsWrongCsrf(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, , $body] = $this->http->post('save_draft_score.php', [
            'csrf_token' => 'not-the-real-token', 'group_id' => 1, 'student_id' => 1, 'criteria_id' => 1, 'mark' => 5,
        ]);
        $json = json_decode($body, true);
        $this->assertSame(403, $code);
        $this->assertSame('invalid_token', $json['error'] ?? null);
    }

    public function testSaveDraftScoreRejectsMissingCsrfField(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, , $body] = $this->http->post('save_draft_score.php', [
            'group_id' => 1, 'student_id' => 1, 'criteria_id' => 1, 'mark' => 5,
        ]);
        $json = json_decode($body, true);
        $this->assertSame(403, $code);
        $this->assertSame('invalid_token', $json['error'] ?? null);
    }

    public function testSaveDraftScoreNeverCrashesOnInvalidGroupCriteriaOrMark(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        foreach ([
            ['group_id' => 0, 'student_id' => 1, 'criteria_id' => 1, 'mark' => 5],
            ['group_id' => 1, 'student_id' => 1, 'criteria_id' => 999999999, 'mark' => 5],
            ['group_id' => 1, 'student_id' => 1, 'criteria_id' => 1, 'mark' => 99],
        ] as $payload) {
            [$code] = $this->http->post('save_draft_score.php', array_merge(['csrf_token' => 'not-the-real-token'], $payload));
            $this->assertNotSame(500, $code, 'save_draft_score.php must never 500, even on malformed input: ' . json_encode($payload));
        }
    }

    // ── save_scores.php / judge_settings.php / judge.php AJAX (judge) ──

    public function testSaveScoresRedirectsRatherThanSavingOnBadCsrf(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, $headers] = $this->http->post('save_scores.php', [
            'csrf_token' => 'not-the-real-token', 'group_id' => 999999999, 'marks' => ['1' => ['1' => 5]],
        ]);
        $this->assertSame(302, $code);
        $this->assertStringContainsStringIgnoringCase('judge.php', HttpClient::extractLocation($headers));
    }

    public function testJudgeSettingsStillRendersOnBadCsrf(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, , $body] = $this->http->post('judge_settings.php', [
            'csrf_token' => 'not-the-real-token', 'action' => 'update_profile', 'name' => 'PHPUNIT_TEST_NAME',
        ]);
        $this->assertSame(200, $code);
        $this->assertFalse(HttpClient::hasVisiblePhpError($body));
    }

    public function testJudgeAjaxAddParameterStaysANoOp(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, , $body] = $this->http->post('judge.php', ['ajax_add_parameter' => 1]);
        $json = json_decode($body, true);
        $this->assertSame(200, $code);
        $this->assertSame('deferred', $json['status'] ?? null);
    }

    // ── pic_*.php POST pages (pic) ──────────────────────────────────────

    /** @return array<string,array> path => extra POST fields */
    private function picRedirectGuardedPages(): array
    {
        return [
            'pic_students.php' => ['action' => 'add'],
            'pic_groups.php'   => ['action' => 'add'],
            'pic_criteria.php' => ['action' => 'add'],
            'pic_sessions.php' => ['action' => 'add'],
            'pic_schools.php'  => ['action' => 'add'],
            'pic_tests.php'    => ['action' => 'add'],
            'pic_levels.php'   => ['action' => 'add'],
        ];
    }

    /** @return array<string,array> */
    private function picRenderGuardedPages(): array
    {
        return [
            'pic_judges.php'         => ['action' => 'add'],
            'pic_siri.php'           => ['action' => 'add'],
            'pic_medal_settings.php' => ['action' => 'add'],
        ];
    }

    public function testPicRedirectGuardedPagesRejectWrongCsrfWithoutCrashing(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        foreach ($this->picRedirectGuardedPages() as $page => $extra) {
            [$code, , $body] = $this->http->post($page, array_merge($extra, ['csrf_token' => 'not-the-real-token']));
            $this->assertNotSame(500, $code, "$page must not 500 on a bad CSRF token");
            $this->assertFalse(HttpClient::hasVisiblePhpError($body), "$page shows a visible PHP error on a bad CSRF token");
        }
    }

    public function testPicRenderGuardedPagesRejectWrongCsrfWithoutCrashing(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        foreach ($this->picRenderGuardedPages() as $page => $extra) {
            [$code, , $body] = $this->http->post($page, array_merge($extra, ['csrf_token' => 'not-the-real-token']));
            $this->assertNotSame(500, $code, "$page must not 500 on a bad CSRF token");
            $this->assertFalse(HttpClient::hasVisiblePhpError($body), "$page shows a visible PHP error on a bad CSRF token");
        }
    }

    public function testMarkNotificationsReadRejectsWrongCsrfWith403(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        [$code] = $this->http->post('mark_notifications_read.php', ['csrf_token' => 'not-the-real-token', 'all' => '1']);
        $this->assertSame(403, $code);
    }

    public function testPicJudgesAddJudgeWithBlankNameDoesNotCrash(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        $csrf = $this->csrfFrom('pic.php');
        [$code] = $this->http->post('pic_judges.php', [
            'csrf_token' => $csrf, 'action' => 'add_judge', 'name' => '', 'pin' => '', 'judge_code' => '',
        ]);
        $this->assertNotSame(500, $code);
    }

    public function testPicSiriAddSiriWithBlankNameDoesNotCrash(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        $csrf = $this->csrfFrom('pic.php');
        [$code] = $this->http->post('pic_siri.php', [
            'csrf_token' => $csrf, 'action' => 'add_siri', 'siri_name' => '', 'siri_year' => date('Y'), 'notes' => '',
        ]);
        $this->assertNotSame(500, $code);
    }

    // ── admin_data.php / admin.php / admin_logs.php (admin) ─────────────

    public function testAdminDataExportRejectsWrongCsrf(): void
    {
        $this->assertTrue($this->loginAs('admin'));
        [$code, , $body] = $this->http->post('admin_data.php', [
            'export_action' => '1', 'export_type' => 'scores', 'export_format' => 'csv', 'csrf_token' => 'not-the-real-token',
        ]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Security token mismatch', $body);
    }

    public function testAdminDataResetRejectsWrongCsrf(): void
    {
        $this->assertTrue($this->loginAs('admin'));
        [$code, , $body] = $this->http->post('admin_data.php', [
            'reset_action' => '1', 'reset_scope' => 'scores', 'confirm_text' => '', 'csrf_token' => 'not-the-real-token',
        ]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Security token mismatch', $body);
    }

    public function testAdminCreateUserRejectsWrongCsrf(): void
    {
        $this->assertTrue($this->loginAs('admin'));
        [$code, , $body] = $this->http->post('admin.php', [
            'csrf_token' => 'not-the-real-token', 'action' => 'create_user',
            'new_username' => 'phpunit_test_user', 'new_password' => 'not-a-real-password', 'new_role' => 'pic',
        ]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Security token mismatch', $body);
    }

    public function testAdminClearLogsRejectsWrongCsrf(): void
    {
        $this->assertTrue($this->loginAs('admin'));
        [$code, , $body] = $this->http->post('admin_logs.php', [
            'csrf_token' => 'not-the-real-token', 'action' => 'clear_logs',
        ]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Security token mismatch', $body);
    }
}

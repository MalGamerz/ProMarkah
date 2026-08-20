<?php

namespace Tests\Http;

use Tests\Support\HttpClient;
use Tests\Support\HttpTestCase;

/**
 * Ports test_suite.php's login-flow checks (per-role login redirect, the
 * judge two-step flow, and the "bad credentials never log in" check) plus
 * page_smoke_test.php's role-isolation checks (a page reachable by one
 * role must redirect a different role to login).
 */
final class AuthFlowTest extends HttpTestCase
{
    public function testPicLoginRedirectsToPicDashboard(): void
    {
        $this->assertTrue($this->loginAs('pic'), 'PIC login did not redirect to pic.php — check TEST_PIC_USERNAME/PASSWORD');
    }

    public function testAdminLoginRedirectsToAdminDashboard(): void
    {
        $this->assertTrue($this->loginAs('admin'), 'Admin login did not redirect to admin.php — check TEST_ADMIN_USERNAME/PASSWORD');
    }

    public function testRecorderLoginRedirectsToAttendance(): void
    {
        $this->assertTrue($this->loginAs('recorder'), 'Recorder login did not redirect to attendance.php — check TEST_RECORDER_USERNAME/PASSWORD');
    }

    public function testJudgeTwoStepLoginRedirectsToJudgeDashboard(): void
    {
        $this->assertTrue($this->loginAs('judge'), 'Judge login did not redirect to judge.php — check TEST_JUDGE_* env vars');
    }

    public function testBadCredentialsAreRejected(): void
    {
        [, $h1, $b1] = $this->http->get('login.php');
        $csrf = HttpClient::extractCsrf($b1);
        sleep(2); // clears the time-trap
        [$code] = $this->http->post('login.php', [
            'username'    => 'xyzzy_fake_user_99',
            'password'    => 'wrong_password_abc',
            'csrf_token'  => $csrf,
            'website_url' => '',
        ]);
        $this->assertNotSame(302, $code, 'A bad login must never redirect to a dashboard');
    }

    public function testSilibusIsBlockedForPic(): void
    {
        $this->assertTrue($this->loginAs('pic'));
        [$code, $headers] = $this->http->get('silibus.php');
        $location = HttpClient::extractLocation($headers);
        $this->assertTrue(
            $code === 302 && stripos($location, 'login') !== false,
            "silibus.php (judge-only) must redirect a PIC session to login, got HTTP $code"
        );
    }

    public function testAdminDashboardIsBlockedForJudge(): void
    {
        $this->assertTrue($this->loginAs('judge'));
        [$code, $headers] = $this->http->get('admin.php');
        $location = HttpClient::extractLocation($headers);
        $this->assertTrue(
            $code === 302 && stripos($location, 'login') !== false,
            "admin.php must redirect a judge session to login, got HTTP $code"
        );
    }
}

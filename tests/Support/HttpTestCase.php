<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base class for every Http-suite test.
 *
 * Skips itself (rather than erroring) when TEST_BASE_URL isn't configured,
 * so `composer test` is safe to run with no setup at all — the Http suite
 * only activates once a developer deliberately points it at a running
 * instance via tests/.env.testing (copy tests/.env.testing.sample).
 */
abstract class HttpTestCase extends TestCase
{
    protected HttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $baseUrl = getenv('TEST_BASE_URL') ?: '';
        if ($baseUrl === '') {
            $this->markTestSkipped(
                'TEST_BASE_URL is not set — copy tests/.env.testing.sample to '
                . 'tests/.env.testing and point it at a running instance to '
                . 'enable the Http suite.'
            );
        }
        $this->http = new HttpClient($baseUrl);
    }

    /**
     * Performs a full login for the given role using test_suite.php's
     * exact flow (GET for CSRF, wait out the >=2s time-trap, POST
     * credentials) — returns true if the session ended up authenticated.
     */
    protected function loginAs(string $role): bool
    {
        $creds = $this->credentialsFor($role);

        if ($role === 'judge') {
            [, $h1, $b1] = $this->http->get('login.php');
            $csrf1 = HttpClient::extractCsrf($b1);
            if ($csrf1 === '') {
                return false;
            }
            sleep(2); // clears login.php's form_load_time anti-bot trap
            [$c2, $h2] = $this->http->post('login.php', [
                'username'    => $creds['username'],
                'password'    => $creds['password'],
                'csrf_token'  => $csrf1,
                'website_url' => '',
            ]);
            if ($c2 !== 302 || stripos(HttpClient::extractLocation($h2), 'login.php') === false) {
                return false;
            }

            [, , $b3] = $this->http->get('login.php');
            $csrf3 = HttpClient::extractCsrf($b3);
            if ($csrf3 === '') {
                return false;
            }
            sleep(2);
            [$c4, $h4] = $this->http->post('login.php', [
                'judge_id'    => $creds['judge_id'],
                'pin'         => $creds['judge_pin'],
                'csrf_token'  => $csrf3,
                'website_url' => '',
            ]);
            return $c4 === 302 && stripos(HttpClient::extractLocation($h4), 'judge.php') !== false;
        }

        $expectedRedirect = [
            'pic'      => 'pic.php',
            'admin'    => 'admin.php',
            'recorder' => 'attendance.php',
        ][$role] ?? '';

        [, $h1, $b1] = $this->http->get('login.php');
        $csrf = HttpClient::extractCsrf($b1);
        if ($csrf === '') {
            return false;
        }
        sleep(2);
        [$c2, $h2] = $this->http->post('login.php', [
            'username'    => $creds['username'],
            'password'    => $creds['password'],
            'csrf_token'  => $csrf,
            'website_url' => '',
        ]);
        return $c2 === 302 && stripos(HttpClient::extractLocation($h2), $expectedRedirect) !== false;
    }

    /** Fetches a fresh CSRF token from any page rendered by the current (authenticated) session. */
    protected function csrfFrom(string $path): string
    {
        [, , $body] = $this->http->get($path);
        return HttpClient::extractCsrf($body);
    }

    private function credentialsFor(string $role): array
    {
        $upper = strtoupper($role);
        $creds = [
            'username'  => getenv("TEST_{$upper}_USERNAME") ?: '',
            'password'  => getenv("TEST_{$upper}_PASSWORD") ?: '',
            'judge_id'  => getenv('TEST_JUDGE_ID') ?: '',
            'judge_pin' => getenv('TEST_JUDGE_PIN') ?: '',
        ];
        if ($creds['username'] === '' || $creds['password'] === '') {
            $this->markTestSkipped("TEST_{$upper}_USERNAME / TEST_{$upper}_PASSWORD not set in tests/.env.testing.");
        }
        if ($role === 'judge' && ($creds['judge_id'] === '' || $creds['judge_pin'] === '')) {
            $this->markTestSkipped('TEST_JUDGE_ID / TEST_JUDGE_PIN not set in tests/.env.testing.');
        }
        return $creds;
    }
}

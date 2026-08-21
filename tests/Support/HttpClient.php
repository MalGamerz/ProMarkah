<?php

namespace Tests\Support;

/**
 * Small cURL wrapper for the Http suite — same idea as test_suite.php's
 * http_get()/http_post() helpers, but as a reusable object with a real
 * cookie jar (a per-instance temp file passed to CURLOPT_COOKIEJAR /
 * CURLOPT_COOKIEFILE) instead of hand-parsing Set-Cookie headers between
 * calls, so a multi-step flow (GET login -> POST credentials -> GET a
 * protected page) behaves exactly like a browser tab would.
 */
final class HttpClient
{
    private string $baseUrl;
    private string $cookieJar;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'pm_test_cookies_');
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /** @return array{0:int,1:string,2:string} [statusCode, headers, body] */
    public function get(string $path, bool $followRedirects = false): array
    {
        return $this->request('GET', $path, null, $followRedirects);
    }

    /** @return array{0:int,1:string,2:string} [statusCode, headers, body] */
    public function post(string $path, array $fields, bool $followRedirects = false): array
    {
        return $this->request('POST', $path, $fields, $followRedirects);
    }

    private function request(string $method, string $path, ?array $fields, bool $followRedirects): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($fields ?? []);
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request to $url failed: $err");
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        return [$code, $headers, $body];
    }

    public static function extractCsrf(string $html): string
    {
        if (preg_match('/name=["\']csrf_token["\'][^>]*value=["\']([a-f0-9]+)["\']/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value=["\']([a-f0-9]{40,})["\'][^>]*name=["\']csrf_token["\']/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public static function extractLocation(string $headers): string
    {
        preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
        return trim($m[1] ?? '');
    }

    /** Whether the response body ends with a well-formed close, matching
     *  page_smoke_test.php's "did the page render to completion" check. */
    public static function rendersToCompletion(string $body): bool
    {
        return (bool) preg_match('/<\/main>\s*<\/body>\s*<\/html>\s*$/i', rtrim($body))
            || (bool) preg_match('/<\/body>\s*<\/html>\s*$/i', rtrim($body));
    }

    public static function hasVisiblePhpError(string $body): bool
    {
        return (bool) preg_match('/Fatal error|Parse error|Uncaught (Error|Exception|TypeError)/i', $body);
    }
}

<?php
/**
 * Staging-only HTTPS + release-identity health probe (PHP 8.1+, no Composer).
 * Port of scripts/staging_health_check.py — excel2.bcsrp.ir only.
 */
declare(strict_types=1);

final class ErpsmartStagingHealth
{
    /** @var array<string, true> */
    public const ALLOWED_HOSTS = ['excel2.bcsrp.ir' => true];
    public const RELEASE_PATH = '/ERPSMART_STAGING_RELEASE.json';
    private const UA = 'ErpSmart-StagingHealth-PHP/1.0';

    public static function hostAllowed(string $host): bool
    {
        $h = strtolower(rtrim(trim($host), '.'));
        return isset(self::ALLOWED_HOSTS[$h]);
    }

    /** @return array<string,mixed> */
    private static function httpGet(string $url, float $timeout = 20.0): array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return ['ok' => false, 'error' => 'https_required', 'url' => $url];
        }
        $host = $parts['host'] ?? '';
        if (!self::hostAllowed($host)) {
            return [
                'ok' => false,
                'error' => 'host_not_staging_allowlist',
                'host' => $host,
                'allowed' => array_keys(self::ALLOWED_HOSTS),
                'url' => $url,
            ];
        }
        $started = microtime(true);
        if (!function_exists('curl_init')) {
            return [
                'ok' => false,
                'error' => 'curl_missing',
                'host' => $host,
                'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_init_failed', 'host' => $host];
        }
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => (int) ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => min(30, (int) ceil($timeout)),
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $elapsed = (int) ((microtime(true) - $started) * 1000);
        if ($raw === false) {
            return [
                'ok' => false,
                'error' => 'curl_error',
                'message' => substr($err, 0, 200),
                'host' => $host,
                'elapsed_ms' => $elapsed,
            ];
        }
        $body = (string) $raw;
        if (strlen($body) > 2_000_000) {
            $body = substr($body, 0, 2_000_000);
        }
        $acceptedError = in_array($status, [401, 403, 302, 303, 307, 308], true);
        $ok = ($status >= 200 && $status < 400) || $acceptedError;
        return [
            'ok' => $ok,
            'status' => $status,
            'elapsed_ms' => $elapsed,
            'tls' => true,
            'body' => $body,
            'body_prefix_len' => min(strlen($body), 4096),
            'host' => $host,
            'note' => $acceptedError ? 'http_error_accepted_as_up' : null,
        ];
    }

    /** @return array<string,mixed> */
    public static function probe(string $url, float $timeout = 20.0): array
    {
        $result = self::httpGet($url, $timeout);
        unset($result['body']);
        return $result;
    }

    /** @return array<string,mixed> */
    public static function probeRelease(string $baseUrl, ?string $expectedSha, float $timeout = 20.0): array
    {
        $parts = parse_url(rtrim($baseUrl, '/') . '/');
        $releaseUrl = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . self::RELEASE_PATH;
        $raw = self::httpGet($releaseUrl, $timeout);
        $body = $raw['body'] ?? '';
        unset($raw['body']);
        $out = [
            'url' => $releaseUrl,
            'http_ok' => (bool) ($raw['ok'] ?? false),
            'status' => $raw['status'] ?? null,
            'tls' => $raw['tls'] ?? null,
            'host' => $raw['host'] ?? null,
            'elapsed_ms' => $raw['elapsed_ms'] ?? null,
        ];
        if (!empty($raw['error'])) {
            $out['error'] = $raw['error'];
            $out['ok'] = false;
            return $out;
        }
        if (!$out['http_ok']) {
            $out['ok'] = false;
            $out['error'] = 'release_meta_unreachable';
            $out['note'] = 'login_page_alone_is_not_success';
            return $out;
        }
        $meta = json_decode((string) $body, true);
        if (!is_array($meta)) {
            $out['ok'] = false;
            $out['error'] = 'release_meta_invalid_json';
            return $out;
        }
        $gotSha = (string) ($meta['git_sha'] ?? '');
        $out['git_sha'] = $gotSha;
        $out['target_host'] = $meta['target_host'] ?? null;
        $out['file_count'] = $meta['file_count'] ?? null;
        $shaOk = true;
        if ($expectedSha !== null && $expectedSha !== '') {
            $shaOk = ($gotSha === $expectedSha);
            $out['expected_git_sha'] = $expectedSha;
            $out['git_sha_match'] = $shaOk;
        }
        $hostOk = ((string) ($meta['target_host'] ?? '')) === 'excel2.bcsrp.ir';
        $out['target_host_ok'] = $hostOk;
        $out['ok'] = (bool) ($shaOk && $hostOk && $gotSha !== '');
        if (!$out['ok'] && empty($out['error'])) {
            $out['error'] = 'release_identity_mismatch';
        }
        return $out;
    }

    /** @return array{ok:bool,checks:array<string,bool>} */
    public static function selftest(): array
    {
        $checks = [
            'staging_allowed' => self::hostAllowed('excel2.bcsrp.ir'),
            'prod_refused' => !self::hostAllowed('excel.bcsrp.ir'),
            'http_scheme_refused' => (self::probe('http://excel2.bcsrp.ir/')['error'] ?? '') === 'https_required',
            'prod_url_refused' => (self::probe('https://excel.bcsrp.ir/')['error'] ?? '') === 'host_not_staging_allowlist',
            'release_prod_refused' => (self::probeRelease('https://excel.bcsrp.ir/', 'abc')['error'] ?? '') === 'host_not_staging_allowlist',
        ];
        return ['ok' => !in_array(false, $checks, true), 'checks' => $checks];
    }
}

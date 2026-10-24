<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/**
 * Small HTTP client with an on-disk cache and parallel fetching (curl_multi when available, streams otherwise).
 */
final class Http
{
    public const VERSION = '1.0.0';

    public static bool $useCache = true;
    public static int $ttl = 600;
    public static int $timeout = 20;
    public static int $requests = 0;

    public static function userAgent(): string
    {
        return 'slashmark-composer-suite/' . self::VERSION . ' (+https://slashmark.in; PHP ' . PHP_VERSION . ')';
    }

    public static function cacheDir(): string
    {
        $dir = getenv('SM_COMPOSER_CACHE') ?: null;
        if (!$dir) {
            $base = getenv('XDG_CACHE_HOME') ?: (getenv('LOCALAPPDATA') ?: ((getenv('HOME') ?: sys_get_temp_dir()) . '/.cache'));
            $dir = rtrim((string) $base, '/\\') . '/sm-composer';
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function clearCache(): int
    {
        $n = 0;
        foreach (glob(self::cacheDir() . '/*.json') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    private static function cacheFile(string $url): string
    {
        return self::cacheDir() . '/' . sha1($url) . '.json';
    }

    private static function headers(string $url, array $extra = []): array
    {
        $h = ['User-Agent: ' . self::userAgent(), 'Accept: application/json, text/plain, */*'];
        if (str_contains($url, 'api.github.com') || str_contains($url, (string) getenv('SM_COMPOSER_GITHUB_API'))) {
            $token = getenv('GITHUB_TOKEN') ?: getenv('GH_TOKEN');
            if ($token) {
                $h[] = 'Authorization: Bearer ' . $token;
            }
            $h[] = 'Accept: application/vnd.github+json';
        }
        return array_merge($h, $extra);
    }

    /** @return array{status:int, body:string, headers:array<string,string>, url:string, time:float} */
    public static function request(string $url, string $method = 'GET', ?string $body = null, array $extraHeaders = [], ?int $ttl = null): array
    {
        $ttl ??= self::$ttl;
        $cacheable = $method === 'GET' && $ttl > 0;
        if ($cacheable && self::$useCache) {
            $f = self::cacheFile($url);
            if (is_file($f) && (time() - filemtime($f)) < $ttl) {
                $c = json_decode((string) file_get_contents($f), true);
                if (is_array($c)) {
                    return $c + ['time' => 0.0];
                }
            }
        }
        self::$requests++;
        $res = function_exists('curl_init')
            ? self::curl($url, $method, $body, $extraHeaders)
            : self::stream($url, $method, $body, $extraHeaders);
        if ($cacheable && $res['status'] >= 200 && $res['status'] < 300) {
            @file_put_contents(self::cacheFile($url), json_encode($res));
        }
        return $res;
    }

    private static function curl(string $url, string $method, ?string $body, array $extra): array
    {
        $ch = curl_init($url);
        self::setup($ch, $url, $method, $body, $extra);
        $start = microtime(true);
        $raw = curl_exec($ch);
        return self::finish($ch, $raw, $url, microtime(true) - $start);
    }

    private static function setup(\CurlHandle $ch, string $url, string $method, ?string $body, array $extra): void
    {
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::$timeout,
            CURLOPT_HTTPHEADER => self::headers($url, $extra),
            CURLOPT_ENCODING => '',
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        $ca = getenv('SSL_CERT_FILE') ?: getenv('CURL_CA_BUNDLE');
        if ($ca && is_file($ca)) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
    }

    private static function finish(\CurlHandle $ch, string|bool $raw, string $url, float $time): array
    {
        if ($raw === false) {
            $err = curl_error($ch);
            throw new HttpException("Request failed: $err", 0, $url);
        }
        $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerText = substr((string) $raw, 0, $hs);
        // keep only the last header block (after redirects)
        $blocks = preg_split("/\r?\n\r?\n/", trim($headerText));
        $headers = self::parseHeaders((string) end($blocks));
        return ['status' => $status, 'body' => (string) substr((string) $raw, $hs), 'headers' => $headers, 'url' => $final, 'time' => $time];
    }

    private static function stream(string $url, string $method, ?string $body, array $extra): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", self::headers($url, $extra)),
            'content' => $body ?? '',
            'timeout' => self::$timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);
        $start = microtime(true);
        $data = @file_get_contents($url, false, $ctx);
        $hdrs = $http_response_header ?? [];
        if ($data === false && !$hdrs) {
            throw new HttpException('Request failed: ' . (error_get_last()['message'] ?? 'unknown error'), 0, $url);
        }
        $status = 0;
        $start2 = 0;
        foreach ($hdrs as $i => $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
                $start2 = $i;
            }
        }
        $headers = self::parseHeaders(implode("\n", array_slice($hdrs, $start2)));
        return ['status' => $status, 'body' => (string) $data, 'headers' => $headers, 'url' => $url, 'time' => microtime(true) - $start];
    }

    private static function parseHeaders(string $text): array
    {
        $out = [];
        foreach (preg_split("/\r?\n/", $text) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim($v);
            }
        }
        return $out;
    }

    /** GET and decode JSON. Throws HttpException on non-2xx. */
    public static function json(string $url, ?int $ttl = null): array
    {
        $r = self::request($url, 'GET', null, [], $ttl);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new HttpException(self::describe($r['status'], $url), $r['status'], $url);
        }
        $d = json_decode($r['body'], true);
        if (!is_array($d)) {
            throw new HttpException("Invalid JSON from $url", $r['status'], $url);
        }
        return $d;
    }

    public static function text(string $url, ?int $ttl = null): string
    {
        $r = self::request($url, 'GET', null, [], $ttl);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new HttpException(self::describe($r['status'], $url), $r['status'], $url);
        }
        return $r['body'];
    }

    private static function describe(int $status, string $url): string
    {
        return match (true) {
            $status === 404 => "Not found (404): $url",
            $status === 403 && str_contains($url, 'github') => 'GitHub API rate limit or access denied (403). Set GITHUB_TOKEN to raise the limit.',
            $status === 429 => "Rate limited (429) by $url — wait a minute and retry",
            $status === 0 => "No response from $url",
            default => "HTTP $status from $url",
        };
    }

    /**
     * Fetch many URLs in parallel. Returns url => decoded JSON array, or null on failure.
     * @param string[] $urls
     * @return array<string, array|null>
     */
    public static function jsonMany(array $urls, int $concurrency = 12, ?int $ttl = null): array
    {
        $ttl ??= self::$ttl;
        $out = [];
        $todo = [];
        foreach (array_unique($urls) as $u) {
            $f = self::cacheFile($u);
            if (self::$useCache && $ttl > 0 && is_file($f) && (time() - filemtime($f)) < $ttl) {
                $c = json_decode((string) file_get_contents($f), true);
                $out[$u] = is_array($c) ? json_decode($c['body'] ?? '', true) : null;
            } else {
                $todo[] = $u;
            }
        }
        if (!$todo) {
            return $out;
        }
        if (!function_exists('curl_multi_init')) {
            foreach ($todo as $u) {
                try {
                    $out[$u] = self::json($u, $ttl);
                } catch (HttpException) {
                    $out[$u] = null;
                }
            }
            return $out;
        }
        $mh = curl_multi_init();
        $queue = $todo;
        $active = [];
        $add = function () use (&$queue, &$active, $mh): void {
            $u = array_shift($queue);
            $ch = curl_init($u);
            self::setup($ch, $u, 'GET', null, []);
            curl_multi_add_handle($mh, $ch);
            $active[(int) spl_object_id($ch)] = [$ch, $u, microtime(true)];
        };
        for ($i = 0; $i < $concurrency && $queue; $i++) {
            $add();
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [, $u, $t0] = $active[spl_object_id($ch)];
                unset($active[spl_object_id($ch)]);
                self::$requests++;
                try {
                    $res = self::finish($ch, curl_multi_getcontent($ch) ?? false, $u, microtime(true) - $t0);
                    if ($res['status'] >= 200 && $res['status'] < 300) {
                        if ($ttl > 0) {
                            @file_put_contents(self::cacheFile($u), json_encode($res));
                        }
                        $d = json_decode($res['body'], true);
                        $out[$u] = is_array($d) ? $d : null;
                    } else {
                        $out[$u] = null;
                    }
                } catch (HttpException) {
                    $out[$u] = null;
                }
                curl_multi_remove_handle($mh, $ch);
                if ($queue) {
                    $add();
                }
            }
        } while (($running || $active) && $status === CURLM_OK);
        curl_multi_close($mh);
        return $out;
    }
}

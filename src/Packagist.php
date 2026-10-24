<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/**
 * Packagist API client. Base URLs can be overridden for mirrors/private repos:
 *   SM_COMPOSER_PACKAGIST (default https://packagist.org)
 *   SM_COMPOSER_REPO      (default https://repo.packagist.org)
 */
final class Packagist
{
    /** @var array<string, array> */
    private static array $memo = [];

    public static function base(): string
    {
        return rtrim(getenv('SM_COMPOSER_PACKAGIST') ?: 'https://packagist.org', '/');
    }

    public static function repo(): string
    {
        return rtrim(getenv('SM_COMPOSER_REPO') ?: 'https://repo.packagist.org', '/');
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}i', $name);
    }

    public static function assertName(string $name): string
    {
        $name = strtolower(trim($name));
        if (!self::validName($name)) {
            throw new \InvalidArgumentException("\"$name\" is not a valid package name (expected vendor/package)");
        }
        return $name;
    }

    public static function isPlatform(string $name): bool
    {
        return (bool) preg_match('{^(php(-64bit|-ipv6|-zts|-debug)?|hhvm|(ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer(-(plugin|runtime)-api)?)$}i', $name);
    }

    public static function search(string $q, array $filters = [], int $perPage = 15, int $page = 1): array
    {
        $params = array_filter(['q' => $q, 'type' => $filters['type'] ?? null, 'tags' => $filters['tags'] ?? null, 'per_page' => $perPage, 'page' => $page], static fn ($v) => $v !== null && $v !== '');
        return Http::json(self::base() . '/search.json?' . http_build_query($params), 300);
    }

    /** Full package info from packagist.org/packages/{name}.json (downloads, maintainers, favers, all versions). */
    public static function package(string $name): array
    {
        $name = self::assertName($name);
        $key = 'pkg:' . $name;
        if (!isset(self::$memo[$key])) {
            try {
                self::$memo[$key] = Http::json(self::base() . '/packages/' . $name . '.json')['package'] ?? [];
            } catch (HttpException $e) {
                if ($e->status === 404) {
                    throw new HttpException("Package \"$name\" was not found on Packagist", 404, $e->url);
                }
                throw $e;
            }
        }
        return self::$memo[$key];
    }

    /** Tagged versions (newest first), expanded from the minified Composer v2 metadata. @return array<int, array> */
    public static function versions(string $name, bool $withDev = false): array
    {
        $name = self::assertName($name);
        $key = 'p2:' . $name . ($withDev ? '~dev' : '');
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        try {
            $data = Http::json(self::repo() . '/p2/' . $name . '.json');
        } catch (HttpException $e) {
            if ($e->status === 404) {
                throw new HttpException("Package \"$name\" was not found on Packagist", 404, $e->url);
            }
            throw $e;
        }
        $list = self::expand($data['packages'][$name] ?? []);
        if ($withDev) {
            try {
                $dev = Http::json(self::repo() . '/p2/' . $name . '~dev.json');
                $list = array_merge($list, self::expand($dev['packages'][$name] ?? []));
            } catch (HttpException) {
                // no dev versions
            }
        }
        usort($list, static fn ($a, $b) => Semver::compare($b['version'], $a['version']));
        return self::$memo[$key] = $list;
    }

    /** Expand Composer 2 minified metadata (each entry only lists keys that changed). */
    public static function expand(array $versions): array
    {
        $out = [];
        $prev = null;
        foreach ($versions as $v) {
            if ($prev === null) {
                $cur = $v;
            } else {
                $cur = $prev;
                foreach ($v as $k => $val) {
                    if ($val === '__unset') {
                        unset($cur[$k]);
                    } else {
                        $cur[$k] = $val;
                    }
                }
            }
            $out[] = $cur;
            $prev = $cur;
        }
        return $out;
    }

    /** Metadata for many packages in parallel. @return array<string, array> name => versions */
    public static function versionsMany(array $names): array
    {
        $urls = [];
        foreach ($names as $n) {
            if (!self::isPlatform($n) && self::validName($n)) {
                $urls[$n] = self::repo() . '/p2/' . strtolower($n) . '.json';
            }
        }
        $res = Http::jsonMany(array_values($urls));
        $out = [];
        foreach ($urls as $n => $u) {
            $d = $res[$u] ?? null;
            $list = $d ? self::expand($d['packages'][strtolower($n)] ?? []) : [];
            usort($list, static fn ($a, $b) => Semver::compare($b['version'], $a['version']));
            $out[$n] = $list;
            self::$memo['p2:' . strtolower($n)] = $list;
        }
        return $out;
    }

    /** Package info for many packages in parallel. @return array<string, array|null> */
    public static function packageMany(array $names): array
    {
        $urls = [];
        foreach ($names as $n) {
            if (!self::isPlatform($n) && self::validName($n)) {
                $urls[$n] = self::base() . '/packages/' . strtolower($n) . '.json';
            }
        }
        $res = Http::jsonMany(array_values($urls));
        $out = [];
        foreach ($urls as $n => $u) {
            $out[$n] = $res[$u]['package'] ?? null;
            if ($out[$n]) {
                self::$memo['pkg:' . strtolower($n)] = $out[$n];
            }
        }
        return $out;
    }

    public static function latest(string $name, bool $stable = true): ?array
    {
        $versions = self::versions($name);
        $v = Semver::latest(array_column($versions, 'version'), $stable);
        foreach ($versions as $row) {
            if ($row['version'] === $v) {
                return $row;
            }
        }
        return $versions[0] ?? null;
    }

    public static function findVersion(string $name, string $version): ?array
    {
        if ($version === 'latest') {
            return self::latest($name);
        }
        $n = Semver::normalize($version);
        foreach (self::versions($name, str_starts_with($version, 'dev-') || str_ends_with($version, '-dev')) as $row) {
            if ($row['version'] === $version || ($n !== null && ($row['version_normalized'] ?? Semver::normalize($row['version'])) === $n)) {
                return $row;
            }
        }
        // treat it as a constraint and pick the best match
        if (Semver::isValid($version)) {
            foreach (self::versions($name) as $row) {
                if (Semver::satisfies($row['version'], $version)) {
                    return $row;
                }
            }
        }
        return null;
    }

    public static function stats(string $name, int $days = 90): array
    {
        $name = self::assertName($name);
        $from = date('Y-m-d', time() - $days * 86400);
        return Http::json(self::base() . '/packages/' . $name . '/stats/all.json?average=daily&from=' . $from, 3600);
    }

    /** @return array<int, array> advisories for the given packages, keyed by package name */
    public static function advisories(array $names): array
    {
        $names = array_values(array_filter($names, static fn ($n) => !self::isPlatform($n)));
        $out = [];
        foreach (array_chunk($names, 80) as $chunk) {
            $qs = implode('&', array_map(static fn ($n) => 'packages[]=' . rawurlencode($n), $chunk));
            $d = Http::json(self::base() . '/api/security-advisories/?' . $qs, 900);
            foreach ($d['advisories'] ?? [] as $pkg => $list) {
                $out[$pkg] = array_values($list);
            }
        }
        return $out;
    }

    public static function popular(int $perPage = 25, int $page = 1): array
    {
        return Http::json(self::base() . '/explore/popular.json?per_page=' . $perPage . '&page=' . $page, 1800);
    }

    public static function listVendor(string $vendor): array
    {
        return Http::json(self::base() . '/packages/list.json?vendor=' . rawurlencode(strtolower($vendor)), 1800)['packageNames'] ?? [];
    }

    /** @return array<int, array{name:string, description:string, downloads:?int}> */
    public static function dependents(string $name, int $page = 1): array
    {
        $name = self::assertName($name);
        try {
            $d = Http::json(self::base() . '/packages/' . $name . '/dependents.json?order_by=downloads&requires=all&page=' . $page, 1800);
            $rows = [];
            foreach ($d['packages'] ?? $d['results'] ?? [] as $p) {
                $rows[] = ['name' => $p['name'], 'description' => $p['description'] ?? '', 'downloads' => $p['downloads'] ?? null];
            }
            if ($rows) {
                return $rows;
            }
        } catch (HttpException) {
            // fall back to the HTML listing below
        }
        $html = Http::text(self::base() . '/packages/' . $name . '/dependents?order_by=downloads&page=' . $page, 1800);
        $rows = [];
        if (preg_match_all('#<a href="/packages/([a-z0-9_.-]+/[a-z0-9_.-]+)"[^>]*>.*?</a>.*?(?:<p[^>]*class="[^"]*description[^"]*"[^>]*>(.*?)</p>)?#si', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                if (strtolower($x[1]) !== $name) {
                    $rows[$x[1]] = ['name' => $x[1], 'description' => trim(html_entity_decode(strip_tags($x[2] ?? ''))), 'downloads' => null];
                }
            }
        }
        return array_values($rows);
    }

    /** Parse a Packagist RSS feed: releases, packages, vendor.{v}, package.{name} */
    public static function feed(string $feed, int $ttl = 300): array
    {
        if (!function_exists('simplexml_load_string')) {
            throw new \RuntimeException('The SimpleXML extension is required for feeds (ext-simplexml)');
        }
        $xml = @simplexml_load_string(Http::text(self::base() . '/feeds/' . $feed . '.rss', $ttl));
        if ($xml === false) {
            throw new \RuntimeException('Could not parse the Packagist feed');
        }
        $items = [];
        foreach ($xml->channel->item as $it) {
            $title = (string) $it->title;
            $name = $version = '';
            if (preg_match('#^(\S+/\S+)\s*\((.+)\)$#', $title, $m)) {
                [$name, $version] = [$m[1], $m[2]];
            } else {
                $name = $title;
            }
            $items[] = [
                'name' => $name,
                'version' => $version,
                'description' => trim(strip_tags((string) $it->description)),
                'link' => (string) $it->link,
                'date' => date('c', strtotime((string) $it->pubDate) ?: time()),
            ];
        }
        return $items;
    }

    /** Pull GitHub owner/repo out of a repository URL. */
    public static function github(?string $url): ?array
    {
        if ($url && preg_match('#github\.com[/:]([^/\s]+)/([^/\s]+?)(?:\.git)?/?$#i', $url, $m)) {
            return [$m[1], $m[2]];
        }
        return null;
    }

    public static function githubApi(): string
    {
        return rtrim(getenv('SM_COMPOSER_GITHUB_API') ?: 'https://api.github.com', '/');
    }

    public static function githubRaw(): string
    {
        return rtrim(getenv('SM_COMPOSER_GITHUB_RAW') ?: 'https://raw.githubusercontent.com', '/');
    }

    public static function packageUrl(string $name): string
    {
        return 'https://packagist.org/packages/' . $name;
    }
}

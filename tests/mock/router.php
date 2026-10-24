<?php

declare(strict_types=1);

/**
 * Local mock of the Packagist + GitHub HTTP APIs, for offline tests only.
 * Run: php -S 127.0.0.1:8765 tests/mock/router.php
 */

$data = require __DIR__ . '/data.php';
$uri = parse_url($_SERVER['REQUEST_URI']);
$path = rawurldecode($uri['path'] ?? '/');
parse_str($uri['query'] ?? '', $q);

function json_out(mixed $d, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
}

function norm(string $v): string
{
    $v = ltrim($v, 'v');
    if (preg_match('/^(\d+(?:\.\d+)*)(?:-(.+))?$/', $v, $m)) {
        $p = explode('.', $m[1]);
        $p = array_pad($p, 4, '0');
        return implode('.', $p) . (isset($m[2]) ? '-' . $m[2] : '');
    }
    return $v;
}

function version_entry(string $name, array $pkg, string $ver, array $info): array
{
    [$vendor, $repo] = explode('/', preg_replace('#^https://github.com/#', '', $pkg['repo']) . '/x');
    $ref = substr(sha1($name . $ver), 0, 40);
    $e = [
        'name' => $name,
        'description' => $pkg['description'],
        'keywords' => $pkg['keywords'],
        'homepage' => $pkg['homepage'],
        'version' => $ver,
        'version_normalized' => norm($ver),
        'license' => $pkg['license'],
        'authors' => $pkg['authors'],
        'source' => ['url' => $pkg['repo'] . '.git', 'type' => 'git', 'reference' => $ref],
        'dist' => ['url' => "https://api.github.com/repos/$vendor/$repo/zipball/$ref", 'type' => 'zip', 'shasum' => '', 'reference' => $ref],
        'type' => $pkg['type'],
        'time' => $info['time'],
        'autoload' => $pkg['autoload'],
        'require' => $info['require'],
        'support' => ['issues' => $pkg['repo'] . '/issues', 'source' => $pkg['repo'] . '/tree/' . $ver, 'docs' => $pkg['homepage'] ?: null],
    ];
    if (!empty($info['require-dev'])) {
        $e['require-dev'] = $info['require-dev'];
    }
    if (isset($pkg['abandoned'])) {
        $e['abandoned'] = $pkg['abandoned'];
    }
    return array_filter($e, static fn ($x) => $x !== null);
}

function minify(array $versions): array
{
    $out = [];
    $prev = null;
    foreach ($versions as $v) {
        if ($prev === null) {
            $out[] = $v;
        } else {
            $diff = [];
            foreach ($v as $k => $val) {
                if (!array_key_exists($k, $prev) || $prev[$k] !== $val) {
                    $diff[$k] = $val;
                }
            }
            foreach ($prev as $k => $_) {
                if (!array_key_exists($k, $v)) {
                    $diff[$k] = '__unset';
                }
            }
            $out[] = $diff;
        }
        $prev = $v;
    }
    return $out;
}

// ---------------- Packagist ----------------

if ($path === '/packages.json') {
    json_out(['packages' => [], 'metadata-url' => '/p2/%package%.json']);
    return;
}

if ($path === '/search.json') {
    $term = strtolower($q['q'] ?? '');
    $type = $q['type'] ?? null;
    $tags = isset($q['tags']) ? (array) $q['tags'] : [];
    $res = [];
    foreach ($data as $name => $p) {
        $hay = strtolower($name . ' ' . $p['description'] . ' ' . implode(' ', $p['keywords']));
        if ($term !== '' && !str_contains($hay, $term)) {
            continue;
        }
        if ($type && $p['type'] !== $type) {
            continue;
        }
        if ($tags && !array_intersect($tags, $p['keywords'])) {
            continue;
        }
        $row = ['name' => $name, 'description' => $p['description'], 'url' => "https://packagist.org/packages/$name", 'repository' => $p['repo'], 'downloads' => $p['downloads'][0], 'favers' => $p['favers']];
        if (isset($p['abandoned'])) {
            $row['abandoned'] = $p['abandoned'];
        }
        $res[] = $row;
    }
    usort($res, static fn ($a, $b) => $b['downloads'] <=> $a['downloads']);
    $per = (int) ($q['per_page'] ?? 15);
    $page = (int) ($q['page'] ?? 1);
    json_out(['results' => array_slice($res, ($page - 1) * $per, $per), 'total' => count($res)]);
    return;
}

if ($path === '/explore/popular.json') {
    $res = [];
    foreach ($data as $name => $p) {
        $res[] = ['name' => $name, 'description' => $p['description'], 'url' => "https://packagist.org/packages/$name", 'downloads' => $p['downloads'][1], 'favers' => $p['favers']];
    }
    usort($res, static fn ($a, $b) => $b['downloads'] <=> $a['downloads']);
    json_out(['packages' => array_slice($res, 0, (int) ($q['per_page'] ?? 25)), 'total' => count($res)]);
    return;
}

if ($path === '/packages/list.json') {
    $names = array_keys($data);
    if (!empty($q['vendor'])) {
        $names = array_values(array_filter($names, static fn ($n) => str_starts_with($n, $q['vendor'] . '/')));
    }
    json_out(['packageNames' => $names]);
    return;
}

if ($path === '/api/security-advisories/') {
    $asked = (array) ($q['packages'] ?? []);
    $all = [
        'firebase/php-jwt' => [[
            'advisoryId' => 'PKSA-y2cr-5h3j-g3ys', 'packageName' => 'firebase/php-jwt', 'remoteId' => 'GHSA-8xf4-w7qw-pjjw',
            'title' => 'Key/algorithm type confusion in firebase/php-jwt', 'link' => 'https://github.com/advisories/GHSA-8xf4-w7qw-pjjw',
            'cve' => 'CVE-2021-46743', 'affectedVersions' => '<6.0.0', 'source' => 'GitHub', 'reportedAt' => '2022-03-29 21:00:00',
            'composerRepository' => 'https://packagist.org', 'severity' => 'high',
        ]],
        'guzzlehttp/psr7' => [[
            'advisoryId' => 'PKSA-k6x3-nh7c-v7qb', 'packageName' => 'guzzlehttp/psr7', 'remoteId' => 'GHSA-wxmh-65f7-jcvw',
            'title' => 'Improper Input Validation in guzzlehttp/psr7', 'link' => 'https://github.com/advisories/GHSA-wxmh-65f7-jcvw',
            'cve' => 'CVE-2023-29197', 'affectedVersions' => '>=2,<2.4.5|<1.9.1', 'source' => 'GitHub', 'reportedAt' => '2023-04-17 16:00:00',
            'composerRepository' => 'https://packagist.org', 'severity' => 'medium',
        ]],
        'swiftmailer/swiftmailer' => [[
            'advisoryId' => 'PKSA-2t5g-xb1p-mf7z', 'packageName' => 'swiftmailer/swiftmailer', 'remoteId' => 'swiftmailer/swiftmailer/CVE-2016-10074.yaml',
            'title' => 'Sendmail transport arbitrary shell execution', 'link' => 'https://legalhackers.com/advisories/SwiftMailer-Exploit-Remote-Code-Exec-CVE-2016-10074-Vuln.html',
            'cve' => 'CVE-2016-10074', 'affectedVersions' => '<5.4.5', 'source' => 'FriendsOfPHP/security-advisories', 'reportedAt' => '2016-12-29 00:00:00',
            'composerRepository' => 'https://packagist.org', 'severity' => null,
        ]],
    ];
    $out = [];
    foreach ($asked as $n) {
        if (isset($all[$n])) {
            $out[$n] = $all[$n];
        }
    }
    json_out(['advisories' => (object) $out]);
    return;
}

if (preg_match('#^/feeds/(releases|packages|vendor\.[^/]+|package\.[^/]+)\.rss$#', $path, $m)) {
    $items = [];
    foreach ($data as $name => $p) {
        foreach ($p['versions'] as $ver => $info) {
            $items[] = [$name, $ver, $info['time'], $p['description']];
        }
    }
    usort($items, static fn ($a, $b) => strcmp($b[2], $a[2]));
    if (str_starts_with($m[1], 'vendor.')) {
        $vendor = substr($m[1], 7);
        $items = array_filter($items, static fn ($i) => str_starts_with($i[0], $vendor . '/'));
    } elseif (str_starts_with($m[1], 'package.')) {
        $pk = substr($m[1], 8);
        $items = array_filter($items, static fn ($i) => $i[0] === $pk);
    }
    header('Content-Type: application/rss+xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Packagist</title>';
    foreach (array_slice(array_values($items), 0, 40) as [$n, $ver, $t, $d]) {
        echo '<item><title>' . htmlspecialchars("$n ($ver)") . '</title><link>https://packagist.org/packages/' . $n . '</link><description>' . htmlspecialchars($d) . '</description><pubDate>' . date(DATE_RSS, strtotime($t)) . '</pubDate></item>';
    }
    echo '</channel></rss>';
    return;
}

if (preg_match('#^/p2/([^/]+/[^/~]+?)(~dev)?\.json$#', $path, $m)) {
    $name = $m[1];
    if (!isset($data[$name])) {
        json_out(['status' => 'error', 'message' => 'Package not found'], 404);
        return;
    }
    $p = $data[$name];
    $list = [];
    if (!empty($m[2])) {
        $list[] = version_entry($name, $p, 'dev-main', ['time' => '2026-10-20T10:00:00+00:00', 'require' => array_values($p['versions'])[0]['require']]);
    } else {
        foreach ($p['versions'] as $ver => $info) {
            $list[] = version_entry($name, $p, $ver, $info);
        }
    }
    json_out(['packages' => [$name => minify($list)], 'minified' => 'composer/2.0']);
    return;
}

if (preg_match('#^/packages/([^/]+/[^/]+)/stats/all\.json$#', $path, $m)) {
    $name = $m[1];
    if (!isset($data[$name])) {
        json_out(['status' => 'error'], 404);
        return;
    }
    $daily = $data[$name]['downloads'][2];
    $from = strtotime($q['from'] ?? '-90 days');
    $labels = $values = [];
    $i = 0;
    for ($t = $from; $t <= time(); $t += 86400, $i++) {
        $labels[] = date('Y-m-d', $t);
        $weekday = (int) date('N', $t);
        $values[] = (int) round($daily * (0.8 + $i * 0.004) * ($weekday >= 6 ? 0.55 : 1.0));
    }
    json_out(['labels' => $labels, 'values' => [$name => $values], 'average' => 'daily']);
    return;
}

if (preg_match('#^/packages/([^/]+/[^/]+)/dependents\.json$#', $path, $m)) {
    $name = $m[1];
    $deps = [];
    foreach ($data as $n => $p) {
        foreach ($p['versions'] as $info) {
            if (isset($info['require'][$name])) {
                $deps[$n] = ['name' => $n, 'description' => $p['description'], 'downloads' => $p['downloads'][0]];
                break;
            }
        }
    }
    usort($deps, static fn ($a, $b) => $b['downloads'] <=> $a['downloads']);
    json_out(['packages' => array_values($deps), 'total' => count($deps)]);
    return;
}

if (preg_match('#^/packages/([^/]+/[^/]+)\.json$#', $path, $m)) {
    $name = $m[1];
    if (!isset($data[$name])) {
        json_out(['status' => 'error', 'message' => 'Package not found'], 404);
        return;
    }
    $p = $data[$name];
    $versions = [];
    foreach ($p['versions'] as $ver => $info) {
        $versions[$ver] = version_entry($name, $p, $ver, $info);
    }
    $times = array_column($p['versions'], 'time');
    sort($times);
    $pkg = [
        'name' => $name, 'description' => $p['description'], 'time' => $times[0],
        'maintainers' => array_map(static fn ($u) => ['name' => $u, 'avatar_url' => "https://www.gravatar.com/avatar/" . md5($u)], $p['maintainers']),
        'versions' => $versions, 'type' => $p['type'], 'repository' => $p['repo'],
        'github_stars' => $p['stars'], 'github_watchers' => (int) ($p['stars'] / 30), 'github_forks' => (int) ($p['stars'] / 8), 'github_open_issues' => $p['issues'],
        'language' => 'PHP', 'dependents' => $p['dependents'], 'suggesters' => (int) ($p['dependents'] / 20),
        'downloads' => ['total' => $p['downloads'][0], 'monthly' => $p['downloads'][1], 'daily' => $p['downloads'][2]],
        'favers' => $p['favers'],
    ];
    if (isset($p['abandoned'])) {
        $pkg['abandoned'] = $p['abandoned'];
    }
    json_out(['package' => $pkg]);
    return;
}

// ---------------- GitHub API ----------------

if (preg_match('#^/gh/repos/([^/]+)/([^/]+)(/.*)?$#', $path, $m)) {
    [$owner, $repo, $rest] = [$m[1], $m[2], $m[3] ?? ''];
    $pkg = null;
    foreach ($data as $n => $p) {
        if (strcasecmp($p['repo'], "https://github.com/$owner/$repo") === 0) {
            $pkg = [$n, $p];
        }
    }
    if (!$pkg) {
        json_out(['message' => 'Not Found'], 404);
        return;
    }
    [$name, $p] = $pkg;
    if ($rest === '') {
        json_out(['full_name' => "$owner/$repo", 'description' => $p['description'], 'default_branch' => 'main', 'stargazers_count' => $p['stars'], 'forks_count' => (int) ($p['stars'] / 8), 'open_issues_count' => $p['issues'], 'pushed_at' => array_values($p['versions'])[0]['time'], 'archived' => isset($p['abandoned']), 'license' => ['spdx_id' => $p['license'][0]], 'html_url' => $p['repo'], 'size' => 2048 + strlen($name) * 100]);
        return;
    }
    if ($rest === '/contributors') {
        $out = [];
        foreach (array_merge($p['maintainers'], ['contributor-a', 'contributor-b']) as $i => $login) {
            $out[] = ['login' => $login, 'contributions' => (int) (900 / ($i + 1)), 'html_url' => "https://github.com/$login"];
        }
        json_out($out);
        return;
    }
    if ($rest === '/releases') {
        $out = [];
        foreach ($p['versions'] as $ver => $info) {
            $out[] = ['tag_name' => $ver, 'name' => $ver, 'published_at' => $info['time'], 'html_url' => $p['repo'] . '/releases/tag/' . $ver, 'prerelease' => str_contains($ver, 'beta'),
                'body' => "## What's Changed\n* Improved performance of handlers in $ver\n* Fixed edge case with `null` context by @contributor\n* Bumped minimum PHP to " . ($info['require']['php'] ?? 'n/a') . "\n\n**Full Changelog**: {$p['repo']}/compare/$ver"];
        }
        json_out($out);
        return;
    }
    if (preg_match('#^/git/trees/(.+)$#', $rest)) {
        json_out(['truncated' => false, 'tree' => [
            ['path' => 'README.md', 'type' => 'blob', 'size' => 4200], ['path' => 'CHANGELOG.md', 'type' => 'blob', 'size' => 9100],
            ['path' => 'composer.json', 'type' => 'blob', 'size' => 1300], ['path' => 'LICENSE', 'type' => 'blob', 'size' => 1070],
            ['path' => 'src', 'type' => 'tree'], ['path' => 'src/Logger.php', 'type' => 'blob', 'size' => 22000],
            ['path' => 'src/Handler', 'type' => 'tree'], ['path' => 'src/Handler/StreamHandler.php', 'type' => 'blob', 'size' => 7400],
            ['path' => 'tests', 'type' => 'tree'], ['path' => 'tests/LoggerTest.php', 'type' => 'blob', 'size' => 15000],
        ]]);
        return;
    }
    json_out(['message' => 'Not Found'], 404);
    return;
}

// raw.githubusercontent.com
if (preg_match('#^/raw/([^/]+)/([^/]+)/([^/]+)/(.+)$#', $path, $m)) {
    $file = $m[4];
    header('Content-Type: text/plain; charset=utf-8');
    if (strtolower($file) === 'readme.md') {
        echo "# {$m[1]}/{$m[2]}\n\n[![Build](https://img.shields.io/badge/build-passing-green)](#)\n\nA **fast** and _friendly_ library. See the [docs](https://example.com/docs).\n\n## Installation\n\n```bash\ncomposer require {$m[1]}/{$m[2]}\n```\n\n## Usage\n\n```php\n\$log = new Logger('app');\n\$log->info('Hello');\n```\n\n- Simple API\n- PSR compliant\n\n> Tip: works great with Laravel.\n";
        return;
    }
    if (strtolower($file) === 'changelog.md') {
        echo "# Changelog\n\n## 3.8.1 (2026-08-14)\n\n* Fixed a regression in StreamHandler\n\n## 3.8.0 (2026-05-02)\n\n* Added new RedisPubSubHandler options\n* Deprecated old formatter flag\n";
        return;
    }
    if ($file === 'src/Logger.php') {
        echo "<?php\n\nnamespace Monolog;\n\nclass Logger\n{\n    public function info(string \$m): void {}\n}\n";
        return;
    }
    http_response_code(404);
    echo '404: Not Found';
    return;
}

// link checker targets
if ($path === '/ok') {
    echo 'ok';
    return;
}
if ($path === '/gone') {
    http_response_code(404);
    echo 'gone';
    return;
}

http_response_code(404);
echo 'mock: no route for ' . $path;

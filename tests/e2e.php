<?php

declare(strict_types=1);

/**
 * End-to-end test: starts the local Packagist/GitHub mock, builds a sample project with known problems,
 * runs all 32 tools (text + --json) and checks exit codes and output.
 *   php tests/e2e.php
 */

$root = dirname(__DIR__);
$bin = $root . '/bin/sm-composer';
$port = 8800 + random_int(0, 150);
$tmp = sys_get_temp_dir() . '/sm-composer-e2e-' . getmypid();
@mkdir($tmp, 0775, true);

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$root/tests/mock/router.php"], [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']], $pipes, null, ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv());
register_shutdown_function(static function () use ($server, $tmp): void {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($tmp));
});
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/tests/make-fixture.php") . ' ' . escapeshellarg("$tmp/demo") . ' > /dev/null');
// the fixture points two links at port 8765; retarget them to this run's port
foreach (['composer.lock'] as $f) {
    $c = (string) file_get_contents("$tmp/demo/$f");
    file_put_contents("$tmp/demo/$f", str_replace('127.0.0.1:8765', "127.0.0.1:$port", $c));
}
// keep the lock fresh after the edit (hash only covers composer.json, so nothing to do)

$env = [
    'SM_COMPOSER_PACKAGIST' => "http://127.0.0.1:$port",
    'SM_COMPOSER_REPO' => "http://127.0.0.1:$port",
    'SM_COMPOSER_GITHUB_API' => "http://127.0.0.1:$port/gh",
    'SM_COMPOSER_GITHUB_RAW' => "http://127.0.0.1:$port/raw",
    'SM_COMPOSER_CACHE' => "$tmp/cache",
    'NO_COLOR' => '1',
    'COLUMNS' => '140',
];

function run(string $bin, array $args, array $env, string $cwd): array
{
    $cmd = array_merge([PHP_BINARY, $bin], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env + getenv());
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}

// [args, expected exit code, text that must appear (text mode)]
$cases = [
    [['search', 'log'], 0, 'monolog/monolog'],
    [['trending', '-n', '3'], 0, 'Trending'],
    [['updates', '--vendor', 'guzzlehttp'], 0, 'guzzlehttp/guzzle'],
    [['vendor', 'guzzlehttp'], 0, 'GuzzleHttp\\Psr7'],
    [['dependents', 'psr/log'], 0, 'monolog/monolog'],
    [['stats', 'monolog/monolog'], 0, 'Weekly totals'],
    [['info', 'monolog/monolog'], 0, 'Seldaek'],
    [['version'], 0, 'major available'],
    [['compare', 'guzzlehttp/guzzle', 'firebase/php-jwt'], 0, 'best in row'],
    [['maintainers', 'guzzlehttp/guzzle', '-c'], 0, 'contributors'],
    [['docs', 'monolog/monolog'], 0, 'Installation'],
    [['source', 'monolog/monolog'], 0, 'Logger.php'],
    [['changelog', 'monolog/monolog'], 0, '3.8.1'],
    [['health', 'guzzlehttp/guzzle'], 0, '/100'],
    [['json'], 0, 'slashmark/demo-app'],
    [['tree'], 0, 'provided by laravel/framework'],
    [['lock'], 1, 'vendor has 1.5.2'],
    [['autoload'], 1, "won't autoload"],
    [['size'], 0, 'vendor/ size'],
    [['outdated', '--strict'], 1, 'laravel/framework'],
    [['conflicts'], 1, 'acme/gpl-lib requires psr/log ^1.0'],
    [['constraint', '^1.2', '1.5.0'], 0, '>=1.2.0 <2.0.0'],
    [['security'], 1, 'CVE-2021-46743'],
    [['abandoned'], 1, 'symfony/mailer'],
    [['license', '--distribute', '--no-dev'], 1, 'GPL'],
    [['links', '--project'], 1, '404'],
    [['php', '8.0'], 1, 'block PHP 8.0'],
    [['framework', 'spatie/laravel-permission'], 0, 'Laravel 12'],
    [['cmd', 'require', 'monolog/monolog'], 0, 'monolog/monolog:^3.8'],
    [['dashboard', 'monolog/monolog', 'firebase/php-jwt'], 1, 'CVE'],
    [['export', '--format', 'cyclonedx', '-o', 'sbom.json'], 0, 'Exported'],
    [['doctor', '--offline'], 1, 'vendor matches lock'],
    // usage errors
    [['nope'], 2, ''],
    [['info'], 2, ''],
];

$pass = $fail = 0;
$seen = [];
foreach ($cases as [$args, $code, $needle]) {
    $label = implode(' ', $args);
    [$rc, $out, $err] = run($bin, $args, $env, "$tmp/demo");
    $ok = $rc === $code && ($needle === '' || str_contains($out . $err, $needle));
    if (!$ok) {
        echo "FAIL  $label  (exit $rc, wanted $code" . ($needle !== '' && !str_contains($out . $err, $needle) ? ", missing \"$needle\"" : '') . ")\n" . substr($out . $err, 0, 1500) . "\n";
        $fail++;
        continue;
    }
    $pass++;
    if ($code !== 2) {
        $seen[$args[0]] = true;
    }
    if ($code !== 2 && $args[0] !== 'export') {
        [$rc2, $jout] = run($bin, [...$args, '--json'], $env, "$tmp/demo");
        $isCmd = $args[0] === 'cmd';
        if (json_decode($jout, true) === null) {
            echo "FAIL  $label --json  (invalid JSON)\n" . substr($jout, 0, 500) . "\n";
            $fail++;
        } elseif ($rc2 !== $rc && !$isCmd) {
            echo "FAIL  $label --json  (exit $rc2, text mode gave $rc)\n";
            $fail++;
        } else {
            $pass++;
        }
    }
}
// every tool has --help
foreach (['search', 'trending', 'updates', 'vendor', 'dependents', 'stats', 'info', 'version', 'compare', 'maintainers', 'docs', 'source', 'changelog', 'health', 'json', 'tree', 'lock', 'autoload', 'size', 'outdated', 'conflicts', 'constraint', 'security', 'abandoned', 'license', 'links', 'php', 'framework', 'cmd', 'dashboard', 'export', 'doctor'] as $t) {
    [$rc, $out] = run($bin, [$t, '--help'], $env, "$tmp/demo");
    if ($rc !== 0 || !str_contains($out, 'USAGE')) {
        echo "FAIL  $t --help\n";
        $fail++;
    } else {
        $pass++;
    }
}
echo "Tools exercised: " . count($seen) . "/32\n";
echo ($fail ? 'FAILED' : 'OK') . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

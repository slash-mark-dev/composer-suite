<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\HttpException;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class DoctorCommand extends Command
{
    public const NAME = 'doctor';
    public const TITLE = 'Installation Troubleshooter';
    public const SUMMARY = 'Diagnose composer install/update problems, or explain an error message';
    public const CATEGORY = 'Workflow';
    public const ALIASES = ['diagnose', 'fix'];
    public const USAGE = '[--error "<composer error text>" | --error-file <log>] [--offline]';
    public const OPTIONS = ['error' => 'value', 'error-file' => 'value', 'offline' => 'flag'];
    public const SHORT = ['e' => 'error'];
    public const HELP = [
        '(default)' => 'Check PHP, extensions, Composer, network, project files and platform requirements',
        '-e, --error "<text>"' => 'Paste a composer error message to get the cause and fix',
        '--error-file <log>' => 'Read the error from a file (e.g. composer update 2> err.log)',
        '--offline' => 'Skip network checks',
    ];
    public const EXAMPLES = ['sm-composer doctor', 'sm-composer doctor -e "Allowed memory size of 1610612736 bytes exhausted"', 'composer update 2> err.log; sm-composer doctor --error-file err.log'];

    /** @var array<int, array{group:string, level:string, check:string, detail:string, fix:?string}> */
    private array $results = [];

    public function run(): int
    {
        $err = $this->a->get('error');
        if ($f = $this->a->get('error-file')) {
            if (!is_file($f)) {
                throw new \RuntimeException("No such file: $f");
            }
            $err = (string) file_get_contents($f);
        }
        if ($err !== null) {
            return $this->explain($err);
        }
        $this->environment();
        $this->composer();
        if (!$this->a->has('offline')) {
            $this->network();
        }
        $this->projectChecks();

        $fails = count(array_filter($this->results, static fn ($r) => $r['level'] === 'fail'));
        $warns = count(array_filter($this->results, static fn ($r) => $r['level'] === 'warn'));
        $this->out(['results' => $this->results, 'failures' => $fails, 'warnings' => $warns], function (array $d) {
            Ui::title('Composer doctor', 'PHP ' . PHP_VERSION . ' · ' . PHP_OS_FAMILY);
            $group = null;
            foreach ($d['results'] as $r) {
                if ($r['group'] !== $group) {
                    $group = $r['group'];
                    Ui::section($group);
                }
                Ui::out('  ' . Ui::status($r['level'], Ui::pad($r['check'], 29) . Ui::c('gray', $r['detail'])));
                if ($r['fix'] && $r['level'] !== 'ok') {
                    Ui::out('      ' . Ui::c('green', '→ ' . $r['fix']));
                }
            }
            Ui::out();
            Ui::out($d['failures'] ? Ui::status('fail', $d['failures'] . ' problems, ' . $d['warnings'] . ' warnings') : ($d['warnings'] ? Ui::status('warn', 'No blockers, ' . $d['warnings'] . ' warnings') : Ui::status('ok', 'All checks passed')));
            Ui::out(Ui::c('gray', '  Got an error message? sm-composer doctor -e "<paste it>"'));
        });
        return $fails ? 1 : 0;
    }

    private function add(string $group, string $level, string $check, string $detail, ?string $fix = null): void
    {
        $this->results[] = compact('group', 'level', 'check', 'detail', 'fix');
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }

    private function environment(): void
    {
        $g = 'PHP environment';
        $this->add($g, PHP_VERSION_ID >= 80100 ? 'ok' : 'warn', 'PHP version', PHP_VERSION . ' (' . PHP_SAPI . ')', PHP_VERSION_ID < 80100 ? 'PHP 8.0 and older are end-of-life; upgrade to 8.2+' : null);
        $mem = (string) ini_get('memory_limit');
        $b = self::bytes($mem);
        $this->add($g, $b === -1 || $b >= 1536 * 1024 * 1024 ? 'ok' : 'warn', 'memory_limit', $mem, 'Composer can need 1.5 GB+: run with COMPOSER_MEMORY_LIMIT=-1 composer update');
        foreach (['openssl' => 'fail', 'json' => 'fail', 'mbstring' => 'warn', 'zip' => 'warn', 'curl' => 'warn', 'intl' => 'info'] as $ext => $lvl) {
            $ok = extension_loaded($ext);
            $fix = match ($ext) {
                'zip' => 'install php-zip, or make sure `unzip` is installed (otherwise Composer falls back to slow git clones)',
                'openssl' => 'enable extension=openssl in php.ini — Composer cannot use HTTPS without it',
                'curl' => 'enable extension=curl for faster parallel downloads',
                default => "enable the $ext extension in php.ini",
            };
            $this->add($g, $ok ? 'ok' : $lvl, "ext-$ext", $ok ? 'loaded' : 'missing', $ok ? null : $fix);
        }
        $this->add($g, ini_get('allow_url_fopen') ? 'ok' : 'warn', 'allow_url_fopen', ini_get('allow_url_fopen') ? 'on' : 'off', 'set allow_url_fopen=On (needed without ext-curl)');
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $procOk = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
        $this->add($g, $procOk ? 'ok' : 'fail', 'proc_open', $procOk ? 'available' : 'disabled', 'remove proc_open from disable_functions (Composer needs it for git/unzip). On shared hosting, build locally and upload vendor/');
        if (extension_loaded('xdebug')) {
            $this->add($g, 'warn', 'xdebug', 'loaded (Composer runs 2–5× slower)', 'Composer disables it automatically; set XDEBUG_MODE=off to be sure');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && !getenv('COMPOSER_ALLOW_SUPERUSER')) {
            $this->add($g, 'warn', 'running as root', 'plugins and scripts run with full privileges', 'run composer as a normal user, or set COMPOSER_ALLOW_SUPERUSER=1 in containers');
        }
        $free = @disk_free_space($this->dir);
        if ($free !== false) {
            $this->add($g, $free > 500 * 1024 * 1024 ? 'ok' : 'warn', 'disk space', Ui::bytes($free) . ' free', 'free up disk space or clear the cache: composer clear-cache');
        }
    }

    private function composer(): void
    {
        $g = 'Composer';
        $bin = null;
        $version = null;
        foreach (['composer', 'composer.phar'] as $cand) {
            $out = $this->exec($cand . ' --version --no-ansi 2>&1');
            if ($out !== null && preg_match('/Composer (?:version )?(\d+\.\d+\.\d+)/', $out, $m)) {
                [$bin, $version] = [$cand, $m[1]];
                break;
            }
        }
        if (!$bin && is_file($this->dir . '/composer.phar')) {
            [$bin, $version] = ['./composer.phar', '?'];
        }
        if (!$bin) {
            $this->add($g, 'fail', 'composer binary', 'not found in PATH', 'install from https://getcomposer.org/download/');
            return;
        }
        $major = (int) $version;
        $this->add($g, $major >= 2 ? 'ok' : 'fail', 'composer version', "$bin $version", $major < 2 ? 'Composer 1 is unsupported by Packagist since 2025 — run: composer self-update --2' : null);
        if ($major >= 2 && Semver::compare($version, '2.7.0') < 0) {
            $this->add($g, 'warn', 'composer up to date', "$version is old", 'composer self-update');
        }
        $home = getenv('COMPOSER_HOME') ?: ($this->exec($bin . ' config --global home 2>/dev/null') ?? '');
        $home = trim((string) $home);
        if ($home !== '') {
            $this->add($g, is_writable($home) || !file_exists($home) ? 'ok' : 'fail', 'COMPOSER_HOME', $home, "fix permissions: chown -R \$(whoami) $home");
        }
        $unzip = $this->exec('unzip -v 2>&1') !== null || $this->exec('7z 2>&1') !== null;
        $this->add($g, $unzip || extension_loaded('zip') ? 'ok' : 'warn', 'unzip / 7z', $unzip ? 'available' : 'not found', 'install unzip (apt install unzip) for faster, safer extraction');
        $git = $this->exec('git --version 2>&1');
        $this->add($g, $git ? 'ok' : 'warn', 'git', $git ? trim($git) : 'not found', 'install git — needed for dev-* branches and source installs');
    }

    private function network(): void
    {
        $g = 'Network';
        foreach (['repo.packagist.org' => Packagist::repo() . '/packages.json', 'github.com (dist downloads)' => 'https://api.github.com/rate_limit'] as $label => $url) {
            try {
                $r = Http::request($url, 'GET', null, [], 0);
                $lvl = $r['status'] < 400 ? 'ok' : ($r['status'] === 403 ? 'warn' : 'fail');
                $this->add($g, $lvl, $label, 'HTTP ' . $r['status'] . ' in ' . (int) ($r['time'] * 1000) . ' ms', $lvl === 'ok' ? null : 'check firewall/proxy; for GitHub rate limits add a token: composer config -g github-oauth.github.com <token>');
            } catch (HttpException $e) {
                $msg = $e->getMessage();
                $fix = str_contains($msg, 'SSL') || str_contains($msg, 'certificate')
                    ? 'outdated CA certificates: update ca-certificates, or set openssl.cafile in php.ini'
                    : 'check internet / proxy settings (HTTPS_PROXY), or a firewall blocking ' . parse_url($url, PHP_URL_HOST);
                $this->add($g, 'fail', $label, $msg, $fix);
            }
        }
        $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: getenv('HTTP_PROXY') ?: getenv('http_proxy');
        if ($proxy) {
            $this->add($g, 'info', 'proxy', preg_replace('#//[^@/]+@#', '//***@', $proxy) ?? '', null);
        }
    }

    private function projectChecks(): void
    {
        $g = 'Project (' . (realpath($this->dir) ?: $this->dir) . ')';
        $jf = $this->dir . '/composer.json';
        if (!is_file($jf)) {
            $this->add($g, 'info', 'composer.json', 'none here — skipping project checks', 'run inside your project or pass -d <dir>');
            return;
        }
        try {
            $p = Project::load($this->dir);
        } catch (\RuntimeException $e) {
            $this->add($g, 'fail', 'composer.json', $e->getMessage(), 'fix the JSON syntax (trailing commas and missing quotes are the usual culprits)');
            return;
        }
        $issues = JsonCommand::validate($p->json, null);
        $errs = array_filter($issues, static fn ($i) => $i['level'] === 'error');
        $this->add($g, $errs ? 'fail' : ($issues ? 'warn' : 'ok'), 'composer.json valid', $errs ? reset($errs)['message'] : ($issues ? count($issues) . ' warnings (sm-composer json --validate)' : 'valid'), $errs ? 'sm-composer json --validate for all problems' : null);
        if (!$p->hasLock()) {
            $this->add($g, 'warn', 'composer.lock', 'missing', 'run composer update once and commit composer.lock for reproducible installs');
        } else {
            $fresh = $p->lockIsFresh();
            $this->add($g, $fresh === false ? 'fail' : 'ok', 'lock in sync', $fresh === false ? 'composer.json changed after the last update' : 'content-hash matches', 'composer update --lock (keeps versions) or composer update <changed packages>');
            // platform requirements of the locked set vs the PHP running now
            $php = PHP_VERSION;
            $override = $p->json['config']['platform']['php'] ?? null;
            $badPhp = [];
            $badExt = [];
            foreach (array_merge([['name' => 'root', 'require' => $p->json['require'] ?? []]], array_values($p->locked())) as $x) {
                foreach ($x['require'] ?? [] as $dep => $c) {
                    if ($dep === 'php' && Semver::isValid((string) $c) && !Semver::satisfies($php, (string) $c)) {
                        $badPhp[] = $x['name'] . " ($c)";
                    } elseif (str_starts_with((string) $dep, 'ext-') && !extension_loaded(substr((string) $dep, 4))) {
                        $badExt[substr((string) $dep, 4)][] = $x['name'];
                    }
                }
            }
            $this->add($g, $badPhp ? 'fail' : 'ok', 'PHP satisfies lock', $badPhp ? 'PHP ' . $php . ' rejected by ' . implode(', ', array_slice($badPhp, 0, 4)) . (count($badPhp) > 4 ? ' …' : '') : 'PHP ' . $php . ' OK for every package', 'use the PHP version the lock was built for' . ($override ? '' : ', or set "config": {"platform": {"php": "' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '"}} and composer update'));
            if ($override && Semver::compare((string) $override, $php) > 0) {
                $this->add($g, 'warn', 'platform override', "config.platform.php=$override but you run $php", 'packages may use syntax your PHP cannot run; align the override with production');
            }
            $this->add($g, $badExt ? 'fail' : 'ok', 'required extensions', $badExt ? 'missing: ' . implode(', ', array_map(static fn ($e, $by) => "ext-$e (" . implode(', ', array_slice($by, 0, 2)) . ')', array_keys($badExt), $badExt)) : 'all present', 'install them, e.g. apt install ' . implode(' ', array_map(static fn ($e) => 'php-' . $e, array_keys($badExt))));
            $vendor = $p->dir . '/' . $p->vendorDir();
            if (!is_dir($vendor)) {
                $this->add($g, 'warn', 'vendor/', 'not installed', 'composer install');
            } else {
                $inst = $p->installed();
                $miss = [];
                foreach ($p->locked() as $n => $x) {
                    if (($inst[$n]['version'] ?? null) !== $x['version']) {
                        $miss[] = $n;
                    }
                }
                $this->add($g, $miss ? 'fail' : 'ok', 'vendor matches lock', $miss ? count($miss) . ' differ: ' . implode(', ', array_slice($miss, 0, 4)) : 'in sync', 'composer install');
                $this->add($g, is_file($vendor . '/autoload.php') ? 'ok' : 'fail', 'vendor/autoload.php', is_file($vendor . '/autoload.php') ? 'present' : 'missing', 'composer dump-autoload');
            }
        }
        $plugins = [];
        foreach ($p->locked() as $n => $x) {
            if (($x['type'] ?? '') === 'composer-plugin') {
                $plugins[] = $n;
            }
        }
        $allowed = $p->json['config']['allow-plugins'] ?? [];
        $blocked = array_filter($plugins, static fn ($n) => !($allowed === true || ($allowed[$n] ?? null) === true || self::wildcardAllowed($allowed, $n)));
        if ($plugins) {
            $this->add($g, $blocked ? 'warn' : 'ok', 'allow-plugins', $blocked ? 'not allowed: ' . implode(', ', $blocked) : count($plugins) . ' plugins allowed', 'composer config allow-plugins.' . (reset($blocked) ?: 'vendor/plugin') . ' true');
        }
        if (is_file($p->dir . '/auth.json')) {
            $ignored = is_file($p->dir . '/.gitignore') && str_contains((string) file_get_contents($p->dir . '/.gitignore'), 'auth.json');
            $this->add($g, $ignored ? 'ok' : 'warn', 'auth.json', $ignored ? 'present and git-ignored' : 'present but NOT in .gitignore', 'add auth.json to .gitignore — it contains credentials');
        }
    }

    private static function wildcardAllowed(array|bool $allowed, string $name): bool
    {
        if (!is_array($allowed)) {
            return (bool) $allowed;
        }
        foreach ($allowed as $pattern => $v) {
            if ($v === true && fnmatch((string) $pattern, $name)) {
                return true;
            }
        }
        return false;
    }

    private function exec(string $cmd): ?string
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $p = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        if (!is_resource($p)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        return $code === 0 ? $out : null;
    }

    /** Known composer error messages → cause + fix */
    private function explain(string $text): int
    {
        $rules = [
            ['/Allowed memory size of \d+ bytes exhausted|memory_limit/i', 'Composer ran out of memory while resolving dependencies.', ['COMPOSER_MEMORY_LIMIT=-1 composer update', 'update only what you need: composer update vendor/package -W', 'make sure you use Composer 2 (much lower memory use)']],
            ['/Your requirements could not be resolved to an installable set/i', 'Two or more packages need versions of something that cannot exist together.', ['read the "Problem 1" lines: they name the package and the clashing constraints', 'see what blocks it: sm-composer why <package> --local', 'test an addition first: sm-composer conflicts --with vendor/package', 'allow transitive upgrades: composer update vendor/package -W']],
            ['/requires php ([^\s,]+).*your php version \(([\d.]+)\)|php version \(([\d.]+)\) does not satisfy that requirement/i', 'A package needs a different PHP version than the one running Composer.', ['check exact blockers: sm-composer php <your-version>', 'if production runs a different PHP, pin it: composer config platform.php 8.2', 'or switch the CLI PHP (update-alternatives / Herd / Laragon / brew link)']],
            ['/requires ext-([a-z0-9_]+)/i', 'A PHP extension is missing on this machine.', ['install it (Debian/Ubuntu: apt install php-<ext>; Windows: enable extension=<ext> in php.ini)', 'check every missing one: sm-composer doctor', 'only if it exists in production: composer install --ignore-platform-req=ext-<ext>']],
            ['/The lock file is not up to date|lock file is not up to date with the latest changes in composer\.json/i', 'composer.json was edited after composer.lock was generated.', ['composer update --lock   (refresh the hash, keep versions)', 'or composer update <the packages you changed>']],
            ['/zip extension and unzip\/7z commands are both missing/i', 'Composer cannot extract downloaded archives.', ['apt install unzip   or   enable extension=zip in php.ini']],
            ['/curl error 60|SSL certificate problem|unable to get local issuer certificate/i', 'Your PHP cannot verify HTTPS certificates (outdated CA bundle, or a corporate proxy).', ['update CA certificates (apt install ca-certificates / update Windows)', 'set openssl.cafile=/path/to/cacert.pem in php.ini (download from curl.se/ca/cacert.pem)', 'behind a proxy that inspects TLS, add its CA to that bundle — never disable verification']],
            ['/curl error (6|7|28)|Could not resolve host|Connection timed out|failed to open stream: Connection/i', 'Network problem reaching Packagist or GitHub.', ['check internet / DNS', 'set HTTPS_PROXY=http://proxy:port if you are behind a proxy', 'retry with: composer update -vvv to see which host fails']],
            ['/API rate limit|Could not authenticate against github\.com|GitHub API limit/i', 'GitHub rate-limited anonymous downloads.', ['create a token at github.com/settings/tokens (no scopes needed for public repos)', 'composer config -g github-oauth.github.com <token>']],
            ['/proc_open\(\) has been disabled|proc_open.*not available/i', 'Your host disabled proc_open, which Composer needs.', ['remove proc_open from disable_functions in php.ini', 'on shared hosting: run composer locally and upload vendor/']],
            ['/allow-plugins|blocked by your allow-plugins config/i', 'Composer 2.2+ blocks plugins until you allow them.', ['composer config allow-plugins.<vendor/plugin> true', 'only allow plugins you trust — they run code during install']],
            ['/Class "?([\\\\\w]+)"? not found/i', 'PHP cannot find a class at runtime.', ['composer dump-autoload', 'check the file path/namespace: sm-composer autoload', 'Linux is case-sensitive: the file name must match the class name exactly']],
            ['/Permission denied|failed to open stream: Permission/i', 'Composer cannot write to vendor/ or its cache.', ['fix ownership: sudo chown -R $(whoami) vendor ~/.cache/composer', 'avoid running composer with sudo']],
            ['/minimum-stability|does not match your minimum-stability|found .* but these were not loaded, likely because it conflicts with another require/i', 'Only a pre-release (dev/beta/RC) matches, but your project only accepts stable releases.', ['require it with a flag: composer require vendor/pkg:^2.0@beta', 'or set "minimum-stability": "dev", "prefer-stable": true in composer.json']],
            ['/could not find (a matching version of )?package|Could not find package/i', 'The package name or version does not exist on Packagist (typo, private package, or wrong version).', ['search it: sm-composer search <name>', 'see available versions: sm-composer version vendor/package --all', 'private packages need a "repositories" entry in composer.json']],
            ['/is abandoned, you should avoid using it/i', 'A dependency is no longer maintained (warning, not an error).', ['find replacements: sm-composer abandoned']],
            ['/Root composer\.json requires ([\w\/.-]+) ([^,]+), found .* but it does not match the constraint/i', 'Your composer.json asks for a version that no release satisfies.', ['check which versions exist: sm-composer constraint "<your constraint>" --package <package>', 'loosen or fix the constraint in composer.json']],
            ['/Your lock file does not contain a compatible set of packages/i', 'composer install on a machine whose PHP/extensions differ from where composer.lock was built.', ['run sm-composer doctor on that machine', 'build the lock with the production PHP: composer config platform.php <version> && composer update']],
            ['/Failed to download .* from dist|The "https:\/\/.*\.zip" file could not be downloaded/i', 'A package archive download failed.', ['retry; clear the cache: composer clear-cache', 'fall back to git: composer install --prefer-source']],
        ];
        $hits = [];
        foreach ($rules as [$re, $cause, $fixes]) {
            if (preg_match($re, $text, $m)) {
                $hits[] = ['match' => trim($m[0]), 'cause' => $cause, 'fixes' => $fixes];
            }
        }
        $pkgs = [];
        if (preg_match_all('#\b([a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*)\b#', strtolower($text), $mm)) {
            $pkgs = array_values(array_unique(array_filter($mm[1], static fn ($n) => Packagist::validName($n) && !str_contains($n, 'packagist.org') && !str_contains($n, 'github.com') && !str_starts_with($n, 'vendor/'))));
        }
        $this->out(['diagnoses' => $hits, 'packages_mentioned' => array_slice($pkgs, 0, 10)], function (array $d) {
            Ui::title('Error diagnosis');
            Ui::out();
            if (!$d['diagnoses']) {
                Ui::out(Ui::status('warn', 'No known pattern matched. Run the command again with -vvv and try sm-composer doctor for a full environment check.'));
            }
            foreach ($d['diagnoses'] as $h) {
                Ui::out(Ui::c('bold.yellow', '● ' . $h['cause']));
                Ui::out(Ui::c('gray', '  matched: ' . Ui::truncate($h['match'], 90)));
                foreach ($h['fixes'] as $f) {
                    Ui::out('  ' . Ui::c('green', '→ ') . $f);
                }
                Ui::out();
            }
            if ($d['packages_mentioned']) {
                Ui::out(Ui::c('gray', '  Packages in the error: ') . implode(', ', $d['packages_mentioned']));
                Ui::out(Ui::c('gray', '  Inspect one: sm-composer why ' . $d['packages_mentioned'][0] . ' --local'));
            }
        });
        return $hits ? 0 : 1;
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class JsonCommand extends Command
{
    public const NAME = 'json';
    public const TITLE = 'composer.json Viewer';
    public const SUMMARY = 'Readable view of composer.json with validation and best-practice checks';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['view', 'validate'];
    public const USAGE = '[path/to/composer.json | vendor/package[:ver]] [--validate] [--section <key>]';
    public const OPTIONS = ['validate' => 'flag', 'section' => 'value', 'strict' => 'flag'];
    public const SHORT = ['s' => 'section'];
    public const HELP = [
        '[path]' => 'A composer.json file or folder (default: ./composer.json)',
        'vendor/package' => "View a published package's composer.json from Packagist",
        '--validate' => 'Only print problems; exit 1 on errors (CI friendly)',
        '--strict' => 'With --validate, warnings also fail',
        '-s, --section <key>' => 'Show one key, e.g. require, autoload, scripts',
    ];
    public const EXAMPLES = ['sm-composer json', 'sm-composer json --validate --strict', 'sm-composer json laravel/framework --section require', 'sm-composer json ../api/composer.json'];

    public function run(): int
    {
        $target = $this->a->arg(0);
        $lock = null;
        if ($target !== null && !file_exists($target) && Packagist::validName(explode(':', $target)[0])) {
            [$name, $ver] = $this->pkgWithVersion($target);
            $row = $ver ? Packagist::findVersion($name, $ver) : Packagist::latest($name);
            if (!$row) {
                throw new \InvalidArgumentException("Version $ver of $name not found");
            }
            $json = array_diff_key($row, array_flip(['dist', 'source', 'version', 'version_normalized', 'time', 'notification-url', 'installation-source', 'uid']));
            $label = "$name:{$row['version']} (from Packagist)";
        } else {
            $file = $target ?? $this->dir . '/composer.json';
            if (is_dir($file)) {
                $file = rtrim($file, '/') . '/composer.json';
            }
            if (!is_file($file)) {
                throw new \RuntimeException("No composer.json at $file");
            }
            try {
                $json = Project::readJson($file);
            } catch (\RuntimeException $e) {
                if ($this->json) {
                    Ui::json(['file' => $file, 'composer' => null, 'issues' => [['level' => 'error', 'message' => $e->getMessage()]]]);
                } else {
                    Ui::out(Ui::status('fail', $e->getMessage()));
                }
                return 1;
            }
            $label = realpath($file) ?: $file;
            $p = Project::load(dirname($file));
            $lock = $p->hasLock() ? $p : null;
        }

        $issues = self::validate($json, $lock);
        $errors = count(array_filter($issues, static fn ($i) => $i['level'] === 'error'));
        $warnings = count($issues) - $errors;
        $failed = $errors > 0 || ($this->a->has('strict') && $warnings > 0);

        $only = $this->a->get('section');
        if ($only !== null && !array_key_exists($only, $json)) {
            throw new \InvalidArgumentException("composer.json has no \"$only\" key. Keys: " . implode(', ', array_keys($json)));
        }
        $show = static fn (string $key): bool => $only === null || $only === $key;
        if ($this->json) {
            Ui::json(['file' => $label, 'composer' => $only !== null ? [$only => $json[$only]] : $json, 'issues' => $issues]);
            return $failed ? 1 : 0;
        }
        if ($this->a->has('validate')) {
            Ui::title('Validate composer.json', $label);
            $this->printIssues($issues);
            return $failed ? 1 : 0;
        }

        Ui::title($json['name'] ?? '(unnamed project)', $label);
        if (!empty($json['description'])) {
            Ui::out(Ui::wrap((string) $json['description']));
        }
        Ui::out();
        Ui::kv([
            'Type' => $json['type'] ?? null,
            'License' => isset($json['license']) ? implode(', ', (array) $json['license']) : null,
            'Version' => $json['version'] ?? null,
            'Homepage' => $json['homepage'] ?? null,
            'Min stability' => isset($json['minimum-stability']) ? $json['minimum-stability'] . (!empty($json['prefer-stable']) ? ' (prefer-stable)' : '') : null,
            'Keywords' => isset($json['keywords']) ? implode(', ', $json['keywords']) : null,
            'Authors' => isset($json['authors']) ? implode(', ', array_map(static fn ($a) => $a['name'] ?? '?', $json['authors'])) : null,
        ]);
        $locked = $lock ? $lock->locked() : [];
        foreach (['require', 'require-dev'] as $key) {
            if (empty($json[$key]) || !$show($key)) {
                continue;
            }
            Ui::section($key . ' (' . count($json[$key]) . ')');
            $t = [];
            foreach ($json[$key] as $n => $c) {
                $plat = Packagist::isPlatform((string) $n);
                $lv = $locked[strtolower((string) $n)]['version'] ?? null;
                $t[] = [$plat ? Ui::c('magenta', (string) $n) : Ui::c('cyan', (string) $n), Ui::c('yellow', (string) $c), $lv ? Ui::c('green', $lv) : ($plat ? Ui::c('gray', 'platform') : ''), Ui::c('gray', Semver::isValid((string) $c) ? Semver::explain((string) $c) : 'invalid constraint')];
            }
            Ui::table(['Package', 'Constraint', $lock ? 'Locked' : 'Kind', 'Means'], $t, [], 3);
        }
        foreach (['autoload', 'autoload-dev'] as $key) {
            if (empty($json[$key]) || !$show($key)) {
                continue;
            }
            Ui::section($key);
            foreach ($json[$key] as $type => $map) {
                foreach ((array) $map as $ns => $path) {
                    Ui::out('  ' . Ui::c('gray', str_pad((string) $type, 9)) . (is_string($ns) ? Ui::c('magenta', $ns) . ' → ' : '') . implode(', ', (array) $path));
                }
            }
        }
        if (!empty($json['scripts']) && $show('scripts')) {
            Ui::section('scripts');
            foreach ($json['scripts'] as $k => $cmd) {
                Ui::out('  ' . Ui::c('cyan', Ui::pad((string) $k, 20)) . Ui::c('gray', implode(' && ', (array) $cmd)));
            }
        }
        foreach (['config', 'repositories', 'extra', 'conflict', 'replace', 'provide', 'suggest', 'bin'] as $key) {
            if (!empty($json[$key]) && $show($key)) {
                Ui::section($key);
                foreach (explode("\n", (string) json_encode($json[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $l) {
                    Ui::out('  ' . Ui::c('gray', $l));
                }
            }
        }
        Ui::out();
        Ui::out(Ui::c('bold', 'Checks'));
        $this->printIssues($issues);
        return 0;
    }

    private function printIssues(array $issues): void
    {
        if (!$issues) {
            Ui::out(Ui::status('ok', 'No problems found'));
            return;
        }
        foreach ($issues as $i) {
            Ui::out(Ui::status($i['level'] === 'error' ? 'fail' : 'warn', $i['message']));
        }
    }

    /** @return array<int, array{level:string, message:string}> */
    public static function validate(array $json, ?Project $project = null): array
    {
        $is = [];
        $add = static function (string $level, string $msg) use (&$is): void {
            $is[] = ['level' => $level, 'message' => $msg];
        };
        $type = $json['type'] ?? 'library';
        if (isset($json['name']) && !Packagist::validName((string) $json['name'])) {
            $add('error', 'name "' . $json['name'] . '" must be lowercase vendor/package');
        }
        if (isset($json['name']) && $json['name'] !== strtolower((string) $json['name'])) {
            $add('error', 'name must be lowercase');
        }
        if ($type !== 'project' && !isset($json['name'])) {
            $add('warn', 'no "name" — required to publish on Packagist');
        }
        if ($type !== 'project' && empty($json['description'])) {
            $add('warn', 'no "description"');
        }
        if (empty($json['license'])) {
            $add('warn', 'no "license" — use an SPDX id such as MIT, or "proprietary"');
        }
        if (isset($json['version'])) {
            $add('warn', '"version" is set; Packagist reads versions from git tags, so this usually causes mismatches');
        }
        $req = $json['require'] ?? [];
        $dev = $json['require-dev'] ?? [];
        if (!isset($req['php'])) {
            $add('warn', 'no "php" requirement — declare which PHP versions you support');
        }
        foreach (['require' => $req, 'require-dev' => $dev] as $key => $list) {
            foreach ($list as $n => $c) {
                $n = (string) $n;
                $c = (string) $c;
                if (!Packagist::isPlatform($n) && !Packagist::validName($n)) {
                    $add('error', "$key: \"$n\" is not a valid package name");
                }
                if ($n !== strtolower($n)) {
                    $add('warn', "$key: \"$n\" should be lowercase");
                }
                if (!Semver::isValid($c)) {
                    $add('error', "$key: $n has an invalid constraint \"$c\"");
                    continue;
                }
                if (trim($c) === '*' && !Packagist::isPlatform($n)) {
                    $add('warn', "$key: $n uses \"*\" — any version, including future breaking majors");
                } elseif (preg_match('/^\s*>=?[^<,|]*$/', $c) && !Packagist::isPlatform($n)) {
                    $add('warn', "$key: $n \"$c\" has no upper bound; prefer ^ (e.g. " . Semver::suggest((string) preg_replace('/^>=?\s*/', '', $c)) . ')');
                }
                if (preg_match('/(^|\s|\|)dev-/', $c)) {
                    $add('warn', "$key: $n points at a branch ($c) — builds are not reproducible");
                }
                if (str_contains($c, '@dev') || str_contains($c, '@alpha')) {
                    $add('warn', "$key: $n allows unstable releases ($c)");
                }
            }
        }
        foreach (array_intersect_key($req, $dev) as $n => $_) {
            $add('warn', "$n is in both require and require-dev");
        }
        foreach (['phpunit/phpunit', 'mockery/mockery', 'phpstan/phpstan', 'squizlabs/php_codesniffer', 'friendsofphp/php-cs-fixer', 'fakerphp/faker', 'fzaninotto/faker', 'vimeo/psalm', 'pestphp/pest', 'barryvdh/laravel-debugbar'] as $tool) {
            if (isset($req[$tool])) {
                $add('warn', "$tool is a dev tool but sits in require — move it to require-dev");
            }
        }
        if (($json['minimum-stability'] ?? 'stable') !== 'stable' && empty($json['prefer-stable'])) {
            $add('warn', 'minimum-stability is "' . $json['minimum-stability'] . '" without "prefer-stable": true');
        }
        foreach (['psr-4', 'psr-0'] as $std) {
            foreach ($json['autoload'][$std] ?? [] as $ns => $_) {
                if ($ns !== '' && !str_ends_with((string) $ns, '\\')) {
                    $add('error', "autoload $std namespace \"$ns\" must end with a backslash (\"$ns\\\")");
                }
            }
        }
        if (!isset($json['autoload']) && $type === 'library') {
            $add('warn', 'library has no "autoload" section');
        }
        if ($project) {
            $fresh = $project->lockIsFresh();
            if ($fresh === false) {
                $add('error', 'composer.lock is out of date with composer.json — run `composer update --lock` (or `composer update`)');
            }
        }
        return $is;
    }
}

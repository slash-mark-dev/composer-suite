<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class PhpCommand extends Command
{
    public const NAME = 'php';
    public const TITLE = 'PHP Version Compatibility Checker';
    public const SUMMARY = 'Will your dependencies run on PHP 7.4 … 8.5?';
    public const CATEGORY = 'Security & compliance';
    public const ALIASES = ['php-compat', 'compat'];
    public const USAGE = '[<php-version>] | <vendor/package> [--php <ver>]';
    public const OPTIONS = ['php' => 'value', 'no-dev' => 'flag'];
    public const HELP = [
        '<php-version>' => 'Check your composer.lock against this PHP version (default: the PHP running now)',
        '<vendor/package>' => 'Show which release of a package supports each PHP version',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer php 8.4', 'sm-composer php 7.4 --no-dev', 'sm-composer php laravel/framework', 'sm-composer php guzzlehttp/guzzle --php 7.4'];

    private const MATRIX = ['7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    public function run(): int
    {
        $arg = $this->a->arg(0);
        if ($arg !== null && str_contains($arg, '/')) {
            return $this->package(Packagist::assertName($arg));
        }
        $target = $arg ?? $this->a->get('php') ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
        if (Semver::normalize($target) === null) {
            throw new \InvalidArgumentException("\"$target\" is not a PHP version");
        }
        $full = self::full($target);
        $p = $this->project();
        $p->requireLock();
        $locked = $p->locked(!$this->a->has('no-dev'));
        $direct = array_change_key_case($p->requires());
        $rootPhp = $p->json['require']['php'] ?? null;
        $platform = $p->json['config']['platform']['php'] ?? null;
        $bad = [];
        $ok = 0;
        $noReq = 0;
        foreach ($locked as $n => $x) {
            $c = $x['require']['php'] ?? null;
            if ($c === null) {
                $noReq++;
                $ok++;
                continue;
            }
            if (!Semver::isValid((string) $c) || Semver::satisfies($full, (string) $c)) {
                $ok++;
                continue;
            }
            $bad[$n] = ['name' => $n, 'version' => $x['version'], 'requires' => $c, 'direct' => isset($direct[$n]), 'dev' => $x['dev'], 'fix' => null];
        }
        if ($bad) {
            $versions = Packagist::versionsMany(array_keys($bad));
            foreach ($bad as $n => &$b) {
                foreach ($versions[$n] ?? [] as $v) {
                    $c = $v['require']['php'] ?? '*';
                    if (Semver::isStable($v['version']) && Semver::isValid($c) && Semver::satisfies($full, $c)) {
                        $b['fix'] = ['version' => $v['version'], 'level' => Semver::diff($b['version'], $v['version']), 'php' => $c];
                        break;
                    }
                }
            }
            unset($b);
        }
        $rootOk = $rootPhp === null || !Semver::isValid($rootPhp) || Semver::satisfies($full, $rootPhp);

        $this->out(['php' => $target, 'packages' => count($locked), 'compatible' => $ok, 'no_php_requirement' => $noReq, 'root_constraint' => $rootPhp, 'root_ok' => $rootOk, 'platform_override' => $platform, 'incompatible' => array_values($bad)], function (array $d) {
            Ui::title('PHP ' . $d['php'] . ' compatibility', $d['packages'] . ' locked packages');
            Ui::out();
            Ui::kv([
                'Your composer.json' => $d['root_constraint'] ? ($d['root_ok'] ? Ui::c('green', '✔ ') : Ui::c('red', '✖ ')) . 'php ' . $d['root_constraint'] : Ui::c('yellow', 'no php requirement declared'),
                'Platform override' => $d['platform_override'] ? 'config.platform.php = ' . $d['platform_override'] . Ui::c('gray', ' (composer resolves as if PHP were this version)') : null,
                'Compatible' => Ui::c('green', (string) $d['compatible']) . ' / ' . $d['packages'] . Ui::c('gray', ' (' . $d['no_php_requirement'] . ' declare no PHP requirement)'),
            ]);
            Ui::out();
            if (!$d['incompatible']) {
                Ui::out(Ui::status('ok', 'Every locked package supports PHP ' . $d['php']));
                return;
            }
            $t = [];
            foreach ($d['incompatible'] as $b) {
                $fix = !$b['fix'] ? Ui::c('red', 'no release supports it') : ($b['fix']['level'] === 'downgrade'
                    ? Ui::c('yellow', '↓ ' . $b['fix']['version']) . Ui::c('gray', ' (older, php ' . $b['fix']['php'] . ')')
                    : Ui::c('green', '↑ ' . $b['fix']['version']) . Ui::c('gray', ' (' . $b['fix']['level'] . ', php ' . $b['fix']['php'] . ')'));
                $t[] = [($b['direct'] ? Ui::c('bold.cyan', $b['name']) : Ui::c('cyan', $b['name'])) . ($b['dev'] ? Ui::c('yellow', ' dev') : ''), $b['version'], Ui::c('yellow', $b['requires']), $fix];
            }
            Ui::table(['Package', 'Locked', 'Needs PHP', 'Compatible release'], $t);
            Ui::out();
            Ui::out(Ui::status('fail', count($d['incompatible']) . ' packages block PHP ' . $d['php']));
            $up = array_filter($d['incompatible'], static fn ($b) => $b['fix'] && $b['fix']['level'] !== 'downgrade');
            $down = array_filter($d['incompatible'], static fn ($b) => $b['fix'] && $b['fix']['level'] === 'downgrade');
            if ($up) {
                Ui::out(Ui::c('gray', '  Upgrade: ') . Ui::c('green', 'composer update ' . implode(' ', array_column($up, 'name')) . ' -W') . Ui::c('gray', '  (run it on PHP ' . $d['php'] . ', then your tests)'));
            }
            if ($down) {
                Ui::out(Ui::c('gray', '  ' . count($down) . ' package(s) only work on PHP ' . $d['php'] . ' in older releases — upgrading the server PHP is usually the better fix.'));
            }
        });
        return $bad || !$rootOk ? 1 : 0;
    }

    private function package(string $name): int
    {
        $versions = array_values(array_filter(Packagist::versions($name), static fn ($v) => Semver::isStable($v['version'])));
        $only = $this->a->get('php');
        $phps = $only ? [$only] : self::MATRIX;
        $matrix = [];
        foreach ($phps as $php) {
            $full = self::full($php);
            $best = null;
            foreach ($versions as $v) {
                $c = $v['require']['php'] ?? '*';
                if (Semver::isValid($c) && Semver::satisfies($full, $c)) {
                    $best = $v;
                    break;
                }
            }
            $matrix[] = ['php' => $php, 'latest_supported' => $best['version'] ?? null, 'released' => $best['time'] ?? null, 'constraint' => $best['require']['php'] ?? null];
        }
        $latest = $versions[0]['version'] ?? null;

        return $this->out(['package' => $name, 'latest' => $latest, 'latest_php' => $versions[0]['require']['php'] ?? null, 'matrix' => $matrix], function (array $d) {
            Ui::title('PHP support · ' . $d['package'], 'latest ' . $d['latest'] . ' needs php ' . ($d['latest_php'] ?? 'any'));
            Ui::out();
            $t = [];
            foreach ($d['matrix'] as $m) {
                $st = $m['latest_supported'] === null ? Ui::c('red', '✖ no release') : ($m['latest_supported'] === $d['latest'] ? Ui::c('green', '✔ latest (' . $m['latest_supported'] . ')') : Ui::c('yellow', '◐ up to ' . $m['latest_supported']));
                $t[] = [Ui::c('bold', 'PHP ' . $m['php']), $st, Ui::c('gray', $m['constraint'] ?? ''), Ui::c('gray', Ui::date($m['released']))];
            }
            Ui::table(['Runtime', 'Newest compatible release', 'Its PHP constraint', 'Released'], $t);
            $first = null;
            foreach ($d['matrix'] as $m) {
                if ($m['latest_supported']) {
                    $first = $m;
                    break;
                }
            }
            if ($first) {
                Ui::out();
                Ui::out(Ui::c('gray', '  Pin for an older server: composer require ' . $d['package'] . ':' . Semver::suggest((string) $first['latest_supported']) . '  (on PHP ' . $first['php'] . ')'));
            }
        });
    }

    /** "8.4" => "8.4.99" so that ">=8.4.1" style lower bounds still match the series. */
    private static function full(string $v): string
    {
        return preg_match('/^\d+\.\d+$/', $v) ? $v . '.99' : $v;
    }
}

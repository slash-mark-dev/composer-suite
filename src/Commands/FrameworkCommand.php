<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class FrameworkCommand extends Command
{
    public const NAME = 'framework';
    public const TITLE = 'Framework Compatibility Checker';
    public const SUMMARY = 'Laravel / Symfony / other framework support of packages';
    public const CATEGORY = 'Security & compliance';
    public const ALIASES = ['fw', 'frameworks'];
    public const USAGE = '<vendor/package> | --laravel <ver> | --symfony <ver>';
    public const OPTIONS = ['laravel' => 'value', 'symfony' => 'value', 'no-dev' => 'flag'];
    public const HELP = [
        '<vendor/package>' => 'Which Laravel / Symfony / CakePHP / Yii / Laminas versions each release supports',
        '--laravel <ver>' => 'Upgrade check: can your project move to this Laravel version? (e.g. 12)',
        '--symfony <ver>' => 'Upgrade check for Symfony (e.g. 7.3)',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer framework spatie/laravel-permission', 'sm-composer framework --laravel 12', 'sm-composer framework --symfony 7.3 --no-dev'];

    /** framework => [package prefixes that represent it, versions to test] */
    private const FRAMEWORKS = [
        'Laravel' => [['laravel/framework', 'illuminate/'], ['8', '9', '10', '11', '12', '13']],
        'Symfony' => [['symfony/framework-bundle', 'symfony/http-kernel', 'symfony/console', 'symfony/dependency-injection', 'symfony/http-foundation', 'symfony/routing', 'symfony/event-dispatcher', 'symfony/config'], ['5.4', '6.4', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0']],
        'CakePHP' => [['cakephp/cakephp', 'cakephp/core'], ['4', '5']],
        'Yii' => [['yiisoft/yii2'], ['2.0']],
        'Laminas' => [['laminas/laminas-mvc', 'laminas/laminas-servicemanager'], ['3', '4']],
        'Slim' => [['slim/slim'], ['3', '4']],
    ];

    public function run(): int
    {
        if ($v = $this->a->get('laravel')) {
            return $this->upgrade('Laravel', $v);
        }
        if ($v = $this->a->get('symfony')) {
            return $this->upgrade('Symfony', $v);
        }
        return $this->matrix($this->pkg());
    }

    /** Find the framework requirement inside a require map. @return array<string, string> framework => constraint */
    private static function detect(array $require): array
    {
        $out = [];
        foreach (self::FRAMEWORKS as $fw => [$prefixes]) {
            foreach ($require as $dep => $c) {
                foreach ($prefixes as $pre) {
                    if ($dep === $pre || (str_ends_with($pre, '/') && str_starts_with((string) $dep, $pre))) {
                        $out[$fw] ??= (string) $c;
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    private static function probe(string $v): string
    {
        $parts = explode('.', $v);
        return count($parts) === 1 ? "$v.99.99" : (count($parts) === 2 ? "$v.99" : $v);
    }

    private function matrix(string $name): int
    {
        $versions = array_values(array_filter(Packagist::versions($name), static fn ($v) => Semver::isStable($v['version'])));
        $used = [];
        foreach ($versions as $v) {
            foreach (self::detect($v['require'] ?? []) as $fw => $_) {
                $used[$fw] = true;
            }
        }
        $result = [];
        foreach (array_keys($used) as $fw) {
            foreach (self::FRAMEWORKS[$fw][1] as $fv) {
                $best = null;
                $c = null;
                foreach ($versions as $v) {
                    $req = self::detect($v['require'] ?? [])[$fw] ?? null;
                    if ($req !== null && Semver::isValid($req) && Semver::satisfies(self::probe($fv), $req)) {
                        $best = $v;
                        $c = $req;
                        break;
                    }
                }
                $result[$fw][] = ['framework_version' => $fv, 'package_version' => $best['version'] ?? null, 'released' => $best['time'] ?? null, 'constraint' => $c];
            }
        }
        return $this->out(['package' => $name, 'latest' => $versions[0]['version'] ?? null, 'frameworks' => $result], function (array $d) {
            Ui::title('Framework support · ' . $d['package'], 'latest ' . $d['latest']);
            if (!$d['frameworks']) {
                Ui::out();
                Ui::out(Ui::status('ok', 'Framework-agnostic: no dependency on Laravel, Symfony, CakePHP, Yii, Laminas or Slim components.'));
                return;
            }
            foreach ($d['frameworks'] as $fw => $rows) {
                Ui::section($fw);
                foreach ($rows as $r) {
                    $label = Ui::pad(Ui::c('bold', "$fw {$r['framework_version']}"), 16);
                    Ui::out('  ' . $label . ($r['package_version'] === null ? Ui::c('red', '✖ not supported') : ($r['package_version'] === $d['latest'] ? Ui::c('green', '✔ ' . $r['package_version']) : Ui::c('yellow', '◐ up to ' . $r['package_version'])) . Ui::c('gray', '  ' . ($r['constraint'] ?? ''))));
                }
            }
        });
    }

    private function upgrade(string $fw, string $target): int
    {
        $p = $this->project();
        $p->requireLock();
        $probe = self::probe($target);
        $locked = $p->locked(!$this->a->has('no-dev'));
        $rows = [];
        $check = [];
        foreach ($locked as $n => $x) {
            $req = self::detect($x['require'] ?? [])[$fw] ?? null;
            if ($req === null) {
                continue;
            }
            $ok = Semver::isValid($req) && Semver::satisfies($probe, $req);
            $rows[$n] = ['name' => $n, 'version' => $x['version'], 'constraint' => $req, 'ok' => $ok, 'fix' => null, 'dev' => $x['dev']];
            if (!$ok) {
                $check[] = $n;
            }
        }
        if ($check) {
            $vs = Packagist::versionsMany($check);
            foreach ($check as $n) {
                // smallest step up first: oldest release newer than the locked one
                $cur = $rows[$n]['version'];
                $newer = array_values(array_filter($vs[$n] ?? [], static fn ($v) => Semver::compare($v['version'], $cur) > 0));
                usort($newer, static fn ($a, $b) => Semver::compare($a['version'], $b['version']));
                $cands = $newer;
                foreach ($cands as $v) {
                    $req = self::detect($v['require'] ?? [])[$fw] ?? null;
                    if (Semver::isStable($v['version']) && $req && Semver::isValid($req) && Semver::satisfies($probe, $req)) {
                        $rows[$n]['fix'] = ['version' => $v['version'], 'level' => Semver::diff($rows[$n]['version'], $v['version'])];
                        break;
                    }
                }
            }
        }
        // the framework itself
        $core = null;
        foreach (self::FRAMEWORKS[$fw][0] as $pre) {
            if (!str_ends_with($pre, '/') && isset($locked[$pre])) {
                $core = ['name' => $pre, 'version' => $locked[$pre]['version']];
                break;
            }
        }
        $blockers = array_values(array_filter($rows, static fn ($r) => !$r['ok']));
        $noFix = array_filter($blockers, static fn ($r) => !$r['fix']);

        $this->out(['framework' => $fw, 'target' => $target, 'current' => $core, 'packages' => array_values($rows), 'blockers' => $blockers], function (array $d) use ($noFix) {
            Ui::title("Upgrade check: {$d['framework']} {$d['target']}", $d['current'] ? 'you are on ' . $d['current']['name'] . ' ' . $d['current']['version'] : '');
            Ui::out();
            if (!$d['packages']) {
                Ui::out(Ui::status('info', "No packages in composer.lock depend on {$d['framework']}."));
                return;
            }
            $t = [];
            foreach ($d['packages'] as $r) {
                $st = $r['ok'] ? Ui::c('green', '✔ ready') : ($r['fix'] ? Ui::c('yellow', '↑ upgrade to ' . $r['fix']['version']) . Ui::c('gray', ' (' . $r['fix']['level'] . ')') : Ui::c('red', '✖ no compatible release yet'));
                $t[] = [Ui::c('cyan', $r['name']) . ($r['dev'] ? Ui::c('yellow', ' dev') : ''), $r['version'], Ui::c('gray', $r['constraint']), $st];
            }
            Ui::table(['Package', 'Locked', "Allows {$d['framework']}", 'Status'], $t);
            Ui::out();
            $ready = count($d['packages']) - count($d['blockers']);
            Ui::out(Ui::status($d['blockers'] ? ($noFix ? 'fail' : 'warn') : 'ok', "$ready/" . count($d['packages']) . " framework-dependent packages already allow {$d['framework']} {$d['target']}" . ($noFix ? '; ' . count($noFix) . ' have no compatible release yet' : '')));
        });
        return $noFix ? 1 : 0;
    }
}

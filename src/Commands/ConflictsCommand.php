<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Resolver;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class ConflictsCommand extends Command
{
    public const NAME = 'conflicts';
    public const TITLE = 'Dependency Conflict Checker';
    public const SUMMARY = 'Detect version conflicts, or test if a new package fits your lock';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['conflict', 'check'];
    public const USAGE = '[--with <vendor/pkg[:constraint]>] [--php <version>] [--no-dev]';
    public const OPTIONS = ['with' => 'value', 'php' => 'value', 'no-dev' => 'flag', 'ignore-platform' => 'flag'];
    public const SHORT = ['w' => 'with'];
    public const HELP = [
        '(default)' => 'Check every requirement in composer.lock against the locked versions, PHP and extensions',
        '-w, --with <pkg>' => 'Can I add this package? Finds the newest version compatible with your lock',
        '--php <ver>' => 'Check against this PHP version instead of the one running (e.g. production server)',
        '--ignore-platform' => 'Skip php / ext-* checks',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer conflicts', 'sm-composer conflicts --php 8.1', 'sm-composer conflicts --with spatie/laravel-permission', 'sm-composer conflicts --with monolog/monolog:^2.0'];

    public function run(): int
    {
        $p = $this->project();
        $php = $this->a->get('php') ?? PHP_VERSION;
        if ($with = $this->a->get('with')) {
            return $this->canAdd($p, $with, $php);
        }
        if (!$p->hasLock()) {
            Ui::info('No composer.lock — resolving composer.json from Packagist to find conflicts…');
            $r = (new Resolver(!$this->a->has('no-dev')))->resolve($p->requires(!$this->a->has('no-dev')), 'root');
            $conf = array_map(static fn ($x) => ['type' => 'resolve', 'package' => $x['package'], 'required_by' => $x['by'], 'constraint' => $x['constraint'], 'have' => null, 'message' => $x['reason']], $r->problems);
            return $this->report($conf, 'composer.json (resolved)');
        }
        $locked = $p->locked(!$this->a->has('no-dev'));
        $provided = [];
        foreach ($locked as $n => $x) {
            foreach (['replace', 'provide'] as $k) {
                foreach ($x[$k] ?? [] as $pn => $pc) {
                    $provided[strtolower((string) $pn)][] = ['by' => $n, 'version' => $pc === 'self.version' ? $x['version'] : $pc, 'kind' => $k];
                }
            }
        }
        $conf = [];
        $sources = ['root' => ['version' => 'root', 'require' => $p->requires(!$this->a->has('no-dev'), true)]] + $locked;
        foreach ($sources as $by => $x) {
            foreach ($x['require'] ?? [] as $dep => $c) {
                $dep = strtolower((string) $dep);
                $c = (string) $c;
                if (Packagist::isPlatform($dep)) {
                    if ($this->a->has('ignore-platform')) {
                        continue;
                    }
                    if (preg_match('/^php(-64bit)?$/', $dep)) {
                        if (Semver::isValid($c) && !Semver::satisfies($php, $c)) {
                            $conf[] = ['type' => 'php', 'package' => 'php', 'required_by' => $by, 'constraint' => $c, 'have' => $php, 'message' => "$by needs PHP $c, you have $php"];
                        }
                    } elseif (str_starts_with($dep, 'ext-') && !extension_loaded(substr($dep, 4)) && !$this->a->get('php')) {
                        $conf[] = ['type' => 'extension', 'package' => $dep, 'required_by' => $by, 'constraint' => $c, 'have' => null, 'message' => "$by needs the $dep PHP extension, which isn't loaded"];
                    }
                    continue;
                }
                $have = $locked[$dep]['version'] ?? null;
                if ($have === null) {
                    if (isset($provided[$dep])) {
                        continue;
                    }
                    if ($by !== 'root' || !$p->isDevRequirement($dep) || !$this->a->has('no-dev')) {
                        $conf[] = ['type' => 'missing', 'package' => $dep, 'required_by' => $by, 'constraint' => $c, 'have' => null, 'message' => "$by requires $dep $c but it is not in composer.lock"];
                    }
                    continue;
                }
                if (!Semver::isValid($c) || str_starts_with($have, 'dev-')) {
                    continue;
                }
                if (!Semver::satisfies($have, $c)) {
                    $conf[] = ['type' => 'version', 'package' => $dep, 'required_by' => $by, 'constraint' => $c, 'have' => $have, 'message' => "$by requires $dep $c but $have is locked"];
                }
            }
            foreach ($x['conflict'] ?? [] as $dep => $c) {
                $dep = strtolower((string) $dep);
                $have = $locked[$dep]['version'] ?? null;
                if ($have && Semver::isValid((string) $c) && Semver::satisfies($have, (string) $c)) {
                    $conf[] = ['type' => 'declared', 'package' => $dep, 'required_by' => $by, 'constraint' => (string) $c, 'have' => $have, 'message' => "$by declares a conflict with $dep $c, and $have is locked"];
                }
            }
        }
        foreach ($provided as $virtual => $list) {
            if (count($list) > 1 && $list[0]['kind'] === 'replace' && isset($locked[$virtual])) {
                $conf[] = ['type' => 'replace', 'package' => $virtual, 'required_by' => implode(', ', array_column($list, 'by')), 'constraint' => '', 'have' => $locked[$virtual]['version'], 'message' => "$virtual is installed but also replaced by " . implode(', ', array_column($list, 'by'))];
            }
        }
        return $this->report($conf, 'composer.lock · PHP ' . $php);
    }

    private function report(array $conf, string $label): int
    {
        $this->out(['checked' => $label, 'conflicts' => $conf], function (array $d) {
            Ui::title('Dependency conflicts', $d['checked']);
            Ui::out();
            if (!$d['conflicts']) {
                Ui::out(Ui::status('ok', 'No conflicts — every requirement is satisfied'));
                return;
            }
            $by = [];
            foreach ($d['conflicts'] as $c) {
                $by[$c['type']][] = $c;
            }
            $titles = ['version' => 'Version conflicts', 'php' => 'PHP version', 'extension' => 'Missing PHP extensions', 'missing' => 'Missing packages', 'declared' => 'Declared conflicts', 'replace' => 'Replaced twice', 'resolve' => 'Unresolvable requirements'];
            foreach ($by as $type => $list) {
                Ui::out(Ui::c('bold', $titles[$type] ?? $type) . Ui::c('gray', ' (' . count($list) . ')'));
                foreach ($list as $c) {
                    Ui::out('  ' . Ui::status('fail', $c['message']));
                }
                Ui::out();
            }
            $hint = [];
            if (isset($by['version']) || isset($by['missing'])) {
                $hint[] = 'composer update ' . implode(' ', array_unique(array_column(array_merge($by['version'] ?? [], $by['missing'] ?? []), 'package'))) . ' -W';
            }
            if (isset($by['php'])) {
                $hint[] = 'pin your server PHP in composer.json: "config": {"platform": {"php": "<server version>"}} then composer update';
            }
            if (isset($by['extension'])) {
                $hint[] = 'install/enable: ' . implode(' ', array_unique(array_column($by['extension'], 'package')));
            }
            foreach ($hint as $h) {
                Ui::out(Ui::c('gray', '  Try: ') . Ui::c('green', $h));
            }
        });
        return $conf ? 1 : 0;
    }

    private function canAdd(\SlashMark\ComposerSuite\Project $p, string $spec, string $php): int
    {
        $name = $spec;
        $constraint = '*';
        if (preg_match('#^([^:@\s]+/[^:@\s]+)[:@ ](.+)$#', $spec, $m)) {
            [$name, $constraint] = [$m[1], $m[2]];
        }
        $name = Packagist::assertName($name);
        $locked = $p->hasLock() ? $p->locked() : [];
        // what does the existing project demand of this package?
        $demands = [];
        foreach ($locked as $n => $x) {
            if (isset($x['require'][$name])) {
                $demands[$n] = (string) $x['require'][$name];
            }
        }
        $tried = [];
        $winner = null;
        foreach (Packagist::versions($name) as $v) {
            $ver = $v['version'];
            if (!Semver::isStable($ver) || ($constraint !== '*' && !Semver::satisfies($ver, $constraint))) {
                continue;
            }
            $problems = [];
            foreach ($v['require'] ?? [] as $dep => $c) {
                $dep = strtolower((string) $dep);
                $c = (string) $c;
                if (preg_match('/^php(-64bit)?$/', $dep)) {
                    if (Semver::isValid($c) && !Semver::satisfies($php, $c)) {
                        $problems[] = "needs PHP $c (have $php)";
                    }
                    continue;
                }
                if (Packagist::isPlatform($dep)) {
                    if (str_starts_with($dep, 'ext-') && !extension_loaded(substr($dep, 4))) {
                        $problems[] = "needs $dep";
                    }
                    continue;
                }
                $have = $locked[$dep]['version'] ?? null;
                if ($have && Semver::isValid($c) && !str_starts_with($have, 'dev-') && !Semver::satisfies($have, $c)) {
                    $problems[] = "needs $dep $c (locked $have)";
                }
            }
            foreach ($demands as $n => $c) {
                if (Semver::isValid($c) && !Semver::satisfies($ver, $c)) {
                    $problems[] = "$n requires $name $c";
                }
            }
            $tried[] = ['version' => $ver, 'problems' => $problems];
            if (!$problems) {
                $winner = $ver;
                break;
            }
            if (count($tried) >= 15) {
                break;
            }
        }
        $cmd = $winner ? 'composer require ' . $name . ':' . Semver::suggest($winner) : null;

        $this->out(['package' => $name, 'constraint' => $constraint, 'compatible' => $winner, 'command' => $cmd, 'tried' => $tried], function (array $d) use ($php) {
            Ui::title("Can I add {$d['package']}?", 'against your composer.lock · PHP ' . $php);
            Ui::out();
            foreach ($d['tried'] as $t) {
                if ($t['problems']) {
                    Ui::out(Ui::status('fail', Ui::c('bold', $t['version']) . '  ' . implode('; ', $t['problems'])));
                } else {
                    Ui::out(Ui::status('ok', Ui::c('bold', $t['version']) . '  fits your project'));
                }
            }
            Ui::out();
            if ($d['compatible']) {
                Ui::out('  ' . Ui::c('green', $d['command']));
                if ($d['tried'][0]['version'] !== $d['compatible']) {
                    Ui::out(Ui::c('gray', "  The newest release ({$d['tried'][0]['version']}) needs other upgrades first; {$d['compatible']} works today."));
                }
            } else {
                Ui::out(Ui::status('warn', 'No recent stable release fits without upgrading other packages. Try: composer require ' . $d['package'] . ' -W'));
            }
        });
        return $winner ? 0 : 1;
    }
}

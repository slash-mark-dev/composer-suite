<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class OutdatedCommand extends Command
{
    public const NAME = 'outdated';
    public const TITLE = 'Dependency Update Checker';
    public const SUMMARY = 'Find outdated dependencies and the exact commands to update them';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['updates-check', 'od'];
    public const USAGE = '[--direct] [--major|--minor|--patch] [--no-dev] [--strict] [--commands]';
    public const OPTIONS = ['direct' => 'flag', 'major' => 'flag', 'minor' => 'flag', 'patch' => 'flag', 'no-dev' => 'flag', 'strict' => 'flag', 'commands' => 'flag', 'ignore' => 'multi'];
    public const SHORT = ['D' => 'direct', 'c' => 'commands'];
    public const HELP = [
        '-D, --direct' => 'Only packages listed in composer.json',
        '--major / --minor / --patch' => 'Only that kind of update',
        '--no-dev' => 'Skip dev packages',
        '--ignore <pkg>' => 'Ignore packages (repeatable, comma separated)',
        '--strict' => 'Exit 1 when anything is outdated (CI)',
        '-c, --commands' => 'Print ready-to-run composer commands',
    ];
    public const EXAMPLES = ['sm-composer outdated', 'sm-composer outdated --direct --commands', 'sm-composer outdated --major', 'sm-composer outdated --strict --no-dev'];

    public function run(): int
    {
        $p = $this->project();
        $p->requireLock();
        $locked = $p->locked(!$this->a->has('no-dev'));
        $direct = array_change_key_case($p->requires(!$this->a->has('no-dev')));
        $ignore = array_map('strtolower', $this->a->list('ignore'));
        $names = array_values(array_filter(array_keys($locked), fn ($n) => !in_array($n, $ignore, true) && (!$this->a->has('direct') || isset($direct[$n]))));
        Ui::info('Checking ' . count($names) . ' packages against Packagist…');
        $all = Packagist::versionsMany($names);
        $rows = [];
        foreach ($names as $n) {
            $cur = (string) $locked[$n]['version'];
            $vs = array_column($all[$n] ?? [], 'version');
            if (!$vs || str_starts_with($cur, 'dev-')) {
                continue;
            }
            $latest = Semver::latest($vs);
            if ($latest === null || Semver::compare($latest, $cur) <= 0) {
                continue;
            }
            $level = Semver::diff($cur, $latest);
            $constraint = $direct[$n] ?? null;
            // best version reachable with `composer update` under the current constraint
            $allowed = null;
            $sameMajor = null;
            $curMajor = explode('.', (string) Semver::normalize($cur))[0];
            foreach (Semver::rsort($vs) as $v) {
                if (!Semver::isStable($v) || Semver::compare($v, $cur) <= 0) {
                    continue;
                }
                if ($allowed === null && $constraint && Semver::isValid($constraint) && Semver::satisfies($v, $constraint)) {
                    $allowed = $v;
                }
                if ($sameMajor === null && explode('.', (string) Semver::normalize($v))[0] === $curMajor) {
                    $sameMajor = $v;
                }
            }
            $latestRow = null;
            foreach ($all[$n] as $r) {
                if ($r['version'] === $latest) {
                    $latestRow = $r;
                }
            }
            $rows[] = [
                'name' => $n, 'current' => $cur, 'latest' => $latest, 'level' => $level,
                'direct' => isset($direct[$n]), 'dev' => $locked[$n]['dev'], 'constraint' => $constraint,
                'latest_allowed' => $constraint ? $allowed : $sameMajor,
                'latest_released' => $latestRow['time'] ?? null,
                'current_released' => $locked[$n]['time'] ?? null,
                'latest_php' => $latestRow['require']['php'] ?? null,
            ];
        }
        foreach (['major', 'minor', 'patch'] as $lv) {
            if ($this->a->has($lv)) {
                $rows = array_values(array_filter($rows, static fn ($r) => $r['level'] === $lv));
            }
        }
        $order = ['major' => 0, 'minor' => 1, 'patch' => 2];
        usort($rows, static fn ($a, $b) => [$order[$a['level']] ?? 3, !$a['direct'], $a['name']] <=> [$order[$b['level']] ?? 3, !$b['direct'], $b['name']]);
        $commands = $this->commands($rows);
        $counts = array_count_values(array_column($rows, 'level'));

        $this->out(['outdated' => $rows, 'summary' => $counts, 'commands' => $commands], function (array $d) use ($names) {
            Ui::title('Outdated dependencies', count($d['outdated']) . ' of ' . count($names) . ' packages');
            Ui::out();
            if (!$d['outdated']) {
                Ui::out(Ui::status('ok', 'Everything is on the latest stable release 🎉'));
                return;
            }
            $t = [];
            foreach ($d['outdated'] as $r) {
                $lvl = match ($r['level']) {
                    'major' => Ui::c('red', '● major'),
                    'minor' => Ui::c('yellow', '● minor'),
                    default => Ui::c('green', '● patch'),
                };
                $safe = $r['latest_allowed'] ? Ui::c('green', $r['latest_allowed']) : Ui::c('gray', '—');
                $t[] = [($r['direct'] ? Ui::c('bold.cyan', $r['name']) : Ui::c('cyan', $r['name'])) . ($r['dev'] ? Ui::c('yellow', ' dev') : ''), $r['current'], $safe, Ui::c('bold', $r['latest']), $lvl, Ui::c('gray', Ui::ago($r['latest_released']))];
            }
            Ui::table(['Package', 'Current', 'Safe update', 'Latest', 'Type', 'Released'], $t);
            Ui::out();
            Ui::out('  ' . implode(' · ', array_map(static fn ($k, $v) => "$v $k", array_keys($d['summary']), $d['summary'])) . Ui::c('gray', '   bold = in your composer.json · safe update = allowed by your constraint (or same major)'));
            if ($this->a->has('commands') && $d['commands']) {
                Ui::section('Commands');
                foreach ($d['commands'] as $c) {
                    Ui::out('  ' . Ui::c('gray', '# ' . $c['label']));
                    Ui::out('  ' . Ui::c('green', $c['command']));
                }
            } elseif ($d['commands']) {
                Ui::out(Ui::c('gray', '  Add --commands to get the composer commands.'));
            }
        });
        return ($this->a->has('strict') && $rows) ? 1 : 0;
    }

    private function commands(array $rows): array
    {
        $out = [];
        $safe = array_values(array_unique(array_column(array_filter($rows, static fn ($r) => $r['latest_allowed']), 'name')));
        if ($safe) {
            $out[] = ['label' => 'Safe updates within your constraints (no composer.json changes)', 'command' => 'composer update ' . implode(' ', $safe) . ' --with-dependencies'];
        }
        $bumpProd = $bumpDev = [];
        foreach ($rows as $r) {
            if ($r['direct'] && $r['constraint'] && !Semver::satisfies($r['latest'], $r['constraint'])) {
                $spec = $r['name'] . ':' . Semver::suggest($r['latest']);
                if ($r['dev']) {
                    $bumpDev[] = $spec;
                } else {
                    $bumpProd[] = $spec;
                }
            }
        }
        if ($bumpProd) {
            $out[] = ['label' => 'Bump constraints to the new majors/minors (review changelogs first: sm-composer changelog <pkg>)', 'command' => 'composer require ' . implode(' ', array_map('escapeshellarg', $bumpProd)) . ' -W'];
        }
        if ($bumpDev) {
            $out[] = ['label' => 'Same for dev packages', 'command' => 'composer require --dev ' . implode(' ', array_map('escapeshellarg', $bumpDev)) . ' -W'];
        }
        return $out;
    }
}

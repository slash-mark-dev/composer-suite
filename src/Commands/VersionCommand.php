<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class VersionCommand extends Command
{
    public const NAME = 'version';
    public const TITLE = 'Version Checker';
    public const SUMMARY = 'Latest vs installed versions of packages';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['versions', 'v'];
    public const USAGE = '[vendor/package …] [--all] [--pre]';
    public const OPTIONS = ['all' => 'flag', 'pre' => 'flag', 'limit' => 'value'];
    public const SHORT = ['a' => 'all', 'n' => 'limit'];
    public const HELP = [
        '(no package)' => 'Check every direct requirement in ./composer.json',
        '-a, --all' => 'List the full release history (one package)',
        '--pre' => 'Count alpha/beta/RC as "latest"',
        '-n, --limit <n>' => 'Rows for --all (default 30)',
    ];
    public const EXAMPLES = ['sm-composer version monolog/monolog', 'sm-composer version laravel/framework --all', 'sm-composer version'];

    public function run(): int
    {
        $names = array_map([Packagist::class, 'assertName'], $this->a->pos);
        $project = null;
        try {
            $project = Project::load($this->dir, false);
        } catch (\RuntimeException) {
        }
        if (!$names) {
            if (!$project || !$project->json) {
                throw new \SlashMark\ComposerSuite\UsageException('Give a package name, or run inside a project with composer.json');
            }
            $names = array_keys($project->requires());
        }
        if (count($names) === 1 && $this->a->has('all')) {
            return $this->history($names[0], $project);
        }
        $all = Packagist::versionsMany($names);
        $installed = $project ? $project->installed() : [];
        $rows = [];
        foreach ($names as $n) {
            $vs = array_column($all[$n] ?? [], 'version');
            if (!$vs) {
                $rows[] = ['name' => $n, 'error' => 'not found on Packagist'];
                continue;
            }
            $latest = Semver::latest($vs, !$this->a->has('pre'));
            $constraint = $project?->json['require'][$n] ?? $project?->json['require-dev'][$n] ?? null;
            $current = $project?->lockedVersion($n) ?? ($installed[$n]['version'] ?? null);
            $allowed = null;
            if ($constraint && Semver::isValid($constraint)) {
                foreach (Semver::rsort($vs) as $v) {
                    if (Semver::isStable($v) && Semver::satisfies($v, $constraint)) {
                        $allowed = $v;
                        break;
                    }
                }
            }
            $time = null;
            foreach ($all[$n] as $row) {
                if ($row['version'] === $latest) {
                    $time = $row['time'] ?? null;
                }
            }
            $rows[] = [
                'name' => $n,
                'latest' => $latest,
                'released' => $time,
                'installed' => $current,
                'constraint' => $constraint,
                'latest_allowed' => $allowed,
                'status' => $current === null ? 'not-installed' : Semver::diff($current, (string) $latest),
            ];
        }

        $behind = count(array_filter($rows, static fn ($r) => in_array($r['status'] ?? '', ['major', 'minor', 'patch'], true)));
        return $this->out(['packages' => $rows], function (array $d) use ($behind) {
            Ui::title('Version check', count($d['packages']) . ' packages · ' . $behind . ' behind');
            Ui::out();
            $t = [];
            foreach ($d['packages'] as $r) {
                if (isset($r['error'])) {
                    $t[] = [Ui::c('cyan', $r['name']), Ui::c('red', $r['error']), '', '', '', ''];
                    continue;
                }
                $st = match ($r['status']) {
                    'same' => Ui::c('green', '✔ up to date'),
                    'patch' => Ui::c('green', '● patch available'),
                    'minor' => Ui::c('yellow', '● minor available'),
                    'major' => Ui::c('red', '● major available'),
                    'not-installed' => Ui::c('gray', '—'),
                    'downgrade' => Ui::c('magenta', 'ahead of latest'),
                    default => Ui::c('gray', $r['status']),
                };
                $t[] = [Ui::c('cyan', $r['name']), $r['installed'] ?? '-', Ui::c('yellow', $r['constraint'] ?? '-'), $r['latest_allowed'] ?? '-', Ui::c('bold', (string) $r['latest']) . Ui::c('gray', ' ' . Ui::ago($r['released'])), $st];
            }
            Ui::table(['Package', 'Installed', 'Constraint', 'Allowed', 'Latest', 'Status'], $t);
        });
    }

    private function history(string $name, ?Project $project): int
    {
        $versions = Packagist::versions($name, true);
        $current = $project?->lockedVersion($name);
        $limit = $this->a->int('limit', 30);
        $rows = [];
        foreach (array_slice($versions, 0, $limit) as $v) {
            $rows[] = ['version' => $v['version'], 'released' => $v['time'] ?? null, 'stability' => Semver::stability($v['version']), 'php' => $v['require']['php'] ?? null, 'installed' => $current === $v['version']];
        }
        return $this->out(['package' => $name, 'total' => count($versions), 'versions' => $rows], function (array $d) {
            Ui::title("Release history · {$d['package']}", $d['total'] . ' versions');
            Ui::out();
            $t = [];
            foreach ($d['versions'] as $r) {
                $v = match ($r['stability']) {
                    'stable' => Ui::c('green', $r['version']),
                    'dev' => Ui::c('gray', $r['version']),
                    default => Ui::c('yellow', $r['version']),
                };
                $t[] = [$v . ($r['installed'] ? Ui::c('bold.cyan', '  ← installed') : ''), Ui::date($r['released']), Ui::c('gray', Ui::ago($r['released'])), $r['stability'], $r['php'] ?? '-'];
            }
            Ui::table(['Version', 'Released', 'Age', 'Stability', 'PHP'], $t);
        });
    }
}

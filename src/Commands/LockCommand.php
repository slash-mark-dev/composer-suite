<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class LockCommand extends Command
{
    public const NAME = 'lock';
    public const TITLE = 'Lock File Analyzer';
    public const SUMMARY = 'Analyze composer.lock: freshness, sources, ages, orphans, diffs';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['lockfile'];
    public const USAGE = '[--packages] [--diff <old composer.lock>] [--no-dev]';
    public const OPTIONS = ['packages' => 'flag', 'diff' => 'value', 'no-dev' => 'flag', 'file' => 'value'];
    public const SHORT = ['p' => 'packages'];
    public const HELP = [
        '-p, --packages' => 'List every locked package with version, type, source and age',
        '--diff <file>' => 'Compare against another lock file (added / removed / upgraded / downgraded)',
        '--no-dev' => 'Ignore packages-dev',
        '--file <path>' => 'A different composer.lock (or folder)',
    ];
    public const EXAMPLES = ['sm-composer lock', 'sm-composer lock --packages', 'git show HEAD~5:composer.lock > old.lock && sm-composer lock --diff old.lock'];

    public function run(): int
    {
        $p = $this->project(false);
        $p->requireLock();
        $lock = $p->lock;
        if ($other = $this->a->get('diff')) {
            return $this->diff($lock, $other);
        }
        $pkgs = $p->locked(!$this->a->has('no-dev'));
        $prod = array_filter($pkgs, static fn ($x) => !$x['dev']);
        $devp = array_filter($pkgs, static fn ($x) => $x['dev']);

        // which packages are reachable from the root requirements?
        $reach = [];
        $stack = array_map('strtolower', array_keys($p->requires(true, false)));
        $provided = [];
        foreach ($pkgs as $n => $x) {
            foreach (array_merge(array_keys($x['replace'] ?? []), array_keys($x['provide'] ?? [])) as $r) {
                $provided[strtolower((string) $r)][] = $n;
            }
        }
        while ($stack) {
            $n = array_pop($stack);
            if (isset($reach[$n])) {
                continue;
            }
            $reach[$n] = true;
            foreach (array_keys($pkgs[$n]['require'] ?? []) as $d) {
                $stack[] = strtolower((string) $d);
            }
            foreach ($provided[$n] ?? [] as $by) {
                $stack[] = $by;
            }
        }
        $orphans = $p->json ? array_values(array_diff(array_keys($pkgs), array_keys($reach))) : [];

        $types = [];
        $sources = ['packagist' => 0, 'vcs / custom repo' => 0, 'path' => 0, 'branch (dev-*)' => 0];
        $branchPkgs = [];
        $custom = [];
        $ages = ['< 6 months' => 0, '6–12 months' => 0, '1–2 years' => 0, '2+ years' => 0];
        $oldest = [];
        $licenses = [];
        foreach ($pkgs as $n => $x) {
            $types[$x['type'] ?? 'library'] = ($types[$x['type'] ?? 'library'] ?? 0) + 1;
            $v = (string) $x['version'];
            if (($x['dist']['type'] ?? '') === 'path' || ($x['source']['type'] ?? '') === 'path') {
                $sources['path']++;
            } elseif (str_starts_with($v, 'dev-') || str_ends_with($v, '-dev')) {
                $sources['branch (dev-*)']++;
                $branchPkgs[] = "$n ($v)";
            } elseif (isset($x['notification-url']) && str_contains((string) $x['notification-url'], 'packagist.org')) {
                $sources['packagist']++;
            } else {
                $sources['vcs / custom repo']++;
                $custom[] = $n;
            }
            $d = \SlashMark\ComposerSuite\Ui::days($x['time'] ?? null);
            if ($d !== null) {
                $ages[match (true) { $d < 183 => '< 6 months', $d < 365 => '6–12 months', $d < 730 => '1–2 years', default => '2+ years' }]++;
                $oldest[$n] = $x['time'];
            }
            foreach ((array) ($x['license'] ?? ['(none)']) as $l) {
                $licenses[$l] = ($licenses[$l] ?? 0) + 1;
            }
        }
        asort($oldest);
        arsort($licenses);
        $installed = $p->installed();
        $mismatch = [];
        if ($installed) {
            foreach ($pkgs as $n => $x) {
                $iv = $installed[$n]['version'] ?? null;
                if ($iv === null) {
                    $mismatch[] = ['package' => $n, 'locked' => $x['version'], 'installed' => null];
                } elseif ($iv !== $x['version']) {
                    $mismatch[] = ['package' => $n, 'locked' => $x['version'], 'installed' => $iv];
                }
            }
        }

        $data = [
            'file' => realpath($p->dir . '/composer.lock'),
            'fresh' => $p->json ? $p->lockIsFresh() : null,
            'content_hash' => $lock['content-hash'] ?? null,
            'expected_hash' => $p->json ? $p->contentHash() : null,
            'packages' => count($prod), 'packages_dev' => count($devp),
            'minimum_stability' => $lock['minimum-stability'] ?? 'stable',
            'prefer_stable' => $lock['prefer-stable'] ?? false,
            'stability_flags' => $lock['stability-flags'] ?? [],
            'platform' => $lock['platform'] ?? [],
            'platform_dev' => $lock['platform-dev'] ?? [],
            'platform_overrides' => $lock['platform-overrides'] ?? [],
            'plugin_api_version' => $lock['plugin-api-version'] ?? null,
            'types' => $types, 'sources' => $sources, 'branch_packages' => $branchPkgs, 'custom_repo_packages' => $custom,
            'ages' => $ages, 'oldest' => array_slice($oldest, 0, 5, true), 'licenses' => $licenses,
            'orphans' => $orphans, 'vendor_mismatch' => $mismatch, 'vendor_installed' => (bool) $installed,
            'list' => array_values(array_map(static fn ($x) => ['name' => $x['name'], 'version' => $x['version'], 'type' => $x['type'] ?? 'library', 'dev' => $x['dev'], 'time' => $x['time'] ?? null, 'license' => $x['license'] ?? []], $pkgs)),
        ];
        $issues = (int) ($data['fresh'] === false) + count($mismatch) + count($orphans);

        $this->out($data, function (array $d) {
            Ui::title('composer.lock analysis', (string) $d['file']);
            Ui::out();
            Ui::kv([
                'Freshness' => $d['fresh'] === null ? Ui::c('gray', 'unknown (no composer.json)') : ($d['fresh'] ? Ui::c('green', '✔ in sync with composer.json') : Ui::c('red', '✖ OUT OF DATE — composer.json changed since the last update')),
                'Packages' => $d['packages'] . ' prod + ' . $d['packages_dev'] . ' dev',
                'Stability' => $d['minimum_stability'] . ($d['prefer_stable'] ? ' (prefer-stable)' : '') . ($d['stability_flags'] ? Ui::c('yellow', '  flags: ' . implode(', ', array_keys($d['stability_flags']))) : ''),
                'Platform' => implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($d['platform']), $d['platform'])) ?: 'none',
                'Platform overrides' => $d['platform_overrides'] ? implode(', ', array_map(static fn ($k, $v) => "$k=$v", array_keys($d['platform_overrides']), $d['platform_overrides'])) : null,
                'Plugin API' => $d['plugin_api_version'],
                'Types' => implode(', ', array_map(static fn ($k, $v) => "$k ×$v", array_keys($d['types']), $d['types'])),
            ]);
            Ui::section('Where packages come from');
            $tot = max(1, array_sum($d['sources']));
            foreach ($d['sources'] as $k => $v) {
                if ($v) {
                    Ui::out('  ' . Ui::pad($k, 20) . Ui::bar($v / $tot, 25) . ' ' . $v);
                }
            }
            if ($d['branch_packages']) {
                Ui::out(Ui::status('warn', 'Locked to branches (not reproducible): ' . implode(', ', $d['branch_packages'])));
            }
            Ui::section('Release age of locked versions');
            $tot = max(1, array_sum($d['ages']));
            foreach ($d['ages'] as $k => $v) {
                Ui::out('  ' . Ui::pad($k, 14) . Ui::bar($v / $tot, 25, $k === '2+ years' ? 'red' : ($k === '1–2 years' ? 'yellow' : 'green')) . ' ' . $v);
            }
            if ($d['oldest']) {
                Ui::out(Ui::c('gray', '  Oldest: ' . implode(', ', array_map(static fn ($n, $t) => "$n (" . Ui::ago($t) . ')', array_keys($d['oldest']), $d['oldest']))));
            }
            Ui::section('Licenses');
            Ui::out('  ' . implode('  ', array_map(static fn ($k, $v) => Ui::c('cyan', (string) $k) . ' ×' . $v, array_keys($d['licenses']), $d['licenses'])));
            Ui::section('Integrity');
            Ui::out($d['orphans'] ? Ui::status('warn', 'In the lock but not required by anything: ' . implode(', ', $d['orphans']) . Ui::c('gray', ' — run composer update to drop them')) : Ui::status('ok', 'Every locked package is reachable from composer.json'));
            if (!$d['vendor_installed']) {
                Ui::out(Ui::status('info', 'vendor/ not installed — run composer install'));
            } elseif ($d['vendor_mismatch']) {
                foreach ($d['vendor_mismatch'] as $m) {
                    Ui::out(Ui::status('fail', $m['package'] . ': lock says ' . $m['locked'] . ', vendor has ' . ($m['installed'] ?? 'nothing') . Ui::c('gray', ' — run composer install')));
                }
            } else {
                Ui::out(Ui::status('ok', 'vendor/ matches composer.lock'));
            }
            if ($this->a->has('packages')) {
                Ui::section('Locked packages');
                $t = [];
                foreach ($d['list'] as $x) {
                    $t[] = [Ui::c('cyan', $x['name']), Ui::c('green', $x['version']), $x['dev'] ? Ui::c('yellow', 'dev') : 'prod', $x['type'], implode(', ', (array) $x['license']), Ui::c('gray', Ui::ago($x['time']))];
                }
                Ui::table(['Package', 'Version', 'Env', 'Type', 'License', 'Released'], $t);
            }
        });
        return $issues > 0 ? 1 : 0;
    }

    private function diff(array $new, string $otherPath): int
    {
        if (is_dir($otherPath)) {
            $otherPath = rtrim($otherPath, '/') . '/composer.lock';
        }
        if (!is_file($otherPath)) {
            throw new \RuntimeException("No lock file at $otherPath");
        }
        $old = self::packagesOf(Project::readJson($otherPath));
        $cur = self::packagesOf($new);
        $rows = [];
        foreach (array_unique([...array_keys($old), ...array_keys($cur)]) as $n) {
            $a = $old[$n]['version'] ?? null;
            $b = $cur[$n]['version'] ?? null;
            if ($a === $b) {
                continue;
            }
            $change = match (true) {
                $a === null => 'added',
                $b === null => 'removed',
                default => Semver::compare($b, $a) > 0 ? 'upgraded' : 'downgraded',
            };
            $rows[] = ['package' => $n, 'change' => $change, 'from' => $a, 'to' => $b, 'level' => $a && $b ? ($change === 'upgraded' ? Semver::diff($a, $b) : Semver::diff($b, $a)) : null, 'dev' => ($cur[$n] ?? $old[$n])['dev']];
        }
        usort($rows, static fn ($x, $y) => [$x['change'], $x['package']] <=> [$y['change'], $y['package']]);
        $counts = array_count_values(array_column($rows, 'change'));

        return $this->out(['against' => $otherPath, 'changes' => $rows, 'summary' => $counts], function (array $d) {
            Ui::title('Lock diff', basename($d['against']) . ' → composer.lock');
            Ui::out();
            if (!$d['changes']) {
                Ui::out(Ui::status('ok', 'No package changes'));
                return;
            }
            $t = [];
            foreach ($d['changes'] as $r) {
                $sym = match ($r['change']) {
                    'added' => Ui::c('green', '+ added'), 'removed' => Ui::c('red', '- removed'),
                    'upgraded' => Ui::c('cyan', '↑ upgraded'), default => Ui::c('magenta', '↓ downgraded'),
                };
                $lvl = $r['level'] && $r['level'] !== 'unknown' ? ($r['level'] === 'major' ? Ui::c('red', 'major') : Ui::c('gray', $r['level'])) : '';
                $t[] = [$sym, Ui::c('cyan', $r['package']) . ($r['dev'] ? Ui::c('yellow', ' dev') : ''), $r['from'] ?? '', $r['to'] ?? '', $lvl];
            }
            Ui::table(['Change', 'Package', 'From', 'To', 'Level'], $t);
            Ui::out();
            Ui::out('  ' . implode(' · ', array_map(static fn ($k, $v) => "$v $k", array_keys($d['summary']), $d['summary'])));
        });
    }

    /** @return array<string, array> name => package */
    private static function packagesOf(array $lock): array
    {
        $out = [];
        foreach (['packages' => false, 'packages-dev' => true] as $k => $dev) {
            foreach ($lock[$k] ?? [] as $p) {
                $out[strtolower($p['name'])] = $p + ['dev' => $dev];
            }
        }
        ksort($out);
        return $out;
    }
}

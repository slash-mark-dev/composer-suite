<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Health;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Resolver;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class CompareCommand extends Command
{
    public const NAME = 'compare';
    public const TITLE = 'Package Compare Tool';
    public const SUMMARY = 'Compare packages side by side: versions, deps, metadata, health';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['vs', 'diff'];
    public const USAGE = '<vendor/a>[:ver] <vendor/b>[:ver] [more…]';
    public const OPTIONS = ['no-deep' => 'flag'];
    public const HELP = [
        '<pkg>:<ver>' => 'Compare specific versions, e.g. guzzlehttp/guzzle:6.5.8 guzzlehttp/guzzle:7.10.0',
        '--no-deep' => 'Skip resolving the full transitive dependency graph (faster)',
    ];
    public const EXAMPLES = [
        'sm-composer compare guzzlehttp/guzzle symfony/http-client',
        'sm-composer compare monolog/monolog:2.10.0 monolog/monolog:3.8.1',
        'sm-composer vs firebase/php-jwt lcobucci/jwt web-token/jwt-framework',
    ];

    public function run(): int
    {
        if (count($this->a->pos) < 2) {
            throw new \SlashMark\ComposerSuite\UsageException('Give at least two packages to compare');
        }
        $specs = array_map(fn ($s) => $this->pkgWithVersion($s), array_slice($this->a->pos, 0, 4));
        $names = array_unique(array_map(static fn ($s) => $s[0], $specs));
        Packagist::packageMany($names);
        Packagist::versionsMany($names);
        $adv = Packagist::advisories($names);

        $cols = [];
        foreach ($specs as [$name, $ver]) {
            $info = Packagist::package($name);
            $versions = Packagist::versions($name);
            $row = $ver ? Packagist::findVersion($name, $ver) : Packagist::latest($name);
            if (!$row) {
                throw new \InvalidArgumentException("Version $ver of $name not found");
            }
            $req = $row['require'] ?? [];
            $pkgDeps = array_filter($req, static fn ($k) => !Packagist::isPlatform((string) $k), ARRAY_FILTER_USE_KEY);
            $ext = array_keys(array_filter($req, static fn ($k) => str_starts_with((string) $k, 'ext-'), ARRAY_FILTER_USE_KEY));
            $deep = null;
            if (!$this->a->has('no-deep')) {
                $r = (new Resolver())->resolve($pkgDeps, $name);
                $deep = count($r->resolved);
            }
            $affecting = array_filter($adv[$name] ?? [], static fn ($a) => Semver::isValid((string) $a['affectedVersions']) && Semver::satisfies($row['version'], (string) $a['affectedVersions']));
            $health = Health::score($info, $versions, $adv[$name] ?? []);
            $cols[] = [
                'name' => $name,
                'version' => $row['version'],
                'released' => $row['time'] ?? null,
                'description' => $info['description'] ?? '',
                'license' => implode(', ', (array) ($row['license'] ?? [])),
                'php' => $req['php'] ?? 'any',
                'downloads_total' => $info['downloads']['total'] ?? 0,
                'downloads_monthly' => $info['downloads']['monthly'] ?? 0,
                'stars' => $info['favers'] ?? 0,
                'open_issues' => $info['github_open_issues'] ?? null,
                'dependents' => $info['dependents'] ?? 0,
                'releases' => count($versions),
                'direct_deps' => array_keys($pkgDeps),
                'extensions' => $ext,
                'transitive_deps' => $deep,
                'advisories' => count($affecting),
                'health' => $health['score'],
                'grade' => $health['grade'],
                'abandoned' => $info['abandoned'] ?? false,
                'require' => $req,
            ];
        }
        $shared = array_values(array_intersect(...array_map(static fn ($c) => $c['direct_deps'], $cols)));

        return $this->out(['packages' => $cols, 'shared_dependencies' => $shared], function (array $d) {
            $cols = $d['packages'];
            Ui::title('Compare', implode('  vs  ', array_map(static fn ($c) => $c['name'] . ':' . $c['version'], $cols)));
            Ui::out();
            // [label, key, better: high|low|null, formatter]
            $metrics = [
                ['Version', 'version', null, null],
                ['Released', 'released', 'newer', static fn ($v) => Ui::date($v) . ' (' . Ui::ago($v) . ')'],
                ['License', 'license', null, null],
                ['PHP', 'php', null, null],
                ['Health score', 'health', 'high', static fn ($v, $c) => $v . '/100 ' . $c['grade']],
                ['Downloads (total)', 'downloads_total', 'high', [Ui::class, 'num']],
                ['Downloads (month)', 'downloads_monthly', 'high', [Ui::class, 'num']],
                ['Stars', 'stars', 'high', [Ui::class, 'num']],
                ['Dependents', 'dependents', 'high', [Ui::class, 'num']],
                ['Releases', 'releases', 'high', null],
                ['Open issues', 'open_issues', 'low', static fn ($v) => $v === null ? '-' : (string) $v],
                ['Direct deps', 'direct_deps', 'low', static fn ($v) => (string) count($v)],
                ['All deps (transitive)', 'transitive_deps', 'low', static fn ($v) => $v === null ? '-' : (string) $v],
                ['PHP extensions', 'extensions', null, static fn ($v) => $v ? implode(', ', $v) : 'none'],
                ['Advisories (this ver)', 'advisories', 'low', static fn ($v) => $v ? Ui::c('red', (string) $v) : Ui::c('green', '0')],
                ['Abandoned', 'abandoned', null, static fn ($v) => $v ? Ui::c('red', is_string($v) ? 'yes → ' . $v : 'yes') : 'no'],
            ];
            $rows = [];
            foreach ($metrics as [$label, $key, $better, $fmt]) {
                $vals = array_column($cols, $key);
                $best = null;
                if ($better !== null) {
                    $nums = array_map(static fn ($v) => match (true) {
                        is_array($v) => count($v),
                        $better === 'newer' => $v ? strtotime((string) $v) : null,
                        default => $v,
                    }, $vals);
                    $valid = array_filter($nums, static fn ($x) => $x !== null);
                    if (count(array_unique($valid)) > 1) {
                        $best = in_array($better, ['high', 'newer'], true) ? max($valid) : min($valid);
                    }
                }
                $r = [Ui::c('gray', $label)];
                foreach ($cols as $i => $c) {
                    $v = $c[$key];
                    $s = $fmt ? $fmt($v, $c) : (string) $v;
                    if ($best !== null) {
                        $cmp = is_array($v) ? count($v) : ($better === 'newer' ? ($v ? strtotime((string) $v) : null) : $v);
                        if ($cmp === $best) {
                            $s = Ui::c('green', '◆ ') . $s;
                        }
                    }
                    $r[] = $s;
                }
                $rows[] = $r;
            }
            Ui::table(['', ...array_map(static fn ($c) => Ui::c('cyan', $c['name']), $cols)], $rows);
            Ui::out();
            Ui::out(Ui::c('gray', '  ◆ = best in row'));
            if ($d['shared_dependencies']) {
                Ui::out(Ui::c('gray', '  Shared direct dependencies: ') . implode(', ', $d['shared_dependencies']));
            }
            $same = count(array_unique(array_column($cols, 'name'))) === 1;
            if ($same && count($cols) === 2) {
                Ui::section('Requirement changes ' . $cols[0]['version'] . ' → ' . $cols[1]['version']);
                $a = $cols[0]['require'];
                $b = $cols[1]['require'];
                foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
                    if (!isset($a[$k])) {
                        Ui::out('  ' . Ui::c('green', '+ ' . $k . ' ' . $b[$k]));
                    } elseif (!isset($b[$k])) {
                        Ui::out('  ' . Ui::c('red', '- ' . $k . ' ' . $a[$k]));
                    } elseif ($a[$k] !== $b[$k]) {
                        Ui::out('  ' . Ui::c('yellow', '~ ' . $k . ' ' . $a[$k] . ' → ' . $b[$k]));
                    }
                }
            }
        });
    }
}

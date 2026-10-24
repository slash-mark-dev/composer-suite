<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class InfoCommand extends Command
{
    public const NAME = 'info';
    public const TITLE = 'Package Info Inspector';
    public const SUMMARY = 'Metadata: author, license, versions, links, requirements';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['show', 'inspect'];
    public const USAGE = '<vendor/package>[:version]';
    public const EXAMPLES = ['sm-composer info monolog/monolog', 'sm-composer info guzzlehttp/guzzle:7.8.1', 'sm-composer info laravel/framework --json'];

    public function run(): int
    {
        [$name, $ver] = $this->pkgWithVersion($this->need(0, 'package name'));
        $info = Packagist::package($name);
        $versions = Packagist::versions($name);
        $all = array_column($versions, 'version');
        $stable = Semver::latest($all, true);
        $pre = null;
        foreach ($versions as $v) {
            if (!Semver::isStable($v['version']) && Semver::compare($v['version'], (string) $stable) > 0) {
                $pre = $v['version'];
                break;
            }
        }
        $row = $ver ? Packagist::findVersion($name, $ver) : Packagist::latest($name);
        if ($ver && !$row) {
            throw new \InvalidArgumentException("Version \"$ver\" of $name was not found. Latest is $stable.");
        }
        $times = array_filter(array_column($versions, 'time'));
        sort($times);
        $data = [
            'name' => $name,
            'description' => $info['description'] ?? '',
            'type' => $info['type'] ?? 'library',
            'version' => $row['version'] ?? null,
            'released' => $row['time'] ?? null,
            'latest_stable' => $stable,
            'latest_prerelease' => $pre,
            'releases' => count($versions),
            'first_release' => $times[0] ?? $info['time'] ?? null,
            'license' => $row['license'] ?? [],
            'keywords' => $row['keywords'] ?? [],
            'homepage' => $row['homepage'] ?? null,
            'repository' => $info['repository'] ?? ($row['source']['url'] ?? null),
            'support' => $row['support'] ?? [],
            'authors' => $row['authors'] ?? [],
            'maintainers' => array_column($info['maintainers'] ?? [], 'name'),
            'downloads' => $info['downloads'] ?? [],
            'stars' => $info['favers'] ?? $info['github_stars'] ?? 0,
            'forks' => $info['github_forks'] ?? null,
            'open_issues' => $info['github_open_issues'] ?? null,
            'dependents' => $info['dependents'] ?? 0,
            'require' => $row['require'] ?? [],
            'require_dev' => $row['require-dev'] ?? [],
            'suggest' => $row['suggest'] ?? [],
            'autoload' => $row['autoload'] ?? [],
            'abandoned' => $info['abandoned'] ?? false,
            'packagist' => Packagist::packageUrl($name),
        ];

        return $this->out($data, function (array $d) {
            Ui::title($d['name'], $d['type']);
            if ($d['abandoned']) {
                Ui::out('  ' . Ui::badge('ABANDONED', 'bgRed') . ' ' . (is_string($d['abandoned']) ? 'use ' . Ui::c('bold', $d['abandoned']) . ' instead' : 'no replacement suggested'));
            }
            Ui::out(Ui::wrap($d['description']));
            Ui::out();
            $ver = Ui::c('bold.green', (string) $d['version']) . Ui::c('gray', '  released ' . Ui::date($d['released']) . ' (' . Ui::ago($d['released']) . ')');
            Ui::kv([
                'Version' => $ver,
                'Latest stable' => $d['latest_stable'] !== $d['version'] ? $d['latest_stable'] : null,
                'Pre-release' => $d['latest_prerelease'] ? Ui::c('yellow', $d['latest_prerelease']) : null,
                'Releases' => $d['releases'] . Ui::c('gray', ' since ' . Ui::date($d['first_release'])),
                'License' => implode(', ', (array) $d['license']) ?: Ui::c('red', 'none declared'),
                'PHP' => $d['require']['php'] ?? 'any',
                'Downloads' => Ui::num($d['downloads']['total'] ?? 0) . ' total · ' . Ui::num($d['downloads']['monthly'] ?? 0) . '/month · ' . Ui::num($d['downloads']['daily'] ?? 0) . ' today',
                'GitHub' => '★ ' . Ui::num($d['stars']) . ($d['forks'] !== null ? ' · ⑂ ' . Ui::num($d['forks']) : '') . ($d['open_issues'] !== null ? ' · ' . $d['open_issues'] . ' open issues' : ''),
                'Dependents' => Ui::num($d['dependents']) . ' packages',
                'Authors' => implode(', ', array_map(static fn ($a) => $a['name'] . (isset($a['email']) ? Ui::c('gray', ' <' . $a['email'] . '>') : ''), $d['authors'])),
                'Maintainers' => implode(', ', $d['maintainers']),
                'Keywords' => implode(', ', $d['keywords']),
                'Homepage' => $d['homepage'],
                'Source' => $d['repository'],
                'Issues' => $d['support']['issues'] ?? null,
                'Docs' => $d['support']['docs'] ?? null,
                'Packagist' => $d['packagist'],
            ]);
            $req = $d['require'];
            if ($req) {
                Ui::section('Requires (' . count($req) . ')');
                foreach ($req as $k => $c) {
                    Ui::out('  ' . (Packagist::isPlatform($k) ? Ui::c('magenta', $k) : Ui::c('cyan', $k)) . ' ' . Ui::c('yellow', $c));
                }
            }
            if ($d['suggest']) {
                Ui::section('Suggests');
                foreach ($d['suggest'] as $k => $why) {
                    Ui::out('  ' . Ui::c('cyan', $k) . Ui::c('gray', '  ' . $why));
                }
            }
            if ($d['autoload']) {
                Ui::section('Autoload');
                foreach ($d['autoload'] as $type => $map) {
                    foreach ((array) $map as $nsOrIdx => $path) {
                        Ui::out('  ' . Ui::c('gray', str_pad($type, 9)) . (is_string($nsOrIdx) ? Ui::c('magenta', $nsOrIdx) . ' → ' : '') . implode(', ', (array) $path));
                    }
                }
            }
            Ui::out();
            Ui::out(Ui::c('gray', '  Install: composer require ' . $d['name'] . ':' . Semver::suggest((string) ($d['latest_stable'] ?? $d['version']))));
        });
    }
}

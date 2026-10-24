<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Health;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class DashboardCommand extends Command
{
    public const NAME = 'dashboard';
    public const TITLE = 'Multi-Package Dashboard';
    public const SUMMARY = 'Monitor many packages in one live view';
    public const CATEGORY = 'Workflow';
    public const ALIASES = ['dash', 'monitor'];
    public const USAGE = '[vendor/pkg …] [--project] [--watch <sec>] [--save]';
    public const OPTIONS = ['project' => 'flag', 'watch' => 'value', 'save' => 'flag', 'config' => 'value', 'no-trend' => 'flag'];
    public const SHORT = ['p' => 'project', 'w' => 'watch'];
    public const HELP = [
        'vendor/pkg …' => 'Packages to monitor',
        '-p, --project' => 'Monitor the direct dependencies of ./composer.json',
        '-w, --watch <sec>' => 'Refresh every N seconds (min 60; Ctrl+C to stop)',
        '--save' => 'Save the package list to .sm-composer.json so plain `sm-composer dashboard` reuses it',
        '--config <file>' => 'Read the list from another file (default ./.sm-composer.json)',
        '--no-trend' => 'Skip download sparklines (fewer requests)',
    ];
    public const EXAMPLES = ['sm-composer dashboard laravel/framework symfony/console guzzlehttp/guzzle', 'sm-composer dashboard --project --save', 'sm-composer dashboard --watch 300'];

    public function run(): int
    {
        $cfgFile = $this->a->get('config') ?? $this->dir . '/.sm-composer.json';
        $names = array_map([Packagist::class, 'assertName'], $this->a->pos);
        $project = null;
        try {
            $project = Project::load($this->dir, false);
        } catch (\Throwable) {
        }
        if ($this->a->has('project')) {
            if (!$project || !$project->json) {
                throw new \RuntimeException('No composer.json here for --project');
            }
            $names = array_merge($names, array_map('strtolower', array_keys($project->requires())));
        }
        if (!$names && is_file($cfgFile)) {
            $names = array_map([Packagist::class, 'assertName'], Project::readJson($cfgFile)['dashboard'] ?? []);
        }
        if (!$names) {
            throw new \SlashMark\ComposerSuite\UsageException('Give packages, use --project, or save a list first with --save');
        }
        $names = array_values(array_unique($names));
        if ($this->a->has('save')) {
            $cfg = is_file($cfgFile) ? Project::readJson($cfgFile) : [];
            $cfg['dashboard'] = $names;
            file_put_contents($cfgFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            Ui::info('Saved ' . count($names) . " packages to $cfgFile");
        }
        $watch = $this->a->get('watch');
        if ($watch !== null && !$this->json) {
            $sec = max(60, (int) $watch);
            while (true) {
                Http::$useCache = false;
                $data = $this->collect($names, $project);
                fwrite(STDOUT, "\033[H\033[2J");
                $this->render($data);
                Ui::out(Ui::c('gray', '  Refreshed ' . date('H:i:s') . " · next in {$sec}s · Ctrl+C to stop"));
                sleep($sec);
            }
        }
        $data = $this->collect($names, $project);
        $this->out($data, fn ($d) => $this->render($d));
        return count(array_filter($data['packages'], static fn ($r) => ($r['advisories'] ?? 0) > 0 || !empty($r['abandoned']))) ? 1 : 0;
    }

    private function collect(array $names, ?Project $project): array
    {
        Ui::info('Loading ' . count($names) . ' packages…');
        $info = Packagist::packageMany($names);
        $versions = Packagist::versionsMany($names);
        $adv = Packagist::advisories($names);
        $series = [];
        if (!$this->a->has('no-trend')) {
            $from = date('Y-m-d', time() - 30 * 86400);
            $urls = [];
            foreach ($names as $n) {
                $urls[$n] = Packagist::base() . "/packages/$n/stats/all.json?average=daily&from=$from";
            }
            $st = Http::jsonMany(array_values($urls), 12, 3600);
            foreach ($names as $n) {
                $series[$n] = array_values($st[$urls[$n]]['values'][$n] ?? []);
            }
        }
        $rows = [];
        foreach ($names as $n) {
            if (empty($info[$n]) || empty($versions[$n])) {
                $rows[] = ['name' => $n, 'error' => 'not found'];
                continue;
            }
            $latest = Semver::latest(array_column($versions[$n], 'version'));
            $time = null;
            foreach ($versions[$n] as $v) {
                if ($v['version'] === $latest) {
                    $time = $v['time'] ?? null;
                }
            }
            $installed = $project?->lockedVersion($n);
            $check = $installed ?? $latest;
            $affecting = array_filter($adv[$n] ?? [], static fn ($a) => $check && Semver::isValid((string) $a['affectedVersions']) && Semver::satisfies($check, (string) $a['affectedVersions']));
            $h = Health::score($info[$n], $versions[$n], $adv[$n] ?? []);
            $rows[] = [
                'name' => $n, 'installed' => $installed, 'latest' => $latest, 'released' => $time,
                'status' => $installed ? Semver::diff($installed, (string) $latest) : null,
                'monthly' => $info[$n]['downloads']['monthly'] ?? 0, 'stars' => $info[$n]['favers'] ?? 0,
                'trend' => $series[$n] ?? [], 'health' => $h['score'], 'grade' => $h['grade'],
                'advisories' => count($affecting), 'abandoned' => $info[$n]['abandoned'] ?? false,
            ];
        }
        return ['generated' => date('c'), 'packages' => $rows];
    }

    private function render(array $d): void
    {
        $alerts = 0;
        $t = [];
        foreach ($d['packages'] as $r) {
            if (isset($r['error'])) {
                $t[] = [Ui::c('cyan', $r['name']), Ui::c('red', $r['error']), '', '', '', '', '', ''];
                continue;
            }
            $state = match ($r['status']) {
                'same' => Ui::c('green', '✔'),
                'patch' => Ui::c('green', '↑p'),
                'minor' => Ui::c('yellow', '↑m'),
                'major' => Ui::c('red', '↑M'),
                default => '',
            };
            $flags = [];
            if ($r['advisories']) {
                $flags[] = Ui::c('red', '⚠ ' . $r['advisories'] . ' CVE');
                $alerts++;
            }
            if ($r['abandoned']) {
                $flags[] = Ui::c('red', 'abandoned');
                $alerts++;
            }
            $t[] = [
                Ui::c('cyan', $r['name']),
                ($r['installed'] ? $r['installed'] . ' ' . $state : Ui::c('gray', '—')),
                Ui::c('bold', (string) $r['latest']) . Ui::c('gray', ' ' . Ui::ago($r['released'])),
                Ui::num($r['monthly']),
                Ui::c('blue', Ui::sparkline(array_slice($r['trend'], -30))),
                '★ ' . Ui::num($r['stars']),
                Ui::badge($r['grade'], Health::style($r['grade'])) . ' ' . $r['health'],
                implode(' ', $flags) ?: Ui::c('green', 'ok'),
            ];
        }
        Ui::title('Package dashboard', count($d['packages']) . ' packages · ' . ($alerts ? Ui::c('red', $alerts . ' alerts') : Ui::c('green', 'no alerts')));
        Ui::out();
        Ui::table(['Package', 'Installed', 'Latest', 'Monthly', '30-day downloads', 'Stars', 'Health', 'Alerts'], $t, [3 => true, 5 => true]);
        Ui::out();
        Ui::out(Ui::c('gray', '  ↑p/↑m/↑M = patch/minor/major update available'));
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class VendorCommand extends Command
{
    public const NAME = 'vendor';
    public const TITLE = 'Namespace Explorer';
    public const SUMMARY = 'Browse every package (and PHP namespace) under a vendor';
    public const CATEGORY = 'Discover';
    public const ALIASES = ['namespace', 'ns'];
    public const USAGE = '<vendor> [--filter <text>] [-n <limit>] [--sort downloads|name]';
    public const OPTIONS = ['filter' => 'value', 'limit' => 'value', 'sort' => 'value', 'names-only' => 'flag'];
    public const SHORT = ['n' => 'limit', 'f' => 'filter'];
    public const HELP = [
        '-f, --filter <text>' => 'Only package names containing this text (e.g. polyfill)',
        '-n, --limit <n>' => 'Packages to enrich with stats (default 30)',
        '--sort <field>' => 'downloads (default) or name',
        '--names-only' => 'Just list names (fast, no extra requests)',
    ];
    public const EXAMPLES = ['sm-composer vendor spatie', 'sm-composer vendor symfony --filter polyfill', 'sm-composer vendor laravel --names-only'];

    public function run(): int
    {
        $vendor = strtolower(trim($this->need(0, 'vendor name'), '/'));
        if (str_contains($vendor, '/')) {
            $vendor = explode('/', $vendor)[0];
        }
        $names = Packagist::listVendor($vendor);
        if ($f = $this->a->get('filter')) {
            $names = array_values(array_filter($names, static fn ($n) => str_contains($n, strtolower($f))));
        }
        sort($names);
        $total = count($names);
        if ($this->a->has('names-only')) {
            return $this->out(['vendor' => $vendor, 'total' => $total, 'packages' => $names], function (array $d) {
                Ui::title("Packages by {$d['vendor']}/", $d['total'] . ' packages');
                foreach ($d['packages'] as $n) {
                    Ui::out('  ' . Ui::c('cyan', $n));
                }
            });
        }
        $limit = max(1, $this->a->int('limit', 30));
        Ui::info("Found $total packages, loading details for " . min($total, $limit) . '…');
        $info = Packagist::packageMany(array_slice($names, 0, 200));
        // rank by downloads before loading version metadata for the top N
        $ranked = $names;
        usort($ranked, static fn ($a, $b) => ($info[$b]['downloads']['total'] ?? 0) <=> ($info[$a]['downloads']['total'] ?? 0));
        $top = array_slice($ranked, 0, $limit);
        $versions = Packagist::versionsMany($top);

        $rows = [];
        foreach ($top as $n) {
            $i = $info[$n] ?? [];
            $vs = $versions[$n] ?? [];
            $latest = Semver::latest(array_column($vs, 'version'));
            $lrow = null;
            foreach ($vs as $v) {
                if ($v['version'] === $latest) {
                    $lrow = $v;
                }
            }
            $ns = array_merge(array_keys($lrow['autoload']['psr-4'] ?? []), array_keys($lrow['autoload']['psr-0'] ?? []));
            $rows[] = [
                'name' => $n,
                'description' => $i['description'] ?? '',
                'latest' => $latest,
                'released' => $lrow['time'] ?? null,
                'downloads' => $i['downloads']['total'] ?? 0,
                'monthly' => $i['downloads']['monthly'] ?? 0,
                'stars' => $i['favers'] ?? 0,
                'namespaces' => array_map(static fn ($x) => rtrim($x, '\\'), $ns),
                'abandoned' => $i['abandoned'] ?? false,
            ];
        }
        if ($this->a->get('sort') === 'name') {
            usort($rows, static fn ($a, $b) => strcmp($a['name'], $b['name']));
        }
        $sum = array_sum(array_column($rows, 'downloads'));

        return $this->out(['vendor' => $vendor, 'total' => $total, 'downloads' => $sum, 'packages' => $rows], function (array $d) {
            Ui::title("Vendor namespace: {$d['vendor']}/", $d['total'] . ' packages · ' . Ui::num($d['downloads']) . ' downloads (shown)');
            Ui::out();
            $t = [];
            foreach ($d['packages'] as $r) {
                $name = Ui::c('cyan', $r['name']) . ($r['abandoned'] ? Ui::c('red', ' ✖') : '');
                $t[] = [$name, $r['latest'] ?? '-', Ui::c('gray', Ui::ago($r['released'])), Ui::num($r['downloads']), Ui::num($r['monthly']), Ui::c('magenta', implode(', ', $r['namespaces']) ?: '-')];
            }
            Ui::table(['Package', 'Latest', 'Released', 'Total', 'Monthly', 'PHP namespace'], $t, [3 => true, 4 => true], 5);
            if ($d['total'] > count($d['packages'])) {
                Ui::out();
                Ui::out(Ui::c('gray', '  Showing top ' . count($d['packages']) . ' by downloads. Use -n ' . $d['total'] . ' for all, or --names-only.'));
            }
        });
    }
}

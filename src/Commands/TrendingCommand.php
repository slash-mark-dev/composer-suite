<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class TrendingCommand extends Command
{
    public const NAME = 'trending';
    public const TITLE = 'Trending Packages Dashboard';
    public const SUMMARY = 'Discover popular packages and who is growing fastest';
    public const CATEGORY = 'Discover';
    public const ALIASES = ['popular', 'top'];
    public const USAGE = '[--limit <n>] [--sort popular|growth] [--tag <tag>]';
    public const OPTIONS = ['limit' => 'value', 'sort' => 'value', 'tag' => 'value', 'days' => 'value'];
    public const SHORT = ['n' => 'limit'];
    public const HELP = [
        '-n, --limit <n>' => 'How many packages (default 15)',
        '--sort <mode>' => 'popular = monthly downloads (default), growth = last 7 days vs the 7 before',
        '--tag <tag>' => 'Trending within a topic, e.g. laravel, testing, pdf',
        '--days <n>' => 'Days of history for the sparkline (default 28)',
    ];
    public const EXAMPLES = ['sm-composer trending', 'sm-composer trending --sort growth -n 25', 'sm-composer trending --tag laravel'];

    public function run(): int
    {
        $limit = max(1, min(50, $this->a->int('limit', 15)));
        $days = max(14, min(90, $this->a->int('days', 28)));
        $tag = $this->a->get('tag');
        if ($tag) {
            $res = Packagist::search('', ['tags' => [$tag]], $limit);
            $list = $res['results'] ?? [];
        } else {
            $list = Packagist::popular($limit)['packages'] ?? [];
        }
        $list = array_slice($list, 0, $limit);

        $from = date('Y-m-d', time() - $days * 86400);
        $urls = [];
        foreach ($list as $p) {
            $urls[$p['name']] = Packagist::base() . '/packages/' . $p['name'] . '/stats/all.json?average=daily&from=' . $from;
        }
        Ui::info('Fetching download history for ' . count($urls) . ' packages…');
        $stats = Http::jsonMany(array_values($urls), 12, 3600);

        $rows = [];
        foreach ($list as $p) {
            $series = array_values($stats[$urls[$p['name']]]['values'][$p['name']] ?? []);
            $last7 = array_sum(array_slice($series, -7));
            $prev7 = array_sum(array_slice($series, -14, 7));
            $growth = $prev7 > 0 ? ($last7 - $prev7) / $prev7 * 100 : null;
            $rows[] = [
                'name' => $p['name'],
                'description' => $p['description'] ?? '',
                'downloads' => $p['downloads'] ?? 0,
                'stars' => $p['favers'] ?? 0,
                'last7' => $last7,
                'growth' => $growth !== null ? round($growth, 1) : null,
                'series' => $series,
            ];
        }
        if ($this->a->get('sort') === 'growth') {
            usort($rows, static fn ($a, $b) => ($b['growth'] ?? -INF) <=> ($a['growth'] ?? -INF));
        }

        return $this->out(['tag' => $tag, 'days' => $days, 'packages' => $rows], function (array $d) use ($tag, $days) {
            Ui::title('Trending Composer packages' . ($tag ? " · #$tag" : ''), "download history: last $days days");
            Ui::out();
            $t = [];
            foreach ($d['packages'] as $i => $r) {
                $g = $r['growth'];
                $gs = $g === null ? Ui::c('gray', '   n/a') : ($g >= 0 ? Ui::c('green', sprintf('▲%5.1f%%', $g)) : Ui::c('red', sprintf('▼%5.1f%%', abs($g))));
                $t[] = [(string) ($i + 1), Ui::c('cyan', $r['name']), Ui::num($r['downloads']), Ui::num($r['last7']), $gs, Ui::c('blue', Ui::sparkline(array_slice($r['series'], -28))), '★ ' . Ui::num($r['stars'])];
            }
            Ui::table(['#', 'Package', $tag ? 'Downloads' : 'Monthly', 'Last 7d', '7d trend', 'Daily downloads', 'Stars'], $t, [0 => true, 2 => true, 3 => true, 6 => true]);
        });
    }
}

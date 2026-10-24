<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class StatsCommand extends Command
{
    public const NAME = 'stats';
    public const TITLE = 'Download Stats Analyzer';
    public const SUMMARY = 'Analyze popularity: totals, trend, weekly chart, peaks';
    public const CATEGORY = 'Discover';
    public const ALIASES = ['downloads'];
    public const USAGE = '<vendor/package> [more packages…] [--days <n>]';
    public const OPTIONS = ['days' => 'value'];
    public const HELP = ['--days <n>' => 'History window, 14–365 days (default 90)'];
    public const EXAMPLES = ['sm-composer stats monolog/monolog', 'sm-composer stats guzzlehttp/guzzle symfony/http-client --days 180'];

    public function run(): int
    {
        $names = array_map([Packagist::class, 'assertName'], $this->a->pos);
        if (!$names) {
            throw new \SlashMark\ComposerSuite\UsageException('Missing package name');
        }
        $days = max(14, min(365, $this->a->int('days', 90)));
        $out = [];
        foreach ($names as $n) {
            $info = Packagist::package($n);
            $st = Packagist::stats($n, $days);
            $labels = $st['labels'] ?? [];
            $series = $st['values'][$n] ?? (!empty($st['values']) && is_array($st['values']) ? array_values($st['values'])[0] : []);
            $values = array_map('intval', array_values((array) $series));
            $weeks = [];
            foreach ($labels as $i => $day) {
                $wk = date('o-\WW', strtotime($day));
                $weeks[$wk] = ($weeks[$wk] ?? 0) + ($values[$i] ?? 0);
            }
            $weekday = $weekend = [];
            foreach ($labels as $i => $day) {
                if (date('N', strtotime($day)) >= 6) {
                    $weekend[] = $values[$i] ?? 0;
                } else {
                    $weekday[] = $values[$i] ?? 0;
                }
            }
            $first30 = array_sum(array_slice($values, 0, 30));
            $last30 = array_sum(array_slice($values, -30));
            $peakI = $values ? array_keys($values, max($values))[0] : null;
            $out[] = [
                'name' => $n,
                'total' => $info['downloads']['total'] ?? 0,
                'monthly' => $info['downloads']['monthly'] ?? 0,
                'daily' => $info['downloads']['daily'] ?? 0,
                'stars' => $info['favers'] ?? 0,
                'dependents' => $info['dependents'] ?? 0,
                'window_days' => $days,
                'window_total' => array_sum($values),
                'trend_pct' => $first30 > 0 && count($values) >= 60 ? round(($last30 - $first30) / $first30 * 100, 1) : null,
                'peak' => $peakI !== null ? ['date' => $labels[$peakI] ?? null, 'downloads' => $values[$peakI]] : null,
                'weekday_avg' => $weekday ? (int) round(array_sum($weekday) / count($weekday)) : 0,
                'weekend_avg' => $weekend ? (int) round(array_sum($weekend) / count($weekend)) : 0,
                'weekly' => $weeks,
                'daily_series' => array_combine($labels, array_slice(array_pad($values, count($labels), 0), 0, count($labels))) ?: [],
            ];
        }

        return $this->out(count($out) === 1 ? $out[0] : $out, function () use ($out) {
            foreach ($out as $s) {
                $this->render($s);
            }
            if (count($out) > 1) {
                Ui::section('Head to head (monthly downloads)');
                $max = max(array_column($out, 'monthly')) ?: 1;
                foreach ($out as $s) {
                    Ui::out('  ' . Ui::pad(Ui::c('cyan', $s['name']), 34) . Ui::bar($s['monthly'] / $max, 30) . ' ' . Ui::num($s['monthly']));
                }
            }
        });
    }

    private function render(array $s): void
    {
        Ui::title('Downloads · ' . $s['name']);
        Ui::out();
        Ui::kv([
            'Total' => Ui::c('bold', Ui::num($s['total'])) . Ui::c('gray', ' (' . number_format($s['total']) . ')'),
            'Last 30 days' => Ui::num($s['monthly']),
            'Today' => Ui::num($s['daily']),
            'Trend' => $s['trend_pct'] === null ? Ui::c('gray', 'need 60+ days of data') : ($s['trend_pct'] >= 0 ? Ui::c('green', '▲ ' . $s['trend_pct'] . '%') : Ui::c('red', '▼ ' . abs($s['trend_pct']) . '%')) . Ui::c('gray', ' last 30d vs first 30d of window'),
            'Peak day' => $s['peak'] ? Ui::num($s['peak']['downloads']) . Ui::c('gray', ' on ' . $s['peak']['date']) : '-',
            'Weekday / weekend' => Ui::num($s['weekday_avg']) . ' / ' . Ui::num($s['weekend_avg']) . Ui::c('gray', ' avg per day' . ($s['weekend_avg'] > 0 && $s['weekday_avg'] / max(1, $s['weekend_avg']) > 1.5 ? ' — mostly used at work (CI/teams)' : '')),
            'Stars · dependents' => '★ ' . Ui::num($s['stars']) . ' · ' . Ui::num($s['dependents']) . ' packages depend on it',
        ]);
        Ui::section('Daily downloads, last ' . $s['window_days'] . ' days');
        Ui::out('  ' . Ui::c('blue', Ui::sparkline(array_slice(array_values($s['daily_series']), -min(100, Ui::termWidth() - 6)))));
        Ui::section('Weekly totals');
        $weeks = array_slice($s['weekly'], -12, null, true);
        $max = max($weeks ?: [1]) ?: 1;
        foreach ($weeks as $wk => $n) {
            Ui::out('  ' . Ui::c('gray', $wk) . '  ' . Ui::bar($n / $max, 40) . ' ' . Ui::num($n));
        }
        Ui::out();
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class SearchCommand extends Command
{
    public const NAME = 'search';
    public const TITLE = 'Packagist Package Search';
    public const SUMMARY = 'Search PHP packages on Packagist with filters';
    public const CATEGORY = 'Discover';
    public const ALIASES = ['s', 'find'];
    public const USAGE = '<query> [--type <type>] [--tag <tag>] [--sort downloads|stars|name] [-n <limit>]';
    public const OPTIONS = ['type' => 'value', 'tag' => 'multi', 'limit' => 'value', 'page' => 'value', 'sort' => 'value', 'min-downloads' => 'value', 'hide-abandoned' => 'flag'];
    public const SHORT = ['n' => 'limit', 't' => 'type'];
    public const HELP = [
        '-t, --type <type>' => 'library, project, symfony-bundle, wordpress-plugin, composer-plugin, …',
        '--tag <tag>' => 'Filter by keyword/tag (repeatable or comma separated)',
        '-n, --limit <n>' => 'Results to show (default 15, max 100)',
        '--page <n>' => 'Results page',
        '--sort <field>' => 'downloads (default), stars, name',
        '--min-downloads <n>' => 'Hide packages below this many total downloads (accepts 10k, 2M)',
        '--hide-abandoned' => 'Hide abandoned packages',
    ];
    public const EXAMPLES = [
        'sm-composer search jwt',
        'sm-composer search pdf --type library --min-downloads 1M',
        'sm-composer search "" --tag laravel --sort stars -n 20',
    ];

    public function run(): int
    {
        $q = $this->a->arg(0, '') ?? '';
        $tags = $this->a->list('tag');
        $type = $this->a->get('type');
        if ($q === '' && !$tags && !$type) {
            throw new \SlashMark\ComposerSuite\UsageException('Give a search term, or filter with --tag / --type');
        }
        $limit = max(1, min(100, $this->a->int('limit', 15)));
        $filtering = $this->a->get('min-downloads') !== null || $this->a->has('hide-abandoned') || in_array($this->a->get('sort'), ['stars', 'favers', 'name'], true);
        $res = Packagist::search($q, ['type' => $type, 'tags' => $tags ?: null], $filtering ? min(100, max(50, $limit * 3)) : $limit, $this->a->int('page', 1));
        $rows = $res['results'] ?? [];
        $min = self::parseCount($this->a->get('min-downloads'));
        $rows = array_values(array_filter($rows, fn ($r) => ($r['downloads'] ?? 0) >= $min && !($this->a->has('hide-abandoned') && !empty($r['abandoned']))));
        $sort = $this->a->get('sort', 'downloads');
        usort($rows, match ($sort) {
            'stars', 'favers' => static fn ($a, $b) => ($b['favers'] ?? 0) <=> ($a['favers'] ?? 0),
            'name' => static fn ($a, $b) => strcmp($a['name'], $b['name']),
            default => static fn ($a, $b) => ($b['downloads'] ?? 0) <=> ($a['downloads'] ?? 0),
        });
        $rows = array_slice($rows, 0, $limit);
        $data = ['query' => $q, 'total' => $res['total'] ?? count($rows), 'results' => $rows];

        return $this->out($data, function (array $d) use ($q) {
            Ui::title('Packagist search' . ($q !== '' ? ": \"$q\"" : ''), Ui::num($d['total']) . ' matches');
            Ui::out();
            $tbl = [];
            foreach ($d['results'] as $i => $r) {
                $name = Ui::c('cyan', $r['name']) . (!empty($r['abandoned']) ? ' ' . Ui::c('red', '[abandoned]') : '');
                $tbl[] = [(string) ($i + 1), $name, Ui::num($r['downloads'] ?? 0), '★ ' . Ui::num($r['favers'] ?? 0), $r['description'] ?? ''];
            }
            Ui::table(['#', 'Package', 'Downloads', 'Stars', 'Description'], $tbl, [0 => true, 2 => true, 3 => true], 4);
            if ($d['results']) {
                Ui::out();
                Ui::out(Ui::c('gray', '  Inspect one: sm-composer info ' . $d['results'][0]['name']));
            }
        });
    }

    public static function parseCount(?string $s): int
    {
        if ($s === null || $s === '') {
            return 0;
        }
        if (preg_match('/^([\d.]+)\s*([kmb])?$/i', trim($s), $m)) {
            $mult = ['k' => 1e3, 'm' => 1e6, 'b' => 1e9][strtolower($m[2] ?? '')] ?? 1;
            return (int) ((float) $m[1] * $mult);
        }
        return (int) $s;
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class UpdatesCommand extends Command
{
    public const NAME = 'updates';
    public const TITLE = 'Latest Updates Tracker';
    public const SUMMARY = 'Track newly released and recently updated packages';
    public const CATEGORY = 'Discover';
    public const ALIASES = ['releases', 'feed'];
    public const USAGE = '[--new] [--vendor <v>] [--package <name>] [--project] [--since <days>] [-n <limit>]';
    public const OPTIONS = ['new' => 'flag', 'vendor' => 'value', 'package' => 'value', 'project' => 'flag', 'since' => 'value', 'limit' => 'value', 'stable' => 'flag'];
    public const SHORT = ['n' => 'limit', 'p' => 'project'];
    public const HELP = [
        '(default)' => 'Latest releases across all of Packagist',
        '--new' => 'Newly published packages instead of releases',
        '--vendor <v>' => 'Only releases from one vendor (e.g. symfony)',
        '--package <name>' => 'Release history of one package',
        '-p, --project' => 'New releases of the packages your composer.json uses',
        '--since <days>' => 'Only show the last N days (default: 30 for --project)',
        '--stable' => 'Hide alpha/beta/RC releases',
        '-n, --limit <n>' => 'Max rows (default 25)',
    ];
    public const EXAMPLES = ['sm-composer updates', 'sm-composer updates --vendor laravel', 'sm-composer updates --project --since 14', 'sm-composer updates --new -n 50'];

    public function run(): int
    {
        $limit = max(1, $this->a->int('limit', 25));
        $since = $this->a->get('since');
        if ($this->a->has('project')) {
            return $this->project_updates((int) ($since ?? 30), $limit);
        }
        $feed = match (true) {
            $this->a->has('new') => 'packages',
            (bool) $this->a->get('package') => 'package.' . Packagist::assertName((string) $this->a->get('package')),
            (bool) $this->a->get('vendor') => 'vendor.' . strtolower((string) $this->a->get('vendor')),
            default => 'releases',
        };
        $items = Packagist::feed($feed);
        if ($since !== null) {
            $cut = time() - (int) $since * 86400;
            $items = array_filter($items, static fn ($i) => strtotime($i['date']) >= $cut);
        }
        if ($this->a->has('stable')) {
            $items = array_filter($items, static fn ($i) => $i['version'] === '' || Semver::isStable($i['version']));
        }
        $items = array_slice(array_values($items), 0, $limit);

        return $this->out(['feed' => $feed, 'items' => $items], function (array $d) {
            $label = match (true) {
                $d['feed'] === 'packages' => 'Newly published packages',
                str_starts_with($d['feed'], 'vendor.') => 'Latest releases from ' . substr($d['feed'], 7) . '/*',
                str_starts_with($d['feed'], 'package.') => 'Release history of ' . substr($d['feed'], 8),
                default => 'Latest releases on Packagist',
            };
            Ui::title($label);
            Ui::out();
            $t = [];
            foreach ($d['items'] as $i) {
                $ver = $i['version'] === '' ? '' : (Semver::isStable($i['version']) ? Ui::c('green', $i['version']) : Ui::c('yellow', $i['version']));
                $t[] = [Ui::c('gray', Ui::ago($i['date'])), Ui::c('cyan', $i['name']), $ver, $i['description']];
            }
            Ui::table(['When', 'Package', 'Version', 'Description'], $t, [], 3);
        });
    }

    private function project_updates(int $days, int $limit): int
    {
        $p = $this->project();
        $reqs = $p->requires();
        $locked = $p->locked();
        $cut = time() - $days * 86400;
        Ui::info('Checking ' . count($reqs) . ' dependencies for releases in the last ' . $days . ' days…');
        $all = Packagist::versionsMany(array_keys($reqs));
        $rows = [];
        foreach ($all as $name => $versions) {
            $current = $locked[strtolower($name)]['version'] ?? null;
            foreach ($versions as $v) {
                $t = strtotime($v['time'] ?? '');
                if ($t === false || $t < $cut) {
                    continue;
                }
                if ($this->a->has('stable') && !Semver::isStable($v['version'])) {
                    continue;
                }
                $rows[] = [
                    'name' => $name,
                    'version' => $v['version'],
                    'date' => $v['time'],
                    'constraint' => $reqs[$name],
                    'allowed' => Semver::isValid($reqs[$name]) && Semver::satisfies($v['version'], $reqs[$name]),
                    'installed' => $current,
                    'newer' => $current ? Semver::compare($v['version'], $current) <=> 0 : null,
                ];
            }
        }
        usort($rows, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        $rows = array_slice($rows, 0, $limit);

        return $this->out(['days' => $days, 'releases' => $rows], function (array $d) {
            Ui::title('New releases of your dependencies', 'last ' . $d['days'] . ' days');
            Ui::out();
            $t = [];
            foreach ($d['releases'] as $r) {
                $note = match (true) {
                    $r['newer'] === 0 => Ui::c('cyan', '● installed'),
                    $r['newer'] === -1 => Ui::c('gray', 'you have a newer one'),
                    $r['allowed'] => Ui::c('green', 'composer update will pick it up'),
                    default => Ui::c('yellow', 'outside ' . $r['constraint'] . ' (needs a constraint bump)'),
                };
                $t[] = [Ui::c('gray', Ui::ago($r['date'])), Ui::c('cyan', $r['name']), Ui::c('bold', $r['version']), $r['installed'] ?? '-', $note];
            }
            Ui::table(['When', 'Package', 'Released', 'Installed', 'Status'], $t);
        });
    }
}

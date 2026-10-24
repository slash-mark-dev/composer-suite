<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class AbandonedCommand extends Command
{
    public const NAME = 'abandoned';
    public const TITLE = 'Deprecated Package Finder';
    public const SUMMARY = 'Find abandoned, deprecated and stale packages with replacements';
    public const CATEGORY = 'Security & compliance';
    public const ALIASES = ['deprecated', 'stale'];
    public const USAGE = '[vendor/pkg …] [--stale <years>] [--no-dev] [--direct]';
    public const OPTIONS = ['stale' => 'value', 'no-dev' => 'flag', 'direct' => 'flag'];
    public const SHORT = ['D' => 'direct'];
    public const HELP = [
        '(no package)' => 'Check every package in composer.lock (or composer.json)',
        '--stale <years>' => 'Also flag packages with no release for this many years (default 2, 0 = off). Mature packages with 1M+ monthly downloads and 1K+ dependents are not flagged',
        '-D, --direct' => 'Only packages in composer.json',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer abandoned', 'sm-composer abandoned --stale 3', 'sm-composer abandoned swiftmailer/swiftmailer fzaninotto/faker'];

    public function run(): int
    {
        $direct = [];
        if ($this->a->pos) {
            $names = array_map([Packagist::class, 'assertName'], $this->a->pos);
            $current = [];
        } else {
            $p = $this->project();
            $direct = array_change_key_case($p->requires(!$this->a->has('no-dev')));
            if ($p->hasLock() && !$this->a->has('direct')) {
                $locked = $p->locked(!$this->a->has('no-dev'));
                $names = array_keys($locked);
                $current = array_map(static fn ($x) => $x['version'], $locked);
            } else {
                $names = array_keys($direct);
                $current = [];
                foreach ($names as $n) {
                    $current[$n] = $p->lockedVersion($n);
                }
            }
        }
        Ui::info('Checking ' . count($names) . ' packages…');
        $info = Packagist::packageMany($names);
        $staleYears = (float) ($this->a->get('stale') ?? 2);
        $versions = $staleYears > 0 ? Packagist::versionsMany($names) : [];
        $rows = [];
        foreach ($names as $n) {
            $i = $info[$n] ?? null;
            if (!$i) {
                continue;
            }
            $ab = $i['abandoned'] ?? false;
            $last = null;
            foreach ($versions[$n] ?? [] as $v) {
                if (!empty($v['time']) && ($last === null || strtotime($v['time']) > strtotime($last))) {
                    $last = $v['time'];
                }
            }
            $days = Ui::days($last);
            // finished, widely used packages (e.g. PSR interfaces) rarely need releases; don't call them stale
            $mature = ($i['downloads']['monthly'] ?? 0) >= 1000000 && ($i['dependents'] ?? 0) >= 1000;
            $stale = $staleYears > 0 && $days !== null && $days > $staleYears * 365 && !$mature;
            if (!$ab && !$stale) {
                continue;
            }
            $rows[] = [
                'name' => $n, 'status' => $ab ? 'abandoned' : 'stale', 'replacement' => is_string($ab) && $ab !== '' ? $ab : null,
                'installed' => $current[$n] ?? null, 'last_release' => $last, 'direct' => isset($direct[$n]), 'monthly' => $i['downloads']['monthly'] ?? 0,
            ];
        }
        usort($rows, static fn ($a, $b) => [$a['status'], !$a['direct']] <=> [$b['status'], !$b['direct']]);

        $this->out(['checked' => count($names), 'stale_years' => $staleYears, 'packages' => $rows], function (array $d) {
            Ui::title('Abandoned & stale packages', $d['checked'] . ' checked');
            Ui::out();
            if (!$d['packages']) {
                Ui::out(Ui::status('ok', 'Nothing abandoned' . ($d['stale_years'] > 0 ? ' or stale for ' . $d['stale_years'] . '+ years' : '')));
                return;
            }
            $t = [];
            foreach ($d['packages'] as $r) {
                $st = $r['status'] === 'abandoned' ? Ui::c('red', '✖ abandoned') : Ui::c('yellow', '▲ stale');
                $rep = $r['replacement'] ? Ui::c('green', '→ ' . $r['replacement']) : ($r['status'] === 'abandoned' ? Ui::c('gray', 'no replacement named') : Ui::c('gray', 'consider alternatives'));
                $t[] = [($r['direct'] ? Ui::c('bold.cyan', $r['name']) : Ui::c('cyan', $r['name']) . Ui::c('gray', ' (transitive)')), $st, $r['installed'] ?? '-', Ui::c('gray', Ui::ago($r['last_release'])), $rep];
            }
            Ui::table(['Package', 'Status', 'Installed', 'Last release', 'Replacement'], $t);
            $swaps = array_filter($d['packages'], static fn ($r) => $r['replacement'] && $r['direct']);
            if ($swaps) {
                Ui::section('Suggested swaps');
                foreach ($swaps as $r) {
                    Ui::out('  ' . Ui::c('green', "composer remove {$r['name']} && composer require {$r['replacement']}"));
                }
            }
            $trans = array_filter($d['packages'], static fn ($r) => !$r['direct']);
            if ($trans) {
                Ui::out();
                Ui::out(Ui::c('gray', '  Transitive ones come from other packages — find the parent with: sm-composer why <package> --local'));
            }
        });
        return count(array_filter($rows, static fn ($r) => $r['status'] === 'abandoned')) ? 1 : 0;
    }
}

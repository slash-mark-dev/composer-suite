<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Health;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class HealthCommand extends Command
{
    public const NAME = 'health';
    public const TITLE = 'Package Health Score';
    public const SUMMARY = 'Score packages 0–100 on maintenance, popularity and risk';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['score'];
    public const USAGE = '<vendor/package …> | --project [--min <score>]';
    public const OPTIONS = ['project' => 'flag', 'min' => 'value', 'all' => 'flag'];
    public const SHORT = ['p' => 'project'];
    public const HELP = [
        '-p, --project' => 'Score every direct dependency in composer.json',
        '--all' => 'With --project: include transitive packages from composer.lock',
        '--min <score>' => 'Exit with code 1 if any package scores below this (CI gate)',
    ];
    public const EXAMPLES = ['sm-composer health guzzlehttp/guzzle', 'sm-composer health --project --min 50', 'sm-composer health firebase/php-jwt lcobucci/jwt'];

    public function run(): int
    {
        $names = array_map([Packagist::class, 'assertName'], $this->a->pos);
        if ($this->a->has('project') || !$names) {
            $p = $this->project();
            $names = $this->a->has('all') && $p->hasLock() ? array_keys($p->locked()) : array_map('strtolower', array_keys($p->requires()));
        }
        Ui::info('Scoring ' . count($names) . ' package(s)…');
        $info = Packagist::packageMany($names);
        $versions = Packagist::versionsMany($names);
        $adv = Packagist::advisories($names);
        $results = [];
        foreach ($names as $n) {
            if (empty($info[$n]) || empty($versions[$n])) {
                $results[] = ['name' => $n, 'error' => 'not found on Packagist'];
                continue;
            }
            $results[] = ['name' => $n] + Health::score($info[$n], $versions[$n], $adv[$n] ?? []);
        }
        usort($results, static fn ($a, $b) => ($a['score'] ?? -1) <=> ($b['score'] ?? -1));
        $min = $this->a->get('min');
        $failing = $min !== null ? array_values(array_filter($results, static fn ($r) => isset($r['score']) && $r['score'] < (int) $min)) : [];

        $this->out(['packages' => $results, 'min' => $min !== null ? (int) $min : null, 'failing' => array_column($failing, 'name')], function (array $d) {
            if (count($d['packages']) === 1 && isset($d['packages'][0]['score'])) {
                $this->detail($d['packages'][0]);
                return;
            }
            Ui::title('Package health', count($d['packages']) . ' packages, weakest first');
            Ui::out();
            $t = [];
            foreach ($d['packages'] as $r) {
                if (isset($r['error'])) {
                    $t[] = [Ui::c('cyan', $r['name']), '', '', Ui::c('red', $r['error'])];
                    continue;
                }
                $weak = [];
                foreach ($r['factors'] as $k => $f) {
                    if ($f['points'] / $f['max'] < 0.5) {
                        $weak[] = $k;
                    }
                }
                $t[] = [Ui::c('cyan', $r['name']) . ($r['abandoned'] ? Ui::c('red', ' (abandoned)') : ''), Ui::badge($r['grade'], Health::style($r['grade'])) . ' ' . $r['score'], Ui::bar($r['score'] / 100, 20, $r['score'] >= 70 ? 'green' : ($r['score'] >= 40 ? 'yellow' : 'red')), Ui::c('gray', $weak ? 'weak: ' . implode(', ', $weak) : 'solid')];
            }
            Ui::table(['Package', 'Score', '', 'Notes'], $t, [], 3);
            if ($d['min'] !== null) {
                Ui::out();
                Ui::out($d['failing'] ? Ui::status('fail', count($d['failing']) . ' below ' . $d['min'] . ': ' . implode(', ', $d['failing'])) : Ui::status('ok', 'All packages score ' . $d['min'] . ' or higher'));
            }
        });
        return $failing ? 1 : 0;
    }

    private function detail(array $r): void
    {
        Ui::title('Health · ' . $r['name'], 'latest ' . $r['latest']);
        Ui::out();
        Ui::out('  ' . Ui::badge(' ' . $r['grade'] . ' ', Health::style($r['grade'])) . '  ' . Ui::c('bold', $r['score'] . '/100') . ($r['abandoned'] ? '  ' . Ui::c('red', 'ABANDONED — capped at 20') : ''));
        Ui::out();
        foreach ($r['factors'] as $k => $f) {
            $ratio = $f['points'] / $f['max'];
            Ui::out('  ' . Ui::pad(ucfirst($k), 12) . Ui::bar($ratio, 20, $ratio >= 0.7 ? 'green' : ($ratio >= 0.4 ? 'yellow' : 'red')) . ' ' . Ui::pad($f['points'] . '/' . $f['max'], 6) . Ui::c('gray', $f['detail']));
        }
        Ui::out();
        Ui::out(Ui::c('gray', '  A ≥85 · B ≥70 · C ≥55 · D ≥40 · F below. Signals from Packagist + advisories DB.'));
    }
}

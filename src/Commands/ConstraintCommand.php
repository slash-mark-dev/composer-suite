<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class ConstraintCommand extends Command
{
    public const NAME = 'constraint';
    public const TITLE = 'Version Constraint Tester';
    public const SUMMARY = 'Test ^, ~, *, ranges against versions or real releases';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['semver', 'satisfies'];
    public const USAGE = '"<constraint>" [version …] [--package <vendor/pkg>] | --cheatsheet';
    public const OPTIONS = ['package' => 'value', 'cheatsheet' => 'flag', 'all' => 'flag'];
    public const SHORT = ['p' => 'package'];
    public const HELP = [
        '[version …]' => 'Versions to test, e.g. 1.2.0 1.9.3 2.0.0-beta1',
        '-p, --package <name>' => 'Test against every real release of a package',
        '--all' => 'With --package: also list non-matching releases',
        '--cheatsheet' => 'Show how each operator works, with examples',
    ];
    public const EXAMPLES = ['sm-composer constraint "^1.2" 1.1.9 1.2.0 1.9.9 2.0.0', 'sm-composer constraint "~2.4.1 || ^3.0" --package guzzlehttp/psr7', 'sm-composer constraint "0.3.*" 0.3.9 0.4.0', 'sm-composer constraint --cheatsheet'];

    public function run(): int
    {
        if ($this->a->has('cheatsheet')) {
            return $this->cheatsheet();
        }
        $c = $this->need(0, 'constraint, e.g. "^1.2"');
        if (!Semver::isValid($c)) {
            throw new \InvalidArgumentException("\"$c\" is not a valid Composer constraint. See: sm-composer constraint --cheatsheet");
        }
        $explain = Semver::explain($c);
        $tests = [];
        foreach (array_slice($this->a->pos, 1) as $v) {
            $n = Semver::normalize($v);
            $tests[] = ['version' => $v, 'valid' => $n !== null, 'normalized' => $n, 'matches' => $n !== null && Semver::satisfies($v, $c), 'stability' => $n ? Semver::stability($v) : null];
        }
        $pkgData = null;
        if ($pkg = $this->a->get('package')) {
            $pkg = Packagist::assertName($pkg);
            $versions = Packagist::versions($pkg);
            $match = $miss = [];
            foreach ($versions as $v) {
                if (Semver::satisfies($v['version'], $c)) {
                    $match[] = ['version' => $v['version'], 'time' => $v['time'] ?? null];
                } else {
                    $miss[] = ['version' => $v['version'], 'time' => $v['time'] ?? null];
                }
            }
            $best = null;
            foreach ($match as $m) {
                if (Semver::isStable($m['version'])) {
                    $best = $m['version'];
                    break;
                }
            }
            $pkgData = ['package' => $pkg, 'matching' => $match, 'not_matching' => $miss, 'would_install' => $best ?? ($match[0]['version'] ?? null), 'latest' => Semver::latest(array_column($versions, 'version'))];
        }
        $notes = self::notes($c);

        $this->out(['constraint' => $c, 'means' => $explain, 'notes' => $notes, 'tests' => $tests, 'package' => $pkgData], function (array $d) {
            Ui::title('Constraint ' . Ui::c('yellow', $d['constraint']));
            Ui::out('  means ' . Ui::c('bold', $d['means']));
            foreach ($d['notes'] as $n) {
                Ui::out(Ui::c('gray', '  • ' . $n));
            }
            if ($d['tests']) {
                Ui::out();
                foreach ($d['tests'] as $t) {
                    if (!$t['valid']) {
                        Ui::out('  ' . Ui::c('red', '? ') . Ui::pad($t['version'], 16) . Ui::c('red', 'not a valid version'));
                        continue;
                    }
                    Ui::out('  ' . ($t['matches'] ? Ui::c('green', '✔ ') : Ui::c('red', '✖ ')) . Ui::pad(Ui::c('bold', $t['version']), 16) . Ui::c('gray', ' ' . $t['normalized'] . ($t['stability'] !== 'stable' ? '  (' . $t['stability'] . ')' : '')));
                }
            }
            if ($p = $d['package']) {
                Ui::section($p['package'] . ': ' . count($p['matching']) . ' of ' . (count($p['matching']) + count($p['not_matching'])) . ' releases match');
                Ui::out('  composer would install ' . ($p['would_install'] ? Ui::c('bold.green', $p['would_install']) : Ui::c('red', 'nothing — no release matches')) . Ui::c('gray', '  (latest is ' . $p['latest'] . ')'));
                Ui::out();
                $list = array_slice($p['matching'], 0, 15);
                Ui::out('  ' . Ui::c('gray', 'match:    ') . implode('  ', array_map(static fn ($m) => Ui::c('green', $m['version']), $list)) . (count($p['matching']) > 15 ? Ui::c('gray', '  …') : ''));
                if ($this->a->has('all') && $p['not_matching']) {
                    Ui::out('  ' . Ui::c('gray', 'no match: ') . implode('  ', array_map(static fn ($m) => Ui::c('gray', $m['version']), array_slice($p['not_matching'], 0, 30))));
                }
            }
        });
        foreach ($tests as $t) {
            if (!$t['matches']) {
                return 1;
            }
        }
        return 0;
    }

    private static function notes(string $c): array
    {
        $n = [];
        if (str_contains($c, '^0.')) {
            $n[] = '^ on 0.x is strict: ^0.3 allows 0.3.* only, because 0.x minors may break things';
        } elseif (str_contains($c, '^')) {
            $n[] = '^ allows any non-breaking update (same major version) — the recommended default';
        }
        if (preg_match('/~\d+\.\d+\.\d+/', $c)) {
            $n[] = '~ with three parts allows patch updates only (~1.2.3 → <1.3.0)';
        } elseif (str_contains($c, '~')) {
            $n[] = '~ with two parts allows minor updates (~1.2 → <2.0.0), same as ^1.2';
        }
        if (str_contains($c, '*') || preg_match('/\.x\b/', $c)) {
            $n[] = 'wildcards match any value in that position';
        }
        if (preg_match('/^\s*>=?[^<,|]*$/', $c)) {
            $n[] = Ui::c('yellow', 'no upper bound — will accept future breaking majors');
        }
        if (str_contains($c, '||') || preg_match('/[^|]\|[^|]/', $c)) {
            $n[] = '|| means OR — any of the ranges is fine';
        }
        if (str_contains($c, '@')) {
            $n[] = '@stability flags only change which stabilities are allowed for this one package';
        }
        if (Semver::isValid($c) && !preg_match('/[\^~*<>=|x@-]/', $c)) {
            $n[] = Ui::c('yellow', 'exact version pin — you will never get bug or security fixes');
        }
        return $n;
    }

    private function cheatsheet(): int
    {
        $rows = [
            ['^1.2.3', 'Caret (recommended)', '>=1.2.3 <2.0.0'],
            ['^0.3.2', 'Caret on 0.x', '>=0.3.2 <0.4.0'],
            ['~1.2', 'Tilde, 2 parts', '>=1.2.0 <2.0.0'],
            ['~1.2.3', 'Tilde, 3 parts', '>=1.2.3 <1.3.0'],
            ['1.2.*', 'Wildcard', '>=1.2.0 <1.3.0'],
            ['1.0 - 2.0', 'Hyphen range', '>=1.0.0 <2.1.0'],
            ['>=1.0 <2.0', 'Range (space or comma = AND)', '>=1.0.0 <2.0.0'],
            ['^1.0 || ^2.0', 'OR', 'either major'],
            ['1.2.3', 'Exact', '==1.2.3'],
            ['dev-main', 'Branch', 'latest commit of main'],
            ['^2.0@beta', 'Stability flag', 'allows 2.x betas for this package'],
            ['*', 'Anything', 'any version (avoid)'],
        ];
        $data = [];
        foreach ($rows as [$c, $what, $means]) {
            $data[] = ['constraint' => $c, 'name' => $what, 'means' => $means];
        }
        return $this->out($data, function (array $d) {
            Ui::title('Composer version constraints cheat sheet');
            Ui::out();
            Ui::table(['Constraint', 'Kind', 'Means'], array_map(static fn ($r) => [Ui::c('yellow', $r['constraint']), $r['name'], Ui::c('bold', $r['means'])], $d));
            Ui::out();
            Ui::out(Ui::c('gray', '  Pre-releases: 1.0.0-dev < -alpha < -beta < -RC < 1.0.0 < 1.0.0-patch1'));
        });
    }
}

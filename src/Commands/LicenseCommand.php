<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class LicenseCommand extends Command
{
    public const NAME = 'license';
    public const TITLE = 'License Compliance Checker';
    public const SUMMARY = 'Classify dependency licenses and flag compliance risks';
    public const CATEGORY = 'Security & compliance';
    public const ALIASES = ['licenses', 'legal'];
    public const USAGE = '[--allow <ids>] [--deny <ids>] [--no-dev] [--distribute]';
    public const OPTIONS = ['allow' => 'multi', 'deny' => 'multi', 'no-dev' => 'flag', 'distribute' => 'flag', 'saas' => 'flag', 'list' => 'flag'];
    public const SHORT = ['l' => 'list'];
    public const HELP = [
        '--allow <ids>' => 'Only these SPDX ids are OK, e.g. --allow MIT,BSD-3-Clause,Apache-2.0',
        '--deny <ids>' => 'These SPDX ids fail the check, e.g. --deny GPL-3.0-only,AGPL-3.0-only',
        '--distribute' => 'You ship the code to customers (copyleft obligations apply)',
        '--saas' => 'You run it as a hosted service (AGPL obligations apply)',
        '-l, --list' => 'List every package with its license',
        '--no-dev' => 'Skip dev packages (they usually are not shipped)',
    ];
    public const EXAMPLES = ['sm-composer license', 'sm-composer license --no-dev --distribute', 'sm-composer license --allow MIT,BSD-2-Clause,BSD-3-Clause,Apache-2.0,ISC'];

    private const PERMISSIVE = ['MIT', 'MIT-0', 'BSD-2-Clause', 'BSD-3-Clause', 'BSD-4-Clause', '0BSD', 'Apache-2.0', 'ISC', 'Zlib', 'Unlicense', 'CC0-1.0', 'PHP-3.0', 'PHP-3.01', 'PSF-2.0', 'Artistic-2.0', 'BSL-1.0', 'WTFPL', 'Python-2.0', 'X11', 'Beerware', 'CC-BY-4.0', 'CC-BY-3.0', 'OFL-1.1'];

    public static function classify(string $id): string
    {
        $u = strtoupper(trim($id));
        return match (true) {
            $u === '' || $u === 'NONE' || $u === 'NOASSERTION' => 'unknown',
            $u === 'PROPRIETARY' => 'proprietary',
            in_array($u, array_map('strtoupper', self::PERMISSIVE), true) => 'permissive',
            str_starts_with($u, 'AGPL') => 'network-copyleft',
            str_starts_with($u, 'GPL') => 'strong-copyleft',
            str_starts_with($u, 'LGPL') || str_starts_with($u, 'MPL') || str_starts_with($u, 'EPL') || str_starts_with($u, 'CDDL') || str_starts_with($u, 'EUPL') || str_starts_with($u, 'OSL') || str_starts_with($u, 'CECILL') => 'weak-copyleft',
            str_starts_with($u, 'CC-BY-NC') || str_starts_with($u, 'SSPL') || str_starts_with($u, 'BUSL') || str_starts_with($u, 'ELASTIC') => 'restricted',
            default => 'unknown',
        };
    }

    /** Resolve a license list / SPDX expression ("MIT or GPL-2.0") to the friendliest option. */
    public static function effective(array $licenses): array
    {
        $rank = ['permissive' => 0, 'weak-copyleft' => 1, 'proprietary' => 2, 'strong-copyleft' => 3, 'network-copyleft' => 4, 'restricted' => 5, 'unknown' => 6];
        $opts = [];
        foreach ($licenses as $l) {
            foreach (preg_split('/\s+or\s+/i', trim((string) $l, '() ')) as $x) {
                $x = trim($x, '() ');
                if ($x !== '') {
                    $opts[] = $x;
                }
            }
        }
        if (!$opts) {
            return ['license' => 'none', 'category' => 'unknown', 'options' => []];
        }
        usort($opts, static fn ($a, $b) => $rank[self::classify($a)] <=> $rank[self::classify($b)]);
        return ['license' => $opts[0], 'category' => self::classify($opts[0]), 'options' => $opts];
    }

    public function run(): int
    {
        $p = $this->project();
        $p->requireLock();
        $pkgs = $p->locked(!$this->a->has('no-dev'));
        $allow = $this->a->list('allow');
        $deny = $this->a->list('deny');
        $projLic = (array) ($p->json['license'] ?? []);
        $projCat = $projLic ? self::effective($projLic)['category'] : 'unknown';
        $closed = $projCat === 'proprietary' || $projCat === 'unknown';
        $distribute = $this->a->has('distribute');
        $saas = $this->a->has('saas');

        $rows = [];
        foreach ($pkgs as $n => $x) {
            $e = self::effective((array) ($x['license'] ?? []));
            $risk = null;
            $reason = null;
            $inDeny = false;
            foreach ($e['options'] as $o) {
                if (in_array(strtolower($o), array_map('strtolower', $deny), true)) {
                    $inDeny = true;
                }
            }
            if ($allow && !array_intersect(array_map('strtolower', $e['options']), array_map('strtolower', $allow))) {
                [$risk, $reason] = ['fail', 'not in --allow list'];
            } elseif ($inDeny) {
                [$risk, $reason] = ['fail', 'license is on your --deny list'];
            } else {
                [$risk, $reason] = match ($e['category']) {
                    'permissive' => ['ok', null],
                    'weak-copyleft' => ['info', 'changes to this library itself must be shared; using it is fine'],
                    'strong-copyleft' => $closed && $distribute ? ['fail', 'GPL: distributing closed-source software that includes it is not allowed']
                        : ($closed ? ['warn', 'GPL: fine for internal/SaaS use, but blocks distributing closed-source code'] : ['info', 'GPL']),
                    'network-copyleft' => $closed && ($saas || $distribute) ? ['fail', 'AGPL: offering it over a network requires publishing your source'] : ['warn', 'AGPL: triggers source disclosure if users access it over a network'],
                    'restricted' => ['fail', 'source-available / non-commercial license — check usage terms'],
                    'proprietary' => ['warn', 'proprietary — make sure you hold a license'],
                    default => ['warn', 'no recognised license declared — legally "all rights reserved"'],
                };
            }
            $rows[] = ['name' => $n, 'version' => $x['version'], 'dev' => $x['dev'], 'license' => implode(' OR ', (array) ($x['license'] ?? [])) ?: 'none', 'effective' => $e['license'], 'category' => $e['category'], 'risk' => $risk, 'reason' => $reason];
        }
        $byCat = array_count_values(array_column($rows, 'category'));
        $byLic = array_count_values(array_column($rows, 'effective'));
        arsort($byLic);
        $flagged = array_values(array_filter($rows, static fn ($r) => in_array($r['risk'], ['fail', 'warn'], true)));
        $fails = count(array_filter($rows, static fn ($r) => $r['risk'] === 'fail'));

        $this->out(['project_license' => $projLic, 'packages' => count($rows), 'categories' => $byCat, 'licenses' => $byLic, 'flagged' => $flagged, 'all' => $rows], function (array $d) use ($rows, $distribute, $saas) {
            Ui::title('License compliance', $d['packages'] . ' packages · your project: ' . (implode(' OR ', $d['project_license']) ?: 'not declared') . ($distribute ? ' · distributed' : '') . ($saas ? ' · SaaS' : ''));
            Ui::section('By category');
            $styles = ['permissive' => 'green', 'weak-copyleft' => 'cyan', 'strong-copyleft' => 'yellow', 'network-copyleft' => 'red', 'restricted' => 'red', 'proprietary' => 'magenta', 'unknown' => 'gray'];
            foreach ($styles as $cat => $st) {
                if (!empty($d['categories'][$cat])) {
                    Ui::out('  ' . Ui::pad($cat, 18) . Ui::bar($d['categories'][$cat] / max(1, $d['packages']), 25, $st) . ' ' . $d['categories'][$cat]);
                }
            }
            Ui::section('By license');
            Ui::out('  ' . implode('  ', array_map(static fn ($k, $v) => Ui::c('cyan', (string) $k) . ' ×' . $v, array_keys($d['licenses']), $d['licenses'])));
            Ui::section('Needs attention');
            if (!$d['flagged']) {
                Ui::out(Ui::status('ok', 'No license risks found'));
            }
            foreach ($d['flagged'] as $r) {
                Ui::out(Ui::status($r['risk'], Ui::c('bold', $r['name']) . ' ' . Ui::c('gray', $r['version']) . '  ' . Ui::c('yellow', $r['license']) . ($r['dev'] ? Ui::c('gray', ' (dev only)') : '') . Ui::c('gray', ' — ' . $r['reason'])));
            }
            if ($this->a->has('list')) {
                Ui::section('All packages');
                $t = [];
                foreach ($rows as $r) {
                    $t[] = [Ui::c('cyan', $r['name']), $r['version'], $r['license'], $r['category'], $r['dev'] ? 'dev' : 'prod'];
                }
                Ui::table(['Package', 'Version', 'License', 'Category', 'Env'], $t);
            }
            Ui::out();
            Ui::out(Ui::c('gray', '  Guidance only, not legal advice. Export a report: sm-composer export --format csv'));
        });
        return $fails ? 1 : 0;
    }
}

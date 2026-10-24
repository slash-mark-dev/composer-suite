<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class SecurityCommand extends Command
{
    public const NAME = 'security';
    public const TITLE = 'Security Advisory Checker';
    public const SUMMARY = 'Check for known vulnerabilities (FriendsOfPHP + GitHub advisories)';
    public const CATEGORY = 'Security & compliance';
    public const ALIASES = ['audit', 'vuln', 'cve'];
    public const USAGE = '[vendor/pkg[:ver] …] [--no-dev] [--severity <level>] [--db <path>]';
    public const OPTIONS = ['no-dev' => 'flag', 'severity' => 'value', 'db' => 'value', 'ignore' => 'multi', 'history' => 'flag'];
    public const HELP = [
        '(no package)' => 'Audit every package in ./composer.lock',
        'vendor/pkg:1.2.3' => 'Check specific packages/versions without a project',
        '--severity <level>' => 'Only report this level or higher: low, medium, high, critical',
        '--db <path>' => 'Use a local clone of github.com/FriendsOfPHP/security-advisories (offline)',
        '--ignore <id>' => 'Ignore advisories by CVE / GHSA / PKSA id (repeatable)',
        '--history' => 'Single package: list every advisory ever published for it',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer security', 'sm-composer security --severity high --no-dev', 'sm-composer security firebase/php-jwt:5.5.1', 'sm-composer security --db ~/security-advisories'];

    private const SEV = ['low' => 1, 'medium' => 2, 'moderate' => 2, 'high' => 3, 'critical' => 4];

    public function run(): int
    {
        $targets = [];
        $source = '';
        if ($this->a->pos) {
            foreach ($this->a->pos as $spec) {
                [$n, $v] = $this->pkgWithVersion($spec);
                $targets[$n] = ['version' => $v ?? (Packagist::latest($n)['version'] ?? null), 'dev' => false];
            }
            $source = 'given packages';
        } else {
            $p = $this->project();
            $p->requireLock();
            foreach ($p->locked(!$this->a->has('no-dev')) as $n => $x) {
                $targets[$n] = ['version' => $x['version'], 'dev' => $x['dev']];
            }
            $source = 'composer.lock (' . count($targets) . ' packages)';
        }
        $db = $this->a->get('db');
        Ui::info('Checking ' . count($targets) . ' packages against ' . ($db ? 'local FriendsOfPHP database' : 'Packagist security advisories (FriendsOfPHP + GitHub)') . '…');
        $all = $db ? $this->fromFriendsOfPhp($db, array_keys($targets)) : Packagist::advisories(array_keys($targets));

        if ($this->a->has('history') && count($targets) === 1) {
            $n = array_key_first($targets);
            return $this->out(['package' => $n, 'advisories' => $all[$n] ?? []], function (array $d) {
                Ui::title('Advisory history · ' . $d['package'], count($d['advisories']) . ' published');
                foreach ($d['advisories'] as $a) {
                    Ui::out('  ' . Ui::c('gray', Ui::date($a['reportedAt'] ?? null)) . '  ' . self::sevLabel($a['severity'] ?? null) . ' ' . $a['title'] . Ui::c('gray', '  affects ' . $a['affectedVersions']));
                }
            });
        }

        $min = self::SEV[strtolower((string) $this->a->get('severity', 'low'))] ?? 1;
        $ignore = array_map('strtolower', $this->a->list('ignore'));
        $found = [];
        foreach ($targets as $n => $t) {
            $v = $t['version'];
            if (!$v || str_starts_with($v, 'dev-')) {
                continue;
            }
            foreach ($all[$n] ?? [] as $a) {
                $ids = array_map('strtolower', array_filter([$a['cve'] ?? null, $a['remoteId'] ?? null, $a['advisoryId'] ?? null]));
                if (array_intersect($ids, $ignore)) {
                    continue;
                }
                $aff = (string) ($a['affectedVersions'] ?? '');
                if (!Semver::isValid($aff) || !Semver::satisfies($v, $aff)) {
                    continue;
                }
                $sev = strtolower((string) ($a['severity'] ?? ''));
                if ((self::SEV[$sev] ?? 2) < $min) {
                    continue;
                }
                $found[] = [
                    'package' => $n, 'version' => $v, 'dev' => $t['dev'],
                    'title' => $a['title'] ?? '', 'cve' => $a['cve'] ?? null, 'id' => $a['remoteId'] ?? $a['advisoryId'] ?? null,
                    'severity' => $sev ?: null, 'affected' => $aff, 'link' => $a['link'] ?? null, 'reported' => $a['reportedAt'] ?? null,
                    'source' => $a['source'] ?? ($a['sources'][0]['name'] ?? null),
                ];
            }
        }
        // suggest the smallest safe upgrade for each vulnerable package
        $fix = [];
        if ($found && !$db) {
            $vuln = array_unique(array_column($found, 'package'));
            $versions = Packagist::versionsMany($vuln);
            foreach ($vuln as $n) {
                $cur = $targets[$n]['version'];
                $affs = array_column(array_filter($found, static fn ($f) => $f['package'] === $n), 'affected');
                $cands = array_reverse(Semver::rsort(array_filter(array_column($versions[$n] ?? [], 'version'), static fn ($v) => Semver::isStable($v) && Semver::compare($v, $cur) > 0)));
                foreach ($cands as $c) {
                    $ok = true;
                    foreach ($affs as $aff) {
                        if (Semver::satisfies($c, $aff)) {
                            $ok = false;
                            break;
                        }
                    }
                    if ($ok) {
                        $fix[$n] = ['version' => $c, 'level' => Semver::diff($cur, $c)];
                        break;
                    }
                }
            }
        }
        usort($found, static fn ($a, $b) => (self::SEV[$b['severity'] ?? ''] ?? 2) <=> (self::SEV[$a['severity'] ?? ''] ?? 2));

        $this->out(['checked' => $source, 'vulnerable_packages' => count(array_unique(array_column($found, 'package'))), 'advisories' => $found, 'fixes' => $fix], function (array $d) {
            Ui::title('Security audit', $d['checked']);
            Ui::out();
            if (!$d['advisories']) {
                Ui::out(Ui::status('ok', 'No known vulnerabilities found'));
                return;
            }
            $by = [];
            foreach ($d['advisories'] as $a) {
                $by[$a['package']][] = $a;
            }
            foreach ($by as $pkg => $list) {
                $f = $d['fixes'][$pkg] ?? null;
                Ui::out(Ui::c('bold.red', '✖ ' . $pkg) . ' ' . Ui::c('bold', $list[0]['version']) . ($list[0]['dev'] ? Ui::c('yellow', ' (dev)') : '') . ($f ? Ui::c('green', '  → fixed in ' . $f['version']) . Ui::c('gray', ' (' . $f['level'] . ' update)') : ''));
                foreach ($list as $a) {
                    Ui::out('  ' . self::sevLabel($a['severity']) . ' ' . $a['title']);
                    Ui::out(Ui::c('gray', '      ' . implode(' · ', array_filter([$a['cve'], $a['id'] !== $a['cve'] ? $a['id'] : null, 'affects ' . $a['affected'], $a['reported'] ? 'reported ' . Ui::date($a['reported']) : null]))));
                    if ($a['link']) {
                        Ui::out(Ui::c('gray', '      ' . $a['link']));
                    }
                }
                Ui::out();
            }
            Ui::out(Ui::status('fail', count($d['advisories']) . ' advisories in ' . $d['vulnerable_packages'] . ' packages'));
            $upd = array_keys(array_filter($d['fixes'], static fn ($f) => $f['level'] !== 'major'));
            $req = array_filter($d['fixes'], static fn ($f) => $f['level'] === 'major');
            if ($upd) {
                Ui::out(Ui::c('gray', '  Fix: ') . Ui::c('green', 'composer update ' . implode(' ', $upd) . ' -W'));
            }
            if ($req) {
                Ui::out(Ui::c('gray', '  Fix (major, check the changelog first): ') . Ui::c('green', 'composer require ' . implode(' ', array_map(static fn ($n, $f) => escapeshellarg($n . ':' . Semver::suggest($f['version'])), array_keys($req), $req)) . ' -W'));
            }
        });
        return $found ? 1 : 0;
    }

    private static function sevLabel(?string $s): string
    {
        return match (strtolower((string) $s)) {
            'critical' => Ui::badge('CRITICAL', 'bgRed'),
            'high' => Ui::badge('HIGH', 'bgRed'),
            'medium', 'moderate' => Ui::badge('MEDIUM', 'bgYellow'),
            'low' => Ui::badge('LOW', 'bgBlue'),
            default => Ui::badge('UNRATED', 'bgMagenta'),
        };
    }

    /**
     * Read advisories from a local clone of FriendsOfPHP/security-advisories.
     * Layout: <vendor>/<package>/<id>.yaml with title, link, cve, reference, branches.*.versions
     */
    private function fromFriendsOfPhp(string $path, array $names): array
    {
        $path = rtrim((string) preg_replace('#^~#', (string) getenv('HOME'), $path), '/');
        if (!is_dir($path)) {
            throw new \RuntimeException("No advisories database at $path. Get it with: git clone https://github.com/FriendsOfPHP/security-advisories");
        }
        $out = [];
        foreach ($names as $n) {
            foreach (glob("$path/$n/*.yaml") ?: [] as $file) {
                $y = self::miniYaml((string) file_get_contents($file));
                $ranges = [];
                foreach ($y['branches'] ?? [] as $b) {
                    $vs = $b['versions'] ?? [];
                    if ($vs) {
                        $ranges[] = implode(',', (array) $vs);
                    }
                }
                $out[$n][] = [
                    'advisoryId' => basename($file, '.yaml'), 'packageName' => $n, 'title' => $y['title'] ?? basename($file),
                    'link' => $y['link'] ?? null, 'cve' => ($y['cve'] ?? '') !== '~' ? ($y['cve'] ?? null) : null,
                    'affectedVersions' => implode('|', $ranges), 'source' => 'FriendsOfPHP/security-advisories', 'reportedAt' => null, 'severity' => null,
                ];
            }
        }
        return $out;
    }

    /** Just enough YAML for the FriendsOfPHP advisory format. */
    public static function miniYaml(string $text): array
    {
        $root = [];
        $stack = [[-1, &$root]];
        foreach (preg_split("/\r?\n/", $text) as $line) {
            if (trim($line) === '' || ltrim($line)[0] === '#') {
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line));
            $line = trim($line);
            while (count($stack) > 1 && $stack[count($stack) - 1][0] >= $indent) {
                array_pop($stack);
            }
            $parent = &$stack[count($stack) - 1][1];
            if (str_starts_with($line, '- ')) {
                $parent[] = self::scalar(substr($line, 2));
                unset($parent);
                continue;
            }
            if (!preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
                unset($parent);
                continue;
            }
            $key = trim($m[1], " '\"");
            $val = $m[2];
            if ($val === '') {
                $parent[$key] = [];
                $stack[] = [$indent, &$parent[$key]];
            } elseif (preg_match('/^\[(.*)\]$/', $val, $mm)) {
                $parent[$key] = $mm[1] === '' ? [] : array_map([self::class, 'scalar'], preg_split('/\s*,\s*/', $mm[1]));
            } else {
                $parent[$key] = self::scalar($val);
            }
            unset($parent);
        }
        return $root;
    }

    private static function scalar(string $v): string
    {
        $v = trim($v);
        if ((str_starts_with($v, "'") && str_ends_with($v, "'")) || (str_starts_with($v, '"') && str_ends_with($v, '"'))) {
            return substr($v, 1, -1);
        }
        return $v;
    }
}

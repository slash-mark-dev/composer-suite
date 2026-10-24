<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Resolver;
use SlashMark\ComposerSuite\Ui;

final class SizeCommand extends Command
{
    public const NAME = 'size';
    public const TITLE = 'Package Size Analyzer';
    public const SUMMARY = 'Disk size and dependency weight of packages';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['weight', 'du'];
    public const USAGE = '[vendor/package] [-n <top>] [--no-dev]';
    public const OPTIONS = ['limit' => 'value', 'no-dev' => 'flag'];
    public const SHORT = ['n' => 'limit'];
    public const HELP = [
        '(no package)' => 'Measure ./vendor: size of each package and with all its dependencies',
        'vendor/package' => 'Estimate the weight of a package before installing (dependency count + repo sizes)',
        '-n, --limit <n>' => 'Rows to show (default 20)',
        '--no-dev' => 'Only production packages (what ships with composer install --no-dev)',
    ];
    public const EXAMPLES = ['sm-composer size', 'sm-composer size --no-dev', 'sm-composer size laravel/framework'];

    public function run(): int
    {
        $target = $this->a->arg(0);
        return $target ? $this->remote(Packagist::assertName($target)) : $this->local();
    }

    private function local(): int
    {
        $p = $this->project();
        $vendor = $p->dir . '/' . $p->vendorDir();
        if (!is_dir($vendor)) {
            throw new \RuntimeException('No ' . $p->vendorDir() . '/ folder — run composer install first, or pass a package name to estimate remotely');
        }
        $locked = $p->hasLock() ? $p->locked(!$this->a->has('no-dev')) : [];
        if (!$locked) {
            foreach ($p->installed() as $n => $x) {
                $locked[$n] = $x + ['dev' => false, 'require' => $x['require'] ?? []];
            }
        }
        $own = [];
        $files = [];
        foreach ($locked as $n => $x) {
            $own[$n] = Project::dirSize("$vendor/$n", $fc);
            $files[$n] = $fc;
        }
        // inclusive size = package + everything it pulls in (each counted once)
        $closure = function (string $n, array &$seen) use (&$closure, $locked): void {
            if (isset($seen[$n]) || !isset($locked[$n])) {
                return;
            }
            $seen[$n] = true;
            foreach (array_keys($locked[$n]['require'] ?? []) as $d) {
                $closure(strtolower((string) $d), $seen);
            }
        };
        $rows = [];
        foreach ($locked as $n => $x) {
            $seen = [];
            $closure($n, $seen);
            $incl = array_sum(array_map(static fn ($k) => $own[$k] ?? 0, array_keys($seen)));
            $rows[] = ['name' => $n, 'version' => $x['version'] ?? '', 'dev' => $x['dev'], 'size' => $own[$n], 'files' => $files[$n], 'with_deps' => $incl, 'deps' => count($seen) - 1];
        }
        usort($rows, static fn ($a, $b) => $b['size'] <=> $a['size']);
        $total = Project::dirSize($vendor, $totalFiles);
        $limit = $this->a->int('limit', 20);

        return $this->out(['vendor' => realpath($vendor), 'total' => $total, 'total_files' => $totalFiles, 'packages' => $rows], function (array $d) use ($limit) {
            Ui::title('vendor/ size', Ui::bytes($d['total']) . ' · ' . number_format($d['total_files']) . ' files · ' . count($d['packages']) . ' packages');
            Ui::out();
            $max = max(array_column($d['packages'], 'size') ?: [1]) ?: 1;
            $t = [];
            foreach (array_slice($d['packages'], 0, $limit) as $r) {
                $t[] = [Ui::c('cyan', $r['name']) . ($r['dev'] ? Ui::c('yellow', ' dev') : ''), Ui::bytes($r['size']), Ui::bar($r['size'] / $max, 18), round($r['size'] / max(1, $d['total']) * 100, 1) . '%', number_format($r['files']), Ui::bytes($r['with_deps']) . Ui::c('gray', ' (+' . $r['deps'] . ')')];
            }
            Ui::table(['Package', 'Size', '', 'Share', 'Files', 'With deps'], $t, [1 => true, 3 => true, 4 => true, 5 => true]);
            $dev = array_sum(array_map(static fn ($r) => $r['dev'] ? $r['size'] : 0, $d['packages']));
            if ($dev > 0) {
                Ui::out();
                Ui::out(Ui::c('gray', '  Dev packages: ' . Ui::bytes($dev) . ' — deploy with `composer install --no-dev -o` to skip them'));
            }
        });
    }

    private function remote(string $name): int
    {
        $latest = Packagist::latest($name);
        if (!$latest) {
            throw new \InvalidArgumentException("No releases for $name");
        }
        $req = array_filter($latest['require'] ?? [], static fn ($k) => !Packagist::isPlatform((string) $k), ARRAY_FILTER_USE_KEY);
        Ui::info('Resolving the dependency graph of ' . $name . '…');
        $r = (new Resolver())->resolve([$name => $latest['version']], 'root');
        $pkgs = array_keys($r->resolved);
        // GitHub repo sizes (KB) as an approximation of download weight
        $urls = [];
        $infos = Packagist::packageMany($pkgs);
        foreach ($pkgs as $p) {
            if ($gh = Packagist::github($infos[$p]['repository'] ?? null)) {
                $urls[$p] = Packagist::githubApi() . "/repos/{$gh[0]}/{$gh[1]}";
            }
        }
        $repos = $urls ? Http::jsonMany(array_values($urls), 8, 86400) : [];
        $rows = [];
        foreach ($pkgs as $p) {
            $kb = isset($urls[$p]) ? ($repos[$urls[$p]]['size'] ?? null) : null;
            $rows[] = ['name' => $p, 'version' => $r->resolved[$p]['version'], 'repo_kb' => $kb, 'direct' => isset($req[$p]) || $p === $name];
        }
        usort($rows, static fn ($a, $b) => ($b['repo_kb'] ?? -1) <=> ($a['repo_kb'] ?? -1));
        $known = array_filter(array_column($rows, 'repo_kb'), static fn ($x) => $x !== null);

        return $this->out(['package' => $name, 'version' => $latest['version'], 'direct_deps' => count($req), 'total_packages' => count($pkgs), 'repo_size_kb_known' => array_sum($known), 'packages' => $rows], function (array $d) {
            Ui::title("Install weight · {$d['package']} {$d['version']}");
            Ui::out();
            Ui::kv([
                'Direct deps' => (string) $d['direct_deps'],
                'Total installed' => $d['total_packages'] . ' packages (incl. itself)',
                'Repo size (sum)' => Ui::bytes($d['repo_size_kb_known'] * 1024) . Ui::c('gray', ' — GitHub repo sizes; dist zips are usually 5–30% of this'),
            ]);
            Ui::out();
            $max = max(array_column($d['packages'], 'repo_kb') ?: [1]) ?: 1;
            $t = [];
            foreach ($d['packages'] as $r) {
                $t[] = [Ui::c($r['direct'] ? 'cyan' : 'gray', $r['name']), $r['version'], $r['repo_kb'] === null ? '-' : Ui::bytes($r['repo_kb'] * 1024), $r['repo_kb'] === null ? '' : Ui::bar($r['repo_kb'] / $max, 18)];
            }
            Ui::table(['Package', 'Version', 'Repo size', ''], $t, [2 => true]);
            Ui::out();
            Ui::out(Ui::c('gray', '  For exact numbers install it in a scratch folder and run: sm-composer size'));
        });
    }
}

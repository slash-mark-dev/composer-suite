<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Resolver;
use SlashMark\ComposerSuite\Ui;

final class TreeCommand extends Command
{
    /** @var array<string, string> virtual/replaced package => provider */
    private array $provided = [];

    public const NAME = 'tree';
    public const TITLE = 'Dependency Tree Viewer';
    public const SUMMARY = 'Visualize the dependency hierarchy (project or any package)';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['deps', 't'];
    public const USAGE = '[vendor/package[:ver]] [--depth <n>] [--no-dev] [--platform] [--full] [--format text|mermaid|dot]';
    public const OPTIONS = ['depth' => 'value', 'no-dev' => 'flag', 'platform' => 'flag', 'full' => 'flag', 'format' => 'value', 'only' => 'value'];
    public const SHORT = ['D' => 'depth', 'f' => 'format'];
    public const HELP = [
        '(no package)' => 'Tree of ./composer.json, using exact versions from composer.lock when present',
        'vendor/package' => 'Resolve and show the tree of any published package',
        '-D, --depth <n>' => 'Max depth (default 6)',
        '--no-dev' => 'Skip require-dev',
        '--platform' => 'Show php / ext-* requirements too',
        '--full' => 'Expand repeated sub-trees instead of marking them (•)',
        '--only <name>' => 'Show only branches that lead to this package',
        '-f, --format <fmt>' => 'text (default), mermaid or dot (Graphviz) for diagrams',
    ];
    public const EXAMPLES = ['sm-composer tree', 'sm-composer tree laravel/framework --depth 2', 'sm-composer tree --only psr/log', 'sm-composer tree --format mermaid > deps.mmd'];

    public function run(): int
    {
        $depth = max(1, $this->a->int('depth', 6));
        $platform = $this->a->has('platform');
        $target = $this->a->arg(0);
        $source = '';
        if ($target !== null) {
            [$name, $ver] = $this->pkgWithVersion($target);
            $row = $ver ? Packagist::findVersion($name, $ver) : Packagist::latest($name);
            if (!$row) {
                throw new \InvalidArgumentException("Version $ver of $name not found");
            }
            $requires = $row['require'] ?? [];
            Ui::info('Resolving dependencies of ' . $name . ':' . $row['version'] . ' from Packagist…');
            $r = (new Resolver())->resolve(array_filter($requires, static fn ($k) => !Packagist::isPlatform((string) $k), ARRAY_FILTER_USE_KEY), $name);
            $nodes = $this->fromResolved($r->resolved);
            $rootLabel = $name . ' ' . $row['version'];
            $source = 'resolved from Packagist (newest matching stable releases)';
            $problems = $r->problems;
        } else {
            $p = $this->project();
            $requires = $p->requires(!$this->a->has('no-dev'), true);
            $rootLabel = ($p->json['name'] ?? basename((string) realpath($p->dir))) . ' (root)';
            if ($p->hasLock()) {
                $nodes = [];
                foreach ($p->locked(!$this->a->has('no-dev')) as $n => $pkg) {
                    $nodes[$n] = ['version' => $pkg['version'], 'require' => $pkg['require'] ?? [], 'dev' => $pkg['dev']];
                    foreach (array_merge(array_keys($pkg['replace'] ?? []), array_keys($pkg['provide'] ?? [])) as $virtual) {
                        $this->provided[strtolower((string) $virtual)] = $pkg['name'];
                    }
                }
                $source = 'composer.lock';
                $problems = [];
            } else {
                Ui::info('No composer.lock — resolving from Packagist…');
                $r = (new Resolver())->resolve(array_filter($requires, static fn ($k) => !Packagist::isPlatform((string) $k), ARRAY_FILTER_USE_KEY), 'root');
                $nodes = $this->fromResolved($r->resolved);
                $source = 'resolved from Packagist (no lock file)';
                $problems = $r->problems;
            }
        }

        $tree = $this->build($requires, $nodes, $depth, $platform);
        if ($only = $this->a->get('only')) {
            $only = strtolower($only);
            $tree = $this->prune($tree, $only);
            if (!$tree) {
                throw new \InvalidArgumentException("Nothing in the tree depends on $only");
            }
        }
        $stats = ['direct' => count(array_filter(array_keys($requires), static fn ($k) => !Packagist::isPlatform((string) $k))), 'total' => count($nodes), 'max_depth' => $this->maxDepth($tree)];
        $fmt = $this->a->get('format', 'text');

        if ($this->json) {
            Ui::json(['root' => $rootLabel, 'source' => $source, 'stats' => $stats, 'tree' => $tree, 'problems' => $problems]);
            return 0;
        }
        if ($fmt === 'mermaid' || $fmt === 'dot') {
            Ui::out($this->graph($rootLabel, $tree, $fmt));
            return 0;
        }
        Ui::title('Dependency tree', $source);
        Ui::out();
        Ui::out(Ui::c('bold', $rootLabel));
        $seen = [];
        Ui::tree($this->labels($tree, $seen));
        Ui::out();
        Ui::out(Ui::c('gray', "  {$stats['direct']} direct · {$stats['total']} total packages · depth {$stats['max_depth']}" . (!$this->a->has('full') ? ' · • = shown above' : '')));
        foreach ($problems as $pr) {
            Ui::out(Ui::status('warn', $pr['package'] . ': ' . $pr['reason']));
        }
        return 0;
    }

    private function fromResolved(array $resolved): array
    {
        $out = [];
        foreach ($resolved as $n => $r) {
            $out[$n] = ['version' => $r['version'], 'require' => $r['require'], 'dev' => false];
        }
        return $out;
    }

    private function build(array $requires, array $nodes, int $maxDepth, bool $platform): array
    {
        $make = function (string $name, string $constraint, array $path, int $depth) use (&$make, $nodes, $maxDepth, $platform): ?array {
            $l = strtolower($name);
            if (Packagist::isPlatform($l)) {
                return $platform ? ['name' => $name, 'constraint' => $constraint, 'version' => null, 'platform' => true, 'children' => []] : null;
            }
            $node = $nodes[$l] ?? null;
            $o = ['name' => $name, 'constraint' => $constraint, 'version' => $node['version'] ?? null, 'dev' => $node['dev'] ?? false, 'children' => []];
            if ($node === null) {
                if (isset($this->provided[$l])) {
                    $o['provided_by'] = $this->provided[$l];
                } else {
                    $o['missing'] = true;
                }
            } elseif (\SlashMark\ComposerSuite\Semver::isValid($constraint) && !str_starts_with((string) $node['version'], 'dev-') && !\SlashMark\ComposerSuite\Semver::satisfies((string) $node['version'], $constraint)) {
                $o['unsatisfied'] = true;
            }
            if (in_array($l, $path, true)) {
                $o['circular'] = true;
                return $o;
            }
            if ($node && $depth < $maxDepth) {
                foreach ($node['require'] as $dn => $dc) {
                    if ($c = $make((string) $dn, (string) $dc, [...$path, $l], $depth + 1)) {
                        $o['children'][] = $c;
                    }
                }
            }
            return $o;
        };
        $out = [];
        foreach ($requires as $n => $c) {
            if ($x = $make((string) $n, (string) $c, [], 1)) {
                $out[] = $x;
            }
        }
        return $out;
    }

    private function labels(array $tree, array &$seen): array
    {
        $out = [];
        foreach ($tree as $n) {
            if (!empty($n['platform'])) {
                $out[] = ['label' => Ui::c('magenta', $n['name']) . ' ' . Ui::c('gray', $n['constraint']), 'children' => []];
                continue;
            }
            $key = strtolower($n['name']);
            $repeat = isset($seen[$key]) && $n['children'] && !$this->a->has('full');
            if (!empty($n['provided_by'])) {
                $out[] = ['label' => Ui::c('gray', $n['name'] . ' (' . $n['constraint'] . ') provided by ' . $n['provided_by']), 'children' => []];
                continue;
            }
            $label = Ui::c('cyan', $n['name']) . ' ' . Ui::c('green', (string) ($n['version'] ?? '?')) . ' ' . Ui::c('gray', '(' . $n['constraint'] . ')');
            if (!empty($n['unsatisfied'])) {
                $label .= ' ' . Ui::c('red', '✖ locked version does not satisfy this');
            }
            if (!empty($n['missing'])) {
                $label .= ' ' . Ui::c('red', 'not installed');
            }
            if (!empty($n['dev'])) {
                $label .= ' ' . Ui::c('yellow', 'dev');
            }
            if (!empty($n['circular'])) {
                $label .= ' ' . Ui::c('magenta', '↻ circular');
            }
            if ($repeat) {
                $label .= Ui::c('gray', ' •');
            }
            $seen[$key] = true;
            $out[] = ['label' => $label, 'children' => $repeat ? [] : $this->labels($n['children'], $seen)];
        }
        return $out;
    }

    private function prune(array $tree, string $target): array
    {
        $out = [];
        foreach ($tree as $n) {
            if (strtolower($n['name']) === $target) {
                $n['children'] = [];
                $out[] = $n;
                continue;
            }
            $kids = $this->prune($n['children'], $target);
            if ($kids) {
                $n['children'] = $kids;
                $out[] = $n;
            }
        }
        return $out;
    }

    private function maxDepth(array $tree, int $d = 1): int
    {
        $m = $tree ? $d : 0;
        foreach ($tree as $n) {
            $m = max($m, $this->maxDepth($n['children'], $d + 1));
        }
        return $m;
    }

    private function graph(string $root, array $tree, string $fmt): string
    {
        $edges = [];
        $walk = function (string $parent, array $nodes) use (&$walk, &$edges): void {
            foreach ($nodes as $n) {
                $label = $n['name'] . (isset($n['version']) && $n['version'] ? ' ' . $n['version'] : '');
                $edges[$parent . "\0" . $label] = [$parent, $label];
                $walk($label, $n['children']);
            }
        };
        $walk($root, $tree);
        $ids = [];
        $id = static function (string $s) use (&$ids): string {
            return $ids[$s] ??= 'n' . count($ids);
        };
        if ($fmt === 'dot') {
            $o = "digraph dependencies {\n  rankdir=LR;\n  node [shape=box, style=rounded, fontname=\"Helvetica\"];\n";
            foreach ($edges as [$a, $b]) {
                $o .= '  "' . addslashes($a) . '" -> "' . addslashes($b) . "\";\n";
            }
            return $o . "}";
        }
        $o = "graph LR\n";
        foreach ($edges as [$a, $b]) {
            $o .= '  ' . $id($a) . '["' . str_replace('"', "'", $a) . '"] --> ' . $id($b) . '["' . str_replace('"', "'", $b) . "\"]\n";
        }
        return rtrim($o);
    }
}

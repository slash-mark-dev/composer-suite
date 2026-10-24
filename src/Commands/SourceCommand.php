<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class SourceCommand extends Command
{
    public const NAME = 'source';
    public const TITLE = 'Source Code Explorer';
    public const SUMMARY = 'Browse a package repository tree and read files';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['src', 'browse'];
    public const USAGE = '<vendor/package> [path] [--file <path>] [--ref <tag>] [--depth <n>] [--local]';
    public const OPTIONS = ['file' => 'value', 'ref' => 'value', 'depth' => 'value', 'local' => 'flag', 'lines' => 'value'];
    public const SHORT = ['f' => 'file', 'l' => 'local'];
    public const HELP = [
        '[path]' => 'Only show this folder, e.g. src/Handler',
        '-f, --file <path>' => 'Print a file with line numbers and PHP highlighting',
        '--lines <a-b>' => 'Only these lines of --file, e.g. 40-80',
        '--ref <tag>' => 'Tag/branch to browse (default: latest stable release tag)',
        '--depth <n>' => 'Tree depth (default 2)',
        '-l, --local' => 'Browse the installed copy in ./vendor (offline)',
    ];
    public const EXAMPLES = ['sm-composer source monolog/monolog', 'sm-composer source monolog/monolog src --depth 3', 'sm-composer source monolog/monolog --file src/Monolog/Logger.php --lines 1-60'];

    public function run(): int
    {
        $name = $this->pkg();
        $sub = trim((string) $this->a->arg(1, ''), '/');
        $depth = max(1, $this->a->int('depth', 2));
        if ($this->a->has('local')) {
            return $this->local($name, $sub, $depth);
        }
        $info = Packagist::package($name);
        $gh = Packagist::github($info['repository'] ?? null);
        if (!$gh) {
            throw new \RuntimeException("Source browsing needs a GitHub repository; $name uses " . ($info['repository'] ?? 'none') . '. Try --local if it is installed.');
        }
        $ref = $this->a->get('ref') ?? (Packagist::latest($name)['version'] ?? 'HEAD');
        $file = $this->a->get('file');
        if ($file !== null) {
            $code = Http::text(Packagist::githubRaw() . "/{$gh[0]}/{$gh[1]}/$ref/" . ltrim($file, '/'), 3600);
            return $this->printFile($code, "{$gh[0]}/{$gh[1]}@$ref:$file", "https://github.com/{$gh[0]}/{$gh[1]}/blob/$ref/$file");
        }
        $repo = Http::json(Packagist::githubApi() . "/repos/{$gh[0]}/{$gh[1]}", 3600);
        $tree = Http::json(Packagist::githubApi() . "/repos/{$gh[0]}/{$gh[1]}/git/trees/" . rawurlencode($ref) . '?recursive=1', 3600);
        $entries = [];
        foreach ($tree['tree'] ?? [] as $e) {
            $entries[] = ['path' => $e['path'], 'dir' => $e['type'] === 'tree', 'size' => $e['size'] ?? 0];
        }
        $data = [
            'package' => $name, 'repository' => "https://github.com/{$gh[0]}/{$gh[1]}", 'ref' => $ref,
            'default_branch' => $repo['default_branch'] ?? null, 'stars' => $repo['stargazers_count'] ?? null, 'size_kb' => $repo['size'] ?? null,
            'archived' => $repo['archived'] ?? false, 'pushed_at' => $repo['pushed_at'] ?? null, 'truncated' => $tree['truncated'] ?? false,
            'entries' => $entries,
        ];
        return $this->out($data, function (array $d) use ($sub, $depth) {
            Ui::title($d['package'] . ' @ ' . $d['ref'], $d['repository']);
            Ui::kv([
                'Last push' => Ui::ago($d['pushed_at']) . ($d['archived'] ? Ui::c('red', '  (archived — read only)') : ''),
                'Default branch' => $d['default_branch'],
                'Files' => count(array_filter($d['entries'], static fn ($e) => !$e['dir'])) . ' · ' . Ui::bytes(array_sum(array_column($d['entries'], 'size'))),
            ]);
            Ui::out();
            $this->renderTree($d['entries'], $sub, $depth);
            if ($d['truncated']) {
                Ui::out(Ui::c('yellow', '  (GitHub truncated this listing — pass a sub-path to see more)'));
            }
            Ui::out();
            Ui::out(Ui::c('gray', '  Read a file: sm-composer source ' . $d['package'] . ' --file <path>'));
            Ui::out(Ui::c('gray', '  Browse online: ' . $d['repository'] . '/tree/' . $d['ref'] . ($sub !== '' ? '/' . $sub : '')));
        });
    }

    private function local(string $name, string $sub, int $depth): int
    {
        $p = $this->project(false);
        $base = $p->dir . '/' . $p->vendorDir() . '/' . $name;
        if (!is_dir($base)) {
            throw new \RuntimeException("$name is not installed in " . $p->vendorDir() . '/');
        }
        if ($file = $this->a->get('file')) {
            $path = $base . '/' . ltrim($file, '/');
            if (!is_file($path)) {
                throw new \RuntimeException("No such file: $file");
            }
            return $this->printFile((string) file_get_contents($path), $p->vendorDir() . "/$name/$file", null);
        }
        $entries = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
            $entries[] = ['path' => $rel, 'dir' => $f->isDir(), 'size' => $f->isFile() ? $f->getSize() : 0];
        }
        usort($entries, static fn ($a, $b) => strcmp($a['path'], $b['path']));
        return $this->out(['package' => $name, 'path' => $base, 'entries' => $entries], function (array $d) use ($sub, $depth) {
            Ui::title($d['package'] . ' (installed copy)', $d['path']);
            Ui::out();
            $this->renderTree($d['entries'], $sub, $depth);
        });
    }

    private function renderTree(array $entries, string $sub, int $depth): void
    {
        $root = [];
        $sizes = [];
        foreach ($entries as $e) {
            $path = $e['path'];
            if ($sub !== '') {
                if (!str_starts_with($path, $sub . '/')) {
                    continue;
                }
                $path = substr($path, strlen($sub) + 1);
            }
            $parts = explode('/', $path);
            // accumulate size into every ancestor folder
            for ($i = 1; $i < count($parts); $i++) {
                $k = implode('/', array_slice($parts, 0, $i));
                $sizes[$k] = ($sizes[$k] ?? 0) + $e['size'];
            }
            if (count($parts) > $depth) {
                continue;
            }
            $node = &$root;
            foreach ($parts as $i => $part) {
                $isLast = $i === count($parts) - 1;
                $node[$part] ??= ['__dir' => !$isLast || $e['dir'], '__size' => $isLast ? $e['size'] : 0, '__path' => implode('/', array_slice($parts, 0, $i + 1)), '__kids' => []];
                if (!$isLast) {
                    $node[$part]['__dir'] = true;
                }
                $node = &$node[$part]['__kids'];
            }
            unset($node);
        }
        $toNodes = function (array $level) use (&$toNodes, $sizes): array {
            uksort($level, static fn ($a, $b) => [!$level[$a]['__dir'], strtolower((string) $a)] <=> [!$level[$b]['__dir'], strtolower((string) $b)]);
            $out = [];
            foreach ($level as $nm => $n) {
                $label = $n['__dir']
                    ? Ui::c('bold.blue', $nm . '/') . Ui::c('gray', '  ' . Ui::bytes($sizes[$n['__path']] ?? 0))
                    : (str_ends_with((string) $nm, '.php') ? Ui::c('cyan', (string) $nm) : (string) $nm) . Ui::c('gray', '  ' . Ui::bytes($n['__size']));
                $out[] = ['label' => $label, 'children' => $toNodes($n['__kids'])];
            }
            return $out;
        };
        Ui::out('  ' . Ui::c('bold', $sub !== '' ? $sub . '/' : './'));
        Ui::tree($toNodes($root), '  ');
    }

    private function printFile(string $code, string $label, ?string $url): int
    {
        $lines = explode("\n", rtrim($code, "\n"));
        $from = 1;
        $to = count($lines);
        if ($r = $this->a->get('lines')) {
            if (preg_match('/^(\d+)(?:-(\d+))?$/', $r, $m)) {
                $from = max(1, (int) $m[1]);
                $to = min(count($lines), (int) ($m[2] ?? $m[1]));
            }
        }
        if ($this->json) {
            Ui::json(['file' => $label, 'url' => $url, 'from' => $from, 'to' => $to, 'content' => implode("\n", array_slice($lines, $from - 1, $to - $from + 1))]);
            return 0;
        }
        Ui::out(Ui::c('gray', "── $label" . ($url ? "  $url" : '')));
        $w = strlen((string) $to);
        for ($i = $from; $i <= $to; $i++) {
            Ui::out(Ui::c('gray', str_pad((string) $i, $w, ' ', STR_PAD_LEFT) . ' │ ') . self::highlight($lines[$i - 1]));
        }
        return 0;
    }

    private static function highlight(string $line): string
    {
        if (!Ui::$color) {
            return $line;
        }
        if (preg_match('#^\s*(//|\*|/\*|\#)#', $line)) {
            return Ui::c('gray', $line);
        }
        $line = (string) preg_replace_callback('/(\'[^\']*\'|"[^"]*")/', static fn ($m) => Ui::c('green', $m[1]), $line);
        $line = (string) preg_replace_callback('/\b(namespace|use|class|interface|trait|enum|function|public|private|protected|static|final|abstract|readonly|return|new|if|else|elseif|foreach|for|while|throw|try|catch|match|fn|const|extends|implements|declare)\b/', static fn ($m) => Ui::c('magenta', $m[1]), $line);
        return (string) preg_replace_callback('/(\$\w+)/', static fn ($m) => Ui::c('cyan', $m[1]), $line);
    }
}

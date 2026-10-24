<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Ui;

final class AutoloadCommand extends Command
{
    public const NAME = 'autoload';
    public const TITLE = 'Autoload Analyzer';
    public const SUMMARY = 'Check PSR-4 / PSR-0 / classmap / files autoload configuration';
    public const CATEGORY = 'Project analysis';
    public const ALIASES = ['psr4', 'classes'];
    public const USAGE = '[--package <vendor/name>] [--dev] [--list]';
    public const OPTIONS = ['package' => 'value', 'no-dev' => 'flag', 'list' => 'flag'];
    public const SHORT = ['l' => 'list', 'p' => 'package'];
    public const HELP = [
        '-p, --package <name>' => 'Analyze an installed package in vendor/ instead of your project',
        '--no-dev' => 'Skip autoload-dev',
        '-l, --list' => 'List every class found with its file',
    ];
    public const EXAMPLES = ['sm-composer autoload', 'sm-composer autoload --list', 'sm-composer autoload --package monolog/monolog'];

    public function run(): int
    {
        if ($pkg = $this->a->get('package')) {
            $p = $this->project(false);
            $root = $p->dir . '/' . $p->vendorDir() . '/' . strtolower($pkg);
            if (!is_file($root . '/composer.json')) {
                throw new \RuntimeException("$pkg is not installed in " . $p->vendorDir() . '/');
            }
            $json = Project::readJson($root . '/composer.json');
        } else {
            $p = $this->project();
            $root = $p->dir;
            $json = $p->json;
        }
        $sections = ['autoload' => $json['autoload'] ?? []];
        if (!$this->a->has('no-dev') && !$pkg) {
            $sections['autoload-dev'] = $json['autoload-dev'] ?? [];
        }

        $mappings = [];
        $classes = [];
        $issues = [];
        $warn = static function (string $level, string $msg) use (&$issues): void {
            $issues[] = ['level' => $level, 'message' => $msg];
        };
        foreach ($sections as $sec => $al) {
            foreach (['psr-4', 'psr-0'] as $std) {
                $prefixes = array_keys($al[$std] ?? []);
                foreach ($prefixes as $a) {
                    foreach ($prefixes as $b) {
                        if ($a !== $b && $a !== '' && str_starts_with((string) $b, (string) $a)) {
                            $warn('info', "$sec $std: \"$b\" is nested inside \"$a\" (fine if intended, but double-check paths)");
                        }
                    }
                }
                foreach ($al[$std] ?? [] as $ns => $paths) {
                    foreach ((array) $paths as $path) {
                        $dir = rtrim($root . '/' . $path, '/');
                        $m = ['section' => $sec, 'type' => $std, 'namespace' => (string) $ns, 'path' => $path, 'exists' => is_dir($dir), 'files' => 0, 'classes' => 0, 'mismatches' => 0];
                        if ($ns !== '' && !str_ends_with((string) $ns, '\\') && $std === 'psr-4') {
                            $warn('error', "$sec psr-4 \"$ns\" must end with a backslash");
                        }
                        if ($ns === '') {
                            $warn('warn', "$sec $std maps the global namespace (\"\") to $path — this slows autoloading; prefer a vendor namespace");
                        }
                        if (!$m['exists']) {
                            $warn('error', "$sec $std \"$ns\" → $path: folder does not exist");
                            $mappings[] = $m;
                            continue;
                        }
                        foreach ($this->phpFiles($dir, $root, $json) as $file) {
                            $m['files']++;
                            $rel = substr($file, strlen($dir) + 1);
                            foreach (self::classesIn($file) as $fqcn) {
                                $m['classes']++;
                                $expected = $std === 'psr-4'
                                    ? (string) $ns . str_replace('/', '\\', substr($rel, 0, -4))
                                    : str_replace('/', '\\', substr($rel, 0, -4));
                                $short = substr($fqcn, (int) strrpos('\\' . $fqcn, '\\'));
                                $ok = strcasecmp($fqcn, $expected) === 0 || ($std === 'psr-0' && strcasecmp(str_replace('_', '\\', $fqcn), $expected) === 0);
                                $classes[] = ['class' => $fqcn, 'file' => substr($file, strlen($root) + 1), 'section' => $sec, 'type' => $std, 'ok' => $ok];
                                if (!$ok) {
                                    $m['mismatches']++;
                                    $warn('error', "$fqcn in " . substr($file, strlen($root) + 1) . " won't autoload — $std expects $expected");
                                } elseif ($fqcn !== $expected && $std === 'psr-4') {
                                    $warn('warn', "$fqcn vs file " . substr($file, strlen($root) + 1) . ': letter case differs (breaks on case-sensitive Linux servers)');
                                }
                                unset($short);
                            }
                        }
                        $mappings[] = $m;
                    }
                }
            }
            foreach ($al['classmap'] ?? [] as $path) {
                $full = $root . '/' . $path;
                $m = ['section' => $sec, 'type' => 'classmap', 'namespace' => '', 'path' => $path, 'exists' => file_exists($full), 'files' => 0, 'classes' => 0, 'mismatches' => 0];
                if (!$m['exists']) {
                    $warn('error', "$sec classmap path $path does not exist");
                } else {
                    foreach (is_dir($full) ? $this->phpFiles($full, $root, $json) : [$full] as $file) {
                        $m['files']++;
                        foreach (self::classesIn($file) as $fqcn) {
                            $m['classes']++;
                            $classes[] = ['class' => $fqcn, 'file' => substr($file, strlen($root) + 1), 'section' => $sec, 'type' => 'classmap', 'ok' => true];
                        }
                    }
                }
                $mappings[] = $m;
            }
            foreach ($al['files'] ?? [] as $f) {
                $exists = is_file($root . '/' . $f);
                $mappings[] = ['section' => $sec, 'type' => 'files', 'namespace' => '', 'path' => $f, 'exists' => $exists, 'files' => (int) $exists, 'classes' => 0, 'mismatches' => 0];
                if (!$exists) {
                    $warn('error', "$sec files entry $f does not exist — every request will fatal");
                } elseif (self::classesIn($root . '/' . $f)) {
                    $warn('warn', "$sec files entry $f declares classes — put classes under psr-4 instead (files are loaded on every request)");
                }
            }
        }
        // duplicates
        $seen = [];
        foreach ($classes as $c) {
            $k = strtolower($c['class']);
            if (isset($seen[$k]) && $seen[$k] !== $c['file']) {
                $warn('error', "duplicate class {$c['class']} in {$seen[$k]} and {$c['file']}");
            }
            $seen[$k] ??= $c['file'];
        }
        if (!$mappings) {
            $warn('warn', 'No autoload configuration found');
        }
        $errors = count(array_filter($issues, static fn ($i) => $i['level'] === 'error'));

        $this->out(['root' => $root, 'mappings' => $mappings, 'classes' => count($classes), 'class_list' => $this->a->has('list') ? $classes : null, 'issues' => $issues], function (array $d) use ($classes, $json) {
            Ui::title('Autoload analysis', $json['name'] ?? $d['root']);
            Ui::out();
            $t = [];
            foreach ($d['mappings'] as $m) {
                $state = !$m['exists'] ? Ui::c('red', 'missing') : ($m['mismatches'] ? Ui::c('red', $m['mismatches'] . ' broken') : Ui::c('green', 'ok'));
                $t[] = [Ui::c('gray', $m['section']), $m['type'], Ui::c('magenta', $m['namespace'] ?: '—'), $m['path'], (string) $m['files'], (string) $m['classes'], $state];
            }
            Ui::table(['Section', 'Type', 'Namespace', 'Path', 'Files', 'Classes', 'Status'], $t, [4 => true, 5 => true]);
            if ($this->a->has('list')) {
                Ui::section('Classes (' . count($classes) . ')');
                foreach ($classes as $c) {
                    Ui::out('  ' . ($c['ok'] ? Ui::c('green', '✔') : Ui::c('red', '✖')) . ' ' . Ui::c('cyan', $c['class']) . Ui::c('gray', '  ' . $c['file']));
                }
            }
            Ui::section('Findings');
            if (!$d['issues']) {
                Ui::out(Ui::status('ok', 'All ' . $d['classes'] . ' classes map cleanly to their files'));
            }
            foreach ($d['issues'] as $i) {
                Ui::out(Ui::status($i['level'] === 'error' ? 'fail' : ($i['level'] === 'warn' ? 'warn' : 'info'), $i['message']));
            }
            Ui::out();
            Ui::out(Ui::c('gray', '  Production tip: composer dump-autoload --optimize --classmap-authoritative'));
        });
        return $errors ? 1 : 0;
    }

    /** @return string[] */
    private function phpFiles(string $dir, string $root, array $json): array
    {
        $exclude = array_map(static fn ($e) => rtrim($root . '/' . ltrim($e, '/'), '/'), $json['autoload']['exclude-from-classmap'] ?? []);
        $vendor = rtrim($root . '/' . ($json['config']['vendor-dir'] ?? 'vendor'), '/');
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $f) use ($vendor, $exclude): bool {
                $p = str_replace('\\', '/', $f->getPathname());
                if ($f->isDir()) {
                    return $p !== $vendor && !in_array($f->getFilename(), ['node_modules', '.git'], true) && !in_array($p, $exclude, true);
                }
                return str_ends_with($p, '.php') && !in_array($p, $exclude, true);
            }
        ));
        foreach ($it as $f) {
            $out[] = str_replace('\\', '/', $f->getPathname());
        }
        sort($out);
        return $out;
    }

    /** Declared classes/interfaces/traits/enums in a PHP file, as FQCNs (uses the tokenizer, no code is executed). */
    public static function classesIn(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false || !str_contains($code, '<?')) {
            return [];
        }
        try {
            $tokens = token_get_all($code);
        } catch (\Throwable) {
            return [];
        }
        $ns = '';
        $out = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                $ns = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $tj = $tokens[$j];
                    if (is_array($tj) && in_array($tj[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $ns .= $tj[1];
                    } elseif ($tj === ';' || $tj === '{') {
                        break;
                    }
                }
                $ns = trim($ns, '\\');
            }
            if (in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                // skip ::class, anonymous classes and "new class"
                $prev = $i - 1;
                while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                    $prev--;
                }
                if ($prev >= 0 && is_array($tokens[$prev]) && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue;
                }
                for ($j = $i + 1; $j < $count; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $out[] = ($ns !== '' ? $ns . '\\' : '') . $tokens[$j][1];
                        break;
                    }
                    if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_WHITESPACE) {
                        break;
                    }
                }
            }
        }
        return $out;
    }
}

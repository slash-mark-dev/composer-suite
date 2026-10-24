<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

abstract class Command
{
    /** command name, e.g. "search" */
    public const NAME = '';
    /** tool title shown on the website, e.g. "Packagist Package Search" */
    public const TITLE = '';
    public const SUMMARY = '';
    public const CATEGORY = '';
    /** @var string[] */
    public const ALIASES = [];
    public const USAGE = '';
    /** @var string[] */
    public const EXAMPLES = [];
    /** @var array<string, string> option => 'value'|'flag'|'multi' */
    public const OPTIONS = [];
    /** @var array<string, string> short letter => long name */
    public const SHORT = [];
    /** @var array<string, string> option help lines */
    public const HELP = [];

    protected Args $a;
    protected bool $json = false;
    protected string $dir = '.';

    public function __construct(Args $args)
    {
        $this->a = $args;
        $this->json = $args->has('json');
        $this->dir = $args->get('working-dir') ?? '.';
    }

    abstract public function run(): int;

    protected function project(bool $needJson = true): Project
    {
        $f = $this->a->get('file');
        if ($f !== null) {
            // allow pointing straight at a composer.json / composer.lock file
            $dir = is_dir($f) ? $f : dirname($f);
            return Project::load($dir, $needJson);
        }
        return Project::load($this->dir, $needJson);
    }

    protected function need(int $i, string $what): string
    {
        $v = $this->a->arg($i);
        if ($v === null || $v === '') {
            throw new UsageException("Missing $what");
        }
        return $v;
    }

    protected function pkg(int $i = 0): string
    {
        return Packagist::assertName($this->need($i, 'package name (vendor/package)'));
    }

    /** "vendor/pkg:1.2.3" or "vendor/pkg@1.2.3" => [name, version|null] */
    protected function pkgWithVersion(string $spec): array
    {
        if (preg_match('#^([^:@\s]+/[^:@\s]+)[:@](.+)$#', $spec, $m)) {
            return [Packagist::assertName($m[1]), $m[2]];
        }
        return [Packagist::assertName($spec), null];
    }

    protected function out(mixed $data, callable $render): int
    {
        if ($this->json) {
            Ui::json($data);
        } else {
            $render($data);
        }
        return 0;
    }

    public static function helpText(): string
    {
        $s = Ui::c('bold.cyan', static::TITLE) . Ui::c('gray', '  (' . static::NAME . ')') . "\n" . static::SUMMARY . "\n\n";
        $s .= Ui::c('bold', 'USAGE') . "\n  sm-composer " . static::NAME . ' ' . static::USAGE . "\n";
        if (static::ALIASES) {
            $s .= Ui::c('gray', '  aliases: ' . implode(', ', static::ALIASES)) . "\n";
        }
        if (static::HELP) {
            $s .= "\n" . Ui::c('bold', 'OPTIONS') . "\n";
            $w = max(array_map('strlen', array_keys(static::HELP)));
            foreach (static::HELP as $k => $v) {
                $s .= '  ' . str_pad($k, $w) . '  ' . $v . "\n";
            }
        }
        $s .= "\n" . Ui::c('bold', 'GLOBAL') . "\n  --json  machine-readable output   --no-color   --no-cache   -d, --working-dir <dir>   -q, --quiet\n";
        if (static::EXAMPLES) {
            $s .= "\n" . Ui::c('bold', 'EXAMPLES') . "\n";
            foreach (static::EXAMPLES as $e) {
                $s .= '  ' . Ui::c('gray', '$ ') . $e . "\n";
            }
        }
        return $s;
    }
}

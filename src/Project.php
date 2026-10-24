<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/** A local Composer project: composer.json, composer.lock and vendor/. */
final class Project
{
    public array $json = [];
    public ?array $lock = null;

    public function __construct(public readonly string $dir, public readonly string $jsonFile = 'composer.json')
    {
    }

    public static function load(string $dir, bool $needJson = true): self
    {
        $dir = rtrim($dir, '/\\') ?: '.';
        $p = new self($dir);
        $jf = $dir . '/composer.json';
        if (is_file($jf)) {
            $p->json = self::readJson($jf);
        } elseif ($needJson) {
            throw new \RuntimeException("No composer.json found in " . realpath($dir) . ' (use -d <dir> to point at your project)');
        }
        $lf = $dir . '/composer.lock';
        if (is_file($lf)) {
            $p->lock = self::readJson($lf);
        }
        return $p;
    }

    public static function readJson(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $d = json_decode($raw, true);
        if (!is_array($d)) {
            $msg = json_last_error_msg();
            $line = self::errorLine($raw);
            throw new \RuntimeException(basename($file) . " is not valid JSON: $msg" . ($line ? " (near line $line)" : ''));
        }
        return $d;
    }

    /** Best-effort location of a JSON syntax error. */
    public static function errorLine(string $raw): ?int
    {
        $lines = explode("\n", $raw);
        $acc = '';
        foreach ($lines as $i => $l) {
            $acc .= $l . "\n";
            // a trailing comma before a closing bracket is the most common mistake
            if (preg_match('/,\s*$/', $l) && isset($lines[$i + 1]) && preg_match('/^\s*[}\]]/', $lines[$i + 1])) {
                return $i + 1;
            }
        }
        return null;
    }

    public function hasLock(): bool
    {
        return $this->lock !== null;
    }

    public function requireLock(): void
    {
        if (!$this->lock) {
            throw new \RuntimeException('No composer.lock found. Run `composer install` or `composer update --lock` first.');
        }
    }

    /** @return array<string,string> */
    public function requires(bool $dev = true, bool $platform = false): array
    {
        $r = $this->json['require'] ?? [];
        if ($dev) {
            $r += $this->json['require-dev'] ?? [];
        }
        if (!$platform) {
            $r = array_filter($r, static fn ($k) => !Packagist::isPlatform((string) $k), ARRAY_FILTER_USE_KEY);
        }
        return $r;
    }

    public function isDevRequirement(string $name): bool
    {
        return isset($this->json['require-dev'][$name]) && !isset($this->json['require'][$name]);
    }

    /** @return array<string, array> lowercase name => locked package (with 'dev' flag) */
    public function locked(bool $includeDev = true): array
    {
        $out = [];
        foreach ($this->lock['packages'] ?? [] as $p) {
            $out[strtolower($p['name'])] = $p + ['dev' => false];
        }
        if ($includeDev) {
            foreach ($this->lock['packages-dev'] ?? [] as $p) {
                $out[strtolower($p['name'])] = $p + ['dev' => true];
            }
        }
        ksort($out);
        return $out;
    }

    public function lockedVersion(string $name): ?string
    {
        return $this->locked()[strtolower($name)]['version'] ?? null;
    }

    /** Packages from vendor/composer/installed.json (what is actually on disk). */
    public function installed(): array
    {
        $f = $this->dir . '/' . $this->vendorDir() . '/composer/installed.json';
        if (!is_file($f)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($f), true) ?: [];
        $list = $d['packages'] ?? $d; // composer 2 vs 1 format
        $out = [];
        foreach ($list as $p) {
            if (isset($p['name'])) {
                $out[strtolower($p['name'])] = $p;
            }
        }
        return $out;
    }

    public function vendorDir(): string
    {
        return $this->json['config']['vendor-dir'] ?? 'vendor';
    }

    /** Composer's own content-hash algorithm (Composer\Package\Locker::getContentHash). */
    public function contentHash(): string
    {
        $keys = ['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'];
        $rel = [];
        foreach (array_intersect($keys, array_keys($this->json)) as $k) {
            $rel[$k] = $this->json[$k];
        }
        if (isset($this->json['config']['platform'])) {
            $rel['config']['platform'] = $this->json['config']['platform'];
        }
        ksort($rel);
        return md5((string) json_encode($rel, 0));
    }

    public function lockIsFresh(): ?bool
    {
        if (!$this->lock || !isset($this->lock['content-hash'])) {
            return null;
        }
        return $this->lock['content-hash'] === $this->contentHash();
    }

    /** Detect which PHP version the project targets: config.platform.php, else require.php lower bound. */
    public function targetPhp(): ?string
    {
        if (isset($this->json['config']['platform']['php'])) {
            return (string) $this->json['config']['platform']['php'];
        }
        $c = $this->json['require']['php'] ?? null;
        if ($c && preg_match('/(\d+\.\d+(?:\.\d+)?)/', (string) $c, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Recursively compute the size of a directory in bytes. */
    public static function dirSize(string $dir, ?int &$files = null): int
    {
        $files = 0;
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $size += $f->getSize();
                $files++;
            }
        }
        return $size;
    }
}

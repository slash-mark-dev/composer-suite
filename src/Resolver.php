<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/**
 * Lightweight dependency resolver for remote packages: for every requirement it picks the newest stable
 * version that satisfies the constraint (what `composer require` would usually pick on a fresh project).
 * It does not backtrack like Composer's SAT solver; it is meant for inspection, not installation.
 */
final class Resolver
{
    /** @var array<string, array{version:string, require:array, constraint:string, by:string}> */
    public array $resolved = [];
    /** @var array<int, array{package:string, constraint:string, by:string, reason:string}> */
    public array $problems = [];

    public function __construct(private readonly bool $includeDev = false, private readonly int $maxDepth = 12)
    {
    }

    /** @param array<string,string> $requires */
    public function resolve(array $requires, string $root = 'root'): self
    {
        $queue = [];
        foreach ($requires as $n => $c) {
            $queue[] = [strtolower($n), (string) $c, $root, 0];
        }
        while ($queue) {
            // fetch the whole frontier in parallel
            $names = array_values(array_unique(array_filter(array_map(static fn ($q) => $q[0], $queue), fn ($n) => !Packagist::isPlatform($n) && !isset($this->resolved[$n]))));
            if ($names) {
                Packagist::versionsMany($names);
            }
            $next = [];
            foreach ($queue as [$name, $constraint, $by, $depth]) {
                if (Packagist::isPlatform($name) || $depth > $this->maxDepth) {
                    continue;
                }
                if (isset($this->resolved[$name])) {
                    $have = $this->resolved[$name]['version'];
                    if (Semver::isValid($constraint) && !Semver::satisfies($have, $constraint)) {
                        $this->problems[] = ['package' => $name, 'constraint' => $constraint, 'by' => $by, 'reason' => "$by needs $constraint but $have was selected for " . $this->resolved[$name]['by']];
                    }
                    continue;
                }
                try {
                    $versions = Packagist::versions($name);
                } catch (HttpException) {
                    $this->problems[] = ['package' => $name, 'constraint' => $constraint, 'by' => $by, 'reason' => 'not found on Packagist'];
                    continue;
                }
                $pick = null;
                foreach ([true, false] as $stableOnly) {
                    foreach ($versions as $v) {
                        if ($stableOnly && !Semver::isStable($v['version'])) {
                            continue;
                        }
                        try {
                            if (Semver::satisfies($v['version'], $constraint)) {
                                $pick = $v;
                                break 2;
                            }
                        } catch (\InvalidArgumentException) {
                            break 2;
                        }
                    }
                }
                if (!$pick) {
                    $this->problems[] = ['package' => $name, 'constraint' => $constraint, 'by' => $by, 'reason' => "no release matches $constraint"];
                    continue;
                }
                $req = $pick['require'] ?? [];
                if ($this->includeDev && $by === 'root') {
                    $req += $pick['require-dev'] ?? [];
                }
                $this->resolved[$name] = ['version' => $pick['version'], 'require' => $req, 'constraint' => $constraint, 'by' => $by, 'time' => $pick['time'] ?? null, 'license' => $pick['license'] ?? [], 'dist' => $pick['dist'] ?? null];
                foreach ($req as $dn => $dc) {
                    // packages a dependency "replaces" (e.g. polyfills) are skipped by Composer too
                    $next[] = [strtolower($dn), (string) $dc, $name, $depth + 1];
                }
            }
            $queue = $next;
        }
        return $this;
    }

    /** Build a nested tree from the resolved set. */
    public function tree(array $requires, int $maxDepth = 10, bool $platform = false): array
    {
        $build = function (string $name, string $constraint, array $path, int $depth) use (&$build, $maxDepth, $platform): ?array {
            $l = strtolower($name);
            if (Packagist::isPlatform($l)) {
                return $platform ? ['name' => $name, 'constraint' => $constraint, 'version' => null, 'platform' => true, 'children' => []] : null;
            }
            $node = $this->resolved[$l] ?? null;
            $out = ['name' => $name, 'constraint' => $constraint, 'version' => $node['version'] ?? null, 'children' => []];
            if (in_array($l, $path, true)) {
                $out['circular'] = true;
                return $out;
            }
            if ($node && $depth < $maxDepth) {
                foreach ($node['require'] as $dn => $dc) {
                    if ($c = $build($dn, (string) $dc, [...$path, $l], $depth + 1)) {
                        $out['children'][] = $c;
                    }
                }
            }
            return $out;
        };
        $nodes = [];
        foreach ($requires as $n => $c) {
            if ($x = $build($n, (string) $c, [], 0)) {
                $nodes[] = $x;
            }
        }
        return $nodes;
    }
}

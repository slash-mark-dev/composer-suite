<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/**
 * Composer-compatible version normalisation and constraint matching
 * (^, ~, *, x, hyphen ranges, comparison operators, AND via space/comma, OR via ||, stability flags).
 */
final class Semver
{
    private const MOD = '[._-]?(?:(stable|beta|b|RC|rc|alpha|a|patch|pl|p)((?:[.-]?\d+)*+)?)?([.-]?dev)?';

    /** Normalise a version to Composer's 4-part form (e.g. v1.2-beta3 => 1.2.0.0-beta3). Returns null if invalid. */
    public static function normalize(string $version): ?string
    {
        $v = trim($version);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^([^,\s]+) +as +[^,\s]+$/', $v, $m)) {
            $v = $m[1];
        }
        $v = (string) preg_replace('/(?:@(?:stable|RC|beta|alpha|dev)|#.+)$/i', '', $v);
        if (preg_match('/^(?:dev-)?(?:master|trunk|default)$/i', $v)) {
            return 'dev-' . strtolower(preg_replace('/^dev-/i', '', $v));
        }
        if (stripos($v, 'dev-') === 0) {
            return 'dev-' . substr($v, 4);
        }
        // branch alias like 2.x-dev / 2.1.x-dev
        if (preg_match('/^v?(\d+)(?:\.(\d+|[xX*]))?(?:\.(\d+|[xX*]))?(?:\.(\d+|[xX*]))?[.-]?dev$/', $v, $m)
            && preg_match('/[xX*]/', $v)) {
            $parts = [];
            for ($i = 1; $i <= 4; $i++) {
                $p = $m[$i] ?? '';
                $parts[] = ($p === '' || in_array($p, ['x', 'X', '*'], true)) ? '9999999' : $p;
            }
            return implode('.', $parts) . '-dev';
        }
        if (preg_match('/^v?(\d{1,5})(\.\d++)?(\.\d++)?(\.\d++)?' . self::MOD . '$/i', $v, $m)) {
            $out = $m[1] . (($m[2] ?? '') !== '' ? $m[2] : '.0') . (($m[3] ?? '') !== '' ? $m[3] : '.0') . (($m[4] ?? '') !== '' ? $m[4] : '.0');
            return $out . self::modifier($m[5] ?? '', $m[6] ?? '', $m[7] ?? '');
        }
        // date-based versions, e.g. 2024.01.15 / 20240115
        if (preg_match('/^v?(\d{4}(?:[.:-]?\d{2}){1,6}(?:[.:-]?\d{1,3})?)' . self::MOD . '$/i', $v, $m)) {
            return preg_replace('/\D/', '.', $m[1]) . self::modifier($m[2] ?? '', $m[3] ?? '', $m[4] ?? '');
        }
        return null;
    }

    private static function modifier(string $mod, string $num, string $dev): string
    {
        $out = '';
        if ($mod !== '' && strtolower($mod) !== 'stable') {
            $map = ['a' => 'alpha', 'alpha' => 'alpha', 'b' => 'beta', 'beta' => 'beta', 'rc' => 'RC', 'p' => 'pl', 'pl' => 'pl', 'patch' => 'pl'];
            $out .= '-' . $map[strtolower($mod)] . ltrim($num, '.-');
        }
        if ($dev !== '') {
            $out .= '-dev';
        }
        return $out;
    }

    public static function isDevBranch(string $normalized): bool
    {
        return str_starts_with($normalized, 'dev-');
    }

    public static function stability(string $version): string
    {
        $n = self::normalize($version) ?? $version;
        if (self::isDevBranch($n) || str_ends_with($n, '-dev')) {
            return 'dev';
        }
        if (preg_match('/-(alpha|beta|RC)\d*/', $n, $m)) {
            return strtolower($m[1]) === 'rc' ? 'RC' : $m[1];
        }
        return 'stable';
    }

    public static function isStable(string $version): bool
    {
        return self::stability($version) === 'stable';
    }

    public static function compare(string $a, string $b): int
    {
        $na = self::normalize($a) ?? $a;
        $nb = self::normalize($b) ?? $b;
        $da = self::isDevBranch($na);
        $db = self::isDevBranch($nb);
        if ($da || $db) {
            return $da === $db ? strcmp($na, $nb) : ($da ? -1 : 1);
        }
        return version_compare($na, $nb);
    }

    /** @param string[] $versions @return string[] newest first */
    public static function rsort(array $versions): array
    {
        usort($versions, static fn ($a, $b) => self::compare($b, $a));
        return $versions;
    }

    /** @param string[] $versions */
    public static function latest(array $versions, bool $stableOnly = true): ?string
    {
        $best = null;
        foreach ($versions as $v) {
            if (self::normalize($v) === null || self::isDevBranch(self::normalize($v))) {
                continue;
            }
            if ($stableOnly && !self::isStable($v)) {
                continue;
            }
            if ($best === null || self::compare($v, $best) > 0) {
                $best = $v;
            }
        }
        return $best ?? ($stableOnly ? self::latest($versions, false) : null);
    }

    /** major | minor | patch | same | downgrade | unknown */
    public static function diff(string $from, string $to): string
    {
        $a = self::normalize($from);
        $b = self::normalize($to);
        if ($a === null || $b === null || self::isDevBranch($a) || self::isDevBranch($b)) {
            return 'unknown';
        }
        $c = version_compare($a, $b);
        if ($c === 0) {
            return 'same';
        }
        if ($c > 0) {
            return 'downgrade';
        }
        $pa = array_map('intval', explode('.', explode('-', $a)[0]));
        $pb = array_map('intval', explode('.', explode('-', $b)[0]));
        if ($pa[0] !== $pb[0]) {
            return 'major';
        }
        // for 0.x, a minor bump is breaking under caret semantics
        if ($pa[1] !== $pb[1]) {
            return $pa[0] === 0 ? 'major' : 'minor';
        }
        return 'patch';
    }

    /** Suggest a caret constraint for a version: 2.5.1 => ^2.5, 0.4.2 => ^0.4.2 */
    public static function suggest(string $version): string
    {
        $n = self::normalize($version);
        if ($n === null || self::isDevBranch($n)) {
            return $version;
        }
        $p = array_map('intval', explode('.', explode('-', $n)[0]));
        $s = self::stability($version);
        $suffix = $s === 'stable' ? '' : '@' . $s;
        if ($p[0] === 0) {
            return '^0.' . $p[1] . '.' . $p[2] . $suffix;
        }
        return '^' . $p[0] . '.' . $p[1] . $suffix;
    }

    /**
     * Parse a constraint into OR-groups of AND-lists of [op, normalizedVersion].
     * @return array<int, array<int, array{0: string, 1: string}>>
     * @throws \InvalidArgumentException
     */
    public static function parse(string $constraint): array
    {
        $c = trim($constraint);
        $c = (string) preg_replace('/@(stable|RC|beta|alpha|dev)\b/i', '', $c);
        $c = (string) preg_replace('/#\S+/', '', $c);
        if (preg_match('/^([^,\s|]+) +as +[^,\s|]+$/', $c, $m)) {
            $c = $m[1];
        }
        $c = trim($c);
        if ($c === '') {
            throw new \InvalidArgumentException('Empty constraint');
        }
        $groups = [];
        foreach (preg_split('/\s*\|\|?\s*/', $c) as $or) {
            $or = trim($or);
            if ($or === '') {
                throw new \InvalidArgumentException("Invalid constraint \"$constraint\"");
            }
            $and = [];
            if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $or, $m)) {
                $and = self::hyphen($m[1], $m[2], $constraint);
            } else {
                $or = (string) preg_replace('/(>=|<=|<>|!=|==|=|>|<|\^|~)\s+/', '$1', $or);
                foreach (preg_split('/(?<!^|[<>=!~^])[\s,]+(?!$)/', $or) as $single) {
                    $single = trim($single, " ,");
                    if ($single === '') {
                        continue;
                    }
                    foreach (self::single($single, $constraint) as $r) {
                        $and[] = $r;
                    }
                }
            }
            $groups[] = $and;
        }
        return $groups;
    }

    /** @return array<int, array{0:string,1:string}> */
    private static function single(string $s, string $orig): array
    {
        if (in_array($s, ['*', 'x', 'X', '*.*', 'x.x'], true) || preg_match('/^v?[xX*](\.[xX*])*$/', $s)) {
            return [['*', '']];
        }
        if (preg_match('/^(==|=)?(dev-\S+)$/i', $s, $m) || preg_match('/^(==|=)?((?:master|trunk|default))$/i', $s, $m)) {
            return [['==', self::normalize($m[2]) ?? $m[2]]];
        }
        // caret
        if (preg_match('/^\^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(.*)$/', $s, $m)) {
            $parts = self::partsOf($m);
            $low = self::normalize(ltrim(substr($s, 1), 'v')) ?? throw self::bad($orig);
            $pos = 0;
            for ($i = 0; $i < count($parts); $i++) {
                $pos = $i;
                if ($parts[$i] !== 0 || $i === count($parts) - 1) {
                    break;
                }
            }
            if (count($parts) === 1) {
                $pos = 0;
            }
            return [['>=', self::devLower($low, $m[5] ?? '')], ['<', self::bump($parts, $pos)]];
        }
        // tilde
        if (preg_match('/^~v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(.*)$/', $s, $m)) {
            $parts = self::partsOf($m);
            $low = self::normalize(ltrim(substr($s, 1), 'v')) ?? throw self::bad($orig);
            $pos = max(0, count($parts) - 2);
            return [['>=', self::devLower($low, $m[5] ?? '')], ['<', self::bump($parts, $pos)]];
        }
        // wildcard 1.2.* / 1.x
        if (preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?\.[xX*]$/', $s, $m)) {
            $parts = self::partsOf($m);
            $low = implode('.', array_pad($parts, 4, 0)) . '-dev';
            return [['>=', $low], ['<', self::bump($parts, count($parts) - 1)]];
        }
        if (preg_match('/^(<>|!=|>=?|<=?|==?)?\s*(.+)$/', $s, $m)) {
            $op = $m[1] === '' ? '==' : ($m[1] === '=' ? '==' : ($m[1] === '<>' ? '!=' : $m[1]));
            $ver = $m[2];
            $n = self::normalize($ver) ?? throw self::bad($orig);
            $isStableNumeric = !self::isDevBranch($n) && !str_contains($n, '-');
            if ($isStableNumeric && ($op === '>=' || $op === '<')) {
                $n .= '-dev'; // Composer semantics: >=1.0 includes 1.0 pre-releases, <2.0 excludes 2.0 pre-releases
            }
            return [[$op, $n]];
        }
        throw self::bad($orig);
    }

    /** @return array<int, array{0:string,1:string}> */
    private static function hyphen(string $from, string $to, string $orig): array
    {
        $low = self::normalize($from) ?? throw self::bad($orig);
        if (!str_contains($low, '-')) {
            $low .= '-dev';
        }
        if (!preg_match('/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(.*)$/', $to, $m)) {
            throw self::bad($orig);
        }
        $parts = self::partsOf($m);
        if (count($parts) >= 3 || ($m[5] ?? '') !== '') {
            return [['>=', $low], ['<=', self::normalize($to) ?? throw self::bad($orig)]];
        }
        return [['>=', $low], ['<', self::bump($parts, count($parts) - 1)]];
    }

    private static function partsOf(array $m): array
    {
        $parts = [];
        for ($i = 1; $i <= 4; $i++) {
            if (isset($m[$i]) && $m[$i] !== '') {
                $parts[] = (int) $m[$i];
            } else {
                break;
            }
        }
        return $parts;
    }

    private static function devLower(string $low, string $suffix): string
    {
        return (trim($suffix) === '' && !str_contains($low, '-')) ? $low . '-dev' : $low;
    }

    private static function bump(array $parts, int $pos): string
    {
        $p = array_pad($parts, 4, 0);
        $p[$pos]++;
        for ($i = $pos + 1; $i < 4; $i++) {
            $p[$i] = 0;
        }
        return implode('.', $p) . '-dev';
    }

    private static function bad(string $c): \InvalidArgumentException
    {
        return new \InvalidArgumentException("Could not parse version constraint \"$c\"");
    }

    public static function isValid(string $constraint): bool
    {
        try {
            self::parse($constraint);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function satisfies(string $version, string $constraint): bool
    {
        return self::matchesParsed($version, self::parse($constraint));
    }

    public static function matchesParsed(string $version, array $groups): bool
    {
        $n = self::normalize($version);
        if ($n === null) {
            return false;
        }
        foreach ($groups as $and) {
            $ok = true;
            foreach ($and as [$op, $v]) {
                if ($op === '*') {
                    continue;
                }
                if (self::isDevBranch($n) || self::isDevBranch($v)) {
                    $res = match ($op) {
                        '==' => $n === $v,
                        '!=' => $n !== $v,
                        default => false,
                    };
                } else {
                    $res = version_compare($n, $v, $op === '==' ? '==' : $op);
                }
                if (!$res) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** Human-readable explanation, e.g. "^1.2" => ">=1.2.0 <2.0.0" */
    public static function explain(string $constraint): string
    {
        $out = [];
        foreach (self::parse($constraint) as $and) {
            $parts = [];
            foreach ($and as [$op, $v]) {
                $parts[] = $op === '*' ? 'any version' : $op . self::pretty($v);
            }
            $out[] = implode(' ', $parts);
        }
        return implode('  OR  ', $out);
    }

    public static function pretty(string $normalized): string
    {
        if (self::isDevBranch($normalized)) {
            return $normalized;
        }
        $dev = str_ends_with($normalized, '-dev');
        $core = preg_replace('/-dev$/', '', $normalized);
        [$num, $suffix] = array_pad(explode('-', (string) $core, 2), 2, '');
        $p = explode('.', $num);
        if (count($p) === 4 && $p[3] === '0') {
            array_pop($p);
        }
        $s = implode('.', $p) . ($suffix !== '' ? '-' . $suffix : '');
        // a trailing -dev on a bound is an internal detail of Composer semantics; hide it unless it's a pre-release
        return ($dev && $suffix !== '') ? $s . '-dev' : $s;
    }

    /** Do two constraints have any overlap? Tested against a set of candidate versions. */
    public static function intersects(string $a, string $b, array $candidates): ?string
    {
        foreach (self::rsort($candidates) as $v) {
            if (self::satisfies($v, $a) && self::satisfies($v, $b)) {
                return $v;
            }
        }
        return null;
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/** Package health scoring (0–100) from maintenance, popularity, community, security and hygiene signals. */
final class Health
{
    public const CURRENT_PHP = '8.4.0';

    /**
     * @param array $info      packagist.org/packages/{name}.json "package"
     * @param array $versions  expanded p2 versions (newest first)
     * @param array $advisories advisories for this package
     */
    public static function score(array $info, array $versions, array $advisories = []): array
    {
        $latestV = Semver::latest(array_column($versions, 'version'));
        $latest = null;
        foreach ($versions as $v) {
            if ($v['version'] === $latestV) {
                $latest = $v;
                break;
            }
        }
        $newestTime = null;
        foreach ($versions as $v) {
            if (!empty($v['time']) && ($newestTime === null || strtotime($v['time']) > strtotime($newestTime))) {
                $newestTime = $v['time'];
            }
        }
        $days = Ui::days($newestTime);
        $recentReleases = count(array_filter($versions, static fn ($v) => ($d = Ui::days($v['time'] ?? null)) !== null && $d <= 365));
        $monthly = (int) ($info['downloads']['monthly'] ?? 0);
        $stars = (int) ($info['favers'] ?? $info['github_stars'] ?? 0);
        $dependents = (int) ($info['dependents'] ?? 0);
        $issues = $info['github_open_issues'] ?? null;
        $affecting = array_values(array_filter($advisories, static fn ($a) => $latestV && Semver::isValid((string) ($a['affectedVersions'] ?? '')) && Semver::satisfies($latestV, (string) $a['affectedVersions'])));

        $f = [];
        $f['maintenance'] = [
            'points' => match (true) { $days === null => 0, $days <= 90 => 25, $days <= 180 => 20, $days <= 365 => 14, $days <= 730 => 6, default => 0 },
            'max' => 25,
            'detail' => $days === null ? 'no release dates' : 'last release ' . Ui::ago($newestTime),
        ];
        $f['cadence'] = [
            'points' => match (true) { $recentReleases >= 6 => 10, $recentReleases >= 3 => 7, $recentReleases >= 1 => 4, default => 0 },
            'max' => 10,
            'detail' => $recentReleases . ' releases in the last 12 months',
        ];
        $f['popularity'] = ['points' => (int) min(20, round(log10($monthly + 1) * 20 / 7)), 'max' => 20, 'detail' => Ui::num($monthly) . ' downloads/month'];
        $f['community'] = ['points' => (int) min(10, round(log10($stars + 1) * 10 / 4.3)), 'max' => 10, 'detail' => '★ ' . Ui::num($stars)];
        $f['adoption'] = ['points' => (int) min(10, round(log10($dependents + 1) * 10 / 4)), 'max' => 10, 'detail' => Ui::num($dependents) . ' dependent packages'];
        $major = $latestV ? (int) explode('.', (string) Semver::normalize($latestV))[0] : 0;
        $f['stability'] = ['points' => $latestV === null ? 0 : ($major >= 1 ? 5 : 2), 'max' => 5, 'detail' => $latestV ? ($major >= 1 ? "stable API ($latestV)" : "pre-1.0 ($latestV)") : 'no stable release'];
        $f['security'] = ['points' => $affecting ? 0 : 10, 'max' => 10, 'detail' => $affecting ? count($affecting) . ' advisory(ies) affect the latest release' : 'no known advisories on latest (' . count($advisories) . ' historical)'];
        $hyg = 0;
        $notes = [];
        if (!empty($latest['license'])) {
            $hyg += 4;
        } else {
            $notes[] = 'no license';
        }
        $php = $latest['require']['php'] ?? null;
        if ($php === null || (Semver::isValid($php) && Semver::satisfies(self::CURRENT_PHP, $php))) {
            $hyg += 3;
        } else {
            $notes[] = 'PHP ' . substr(self::CURRENT_PHP, 0, 3) . ' not allowed by "' . $php . '"';
        }
        if ($issues === null || $stars === 0 || $issues / max(1, $stars) < 0.02 || $issues < 30) {
            $hyg += 3;
        } else {
            $notes[] = $issues . ' open issues';
        }
        $f['hygiene'] = ['points' => $hyg, 'max' => 10, 'detail' => $notes ? implode(', ', $notes) : 'license, current PHP support, issue backlog OK'];

        $score = array_sum(array_column($f, 'points'));
        $abandoned = $info['abandoned'] ?? false;
        if ($abandoned) {
            $score = min($score, 20);
        }
        return [
            'score' => $score,
            'grade' => self::grade($score),
            'abandoned' => $abandoned,
            'latest' => $latestV,
            'factors' => $f,
        ];
    }

    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            $score >= 40 => 'D',
            default => 'F',
        };
    }

    public static function style(string $grade): string
    {
        return match ($grade) {
            'A' => 'bgGreen',
            'B' => 'bgBlue',
            'C' => 'bgYellow',
            'D' => 'bgMagenta',
            default => 'bgRed',
        };
    }
}

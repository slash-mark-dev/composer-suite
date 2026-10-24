<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\HttpException;
use SlashMark\ComposerSuite\Markdown;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Project;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class ChangelogCommand extends Command
{
    public const NAME = 'changelog';
    public const TITLE = 'Changelog Viewer';
    public const SUMMARY = 'Release notes between your version and the latest';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['changes', 'notes'];
    public const USAGE = '<vendor/package> [--from <ver>] [--to <ver>] [-n <limit>] [--pre]';
    public const OPTIONS = ['from' => 'value', 'to' => 'value', 'limit' => 'value', 'pre' => 'flag', 'source' => 'value'];
    public const SHORT = ['n' => 'limit'];
    public const HELP = [
        '--from <ver>' => 'Only releases after this version (default: your locked version, if any)',
        '--to <ver>' => 'Stop at this version',
        '-n, --limit <n>' => 'Max releases (default 10)',
        '--pre' => 'Include alpha/beta/RC releases',
        '--source <s>' => 'releases (GitHub Releases), file (CHANGELOG.md) or meta (Packagist only)',
    ];
    public const EXAMPLES = ['sm-composer changelog monolog/monolog', 'sm-composer changelog laravel/framework --from v11.0.0 -n 20', 'sm-composer changelog guzzlehttp/guzzle --source file'];

    public function run(): int
    {
        $name = $this->pkg();
        $info = Packagist::package($name);
        $from = $this->a->get('from');
        if ($from === null) {
            try {
                $from = Project::load($this->dir, false)->lockedVersion($name);
                if ($from) {
                    Ui::info("Showing changes since your locked version $from (override with --from)");
                }
            } catch (\Throwable) {
            }
        }
        $to = $this->a->get('to');
        $limit = max(1, $this->a->int('limit', 10));
        $want = $this->a->get('source');
        $inRange = function (string $v) use ($from, $to): bool {
            if (Semver::normalize($v) === null) {
                return false;
            }
            if (!$this->a->has('pre') && !Semver::isStable($v)) {
                return false;
            }
            if ($from && Semver::compare($v, $from) <= 0) {
                return false;
            }
            return !($to && Semver::compare($v, $to) > 0);
        };
        $gh = Packagist::github($info['repository'] ?? null);
        $entries = [];
        $source = 'meta';

        if ($gh && in_array($want, [null, 'releases'], true)) {
            try {
                $rel = Http::json(Packagist::githubApi() . "/repos/{$gh[0]}/{$gh[1]}/releases?per_page=100", 3600);
                foreach ($rel as $r) {
                    if (!empty($r['draft']) || !$inRange((string) $r['tag_name'])) {
                        continue;
                    }
                    $entries[] = ['version' => $r['tag_name'], 'date' => $r['published_at'] ?? null, 'notes' => trim((string) ($r['body'] ?? '')), 'url' => $r['html_url'] ?? null];
                }
                if ($entries) {
                    $source = 'github releases';
                }
            } catch (HttpException $e) {
                Ui::info('GitHub releases unavailable (' . $e->getMessage() . '), trying CHANGELOG file…');
            }
        }
        if (!$entries && $gh && in_array($want, [null, 'file'], true)) {
            foreach (['CHANGELOG.md', 'CHANGELOG', 'CHANGES.md', 'UPGRADE.md'] as $f) {
                try {
                    $md = Http::text(Packagist::githubRaw() . "/{$gh[0]}/{$gh[1]}/HEAD/$f", 3600);
                } catch (HttpException) {
                    continue;
                }
                foreach ($this->splitChangelog($md) as $v => [$date, $notes]) {
                    if ($inRange($v)) {
                        $entries[] = ['version' => $v, 'date' => $date, 'notes' => $notes, 'url' => "https://github.com/{$gh[0]}/{$gh[1]}/blob/HEAD/$f"];
                    }
                }
                if ($entries) {
                    $source = $f;
                    break;
                }
            }
        }
        if (!$entries) {
            // Packagist-only fallback: show what changed in requirements between releases
            $versions = array_values(array_filter(Packagist::versions($name), static fn ($v) => $inRange($v['version'])));
            $all = Packagist::versions($name);
            foreach ($versions as $v) {
                $prev = null;
                foreach ($all as $cand) {
                    if (Semver::compare($cand['version'], $v['version']) < 0 && Semver::isStable($cand['version'])) {
                        $prev = $cand;
                        break;
                    }
                }
                if ($prev === null) {
                    $entries[] = ['version' => $v['version'], 'date' => $v['time'] ?? null, 'notes' => '_Earliest release on record._', 'url' => null];
                    continue;
                }
                $lines = [];
                $a = $prev['require'] ?? [];
                $b = $v['require'] ?? [];
                foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $k) {
                    if (!isset($a[$k])) {
                        $lines[] = "* added requirement `$k {$b[$k]}`";
                    } elseif (!isset($b[$k])) {
                        $lines[] = "* dropped requirement `$k`";
                    } elseif ($a[$k] !== $b[$k]) {
                        $lines[] = "* `$k` {$a[$k]} → {$b[$k]}";
                    }
                }
                $entries[] = ['version' => $v['version'], 'date' => $v['time'] ?? null, 'notes' => $lines ? implode("\n", $lines) : '_No release notes published; requirements unchanged._', 'url' => null];
            }
            $source = 'packagist metadata (no release notes found)';
        }
        usort($entries, static fn ($x, $y) => Semver::compare($y['version'], $x['version']));
        $total = count($entries);
        $entries = array_slice($entries, 0, $limit);

        return $this->out(['package' => $name, 'from' => $from, 'source' => $source, 'total' => $total, 'releases' => $entries], function (array $d) {
            Ui::title("Changelog · {$d['package']}", $d['source'] . ($d['from'] ? ' · newer than ' . $d['from'] : ''));
            if (!$d['releases']) {
                Ui::out();
                Ui::out(Ui::status('ok', $d['from'] ? "You're on the latest release ({$d['from']})." : 'No releases found.'));
                return;
            }
            foreach ($d['releases'] as $r) {
                Ui::out();
                $major = $d['from'] && Semver::diff($d['from'], $r['version']) === 'major';
                Ui::out(Ui::c('bold.green', '■ ' . $r['version']) . Ui::c('gray', '  ' . Ui::date($r['date']) . ($r['url'] ? '  ' . $r['url'] : '')) . ($major ? '  ' . Ui::badge('MAJOR', 'bgRed') : ''));
                $notes = $r['notes'] !== '' ? $r['notes'] : '_No notes._';
                Ui::out(Markdown::render(self::shorten($notes, 40), min(100, Ui::termWidth() - 4)));
            }
            if ($d['total'] > count($d['releases'])) {
                Ui::out();
                Ui::out(Ui::c('gray', '  ' . ($d['total'] - count($d['releases'])) . ' more — use -n ' . $d['total']));
            }
        });
    }

    /** @return array<string, array{0: ?string, 1: string}> version => [date, notes] */
    private function splitChangelog(string $md): array
    {
        $out = [];
        $cur = null;
        $buf = [];
        $date = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $md)) as $line) {
            if (preg_match('/^#{1,3}\s*\[?v?(\d+\.\d+(?:\.\d+)?(?:-[\w.]+)?)\]?(?:.*?(\d{4}-\d{2}-\d{2}))?/', $line, $m)) {
                if ($cur) {
                    $out[$cur] = [$date, trim(implode("\n", $buf))];
                }
                [$cur, $date, $buf] = [$m[1], $m[2] ?? null, []];
                continue;
            }
            if ($cur) {
                $buf[] = $line;
            }
        }
        if ($cur) {
            $out[$cur] = [$date, trim(implode("\n", $buf))];
        }
        return $out;
    }

    private static function shorten(string $notes, int $maxLines): string
    {
        $lines = explode("\n", $notes);
        if (count($lines) <= $maxLines) {
            return $notes;
        }
        return implode("\n", array_slice($lines, 0, $maxLines)) . "\n\n_… " . (count($lines) - $maxLines) . ' more lines (use --json for the full text)_';
    }
}

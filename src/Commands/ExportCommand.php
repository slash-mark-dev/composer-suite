<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;

final class ExportCommand extends Command
{
    public const NAME = 'export';
    public const TITLE = 'Offline Package Exporter';
    public const SUMMARY = 'Export package metadata to JSON, CSV, Markdown, HTML or a CycloneDX SBOM';
    public const CATEGORY = 'Workflow';
    public const ALIASES = ['report', 'sbom'];
    public const USAGE = '[vendor/pkg …] [--format json|csv|md|html|cyclonedx] [-o <file>] [--offline] [--full]';
    public const OPTIONS = ['format' => 'value', 'output' => 'value', 'offline' => 'flag', 'full' => 'flag', 'no-dev' => 'flag'];
    public const SHORT = ['f' => 'format', 'o' => 'output'];
    public const HELP = [
        '(no package)' => 'Export everything in ./composer.lock',
        '-f, --format <f>' => 'json (default, complete bundle), csv, md, html (shareable report), cyclonedx (SBOM 1.5)',
        '-o, --output <file>' => 'Write to this file (default: composer-report.<ext>; "-" = stdout)',
        '--offline' => 'Use only local files — no network (lock data only)',
        '--full' => 'json: also embed every release of every package for offline browsing',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer export --format html', 'sm-composer export --format csv -o deps.csv', 'sm-composer export --format cyclonedx -o sbom.json', 'sm-composer export laravel/framework --full -o laravel.json'];

    public function run(): int
    {
        $fmt = strtolower($this->a->get('format', 'json'));
        $ext = ['json' => 'json', 'csv' => 'csv', 'md' => 'md', 'markdown' => 'md', 'html' => 'html', 'cyclonedx' => 'cdx.json', 'sbom' => 'cdx.json'][$fmt] ?? throw new \InvalidArgumentException("Unknown format \"$fmt\" (json, csv, md, html, cyclonedx)");
        $offline = $this->a->has('offline');
        $project = null;
        $rows = [];
        if ($this->a->pos) {
            if ($offline) {
                throw new \InvalidArgumentException('--offline needs a composer.lock (package names need the network)');
            }
            foreach ($this->a->pos as $spec) {
                [$n, $v] = $this->pkgWithVersion($spec);
                $row = $v ? Packagist::findVersion($n, $v) : Packagist::latest($n);
                if (!$row) {
                    throw new \InvalidArgumentException("Version $v of $n not found");
                }
                $rows[$n] = $row + ['dev' => false];
            }
        } else {
            $project = $this->project();
            $project->requireLock();
            $rows = $project->locked(!$this->a->has('no-dev'));
        }
        $names = array_keys($rows);
        $enrich = [];
        $adv = [];
        $info = [];
        $all = [];
        if (!$offline) {
            Ui::info('Enriching ' . count($names) . ' packages from Packagist…');
            $info = Packagist::packageMany($names);
            $all = Packagist::versionsMany($names);
            $adv = Packagist::advisories($names);
        }
        $packages = [];
        foreach ($rows as $n => $x) {
            $latest = $all[$n] ?? null ? Semver::latest(array_column($all[$n], 'version')) : null;
            $affecting = array_values(array_filter($adv[$n] ?? [], static fn ($a) => Semver::isValid((string) $a['affectedVersions']) && Semver::satisfies((string) $x['version'], (string) $a['affectedVersions'])));
            $pkg = [
                'name' => $x['name'] ?? $n,
                'version' => $x['version'],
                'dev' => $x['dev'] ?? false,
                'type' => $x['type'] ?? 'library',
                'description' => $x['description'] ?? ($info[$n]['description'] ?? ''),
                'license' => (array) ($x['license'] ?? []),
                'homepage' => $x['homepage'] ?? null,
                'source' => $x['source']['url'] ?? null,
                'reference' => $x['source']['reference'] ?? $x['dist']['reference'] ?? null,
                'dist' => $x['dist']['url'] ?? null,
                'shasum' => $x['dist']['shasum'] ?? null,
                'released' => $x['time'] ?? null,
                'authors' => array_map(static fn ($a) => $a['name'] ?? '', $x['authors'] ?? []),
                'require' => $x['require'] ?? [],
                'autoload' => $x['autoload'] ?? [],
                'support' => $x['support'] ?? [],
            ];
            if (!$offline) {
                $pkg += [
                    'latest' => $latest,
                    'update' => $latest ? Semver::diff((string) $x['version'], $latest) : null,
                    'downloads_total' => $info[$n]['downloads']['total'] ?? null,
                    'downloads_monthly' => $info[$n]['downloads']['monthly'] ?? null,
                    'stars' => $info[$n]['favers'] ?? null,
                    'abandoned' => $info[$n]['abandoned'] ?? false,
                    'advisories' => array_map(static fn ($a) => ['id' => $a['cve'] ?? $a['remoteId'] ?? $a['advisoryId'], 'title' => $a['title'], 'severity' => $a['severity'] ?? null, 'affected' => $a['affectedVersions'], 'link' => $a['link'] ?? null], $affecting),
                ];
                if ($this->a->has('full')) {
                    $pkg['all_versions'] = $all[$n] ?? [];
                }
            }
            $packages[] = $pkg;
        }
        unset($enrich);
        $meta = [
            'generator' => 'slashmark/composer-suite ' . Http::VERSION,
            'generated_at' => date('c'),
            'project' => $project?->json['name'] ?? null,
            'project_license' => $project?->json['license'] ?? null,
            'php' => PHP_VERSION,
            'count' => count($packages),
            'offline' => $offline,
        ];
        $content = match ($ext) {
            'json' => json_encode(['meta' => $meta, 'packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            'csv' => $this->csv($packages, $offline),
            'md' => $this->markdown($meta, $packages, $offline),
            'html' => $this->html($meta, $packages, $offline),
            'cdx.json' => $this->cyclonedx($meta, $packages),
        };
        $out = $this->a->get('output') ?? 'composer-report.' . $ext;
        if ($out === '-') {
            fwrite(STDOUT, $content);
            return 0;
        }
        if (file_put_contents($out, $content) === false) {
            throw new \RuntimeException("Could not write $out");
        }
        if ($this->json) {
            Ui::json(['file' => realpath($out), 'format' => $fmt, 'packages' => count($packages), 'bytes' => strlen($content)]);
            return 0;
        }
        Ui::out(Ui::status('ok', 'Exported ' . count($packages) . ' packages → ' . Ui::c('bold', (string) realpath($out)) . Ui::c('gray', ' (' . Ui::bytes(strlen($content)) . ', ' . $fmt . ')')));
        return 0;
    }

    private function csv(array $packages, bool $offline): string
    {
        $h = fopen('php://temp', 'w+');
        $cols = ['name', 'version', 'dev', 'type', 'license', 'released', 'homepage', 'source', 'reference'];
        if (!$offline) {
            array_push($cols, 'latest', 'update', 'downloads_monthly', 'stars', 'abandoned', 'advisories');
        }
        fputcsv($h, $cols, ',', '"', '\\');
        foreach ($packages as $p) {
            $r = [];
            foreach ($cols as $c) {
                $v = $p[$c] ?? '';
                $r[] = match (true) {
                    $c === 'advisories' => implode('; ', array_column((array) $v, 'id')),
                    is_bool($v) => $v ? 'yes' : 'no',
                    is_array($v) => implode(' OR ', $v),
                    default => (string) $v,
                };
            }
            fputcsv($h, $r, ',', '"', '\\');
        }
        rewind($h);
        return (string) stream_get_contents($h);
    }

    private function markdown(array $meta, array $packages, bool $offline): string
    {
        $o = '# Dependency report' . ($meta['project'] ? ' — ' . $meta['project'] : '') . "\n\n";
        $o .= '_Generated ' . $meta['generated_at'] . ' by ' . $meta['generator'] . '_' . "\n\n";
        $vuln = array_filter($packages, static fn ($p) => !empty($p['advisories']));
        $outd = array_filter($packages, static fn ($p) => in_array($p['update'] ?? null, ['major', 'minor', 'patch'], true));
        $o .= '| Packages | Vulnerable | Outdated | Abandoned |' . "\n|---|---|---|---|\n";
        $o .= '| ' . count($packages) . ' | ' . ($offline ? 'n/a' : count($vuln)) . ' | ' . ($offline ? 'n/a' : count($outd)) . ' | ' . ($offline ? 'n/a' : count(array_filter($packages, static fn ($p) => !empty($p['abandoned'])))) . " |\n\n";
        $o .= "## Packages\n\n| Package | Version | Env | License | Released" . ($offline ? '' : ' | Latest | Advisories') . " |\n|---|---|---|---|---" . ($offline ? '' : '|---|---') . "|\n";
        foreach ($packages as $p) {
            $o .= '| ' . $p['name'] . ' | ' . $p['version'] . ' | ' . ($p['dev'] ? 'dev' : 'prod') . ' | ' . implode(' OR ', $p['license']) . ' | ' . substr((string) $p['released'], 0, 10);
            if (!$offline) {
                $o .= ' | ' . ($p['latest'] ?? '') . ($p['update'] && $p['update'] !== 'same' ? ' (' . $p['update'] . ')' : '') . ' | ' . (implode(', ', array_column($p['advisories'], 'id')) ?: '—');
            }
            $o .= " |\n";
        }
        return $o;
    }

    private function html(array $meta, array $packages, bool $offline): string
    {
        $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $vuln = count(array_filter($packages, static fn ($p) => !empty($p['advisories'])));
        $outd = count(array_filter($packages, static fn ($p) => in_array($p['update'] ?? null, ['major', 'minor', 'patch'], true)));
        $aband = count(array_filter($packages, static fn ($p) => !empty($p['abandoned'])));
        $rowsHtml = '';
        foreach ($packages as $p) {
            $upd = $p['update'] ?? null;
            $badge = match ($upd) {
                'major' => '<span class="b r">major</span>', 'minor' => '<span class="b y">minor</span>', 'patch' => '<span class="b g">patch</span>', default => '',
            };
            $adv = '';
            foreach ($p['advisories'] ?? [] as $a) {
                $adv .= '<a class="b r" href="' . $e($a['link']) . '" target="_blank" rel="noopener">' . $e($a['id']) . '</a> ';
            }
            $rowsHtml .= '<tr data-q="' . $e(strtolower($p['name'] . ' ' . implode(' ', $p['license']))) . '"><td><b>' . $e($p['name']) . '</b>' . (!empty($p['abandoned']) ? ' <span class="b r">abandoned</span>' : '') . '<div class="d">' . $e($p['description']) . '</div></td><td>' . $e($p['version']) . '</td><td>' . ($p['dev'] ? 'dev' : 'prod') . '</td><td>' . $e(implode(' OR ', $p['license'])) . '</td><td>' . $e(substr((string) $p['released'], 0, 10)) . '</td>'
                . ($offline ? '' : '<td>' . $e($p['latest'] ?? '') . ' ' . $badge . '</td><td>' . ($adv ?: '—') . '</td><td class="n">' . $e(Ui::num($p['downloads_monthly'] ?? 0)) . '</td>') . '</tr>';
        }
        $title = 'Dependency report' . ($meta['project'] ? ' — ' . $meta['project'] : '');
        $cards = '<div class="c"><b>' . count($packages) . '</b>packages</div>' . ($offline ? '' : '<div class="c' . ($vuln ? ' bad' : '') . '"><b>' . $vuln . '</b>vulnerable</div><div class="c"><b>' . $outd . '</b>outdated</div><div class="c' . ($aband ? ' bad' : '') . '"><b>' . $aband . '</b>abandoned</div>');
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($title) . '</title><style>
:root{--bg:#fff;--fg:#0f172a;--mut:#64748b;--line:#e2e8f0;--card:#f8fafc;--acc:#7c3aed}
@media (prefers-color-scheme:dark){:root{--bg:#0b1020;--fg:#e2e8f0;--mut:#94a3b8;--line:#1e293b;--card:#111827}}
body{margin:0;background:var(--bg);color:var(--fg);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}main{max-width:1200px;margin:0 auto;padding:24px 16px}
h1{margin:0 0 4px;font-size:24px}.m{color:var(--mut);margin-bottom:20px}.cards{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}.c{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 18px;min-width:110px;color:var(--mut)}.c b{display:block;font-size:24px;color:var(--fg)}.c.bad b{color:#dc2626}
input{width:100%;max-width:360px;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--fg);margin-bottom:12px}
.w{overflow-x:auto;border:1px solid var(--line);border-radius:10px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}th{background:var(--card);font-weight:600;font-size:12px;text-transform:uppercase;color:var(--mut);position:sticky;top:0}.d{color:var(--mut);font-size:12px;max-width:420px}.n{text-align:right}
.b{display:inline-block;font-size:11px;padding:1px 7px;border-radius:99px;text-decoration:none;margin:1px 0}.r{background:#fee2e2;color:#991b1b}.y{background:#fef3c7;color:#92400e}.g{background:#dcfce7;color:#166534}footer{color:var(--mut);font-size:12px;margin-top:16px}a{color:var(--acc)}
</style></head><body><main><h1>' . $e($title) . '</h1><div class="m">Generated ' . $e($meta['generated_at']) . ' · ' . $e($meta['generator']) . '</div><div class="cards">' . $cards . '</div>
<input id="q" placeholder="Filter packages or licenses…" aria-label="Filter"><div class="w"><table><thead><tr><th>Package</th><th>Version</th><th>Env</th><th>License</th><th>Released</th>' . ($offline ? '' : '<th>Latest</th><th>Advisories</th><th class="n">Monthly</th>') . '</tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>
<footer>Made with <a href="https://slashmark.in" target="_blank" rel="noopener">Slash Mark Composer Suite</a></footer></main>
<script>document.getElementById("q").addEventListener("input",function(e){var q=e.target.value.toLowerCase();document.querySelectorAll("tbody tr").forEach(function(r){r.style.display=r.dataset.q.indexOf(q)>-1?"":"none"})})</script></body></html>' . "\n";
    }

    private function cyclonedx(array $meta, array $packages): string
    {
        $comps = [];
        foreach ($packages as $p) {
            [$vendor, $name] = array_pad(explode('/', (string) $p['name'], 2), 2, '');
            $c = [
                'type' => 'library',
                'bom-ref' => 'pkg:composer/' . $p['name'] . '@' . $p['version'],
                'group' => $vendor,
                'name' => $name,
                'version' => (string) $p['version'],
                'description' => $p['description'],
                'purl' => 'pkg:composer/' . $p['name'] . '@' . rawurlencode((string) $p['version']),
                'scope' => $p['dev'] ? 'optional' : 'required',
                'licenses' => array_map(static fn ($l) => ['license' => ['id' => $l]], $p['license']),
                'externalReferences' => array_values(array_filter([
                    $p['source'] ? ['type' => 'vcs', 'url' => $p['source']] : null,
                    $p['homepage'] ? ['type' => 'website', 'url' => $p['homepage']] : null,
                    $p['dist'] ? ['type' => 'distribution', 'url' => $p['dist']] : null,
                ])),
            ];
            if (!empty($p['shasum'])) {
                $c['hashes'] = [['alg' => 'SHA-1', 'content' => $p['shasum']]];
            }
            $comps[] = $c;
        }
        $vulns = [];
        foreach ($packages as $p) {
            foreach ($p['advisories'] ?? [] as $a) {
                $vulns[] = ['id' => $a['id'], 'source' => ['name' => 'Packagist security advisories'], 'description' => $a['title'], 'ratings' => $a['severity'] ? [['severity' => $a['severity']]] : [], 'advisories' => $a['link'] ? [['url' => $a['link']]] : [], 'affects' => [['ref' => 'pkg:composer/' . $p['name'] . '@' . $p['version']]]];
            }
        }
        $bom = [
            'bomFormat' => 'CycloneDX', 'specVersion' => '1.5', 'serialNumber' => 'urn:uuid:' . self::uuid(), 'version' => 1,
            'metadata' => ['timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'tools' => [['vendor' => 'Slash Mark', 'name' => 'composer-suite', 'version' => Http::VERSION]], 'component' => ['type' => 'application', 'name' => $meta['project'] ?? 'project']],
            'components' => $comps,
        ];
        if ($vulns) {
            $bom['vulnerabilities'] = $vulns;
        }
        return json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

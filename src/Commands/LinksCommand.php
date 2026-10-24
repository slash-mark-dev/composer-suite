<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\HttpException;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class LinksCommand extends Command
{
    public const NAME = 'links';
    public const TITLE = 'Broken Link Checker';
    public const SUMMARY = 'Detect dead homepage, repository, docs and support links';
    public const CATEGORY = 'Security & compliance';
    public const ALIASES = ['linkcheck', 'urls'];
    public const USAGE = '[vendor/pkg …] [--project] [--all]';
    public const OPTIONS = ['project' => 'flag', 'all' => 'flag', 'no-dev' => 'flag'];
    public const SHORT = ['p' => 'project', 'a' => 'all'];
    public const HELP = [
        'vendor/pkg' => 'Check one or more published packages (latest release metadata)',
        '-p, --project' => 'Check links of every package in composer.lock plus your own composer.json',
        '-a, --all' => 'Show working links too (default: only problems)',
        '--no-dev' => 'Skip dev packages',
    ];
    public const EXAMPLES = ['sm-composer links monolog/monolog --all', 'sm-composer links --project', 'sm-composer links acme/gpl-lib'];

    public function run(): int
    {
        $links = [];
        if ($this->a->pos) {
            foreach ($this->a->pos as $n) {
                $n = Packagist::assertName($n);
                $info = Packagist::package($n);
                $latest = Packagist::latest($n) ?? [];
                $this->collect($links, $n, $latest + ['repository' => $info['repository'] ?? null]);
            }
        } else {
            $p = $this->project();
            $this->collect($links, $p->json['name'] ?? 'your composer.json', $p->json);
            foreach ($p->locked(!$this->a->has('no-dev')) as $n => $x) {
                $this->collect($links, $n, $x);
            }
        }
        $urls = array_values(array_unique(array_column($links, 'url')));
        Ui::info('Checking ' . count($urls) . ' links…');
        $status = $this->check($urls);
        $rows = [];
        foreach ($links as $l) {
            $s = $status[$l['url']];
            $rows[] = $l + $s;
        }
        $bad = array_values(array_filter($rows, static fn ($r) => $r['state'] === 'broken'));
        $warn = array_values(array_filter($rows, static fn ($r) => $r['state'] === 'warn'));

        $this->out(['checked' => count($urls), 'broken' => $bad, 'warnings' => $warn, 'links' => $rows], function (array $d) use ($rows) {
            Ui::title('Link check', $d['checked'] . ' unique links');
            Ui::out();
            $show = $this->a->has('all') ? $rows : array_merge($d['broken'], $d['warnings']);
            if (!$show) {
                Ui::out(Ui::status('ok', 'All links work'));
                return;
            }
            $t = [];
            foreach ($show as $r) {
                $st = match ($r['state']) {
                    'ok' => Ui::c('green', '✔ ' . $r['code']),
                    'warn' => Ui::c('yellow', '▲ ' . ($r['code'] ?: '') . ' ' . $r['note']),
                    default => Ui::c('red', '✖ ' . ($r['code'] ?: 'error') . ' ' . $r['note']),
                };
                $t[] = [Ui::c('cyan', $r['package']), Ui::c('gray', $r['kind']), $r['url'], $st];
            }
            Ui::table(['Package', 'Link', 'URL', 'Status'], $t, [], 2);
            Ui::out();
            Ui::out(($d['broken'] ? Ui::status('fail', count($d['broken']) . ' broken') : Ui::status('ok', '0 broken')) . Ui::c('gray', ' · ' . count($d['warnings']) . ' warnings'));
        });
        return $bad ? 1 : 0;
    }

    private function collect(array &$links, string $pkg, array $meta): void
    {
        $add = static function (string $kind, $url) use (&$links, $pkg): void {
            if (!is_string($url) || $url === '') {
                return;
            }
            if (preg_match('#^git@([^:]+):(.+?)(\.git)?$#', $url, $m)) {
                $url = 'https://' . $m[1] . '/' . $m[2];
            }
            $url = (string) preg_replace('#\.git$#', '', $url);
            if (!preg_match('#^https?://#i', $url)) {
                return;
            }
            $links[] = ['package' => $pkg, 'kind' => $kind, 'url' => $url];
        };
        $add('homepage', $meta['homepage'] ?? null);
        $add('repository', $meta['repository'] ?? ($meta['source']['url'] ?? null));
        foreach ($meta['support'] ?? [] as $k => $u) {
            if ($k !== 'source' && $k !== 'email') {
                $add('support.' . $k, $u);
            }
        }
        foreach ($meta['funding'] ?? [] as $f) {
            $add('funding', $f['url'] ?? null);
        }
        // de-duplicate per package
        $seen = [];
        $links = array_values(array_filter($links, static function ($l) use (&$seen) {
            $k = $l['package'] . '|' . $l['url'];
            if (isset($seen[$k])) {
                return false;
            }
            return $seen[$k] = true;
        }));
    }

    /** @return array<string, array{state:string, code:int, note:string, final:?string}> */
    private function check(array $urls): array
    {
        $out = [];
        $classify = static function (string $url, int $code, string $final, ?string $err): array {
            if ($err !== null || $code === 0) {
                return ['state' => 'broken', 'code' => 0, 'note' => $err ?? 'no response', 'final' => null];
            }
            if ($code >= 400 && !in_array($code, [401, 403, 429], true)) {
                return ['state' => 'broken', 'code' => $code, 'note' => $code === 404 ? 'not found' : 'error', 'final' => $final];
            }
            if (in_array($code, [401, 403, 429], true)) {
                return ['state' => 'warn', 'code' => $code, 'note' => 'blocked automated check (verify manually)', 'final' => $final];
            }
            if (str_starts_with($url, 'http://')) {
                return ['state' => 'warn', 'code' => $code, 'note' => 'insecure http:// link', 'final' => $final];
            }
            if ($final && rtrim($final, '/') !== rtrim($url, '/') && parse_url($final, PHP_URL_HOST) !== parse_url($url, PHP_URL_HOST)) {
                return ['state' => 'warn', 'code' => $code, 'note' => 'redirects to ' . parse_url($final, PHP_URL_HOST), 'final' => $final];
            }
            return ['state' => 'ok', 'code' => $code, 'note' => '', 'final' => $final];
        };
        if (!function_exists('curl_multi_init')) {
            foreach ($urls as $u) {
                try {
                    $r = Http::request($u, 'GET', null, [], 0);
                    $out[$u] = $classify($u, $r['status'], $r['url'], null);
                } catch (HttpException $e) {
                    $out[$u] = $classify($u, 0, '', $e->getMessage());
                }
            }
            return $out;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($urls as $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => Http::userAgent(),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$u] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running);
        $retry = [];
        foreach ($handles as $u => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_errno($ch) ? curl_error($ch) : null;
            // some servers reject HEAD — retry those with GET
            if ($err === null && in_array($code, [400, 403, 405, 501], true)) {
                $retry[] = $u;
            } else {
                $out[$u] = $classify($u, $code, (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), $err);
            }
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        foreach ($retry as $u) {
            try {
                $r = Http::request($u, 'GET', null, [], 0);
                $out[$u] = $classify($u, $r['status'], $r['url'], null);
            } catch (HttpException $e) {
                $out[$u] = $classify($u, 0, '', $e->getMessage());
            }
        }
        return $out;
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\HttpException;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class MaintainersCommand extends Command
{
    public const NAME = 'maintainers';
    public const TITLE = 'Maintainer Info Viewer';
    public const SUMMARY = 'Maintainers, authors and top contributors (plus bus-factor for projects)';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['authors', 'who'];
    public const USAGE = '<vendor/package> [--contributors] | --project';
    public const OPTIONS = ['contributors' => 'flag', 'project' => 'flag', 'limit' => 'value'];
    public const SHORT = ['c' => 'contributors', 'p' => 'project', 'n' => 'limit'];
    public const HELP = [
        '-c, --contributors' => 'Also fetch top GitHub contributors (set GITHUB_TOKEN for higher rate limits)',
        '-p, --project' => 'Maintainers across all your dependencies, flagging single-maintainer packages',
        '-n, --limit <n>' => 'Contributors to show (default 10)',
    ];
    public const EXAMPLES = ['sm-composer maintainers guzzlehttp/guzzle', 'sm-composer maintainers laravel/framework -c', 'sm-composer maintainers --project'];

    public function run(): int
    {
        if ($this->a->has('project')) {
            return $this->projectView();
        }
        $name = $this->pkg();
        $info = Packagist::package($name);
        $versions = Packagist::versions($name);
        $authors = [];
        foreach ($versions as $v) {
            foreach ($v['authors'] ?? [] as $a) {
                $k = strtolower($a['name'] ?? $a['email'] ?? '?');
                $authors[$k] ??= ['name' => $a['name'] ?? '?', 'email' => $a['email'] ?? null, 'homepage' => $a['homepage'] ?? null, 'role' => $a['role'] ?? null, 'versions' => 0, 'latest' => null];
                $authors[$k]['versions']++;
                $authors[$k]['latest'] ??= $v['version'];
            }
        }
        $maint = array_map(static fn ($m) => ['name' => $m['name'], 'profile' => 'https://packagist.org/users/' . rawurlencode($m['name']) . '/', 'avatar' => $m['avatar_url'] ?? null], $info['maintainers'] ?? []);
        $contrib = [];
        $contribError = null;
        if ($this->a->has('contributors')) {
            $gh = Packagist::github($info['repository'] ?? null);
            if ($gh) {
                try {
                    $list = Http::json(Packagist::githubApi() . "/repos/{$gh[0]}/{$gh[1]}/contributors?per_page=" . $this->a->int('limit', 10), 3600);
                    foreach ($list as $c) {
                        $contrib[] = ['login' => $c['login'] ?? '?', 'contributions' => $c['contributions'] ?? 0, 'profile' => $c['html_url'] ?? null];
                    }
                } catch (HttpException $e) {
                    $contribError = $e->getMessage();
                }
            } else {
                $contribError = 'repository is not on GitHub';
            }
        }

        return $this->out(['package' => $name, 'maintainers' => $maint, 'authors' => array_values($authors), 'contributors' => $contrib, 'contributors_error' => $contribError], function (array $d) {
            Ui::title("People behind {$d['package']}");
            Ui::section('Packagist maintainers (' . count($d['maintainers']) . ')' . (count($d['maintainers']) === 1 ? Ui::c('yellow', '  single maintainer') : ''));
            foreach ($d['maintainers'] as $m) {
                Ui::out('  ' . Ui::pad(Ui::c('cyan', $m['name']), 20) . Ui::c('gray', $m['profile']));
            }
            Ui::section('Authors listed in composer.json');
            $t = [];
            foreach ($d['authors'] as $a) {
                $t[] = [Ui::c('bold', $a['name']), $a['role'] ?? '', $a['email'] ?? '', $a['homepage'] ?? '', $a['versions'] . ' releases'];
            }
            Ui::table(['Name', 'Role', 'Email', 'Homepage', 'Listed in'], $t);
            if ($d['contributors']) {
                Ui::section('Top GitHub contributors');
                $max = max(array_column($d['contributors'], 'contributions')) ?: 1;
                foreach ($d['contributors'] as $c) {
                    Ui::out('  ' . Ui::pad(Ui::c('cyan', $c['login']), 22) . Ui::bar($c['contributions'] / $max, 25) . ' ' . $c['contributions'] . ' commits');
                }
            } elseif ($d['contributors_error']) {
                Ui::out();
                Ui::out(Ui::status('warn', 'Contributors unavailable: ' . $d['contributors_error']));
            }
        });
    }

    private function projectView(): int
    {
        $p = $this->project();
        $p->requireLock();
        $names = array_keys($p->locked());
        Ui::info('Loading maintainers for ' . count($names) . ' packages…');
        $info = Packagist::packageMany($names);
        $byPerson = [];
        $single = [];
        foreach ($info as $n => $i) {
            $ms = array_column($i['maintainers'] ?? [], 'name');
            foreach ($ms as $m) {
                $byPerson[$m][] = $n;
            }
            if (count($ms) === 1) {
                $single[] = ['package' => $n, 'maintainer' => $ms[0], 'monthly' => $i['downloads']['monthly'] ?? 0];
            }
        }
        uasort($byPerson, static fn ($a, $b) => count($b) <=> count($a));
        return $this->out(['packages' => count($names), 'by_maintainer' => $byPerson, 'single_maintainer' => $single], function (array $d) {
            Ui::title('Who maintains your dependencies', $d['packages'] . ' packages');
            Ui::section('By maintainer');
            $t = [];
            foreach (array_slice($d['by_maintainer'], 0, 20, true) as $m => $pk) {
                $t[] = [Ui::c('cyan', $m), (string) count($pk), Ui::truncate(implode(', ', $pk), 80)];
            }
            Ui::table(['Maintainer', 'Packages', 'Which'], $t, [1 => true], 2);
            Ui::section('Single Packagist maintainer account (' . count($d['single_maintainer']) . ')');
            Ui::out(Ui::c('gray', '  Only one account can publish releases. Fine for organisation accounts (e.g. php-fig); a risk for personal ones.'));
            $t = [];
            foreach ($d['single_maintainer'] as $s) {
                $t[] = [Ui::c('yellow', $s['package']), $s['maintainer'], Ui::num($s['monthly']) . '/mo'];
            }
            Ui::table(['Package', 'Maintainer', 'Downloads'], $t, [2 => true]);
        });
    }
}

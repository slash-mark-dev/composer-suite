<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class DependentsCommand extends Command
{
    public const NAME = 'dependents';
    public const TITLE = 'Reverse Dependencies Finder';
    public const SUMMARY = 'Find packages that depend on a package (on Packagist or in your lock file)';
    public const CATEGORY = 'Discover';
    public const ALIASES = ['why', 'rdeps', 'depends'];
    public const USAGE = '<vendor/package> [--local] [--page <n>] [-n <limit>]';
    public const OPTIONS = ['local' => 'flag', 'page' => 'value', 'limit' => 'value'];
    public const SHORT = ['l' => 'local', 'n' => 'limit'];
    public const HELP = [
        '-l, --local' => 'Search your composer.lock instead (like `composer why`, with the chain to your root)',
        '--page <n>' => 'Page of Packagist results',
        '-n, --limit <n>' => 'Max rows (default 25)',
    ];
    public const EXAMPLES = ['sm-composer dependents psr/log', 'sm-composer why psr/http-message --local'];

    public function run(): int
    {
        $name = $this->pkg();
        return $this->a->has('local') ? $this->local($name) : $this->remote($name);
    }

    private function remote(string $name): int
    {
        $info = Packagist::package($name);
        $limit = max(1, $this->a->int('limit', 25));
        $rows = array_slice(Packagist::dependents($name, $this->a->int('page', 1)), 0, $limit);
        $data = ['package' => $name, 'total' => $info['dependents'] ?? count($rows), 'suggesters' => $info['suggesters'] ?? 0, 'dependents' => $rows];

        return $this->out($data, function (array $d) {
            Ui::title("Packages that depend on {$d['package']}", Ui::num($d['total']) . ' dependents · ' . Ui::num($d['suggesters']) . ' suggesters');
            Ui::out();
            $t = [];
            foreach ($d['dependents'] as $i => $r) {
                $t[] = [(string) ($i + 1), Ui::c('cyan', $r['name']), $r['downloads'] !== null ? Ui::num($r['downloads']) : '', $r['description']];
            }
            Ui::table(['#', 'Package', 'Downloads', 'Description'], $t, [0 => true, 2 => true], 3);
            Ui::out();
            Ui::out(Ui::c('gray', '  Full list: ' . Packagist::packageUrl($d['package']) . '/dependents'));
        });
    }

    private function local(string $name): int
    {
        $p = $this->project();
        $p->requireLock();
        $locked = $p->locked();
        $direct = [];
        // root project
        foreach (['require' => false, 'require-dev' => true] as $key => $dev) {
            foreach ($p->json[$key] ?? [] as $dep => $c) {
                if (strtolower($dep) === $name) {
                    $direct[] = ['name' => $p->json['name'] ?? '__root__', 'version' => 'root', 'constraint' => $c, 'dev' => $dev, 'root' => true];
                }
            }
        }
        foreach ($locked as $pkg) {
            foreach (['require', 'require-dev'] as $key) {
                foreach ($pkg[$key] ?? [] as $dep => $c) {
                    if (strtolower($dep) === $name && $key === 'require') {
                        $direct[] = ['name' => $pkg['name'], 'version' => $pkg['version'], 'constraint' => $c, 'dev' => $pkg['dev'], 'root' => false];
                    }
                }
            }
        }
        // chains back to the root for each direct dependent
        $parents = [];
        foreach ($locked as $pkg) {
            foreach ($pkg['require'] ?? [] as $dep => $c) {
                $parents[strtolower($dep)][] = $pkg['name'];
            }
        }
        $rootReq = array_change_key_case(($p->json['require'] ?? []) + ($p->json['require-dev'] ?? []));
        $chain = function (string $pkg, array $seen = []) use (&$chain, $parents, $rootReq): ?array {
            $l = strtolower($pkg);
            if (isset($rootReq[$l])) {
                return [$pkg];
            }
            foreach ($parents[$l] ?? [] as $par) {
                if (!in_array($par, $seen, true) && ($c = $chain($par, [...$seen, $pkg]))) {
                    return [...$c, $pkg];
                }
            }
            return null;
        };
        foreach ($direct as &$d) {
            $d['chain'] = $d['root'] ? [] : ($chain($d['name']) ?? []);
        }
        unset($d);
        $installed = $locked[$name]['version'] ?? null;

        return $this->out(['package' => $name, 'installed' => $installed, 'required_by' => $direct], function (array $d) {
            Ui::title("Why is {$d['package']} installed?", $d['installed'] ? 'locked at ' . $d['installed'] : 'not in composer.lock');
            Ui::out();
            if (!$d['required_by']) {
                Ui::out(Ui::status('info', 'Nothing in this project requires it.'));
                return;
            }
            foreach ($d['required_by'] as $r) {
                $who = $r['root'] ? Ui::c('bold', 'your composer.json') : Ui::c('cyan', $r['name']) . ' ' . Ui::c('gray', $r['version']);
                Ui::out('  ' . $who . ' requires ' . Ui::c('yellow', $r['constraint']) . ($r['dev'] ? Ui::c('gray', '  (dev)') : ''));
                if ($r['chain']) {
                    Ui::out('    ' . Ui::c('gray', 'root → ' . implode(' → ', $r['chain']) . ' → ' . $d['package']));
                }
            }
        });
    }
}

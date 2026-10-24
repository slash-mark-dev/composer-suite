<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Ui;
use SlashMark\ComposerSuite\UsageException;

final class CmdCommand extends Command
{
    public const NAME = 'cmd';
    public const TITLE = 'Composer Command Generator';
    public const SUMMARY = 'Generate correct composer require/install/update commands';
    public const CATEGORY = 'Workflow';
    public const ALIASES = ['gen', 'command'];
    public const USAGE = '<require|remove|update|install|deploy|ci|create-project> [packages…] [options]';
    public const OPTIONS = ['dev' => 'flag', 'exact' => 'flag', 'tilde' => 'flag', 'php' => 'value', 'with-all' => 'flag', 'no-update' => 'flag', 'ignore-platform' => 'flag', 'global' => 'flag', 'format' => 'value', 'stability' => 'value'];
    public const HELP = [
        'require <pkg…>' => 'Looks up the latest stable release and writes the best constraint (^x.y)',
        'remove <pkg…>' => 'Remove packages cleanly (with unused dependencies)',
        'update [pkg…]' => 'Targeted update command',
        'install' => 'Reproducible install from composer.lock',
        'deploy' => 'Production install: no dev deps, optimized + authoritative autoloader',
        'ci' => 'CI install with caching-friendly flags',
        'create-project <pkg> [dir]' => 'Start a new project from a skeleton',
        '--dev' => 'Add to require-dev',
        '--exact / --tilde' => 'Pin exactly, or allow patch updates only (~x.y.z)',
        '--php <ver>' => 'Pick the newest release that still supports this PHP version',
        '--stability <s>' => 'Allow beta/RC/dev releases for require (adds @<s>)',
        '--with-all' => 'Add -W (also update dependencies of the named packages)',
        '--no-update / --ignore-platform / --global' => 'Pass the matching composer flag',
        '--format <f>' => 'shell (default), docker, github (GitHub Actions step) or makefile',
    ];
    public const EXAMPLES = [
        'sm-composer cmd require monolog/monolog guzzlehttp/guzzle',
        'sm-composer cmd require phpunit/phpunit --dev --php 8.1',
        'sm-composer cmd deploy --format docker',
        'sm-composer cmd create-project laravel/laravel blog',
    ];

    public function run(): int
    {
        $action = strtolower($this->need(0, 'action (require, remove, update, install, deploy, ci, create-project)'));
        $args = array_slice($this->a->pos, 1);
        $notes = [];
        $flags = [];
        if ($this->a->has('no-update')) {
            $flags[] = '--no-update';
        }
        if ($this->a->has('ignore-platform')) {
            $flags[] = '--ignore-platform-reqs';
        }
        $global = $this->a->has('global') ? 'global ' : '';
        $cmds = [];

        switch ($action) {
            case 'require':
            case 'add':
                if (!$args) {
                    throw new UsageException('Give at least one package, e.g. cmd require monolog/monolog');
                }
                $specs = [];
                foreach ($args as $spec) {
                    [$name, $ver] = $this->pkgWithVersion($spec);
                    if ($ver !== null) {
                        if (!Semver::isValid($ver)) {
                            throw new \InvalidArgumentException("Invalid constraint \"$ver\" for $name");
                        }
                        $specs[] = "$name:$ver";
                        continue;
                    }
                    [$v, $note] = $this->pick($name);
                    $notes[] = $note;
                    $c = match (true) {
                        $this->a->has('exact') => $v,
                        $this->a->has('tilde') => '~' . implode('.', array_slice(explode('.', ltrim($v, 'v')), 0, 3)),
                        default => Semver::suggest($v),
                    };
                    if (($s = $this->a->get('stability')) && !str_contains($c, '@')) {
                        $c .= '@' . $s;
                    }
                    $specs[] = "$name:$c";
                }
                if ($this->a->has('dev')) {
                    $flags[] = '--dev';
                }
                if ($this->a->has('with-all')) {
                    $flags[] = '-W';
                }
                $cmds[] = 'composer ' . $global . 'require ' . implode(' ', array_map([self::class, 'q'], $specs)) . ($flags ? ' ' . implode(' ', $flags) : '');
                break;
            case 'remove':
                if (!$args) {
                    throw new UsageException('Give at least one package to remove');
                }
                $cmds[] = 'composer ' . $global . 'remove ' . implode(' ', array_map([Packagist::class, 'assertName'], $args)) . ($this->a->has('dev') ? ' --dev' : '') . ' --update-with-dependencies' . ($flags ? ' ' . implode(' ', $flags) : '');
                break;
            case 'update':
                $pk = array_map([Packagist::class, 'assertName'], $args);
                $cmds[] = 'composer ' . $global . 'update' . ($pk ? ' ' . implode(' ', $pk) : '') . ($this->a->has('with-all') || $pk ? ' -W' : '') . ($flags ? ' ' . implode(' ', $flags) : '');
                $notes[] = $pk ? 'Updates only these packages (and their dependencies with -W); composer.json is unchanged' : 'Updates everything allowed by composer.json — commit the new composer.lock';
                break;
            case 'install':
                $cmds[] = 'composer install --no-interaction --prefer-dist' . ($flags ? ' ' . implode(' ', $flags) : '');
                $notes[] = 'Installs exactly what composer.lock says';
                break;
            case 'deploy':
            case 'production':
                $cmds[] = 'composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative --no-progress';
                $cmds[] = 'composer check-platform-reqs --no-dev';
                $notes[] = '--classmap-authoritative: classes not in the classmap are never searched on disk (fastest; run dump-autoload after generating classes)';
                $notes[] = 'Run check-platform-reqs on the server to confirm PHP version and extensions';
                break;
            case 'ci':
                $cmds[] = 'composer validate --strict';
                $cmds[] = 'composer install --no-interaction --prefer-dist --no-progress';
                $cmds[] = 'composer audit';
                $notes[] = 'Cache ~/.cache/composer (or `composer config cache-dir`) between CI runs';
                break;
            case 'create-project':
            case 'new':
                if (!$args) {
                    throw new UsageException('Give a skeleton package, e.g. laravel/laravel');
                }
                $name = Packagist::assertName($args[0]);
                $dir = $args[1] ?? explode('/', $name)[1];
                [$v, $note] = $this->pick($name);
                $notes[] = $note;
                $cmds[] = 'composer create-project ' . $name . ' ' . self::q($dir) . ' ' . self::q(Semver::suggest($v)) . ' --prefer-dist';
                break;
            default:
                throw new UsageException("Unknown action \"$action\"");
        }

        $fmt = $this->a->get('format', 'shell');
        $rendered = match ($fmt) {
            'docker' => implode("\n", array_map(static fn ($c) => 'RUN ' . $c, $cmds)),
            'github' => "- name: Composer\n  run: |\n" . implode("\n", array_map(static fn ($c) => '    ' . $c, $cmds)),
            'makefile' => "composer:\n" . implode("\n", array_map(static fn ($c) => "\t" . $c, $cmds)),
            default => implode("\n", $cmds),
        };
        $notes = array_values(array_filter($notes));

        return $this->out(['action' => $action, 'commands' => $cmds, 'formatted' => $rendered, 'notes' => $notes], function (array $d) {
            Ui::out(Ui::c('gray', '# ' . $d['action']));
            foreach (explode("\n", $d['formatted']) as $l) {
                Ui::out(Ui::c('green', $l));
            }
            foreach ($d['notes'] as $n) {
                Ui::err(Ui::c('gray', '# ' . $n));
            }
        });
    }

    /** @return array{0: string, 1: string} [version, note] */
    private function pick(string $name): array
    {
        $versions = Packagist::versions($name);
        $php = $this->a->get('php');
        $stab = $this->a->get('stability');
        foreach ($versions as $v) {
            if (!$stab && !Semver::isStable($v['version'])) {
                continue;
            }
            if ($php) {
                $c = $v['require']['php'] ?? '*';
                $probe = preg_match('/^\d+\.\d+$/', $php) ? $php . '.99' : $php;
                if (!Semver::isValid($c) || !Semver::satisfies($probe, $c)) {
                    continue;
                }
            }
            $latest = Semver::latest(array_column($versions, 'version'));
            $note = $php && $v['version'] !== $latest ? "$name: using {$v['version']} — latest $latest needs PHP " . (Packagist::findVersion($name, (string) $latest)['require']['php'] ?? '?') : "$name: newest " . ($stab ? "allowed ($stab or better)" : "stable") . " release is {$v['version']}";
            return [$v['version'], $note];
        }
        throw new \InvalidArgumentException("No release of $name matches" . ($php ? " PHP $php" : ' (stable)'));
    }

    private static function q(string $s): string
    {
        return preg_match('/^[\w\/.:@-]+$/', $s) ? $s : '"' . str_replace('"', '\"', $s) . '"';
    }
}

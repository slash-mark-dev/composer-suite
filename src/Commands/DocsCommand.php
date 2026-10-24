<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite\Commands;

use SlashMark\ComposerSuite\Command;
use SlashMark\ComposerSuite\Http;
use SlashMark\ComposerSuite\HttpException;
use SlashMark\ComposerSuite\Markdown;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Ui;

final class DocsCommand extends Command
{
    public const NAME = 'docs';
    public const TITLE = 'Documentation Viewer';
    public const SUMMARY = 'Read a package README right in the terminal';
    public const CATEGORY = 'Package insight';
    public const ALIASES = ['readme', 'doc'];
    public const USAGE = '<vendor/package> [--section <heading>] [--toc] [--raw] [--ref <tag>]';
    public const OPTIONS = ['section' => 'value', 'toc' => 'flag', 'raw' => 'flag', 'ref' => 'value', 'local' => 'flag'];
    public const SHORT = ['s' => 'section', 't' => 'toc', 'l' => 'local'];
    public const HELP = [
        '-s, --section <text>' => 'Show one section only, e.g. --section install',
        '-t, --toc' => 'Table of contents only',
        '--raw' => 'Print the raw Markdown',
        '--ref <tag>' => 'Read docs for a tag/branch (default: default branch)',
        '-l, --local' => 'Read the copy in ./vendor (works offline)',
    ];
    public const EXAMPLES = ['sm-composer docs guzzlehttp/guzzle', 'sm-composer docs spatie/laravel-permission --section install', 'sm-composer docs monolog/monolog --toc', 'sm-composer docs psr/log --local'];

    private const NAMES = ['README.md', 'readme.md', 'Readme.md', 'README.markdown', 'README.rst', 'README.txt', 'README'];

    public function run(): int
    {
        $name = $this->pkg();
        [$md, $source] = $this->a->has('local') ? $this->fromVendor($name) : $this->fromRepo($name);
        if ($md === null) {
            $info = Packagist::package($name);
            $latest = Packagist::latest($name);
            Ui::out(Ui::status('warn', "No README found for $name ($source)."));
            Ui::out(Ui::wrap($info['description'] ?? ''));
            foreach (['Docs' => $latest['support']['docs'] ?? null, 'Homepage' => $latest['homepage'] ?? null, 'Source' => $info['repository'] ?? null] as $k => $u) {
                if ($u) {
                    Ui::out('  ' . Ui::c('gray', $k . ': ') . $u);
                }
            }
            return 1;
        }
        if ($sec = $this->a->get('section')) {
            $part = Markdown::section($md, $sec);
            if ($part === null) {
                $titles = array_column(Markdown::toc($md), 'title');
                throw new \InvalidArgumentException("No section matching \"$sec\". Sections: " . implode(', ', $titles));
            }
            $md = $part;
        }
        if ($this->json) {
            Ui::json(['package' => $name, 'source' => $source, 'toc' => Markdown::toc($md), 'markdown' => $md]);
            return 0;
        }
        if ($this->a->has('toc')) {
            Ui::title("Contents · $name", $source);
            foreach (Markdown::toc($md) as $h) {
                Ui::out(str_repeat('  ', $h['level']) . ($h['level'] <= 2 ? Ui::c('cyan', $h['title']) : $h['title']));
            }
            return 0;
        }
        if ($this->a->has('raw')) {
            Ui::out($md);
            return 0;
        }
        Ui::out(Ui::c('gray', "── $name · $source"));
        Ui::out();
        Ui::out(Markdown::render($md, min(100, Ui::termWidth() - 4)));
        return 0;
    }

    /** @return array{0: ?string, 1: string} */
    private function fromRepo(string $name): array
    {
        $info = Packagist::package($name);
        $gh = Packagist::github($info['repository'] ?? null);
        if (!$gh) {
            return [null, 'repository is not on GitHub: ' . ($info['repository'] ?? 'none')];
        }
        $ref = $this->a->get('ref', 'HEAD');
        foreach (self::NAMES as $f) {
            try {
                $url = Packagist::githubRaw() . "/{$gh[0]}/{$gh[1]}/$ref/$f";
                return [Http::text($url, 3600), "github.com/{$gh[0]}/{$gh[1]}/$f"];
            } catch (HttpException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        return [null, "github.com/{$gh[0]}/{$gh[1]}"];
    }

    /** @return array{0: ?string, 1: string} */
    private function fromVendor(string $name): array
    {
        $p = $this->project(false);
        $dir = $p->dir . '/' . $p->vendorDir() . '/' . $name;
        if (!is_dir($dir)) {
            return [null, "$name is not installed in " . $p->vendorDir() . '/'];
        }
        foreach (self::NAMES as $f) {
            if (is_file("$dir/$f")) {
                return [(string) file_get_contents("$dir/$f"), $p->vendorDir() . "/$name/$f"];
            }
        }
        return [null, $p->vendorDir() . "/$name"];
    }
}

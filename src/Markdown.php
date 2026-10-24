<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/** Renders Markdown for the terminal (headings, code, lists, quotes, emphasis, links, tables). */
final class Markdown
{
    public static function render(string $md, int $width = 100): string
    {
        $md = str_replace("\r\n", "\n", $md);
        // drop HTML comments, images and badge rows
        $md = (string) preg_replace('/<!--.*?-->/s', '', $md);
        $md = (string) preg_replace('/\[!\[[^\]]*\]\([^)]*\)\]\([^)]*\)/', '', $md);
        $md = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $md);
        $md = (string) preg_replace('#<img[^>]*>#i', '', $md);
        $out = [];
        $inCode = false;
        $blank = 0;
        foreach (explode("\n", $md) as $line) {
            if (preg_match('/^\s*(```|~~~)\s*([\w+-]*)/', $line, $m)) {
                $inCode = !$inCode;
                $out[] = $inCode ? Ui::c('gray', '  ┌─' . ($m[2] !== '' ? ' ' . $m[2] . ' ' : '') . str_repeat('─', 20)) : Ui::c('gray', '  └' . str_repeat('─', 24));
                continue;
            }
            if ($inCode) {
                $out[] = Ui::c('gray', '  │ ') . Ui::c('yellow', $line);
                continue;
            }
            $line = rtrim(html_entity_decode((string) preg_replace('#</?(?:p|div|span|br|a|picture|source|details|summary|sup|sub|center|b|i|strong|em)\b[^>]*>#i', '', $line)));
            if (trim($line) === '') {
                if ($blank++ < 1) {
                    $out[] = '';
                }
                continue;
            }
            $blank = 0;
            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*$/', $line, $m)) {
                $lvl = strlen($m[1]);
                $t = self::inline($m[2]);
                $out[] = $lvl === 1 ? Ui::c('bold.cyan', '█ ' . Ui::strip($t)) : ($lvl === 2 ? Ui::c('bold.cyan', '▌ ' . Ui::strip($t)) : Ui::c('bold', '  ' . Ui::strip($t)));
                continue;
            }
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $out[] = Ui::c('gray', '  ' . str_repeat('─', 40));
                continue;
            }
            if (preg_match('/^(\s*)[-*+]\s+(?:\[( |x)\]\s+)?(.*)$/i', $line, $m)) {
                $box = $m[2] === '' ? Ui::c('cyan', '•') : ($m[2] === ' ' ? '☐' : Ui::c('green', '☑'));
                $out[] = '  ' . $m[1] . $box . ' ' . self::inline($m[3]);
                continue;
            }
            if (preg_match('/^(\s*)(\d+)[.)]\s+(.*)$/', $line, $m)) {
                $out[] = '  ' . $m[1] . Ui::c('cyan', $m[2] . '.') . ' ' . self::inline($m[3]);
                continue;
            }
            if (preg_match('/^\s*>\s?(.*)$/', $line, $m)) {
                $out[] = Ui::c('gray', '  ┃ ') . Ui::c('italic', self::inline($m[1]));
                continue;
            }
            if (preg_match('/^\s*\|.*\|\s*$/', $line)) {
                if (preg_match('/^\s*\|[\s:|-]+\|\s*$/', $line)) {
                    continue; // table separator
                }
                $cells = array_map('trim', explode('|', trim($line, " |")));
                $out[] = '  ' . implode(Ui::c('gray', ' │ '), array_map([self::class, 'inline'], $cells));
                continue;
            }
            $out[] = Ui::wrap(self::inline(trim($line)), 2, $width);
        }
        return implode("\n", $out);
    }

    public static function inline(string $s): string
    {
        $s = (string) preg_replace_callback('/`([^`]+)`/', static fn ($m) => Ui::c('yellow', $m[1]), $s);
        $s = (string) preg_replace_callback('/\*\*(.+?)\*\*|__(.+?)__/', static fn ($m) => Ui::c('bold', $m[1] !== '' ? $m[1] : $m[2]), $s);
        $s = (string) preg_replace_callback('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?!\w)|(?<!\w)_(?!\s)(.+?)(?<!\s)_(?!\w)/', static fn ($m) => Ui::c('italic', ($m[1] ?? '') !== '' ? $m[1] : $m[2]), $s);
        $s = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)[^)]*\)/', static fn ($m) => Ui::c('under', $m[1]) . (str_starts_with($m[2], '#') ? '' : Ui::c('gray', ' (' . $m[2] . ')')), $s);
        return $s;
    }

    /** @return array<int, array{level:int, title:string}> */
    public static function toc(string $md): array
    {
        $toc = [];
        $inCode = false;
        foreach (explode("\n", $md) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inCode = !$inCode;
            }
            if (!$inCode && preg_match('/^(#{1,4})\s+(.*?)\s*#*$/', $line, $m)) {
                $toc[] = ['level' => strlen($m[1]), 'title' => Ui::strip(self::inline($m[2]))];
            }
        }
        return $toc;
    }

    /** Extract one section (by heading text, case-insensitive substring). */
    public static function section(string $md, string $heading): ?string
    {
        $lines = explode("\n", $md);
        $start = null;
        $level = 0;
        $inCode = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inCode = !$inCode;
            }
            if ($inCode || !preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                continue;
            }
            if ($start === null && stripos($m[2], $heading) !== false) {
                $start = $i;
                $level = strlen($m[1]);
            } elseif ($start !== null && strlen($m[1]) <= $level) {
                return implode("\n", array_slice($lines, $start, $i - $start));
            }
        }
        return $start === null ? null : implode("\n", array_slice($lines, $start));
    }
}

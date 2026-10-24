<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/**
 * Terminal output helpers: colours, tables, trees, sparklines, number/time formatting.
 * Colours turn off automatically when output is not a TTY, NO_COLOR is set, or --no-color is passed.
 */
final class Ui
{
    public static bool $color = true;
    public static bool $quiet = false;

    private const CODES = [
        'bold' => '1', 'dim' => '2', 'italic' => '3', 'under' => '4',
        'red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34',
        'magenta' => '35', 'cyan' => '36', 'gray' => '90', 'white' => '97',
        'bgRed' => '41;97', 'bgGreen' => '42;30', 'bgYellow' => '43;30', 'bgBlue' => '44;97', 'bgMagenta' => '45;97',
    ];

    public static function init(bool $forceNoColor = false): void
    {
        $tty = function_exists('stream_isatty') ? @stream_isatty(STDOUT) : (function_exists('posix_isatty') && @posix_isatty(STDOUT));
        self::$color = !$forceNoColor && getenv('NO_COLOR') === false && ($tty || getenv('FORCE_COLOR') !== false);
        if (DIRECTORY_SEPARATOR === '\\' && function_exists('sapi_windows_vt100_support')) {
            @sapi_windows_vt100_support(STDOUT, true);
        }
    }

    public static function c(string $style, string $text): string
    {
        if (!self::$color || $text === '') {
            return $text;
        }
        $codes = [];
        foreach (explode('.', $style) as $s) {
            if (isset(self::CODES[$s])) {
                $codes[] = self::CODES[$s];
            }
        }
        return $codes ? "\033[" . implode(';', $codes) . 'm' . $text . "\033[0m" : $text;
    }

    public static function out(string $line = ''): void
    {
        // stop quietly when the reader went away (e.g. `| head`)
        if (@fwrite(STDOUT, $line . PHP_EOL) === false) {
            exit(0);
        }
    }

    public static function err(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }

    public static function info(string $msg): void
    {
        if (!self::$quiet) {
            fwrite(STDERR, self::c('gray', $msg) . PHP_EOL);
        }
    }

    public static function json(mixed $data): void
    {
        @fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
    }

    public static function title(string $title, string $sub = ''): void
    {
        self::out(self::c('bold.cyan', '▌ ' . $title) . ($sub !== '' ? '  ' . self::c('gray', $sub) : ''));
    }

    public static function section(string $title): void
    {
        self::out();
        self::out(self::c('bold', $title));
    }

    public static function kv(array $pairs, int $pad = 0): void
    {
        if ($pad === 0) {
            foreach ($pairs as $k => $_) {
                $pad = max($pad, self::width((string) $k));
            }
        }
        foreach ($pairs as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            self::out('  ' . self::c('gray', self::pad((string) $k, $pad)) . '  ' . $v);
        }
    }

    public static function strip(string $s): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $s);
    }

    public static function width(string $s): int
    {
        $s = self::strip($s);
        return function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);
    }

    public static function pad(string $s, int $w, bool $right = false): string
    {
        $gap = max(0, $w - self::width($s));
        return $right ? str_repeat(' ', $gap) . $s : $s . str_repeat(' ', $gap);
    }

    public static function truncate(string $s, int $max): string
    {
        if ($max < 2 || self::width($s) <= $max) {
            return $s;
        }
        $plain = self::strip($s);
        return (function_exists('mb_strimwidth') ? mb_strimwidth($plain, 0, $max - 1, '', 'UTF-8') : substr($plain, 0, $max - 1)) . '…';
    }

    public static function termWidth(): int
    {
        $cols = (int) (getenv('COLUMNS') ?: 0);
        if ($cols <= 0 && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @stream_isatty(STDOUT)) {
            $cols = (int) @shell_exec('tput cols 2>/dev/null');
        }
        return $cols > 40 ? $cols : 120;
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, string|int|float|null>> $rows
     * @param array<int, bool> $rightAlign column index => right aligned
     */
    public static function table(array $headers, array $rows, array $rightAlign = [], ?int $flexCol = null): void
    {
        if (!$rows) {
            self::out(self::c('gray', '  (nothing to show)'));
            return;
        }
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = self::width($h);
        }
        foreach ($rows as $r) {
            foreach (array_values($r) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, self::width((string) $cell));
            }
        }
        // shrink the flexible column so the table fits the terminal
        $total = array_sum($widths) + 2 * count($widths) + 2;
        $term = self::termWidth();
        if ($flexCol !== null && $total > $term) {
            $widths[$flexCol] = max(12, $widths[$flexCol] - ($total - $term));
        }
        $line = '  ';
        foreach ($headers as $i => $h) {
            $line .= self::c('bold.gray', self::pad($h, $widths[$i], !empty($rightAlign[$i]))) . '  ';
        }
        self::out(rtrim($line));
        $sep = '  ';
        foreach ($widths as $w) {
            $sep .= str_repeat('─', $w) . '  ';
        }
        self::out(self::c('gray', rtrim($sep)));
        foreach ($rows as $r) {
            $line = '  ';
            foreach (array_values($r) as $i => $cell) {
                $cell = (string) $cell;
                if ($i === $flexCol) {
                    $cell = self::truncate($cell, $widths[$i]);
                }
                $line .= self::pad($cell, $widths[$i], !empty($rightAlign[$i])) . '  ';
            }
            self::out(rtrim($line));
        }
    }

    public static function sparkline(array $values): string
    {
        $values = array_values(array_map('floatval', $values));
        if (!$values) {
            return '';
        }
        $chars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $min = min($values);
        $max = max($values);
        $out = '';
        foreach ($values as $v) {
            $i = $max == $min ? 3 : (int) round(($v - $min) / ($max - $min) * 7);
            $out .= $chars[$i];
        }
        return $out;
    }

    public static function bar(float $ratio, int $width = 20, string $style = 'cyan'): string
    {
        $ratio = max(0.0, min(1.0, $ratio));
        $full = (int) round($ratio * $width);
        return self::c($style, str_repeat('█', $full)) . self::c('gray', str_repeat('░', $width - $full));
    }

    public static function num(int|float|null $n): string
    {
        if ($n === null) {
            return '-';
        }
        $n = (float) $n;
        $abs = abs($n);
        return match (true) {
            $abs >= 1e9 => rtrim(rtrim(number_format($n / 1e9, 1), '0'), '.') . 'B',
            $abs >= 1e6 => rtrim(rtrim(number_format($n / 1e6, 1), '0'), '.') . 'M',
            $abs >= 1e3 => rtrim(rtrim(number_format($n / 1e3, 1), '0'), '.') . 'K',
            default => (string) (int) $n,
        };
    }

    public static function bytes(int|float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($b >= 1024 && $i < 3) {
            $b /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $b : number_format($b, 1)) . ' ' . $u[$i];
    }

    public static function ago(?string $date): string
    {
        if (!$date || ($t = strtotime($date)) === false) {
            return '-';
        }
        $d = time() - $t;
        if ($d < 0) {
            return 'just now';
        }
        return match (true) {
            $d < 3600 => max(1, intdiv($d, 60)) . 'm ago',
            $d < 86400 => intdiv($d, 3600) . 'h ago',
            $d < 86400 * 45 => intdiv($d, 86400) . 'd ago',
            $d < 86400 * 365 => intdiv($d, 86400 * 30) . 'mo ago',
            default => round($d / (86400 * 365), 1) . 'y ago',
        };
    }

    public static function days(?string $date): ?int
    {
        if (!$date || ($t = strtotime($date)) === false) {
            return null;
        }
        return (int) floor((time() - $t) / 86400);
    }

    public static function date(?string $date): string
    {
        return $date && ($t = strtotime($date)) !== false ? date('Y-m-d', $t) : '-';
    }

    public static function badge(string $text, string $style): string
    {
        return self::c($style, ' ' . $text . ' ');
    }

    public static function status(string $level, string $text): string
    {
        return match ($level) {
            'ok' => self::c('green', '✔ ') . $text,
            'warn' => self::c('yellow', '▲ ') . $text,
            'fail' => self::c('red', '✖ ') . $text,
            default => self::c('cyan', '• ') . $text,
        };
    }

    /** Render a nested tree: [['label' => string, 'children' => [...]], ...] */
    public static function tree(array $nodes, string $prefix = ''): void
    {
        $n = count($nodes);
        foreach (array_values($nodes) as $i => $node) {
            $last = $i === $n - 1;
            self::out($prefix . self::c('gray', $last ? '└── ' : '├── ') . $node['label']);
            if (!empty($node['children'])) {
                self::tree($node['children'], $prefix . self::c('gray', $last ? '    ' : '│   '));
            }
        }
    }

    public static function wrap(string $text, int $indent = 2, ?int $width = null): string
    {
        $width ??= min(100, self::termWidth()) - $indent;
        $pad = str_repeat(' ', $indent);
        return $pad . str_replace("\n", "\n" . $pad, wordwrap($text, max(20, $width), "\n", true));
    }
}

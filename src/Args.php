<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

/** Minimal argument parser: positionals, --flag, --key=value, --key value, -k value, -abc. */
final class Args
{
    /** @var string[] */
    public array $pos = [];
    /** @var array<string, string|bool|array> */
    public array $opt = [];

    /**
     * @param string[] $argv
     * @param array<string,string> $spec option => 'value'|'flag'|'multi'
     * @param array<string,string> $short single letter => long name
     */
    public static function parse(array $argv, array $spec, array $short = []): self
    {
        $a = new self();
        $n = count($argv);
        for ($i = 0; $i < $n; $i++) {
            $t = $argv[$i];
            if ($t === '--') {
                array_push($a->pos, ...array_slice($argv, $i + 1));
                break;
            }
            if (str_starts_with($t, '--') && strlen($t) > 2) {
                $name = substr($t, 2);
                $val = null;
                if (str_contains($name, '=')) {
                    [$name, $val] = explode('=', $name, 2);
                }
                $kind = $spec[$name] ?? (str_starts_with($name, 'no-') ? 'flag' : 'flag');
                if ($kind !== 'flag' && $val === null) {
                    if ($i + 1 >= $n) {
                        throw new \InvalidArgumentException("Option --$name needs a value");
                    }
                    $val = $argv[++$i];
                }
                $a->set($name, $kind, $val ?? true);
                continue;
            }
            if ($t !== '' && $t[0] === '-' && strlen($t) > 1 && !is_numeric($t)) {
                $letters = substr($t, 1);
                for ($j = 0; $j < strlen($letters); $j++) {
                    $l = $letters[$j];
                    $name = $short[$l] ?? $l;
                    $kind = $spec[$name] ?? 'flag';
                    if ($kind !== 'flag') {
                        $rest = substr($letters, $j + 1);
                        if ($rest !== '') {
                            $val = ltrim($rest, '=');
                        } else {
                            if ($i + 1 >= $n) {
                                throw new \InvalidArgumentException("Option -$l needs a value");
                            }
                            $val = $argv[++$i];
                        }
                        $a->set($name, $kind, $val);
                        break;
                    }
                    $a->set($name, 'flag', true);
                }
                continue;
            }
            $a->pos[] = $t;
        }
        return $a;
    }

    private function set(string $name, string $kind, string|bool $val): void
    {
        if ($kind === 'multi') {
            $this->opt[$name] = array_merge((array) ($this->opt[$name] ?? []), array_map('trim', explode(',', (string) $val)));
        } else {
            $this->opt[$name] = $val;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->opt[$name]) && $this->opt[$name] !== false;
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $v = $this->opt[$name] ?? null;
        return is_string($v) ? $v : $default;
    }

    public function int(string $name, int $default): int
    {
        $v = $this->get($name);
        return $v !== null && is_numeric($v) ? (int) $v : $default;
    }

    /** @return string[] */
    public function list(string $name): array
    {
        $v = $this->opt[$name] ?? [];
        return array_values(array_filter(is_array($v) ? $v : (is_string($v) ? array_map('trim', explode(',', $v)) : [])));
    }

    public function arg(int $i, ?string $default = null): ?string
    {
        return $this->pos[$i] ?? $default;
    }
}

<?php

declare(strict_types=1);

namespace SlashMark\ComposerSuite;

use SlashMark\ComposerSuite\Commands as C;

final class Application
{
    public const NAME = 'Slash Mark Composer Suite';

    /** @var array<int, class-string<Command>> */
    public const COMMANDS = [
        // Discover
        C\SearchCommand::class,
        C\TrendingCommand::class,
        C\UpdatesCommand::class,
        C\VendorCommand::class,
        C\DependentsCommand::class,
        C\StatsCommand::class,
        // Package insight
        C\InfoCommand::class,
        C\VersionCommand::class,
        C\CompareCommand::class,
        C\MaintainersCommand::class,
        C\DocsCommand::class,
        C\SourceCommand::class,
        C\ChangelogCommand::class,
        C\HealthCommand::class,
        // Project analysis
        C\JsonCommand::class,
        C\TreeCommand::class,
        C\LockCommand::class,
        C\AutoloadCommand::class,
        C\SizeCommand::class,
        C\OutdatedCommand::class,
        C\ConflictsCommand::class,
        C\ConstraintCommand::class,
        // Security & compliance
        C\SecurityCommand::class,
        C\AbandonedCommand::class,
        C\LicenseCommand::class,
        C\LinksCommand::class,
        C\PhpCommand::class,
        C\FrameworkCommand::class,
        // Workflow
        C\CmdCommand::class,
        C\DashboardCommand::class,
        C\ExportCommand::class,
        C\DoctorCommand::class,
    ];

    private const GLOBAL_OPTS = ['json' => 'flag', 'no-color' => 'flag', 'no-cache' => 'flag', 'working-dir' => 'value', 'help' => 'flag', 'quiet' => 'flag', 'version' => 'flag', 'cache-ttl' => 'value', 'timeout' => 'value'];
    private const GLOBAL_SHORT = ['d' => 'working-dir', 'h' => 'help', 'q' => 'quiet', 'V' => 'version'];

    /** @return array<string, class-string<Command>> */
    public static function map(): array
    {
        $m = [];
        foreach (self::COMMANDS as $cls) {
            $m[$cls::NAME] = $cls;
            foreach ($cls::ALIASES as $al) {
                $m[$al] = $cls;
            }
        }
        return $m;
    }

    public function run(array $argv): int
    {
        array_shift($argv);
        $argv = array_values($argv);
        $cmdName = null;
        $valueGlobals = ['-d', '--working-dir', '--cache-ttl', '--timeout'];
        for ($i = 0, $n = count($argv); $i < $n; $i++) {
            $t = $argv[$i];
            if (in_array($t, $valueGlobals, true)) {
                $i++; // skip its value
                continue;
            }
            if ($t !== '' && $t[0] !== '-') {
                $cmdName = $t;
                array_splice($argv, $i, 1);
                break;
            }
        }

        $map = self::map();
        $cls = $cmdName !== null ? ($map[strtolower($cmdName)] ?? null) : null;
        $spec = self::GLOBAL_OPTS + ($cls ? $cls::OPTIONS : []);
        $short = self::GLOBAL_SHORT + ($cls ? $cls::SHORT : []);

        try {
            $args = Args::parse($argv, $spec, $short);
        } catch (\InvalidArgumentException $e) {
            Ui::init();
            Ui::err(Ui::c('red', 'Error: ') . $e->getMessage());
            return 2;
        }
        Ui::init($args->has('no-color'));
        Ui::$quiet = $args->has('quiet') || $args->has('json');
        Http::$useCache = !$args->has('no-cache');
        Http::$ttl = $args->int('cache-ttl', Http::$ttl);
        Http::$timeout = $args->int('timeout', Http::$timeout);

        if ($args->has('version')) {
            Ui::out(self::NAME . ' ' . Http::VERSION);
            return 0;
        }
        if ($cmdName === null || $cmdName === 'help' || $cmdName === 'list') {
            $topic = $args->arg(0);
            if ($topic !== null && isset($map[$topic])) {
                Ui::out($map[$topic]::helpText());
                return 0;
            }
            $this->help();
            return 0;
        }
        if ($cmdName === 'cache:clear' || $cmdName === 'clear-cache') {
            Ui::out(Ui::status('ok', 'Removed ' . Http::clearCache() . ' cached responses from ' . Http::cacheDir()));
            return 0;
        }
        if ($cls === null) {
            Ui::err(Ui::c('red', "Unknown command \"$cmdName\".") . $this->suggest($cmdName));
            Ui::err('Run ' . Ui::c('cyan', 'sm-composer help') . ' to see all 32 tools.');
            return 2;
        }
        if ($args->has('help')) {
            Ui::out($cls::helpText());
            return 0;
        }

        try {
            /** @var Command $cmd */
            $cmd = new $cls($args);
            return $cmd->run();
        } catch (UsageException $e) {
            Ui::err(Ui::c('red', 'Error: ') . $e->getMessage());
            Ui::err('Usage: sm-composer ' . $cls::NAME . ' ' . $cls::USAGE . Ui::c('gray', '   (sm-composer ' . $cls::NAME . ' --help)'));
            return 2;
        } catch (\InvalidArgumentException $e) {
            Ui::err(Ui::c('red', 'Error: ') . $e->getMessage());
            return 2;
        } catch (HttpException $e) {
            if ($e->status === 404) {
                Ui::err(Ui::c('red', 'Not found: ') . $e->getMessage());
                return 1;
            }
            Ui::err(Ui::c('red', 'Network error: ') . $e->getMessage());
            if ($e->status === 0) {
                Ui::err(Ui::c('gray', 'Check your internet connection or proxy (HTTPS_PROXY). Run `sm-composer doctor` for a full diagnosis.'));
            }
            return 3;
        } catch (\Throwable $e) {
            Ui::err(Ui::c('red', 'Error: ') . $e->getMessage());
            if (getenv('SM_COMPOSER_DEBUG')) {
                Ui::err($e->getTraceAsString());
            }
            return 3;
        }
    }

    private function suggest(string $name): string
    {
        $best = null;
        $bestD = 99;
        foreach (array_keys(self::map()) as $k) {
            $d = levenshtein($name, $k);
            if ($d < $bestD) {
                [$best, $bestD] = [$k, $d];
            }
        }
        return $best !== null && $bestD <= 3 ? ' Did you mean ' . Ui::c('cyan', $best) . '?' : '';
    }

    private function help(): void
    {
        Ui::out(Ui::c('bold.cyan', '  ╭─ ' . self::NAME) . Ui::c('gray', ' v' . Http::VERSION));
        Ui::out(Ui::c('bold.cyan', '  ╰─ ') . 'PHP Package Intelligence Suite — explore, analyze and manage Composer packages');
        Ui::out();
        Ui::out(Ui::c('bold', 'USAGE') . '  sm-composer <tool> [arguments] [options]');
        $groups = [];
        foreach (self::COMMANDS as $cls) {
            $groups[$cls::CATEGORY][] = $cls;
        }
        foreach ($groups as $cat => $list) {
            Ui::out();
            Ui::out(Ui::c('bold.magenta', strtoupper($cat)));
            foreach ($list as $cls) {
                Ui::out('  ' . Ui::c('cyan', str_pad($cls::NAME, 12)) . Ui::pad($cls::TITLE, 34) . Ui::c('gray', $cls::SUMMARY));
            }
        }
        Ui::out();
        Ui::out(Ui::c('bold', 'GLOBAL OPTIONS'));
        Ui::out('  --json             JSON output for scripts and CI');
        Ui::out('  -d, --working-dir  Project directory (default: current)');
        Ui::out('  --no-cache         Skip the 10-minute response cache   --cache-ttl <sec>');
        Ui::out('  --no-color, -q     Plain output / quiet mode            --timeout <sec>');
        Ui::out();
        Ui::out(Ui::c('gray', '  sm-composer <tool> --help   detailed help for one tool'));
        Ui::out(Ui::c('gray', '  sm-composer cache:clear     clear cached API responses'));
        Ui::out(Ui::c('gray', '  Exit codes: 0 ok · 1 issues found · 2 usage error · 3 network/runtime error'));
    }
}

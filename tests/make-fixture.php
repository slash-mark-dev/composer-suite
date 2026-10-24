<?php

declare(strict_types=1);

/**
 * Builds a sample project (composer.json, composer.lock, vendor/, src/) in the given folder,
 * with deliberate problems for the tools to find. Usage: php tests/make-fixture.php <dir>
 */

spl_autoload_register(static function (string $c): void {
    $p = 'SlashMark\\ComposerSuite\\';
    if (str_starts_with($c, $p)) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
    }
});

$dir = $argv[1] ?? throw new RuntimeException('usage: make-fixture.php <dir>');
$data = require __DIR__ . '/mock/data.php';
@mkdir($dir, 0775, true);

$composer = [
    'name' => 'slashmark/demo-app',
    'description' => 'Demo project used to exercise the Composer Suite',
    'type' => 'project',
    'license' => 'proprietary',
    'require' => [
        'php' => '^8.1',
        'ext-json' => '*',
        'ext-mbstring' => '*',
        'monolog/monolog' => '^3.5',
        'guzzlehttp/guzzle' => '^7.8',
        'firebase/php-jwt' => '^5.5',
        'swiftmailer/swiftmailer' => '^6.2',
        'acme/gpl-lib' => '^1.2',
        'laravel/framework' => '^10.0',
        'spatie/laravel-permission' => '^5.11',
    ],
    'require-dev' => [
        'phpunit/phpunit' => '^10.5',
        'fzaninotto/faker' => '^1.9',
    ],
    'autoload' => [
        'psr-4' => ['App\\' => 'src/'],
        'files' => ['src/helpers.php'],
    ],
    'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
    'config' => ['sort-packages' => true],
];

$pick = [
    'monolog/monolog' => '3.5.0', 'psr/log' => '3.0.2', 'guzzlehttp/guzzle' => '7.8.1', 'guzzlehttp/psr7' => '2.4.4',
    'guzzlehttp/promises' => '1.5.3', 'psr/http-message' => '1.1', 'psr/http-client' => '1.0.3', 'firebase/php-jwt' => '5.5.1',
    'swiftmailer/swiftmailer' => 'v6.3.0', 'acme/gpl-lib' => '1.2.0', 'symfony/polyfill-mbstring' => '1.27.0',
    'laravel/framework' => 'v10.48.0', 'symfony/console' => '6.4.12', 'symfony/string' => '6.4.12', 'spatie/laravel-permission' => '5.11.1',
];
$pickDev = ['phpunit/phpunit' => '10.5.17', 'fzaninotto/faker' => 'v1.9.2'];

$entry = static function (string $name, string $ver) use ($data): array {
    $p = $data[$name];
    $info = $p['versions'][$ver];
    $ref = substr(sha1($name . $ver), 0, 40);
    [$o, $r] = explode('/', str_replace('https://github.com/', '', $p['repo']));
    $e = [
        'name' => $name, 'version' => $ver,
        'source' => ['type' => 'git', 'url' => $p['repo'] . '.git', 'reference' => $ref],
        'dist' => ['type' => 'zip', 'url' => "https://api.github.com/repos/$o/$r/zipball/$ref", 'reference' => $ref, 'shasum' => ''],
        'require' => $info['require'],
        'type' => $p['type'],
        'autoload' => $p['autoload'],
        'notification-url' => 'https://packagist.org/downloads/',
        'license' => $p['license'],
        'authors' => $p['authors'],
        'description' => $p['description'],
        'homepage' => $p['homepage'],
        'keywords' => $p['keywords'],
        'support' => ['issues' => $p['repo'] . '/issues', 'source' => $p['repo'] . '/tree/' . $ver],
        'time' => $info['time'],
    ];
    if ($name === 'acme/gpl-lib') {
        $e['support']['docs'] = 'http://127.0.0.1:8765/gone';
        $e['homepage'] = 'http://127.0.0.1:8765/gone';
    } elseif ($name === 'monolog/monolog') {
        $e['homepage'] = 'http://127.0.0.1:8765/ok';
        unset($e['support']);
    } else {
        unset($e['support'], $e['homepage']); // keep the link check local and fast
    }
    if (isset($p['abandoned'])) {
        $e['abandoned'] = $p['abandoned'];
    }
    if ($name === 'laravel/framework') {
        $e['replace'] = ['illuminate/auth' => 'self.version', 'illuminate/container' => 'self.version', 'illuminate/database' => 'self.version', 'illuminate/support' => 'self.version'];
    }
    return $e;
};

$lock = ['_readme' => ['This file locks the dependencies of your project to a known state'], 'content-hash' => '', 'packages' => [], 'packages-dev' => []];
foreach ($pick as $n => $v) {
    $lock['packages'][] = $entry($n, $v);
}
foreach ($pickDev as $n => $v) {
    $lock['packages-dev'][] = $entry($n, $v);
}
$lock += ['aliases' => [], 'minimum-stability' => 'stable', 'stability-flags' => [], 'prefer-stable' => false, 'prefer-lowest' => false,
    'platform' => ['php' => '^8.1', 'ext-json' => '*', 'ext-mbstring' => '*'], 'platform-dev' => [], 'plugin-api-version' => '2.6.0'];

file_put_contents("$dir/composer.json", json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$p = SlashMark\ComposerSuite\Project::load($dir);
$lock['content-hash'] = $p->contentHash();
file_put_contents("$dir/composer.lock", json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// vendor/ — sizes roughly proportional, with one version mismatch
$installed = ['packages' => [], 'dev' => true, 'dev-package-names' => array_keys($pickDev)];
foreach (array_merge($pick, $pickDev) as $n => $v) {
    $base = "$dir/vendor/$n/src";
    @mkdir($base, 0775, true);
    $kb = (strlen($n) * 37) % 400 + 20;
    file_put_contents("$base/Main.php", "<?php\n// " . str_repeat('x', $kb * 1024) . "\n");
    file_put_contents("$dir/vendor/$n/README.md", "# $n\n\nInstalled copy of **$n** $v.\n\n## Usage\n\n```php\n// ...\n```\n");
    file_put_contents("$dir/vendor/$n/composer.json", json_encode(['name' => $n, 'autoload' => $data[$n]['autoload']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $installed['packages'][] = ['name' => $n, 'version' => $n === 'guzzlehttp/promises' ? '1.5.2' : $v];
}
@mkdir("$dir/vendor/composer", 0775, true);
file_put_contents("$dir/vendor/composer/installed.json", json_encode($installed, JSON_PRETTY_PRINT));
file_put_contents("$dir/vendor/autoload.php", "<?php\n");

// application code — one class in the wrong namespace on purpose
@mkdir("$dir/src/Models", 0775, true);
@mkdir("$dir/src/Services", 0775, true);
@mkdir("$dir/tests", 0775, true);
file_put_contents("$dir/src/Models/User.php", "<?php\n\nnamespace App\\Models;\n\nfinal class User\n{\n    public function name(): string { return User::class; }\n}\n");
file_put_contents("$dir/src/Services/Mailer.php", "<?php\n\nnamespace App\\Service;\n\nclass Mailer\n{\n}\n");
file_put_contents("$dir/src/Services/Report.php", "<?php\n\nnamespace App\\Services;\n\nenum Report: string\n{\n    case Daily = 'daily';\n}\n");
file_put_contents("$dir/src/helpers.php", "<?php\n\nfunction app_name(): string { return 'demo'; }\n\$x = new class {};\n");
file_put_contents("$dir/tests/UserTest.php", "<?php\n\nnamespace Tests;\n\nfinal class UserTest\n{\n}\n");
echo "fixture written to $dir\n";

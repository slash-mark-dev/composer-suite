<?php

declare(strict_types=1);

/**
 * Fixture registry used by the local mock of Packagist/GitHub (tests only).
 * Shapes mirror the real API responses; numbers are illustrative.
 */

function v(string $time, array $require = [], array $extra = []): array
{
    return ['time' => $time, 'require' => $require] + $extra;
}

return [
    'monolog/monolog' => [
        'description' => 'Sends your logs to files, sockets, inboxes, databases and various web services',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['log', 'logging', 'psr-3'],
        'repo' => 'https://github.com/Seldaek/monolog', 'homepage' => 'https://github.com/Seldaek/monolog',
        'downloads' => [905000000, 14200000, 512000], 'favers' => 21100, 'stars' => 21100, 'issues' => 41, 'dependents' => 15800,
        'maintainers' => ['seldaek'], 'authors' => [['name' => 'Jordi Boggiano', 'email' => 'j.boggiano@seld.be', 'homepage' => 'https://seld.be']],
        'autoload' => ['psr-4' => ['Monolog\\' => 'src/Monolog']],
        'versions' => [
            '3.8.1' => v('2026-08-14T10:00:00+00:00', ['php' => '>=8.1', 'psr/log' => '^2.0 || ^3.0'], ['require-dev' => ['phpunit/phpunit' => '^10.5.17']]),
            '3.8.0' => v('2026-05-02T10:00:00+00:00', ['php' => '>=8.1', 'psr/log' => '^2.0 || ^3.0']),
            '3.5.0' => v('2025-10-10T10:00:00+00:00', ['php' => '>=8.1', 'psr/log' => '^2.0 || ^3.0']),
            '3.0.0' => v('2022-05-10T10:00:00+00:00', ['php' => '>=8.1', 'psr/log' => '^2.0 || ^3.0']),
            '2.10.0' => v('2024-11-12T10:00:00+00:00', ['php' => '>=7.2', 'psr/log' => '^1.0.1 || ^2.0 || ^3.0']),
            '2.9.1' => v('2023-02-06T10:00:00+00:00', ['php' => '>=7.2', 'psr/log' => '^1.0.1 || ^2.0 || ^3.0']),
            '1.27.1' => v('2022-06-09T10:00:00+00:00', ['php' => '>=7.0', 'psr/log' => '~1.0']),
            '4.0.0-beta1' => v('2026-10-01T10:00:00+00:00', ['php' => '>=8.2', 'psr/log' => '^3.0']),
        ],
    ],
    'psr/log' => [
        'description' => 'Common interface for logging libraries',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['psr', 'psr-3', 'log'],
        'repo' => 'https://github.com/php-fig/log', 'homepage' => 'https://github.com/php-fig/log',
        'downloads' => [1110000000, 15000000, 520000], 'favers' => 10400, 'stars' => 10400, 'issues' => 3, 'dependents' => 9800,
        'maintainers' => ['php-fig'], 'authors' => [['name' => 'PHP-FIG', 'homepage' => 'https://www.php-fig.org/']],
        'autoload' => ['psr-4' => ['Psr\\Log\\' => 'src']],
        'versions' => [
            '3.0.2' => v('2024-09-11T13:17:53+00:00', ['php' => '>=8.0.0']),
            '3.0.0' => v('2021-07-14T16:46:02+00:00', ['php' => '>=8.0.0']),
            '2.0.0' => v('2021-07-14T16:41:46+00:00', ['php' => '>=8.0.0']),
            '1.1.4' => v('2021-05-03T11:20:27+00:00', ['php' => '>=5.3.0']),
        ],
    ],
    'symfony/console' => [
        'description' => 'Eases the creation of beautiful and testable command line interfaces',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['console', 'cli', 'terminal'],
        'repo' => 'https://github.com/symfony/console', 'homepage' => 'https://symfony.com',
        'downloads' => [800000000, 13000000, 470000], 'favers' => 9700, 'stars' => 9700, 'issues' => 0, 'dependents' => 12000,
        'maintainers' => ['fabpot'], 'authors' => [['name' => 'Fabien Potencier', 'email' => 'fabien@symfony.com'], ['name' => 'Symfony Community', 'homepage' => 'https://symfony.com/contributors']],
        'autoload' => ['psr-4' => ['Symfony\\Component\\Console\\' => '']],
        'versions' => [
            '7.3.4' => v('2026-09-28T10:00:00+00:00', ['php' => '>=8.2', 'symfony/polyfill-mbstring' => '~1.0', 'symfony/string' => '^7.2']),
            '7.1.0' => v('2025-05-31T10:00:00+00:00', ['php' => '>=8.2', 'symfony/polyfill-mbstring' => '~1.0', 'symfony/string' => '^6.4|^7.0']),
            '6.4.12' => v('2026-02-20T10:00:00+00:00', ['php' => '>=8.1', 'symfony/polyfill-mbstring' => '~1.0', 'symfony/string' => '^5.4|^6.0|^7.0']),
            '5.4.40' => v('2024-11-01T10:00:00+00:00', ['php' => '>=7.2.5', 'symfony/polyfill-mbstring' => '~1.0', 'symfony/string' => '^5.1|^6.0']),
        ],
    ],
    'symfony/string' => [
        'description' => 'Provides an object-oriented API to strings and deals with bytes, UTF-8 code points and grapheme clusters',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['string', 'utf8', 'unicode'],
        'repo' => 'https://github.com/symfony/string', 'homepage' => 'https://symfony.com',
        'downloads' => [520000000, 11000000, 400000], 'favers' => 1700, 'stars' => 1700, 'issues' => 0, 'dependents' => 900,
        'maintainers' => ['fabpot'], 'authors' => [['name' => 'Nicolas Grekas', 'email' => 'p@tchwork.com']],
        'autoload' => ['psr-4' => ['Symfony\\Component\\String\\' => '']],
        'versions' => [
            '7.3.4' => v('2026-09-11T10:00:00+00:00', ['php' => '>=8.2', 'symfony/polyfill-mbstring' => '~1.0']),
            '7.1.0' => v('2025-05-31T10:00:00+00:00', ['php' => '>=8.2', 'symfony/polyfill-mbstring' => '~1.0']),
            '6.4.12' => v('2026-02-20T10:00:00+00:00', ['php' => '>=8.1', 'symfony/polyfill-mbstring' => '~1.0']),
        ],
    ],
    'symfony/polyfill-mbstring' => [
        'description' => 'Symfony polyfill for the Mbstring extension',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['polyfill', 'mbstring', 'shim'],
        'repo' => 'https://github.com/symfony/polyfill-mbstring', 'homepage' => 'https://symfony.com',
        'downloads' => [1030000000, 16000000, 560000], 'favers' => 7800, 'stars' => 7800, 'issues' => 0, 'dependents' => 2100,
        'maintainers' => ['fabpot'], 'authors' => [['name' => 'Nicolas Grekas', 'email' => 'p@tchwork.com']],
        'autoload' => ['psr-4' => ['Symfony\\Polyfill\\Mbstring\\' => ''], 'files' => ['bootstrap.php']],
        'versions' => [
            '1.33.0' => v('2026-06-24T10:00:00+00:00', ['php' => '>=7.2']),
            '1.31.0' => v('2025-09-09T10:00:00+00:00', ['php' => '>=7.2']),
            '1.27.0' => v('2022-11-03T10:00:00+00:00', ['php' => '>=7.1']),
        ],
    ],
    'guzzlehttp/guzzle' => [
        'description' => 'Guzzle is a PHP HTTP client library',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['http', 'client', 'curl', 'psr-18'],
        'repo' => 'https://github.com/guzzle/guzzle', 'homepage' => 'https://docs.guzzlephp.org',
        'downloads' => [880000000, 12500000, 450000], 'favers' => 23300, 'stars' => 23300, 'issues' => 31, 'dependents' => 16500,
        'maintainers' => ['mtdowling', 'GrahamCampbell', 'Nyholm'], 'authors' => [['name' => 'Graham Campbell', 'email' => 'hello@gjcampbell.co.uk'], ['name' => 'Michael Dowling', 'email' => 'mtdowling@gmail.com']],
        'autoload' => ['psr-4' => ['GuzzleHttp\\' => 'src/'], 'files' => ['src/functions_include.php']],
        'versions' => [
            '7.10.0' => v('2026-08-23T10:00:00+00:00', ['php' => '^7.2.5 || ^8.0', 'guzzlehttp/promises' => '^1.5.3 || ^2.0.3', 'guzzlehttp/psr7' => '^2.7.0', 'psr/http-client' => '^1.0', 'ext-json' => '*']),
            '7.8.1' => v('2023-12-03T10:00:00+00:00', ['php' => '^7.2.5 || ^8.0', 'guzzlehttp/promises' => '^1.5.3 || ^2.0.1', 'guzzlehttp/psr7' => '^1.9.1 || ^2.5.1', 'psr/http-client' => '^1.0', 'ext-json' => '*']),
            '7.4.4' => v('2022-06-09T10:00:00+00:00', ['php' => '^7.2.5 || ^8.0', 'guzzlehttp/promises' => '^1.5', 'guzzlehttp/psr7' => '^1.9 || ^2.4', 'psr/http-client' => '^1.0', 'ext-json' => '*']),
            '6.5.8' => v('2022-06-20T10:00:00+00:00', ['php' => '>=5.5', 'guzzlehttp/promises' => '^1.0', 'guzzlehttp/psr7' => '^1.9', 'ext-json' => '*']),
        ],
    ],
    'guzzlehttp/psr7' => [
        'description' => 'PSR-7 message implementation that also provides common utility methods',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['http', 'psr-7', 'message', 'stream'],
        'repo' => 'https://github.com/guzzle/psr7', 'homepage' => 'https://github.com/guzzle/psr7',
        'downloads' => [830000000, 12800000, 455000], 'favers' => 7900, 'stars' => 7900, 'issues' => 12, 'dependents' => 2800,
        'maintainers' => ['GrahamCampbell'], 'authors' => [['name' => 'Tobias Nyholm', 'email' => 'tobias.nyholm@gmail.com']],
        'autoload' => ['psr-4' => ['GuzzleHttp\\Psr7\\' => 'src/']],
        'versions' => [
            '2.8.0' => v('2026-08-23T10:00:00+00:00', ['php' => '^7.2.5 || ^8.0', 'psr/http-message' => '^1.1 || ^2.0']),
            '2.4.4' => v('2023-03-09T10:00:00+00:00', ['php' => '^7.2.5 || ^8.0', 'psr/http-message' => '^1.0']),
            '1.9.0' => v('2022-06-20T10:00:00+00:00', ['php' => '>=5.4.0', 'psr/http-message' => '~1.0']),
        ],
    ],
    'guzzlehttp/promises' => [
        'description' => 'Guzzle promises library',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['promise'],
        'repo' => 'https://github.com/guzzle/promises', 'homepage' => 'https://github.com/guzzle/promises',
        'downloads' => [800000000, 12000000, 440000], 'favers' => 7700, 'stars' => 7700, 'issues' => 2, 'dependents' => 700,
        'maintainers' => ['GrahamCampbell'], 'authors' => [['name' => 'Graham Campbell']],
        'autoload' => ['psr-4' => ['GuzzleHttp\\Promise\\' => 'src/']],
        'versions' => [
            '2.3.0' => v('2026-08-22T10:00:00+00:00', ['php' => '^7.2.5 || ^8.0']),
            '1.5.3' => v('2023-05-21T10:00:00+00:00', ['php' => '>=5.5']),
        ],
    ],
    'psr/http-message' => [
        'description' => 'Common interface for HTTP messages',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['psr', 'psr-7', 'http'],
        'repo' => 'https://github.com/php-fig/http-message', 'homepage' => 'https://github.com/php-fig/http-message',
        'downloads' => [900000000, 13000000, 450000], 'favers' => 7000, 'stars' => 7000, 'issues' => 1, 'dependents' => 5300,
        'maintainers' => ['php-fig'], 'authors' => [['name' => 'PHP-FIG']],
        'autoload' => ['psr-4' => ['Psr\\Http\\Message\\' => 'src/']],
        'versions' => [
            '2.0' => v('2023-04-04T09:54:51+00:00', ['php' => '^7.2 || ^8.0']),
            '1.1' => v('2023-04-04T09:50:52+00:00', ['php' => '^7.2 || ^8.0']),
            '1.0.1' => v('2016-08-06T14:39:51+00:00', ['php' => '>=5.3.0']),
        ],
    ],
    'psr/http-client' => [
        'description' => 'Common interface for HTTP clients',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['psr', 'psr-18', 'http'],
        'repo' => 'https://github.com/php-fig/http-client', 'homepage' => 'https://github.com/php-fig/http-client',
        'downloads' => [500000000, 11000000, 400000], 'favers' => 1700, 'stars' => 1700, 'issues' => 0, 'dependents' => 2500,
        'maintainers' => ['php-fig'], 'authors' => [['name' => 'PHP-FIG']],
        'autoload' => ['psr-4' => ['Psr\\Http\\Client\\' => 'src/']],
        'versions' => [
            '1.0.3' => v('2023-09-23T14:17:50+00:00', ['php' => '^7.0 || ^8.0', 'psr/http-message' => '^1.0 || ^2.0']),
            '1.0.1' => v('2020-06-29T06:28:15+00:00', ['php' => '^7.0 || ^8.0', 'psr/http-message' => '^1.0']),
        ],
    ],
    'firebase/php-jwt' => [
        'description' => 'A simple library to encode and decode JSON Web Tokens (JWT) in PHP. Should conform to the current spec.',
        'type' => 'library', 'license' => ['BSD-3-Clause'], 'keywords' => ['jwt', 'php'],
        'repo' => 'https://github.com/firebase/php-jwt', 'homepage' => 'https://github.com/firebase/php-jwt',
        'downloads' => [330000000, 7200000, 260000], 'favers' => 9500, 'stars' => 9500, 'issues' => 18, 'dependents' => 2400,
        'maintainers' => ['bshaffer'], 'authors' => [['name' => 'Neuman Vong', 'email' => 'neuman+pear@twilio.com', 'role' => 'Developer'], ['name' => 'Anant Narayanan', 'email' => 'anant@php.net', 'role' => 'Developer']],
        'autoload' => ['psr-4' => ['Firebase\\JWT\\' => 'src']],
        'versions' => [
            '6.11.1' => v('2026-04-09T10:00:00+00:00', ['php' => '^8.0']),
            '6.4.0' => v('2023-02-09T10:00:00+00:00', ['php' => '^7.1||^8.0']),
            '5.5.1' => v('2021-11-08T20:18:51+00:00', ['php' => '>=5.3.0']),
        ],
    ],
    'swiftmailer/swiftmailer' => [
        'description' => 'Swiftmailer, free feature-rich PHP mailer',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['mail', 'mailer', 'email'],
        'repo' => 'https://github.com/swiftmailer/swiftmailer', 'homepage' => 'https://swiftmailer.symfony.com',
        'downloads' => [210000000, 900000, 30000], 'favers' => 9600, 'stars' => 9600, 'issues' => 190, 'dependents' => 2900,
        'maintainers' => ['fabpot'], 'authors' => [['name' => 'Chris Corbyn'], ['name' => 'Fabien Potencier', 'email' => 'fabien@symfony.com']],
        'autoload' => ['files' => ['lib/swift_required.php']],
        'abandoned' => 'symfony/mailer',
        'versions' => [
            'v6.3.0' => v('2021-10-18T15:26:12+00:00', ['php' => '>=7.0.0']),
            'v6.2.7' => v('2021-03-09T12:30:35+00:00', ['php' => '>=7.0.0']),
        ],
    ],
    'fzaninotto/faker' => [
        'description' => 'Faker is a PHP library that generates fake data for you.',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['faker', 'fixtures', 'data'],
        'repo' => 'https://github.com/fzaninotto/Faker', 'homepage' => '',
        'downloads' => [140000000, 600000, 20000], 'favers' => 26000, 'stars' => 26000, 'issues' => 0, 'dependents' => 6000,
        'maintainers' => ['fzaninotto'], 'authors' => [['name' => 'François Zaninotto']],
        'autoload' => ['psr-4' => ['Faker\\' => 'src/Faker/']],
        'abandoned' => true,
        'versions' => [
            'v1.9.2' => v('2020-12-11T09:56:16+00:00', ['php' => '^5.3.3 || ^7.0 || ^8.0']),
            'v1.9.1' => v('2019-12-12T13:22:17+00:00', ['php' => '^5.3.3 || ^7.0']),
        ],
    ],
    'laravel/framework' => [
        'description' => 'The Laravel Framework.',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['framework', 'laravel'],
        'repo' => 'https://github.com/laravel/framework', 'homepage' => 'https://laravel.com',
        'downloads' => [470000000, 9000000, 330000], 'favers' => 34000, 'stars' => 34000, 'issues' => 6, 'dependents' => 26000,
        'maintainers' => ['taylorotwell'], 'authors' => [['name' => 'Taylor Otwell', 'email' => 'taylor@laravel.com']],
        'autoload' => ['psr-4' => ['Illuminate\\' => 'src/Illuminate/']],
        'versions' => [
            'v12.30.0' => v('2026-10-14T10:00:00+00:00', ['php' => '^8.2', 'monolog/monolog' => '^3.0', 'symfony/console' => '^7.2', 'guzzlehttp/guzzle' => '^7.8.2', 'psr/log' => '^1.0|^2.0|^3.0']),
            'v11.45.0' => v('2026-03-10T10:00:00+00:00', ['php' => '^8.2', 'monolog/monolog' => '^3.0', 'symfony/console' => '^7.0', 'guzzlehttp/guzzle' => '^7.8', 'psr/log' => '^1.0|^2.0|^3.0']),
            'v10.48.0' => v('2025-02-10T10:00:00+00:00', ['php' => '^8.1', 'monolog/monolog' => '^3.0', 'symfony/console' => '^6.2', 'guzzlehttp/guzzle' => '^7.5', 'psr/log' => '^1.0|^2.0|^3.0']),
            'v9.52.16' => v('2024-05-22T10:00:00+00:00', ['php' => '^8.0.2', 'monolog/monolog' => '^2.0', 'symfony/console' => '^6.0.9', 'psr/log' => '^1.0|^2.0|^3.0']),
        ],
    ],
    'spatie/laravel-permission' => [
        'description' => 'Permission handling for Laravel 8.0 and up',
        'type' => 'library', 'license' => ['MIT'], 'keywords' => ['laravel', 'permission', 'acl', 'roles'],
        'repo' => 'https://github.com/spatie/laravel-permission', 'homepage' => 'https://github.com/spatie/laravel-permission',
        'downloads' => [80000000, 2200000, 80000], 'favers' => 12300, 'stars' => 12300, 'issues' => 4, 'dependents' => 900,
        'maintainers' => ['spatie', 'freekmurze'], 'authors' => [['name' => 'Freek Van der Herten', 'email' => 'freek@spatie.be', 'homepage' => 'https://spatie.be', 'role' => 'Developer']],
        'autoload' => ['psr-4' => ['Spatie\\Permission\\' => 'src']],
        'versions' => [
            '6.21.0' => v('2026-07-23T10:00:00+00:00', ['php' => '^8.0', 'illuminate/auth' => '^8.12|^9.0|^10.0|^11.0|^12.0', 'illuminate/container' => '^8.12|^9.0|^10.0|^11.0|^12.0', 'illuminate/database' => '^8.12|^9.0|^10.0|^11.0|^12.0']),
            '5.11.1' => v('2023-10-25T10:00:00+00:00', ['php' => '^7.3|^8.0', 'illuminate/auth' => '^7.0|^8.0|^9.0|^10.0', 'illuminate/database' => '^7.0|^8.0|^9.0|^10.0']),
        ],
    ],
    'phpunit/phpunit' => [
        'description' => 'The PHP Unit Testing framework.',
        'type' => 'library', 'license' => ['BSD-3-Clause'], 'keywords' => ['phpunit', 'testing', 'xunit'],
        'repo' => 'https://github.com/sebastianbergmann/phpunit', 'homepage' => 'https://phpunit.de/',
        'downloads' => [880000000, 11000000, 400000], 'favers' => 19800, 'stars' => 19800, 'issues' => 24, 'dependents' => 140000,
        'maintainers' => ['sebastian'], 'authors' => [['name' => 'Sebastian Bergmann', 'email' => 'sebastian@phpunit.de', 'role' => 'lead']],
        'autoload' => ['classmap' => ['src/']],
        'versions' => [
            '12.4.0' => v('2026-10-03T10:00:00+00:00', ['php' => '>=8.3', 'ext-dom' => '*', 'ext-json' => '*', 'ext-mbstring' => '*']),
            '11.5.40' => v('2026-09-12T10:00:00+00:00', ['php' => '>=8.2', 'ext-dom' => '*', 'ext-json' => '*']),
            '10.5.17' => v('2024-04-05T10:00:00+00:00', ['php' => '>=8.1', 'ext-dom' => '*', 'ext-json' => '*']),
            '9.6.20' => v('2024-07-10T10:00:00+00:00', ['php' => '>=7.3', 'ext-dom' => '*', 'ext-json' => '*']),
        ],
    ],
    'acme/gpl-lib' => [
        'description' => 'Example GPL licensed helper library',
        'type' => 'library', 'license' => ['GPL-3.0-or-later'], 'keywords' => ['helper'],
        'repo' => 'https://github.com/acme/gpl-lib', 'homepage' => 'https://acme.example/broken-docs',
        'downloads' => [12000, 300, 10], 'favers' => 12, 'stars' => 12, 'issues' => 9, 'dependents' => 2,
        'maintainers' => ['acme'], 'authors' => [['name' => 'Acme Dev']],
        'autoload' => ['psr-4' => ['Acme\\Gpl\\' => 'src']],
        'versions' => [
            '1.2.0' => v('2023-01-10T10:00:00+00:00', ['php' => '>=7.4', 'psr/log' => '^1.0']),
            '1.0.0' => v('2021-01-10T10:00:00+00:00', ['php' => '>=7.2', 'psr/log' => '^1.0']),
        ],
    ],
];

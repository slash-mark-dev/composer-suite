<?php

declare(strict_types=1);

/**
 * Zero-dependency test runner.
 *   php tests/run.php                       unit tests
 *   COMPOSER_PHAR=/path/composer.phar php tests/run.php   + differential test against Composer's own composer/semver
 */

spl_autoload_register(static function (string $c): void {
    $p = 'SlashMark\\ComposerSuite\\';
    if (str_starts_with($c, $p)) {
        $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});

use SlashMark\ComposerSuite\Semver;
use SlashMark\ComposerSuite\Packagist;
use SlashMark\ComposerSuite\Commands\LicenseCommand;
use SlashMark\ComposerSuite\Commands\SecurityCommand;

$pass = 0;
$fail = 0;
function check(string $label, mixed $got, mixed $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
    }
}

// --- normalisation
foreach ([
    '1.2.3' => '1.2.3.0', 'v1.2' => '1.2.0.0', '2' => '2.0.0.0', '1.0.0-beta2' => '1.0.0.0-beta2', '1.0.0-b2' => '1.0.0.0-beta2',
    '1.0.0RC1' => '1.0.0.0-RC1', '1.0.0-alpha' => '1.0.0.0-alpha', '1.0.0-dev' => '1.0.0.0-dev', 'dev-main' => 'dev-main',
    'master' => 'dev-master', '2.x-dev' => '2.9999999.9999999.9999999-dev', '1.2.x-dev' => '1.2.9999999.9999999-dev', 'v6.3.0' => '6.3.0.0',
    '1.0.0-patch1' => '1.0.0.0-pl1', 'not-a-version' => null,
] as $in => $want) {
    check("normalize($in)", Semver::normalize((string) $in), $want);
}

// --- constraint matching (expectations follow Composer semantics)
$cases = [
    ['^1.2.3', '1.2.3', true], ['^1.2.3', '1.9.0', true], ['^1.2.3', '2.0.0', false], ['^1.2.3', '1.2.2', false],
    ['^1.2', '2.0.0-beta1', false], ['^1.2', '1.3.0-beta1', true],
    ['^0.3.2', '0.3.9', true], ['^0.3.2', '0.4.0', false], ['^0.0.3', '0.0.4', false], ['^0.0.3', '0.0.3', true],
    ['~1.2', '1.9.9', true], ['~1.2', '2.0.0', false], ['~1.2.3', '1.2.9', true], ['~1.2.3', '1.3.0', false], ['~1', '1.5', true],
    ['1.2.*', '1.2.7', true], ['1.2.*', '1.3.0', false], ['1.*', '1.99', true], ['*', '9.9.9', true],
    ['>=1.0 <2.0', '1.5', true], ['>=1.0 <2.0', '2.0', false], ['>=1.0,<2.0', '1.0.0', true], ['>= 1.0', '1.0', true],
    ['1.0 - 2.0', '2.0.5', true], ['1.0 - 2.0', '2.1.0', false], ['1.0.0 - 2.1.0', '2.1.0', true], ['1.0.0 - 2.1.0', '2.1.1', false],
    ['^1.0 || ^2.0', '2.5', true], ['^1.0 || ^2.0', '3.0', false], ['^1.0|^2.0', '1.1', true],
    ['^7.2.5 || ^8.0', '8.3.6', true], ['^7.2.5 || ^8.0', '7.2.4', false],
    ['1.2.3', '1.2.3', true], ['1.2.3', '1.2.4', false], ['!=1.2.3', '1.2.4', true], ['<1.2.3', '1.2.3-beta', false],
    ['dev-main', 'dev-main', true], ['^1.0', 'dev-main', false], ['^2.0@beta', '2.1.0-beta1', true],
    ['>=2,<2.4.5|<1.9.1', '2.4.4', true], ['>=2,<2.4.5|<1.9.1', '2.4.5', false], ['>=2,<2.4.5|<1.9.1', '1.9.0', true],
    ['>=8.1', '8.4.99', true], ['^8.2', '8.1.99', false], ['>=7.2.5', '7.2.5', true],
];
foreach ($cases as [$c, $v, $want]) {
    check("satisfies($v, $c)", Semver::satisfies($v, $c), $want);
}
check('invalid constraint', Semver::isValid('^^1'), false);
check('explain ^1.2', Semver::explain('^1.2'), '>=1.2.0 <2.0.0');
check('explain ~1.2.3', Semver::explain('~1.2.3'), '>=1.2.3 <1.3.0');

// --- diff / latest / suggest
check('diff patch', Semver::diff('1.2.3', '1.2.9'), 'patch');
check('diff minor', Semver::diff('1.2.3', '1.4.0'), 'minor');
check('diff major', Semver::diff('1.2.3', '2.0.0'), 'major');
check('diff 0.x minor is major', Semver::diff('0.3.0', '0.4.0'), 'major');
check('latest stable', Semver::latest(['1.0.0', '2.0.0-beta1', '1.5.0', 'dev-main']), '1.5.0');
check('latest pre', Semver::latest(['1.0.0', '2.0.0-beta1'], false), '2.0.0-beta1');
check('suggest', Semver::suggest('v3.8.1'), '^3.8');
check('suggest 0.x', Semver::suggest('0.4.2'), '^0.4.2');
check('stability RC', Semver::stability('1.0.0-RC2'), 'RC');

// --- Packagist helpers
check('platform php', Packagist::isPlatform('php'), true);
check('platform ext', Packagist::isPlatform('ext-mbstring'), true);
check('platform pkg', Packagist::isPlatform('psr/log'), false);
check('valid name', Packagist::validName('symfony/polyfill-mbstring'), true);
check('invalid name', Packagist::validName('Not A Name'), false);
check('github', Packagist::github('https://github.com/Seldaek/monolog.git'), ['Seldaek', 'monolog']);
$exp = Packagist::expand([['version' => '2.0', 'require' => ['a' => '1'], 'x' => 1], ['version' => '1.0', 'x' => '__unset']]);
check('expand inherits', $exp[1]['require'], ['a' => '1']);
check('expand unset', isset($exp[1]['x']), false);

// --- licenses
check('license MIT', LicenseCommand::classify('MIT'), 'permissive');
check('license GPL', LicenseCommand::classify('GPL-3.0-or-later'), 'strong-copyleft');
check('license LGPL', LicenseCommand::classify('LGPL-2.1-only'), 'weak-copyleft');
check('license OR picks friendliest', LicenseCommand::effective(['(GPL-2.0-only or MIT)'])['license'], 'MIT');

// --- FriendsOfPHP YAML
$y = SecurityCommand::miniYaml("title:     'Sendmail transport RCE'\nlink:      https://example.com/x\ncve:       CVE-2016-10074\nbranches:\n    5.x:\n        time:     2016-12-29 00:00:00\n        versions: ['>=5.0.0', '<5.4.5']\n    4.x:\n        versions: ['<4.9']\nreference: composer://swiftmailer/swiftmailer\n");
check('yaml title', $y['title'] ?? null, 'Sendmail transport RCE');
check('yaml versions', $y['branches']['5.x']['versions'] ?? null, ['>=5.0.0', '<5.4.5']);
check('yaml second branch', $y['branches']['4.x']['versions'] ?? null, ['<4.9']);
check('yaml after nested', $y['reference'] ?? null, 'composer://swiftmailer/swiftmailer');

// --- differential test against Composer's own composer/semver
$phar = getenv('COMPOSER_PHAR');
if ($phar && is_file($phar)) {
    foreach (['Constraint/ConstraintInterface', 'Constraint/Bound', 'Constraint/Constraint', 'Constraint/MultiConstraint', 'Constraint/MatchAllConstraint', 'Constraint/MatchNoneConstraint', 'VersionParser', 'Semver', 'Comparator', 'Interval', 'Intervals', 'CompilingMatcher'] as $f) {
        require_once "phar://$phar/vendor/composer/semver/src/$f.php";
    }
    $constraints = array_unique(array_merge(array_column($cases, 0), ['^1', '^1.0.0', '~0.1', '~2.4.1', '>1.0', '<=1.5', '1.x', '2.*', '^5.4|^6.0|^7.0', '>=5.3.0', '^8.12|^9.0|^10.0|^11.0|^12.0', '>=7.0.0', '~1.0', '^0.1.0', '>=1.0.0-beta1']));
    $versions = ['0.0.1', '0.0.3', '0.1.0', '0.1.5', '0.3.2', '0.3.9', '0.4.0', '1.0.0-beta1', '1.0.0-RC1', '1.0', '1.0.1', '1.2.2', '1.2.3', '1.2.9', '1.3.0-beta1', '1.3.0', '1.5', '1.9.9', '2.0.0-beta1', '2.0.0', '2.0.5', '2.1.0', '2.4.4', '2.4.5', '3.0', '5.4.40', '6.4.12', '7.2.4', '7.3.4', '8.3.6', '9.0.1', '12.30.0'];
    $diff = 0;
    foreach ($constraints as $c) {
        foreach ($versions as $v) {
            try {
                $theirs = \Composer\Semver\Semver::satisfies($v, $c);
            } catch (\Throwable) {
                continue;
            }
            $ours = Semver::satisfies($v, $c);
            if ($ours !== $theirs) {
                $diff++;
                echo "DIFF composer/semver says " . var_export($theirs, true) . " for $v vs \"$c\", we say " . var_export($ours, true) . "\n";
            }
            $pass++;
        }
    }
    $fail += $diff;
    echo "Differential test vs composer/semver: " . (count($constraints) * count($versions)) . " pairs, $diff differences\n";
}

echo ($fail ? "FAILED" : "OK") . ": $pass passed, $fail failed\n";
exit($fail ? 1 : 0);

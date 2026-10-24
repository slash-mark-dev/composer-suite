# Slash Mark Composer Suite

**PHP Package Intelligence Suite.** Explore, analyze and manage Composer packages from your terminal — 32 tools in one zero-dependency command: `sm-composer`.

```bash
composer global require slashmark/composer-suite
sm-composer help
```

- **Zero dependencies** — plain PHP 8.1+, nothing else gets pulled into your project
- **Live data** from Packagist, the Packagist security advisories API (FriendsOfPHP + GitHub advisories) and GitHub
- **`--json` on every tool** for scripts and CI, plus meaningful exit codes (`0` ok · `1` issues found · `2` usage error · `3` network/runtime error)
- **Fast** — parallel requests and a 10-minute response cache (`--no-cache`, `sm-composer cache:clear`)
- Works on **Windows, macOS and Linux**

## Install

```bash
# globally (recommended) — make sure Composer's global bin dir is on your PATH
composer global require slashmark/composer-suite

# or per project, as a dev tool
composer require --dev slashmark/composer-suite
vendor/bin/sm-composer help
```

Find the global bin dir with `composer global config bin-dir --absolute`.

## The 32 tools

### Discover
| Tool | Command | Example |
|---|---|---|
| Packagist Package Search | `search` | `sm-composer search pdf --type library --min-downloads 1M` |
| Trending Packages Dashboard | `trending` | `sm-composer trending --sort growth -n 25` |
| Latest Updates Tracker | `updates` | `sm-composer updates --project --since 14` |
| Namespace Explorer | `vendor` | `sm-composer vendor spatie` |
| Reverse Dependencies Finder | `dependents` | `sm-composer dependents psr/log` · `sm-composer why psr/log --local` |
| Download Stats Analyzer | `stats` | `sm-composer stats guzzlehttp/guzzle symfony/http-client --days 180` |

### Package insight
| Tool | Command | Example |
|---|---|---|
| Package Info Inspector | `info` | `sm-composer info monolog/monolog` |
| Version Checker | `version` | `sm-composer version` · `sm-composer version laravel/framework --all` |
| Package Compare Tool | `compare` | `sm-composer compare guzzlehttp/guzzle symfony/http-client` |
| Maintainer Info Viewer | `maintainers` | `sm-composer maintainers laravel/framework -c` · `--project` |
| Documentation Viewer | `docs` | `sm-composer docs spatie/laravel-permission --section install` |
| Source Code Explorer | `source` | `sm-composer source monolog/monolog --file src/Monolog/Logger.php` |
| Changelog Viewer | `changelog` | `sm-composer changelog laravel/framework --from v11.0.0` |
| Package Health Score | `health` | `sm-composer health --project --min 50` |

### Project analysis
| Tool | Command | Example |
|---|---|---|
| composer.json Viewer | `json` | `sm-composer json` · `sm-composer json --validate --strict` |
| Dependency Tree Viewer | `tree` | `sm-composer tree --only psr/log` · `--format mermaid` |
| Lock File Analyzer | `lock` | `sm-composer lock` · `sm-composer lock --diff old.lock` |
| Autoload Analyzer | `autoload` | `sm-composer autoload --list` |
| Package Size Analyzer | `size` | `sm-composer size --no-dev` · `sm-composer size laravel/framework` |
| Dependency Update Checker | `outdated` | `sm-composer outdated --direct --commands` |
| Dependency Conflict Checker | `conflicts` | `sm-composer conflicts --with spatie/laravel-permission` |
| Version Constraint Tester | `constraint` | `sm-composer constraint "^2.4" --package guzzlehttp/psr7` |

### Security & compliance
| Tool | Command | Example |
|---|---|---|
| Security Advisory Checker | `security` | `sm-composer security --severity high --no-dev` |
| Deprecated Package Finder | `abandoned` | `sm-composer abandoned --stale 3` |
| License Compliance Checker | `license` | `sm-composer license --no-dev --distribute` |
| Broken Link Checker | `links` | `sm-composer links --project` |
| PHP Version Compatibility Checker | `php` | `sm-composer php 8.4` · `sm-composer php laravel/framework` |
| Framework Compatibility Checker | `framework` | `sm-composer framework --laravel 12` |

### Workflow
| Tool | Command | Example |
|---|---|---|
| Composer Command Generator | `cmd` | `sm-composer cmd require phpunit/phpunit --dev --php 8.1` |
| Multi-Package Dashboard | `dashboard` | `sm-composer dashboard --project --watch 300` |
| Offline Package Exporter | `export` | `sm-composer export --format html` · `--format cyclonedx` |
| Installation Troubleshooter | `doctor` | `sm-composer doctor` · `sm-composer doctor -e "<error text>"` |

Every tool has detailed help: `sm-composer <tool> --help`.

## Highlights

- **`security`** audits `composer.lock` and tells you the *smallest* safe upgrade per package; `--db` reads a local clone of [FriendsOfPHP/security-advisories](https://github.com/FriendsOfPHP/security-advisories) for fully offline audits.
- **`conflicts --with vendor/pkg`** answers "can I add this package?" against your lock file and PHP version before you touch `composer.json`.
- **`php 8.4`** lists every locked package that would block a PHP upgrade, with the release that fixes it.
- **`framework --laravel 12`** is an upgrade-readiness report for Laravel or Symfony.
- **`lock`** recomputes Composer's own content-hash to detect stale lock files, finds orphans, branch-locked packages and `vendor/` drift, and diffs two lock files.
- **`export --format cyclonedx`** writes a CycloneDX 1.5 SBOM including known vulnerabilities; `--format html` makes a shareable report.
- **`doctor -e "…"`** explains common Composer errors (memory, unresolvable sets, PHP/ext mismatches, SSL, GitHub rate limits, allow-plugins, …) with the exact fix.

## CI example (GitHub Actions)

```yaml
- run: composer global require slashmark/composer-suite
- run: sm-composer security --no-dev          # fails on known vulnerabilities
- run: sm-composer license --no-dev --deny AGPL-3.0-only,AGPL-3.0-or-later
- run: sm-composer json --validate --strict
- run: sm-composer health --project --min 40
```

## Configuration

| Variable | Purpose |
|---|---|
| `GITHUB_TOKEN` | Higher GitHub rate limits for `docs`, `source`, `changelog`, `maintainers -c` |
| `SM_COMPOSER_PACKAGIST` / `SM_COMPOSER_REPO` | Point at a Packagist mirror (defaults `https://packagist.org`, `https://repo.packagist.org`) |
| `SM_COMPOSER_CACHE` | Cache folder (default `~/.cache/sm-composer`) |
| `NO_COLOR` / `--no-color` | Plain output |
| `HTTPS_PROXY` | Standard proxy support |

## Notes

- Version constraints are matched with Composer's semantics (tested against `composer/semver` on 1,300+ constraint/version pairs). The remote dependency resolver used by `tree <package>`, `compare` and `size <package>` picks the newest matching stable release for each requirement; it is meant for inspection, not installation.
- Reverse dependencies on Packagist use its public dependents listing; totals come from Packagist's package API.
- License guidance is informational, not legal advice.

## Development

```bash
php tests/run.php     # unit tests (semver, licenses, YAML, helpers)
php tests/e2e.php     # runs all 32 tools against a local mock of Packagist/GitHub
```

## License

MIT © [Slash Mark IT Solutions](https://slashmark.in). Need specialized PHP development or Composer package management? Contact mukesh@slashmark.in.

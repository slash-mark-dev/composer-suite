# Publishing `slashmark/composer-suite` on Packagist

Packagist reads packages from a public Git repository, so publishing is: push to GitHub → tag → submit once. About 10 minutes.

## 1. Run the tests

```bash
php tests/run.php
php tests/e2e.php
composer validate --strict
```

## 2. Push to GitHub

Create an empty public repository, e.g. `github.com/slashmark/composer-suite`, then:

```bash
cd composer-suite
git init
git add .
git commit -m "Composer Suite 1.0.0"
git branch -M main
git remote add origin https://github.com/slashmark/composer-suite.git
git push -u origin main
```

## 3. Tag the release

Packagist turns git tags into versions (there is deliberately no `version` field in composer.json):

```bash
git tag v1.0.0
git push origin v1.0.0
```

## 4. Submit to Packagist (one time)

1. Sign in at https://packagist.org with your GitHub account.
2. Click **Submit**, paste `https://github.com/slashmark/composer-suite`, click **Check**, then **Submit**.
3. If Packagist says the `slashmark` vendor name belongs to someone else, change `"name"` in composer.json (for example `slashmark-it/composer-suite`), commit, tag `v1.0.1` and submit again.

## 5. Turn on auto-updates

When you signed in with GitHub, Packagist usually installs its GitHub hook automatically — the package page then shows no "not auto-updated" warning. If it does show one, add a webhook in the GitHub repo settings:

- Payload URL: `https://packagist.org/api/github?username=<your packagist username>`
- Content type: `application/json`
- Secret: your Packagist API token (Profile → Show API Token)

From then on every `git push --tags` publishes a new version within a minute.

## 6. Check it works

```bash
composer global require slashmark/composer-suite
sm-composer --version
sm-composer info slashmark/composer-suite
```

## Releasing an update

```bash
# edit code, update CHANGELOG.md and Http::VERSION in src/Http.php
git commit -am "Release 1.1.0"
git tag v1.1.0
git push && git push --tags
```

## Website

`website/composer-suite.html` is the Digital Ecosystem section for slashmark.in (self-contained CSS with an `smc-` prefix, so it won't clash with the site's styles). Publish to Packagist first so the "View on Packagist" links don't 404, try it on test.slashmark.in, then the main site.

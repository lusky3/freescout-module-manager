# FreeScout Module Manager

Install FreeScout modules from a GitHub repository or an uploaded ZIP file — no telemetry, no license/trial gating, no third-party backend.

## What this is not

This is a from-scratch, MIT-licensed implementation. It is not a fork of, and has no code in common with, the AGPL-3.0-licensed `FreescoutInstaller` module from freescout-modules.com. That module funneled every install through a third-party Cloudflare Worker (catalog, trial tracking, and the download itself) and had no protection against Zip-Slip path traversal during extraction. This module makes neither mistake: the only network calls it ever makes are to the exact `github.com/{owner}/{repo}/archive/{ref}.zip` URL for a repository you explicitly added, and every ZIP — from GitHub or uploaded — is validated for path traversal and a well-formed `module.json` before a single file is written.

## Requirements

PHP 8.2+ is the target/recommended version, matching FreeScout's own current
guidance (FreeScout's
[Installation Guide](https://github.com/freescout-help-desk/freescout/wiki/Installation-Guide)
and [Upgrade-PHP](https://github.com/freescout-help-desk/freescout/wiki/Upgrade-PHP)
wiki pages explicitly warn against PHP 8.1 and recommend 8.2 or newer). PHP
7.4 also works — this module's code is written in conservative,
7.4-compatible style, and `composer.json`'s `"php"` constraint
(`^7.4|^8.0`) accepts either. Both versions are exercised in CI (see
`.github/workflows/tests.yml`) and were verified end-to-end (unit suite +
live HTTP flows against a real FreeScout instance) against this module.

When running `composer install`/`update` against FreeScout core itself
(not this module), always add `--ignore-platform-reqs` — FreeScout core's
own committed `composer.lock` is otherwise picked apart by an unrelated
`rap2hpoutre/laravel-log-viewer` packaging bug (a missing `src/controllers`
directory in that package's distributed archive) that surfaces as a
classmap-generation error regardless of PHP version or the
`--ignore-platform-reqs` flag. In practice this means core's own
`composer install` cannot complete on any PHP version; FreeScout core is
designed to run from its pre-committed `vendor/` directory for exactly
this kind of shared-hosting-style deployment, and that pre-committed
`vendor/` boots and runs correctly under both PHP 7.4 and PHP 8.2.

## Installing into FreeScout

1. Clone this repo into `Modules/ModuleManager` inside your FreeScout installation.
2. `php artisan module:enable "FreeScout Module Manager"` (nwidart-modules in this FreeScout version resolves by the `name` field in module.json, not the folder/alias — verified during Task 7 execution)
3. Visit **Settings → Module Manager**.

## Using it

- **From GitHub:** add an owner/repo/branch-or-tag under "Add a Repository", then click **Install** on the saved row.
- **From a ZIP file:** use the "Install from Uploaded ZIP" form.
- Either way, the module is extracted into `Modules/<alias>` — you still need to enable it from FreeScout's own Modules page afterward.

`Resources/default-repos.json` ships with exactly one example entry (`nielspeen/AiAssistant`) as a template. Review it, and any repo you add, before installing — this tool does not vet third-party code for you.

## Development

Unit tests (no FreeScout instance required):
```bash
composer install
vendor/bin/phpunit --coverage-text
```

Local FreeScout instance for integration testing:
```bash
./scripts/setup-dev-env.sh
# then visit http://localhost:8080/install
```

### Why there's no automated Feature-test suite

This module has no `Tests/Feature` directory, and `phpunit.xml` only defines a
`Unit` testsuite. That's a deliberate scope boundary, not an oversight.

`composer.json` intentionally has no `laravel/framework` or
`orchestra/testbench` dependency: this module unit-tests its own services in
isolation, without a Laravel bootstrap, so `composer install && vendor/bin/phpunit`
runs in seconds with no database and no FreeScout checkout required. Pulling
in a generic Testbench skeleton to exercise `ModuleManagerController` and its
routes wouldn't actually cover much, because the things worth testing there
are FreeScout-specific: the controller's admin gate calls
`Auth::user()->isAdmin()` on FreeScout's own `User` model, and the settings
page it renders only exists because `ModuleManagerServiceProvider` hooks
FreeScout's `\Eventy` filters (`settings.sections`, `settings.section_settings`,
`settings.view`) that FreeScout core's `SettingsController@view` reads. None
of that exists in a stock Testbench app, so a "Feature test" built on one
would mostly exercise scaffolding written to imitate FreeScout, not FreeScout
itself.

Instead, admin-authorization behavior (guests/non-admins are redirected away
from `/app-settings/modulemanager` and can't add or install repos) and the
full request flow through the real settings page are verified manually
against the Docker dev environment above, using a real FreeScout install with
real session/auth middleware and the real `\Eventy` filter pipeline. If that
manual check is ever automated, it belongs in a test run against a full
FreeScout-core checkout (mirroring how a local, gitignored FreeScout
`phpunit.xml` can add its own testsuite pointing at this module's tests), not
in this repo's standalone `composer install` workflow.

## License

MIT — see `LICENSE`.

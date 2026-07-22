# FreeScout Module Manager

Installs FreeScout modules from a GitHub repo or an uploaded ZIP. No telemetry, no phone-home license checks, no third-party backend in the middle.

## Why this exists

FreescoutInstaller, the module this replaces, routed every install through a Cloudflare Worker it didn't control, and never checked ZIP entries for path traversal before extracting them. This is a clean rewrite, not a fork: no shared code, no AGPL-3.0 carried over, MIT instead. The only network call this module makes is a GET to `github.com/{owner}/{repo}/archive/{ref}.zip`, for a repo you added yourself. It checks every ZIP, whether it came from GitHub or an upload, for path traversal and a valid `module.json` before writing anything to disk.

## Requirements

PHP 8.2+. FreeScout's own docs warn against 8.1 specifically and recommend 8.2 or newer, so that's the target here too. PHP 7.4 also works, since the code is deliberately written to that older syntax; both versions run in CI.

One gotcha that has nothing to do with this module: if you're setting up FreeScout core itself, always run `composer install --ignore-platform-reqs`. Skip the flag and composer chokes on version constraints nobody actually needs enforced. Even with the flag, core's own `composer install` still fails — an unrelated packaging bug in `rap2hpoutre/laravel-log-viewer` ships an archive missing the `src/controllers` directory its own `composer.json` points at. FreeScout ships a pre-built `vendor/` for exactly this reason, so most real installs never run `composer install` on core at all. That pre-built vendor boots fine under both 7.4 and 8.2.

## Installing it

Clone this repo into `Modules/ModuleManager` inside your FreeScout install, then run:

```bash
php artisan module:enable "FreeScout Module Manager"
```

Enable by name, not folder — that's how nwidart-modules resolves it here. Then go to Settings → Module Manager.

## Using it

Add an owner, repo, and branch or tag under "Add a Repository," then hit Install on that row. Or skip GitHub entirely and upload a ZIP directly. Either way the module lands in `Modules/<alias>`, and you still need to enable it from FreeScout's own Modules page afterward.

`Resources/default-repos.json` ships with one entry, `nielspeen/AiAssistant`, as an example — look at it before installing, and look at anything else you add too. This tool checks that a ZIP is safe to extract. It doesn't check whether the code inside it is safe to run.

## Development

Unit tests don't need a FreeScout instance:

```bash
composer install
vendor/bin/phpunit --coverage-text
```

For integration testing against a real instance:

```bash
./scripts/setup-dev-env.sh
# then visit http://localhost:8080/install
```

There's no Feature-test suite, on purpose. `composer.json` has no Laravel or Testbench dependency, which is the whole reason the unit suite runs in a couple seconds with zero setup. What's actually worth testing at the controller level is tied to FreeScout itself — the admin check calls `Auth::user()->isAdmin()` on FreeScout's own User model, and the settings page only renders because the service provider hooks into FreeScout's `Eventy` filters. None of that exists in a generic Testbench app, so a Feature test built on one would mostly test a stand-in for FreeScout instead of FreeScout. Admin-gating and the full request flow get checked by hand against the real Docker environment above.

## Security

See `SECURITY.md` for how to report a vulnerability.

## License

MIT. See `LICENSE`.

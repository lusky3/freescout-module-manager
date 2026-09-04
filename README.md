# FreeScout Module Manager

Installs FreeScout modules from a GitHub repo or an uploaded ZIP. No telemetry, no phone-home license checks, no third-party backend in the middle.

## How it stays safe

This module makes network calls to GitHub, and only to GitHub, for a repo you added yourself: a GET to `api.github.com/repos/{owner}/{repo}` when you add a repo by pasting its URL, a GET to `github.com/{owner}/{repo}/archive/{ref}.zip` when you install or update it, and, only when you click "Check for Updates" or "Update" for a saved repo, up to three more GETs to `api.github.com` to find its latest release, look for a `stable` branch, and read a commit's SHA. None of that runs automatically; it's a network call exactly when you ask for one, for the specific repo you're asking about. One more, different in kind from the rest: FreeScout's own core checks every installed module's `module.json` for update info on every visit to its Modules page, and this module declares that info (pointing at this repo's GitHub Releases) so it shows up there too. That check runs automatically, not on a click, because FreeScout core controls it, not this module's code. Nothing routes through a server this project doesn't control. Every ZIP, whether it came from GitHub or a direct upload, gets checked for path traversal and a valid `module.json` before a single file is written to disk. MIT licensed.

## Requirements

PHP 8.2+. FreeScout's own docs warn against 8.1 specifically and recommend 8.2 or newer, so that's the target here too. PHP 7.4 also works, since the code is deliberately written to that older syntax; both versions run in CI.

One gotcha that has nothing to do with this module: if you're setting up FreeScout core itself, always run `composer install --ignore-platform-reqs`. Skip the flag and composer chokes on version constraints nobody actually needs enforced. Even with the flag, core's own `composer install` still fails: an unrelated packaging bug in `rap2hpoutre/laravel-log-viewer` ships an archive missing the `src/controllers` directory its own `composer.json` points at. FreeScout ships a pre-built `vendor/` for exactly this reason, so most real installs never run `composer install` on core at all. That pre-built vendor boots fine under both 7.4 and 8.2.

## Installing it

Clone this repo into `Modules/ModuleManager` inside your FreeScout install, then run:

```bash
php artisan module:enable "FreeScout Module Manager"
```

Enable by name, not folder; that's how nwidart-modules resolves it here. Then go to Settings → Module Manager.

## Using it

Paste a GitHub URL under "Add a Repository" and the owner, repo, branch, and name get filled in automatically from GitHub's API. That's the fast path for a public repo. Prefer typing the four fields by hand instead? Use the "Or add manually" form below it; that's also where you go for a private repo or anything the URL parser doesn't recognize. Either way, hit Install on the saved row once it's added. Or skip GitHub entirely and upload a ZIP directly. Whichever path you take, the module lands in `Modules/<alias>`, and you still need to enable it from FreeScout's own Modules page afterward.

`Resources/default-repos.json` ships with one entry, `nielspeen/AiAssistant`, as an example. Look at it before installing, and look at anything else you add too. This tool checks that a ZIP is safe to extract. It doesn't check whether the code inside it is safe to run.

Every saved row also gets a Check for Updates button. It looks for a tagged release first; if the repo doesn't publish one, it falls back to a branch instead: `stable` if the repo has one, otherwise whatever branch or tag you already saved it with. Checking and updating are both a single click, both manual, and both scoped to the one row you clicked; nothing checks in the background, and updating one saved repo never touches another.

## Module catalog

The settings page also shows a catalog of pre-checked community modules you can add with one click instead of typing a URL or filling in four fields. Each entry was briefly reviewed for obvious red flags (obfuscated code, hidden network calls, that kind of thing) before being listed. That's not the same as a full audit, and it's not an endorsement. Read the repo yourself before installing anything from it. The catalog disclaimer on the page says the same thing; it's there because it's true, not as boilerplate.

The catalog is a static file (`Resources/catalog.json`) shipped with this module, not a live lookup: adding an entry never talks to any server beyond what's already disclosed above. It's refreshed by re-running the curation workflow in `scripts/curate-catalog.workflow.js` and shipping the result through a normal reviewed PR, the same as any other change to this repo.

### Refreshing the catalog

Run the curation workflow (needs a Claude Code session with the Workflow tool and GitHub MCP access):

```
Workflow({ scriptPath: "scripts/curate-catalog.workflow.js" })
```

It returns a list of newly-found, safety-reviewed candidates. Read every one yourself before adding it.
The workflow's review is the same "brief, not a full audit" check described in the catalog's own
disclaimer, applied by an agent instead of a human, and it can be wrong. For each one you accept: fill
in `reviewed_at` with today's date, add it to `Resources/catalog.json`, and open a PR the normal way.
Never merge a catalog update without reading the diff.

## Self-updating

This module can update itself through FreeScout's own Modules page (`/modules/list`), the same way any third-party module can: `module.json` declares `latestVersionUrl` and `latestVersionZipUrl`, and FreeScout core checks and applies updates on its own; this module doesn't write any of that logic. Two things worth knowing:

- The check runs on every load of that page, automatically, with no caching on FreeScout's side. Every other network call this module makes is one you triggered with a click; this is the one exception, and it's FreeScout core's design, not a choice made here.
- FreeScout core does the actual update download and extraction itself, with no checksum or signature check and no backup of the old version first. That's true for any third-party module using this mechanism; this module can't change how core handles it.

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

There's no Feature-test suite, on purpose. `composer.json` has no Laravel or Testbench dependency, which is the whole reason the unit suite runs in a couple seconds with zero setup. What's actually worth testing at the controller level is tied to FreeScout itself: the admin check calls `Auth::user()->isAdmin()` on FreeScout's own User model, and the settings page only renders because the service provider hooks into FreeScout's `Eventy` filters. None of that exists in a generic Testbench app, so a Feature test built on one would mostly test a stand-in for FreeScout instead of FreeScout. Admin-gating and the full request flow get checked by hand against the real Docker environment above.

## Security

See `SECURITY.md` for how to report a vulnerability.

## License

MIT. See `LICENSE`.

# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning follows
[Semantic Versioning](https://semver.org/).

## [1.3.3] - 2026-09-05

### Added

- Added `lusky3/freescout-quiet-autoclosed` to the module catalog (27
  entries now): suppresses the "new conversation" notification for tickets
  an automatic Workflow has already closed.

### Changed

- Tightened prose in README.md, SECURITY.md, and the catalog's review
  notes for clarity; no functional changes.

## [1.3.2] - 2026-09-04

### Fixed

- Added a real icon for the Modules page, served from this module's own
  `Public/` directory. Without it, `module.json` had no `img` field and
  FreeScout core fell back to a generic default icon.
- Fixed the "View details" link on the Modules page and the "new version
  available" banner. Without a `detailsUrl` in `module.json`, that link
  rendered as a relative `?changelog=1` href, which just reopened the
  current page instead of going anywhere. It now points to this repo's
  GitHub Releases page.

## [1.3.1] - 2026-09-04

### Security

- Bumped `guzzlehttp/guzzle` 8.0.0 → 8.1.0, fixing two CVEs in 8.0.x: a
  noncanonical-host check bypass (CVE-2026-69246, high) and a noncanonical
  cookie-domain scope leak (CVE-2026-69245, medium).
- Bumped `squizlabs/php_codesniffer` (dev-only) 4.0.1 → 4.0.4, fixing a
  command-injection CVE in its Git/Hg/Svn blame reports (CVE-2026-67434,
  high). This project doesn't use those reports, so it wasn't exploitable
  here, but the dependency is patched regardless.

## [1.3.0] - 2026-08-14

### Added

- Per-repo update checking: every saved GitHub repo gets a "Check for
  Updates" and "Update" button, looking for a tagged release first and
  falling back to a `stable` branch or the saved ref.
- Module catalog expanded from 4 to 26 reviewed community modules.

## [1.2.0] - 2026-07-24

### Added

- Self-update via FreeScout's own native module-update mechanism —
  `module.json` declares `latestVersionUrl`/`latestVersionZipUrl`, and
  FreeScout core checks and applies updates on its own.

## [1.1.0] - 2026-07-23

### Added

- Module catalog: a curated, safety-reviewed list of community modules
  installable with one click from the settings page.
- Add a repo by pasting its GitHub URL, instead of typing owner/repo/ref by
  hand.

### Fixed

- Flaky real-network test for the GitHub download size cap.
- Several findings from a multi-agent review pass.

## [1.0.0] - 2026-07-22

### Added

- Initial release: install FreeScout modules from a GitHub repo ref or an
  uploaded ZIP, through a settings page with a saved-repo list.
- ZIP extraction hardened against Zip-Slip, path traversal, and symlink
  escapes before any file is written to disk.
- One verified example repo seeded on first run.

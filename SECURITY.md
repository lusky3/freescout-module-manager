# Security Policy

## Reporting a vulnerability

Use GitHub's private vulnerability reporting: open the Security tab on this repo and click "Report a vulnerability." That opens a private advisory only the maintainer can see, which is the right way to report anything that shouldn't be public before a fix ships.

Don't open a public issue for a security problem. If you already did, just flag it and I'll convert it to a private advisory.

## What counts

Path traversal or symlink escapes in the ZIP extraction logic. Anything that lets an unauthenticated or non-admin request reach the install, upload, or remove endpoints. Requests going somewhere other than the intended `github.com/{owner}/{repo}/archive/{ref}.zip` pattern. A way around the download or extraction size limits.

Malicious code inside a module you chose to install isn't a vulnerability in this tool. This tool's job is to extract ZIPs safely, not to audit what's inside them — see the README for that distinction.

## Supported versions

Whatever's on `main`. This is a small module maintained by one person; there's no LTS branch and no backport policy.

## Response time

No SLA. Expect an acknowledgment within a few days.

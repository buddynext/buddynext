# BuddyNext

[![CI](https://github.com/buddynext/buddynext/actions/workflows/ci.yml/badge.svg)](https://github.com/buddynext/buddynext/actions/workflows/ci.yml)

Enterprise-grade social community platform for WordPress (free + pro). Owned by Wbcom Designs.

## Requirements

- WordPress 6.9+
- PHP 8.1+

## Development

See [CLAUDE.md](CLAUDE.md) for development standards and workflow.

## Building a distribution zip

QA and customers install a single zip — **no composer, no npm, no commands**. Build it with:

```bash
bin/build-release.sh
# → ~/Documents/work-artifacts/scratch/buddynext-<version>.zip
```

Pass a target directory to override the default destination:

```bash
bin/build-release.sh ./dist        # → ./dist/buddynext-<version>.zip
```

What the builder does:

- Stages **committed state only** (`git archive HEAD`) — so commit your changes first, or the
  zip ships the old code even though its filename shows the new version.
- Regenerates a lean runtime `vendor/` with `composer install --no-dev --optimize-autoloader`
  (just the autoloader + bundled `libs/`; no dev tooling).
- Copies **only an allowlist** of runtime paths (`buddynext.php includes templates assets blocks
  libs theme.json` + optional `languages uninstall.php readme.txt`). QA dirs, screenshots, docs,
  `.md` files, and dev configs can never leak in.
- Reads the version from the plugin header — it is **never bumped here** (BuddyNext is pre-release;
  free and pro stay in lockstep, so bump both headers together when the version does change).

The mu-plugin (BuddyNext Isolation) is **not** shipped in the zip — it is auto-created on plugin
activation by the installer, so a fresh install needs nothing extra.

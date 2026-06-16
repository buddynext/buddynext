#!/usr/bin/env bash
# Build a self-contained, command-free release zip for QA / testers.
#
#   bin/build-release.sh [dist-dir]
#
# Produces <dist>/buddynext-<version>.zip that QA can install and test with NO
# commands (no composer, no npm). It ships a LEAN runtime vendor (the composer
# autoloader only — the 55 MB of dev tooling is dropped) + libs/, and excludes
# every dev / QA / docs file. Version is read from the plugin header (never bumped
# here — BuddyNext is pre-release).
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="buddynext"
VERSION="$(grep -m1 'Version:' buddynext.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d ' \r')"
DIST="${1:-$HOME/Documents/work-artifacts/scratch}"

TMP="$(mktemp -d)"
STAGE="$TMP/$SLUG"
mkdir -p "$STAGE"

# 1. Clean, committed state only (respects what's in git, ignores local junk).
git archive HEAD | tar -x -C "$STAGE"

# 2. Drop dev / QA / docs — none of it is needed to RUN the plugin. Note the
#    glob: `audit*` removes both audit/ (manifest) and any audit-YYYY-MM-DD/
#    screenshot dumps (5+ MB of QA PNGs that must never ship).
( cd "$STAGE" \
    && rm -rf tests docs audit* bin plan .github .claude node_modules \
    && rm -f ./*.sh tsconfig.json .cert.json .contract-audit-baseline.json \
       phpcs.xml* phpstan* phpunit* .editorconfig .gitignore .gitattributes \
       package.json package-lock.json )
# No docs and no markdown anywhere in the shipped plugin (incl. libs' own READMEs).
find "$STAGE" -type f -name '*.md' -delete

# 3. Lean runtime vendor: regenerate the autoloader WITHOUT dev deps. (composer.json
#    require is php-only, so this yields just the autoloader — the runtime need —
#    and a correct autoload_files.php, which a manual prune would break.)
rm -rf "$STAGE/vendor"
( cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --quiet 2>/dev/null )
# composer.json/lock are build inputs only — testers never run composer.
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

# 4. Zip.
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$TMP"

echo "built: $ZIP ($(du -h "$ZIP" | cut -f1))"

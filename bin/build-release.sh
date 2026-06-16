#!/usr/bin/env bash
# Build a self-contained, command-free release zip for QA / testers.
#
#   bin/build-release.sh [dist-dir]
#
# Produces <dist>/buddynext-<version>.zip that QA installs and tests with NO
# commands (no composer, no npm). It ships a LEAN runtime vendor (the composer
# autoloader only) + libs/, and — by ALLOWLIST — only the paths the plugin needs
# to RUN. Anything not on the allowlist (QA dirs, screenshots, docs, .md, dev
# configs, the dev mu-plugins/) can never leak in, regardless of what's committed.
# Version is read from the plugin header (never bumped here — BuddyNext is pre-release).
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="buddynext"
VERSION="$(grep -m1 'Version:' buddynext.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d ' \r')"
DIST="${1:-$HOME/Documents/work-artifacts/scratch}"

# The ONLY paths that ship. vendor/ is added after a lean --no-dev rebuild.
# Optional ones (languages, uninstall.php, readme.txt) are copied only if present.
RUNTIME=( buddynext.php includes templates assets blocks libs theme.json )
OPTIONAL=( languages uninstall.php readme.txt )

TMP="$(mktemp -d)"
SRC="$TMP/src"
STAGE="$TMP/$SLUG"
mkdir -p "$SRC" "$STAGE"

# 1. Clean committed state only.
git archive HEAD | tar -x -C "$SRC"

# 2. Lean runtime vendor: regenerate the autoloader WITHOUT dev deps (composer.json
#    require is php-only, so this yields just the autoloader + a correct
#    autoload_files.php, which a manual prune would break).
rm -rf "$SRC/vendor"
( cd "$SRC" && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --quiet 2>/dev/null )

# 3. Copy ONLY the allowlist into the staged plugin dir.
for item in "${RUNTIME[@]}" vendor; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done
for item in "${OPTIONAL[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done

# 4. Belt-and-braces: no markdown anywhere (bundled libs carry their own READMEs).
find "$STAGE" -type f -name '*.md' -delete

# 5. Zip.
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$TMP"

echo "built: $ZIP ($(du -h "$ZIP" | cut -f1))"

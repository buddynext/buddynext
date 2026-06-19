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

# 0. Release gate — never package a build that fails the quality bar. Aborts on a
#    NEW/unbaselined flow-audit error (static, free+pro pair; the CLI loads
#    audit/.flow-audit-baseline.json and exits non-zero only on unbaselined
#    errors) or a cert failure (behavioural, needs a live WP site). Flow-audit
#    always runs; cert runs only when BN_WP_PATH points at a live activated site.
#    Override the CLI path with FLOW_AUDIT_CLI and the Pro root with BN_PRO_PATH;
#    set SKIP_RELEASE_GATE=1 to bypass (e.g. a docs-only re-zip).
if [ "${SKIP_RELEASE_GATE:-0}" != 1 ]; then
	FLOW_AUDIT_CLI="${FLOW_AUDIT_CLI:-$HOME/.mcp-servers/wp-plugin-qa-mcp-server/build/flow-audit-cli.js}"
	BN_PRO_PATH="${BN_PRO_PATH:-$HOME/dev/repos/buddynext-pro}"
	if command -v node >/dev/null 2>&1 && [ -f "$FLOW_AUDIT_CLI" ]; then
		echo "release gate: flow-audit (free + pro)…"
		if ! node "$FLOW_AUDIT_CLI" "$PWD" "$BN_PRO_PATH" >/dev/null 2>&1; then
			echo "release gate FAILED: new/unbaselined flow-audit errors — run: node \"$FLOW_AUDIT_CLI\" \"$PWD\" \"$BN_PRO_PATH\" (see audit/flow-audit-report.md)" >&2
			exit 1
		fi
	else
		echo "release gate: flow-audit SKIPPED — CLI not found (set FLOW_AUDIT_CLI to .../build/flow-audit-cli.js)" >&2
	fi
	if [ -n "${BN_WP_PATH:-}" ] && command -v wp >/dev/null 2>&1; then
		echo "release gate: wp buddynext cert…"
		if ! wp --path="$BN_WP_PATH" buddynext cert >/dev/null 2>&1; then
			echo "release gate FAILED: functional certification failed — run: wp --path=\"$BN_WP_PATH\" buddynext cert" >&2
			exit 1
		fi
	else
		echo "release gate: cert SKIPPED — set BN_WP_PATH to a live WP root to enforce the behavioural gate" >&2
	fi
fi

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

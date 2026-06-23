#!/usr/bin/env bash
# Build a self-contained, command-free release zip for QA / testers.
#
#   bin/build-release.sh [dist-dir]
#
# Produces <dist>/buddynext-<version>.zip that QA installs and tests with NO
# commands (no composer, no npm). Runtime deps ship committed under libs/ and the
# plugin uses a hand-written PSR-4 autoloader, so the zip is deps-complete from
# the committed tree — no vendor/, no build step. By ALLOWLIST, only the paths the
# plugin needs to RUN ship; anything else (QA dirs, screenshots, docs, .md, dev
# configs, the dev mu-plugins/) can never leak in, regardless of what's committed.
# Version is read from the plugin header (never bumped here — BuddyNext is pre-release).
set -euo pipefail

cd "$(dirname "$0")/.."
SLUG="buddynext"
VERSION="$(grep -m1 'Version:' buddynext.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d ' \r')"
DIST="${1:-$HOME/Documents/work-artifacts/scratch}"

# The ONLY paths that ship (libs/ carries the committed runtime deps).
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

# 2. Copy ONLY the allowlist into the staged plugin dir. No composer step:
#    runtime deps are committed under libs/ and loaded via a hand-written
#    autoloader, so the git-archived tree is already deps-complete.
for item in "${RUNTIME[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done
for item in "${OPTIONAL[@]}"; do
	[ -e "$SRC/$item" ] && cp -R "$SRC/$item" "$STAGE/$item"
done

# 4. Belt-and-braces: strip docs + dev cruft that bundled libs carry (their own
#    READMEs, VCS dotfiles, and composer manifests). None are needed at runtime,
#    and they otherwise trip packaging / hidden-file checks.
find "$STAGE" -type f -name '*.md' -delete
find "$STAGE" -type f \( -name '.gitignore' -o -name '.gitattributes' -o -name 'composer.json' -o -name 'composer.lock' -o -name '.editorconfig' \) -delete
find "$STAGE" -depth -type d \( -name '.github' -o -name '.git' -o -name '.circleci' \) -exec rm -rf {} +

# 5. Zip.
mkdir -p "$DIST"
ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$TMP" && zip -rqX "$ZIP" "$SLUG" -x '*.DS_Store' )
rm -rf "$TMP"

echo "built: $ZIP ($(du -h "$ZIP" | cut -f1))"

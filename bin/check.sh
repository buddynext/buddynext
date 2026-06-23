#!/usr/bin/env bash
# Full BuddyNext quality gate — runs every check that PR review would run.
#
# Use cases:
#   bin/check.sh                     # everything, exit non-zero on any failure
#   bin/check.sh --staged            # only staged files (fast pre-commit signal)
#   bin/check.sh --skip-audit        # skip the UX audit step
#
# What it runs (in order, fail-fast):
#   1. PHP -l on every .php file under includes/ + templates/
#   2. WPCS via composer's phpcs script (configured by phpcs.xml.dist)
#   3. bin/check-rest-boundary.sh — fails on any admin-ajax surface
#   4. PHPStan level 5 against includes/
#   5. bin/ux-audit.sh — block-severity violations fail
#   6. flow-audit CLI (free+pro) — fails on new/unbaselined flow-audit errors
#   7. wp buddynext cert — behavioural gate (only when BN_WP_PATH is set)
#
# This script is the single entry point a contributor runs before pushing.
# CI runs the same script. Anchor docs: docs/v2 Plans/PLAN.md Part 4 gates,
# Claude skills /wp-plugin-development + /ux-audit.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1
PLUGIN_DIR="$PWD"

STAGED=0
SKIP_AUDIT=0
for arg in "$@"; do
	case "$arg" in
		--staged)     STAGED=1 ;;
		--skip-audit) SKIP_AUDIT=1 ;;
		--help|-h)
			grep '^#' "$0" | grep -v '^#!' | sed 's/^# \{0,1\}//'
			exit 0
			;;
	esac
done

EXIT=0
RED=$'\e[31m'
GREEN=$'\e[32m'
YELLOW=$'\e[33m'
DIM=$'\e[2m'
RESET=$'\e[0m'

section() { printf "\n${DIM}── %s ──${RESET}\n" "$1"; }
ok()      { printf "${GREEN}✓${RESET} %s\n" "$1"; }
fail()    { printf "${RED}✗${RESET} %s\n" "$1"; EXIT=1; }
note()    { printf "${YELLOW}!${RESET} %s\n" "$1"; }

# 1. PHP lint
section "PHP -l"
if [ "$STAGED" = 1 ]; then
	PHP_FILES="$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)"
else
	PHP_FILES="$(find includes templates -type f -name '*.php' 2>/dev/null)"
fi
if [ -z "$PHP_FILES" ]; then
	note "no PHP files to lint"
else
	LINT_BAD=0
	while IFS= read -r f; do
		[ -z "$f" ] && continue
		[ -f "$f" ] || continue
		if ! php -l "$f" >/dev/null 2>&1; then
			fail "$f"
			LINT_BAD=$((LINT_BAD+1))
		fi
	done <<< "$PHP_FILES"
	[ "$LINT_BAD" = 0 ] && ok "all clean"
fi

# 2. WPCS
section "WPCS (WordPress standard)"
if [ -x vendor/bin/phpcs ]; then
	if [ "$STAGED" = 1 ]; then
		if [ -n "$PHP_FILES" ]; then
			# shellcheck disable=SC2086
			if vendor/bin/phpcs --standard=phpcs.xml $PHP_FILES; then
				ok "staged PHP clean"
			else
				fail "staged PHP has WPCS issues"
			fi
		fi
	else
		if vendor/bin/phpcs --standard=phpcs.xml; then
			ok "all PHP clean"
		else
			fail "WPCS violations"
		fi
	fi
else
	note "vendor/bin/phpcs missing — run \`composer install\`"
fi

# 3. REST-frontend boundary (no admin-ajax)
section "REST-frontend boundary"
if [ -x bin/check-rest-boundary.sh ]; then
	if bin/check-rest-boundary.sh; then
		:
	else
		fail "admin-ajax surface detected — frontend must be 100% REST"
	fi
else
	note "bin/check-rest-boundary.sh missing"
fi

# 4. PHPStan
section "PHPStan (level 5)"
if [ -x vendor/bin/phpstan ]; then
	if vendor/bin/phpstan analyse --no-progress --memory-limit=2G; then
		ok "no errors"
	else
		fail "PHPStan reported errors"
	fi
else
	note "vendor/bin/phpstan missing — run \`composer install\`"
fi

# 4. UX audit
if [ "$SKIP_AUDIT" = 0 ]; then
	section "UX audit (token + primitive gates)"
	if [ -x bin/ux-audit.sh ]; then
		AUDIT_OUTPUT="$(bin/ux-audit.sh "$PLUGIN_DIR" 2>/dev/null)"
		BLOCK_LINE="$(echo "$AUDIT_OUTPUT" | grep -E '\*\*Block-severity violations:' || echo 'Block-severity violations: ?')"
		BLOCK_COUNT="$(echo "$BLOCK_LINE" | grep -oE '[0-9]+' | head -1)"
		BLOCK_COUNT="${BLOCK_COUNT:-0}"
		if [ "$BLOCK_COUNT" = 0 ]; then
			ok "0 block-severity"
		else
			fail "${BLOCK_COUNT} block-severity violations — run bin/ux-audit.sh to see them"
		fi
	else
		note "bin/ux-audit.sh missing"
	fi
fi

# 5. Flow audit (cross-layer dup / orphan / rest-flow / canonical / template /
# logic). Static — runs the free + pro pair through the flow-audit CLI, which
# loads audit/.flow-audit-baseline.json and exits non-zero ONLY on new /
# unbaselined errors (the same baseline-suppression pattern as the contract
# audit). The CLI ships in the wp-plugin-qa MCP server OUTSIDE this repo; override
# its path with FLOW_AUDIT_CLI and the Pro root with BN_PRO_PATH. Skipped (not
# failed) when node or the CLI is unavailable so a fresh clone without the MCP
# server still passes the other gates.
section "Flow audit (free + pro pair)"
FLOW_AUDIT_CLI="${FLOW_AUDIT_CLI:-$HOME/.mcp-servers/wp-plugin-qa-mcp-server/build/flow-audit-cli.js}"
BN_PRO_PATH="${BN_PRO_PATH:-$HOME/dev/repos/buddynext-pro}"
if command -v node >/dev/null 2>&1 && [ -f "$FLOW_AUDIT_CLI" ]; then
	if node "$FLOW_AUDIT_CLI" "$PLUGIN_DIR" "$BN_PRO_PATH" >/dev/null 2>&1; then
		ok "0 unbaselined flow-audit errors"
	else
		fail "flow-audit: new/unbaselined errors — run: node \"$FLOW_AUDIT_CLI\" \"$PLUGIN_DIR\" \"$BN_PRO_PATH\" (see audit/flow-audit-report.md)"
	fi
else
	note "skipped — flow-audit CLI not found (set FLOW_AUDIT_CLI to .../build/flow-audit-cli.js)"
fi

# 6. Functional certification (behaviour, not shape) — needs a live WP site.
# Set BN_WP_PATH to the WordPress root the plugin is active on; without it the
# behavioural gate is skipped (the static checks above still ran). This is the
# only gate that proves toggles actually enforce and routes don't fatal.
section "Functional certification (wp buddynext cert)"
if [ -n "${BN_WP_PATH:-}" ] && command -v wp >/dev/null 2>&1; then
	if wp --path="$BN_WP_PATH" buddynext cert 2>/dev/null; then
		ok "functional certification passed"
	else
		fail "functional certification failed — run: wp --path=\"$BN_WP_PATH\" buddynext cert"
	fi
else
	note "skipped — set BN_WP_PATH to the WordPress root to run the behavioural gate"
fi

# Summary
echo
if [ "$EXIT" = 0 ]; then
	printf "${GREEN}All checks passed.${RESET}\n"
else
	printf "${RED}One or more checks failed.${RESET} Fix and re-run \`bin/check.sh\`.\n"
fi
exit "$EXIT"

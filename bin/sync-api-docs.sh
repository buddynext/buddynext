#!/usr/bin/env bash
# bin/sync-api-docs.sh — regenerate docs/api/openapi.json from the live route registry.
#
# The OpenAPI document is GENERATED from the running WordPress route registry so it
# can never drift from the code. This wrapper runs the generator through WP-CLI and,
# when available, runs the reachability audit so a route that registers without a
# permission callback is caught before the docs are trusted.
#
# Requires a WordPress install with BuddyNext active. Point WP_PATH at its root (or
# run from inside the site so wp autodetects it).
#
#   WP_PATH=/path/to/wordpress bin/sync-api-docs.sh
#
# Exit 0 = docs regenerated (and audit clean, if it ran); non-zero = a step failed.
set -uo pipefail
cd "$(dirname "$0")/.."

PLUGIN_DIR="$(pwd)"
WP_ARGS=()
if [ -n "${WP_PATH:-}" ]; then
	WP_ARGS+=( "--path=${WP_PATH}" )
fi

if ! command -v wp >/dev/null 2>&1; then
	echo "✗ sync-api-docs: WP-CLI (wp) not found on PATH." >&2
	exit 1
fi

# NOTE: `wp eval-file` wraps the file in eval(), which rejects a
# `declare(strict_types=1)` first statement. These files keep strict types (repo
# standard), so they are loaded with `wp eval "require '…';"` instead - require
# preserves __FILE__/__DIR__, so the scripts still resolve their own paths.
echo "• Generating docs/api/openapi.json from the live route registry…"
# ${WP_ARGS[@]+...} guard: bash 3.2 - still the default /bin/bash on macOS -
# treats "${ARR[@]}" on an EMPTY array as an unbound variable under `set -u`,
# so this aborted with "WP_ARGS[@]: unbound variable" whenever WP_PATH was not
# set. That is the documented default usage, so the script only worked the one
# way its own docs call optional.
if ! wp ${WP_ARGS[@]+"${WP_ARGS[@]}"} eval "require '${PLUGIN_DIR}/bin/gen-openapi.php';"; then
	echo "✗ sync-api-docs: generator failed." >&2
	exit 1
fi

if [ -f "${PLUGIN_DIR}/tests/audit/rest-reachability.php" ]; then
	echo "• Running REST reachability audit…"
	if ! wp "${WP_ARGS[@]}" eval "require '${PLUGIN_DIR}/tests/audit/rest-reachability.php';"; then
		echo "✗ sync-api-docs: reachability audit failed." >&2
		exit 1
	fi
fi

echo "✓ sync-api-docs: docs/api/openapi.json is up to date."

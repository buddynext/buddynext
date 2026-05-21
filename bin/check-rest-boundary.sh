#!/usr/bin/env bash
# REST-frontend boundary check for BuddyNext.
#
# Fails CI if any new admin-ajax surface appears in includes/, templates/, or
# assets/js/. The BuddyNext frontend is contractually 100% REST — see
# docs/specs/REST-FRONTEND-CONTRACT.md.
#
# Lines may opt out by appending the marker `wp-frontend-rest-only-allow:`
# followed by a short reason. Use this only when REST is genuinely impossible
# (e.g. a third-party hook signature requires admin-ajax).
#
# Usage:
#   bin/check-rest-boundary.sh          # scan, exit non-zero on violation
#
# Wired into bin/check.sh between the WPCS gate and the UX audit.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

# Patterns that indicate an admin-ajax surface.
PATTERNS='admin-ajax\.php|wp_ajax_|admin_ajax_url|\bajaxurl\b'

# Scan only frontend-eligible directories.
HITS="$(grep -rnE "$PATTERNS" includes/ templates/ assets/js/ 2>/dev/null \
	| grep -v 'wp-frontend-rest-only-allow:' \
	| grep -vE '^[^:]+:[0-9]+:\s*\*' \
	| grep -vE '^[^:]+:[0-9]+:\s*//' \
	| grep -vE '^[^:]+:[0-9]+:\s*#' \
	|| true)"

if [ -n "$HITS" ]; then
	printf '\033[31m✗\033[0m REST-boundary violation: BuddyNext frontend must be 100%% REST.\n'
	printf '\n%s\n' "$HITS"
	printf '\nIf admin-ajax is truly unavoidable, append "// wp-frontend-rest-only-allow: <reason>" to the line.\n'
	printf 'See docs/specs/REST-FRONTEND-CONTRACT.md.\n'
	exit 1
fi

printf '\033[32m✓\033[0m REST-boundary clean — no admin-ajax surface.\n'
exit 0

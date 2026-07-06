#!/usr/bin/env bash
# bin/check-route-urls.sh — fail on hand-rolled route URLs.
#
# Every member/space/hub URL must come from a PageRouter builder (which reads
# the configurable buddynext_slug_* options). Hand-rolling home_url('/members/…')
# 404s the moment a site renames its slugs — the exact customer bug class fixed
# in the 2026-07-06 nav sweep (14 template sites + format_content + the
# onboarding-nudge email CTA all shipped it).
#
# A line is exempt only when it carries a `bn-route-ok:` marker comment with a
# one-line justification (plugin-owned fixed rewrites like /oauth/, slug-aware
# builds from configured options, or explicit absent-plugin fallbacks).
#
# Exit 0 = clean; 1 = violations listed.
set -uo pipefail
cd "$(dirname "$0")/.."

VIOLATIONS=$(grep -rn "home_url( *['\"][^'\"]" \
	--include='*.php' \
	includes/ templates/ ./*.php 2>/dev/null \
	| grep -vE "home_url\( *['\"]/['\"] *[,)]" \
	| grep -v "includes/Core/PageRouter.php" \
	| grep -v "bn-route-ok" \
	| grep -vE ":[0-9]+:[[:space:]]*(\*|//|#)" || true)

if [ -n "$VIOLATIONS" ]; then
	echo "✗ Hand-rolled route URLs found — use a PageRouter builder, or annotate with 'bn-route-ok: <reason>':"
	echo "$VIOLATIONS"
	exit 1
fi

echo "✓ route URLs clean — every path-building home_url() is PageRouter or annotated"

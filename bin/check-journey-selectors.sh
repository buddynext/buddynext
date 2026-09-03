#!/usr/bin/env bash
#
# Journey SELECTOR gate — every selector a spec asserts must exist in the product.
#
# Why this exists, in one sentence: five journey specs were failing against
# markup that had been deliberately changed, and nothing noticed until the
# execution gate reported them as regressions in a shipped release.
#
# Each one was the same shape. A change moved the markup on purpose and did not
# carry its spec with it:
#
#   J-700  asserted actions.follow / actions.unfollow. Neither exists anywhere in
#          the plugin - there is one actions.toggleFollow control. The spec died
#          on its first assertion and never reached the unfollow behaviour it is
#          named for.
#   J-742  asserted .bn-share-menu-wrap + actions.copyProfileLink after the share
#          menu was folded into the More menu on purpose.
#   J-603  asserted a background-image on the space cover after it became an
#          <img> so the focal point could pan and zoom.
#   J-505  walked /activity/ for a Pin control that had been re-scoped to profile
#          context.
#
# Those cost a release cycle of "is this a product bug or a stale spec", and the
# answer was stale spec four times out of five. The execution gate cannot tell
# the difference: a selector that matches nothing looks exactly like a feature
# that is broken. This gate can, and it runs in about a second.
#
# It is deliberately NOT a general CSS linter. It checks the two things that
# actually drifted: Interactivity action names, and BuddyNext's own bn-* classes.
#
#   ERROR   a selector is the ONLY thing a locator can match, and it exists
#           nowhere in templates/, assets/, blocks/ or includes/. The spec cannot
#           pass; it is asserting removed markup.
#   WARNING a selector appears only as a FALLBACK alternative (a comma-separated
#           list where a live selector comes first). Harmless today, but it
#           states that the markup might take a form it can no longer take, and
#           it is how the next reader learns the wrong thing.
#
# Comments are ignored: specs legitimately mention old class names when
# explaining why they changed, and that prose is worth keeping.
#
# Usage:  bin/check-journey-selectors.sh
# Exit:   0 clean (warnings allowed), 1 at least one ERROR.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

SPEC_DIR="tests/e2e"
HAYSTACK=(templates assets blocks includes)

# tests/e2e/pro/ covers the PAIR, so Pro's markup counts as "the product"
# too. Without it every Pro-only class (.bn-my-membership and friends) reads
# as removed markup. Optional: Free is a standalone plugin and the sibling is
# simply absent on a Free-only checkout.
PRO_DIR="../buddynext-pro"
if [ -d "$PRO_DIR/templates" ]; then
	HAYSTACK+=("$PRO_DIR/templates" "$PRO_DIR/assets" "$PRO_DIR/includes")
fi

[ -d "$SPEC_DIR" ] || { echo "no ${SPEC_DIR} - nothing to check"; exit 0; }

errors=0
warnings=0

# Strip // and * comment lines so prose about old markup is not treated as an
# assertion. Block-comment bodies in these specs are all leading-* lines.
spec_code() {
	grep -rhv -E '^\s*(//|\*|/\*)' "$SPEC_DIR" --include='*.ts' 2>/dev/null
}

# Substring matching is NOT good enough here, and getting this wrong makes the
# whole gate worthless. `grep -F actions.follow` matches
# actions.followSuggestedUser in templates/onboarding/index.php, so the first
# version of this script declared a REMOVED selector present and passed its own
# mutation test. A name must end where the selector ends.
exists_in_product() {
	local escaped
	escaped="$(printf '%s' "$1" | sed 's/[.[\*^$]/\\&/g')"
	grep -rqE "${escaped}([^A-Za-z0-9_-]|\$)" "${HAYSTACK[@]}" 2>/dev/null
}

# A selector is a fallback when the line offers an alternative: a comma inside
# the selector string, which is how these specs spell "either shape".
is_fallback() {
	# NOTE the ^[^:]*: prefix. grep -r prefixes every line with its filename, so
	# anchoring the comment filter at ^\s*(//|\*) matched nothing and comments
	# were never stripped. This script's own comment about actions.follow contains
	# a comma, which the fallback pattern then matched - so the gate excused the
	# exact selector it exists to catch, and passed its own mutation test.
	grep -rF "$1" "$SPEC_DIR" --include='*.ts' 2>/dev/null \
		| grep -v -E '^[^:]*:[[:space:]]*(//|\*)' \
		| grep -qE "[,]\s*[.\[]|[.\[][^'\"]*,"
}

# A spec may reference removed markup ON PURPOSE - a journey that is baselined
# because the feature it covers was withdrawn, pending a decision to restore it
# or retire the journey. That has to be DECLARED on the line, never silent, so
# the next reader knows it is a recorded gap and not rot:
#   const X = '.bn-gone'; // journey-selector-allow: feature removed, see J-743
is_declared() {
	grep -rF "$1" "$SPEC_DIR" --include='*.ts' 2>/dev/null | grep -q 'journey-selector-allow:'
}

report() {
	local kind="$1" sel="$2"
	local where
	where="$(grep -rn -F "$sel" "$SPEC_DIR" --include='*.ts' 2>/dev/null | grep -v -E ':\s*(//|\*)' | head -1 | cut -d: -f1-2)"
	if [ "$kind" = "ERROR" ]; then
		echo "  ERROR   ${sel}"
		echo "          asserted at ${where}, exists nowhere in the product"
		errors=$((errors + 1))
	else
		echo "  WARNING ${sel}"
		echo "          dead fallback at ${where} - the markup cannot take this form"
		warnings=$((warnings + 1))
	fi
}

echo "journey selectors: checking spec assertions against the product ..."

# 1. Interactivity actions.
while read -r action; do
	[ -n "$action" ] || continue
	exists_in_product "$action" && continue
	is_declared "$action" && continue
	if is_fallback "$action"; then report WARNING "$action"; else report ERROR "$action"; fi
# Only the SELECTOR form counts. A bare `actions.foo` also matches ordinary
# JavaScript - `locator.getAttribute(...)` on a variable named actions - which
# reported actions.getAttribute as a missing control on the first run.
done < <(spec_code \
	| grep -ohE 'data-wp-on--click=\\?"actions\.[a-zA-Z][a-zA-Z0-9_]*' \
	| grep -ohE 'actions\.[a-zA-Z][a-zA-Z0-9_]*' | sort -u)

# 2. BuddyNext's own classes.
while read -r class; do
	[ -n "$class" ] || continue
	exists_in_product "$class" && continue
	is_declared ".$class" && continue
	if is_fallback ".$class"; then report WARNING ".$class"; else report ERROR ".$class"; fi
# The WHOLE identifier, hyphens included. [a-z0-9]+ stopped at the first hyphen,
# so .bn-follow-button was read as .bn-follow - a name that does not exist, and
# eleven such truncations were reported as missing markup.
done < <(spec_code | grep -ohE '\.bn-[A-Za-z0-9_-]+' | sed 's/^\.//' | sort -u)

echo "journey selectors: ${errors} error(s), ${warnings} warning(s)"

if [ "$errors" -gt 0 ]; then
	echo
	echo "A spec is asserting markup the product no longer has, so it can only fail." >&2
	echo "Update the spec to the current markup - do NOT delete the assertion, or the" >&2
	echo "journey stops covering the behaviour it is named for." >&2
	exit 1
fi

exit 0

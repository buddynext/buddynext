#!/usr/bin/env bash
#
# Five-tab cap gate for the admin information architecture.
#
# AdminHub::TAB_PLACEMENT states the rule in its own docblock — "No section
# exceeds five tabs, so no screen overwhelms the owner." Nothing enforced it, and
# it drifted: Settings and Moderation both ship 7.
#
# It drifted quietly for a structural reason, which is why a comment was never
# going to hold it. Pro registers roughly half the tabs FROM A SEPARATE REPO, so
# nobody adding a tab on either side ever sees the combined total — each change
# looks like "one more tab" onto a list that is already over.
#
# This asks AdminHub itself rather than reading the source, and that is the whole
# design decision. Two source-parsing versions were written first and both printed
# numbers LOWER than the screen: counting placement rules misses every tab that
# has no rule, and counting register_tab() calls misses Settings, which registers
# its tabs in a loop with a variable slug. A gate that under-reports is worse than
# no gate, because its number gets quoted in a card.
#
# WP_ADMIN has to be defined BEFORE WordPress loads: registration happens on
# `init` behind is_admin(), so a plain `wp eval` reports zero tabs in every
# section — the other way to be confidently wrong.
#
# Needs a WordPress install with both plugins active, so it skips loudly without
# one, the same contract bin/check.sh already uses for the cert and journey gates.
#
#   BN_WP_PATH=/path/to/wordpress bin/check-tab-cap.sh
#
set -uo pipefail

CAP=5

if ! command -v wp >/dev/null 2>&1; then
	echo "tab cap: SKIPPED — wp-cli not on PATH"
	exit 0
fi

if [ -z "${BN_WP_PATH:-}" ]; then
	echo "tab cap: SKIPPED — set BN_WP_PATH to the WordPress root to run this gate"
	exit 0
fi

# Pro contributes roughly half the tabs. Counting Free alone would pass sections
# that ship over the cap in the product an owner actually installs.
PRO_ACTIVE="$(wp --path="$BN_WP_PATH" plugin is-active buddynext-pro >/dev/null 2>&1 && echo yes || echo no)"

REPORT="$(wp --path="$BN_WP_PATH" --exec="define('WP_ADMIN', true);" eval '
$hub   = BuddyNext\Admin\AdminHub::class;
$over  = array();
$total = 0;
foreach ( $hub::sections() as $key => $section ) {
	$count  = count( $hub::get_tabs( (string) $key ) );
	$total += $count;
	printf( "  %-18s %d%s\n", $key, $count, $count > 5 ? "  <-- over the cap" : "" );
	if ( $count > 5 ) {
		$over[] = $key . " has " . $count;
	}
}
printf( "  %-18s %d tabs\n", "total", $total );
if ( $over ) {
	fwrite( STDERR, "OVER:" . implode( ", ", $over ) . "\n" );
}
' 2>&1)"

# The eval prints the per-section table on stdout and one OVER: line on stderr;
# both are captured together so the table is always shown with the verdict.
echo "${REPORT}" | grep -v '^OVER:'

if [ "$PRO_ACTIVE" != "yes" ]; then
	echo ""
	echo "  note: buddynext-pro is not active on this install, so roughly half the"
	echo "        tabs are missing from these counts. Activate it before trusting a pass."
fi

if echo "${REPORT}" | grep -q '^OVER:'; then
	OVER_LINE="$(echo "${REPORT}" | grep '^OVER:' | sed 's/^OVER://')"
	echo "" >&2
	echo "tab cap FAILED:${OVER_LINE}" >&2
	echo "" >&2
	echo "AdminHub::TAB_PLACEMENT documents a ${CAP}-tab cap per section." >&2
	echo "Re-file a tab through TAB_PLACEMENT, or change the cap deliberately — in this" >&2
	echo "gate and in that docblock together, so the rule and its enforcement cannot" >&2
	echo "part company again." >&2
	exit 1
fi

echo ""
echo "tab cap: OK — no section over ${CAP}"
exit 0

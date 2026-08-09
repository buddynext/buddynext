#!/usr/bin/env bash
# Block style declarations must reach the token layer.
#
# A `file:` reference in block.json gets a WordPress AUTO-GENERATED style handle
# with NO dependencies. blocks.css uses 12 --bn-* tokens and .bn-btn lives in
# bn-base.css, so a block declaring ONLY `file:...` renders token-less and
# unstyled on an ordinary page - invisible on BuddyNext hub routes, where the
# feature stylesheets are already enqueued for other reasons. That is what made
# this class of bug survive so long (Basecamp 10182506250).
#
# So: every block below must declare at least one NAMED handle alongside any
# file: reference. Named feature handles are registered with bn-base as a
# dependency (AssetService::register_styles), so one is enough to pull the tokens.
#
# SCOPED to the blocks carded in the 1.1.3 batch, by owner decision. A wider
# version (fail on any file:-only block) would close the class for good; if a new
# block ever drifts the same way, widen this list rather than re-triaging.
set -u
cd "$(dirname "$0")/.." || exit 1

SCOPED="bn-follow-button bn-connection-button bn-my-spaces bn-post-composer bn-search-bar bn-header-user-menu bn-member-directory"
fail=0

for b in $SCOPED; do
	f="blocks/$b/block.json"
	[ -f "$f" ] || { echo "  ✗ $b: block.json missing"; fail=1; continue; }

	named=$(python3 - "$f" <<'PY'
import json,sys
style = json.load(open(sys.argv[1])).get('style')
items = style if isinstance(style, list) else ([style] if style else [])
print(sum(1 for s in items if isinstance(s, str) and not s.startswith('file:')))
PY
)
	if [ "${named:-0}" -lt 1 ]; then
		echo "  ✗ $b: declares only file: style refs — no named handle, so bn-base never loads"
		fail=1
	fi
done

if [ "$fail" -ne 0 ]; then
	echo "block-styles: FAILED — add the named handle that owns the classes the template emits."
	echo "              Do NOT add a second file: ref; that bypasses the dependency graph too."
	exit 1
fi

echo "block-styles: clean — every scoped block declares a named handle (bn-base reachable)"

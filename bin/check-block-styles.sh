#!/usr/bin/env bash
# Block style declarations must reach the token layer — on BOTH surfaces.
#
# A `file:` reference in block.json gets a WordPress AUTO-GENERATED style handle
# with NO dependencies. blocks.css uses --bn-* tokens and .bn-btn lives in
# bn-base.css, so a block declaring ONLY `file:...` renders token-less and
# unstyled on an ordinary page — invisible on BuddyNext hub routes, where the
# feature stylesheets are already enqueued for other reasons. That is what made
# this class of bug survive so long (Basecamp 10182506250).
#
# `editorStyle` needs the same rule for the same reason, one surface over: a
# file:-only editorStyle previews the block against no token layer, which is why
# header-user-menu rendered broken in the editor while its front end was fine
# (Basecamp 10184505520).
#
# So: every block must declare at least one NAMED handle alongside any file:
# reference, in each of `style` and `editorStyle` that it declares at all. Named
# feature handles are registered with bn-base as a dependency
# (AssetService::register_styles), so one is enough to pull the tokens.
#
# This checked only the seven blocks carded in the 1.1.3 batch until every block
# passed it, at which point the narrow list had no reason to exist.
set -u
cd "$(dirname "$0")/.." || exit 1

report=$(python3 - <<'PY'
import glob
import json

problems = []
for path in sorted(glob.glob('blocks/*/block.json')):
    try:
        block = json.load(open(path, encoding='utf-8'))
    except (OSError, ValueError) as exc:
        problems.append(f'  x {path}: unreadable ({exc})')
        continue

    name = block.get('name', path)
    for key in ('style', 'editorStyle'):
        value = block.get(key)
        items = value if isinstance(value, list) else ([value] if value else [])
        if not items:
            continue
        named = [s for s in items if isinstance(s, str) and not s.startswith('file:')]
        if not named:
            problems.append(
                f'  x {name}: {key} declares only file: refs '
                '— no named handle, so bn-base never loads'
            )

print('\n'.join(problems))
PY
)

if [ -n "$report" ]; then
	echo "$report"
	echo "block-styles: FAILED — add the named handle that owns the classes the template emits."
	echo "              Do NOT add a second file: ref; that bypasses the dependency graph too."
	exit 1
fi

echo "block-styles: clean — every block's style AND editorStyle name a handle (bn-base reachable)"

#!/usr/bin/env bash
# Every front-end BuddyNext stylesheet must declare a path to bn-base.
#
# bn-base defines the --bn-* tokens every other sheet authors against, so it has
# to load first. Enqueue ORDER does not guarantee that: WordPress 6.9's
# wp_hoist_late_printed_styles() buffers the page and lifts late-printed styles
# back into the head, and a stylesheet reached through a block.json `style`
# entry lands in the non-core-block bucket, which sits ABOVE the region where
# ordinary enqueued styles printed. A handle that does not NAME bn-base as a
# dependency can therefore end up above it.
#
# That is not theoretical: bn-media and bn-achievements both registered with
# `array()` deps, and on any page carrying a BuddyNext block bn-media.css
# printed before bn-base.css. Every equal-specificity override those sheets make
# against a bn-base primitive lost, silently, on block surfaces only.
#
# A dep of bn-base OR of another handle that itself depends on bn-base counts
# (the bn-admin-* sheets reach it through bn-admin).
set -u
cd "$(dirname "$0")/.." || exit 1

python3 - <<'PY'
import re, os, sys

# Handles that ARE the token layer, or that reach it through another handle.
ROOTS = {'bn-fonts', 'bn-base'}
TRANSITIVE_OK = {'bn-admin'}  # registered with bn-fonts + bn-base

found = []
for root, _, files in os.walk('includes'):
    for fn in files:
        if not fn.endswith('.php'):
            continue
        path = os.path.join(root, fn)
        src = open(path, encoding='utf-8', errors='replace').read()
        # Only registrations that supply a src AND an explicit deps array.
        pattern = r"wp_(?:register|enqueue)_style\(\s*'(bn-[a-z0-9-]+)'\s*,\s*([^,]+),\s*(array\([^)]*\)|\[[^\]]*\])"
        for m in re.finditer(pattern, src, re.S):
            handle, deps = m.group(1), m.group(3)
            if handle in ROOTS:
                continue
            if 'bn-base' in deps:
                continue
            if any(t in deps for t in TRANSITIVE_OK):
                continue
            line = src[:m.start()].count('\n') + 1
            found.append((path, line, handle, ' '.join(deps.split())))

if found:
    print('style-deps: FAILED — stylesheet(s) with no declared path to bn-base:')
    for path, line, handle, deps in found:
        print(f'  x {path}:{line}  {handle}  deps={deps}')
    print('    Add bn-base (or a handle that depends on it) to the deps array.')
    print('    Enqueue order is not a substitute — see this script\'s header.')
    sys.exit(1)

print('style-deps: clean — every front-end bn-* stylesheet declares a path to bn-base')
PY

#!/usr/bin/env bash
# Frontend interactive primitives must not be sized below the 40px tap floor.
#
# ux-foundation Rule 13: "40px minimum tap target on buttons. Density exception:
# 34px floor for high-density admin lists (compact rows)." The exception is for
# ADMIN lists, so member-facing CSS gets no sub-40px sizing at all. bn-base.css
# raises everything again to 44px at touch viewports.
#
# This is a static check on the DECLARATIONS, because that is where the bug was:
# nothing looked wrong in any template. `.bn-btn[data-variant]` carried
# `min-height: 36px` and outranked the base rule, so it was the real default
# height for every button in the plugin; `[data-size="sm"]` set 28px and was what
# every card action cluster used; and a `sm` carve-out inside the touch block
# exempted those same buttons from the 44px floor they were supposed to get.
#
# Admin stylesheets are out of scope — that is where the density exception lives.
set -u
cd "$(dirname "$0")/.." || exit 1

report=$(python3 - <<'PY'
import glob
import os
import re

FLOOR = 40
# Interactive primitives. A rule only counts when its selector TARGETS one of
# these, not when it sizes something inside one (`.bn-btn svg { height: 16px }`).
PRIMITIVES = ('bn-btn', 'bn-input', 'bn-select', 'bn-textarea', 'bn-tab', 'bn-page-btn')
SIZING = ('min-height', 'height', 'min-block-size', 'block-size')

problems = []

for path in sorted(glob.glob('assets/css/*.css')):
    if os.path.basename(path).startswith('bn-admin'):
        continue  # density exception lives in admin
    css = open(path, encoding='utf-8', errors='replace').read()
    css = re.sub(r'/\*.*?\*/', '', css, flags=re.S)

    for match in re.finditer(r'([^{}]+)\{([^{}]*)\}', css):
        selectors, body = match.group(1), match.group(2)
        sel_list = [s.strip() for s in selectors.split(',') if s.strip()]

        targets = False
        for sel in sel_list:
            last = re.split(r'\s+|>|\+|~', sel.strip())[-1]
            base = last.split(':')[0]
            classes = re.findall(r'\.([A-Za-z0-9_-]+)', base)
            if any(c in PRIMITIVES for c in classes):
                targets = True
                break
        if not targets:
            continue

        for prop in SIZING:
            for dec in re.finditer(rf'(?<![-\w]){prop}\s*:\s*([0-9.]+)px', body):
                value = float(dec.group(1))
                if value < FLOOR:
                    line = css[:match.start()].count('\n') + 1
                    problems.append(
                        f'  x {path}:~{line}  {sel_list[0]}  {prop}: {dec.group(1)}px  (floor {FLOOR}px)'
                    )

print('\n'.join(problems))
PY
)

if [ -n "$report" ]; then
	echo "$report"
	echo "tap-targets: FAILED — a frontend interactive primitive is sized under 40px."
	echo "             ux-foundation Rule 13 allows 34px only for compact ADMIN lists."
	echo "             Use padding / font-size for density; never a smaller hit area."
	exit 1
fi

echo "tap-targets: clean — no frontend primitive sized under 40px"

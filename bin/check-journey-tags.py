#!/usr/bin/env python3
"""Every Playwright spec must declare the journey it proves.

A spec that names no journey cannot be reconciled with the journey catalogue,
so it is invisible to coverage reporting: it neither proves a catalogued
journey nor shows up as a gap. Five specs sat in exactly that state until the
2026-07-21 audit (comment-threading, composer-drafts, reactive-tabs,
followers-following, owner-gate) — all passing, none counted.

This gate is the PUBLIC half of the check. It only asserts that a spec declares
some `J-NN`; it cannot verify the id EXISTS, because the catalogue is internal
and lives outside this repository. The private half
(`buddynext-pro/bin/check-journey-coverage.py`) does the cross-check both ways.

Convention: put the id in the spec's leading docblock, e.g.

    /**
     * J-77-post-edit-delete.
     */

One spec may declare several ids when it proves several journeys.

Exit 0 = every spec declares at least one journey id.
"""

import re
import sys
from pathlib import Path

E2E = Path(__file__).resolve().parent.parent / "tests" / "e2e"
JOURNEY_RE = re.compile(r"\bJ-\d{2,3}\b")

# An UNCONDITIONAL test.fixme — one whose first argument is a string name
# (`test.fixme('name', fn)`) or a bare boolean (`test.fixme(true, ...)`) — always
# skips its test, so it is real debt and must be traceable to a catalogue journey
# that records the same "fixme" status and why. A CONDITIONAL fixme
# (`test.fixme(process.env.BN_PRO !== '1', ...)`, `test.fixme(isMobile, ...)`) is
# an environment/Pro gate and stays allowed — its first argument is an expression,
# never a string/boolean literal, so it does not match here.
UNCONDITIONAL_FIXME_RE = re.compile(r"test\.fixme\(\s*(?:'(?:[^'\\]|\\.)*'|true|false)\s*[,)]")


def main() -> int:
    if not E2E.is_dir():
        print(f"  ! {E2E} not found — skipping")
        return 0

    specs = sorted(E2E.rglob("*.spec.ts"))
    if not specs:
        print("  ! no specs found — skipping")
        return 0

    untagged = []
    silent_fixmes = []
    tagged = 0
    for spec in specs:
        text = spec.read_text(encoding="utf-8")
        ids = JOURNEY_RE.findall(text)
        if ids:
            tagged += 1
        else:
            untagged.append(spec.relative_to(E2E))

        # Every unconditional test.fixme must name a journey on its own line, so an
        # always-skipped test can be reconciled with the catalogue entry that
        # records it as fixme — never a silent skip nobody can trace.
        for lineno, line in enumerate(text.splitlines(), 1):
            stripped = line.lstrip()
            if stripped.startswith("//") or stripped.startswith("*"):
                continue  # A comment mentioning test.fixme(...) is not a real skip.
            if UNCONDITIONAL_FIXME_RE.search(line) and not JOURNEY_RE.search(line):
                silent_fixmes.append((spec.relative_to(E2E), lineno))

    failed = False

    if untagged:
        failed = True
        print(f"  {len(untagged)} spec(s) declare no journey id:")
        for path in untagged:
            print(f"      {path}")
        print()
        print("  Add the journey id to the spec's leading docblock, e.g. `* J-77-post-edit-delete.`")
        print("  If the spec proves something the catalogue does not list yet, add the")
        print("  journey to JOURNEYS.md first so the two sides stay reconcilable.")

    if silent_fixmes:
        failed = True
        print(f"  {len(silent_fixmes)} unconditional test.fixme(s) name no journey (silent debt):")
        for path, lineno in silent_fixmes:
            print(f"      {path}:{lineno}")
        print()
        print("  An always-skipped test must name the journey it covers on the same line,")
        print("  e.g. test.fixme(true, 'J-14: poll mode not wired yet - see card ...'), so it")
        print("  is traceable to the catalogue entry that records it as fixme and why.")
        print("  A conditional gate (test.fixme(process.env.BN_PRO !== '1', ...)) is exempt.")

    if failed:
        return 1

    print(f"✓ all {tagged} Playwright specs declare a journey id; no silent test.fixme")
    return 0


if __name__ == "__main__":
    sys.exit(main())

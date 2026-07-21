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


def main() -> int:
    if not E2E.is_dir():
        print(f"  ! {E2E} not found — skipping")
        return 0

    specs = sorted(E2E.rglob("*.spec.ts"))
    if not specs:
        print("  ! no specs found — skipping")
        return 0

    untagged = []
    tagged = 0
    for spec in specs:
        ids = JOURNEY_RE.findall(spec.read_text(encoding="utf-8"))
        if ids:
            tagged += 1
        else:
            untagged.append(spec.relative_to(E2E))

    if untagged:
        print(f"  {len(untagged)} spec(s) declare no journey id:")
        for path in untagged:
            print(f"      {path}")
        print()
        print("  Add the journey id to the spec's leading docblock, e.g. `* J-77-post-edit-delete.`")
        print("  If the spec proves something the catalogue does not list yet, add the")
        print("  journey to JOURNEYS.md first so the two sides stay reconcilable.")
        return 1

    print(f"✓ all {tagged} Playwright specs declare a journey id")
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""docs/website/docs_config.json must match the pages on disk, exactly.

`docs_config.json` is the hand-maintained index of the customer documentation: it
names every page and the order they appear in. Nothing validated it, so it stayed
correct only for as long as everyone remembered to edit it — and the failure is
silent both ways.

  A page on disk that is NOT listed  -> written, committed, and invisible. It
  simply never appears, and nobody finds out until a reader asks where it went.

  A page listed that is NOT on disk  -> a dangling entry. Depending on the
  consumer that is either a 404 or a build error, and it appears only at publish
  time, long after the commit that caused it.

Both happened during the 1.1.6 docs pass, which added pages (renewal reminders),
renamed one (membership-tiers -> membership-plans) and renumbered a series
(whats-new 1.1.3/1.1.4). Each of those is one forgotten line away from a silent
break, which is exactly the shape of thing a gate should hold.

Also checks that a listed path is not a duplicate, because a page listed twice
renders twice and the second one wins the slug.

Usage: python3 bin/check-docs-config.py     (exit 1 on any mismatch)
"""

import json
import pathlib
import sys
from collections import Counter

ROOT = pathlib.Path(__file__).resolve().parent.parent
WEBSITE = ROOT / "docs" / "website"
CONFIG = WEBSITE / "docs_config.json"


def main() -> int:
    if not CONFIG.is_file():
        print(f"docs-config: {CONFIG.relative_to(ROOT)} not found", file=sys.stderr)
        return 1

    try:
        cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print(f"docs-config: {CONFIG.relative_to(ROOT)} is not valid JSON — {exc}", file=sys.stderr)
        return 1

    listed = []
    for sub in cfg.get("subcategories", []):
        listed.extend(sub.get("docs", []))

    on_disk = {
        str(p.relative_to(WEBSITE))
        for p in WEBSITE.rglob("*.md")
    }

    listed_set = set(listed)
    missing = sorted(listed_set - on_disk)   # listed, no file
    unlisted = sorted(on_disk - listed_set)  # file, not listed
    dupes = sorted(name for name, n in Counter(listed).items() if n > 1)

    if not (missing or unlisted or dupes):
        print(f"✓ docs config in sync — {len(listed)} pages listed, {len(on_disk)} on disk")
        return 0

    if missing:
        print("✗ listed in docs_config.json but NOT on disk (dangling entry):", file=sys.stderr)
        for m in missing:
            print(f"    {m}", file=sys.stderr)
    if unlisted:
        print("✗ on disk but NOT listed in docs_config.json (page would never appear):", file=sys.stderr)
        for u in unlisted:
            print(f"    {u}", file=sys.stderr)
    if dupes:
        print("✗ listed more than once in docs_config.json:", file=sys.stderr)
        for d in dupes:
            print(f"    {d}", file=sys.stderr)

    print(
        "\n  Fix docs/website/docs_config.json so it names exactly the .md files under "
        "docs/website/ — every page, once each.",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())

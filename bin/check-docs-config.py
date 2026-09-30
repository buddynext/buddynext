#!/usr/bin/env python3
"""docs/website/docs_config.json must match the pages on disk, exactly.

The config uses the Wbcom docs `sections[]` schema: one entry per section, each
naming a FOLDER under docs/website/. Page order inside a folder comes from the
numeric filename prefix, so pages are never listed one by one (the per-page list
this replaced went stale every time a page was added or renumbered).

What can still go wrong silently, and what this checks:

  A folder of pages that is NOT a section -> every page in it is invisible.
  A section whose folder is missing/empty -> a dangling section at publish time.
  A section listed twice                  -> it renders twice.
  A page loose in docs/website/ itself    -> belongs to no section, never appears.
  A missing product/productType/shortId   -> the schema's required keys.

Usage: python3 bin/check-docs-config.py     (exit 1 on any mismatch)
"""

import json
import pathlib
import sys
from collections import Counter

ROOT = pathlib.Path(__file__).resolve().parent.parent
WEBSITE = ROOT / "docs" / "website"
CONFIG = WEBSITE / "docs_config.json"


REQUIRED_KEYS = ("product", "productType", "shortId")


def main() -> int:
    if not CONFIG.is_file():
        print(f"docs-config: {CONFIG.relative_to(ROOT)} not found", file=sys.stderr)
        return 1

    try:
        cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        print(f"docs-config: {CONFIG.relative_to(ROOT)} is not valid JSON — {exc}", file=sys.stderr)
        return 1

    problems = []

    absent = [k for k in REQUIRED_KEYS if not str(cfg.get(k, "")).strip()]
    if absent:
        problems.append(("missing required keys", absent))

    folders = [str(sec.get("folder", "")).strip("/") for sec in cfg.get("sections", [])]
    if not folders:
        problems.append(("no sections[] entries", ["sections"]))

    page_folders = {
        str(p.relative_to(WEBSITE).parts[0])
        for p in WEBSITE.rglob("*.md")
        if len(p.relative_to(WEBSITE).parts) > 1
    }
    loose = sorted(str(p.relative_to(WEBSITE)) for p in WEBSITE.glob("*.md"))

    unlisted = sorted(page_folders - set(folders))
    empty = sorted(f for f in set(folders) if f not in page_folders)
    dupes = sorted(name for name, n in Counter(folders).items() if n > 1)

    if unlisted:
        problems.append(("folder of pages NOT a section (its pages would never appear)", unlisted))
    if empty:
        problems.append(("section folder missing or has no pages (dangling section)", empty))
    if dupes:
        problems.append(("section listed more than once", dupes))
    if loose:
        problems.append(("page outside any section folder", loose))

    if not problems:
        pages = sum(1 for _ in WEBSITE.rglob("*.md"))
        print(f"✓ docs config in sync — {len(folders)} sections, {pages} pages")
        return 0

    for title, items in problems:
        print(f"✗ {title}:", file=sys.stderr)
        for item in items:
            print(f"    {item}", file=sys.stderr)
    print(
        "\n  Fix docs/website/docs_config.json so its sections[] name every page folder under "
        "docs/website/, once each.",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())

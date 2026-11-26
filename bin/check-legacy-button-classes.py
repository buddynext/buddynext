#!/usr/bin/env python3
"""Fail when markup uses a legacy BEM button modifier instead of the data-* API.

WHY THIS EXISTS

Buttons are styled by one API in bn-base.css: class="bn-btn" plus
data-variant (primary, secondary, ghost, danger, ai), data-size (sm, lg) and
data-full (full width inside auth cards). Up to 1.2.9 a second, older set of
modifiers (bn-btn--primary, --secondary, --sm, --block ...) was defined only in
bn-profile.css. Any page that does not load that file rendered those buttons as
bare grey boxes: the finish-sign-up "Create my account" button shipped that way.
1.3.0 moved every caller to the data-* API and deleted the old rules; this check
keeps the old names from coming back in Free or Pro.

A hook class that merely starts with "bn-btn--" (for example bn-btn--prefs-link)
is fine: only the retired variant and size names are refused.

Exit 0 when clean, 1 on any violation.
"""

import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PRO = Path(os.environ.get("BN_PRO_PATH", ROOT.parent / "buddynext-pro"))

LEGACY = re.compile(r"\bbn-btn--(primary|secondary|ghost|danger|sm|lg|block|icon)(?![\w-])")
SCAN = ("includes", "templates", "blocks", "assets/js", "assets/css")
SUFFIXES = {".php", ".js", ".css", ".html"}


def files():
    for base in (ROOT, PRO):
        for folder in SCAN:
            path = base / folder
            if path.is_dir():
                for f in sorted(path.rglob("*")):
                    if f.suffix in SUFFIXES and ".min." not in f.name and "/build/" not in str(f):
                        yield base, f


def main() -> int:
    problems = []
    for base, path in files():
        for lineno, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
            stripped = line.lstrip()
            # Comments that explain the retirement may name the old classes.
            if stripped.startswith(("//", "*", "/*", "#")):
                continue
            m = LEGACY.search(line)
            if m:
                rel = path.relative_to(base.parent)
                problems.append(
                    f"{rel}:{lineno}: legacy class {m.group(0)} - use class=\"bn-btn\" with data-variant / data-size / data-full"
                )
    if problems:
        print("\n".join(problems))
        return 1
    print("No legacy bn-btn-- variant classes.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

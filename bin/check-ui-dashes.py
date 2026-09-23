#!/usr/bin/env python3
"""No em-dashes in translatable UI copy.

House style is a hyphen or ordinary punctuation. A one-off sweep is true the day it
lands and false the moment the next commit adds one back (card 10300359034 bounced
for exactly that), so the count is gated on every commit instead.

Scans gettext calls (__, _e, _x, _n, esc_html__, ...) in PHP under includes/,
templates/ and blocks/ of the repo given as the first argument (default: this
repo). Comments are not UI and are ignored.

Exit 0 = none found.
"""

import os
import re
import sys

ROOT = os.path.abspath(sys.argv[1]) if len(sys.argv) > 1 else os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

GETTEXT = re.compile(
    r"\b(?:__|_e|_x|_ex|_n|_nx|_n_noop|_nx_noop|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_html_x|esc_attr_x)\s*\(\s*"
    r"""('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")(?:\s*,\s*('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"))?"""
)
EM_DASH = "—"


def main() -> int:
    hits = []
    for sub in ("includes", "templates", "blocks"):
        base = os.path.join(ROOT, sub)
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [d for d in dirnames if d not in ("node_modules", "vendor", "build")]
            for name in filenames:
                if not name.endswith(".php"):
                    continue
                path = os.path.join(dirpath, name)
                with open(path, encoding="utf-8", errors="ignore") as handle:
                    source = handle.read()
                for match in GETTEXT.finditer(source):
                    strings = [s for s in match.groups() if s]
                    if any(EM_DASH in s for s in strings):
                        line = source.count("\n", 0, match.start()) + 1
                        hits.append(f"{os.path.relpath(path, ROOT)}:{line}")

    if hits:
        print(f"  {len(hits)} translatable string(s) contain an em-dash (house style is a hyphen or plain punctuation):")
        for hit in hits:
            print(f"    {hit}")
        return 1

    print("✓ no em-dashes in translatable UI strings")
    return 0


if __name__ == "__main__":
    sys.exit(main())

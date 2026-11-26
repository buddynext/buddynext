#!/usr/bin/env python3
"""Fail when a CronScheduler::ensure_recurring() call uses the pre-1.3.0 argument order.

WHY THIS EXISTS

1.3.0 changed the signature to ( $hook, $first, $interval, $group ), the order the
MediaVerse and Jetonomy helpers use. A caller left on the old ( $hook, $interval )
order is a fatal ArgumentCountError on every scheduling request (it took down
`wp plugin deactivate jetonomy` on a dev site), and a caller that swaps two ints
silently schedules the first run years away. PHPStan sees neither once the arity
matches, so this checks each call: at least three arguments, and the second one
(the first run) is never a bare interval (`*_IN_SECONDS`, `$interval` without
`time() +`).

Scans this plugin and, when present, the sibling buddynext-pro checkout, including
docs/ so a copied example cannot teach the old order. Exit 0 when clean, 1 otherwise.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TARGETS = [ROOT, ROOT.parent / "buddynext-pro"]
SKIP = ("/vendor/", "/libs/", "/node_modules/")
CALL = re.compile(r"ensure_recurring\(\s*(?!string\b)")


def split_args(text: str, start: int):
    """Top-level comma-split of the argument list that opens at text[start] == '('."""
    depth, args, cur = 0, [], ""
    for ch in text[start:]:
        if ch in "([":
            depth += 1
            if depth == 1:
                continue
        elif ch in ")]":
            depth -= 1
            if depth == 0:
                args.append(cur.strip())
                return [a for a in args if a]
        elif ch == "," and depth == 1:
            args.append(cur.strip())
            cur = ""
            continue
        cur += ch
    return None


def main() -> int:
    bad = []
    for base in TARGETS:
        if not base.is_dir():
            continue
        for path in list(base.rglob("*.php")) + list(base.rglob("*.md")):
            if any(s in str(path) for s in SKIP):
                continue
            text = path.read_text(encoding="utf-8", errors="replace")
            for m in CALL.finditer(text):
                args = split_args(text, m.start() + len("ensure_recurring"))
                if not args:
                    continue  # Prose mention such as `ensure_recurring()`.
                line = text.count("\n", 0, m.start()) + 1
                if len(args) < 3:
                    bad.append(f"{path}:{line}: {len(args)} args; needs ( $hook, $first, $interval[, $group] )")
                elif re.search(r"_IN_SECONDS\b|\$interval\b", args[1]) and "time(" not in args[1]:  # time() + X is a timestamp.
                    bad.append(f"{path}:{line}: second argument '{args[1]}' is an interval; it is the first run time")
    for b in bad:
        print(b)
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())

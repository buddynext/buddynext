#!/usr/bin/env python3
"""
Hook-doc conformance gate.

The integration-hook table in CLAUDE.md is read by third-party integrators AND by AI agents.
It was hand-maintained and rotted: 10 of 36 signatures handed wrong values to any listener that
trusted them, and 2 were fatal under PHP 8 (ArgumentCountError).

This gate compares the documented ARG COUNT of every hook against the real do_action() call
site, so the table can never silently drift again. Arg COUNT is what causes the fatal; names are
checked by eye at review time.

Usage: python3 bin/check-hook-docs.py     (exit 1 on drift)
"""
import re
import pathlib
import sys
from collections import defaultdict

ROOT = pathlib.Path(__file__).resolve().parent.parent
DO_ACTION = re.compile(r"do_action\(\s*'(buddynext_[a-z_]+)'\s*,\s*(.+?)\s*\);", re.S)


def split_args(s):
    out, depth, cur = [], 0, ""
    for ch in s:
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(cur.strip()); cur = ""
        else:
            cur += ch
    if cur.strip():
        out.append(cur.strip())
    return out


def real_signatures():
    sigs = defaultdict(set)
    for f in (ROOT / "includes").rglob("*.php"):
        src = f.read_text(errors="ignore")
        src = re.sub(r"/\*.*?\*/", "", src, flags=re.S)
        src = re.sub(r"^\s*(//|#).*$", "", src, flags=re.M)
        for m in DO_ACTION.finditer(src):
            sigs[m.group(1)].add(len(split_args(m.group(2))))
    return sigs


def documented():
    md = (ROOT / "CLAUDE.md").read_text()
    start = md.find("### BuddyNext fires → Addons listen")
    end = md.find("### Addons fire → BuddyNext listens")
    if start == -1 or end == -1:
        print("  ! could not locate the hook table in CLAUDE.md")
        sys.exit(1)
    block = md[start:end]
    block = re.sub(r"//.*$", "", block, flags=re.M)  # strip our own inline notes
    return {m.group(1): len(split_args(m.group(2))) for m in DO_ACTION.finditer(block)}


def main():
    real, doc, fail = real_signatures(), documented(), 0

    for hook, n_doc in doc.items():
        counts = real.get(hook)
        if not counts:
            print(f"  NEVER FIRED      {hook} — documented but no do_action() exists")
            fail = 1
            continue
        if n_doc not in counts:
            worst = min(counts)
            tag = "FATAL" if n_doc > worst else "DRIFT"
            print(f"  {tag:<16} {hook} — doc declares {n_doc} args, code fires {sorted(counts)}")
            if n_doc > worst:
                print(f"                   A listener with accepted_args={n_doc} gets ArgumentCountError (PHP 8).")
            fail = 1

    if fail == 0:
        print(f"✓ hook docs conform — {len(doc)} signatures match their do_action() call sites")
    else:
        print("")
        print("✗ hook-doc drift. REGENERATE the table from the call sites; do not hand-edit it.")
        print("  Integrators and AI agents read that table. A wrong signature is a landmine.")
    sys.exit(fail)


if __name__ == "__main__":
    main()

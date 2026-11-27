#!/usr/bin/env python3
"""Fail when a shipped locale is not fully translated.

Per languages/<domain>-<locale>.po: counts untranslated msgids, fuzzy entries, and entries whose
msgstr placeholders differ from the msgid (the multiset of {{tokens}}, printf specifiers, HTML tag
names and entities). Prints a per-locale table; exits 1 if any count is above zero.

Usage: check-translations.py [plugin-dir | languages-dir] [text-domain]
  Default dir: this plugin. The domain defaults to the stem of the .pot found in the languages dir
  (else any *.po). The target is zero everywhere: there is deliberately no ratchet file.
Fix: translate the entry (keep every placeholder), clear the fuzzy flag, rebuild the .mo with msgfmt.
"""
import ast
import collections
import glob
import os
import re
import sys

TOKEN = re.compile(
    r"\{\{[^{}]*\}\}"                                  # {{token}}
    r"|%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[sdfuxXeEgGcoi]"  # %s %d %1$s %.2f
    r"|</?[A-Za-z][A-Za-z0-9]*"                        # HTML tag name
    r"|&[A-Za-z][A-Za-z0-9]*;"                         # named entity (numeric ones are localised quotes)
)


def tokens(text):
    return collections.Counter(t.lower() if t.startswith(("<", "&")) else t for t in TOKEN.findall(text.replace("%%", "")))


def parse(path):
    """Yield dicts {line, flags, ctx, id, plural, str:{n:text}} for each live (non-obsolete) entry."""
    entries, cur, field, flags = [], None, None, []
    with open(path, encoding="utf-8") as fh:
        lines = fh.read().split("\n")
    def flush():
        nonlocal cur
        if cur and "id" in cur:
            entries.append(cur)
        cur = None
    for no, raw in enumerate(lines, 1):
        line = raw.strip()
        if not line:
            flush(); flags = []; field = None
        elif line.startswith("#,"):
            flags = [f.strip() for f in line[2:].split(",")]
        elif line.startswith("#"):
            continue
        else:
            m = re.match(r"(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(\".*\")$", line)
            if m:
                key = m.group(1)
                val = ast.literal_eval(m.group(3))
                if key == "msgid" and cur and "id" in cur:
                    flush()
                if cur is None:
                    cur = {"line": no, "flags": flags, "ctx": "", "id": None, "plural": None, "str": {}}
                if key == "msgctxt":
                    cur["ctx"] = val; field = ("ctx",)
                elif key == "msgid":
                    cur["id"] = val; field = ("id",)
                elif key == "msgid_plural":
                    cur["plural"] = val; field = ("plural",)
                else:
                    n = int(m.group(2) or 0)
                    cur["str"][n] = val; field = ("str", n)
            elif line.startswith('"') and cur is not None and field:
                val = ast.literal_eval(line)
                if field[0] == "str":
                    cur["str"][field[1]] += val
                else:
                    cur[field[0]] += val
    flush()
    return [e for e in entries if e["id"] != ""]


def check(path):
    untr = fuzzy = bad = 0
    detail = []
    for e in parse(path):
        strs = e["str"] or {0: ""}
        if "fuzzy" in e["flags"]:
            fuzzy += 1; detail.append((e["line"], "fuzzy", e["id"]))
            continue
        if any(s == "" for s in strs.values()):
            untr += 1; detail.append((e["line"], "untranslated", e["id"]))
            continue
        want = [tokens(e["id"])] + ([tokens(e["plural"])] if e["plural"] else [])
        for s in strs.values():
            if tokens(s) not in want:
                bad += 1; detail.append((e["line"], "placeholder", e["id"])); break
    return untr, fuzzy, bad, detail


def main():
    root = os.path.abspath(sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(__file__), ".."))
    langs = os.path.join(root, "languages") if os.path.isdir(os.path.join(root, "languages")) else root
    domain = sys.argv[2] if len(sys.argv) > 2 else None
    if not domain:
        pots = glob.glob(os.path.join(langs, "*.pot"))
        domain = os.path.basename(pots[0])[:-4] if pots else "*"
    files = sorted(glob.glob(os.path.join(langs, domain + "-*.po")))
    if not files:
        print("FAIL: no %s-*.po in %s" % (domain, langs), file=sys.stderr)
        return 1
    print("%-28s %12s %6s %12s" % ("locale", "untranslated", "fuzzy", "placeholders"))
    failed = False
    for f in files:
        u, z, b, detail = check(f)
        print("%-28s %12d %6d %12d" % (os.path.basename(f)[len(domain) + 1:-3], u, z, b))
        if u or z or b:
            failed = True
            for line, kind, msgid in detail[:5]:
                print("    %s:%d %s: %s" % (os.path.basename(f), line, kind, msgid[:70]), file=sys.stderr)
    if failed:
        print("FAIL: translations incomplete (translate, keep placeholders, drop fuzzy, rebuild .mo)", file=sys.stderr)
    return 1 if failed else 0


sys.exit(main())

#!/usr/bin/env python3
"""Check the structure of every shipped locale; report (not gate) translation coverage.

Per languages/<domain>-<locale>.po prints untranslated and fuzzy counts as information only: a
community may supply any language, finished or not, so incomplete coverage never fails the build.
FAILS (exit 1) on: a translated entry whose msgstr placeholders differ from the msgid (multiset of
{{tokens}}, printf specifiers, HTML tag names, named entities), a .po msgfmt rejects, or a .mo that
is not a valid gettext file.

Usage: check-translations.py [plugin-dir | languages-dir] [text-domain]
  Default dir: this plugin. The domain defaults to the stem of the .pot found in the languages dir
  (else any *.po). Pot freshness is gated separately by bin/check-pot-fresh.php.
Fix: restore the placeholder in the msgstr, then rebuild the .mo with msgfmt.
"""
import ast
import collections
import glob
import os
import re
import struct
import subprocess
import sys

TOKEN = re.compile(
    r"\{\{[^{}]*\}\}"                                  # {{token}}
    r"|%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[sdfuxXeEgGcoi]"  # %s %d %1$s %.2f
    r"|</?[A-Za-z][A-Za-z0-9]*"                        # HTML tag name
    r"|&(?!amp;)[A-Za-z][A-Za-z0-9]*;"                 # named entity (numeric ones are localised quotes; &amp; is an ampersand a translation may write as a word)
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


def structure(po):
    """Return a list of structural problems with a .po and its sibling .mo."""
    probs = []
    try:
        r = subprocess.run(["msgfmt", "-c", "-o", os.devnull, po], capture_output=True, text=True)
        if r.returncode:
            probs.append("msgfmt rejects the .po: " + r.stderr.strip().split("\n")[0])
    except FileNotFoundError:
        pass  # msgfmt missing: the placeholder check below still runs.
    mo = po[:-3] + ".mo"
    if os.path.isfile(mo):
        with open(mo, "rb") as fh:
            head = fh.read(4)
        if head not in (struct.pack("<I", 0x950412DE), struct.pack(">I", 0x950412DE)):
            probs.append("%s is not a gettext .mo" % os.path.basename(mo))
    return probs


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
    print("%-14s %12s %6s | %12s %9s   (coverage is informational, only the right-hand columns gate)" % ("locale", "untranslated", "fuzzy", "placeholders", "structure"))
    failed = False
    for f in files:
        u, z, b, detail = check(f)
        probs = structure(f)
        print("%-14s %12d %6d | %12d %9d" % (os.path.basename(f)[len(domain) + 1:-3], u, z, b, len(probs)))
        for line, kind, msgid in [d for d in detail if d[1] == "placeholder"][:5]:
            print("    %s:%d placeholder differs from msgid: %s" % (os.path.basename(f), line, msgid[:70]), file=sys.stderr)
        for p in probs:
            print("    " + p, file=sys.stderr)
        failed = failed or b or probs
    if failed:
        print("FAIL: a translated entry lost or changed a placeholder, or a .po/.mo is malformed", file=sys.stderr)
    return 1 if failed else 0


sys.exit(main())

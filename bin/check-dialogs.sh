#!/usr/bin/env bash
# Popup copy conformance (front end).
#
#   1. Every bnConfirm() names its action: a title, a confirmLabel AND an explicit tone.
#      Without a confirmLabel the button falls back to "Confirm", which says nothing about
#      what the member is agreeing to. Without a tone bnConfirm defaults to "danger", so a
#      harmless confirm (unban, approve an appeal) renders as a red destructive dialog.
#   2. No "Please try again" in a front-end string. One voice: "Could not X. Try again."
#   3. No "!!" in a data-wp-* expression. The Interactivity parser honours one leading "!",
#      so "!!context.x" is always true and a field opens in its error state. Use a state getter.
#
# Admin (assets/js/admin/) has its own bnConfirm that takes okLabel, and is out of scope.
# Native confirm/alert/prompt are banned separately by bin/ux-audit.sh rule F8.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

python3 - <<'PY'
import glob, re, sys

bad = []
for f in glob.glob("assets/js/**/*.js", recursive=True):
    if ".min." in f or "/admin/" in f:
        continue
    s = open(f, encoding="utf-8").read()
    for m in re.finditer(r"(?:window\.)?bnConfirm\(\s*\{", s):
        i, depth = m.end(), 1
        while i < len(s) and depth:
            depth += (s[i] == "{") - (s[i] == "}")
            i += 1
        keys = set(re.findall(r"\b([A-Za-z]+)\s*:", s[m.end():i - 1]))
        line = s[:m.start()].count("\n") + 1
        for need in ("title", "confirmLabel", "tone"):
            if need not in keys:
                bad.append(f"{f}:{line} bnConfirm() has no {need}")
    for n, text in enumerate(s.splitlines(), 1):
        if re.search(r"Please try again", text, re.I) and not text.lstrip().startswith(("*", "//", "/*")):
            bad.append(f"{f}:{n} \"Please try again\": write \"Try again.\"")

for f in glob.glob("templates/**/*.php", recursive=True) + glob.glob("includes/**/*.php", recursive=True) + glob.glob("blocks/**/*.php", recursive=True):
    for n, text in enumerate(open(f, encoding="utf-8").read().splitlines(), 1):
        if re.search(r'data-wp-[a-z-]+(?:--[a-z0-9-]+)?="\s*!!', text):
            bad.append(f"{f}:{n} data-wp-* expression starts with \"!!\" (always true): use a state getter")

if bad:
    print("  popup copy: %d problem(s)" % len(bad))
    for b in bad:
        print("    " + b)
    sys.exit(1)
print("  popup copy: every bnConfirm names its action and tone, one voice for retries, no !! expressions")
PY

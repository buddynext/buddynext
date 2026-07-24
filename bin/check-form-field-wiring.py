#!/usr/bin/env python3
"""A3 — every user-editable auth-form control must be wired into the submit payload.

Card 10123540374: the signup "Your name" input carried only data-wp-bind--disabled —
no value/input binding and no data-bn-reg-field. The Interactivity store therefore never
captured it, submitSignup never put it in the POST body, and the server fell back to the
email-handle prefix. The form validated, the account was CREATED — it just had the wrong
display_name. Nothing errored; a journey that only asserts "signup succeeded" passes.

The BuddyNext auth forms submit via fetch() from an Interactivity store, so a control
reaches the request body only if it is captured one of two sanctioned ways:

  1. data-bn-reg-field            → gathered by collectRegFields() (custom profile fields)
  2. a store binding on the input → data-wp-on--input / data-wp-on--change
                                     (writes a context key the store adds to the body),
                                     or data-wp-bind--value

An editable, named control with NEITHER is invisible to submit — exactly card 10. This
gate scans templates/auth/*.php (PHP stripped first, because a <?php ?> echo inside an
attribute contains a '>' that breaks naive tag matching — the trap that hides these).

Editable = type in {text,email,password,tel,url,number,search-excluded,checkbox,radio}
plus <select>/<textarea>. Excluded: type=hidden and type=submit/button (PHP/native or
non-data), and name="s" (the theme search box that lives in the page chrome).

Exit 0 = every editable named auth-form control is wired to the payload.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
AUTH = ROOT / "templates" / "auth"

PHP_BLOCK = re.compile(r"<\?(?:php|=).*?\?>", re.S)
CONTROL = re.compile(r"<(input|select|textarea)\b(.*?)>", re.S)
NAME = re.compile(r'\bname="([^"]+)"')
TYPE = re.compile(r'\btype="([^"]+)"')

# Controls that never carry a submit payload here.
EXCLUDE_TYPES = {"hidden", "submit", "button", "reset", "image"}
EXCLUDE_NAMES = {"s"}  # theme search field in page chrome

WIRED = re.compile(r"data-bn-reg-field|data-wp-on--(input|change)|data-wp-bind--value")

failures = []

if not AUTH.exists():
    print("A3 form-field wiring: no templates/auth — skipped.")
    sys.exit(0)

for tpl in sorted(AUTH.glob("*.php")):
    raw = tpl.read_text(encoding="utf-8", errors="ignore")
    stripped = PHP_BLOCK.sub(" ", raw)  # remove PHP so '>' inside echoes can't truncate tags
    for m in CONTROL.finditer(stripped):
        tag = m.group(0)
        name_m = NAME.search(tag)
        if not name_m:
            continue
        name = name_m.group(1)
        if name in EXCLUDE_NAMES:
            continue
        typ = (TYPE.search(tag).group(1) if TYPE.search(tag) else "text")
        if typ in EXCLUDE_TYPES:
            continue
        if WIRED.search(tag):
            continue
        # approximate original line for the message
        line = raw.count("\n", 0, raw.find(f'name="{name}"')) + 1 if f'name="{name}"' in raw else 0
        failures.append(
            f"templates/auth/{tpl.name}:{line}: <{m.group(1)} name=\"{name}\" type=\"{typ}\"> has no "
            f"payload wiring (no data-bn-reg-field, no data-wp-on--input/change, no data-wp-bind--value) "
            f"— it will not reach the submit body. This is the card-10 class (field renders, account "
            f"created, value silently dropped)."
        )

if failures:
    print("A3 form-field wiring — FAILURES:\n")
    for f in failures:
        print("  ✗ " + f)
    print(
        "\nFix: bind the control into the store (data-wp-on--input=\"actions.setX\" whose key the "
        "submit body includes) or tag it data-bn-reg-field so collectRegFields() gathers it."
    )
    sys.exit(1)

print("A3 form-field wiring: every editable auth-form control is wired to the payload.")
sys.exit(0)

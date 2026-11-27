#!/usr/bin/env bash
# Negative control for bin/check-translations.py: a fully translated locale must pass; an empty
# msgstr, a fuzzy flag, a dropped {{token}}, a changed %s and a lost HTML tag must each fail.
# Run: bash bin/test-translation-gate.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
GOOD='msgid ""
msgstr ""
"Content-Type: text/plain; charset=UTF-8\n"

#, php-format
msgid "Hello %s, open <a>{{link}}</a> &amp; go"
msgstr "Hallo %s, oeffne <a>{{link}}</a> &amp; los"

msgid "Save"
msgstr "Speichern"
'
mk() { mkdir -p "$T/$1"; printf '%s' "$2" > "$T/$1/demo-de_DE.po"; }
mk good "$GOOD"
mk empty "${GOOD/msgstr \"Speichern\"/msgstr \"\"}"
mk fuzzy "${GOOD/msgid \"Save\"/#, fuzzy
msgid \"Save\"}"
mk token "${GOOD/\{\{link\}\}<\/a> &amp; los/<\/a> &amp; los}"
mk printf "${GOOD/Hallo %s/Hallo %d}"
mk tag "${GOOD/<a>\{\{link\}\}<\/a>/{{link\}\}}"
python3 -I "$HERE/check-translations.py" "$T/good" >/dev/null || { echo "FAIL: gate rejected a fully translated locale"; exit 1; }
for bad in empty fuzzy token printf tag; do
	if cmp -s "$T/good/demo-de_DE.po" "$T/$bad/demo-de_DE.po"; then echo "FAIL: $bad fixture was not mutated"; exit 1; fi
	if python3 -I "$HERE/check-translations.py" "$T/$bad" >/dev/null 2>&1; then echo "FAIL: gate passed the $bad fixture"; exit 1; fi
done
echo "ok: good passes; empty, fuzzy, token, printf and tag fixtures all fail"

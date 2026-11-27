#!/usr/bin/env bash
# Negative control for bin/check-translations.py: a translated locale passes, and so do an empty msgstr
# and a fuzzy entry (coverage is informational); a dropped {{token}}, a changed %s, a lost HTML tag and a
# malformed .po must each fail.
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
mk broken "${GOOD/msgid \"Save\"/msgidd \"Save\"}"
mk tag "${GOOD/<a>\{\{link\}\}<\/a>/{{link\}\}}"
python3 -I "$HERE/check-translations.py" "$T/good" >/dev/null || { echo "FAIL: gate rejected a fully translated locale"; exit 1; }
for ok in empty fuzzy; do
	python3 -I "$HERE/check-translations.py" "$T/$ok" >/dev/null 2>&1 || { echo "FAIL: gate rejected the $ok fixture (coverage must not gate)"; exit 1; }
done
for bad in token printf tag broken; do
	if cmp -s "$T/good/demo-de_DE.po" "$T/$bad/demo-de_DE.po"; then echo "FAIL: $bad fixture was not mutated"; exit 1; fi
	if python3 -I "$HERE/check-translations.py" "$T/$bad" >/dev/null 2>&1; then echo "FAIL: gate passed the $bad fixture"; exit 1; fi
done
echo "ok: good, empty and fuzzy pass; token, printf, tag and malformed .po fail"

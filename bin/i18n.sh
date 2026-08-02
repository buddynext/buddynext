#!/usr/bin/env bash
#
# BuddyNext i18n build — regenerate the .pot template, MERGE it into every .po,
# and compile the runtime files WordPress actually loads: .mo (PHP) + .json (JS).
#
# Usage:
#   bin/i18n.sh          full pass: .pot -> merge into .po -> compile .mo + .json
#   bin/i18n.sh pot      only regenerate the .pot template
#
# Why the merge step exists: without it the .pot moved forward on every release
# and the .po files never did, so translators were shown a catalogue frozen at
# whatever the last hand-merge was. By 1.1.1 that gap had reached 290 strings —
# every string added after 2026-07-08 was invisible to all ten locales, including
# ones QA had reported as "not translatable". Running make-pot alone is what
# created that; it is no longer possible to do by accident.
#
# Requires WP-CLI and gettext (msgmerge/msgfmt/msgattrib) on PATH.
# Set WP_CLI to override the wp binary.
#
set -euo pipefail
cd "$(dirname "$0")/.."

WP="${WP_CLI:-wp}"
DOMAIN="buddynext"
LANGS="languages"
MAIN_FILE="buddynext.php"

for tool in msgmerge msgfmt msgattrib; do
	if ! command -v "$tool" >/dev/null 2>&1; then
		echo "ERROR: $tool not found. Install gettext (brew install gettext / apt-get install gettext)." >&2
		exit 1
	fi
done

VERSION="$(sed -n 's/^ \* Version: *//p' "$MAIN_FILE" | head -1 | tr -d ' \r')"
if [ -z "$VERSION" ]; then
	echo "ERROR: could not read the plugin version from $MAIN_FILE." >&2
	exit 1
fi

echo "==> make-pot ($DOMAIN $VERSION)"
# No --skip-audit: the audit is the only thing that reports duplicate translator
# comments and other extraction problems. Warnings are surfaced, not buried.
"$WP" i18n make-pot . "$LANGS/$DOMAIN.pot" --slug="$DOMAIN" --domain="$DOMAIN"

if [ "${1:-all}" = "pot" ]; then
	echo "Done (.pot only)."
	exit 0
fi

echo "==> msgmerge  (.pot -> every .po, so translators see new strings)"
for po in "$LANGS"/*.po; do
	[ -e "$po" ] || continue
	# --no-fuzzy-matching: a wrong fuzzy guess silently ships a mistranslation.
	# An untranslated string falls back to English, which is always safe.
	msgmerge --quiet --update --backup=none --no-fuzzy-matching "$po" "$LANGS/$DOMAIN.pot"

	# Keep the catalogue's version header honest — a .po claiming an old version
	# is how a stale catalogue goes unnoticed for four releases.
	sed -i.bak -E "s/\"Project-Id-Version: BuddyNext [^\\\\]*\\\\n\"/\"Project-Id-Version: BuddyNext ${VERSION}\\\\n\"/" "$po"
	rm -f "$po.bak"
done

echo "==> make-mo + make-json  ($DOMAIN-{locale}.mo  +  $DOMAIN-{locale}-{hash}.json)"
# A locale ships its runtime artifacts only when it is actually translated, and
# "actually" is a COVERAGE BAR, not "has at least one string".
#
# Both artifacts are gated in ONE loop on ONE measurement, deliberately. The .mo
# used to be gated here while `wp i18n make-json "$LANGS"` ran unconditionally
# over the whole directory, so three empty locales shipped 13 JSON files each —
# 39 files, every msgid mapped to "". Two gates on the same condition in two
# places is how they drift apart; there is now only one.
#
# The bar itself replaced a `> 0 translated` test. That test was right while a
# scaffold held literally nothing, and stopped being right the moment a locale was
# partially filled: a compendium merge from the Pro catalogue put 119 of ~4,280
# strings into ja / ko_KR / zh_CN, and `> 0` would have started shipping a .mo at
# 2.8% — a Japanese member seeing メンバー and スペース in a page of English.
# Half-translated reads as broken in a way that plain English does not, which is
# the complaint this whole area started from.
#
# 80 keeps every locale that ships today shipping (they sit at 88.3%) and holds
# the partials back until they are done. Override with BN_I18N_MIN_COVERAGE=0.
#
# (The hazard the empty JSON stubs were originally reported as — an empty msgstr
# rendering a BLANK label rather than falling back to English — was tested and
# does not happen: @wordpress/i18n falls back to the original string. What the
# gate prevents is package hygiene and a false claim of support, not blank UI.)
MIN_COVERAGE="${BN_I18N_MIN_COVERAGE:-80}"

for po in "$LANGS"/*.po; do
	[ -e "$po" ] || continue
	locale="$(basename "$po" .po)"

	# msgfmt is authoritative. The previous count came from
	# `grep -c '^msgstr "[^"]'`, which cannot see a multi-line msgstr or a
	# msgstr[n] plural form and so under-reported every locale by ~500. It never
	# broke the old 0-vs-nonzero gate, but it is wrong the instant a number is
	# compared against a threshold - or read by a human as evidence.
	stats="$(msgfmt --statistics -o /dev/null "$po" 2>&1)"
	translated="$(printf '%s' "$stats" | grep -oE '[0-9]+ translated' | grep -oE '[0-9]+' || echo 0)"
	untranslated="$(printf '%s' "$stats" | grep -oE '[0-9]+ untranslated' | grep -oE '[0-9]+' || echo 0)"
	total=$(( translated + untranslated ))
	coverage=0
	[ "$total" -gt 0 ] && coverage=$(( translated * 100 / total ))

	if [ "$coverage" -lt "$MIN_COVERAGE" ]; then
		printf '    skip  %s (%s%% translated, below the %s%% bar - %s/%s)\n' \
			"$locale" "$coverage" "$MIN_COVERAGE" "$translated" "$total"
		# Remove any artifact from a previous build. This is what deletes a stub
		# when a locale is emptied, and it is why the fix is self-healing rather
		# than a one-off cleanup.
		rm -f "$LANGS/$locale.mo"
		rm -f "$LANGS/$locale"-*.json
		continue
	fi

	msgfmt --output-file="$LANGS/$locale.mo" "$po"

	# Per-file, not per-directory: the directory form has no locale gate.
	# --no-purge keeps the JS strings in the .po so the catalogue stays complete
	# for translators.
	"$WP" i18n make-json "$po" "$LANGS" --no-purge >/dev/null

	json_count="$(find "$LANGS" -maxdepth 1 -name "$locale-*.json" | wc -l | tr -d ' ')"
	echo "    built $locale.mo + ${json_count} JSON ($translated strings)"
done

echo "Done. .pot + .po + .mo + .json regenerated in $LANGS/"

#!/usr/bin/env bash
# Icon-set conformance.
#
# The house rule (CLAUDE.md): icons are Lucide-style SVGs in assets/icons/,
# rendered through buddynext_icon(). They carry NO width/height, so the consumer
# decides the size in CSS — an icon that ships its own dimensions silently
# ignores the layout it is dropped into.
#
# THE SUBTLETY THIS SCRIPT EXISTS FOR: a naive `grep width=` matches the geometry
# on <rect width="20" height="14">, which every rect-based icon legitimately has.
# That false positive was reported as "11 icons violate the standard" and nearly
# triggered a rewrite of 11 healthy files. Only the ROOT <svg> tag is inspected
# here, which is the only place a size attribute would be wrong.
#
# Brand marks (apple, discord, facebook, github, google) are filled logos, not line
# icons. They are exempt from the stroke rule by design — a brand mark drawn in
# currentColor is no longer the brand.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

DIR="assets/icons"
[ -d "$DIR" ] || { printf '  no %s directory\n' "$DIR"; exit 0; }

fails=0
line_icons=0
brand_icons=0

for icon in "$DIR"/*.svg; do
	[ -e "$icon" ] || continue
	name="$(basename "$icon")"

	# The opening tag only — never the icon body.
	root="$(grep -oE '<svg[^>]*>' "$icon" | head -1)"

	if [ -z "$root" ]; then
		printf '  %s: no <svg> root element\n' "$name"
		fails=$((fails + 1))
		continue
	fi

	if printf '%s' "$root" | grep -qE '[[:space:]](width|height)='; then
		printf '  %s: root <svg> carries width/height — sizing belongs in CSS\n' "$name"
		fails=$((fails + 1))
	fi

	# Brand marks keep the grid their brand is drawn on. Google's "G" is a 48-box
	# asset whose four colour paths depend on that geometry; a viewBox scales to
	# whatever size CSS gives it, so redrawing it on a 24 grid would risk
	# distorting an official mark for no rendering benefit.
	case "$name" in
		apple.svg | discord.svg | facebook.svg | github.svg | google.svg) is_brand=1 ;;
		*) is_brand=0 ;;
	esac

	if [ "$is_brand" -eq 0 ] && ! printf '%s' "$root" | grep -q 'viewBox="0 0 24 24"'; then
		printf '  %s: viewBox must be "0 0 24 24" so icons align optically\n' "$name"
		fails=$((fails + 1))
	fi

	if printf '%s' "$root" | grep -q 'stroke="currentColor"'; then
		line_icons=$((line_icons + 1))
	else
		# Not a line icon. Only the known brand marks may opt out.
		if [ "$is_brand" -eq 1 ]; then
			brand_icons=$((brand_icons + 1))
		else
			printf '  %s: not stroke="currentColor" and not a known brand mark\n' "$name"
			fails=$((fails + 1))
		fi
	fi

	# A malformed icon renders as nothing at all, which reads as a missing feature
	# rather than a broken file.
	if ! python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse('$icon')" 2>/dev/null; then
		printf '  %s: not well-formed XML\n' "$name"
		fails=$((fails + 1))
	fi
done

# ── Reverse check: every icon we ASK FOR must exist ──────────────────────────
#
# Everything above validates the icons that are on disk. Nothing validated that
# the slugs our own code names resolve to one, and the check runs in the
# direction that cannot catch the bug that shipped: profile-hero.php asked for
# 'play-circle', no such file was ever added, and IconService::render() returns
# an EMPTY STRING for an unknown slug — so the YouTube chip rendered as a blank
# red square for every install of 1.1.5. No error, no warning, no notice. A
# blank slot reads as a styling problem, which is why it sat there (10233462637).
#
# Two exemptions, both real rather than convenient:
#   tab-*  — resolves through IconService's alias-then-fallback path
#            (assets/icons/{bare}.svg, else assets/svg/admin/{name}.svg).
#   brand marks — exempt from the STYLE rules above, but still must exist, so
#            they are deliberately NOT exempt here.
#
# Only a literal that closes the call is a slug. An earlier draft matched
# `buddynext_icon( 'following' === $relation ? 'user-plus' : 'users' )` and
# reported 'following' as missing — the comparison operand, not the icon.
missing_refs=0
while IFS= read -r ref; do
	[ -n "$ref" ] || continue

	if [ -f "$DIR/$ref.svg" ]; then
		continue
	fi

	case "$ref" in
		tab-*)
			bare="${ref#tab-}"
			if [ -f "$DIR/$bare.svg" ] || [ -f "assets/svg/admin/$ref.svg" ]; then
				continue
			fi
			printf '  referenced but missing: %s (no %s/%s.svg, no %s/%s.svg, no assets/svg/admin/%s.svg)\n' \
				"$ref" "$DIR" "$ref" "$DIR" "$bare" "$ref"
			;;
		*)
			printf '  referenced but missing: %s.svg — renders as nothing at all\n' "$ref"
			;;
	esac
	missing_refs=$((missing_refs + 1))
done < <(
	grep -rhoE "(buddynext_icon|buddynext_get_icon|IconService::render)\(\s*'[a-z0-9_-]+'\s*[,)]" \
		--include='*.php' includes templates blocks 2>/dev/null \
		| grep -oE "'[a-z0-9_-]+'" | tr -d "'" | sort -u
)

if [ "$missing_refs" -gt 0 ]; then
	fails=$((fails + missing_refs))
fi

if [ "$fails" -gt 0 ]; then
	printf '  %d icon issue(s)\n' "$fails"
	exit 1
fi

printf '  icons conform — %d Lucide line icons, %d brand marks, every referenced slug resolves\n' "$line_icons" "$brand_icons"
exit 0

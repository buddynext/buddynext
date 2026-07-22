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
# Brand marks (discord, facebook, github, google) are filled logos, not line
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
		discord.svg | facebook.svg | github.svg | google.svg) is_brand=1 ;;
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

if [ "$fails" -gt 0 ]; then
	printf '  %d icon issue(s)\n' "$fails"
	exit 1
fi

printf '  icons conform — %d Lucide line icons, %d brand marks\n' "$line_icons" "$brand_icons"
exit 0

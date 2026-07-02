#!/usr/bin/env bash
#
# BuddyNext i18n build — regenerate the .pot template and compile every .po in
# languages/ to the runtime files WordPress actually loads: .mo (PHP) + .json (JS).
#
# Usage:
#   bin/i18n.sh          regenerate .pot, then compile all .po -> .mo + .json
#   bin/i18n.sh pot      only regenerate the .pot template
#
# Requires WP-CLI on PATH (or set WP_CLI to its path).
#
set -euo pipefail
cd "$(dirname "$0")/.."

WP="${WP_CLI:-wp}"
DOMAIN="buddynext"
LANGS="languages"

echo "==> make-pot ($DOMAIN)"
"$WP" i18n make-pot . "$LANGS/$DOMAIN.pot" --slug="$DOMAIN" --domain="$DOMAIN" --skip-audit

if [ "${1:-all}" = "pot" ]; then
	echo "Done (.pot only)."
	exit 0
fi

echo "==> make-mo   (PHP  -> $DOMAIN-{locale}.mo)"
"$WP" i18n make-mo "$LANGS" "$LANGS"

echo "==> make-json (JS   -> $DOMAIN-{locale}-{hash}.json)"
"$WP" i18n make-json "$LANGS" --no-purge

echo "Done. .pot + .mo + .json regenerated in $LANGS/"

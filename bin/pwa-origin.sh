#!/usr/bin/env bash
# Start/stop the PWA test origin (see docker/pwa-test/README.md).
#
# A service worker needs a secure context. A plain-HTTP .local host is not one —
# 'serviceWorker' in navigator is FALSE there — so PWA behaviour cannot be tested
# against the dev site directly. 127.0.0.1 is a secure context whatever the
# scheme, so this proxies the site onto it.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../docker/pwa-test" && pwd)"
SITE_HOST="${SITE_HOST:-buddynext.local}"
PROXY_PORT="${PROXY_PORT:-8080}"
CMD="${1:-up}"

export SITE_HOST PROXY_PORT

case "$CMD" in
	up)
		docker compose -f "$DIR/docker-compose.yml" up -d
		printf '\n  %s is now testable at http://127.0.0.1:%s/\n' "$SITE_HOST" "$PROXY_PORT"
		printf '  Load a page and let the SITE register the worker, then:\n'
		printf '    %s stop      # simulate going offline\n' "$0"
		printf '    %s up        # bring it back\n\n' "$0"
		;;
	stop|offline)
		docker compose -f "$DIR/docker-compose.yml" stop
		printf '  offline — navigate WITHIN the page to see the offline fallback\n'
		;;
	down)
		docker compose -f "$DIR/docker-compose.yml" down
		;;
	*)
		printf 'usage: %s [up|stop|down]\n' "$0" >&2
		exit 1
		;;
esac

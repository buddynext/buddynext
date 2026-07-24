#!/usr/bin/env bash
# Start/stop an origin where PWA behaviour can actually be tested.
# See docker/pwa-test/README.md.
#
# A service worker only runs in a SECURE CONTEXT, and plain-HTTP buddynext.local
# is not one — 'serviceWorker' in navigator is literally false there — so PWA
# behaviour cannot be tested against the dev site directly.
#
#   up      loopback origin on 127.0.0.1 (a secure context whatever the scheme)
#   tunnel  public https:// origin via cloudflared — real TLS, real phones, and
#           shareable with QA, at the cost of exposing the dev site
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../docker/pwa-test" && pwd)"
SITE_HOST="${SITE_HOST:-buddynext.local}"
PROXY_PORT="${PROXY_PORT:-8080}"
CMD="${1:-up}"

compose() {
	PUBLIC_ORIGIN_ESCAPED="${PUBLIC_ORIGIN//\//\\/}" \
	SITE_HOST="$SITE_HOST" PROXY_PORT="$PROXY_PORT" PUBLIC_ORIGIN="$PUBLIC_ORIGIN" \
		docker compose -f "$DIR/docker-compose.yml" "$@"
}

case "$CMD" in
	up)
		PUBLIC_ORIGIN="http://127.0.0.1:${PROXY_PORT}"
		compose up -d
		printf '\n  %s is testable at %s\n\n' "$SITE_HOST" "$PUBLIC_ORIGIN"
		printf '  Load a page and let the SITE register the worker, then:\n'
		printf '    bin/pwa-origin.sh stop   # simulate going offline\n'
		printf '    bin/pwa-origin.sh up     # bring it back\n\n'
		;;

	tunnel)
		command -v cloudflared >/dev/null 2>&1 || {
			printf 'cloudflared is not installed:  brew install cloudflared\n' >&2
			exit 1
		}

		# Chicken-and-egg: the proxy must rewrite the site's URLs to the tunnel
		# hostname, but that hostname does not exist until cloudflared runs. Start
		# the proxy on loopback FIRST so cloudflared has something to connect to —
		# without this it reports 530 (origin unreachable) and keeps failing for a
		# while after the origin appears — then recreate it with the real hostname.
		PUBLIC_ORIGIN="http://127.0.0.1:${PROXY_PORT}"
		compose up -d >/dev/null 2>&1

		log="$(mktemp -t bn-tunnel)"
		cloudflared tunnel --url "http://localhost:${PROXY_PORT}" --no-autoupdate >"$log" 2>&1 &
		tunnel_pid=$!

		printf '  waiting for the tunnel hostname'
		url=""
		for _ in $(seq 1 40); do
			url="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$log" 2>/dev/null | head -1 || true)"
			[ -n "$url" ] && break
			printf '.'
			sleep 1
		done
		printf '\n'

		if [ -z "$url" ]; then
			printf '  could not read a tunnel URL from %s\n' "$log" >&2
			kill "$tunnel_pid" 2>/dev/null || true
			exit 1
		fi

		PUBLIC_ORIGIN="$url"
		compose up -d --force-recreate

		# The tunnel edge may still be retrying from before the origin existed.
		printf '  waiting for the tunnel to reach the origin'
		for _ in $(seq 1 20); do
			code="$(curl -s -o /dev/null -w '%{http_code}' "$url/" --max-time 15 || true)"
			case "$code" in 2*|3*) break ;; esac
			printf '.'
			sleep 2
		done
		printf '\n'

		printf '\n  %s is testable at %s\n' "$SITE_HOST" "$url"
		printf '  Real TLS, so this installs as a PWA and works on a phone.\n\n'
		printf '  THIS IS PUBLIC. wp-admin and every member page are reachable by\n'
		printf '  anyone with the link. Stop it as soon as the test is done:\n'
		printf '    kill %s && bin/pwa-origin.sh down\n\n' "$tunnel_pid"
		printf '  tunnel log: %s\n\n' "$log"
		;;

	stop|offline)
		PUBLIC_ORIGIN="http://127.0.0.1:${PROXY_PORT}" compose stop
		printf '  offline — navigate WITHIN the page to see the offline fallback\n'
		;;

	down)
		PUBLIC_ORIGIN="http://127.0.0.1:${PROXY_PORT}" compose down
		pkill -f 'cloudflared tunnel --url' 2>/dev/null || true
		;;

	*)
		printf 'usage: bin/pwa-origin.sh [up|tunnel|stop|down]\n' >&2
		exit 1
		;;
esac

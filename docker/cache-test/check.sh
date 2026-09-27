#!/usr/bin/env bash
# Page-cache compatibility check for BuddyNext. Usage: ./check.sh <cache>
#   cache: none | wp-super-cache | w3-total-cache | wp-rocket | litespeed-cache
#   (wp-rocket is commercial: drop your licensed wp-rocket.zip into zips/ and re-run ./setup.sh)
#   (litespeed-cache runs on the OpenLiteSpeed site: `docker compose --profile litespeed up -d`, then ./setup.sh litespeed)
# Each cache is switched on in its WORST case for a community: caching
# logged-in visitors too. Exit 1 on any FAIL.
set -uo pipefail
cd "$(dirname "$0")"
CACHE="${1:?usage: ./check.sh <none|wp-super-cache|w3-total-cache|wp-rocket|litespeed-cache>}"
if [ "$CACHE" = litespeed-cache ]; then
	CLI=cli-ols; WEB=ols; DOCROOT=/var/www/vhosts/localhost/html
	BASE="http://127.0.0.1:${BN_CACHE_OLS_PORT:-8092}"
else
	CLI=cli; WEB=wp; DOCROOT=/var/www/html
	BASE="http://127.0.0.1:${BN_CACHE_PORT:-8091}"
fi
JAR="$(mktemp)"
FAILS=0
wp() { docker compose exec -T "$CLI" wp "$@"; }
stamp() { grep -o 'name="bn-bench-render" content="[0-9a-f.]*"' | head -1; }
pass() { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; FAILS=$((FAILS + 1)); }

cache_off() {
	# Optimisers too: each run starts from a clean site (JS optimisation is
	# checked separately in a browser, see README).
	wp plugin deactivate wp-super-cache w3-total-cache wp-rocket litespeed-cache autoptimize >/dev/null 2>&1
	wp config set WP_CACHE false --raw >/dev/null 2>&1
	docker compose exec -T "$WEB" sh -c "rm -rf $DOCROOT/wp-content/advanced-cache.php $DOCROOT/wp-content/cache/*" >/dev/null 2>&1
	# WP Rocket writes into these on the next request; recreate them empty.
	docker compose exec -T "$CLI" sh -c "mkdir -p /var/www/html/wp-content/cache/wp-rocket /var/www/html/wp-content/cache/busting" >/dev/null 2>&1
}

cache_on() {
	case "$CACHE" in
	none) ;;
	wp-super-cache)
		wp plugin activate wp-super-cache >/dev/null 2>&1
		wp config set WP_CACHE true --raw >/dev/null
		# PHP ("WP-Cache") mode, caching known (logged-in) users too.
		wp eval 'wp_cache_create_advanced_cache(); wp_cache_enable(); wp_cache_setting( "super_cache_enabled", false ); wp_cache_setting( "wp_cache_not_logged_in", 0 ); wp_cache_setting( "wp_cache_mod_rewrite", 0 );' >/dev/null
		;;
	w3-total-cache)
		wp plugin activate w3-total-cache >/dev/null 2>&1
		# Page cache on, Disk: Basic engine, logged-in users cached too.
		wp w3-total-cache option set pgcache.enabled true --type=boolean >/dev/null
		wp w3-total-cache option set pgcache.engine file >/dev/null
		wp w3-total-cache option set pgcache.reject.logged false --type=boolean >/dev/null
		wp config set WP_CACHE true --raw >/dev/null
		wp w3-total-cache fix_environment >/dev/null
		;;
	wp-rocket)
		wp plugin is-installed wp-rocket 2>/dev/null || { echo "wp-rocket not installed: put wp-rocket.zip in zips/ and re-run ./setup.sh" >&2; exit 2; }
		wp plugin activate wp-rocket >/dev/null 2>&1
		wp config set WP_CACHE true --raw >/dev/null
		# Defaults come from WP Rocket's own installer (admin-only file), then
		# "Enable caching for logged-in WordPress users" on.
		wp eval 'require_once WP_ROCKET_PATH . "inc/admin/upgrader.php"; if ( ! get_option( WP_ROCKET_SLUG ) ) { rocket_first_install(); } $o = get_option( WP_ROCKET_SLUG ); $o["cache_logged_user"] = 1; update_option( WP_ROCKET_SLUG, $o ); rocket_generate_config_file(); rocket_generate_advanced_cache_file(); flush_rocket_htaccess();' >/dev/null
		;;
	litespeed-cache)
		wp plugin activate litespeed-cache >/dev/null 2>&1
		# Public cache on, and "Cache Logged-in Users" (private cache) on.
		wp litespeed-option set cache true >/dev/null
		wp litespeed-option set cache-priv true >/dev/null
		# OpenLiteSpeed reads the plugin's .htaccess cache rules at start-up.
		docker compose exec -T ols /usr/local/lsws/bin/lswsctrl restart >/dev/null && sleep 2
		;;
	*) echo "unknown cache: $CACHE" >&2; exit 2 ;;
	esac
}

purge() {
	if [ "$CACHE" = wp-rocket ]; then
		# WP Rocket needs its own cache folders to exist; clear through its API.
		wp eval 'rocket_clean_domain();' >/dev/null 2>&1
	else
		docker compose exec -T "$WEB" sh -c "rm -rf $DOCROOT/wp-content/cache/*" >/dev/null 2>&1
	fi
	# LiteSpeed's cache lives in the server, and `wp litespeed-purge` cannot
	# reach it from the CLI container, so clear the store directly.
	[ "$CACHE" = litespeed-cache ] && docker compose exec -T ols sh -c 'rm -rf /usr/local/lsws/cachedata/*' >/dev/null 2>&1
	wp cache flush >/dev/null 2>&1
}

login_member() {
	rm -f "$JAR"
	curl -s -c "$JAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null \
		-d 'log=member&pwd=member&testcookie=1&redirect_to=/' "$BASE/wp-login.php"
	grep -q wordpress_logged_in "$JAR" || { echo "member login failed" >&2; exit 2; }
}

echo "== $CACHE"
cache_off
cache_on
MEMBER_ID="$(wp user get member --field=ID)"
wp user meta delete "$MEMBER_ID" bn_onboarding_complete >/dev/null 2>&1
purge
login_member

# 1. Customer path (card 10344282503): the wizard reloads after a step is saved.
wp user meta update "$MEMBER_ID" bn_onboarding_step 1 >/dev/null
curl -s -b "$JAR" "$BASE/onboarding/" >/dev/null
curl -s -b "$JAR" "$BASE/onboarding/" >/dev/null
wp user meta update "$MEMBER_ID" bn_onboarding_step 3 >/dev/null
STEP="$(curl -s -b "$JAR" "$BASE/onboarding/" | grep -o 'Step [0-9] of [0-9]' | head -1)"
case "$STEP" in
	"Step 3 of"*) pass "onboarding reload shows the saved step ($STEP)" ;;
	*) fail "onboarding reload shows '$STEP', expected step 3 (served from cache)" ;;
esac

# 2. No community page is served from cache to a logged-in member.
for path in /activity/ /activity/explore/ /members/ /members/admin/ /spaces/ /notifications/ /settings/ /onboarding/; do
	a="$(curl -s -b "$JAR" "$BASE$path" | stamp)"
	b="$(curl -s -b "$JAR" "$BASE$path" | stamp)"
	if [ -z "$b" ]; then
		fail "member $path: no render stamp (page did not render)"
	elif [ "$a" = "$b" ]; then
		fail "member $path served from cache"
	else
		pass "member $path rendered fresh"
	fi
done

# 3. Guests still get cached public pages (a page cache must keep working),
#    including hub sub-routes (explore, leaderboard, a profile), and never a
#    cached login page (it carries per-request tokens).
purge
for path in /spaces/ /activity/explore/ /activity/leaderboard/ /members/admin/ /login/; do
	# Warm first: some caches (LiteSpeed with JS/CSS combine on) store a page
	# only once its optimised assets exist, not on the very first request.
	curl -s "$BASE$path" >/dev/null; sleep 1
	a="$(curl -s "$BASE$path" | stamp)"
	b="$(curl -s "$BASE$path" | stamp)"
	cached=no; [ -n "$a" ] && [ "$a" = "$b" ] && cached=yes
	if [ "$path" = "/login/" ]; then
		[ "$cached" = no ] && pass "guest /login/ never cached" || fail "guest /login/ served from cache"
	elif [ "$CACHE" = none ]; then
		pass "guest $path (no cache plugin: nothing to check)"
	else
		[ "$cached" = yes ] && pass "guest $path served from cache" || fail "guest $path not cached (cache not doing its job for guests)"
	fi
done

cache_off
rm -f "$JAR"
echo "== $CACHE: $FAILS failure(s)"
[ "$FAILS" -eq 0 ]

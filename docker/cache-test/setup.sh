#!/usr/bin/env bash
# Install (or repair) a bench site. Idempotent: safe to re-run.
#   ./setup.sh             Apache site  -> http://127.0.0.1:8091
#   ./setup.sh litespeed   OpenLiteSpeed site -> http://127.0.0.1:8092
#                          (start it first: docker compose --profile litespeed up -d)
set -euo pipefail
cd "$(dirname "$0")"

if [ "${1:-}" = litespeed ]; then
	CLI=cli-ols; DB=wpols; REDIS_DB=1; PORT="${BN_CACHE_OLS_PORT:-8092}"
else
	CLI=cli; DB=wp; REDIS_DB=0; PORT="${BN_CACHE_PORT:-8091}"
fi
wp() { docker compose exec -T "$CLI" wp "$@"; }
sh_in() { docker compose exec -T "$CLI" sh -c "$1"; }

# Everything is downloaded on the host (container downloads have stalled) and
# installed from ./zips, which both CLI containers mount read-only.
mkdir -p zips
for p in redis-cache wp-super-cache w3-total-cache litespeed-cache autoptimize; do
	[ -s "zips/$p.zip" ] || curl -sSL -m 300 -o "zips/$p.zip" "https://downloads.wordpress.org/plugin/$p.latest-stable.zip"
done
[ -s zips/wordpress.tar.gz ] || curl -sSL -m 300 -o zips/wordpress.tar.gz https://wordpress.org/latest.tar.gz

# The OpenLiteSpeed docroot starts empty: WordPress core + wp-config.
if [ "$CLI" = cli-ols ]; then
	docker compose exec -T db mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS $DB; GRANT ALL ON $DB.* TO 'wp'@'%';"
	# The volume (and the dirs made for the BuddyNext mount) start root-owned.
	docker compose exec -T ols chown 33:33 /var/www/vhosts/localhost/html /var/www/vhosts/localhost/html/wp-content /var/www/vhosts/localhost/html/wp-content/plugins
	sh_in '[ -f /var/www/html/wp-load.php ] || tar -xzf /zips/wordpress.tar.gz -C /var/www/html --strip-components=1'
	sh_in '[ -f /var/www/html/wp-config.php ]' || wp config create --dbname="$DB" --dbuser=wp --dbpass=wp --dbhost=db --skip-check \
		--extra-php <<PHP
define( 'WP_REDIS_HOST', 'redis' );
define( 'WP_REDIS_DATABASE', $REDIS_DB );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP
fi

if ! wp core is-installed 2>/dev/null; then
	wp core install --url="http://127.0.0.1:${PORT}" --title="BN cache bench" \
		--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
fi
wp rewrite structure '/%postname%/' >/dev/null 2>&1
# WP-CLI cannot detect the web server's rewrite module from its own container,
# so write the standard WordPress block directly (a cache plugin adds its own).
sh_in 'grep -q "BEGIN WordPress" /var/www/html/.htaccess 2>/dev/null || printf "%s\n" "# BEGIN WordPress" "<IfModule mod_rewrite.c>" "RewriteEngine On" "RewriteBase /" "RewriteRule ^index\\.php\$ - [L]" "RewriteCond %{REQUEST_FILENAME} !-f" "RewriteCond %{REQUEST_FILENAME} !-d" "RewriteRule . /index.php [L]" "</IfModule>" "# END WordPress" >> /var/www/html/.htaccess'

wp plugin activate buddynext >/dev/null 2>&1
for p in redis-cache wp-super-cache w3-total-cache litespeed-cache autoptimize; do
	wp plugin is-installed "$p" 2>/dev/null || wp plugin install "/zips/$p.zip" >/dev/null
done
wp plugin activate redis-cache >/dev/null 2>&1
wp redis enable >/dev/null 2>&1 || true

# Render stamp: a per-request marker in every page, so check.sh can tell a
# cached copy (same stamp twice) from a fresh render without trusting any one
# plugin's own markers. Bench-only; lives in the site, not in BuddyNext.
# A <meta> tag, not an HTML comment: optimisers (Autoptimize) strip comments.
sh_in 'mkdir -p /var/www/html/wp-content/mu-plugins && printf "%s\n" "<?php" "add_action( \"wp_head\", static function () { echo \"<meta name=\\\"bn-bench-render\\\" content=\\\"\" . uniqid( \"\", true ) . \"\\\">\"; }, 1 );" > /var/www/html/wp-content/mu-plugins/bn-bench-stamp.php'

# The member the checks log in as, with onboarding not yet finished.
wp user get member >/dev/null 2>&1 || wp user create member member@example.test --role=subscriber --user_pass=member >/dev/null
MEMBER_ID="$(wp user get member --field=ID)"
wp user meta delete "$MEMBER_ID" bn_onboarding_complete >/dev/null 2>&1 || true
wp user meta update "$MEMBER_ID" bn_onboarding_step 1 >/dev/null

# OpenLiteSpeed reads .htaccess at start-up, so pick up the rewrite block.
[ "$CLI" = cli-ols ] && docker compose exec -T ols /usr/local/lsws/bin/lswsctrl restart >/dev/null && sleep 2

echo "Site: http://127.0.0.1:${PORT}  (admin/admin, member/member)"
wp redis status 2>/dev/null | grep -E "^Status" || true

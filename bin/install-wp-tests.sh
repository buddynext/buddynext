#!/usr/bin/env bash
# -----------------------------------------------------------------------------
# Install the WordPress PHPUnit test suite.
#
# Usage:
#   bin/install-wp-tests.sh <db_name> <db_user> <db_pass> [db_host] [wp_version]
#
# Example (matches Local by Flywheel default credentials):
#   bin/install-wp-tests.sh buddynext_test root root localhost latest
#
# The script will:
#   1. Download WordPress core into /tmp/wordpress
#   2. Download the WP test suite into /tmp/wordpress-tests-lib
#   3. Create a wp-tests-config.php pointing at the test database
#
# The test database is created and dropped each test run — never use a production DB.
# -----------------------------------------------------------------------------

set -euo pipefail

DB_NAME="${1:-buddynext_test}"
DB_USER="${2:-root}"
DB_PASS="${3:-root}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

download() {
	local url="$1"
	local dest="$2"

	if command -v curl &>/dev/null; then
		curl --silent --location "$url" --output "$dest"
	elif command -v wget &>/dev/null; then
		wget --quiet --output-document="$dest" "$url"
	else
		echo "Error: neither curl nor wget is available." >&2
		exit 1
	fi
}

# ---------------------------------------------------------------------------
# Resolve WP version tag
# ---------------------------------------------------------------------------

if [ "$WP_VERSION" = "latest" ]; then
	WP_TESTS_TAG="trunk"
else
	# Convert "6.5" → "tags/6.5"
	WP_TESTS_TAG="tags/${WP_VERSION}"
fi

# ---------------------------------------------------------------------------
# Install WordPress core (needed for test bootstrapping)
# ---------------------------------------------------------------------------

if [ ! -d "$WP_CORE_DIR/src" ]; then
	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" = "latest" ]; then
		local_package=""
	else
		local_package="/tmp/wordpress-${WP_VERSION}.tar.gz"
		if [ ! -f "$local_package" ]; then
			download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" "$local_package"
		fi
	fi

	if [ -n "${local_package:-}" ] && [ -f "$local_package" ]; then
		tar --strip-components=1 -zxmf "$local_package" -C "$WP_CORE_DIR"
	else
		download https://wordpress.org/latest.tar.gz /tmp/latest-wordpress.tar.gz
		tar --strip-components=1 -zxmf /tmp/latest-wordpress.tar.gz -C "$WP_CORE_DIR"
	fi
fi

# ---------------------------------------------------------------------------
# Install the WP test suite
# ---------------------------------------------------------------------------

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
	mkdir -p "$WP_TESTS_DIR"

	svn export --quiet \
		"https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" \
		"${WP_TESTS_DIR}/includes"

	svn export --quiet \
		"https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" \
		"${WP_TESTS_DIR}/data"
fi

# ---------------------------------------------------------------------------
# Write wp-tests-config.php
# ---------------------------------------------------------------------------

if [ ! -f "$WP_TESTS_DIR/wp-tests-config.php" ]; then
	download \
		"https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" \
		"$WP_TESTS_DIR/wp-tests-config.php"

	# sed -i has incompatible BSD (macOS) and GNU forms — use -i.bak then
	# remove the backup for portability across both.
	SED_INPLACE=( -i.bak )
	sed "${SED_INPLACE[@]}" "s|dirname( __FILE__ ) . '/src/'|'${WP_CORE_DIR}/'|" "$WP_TESTS_DIR/wp-tests-config.php"
	sed "${SED_INPLACE[@]}" "s|youremptytestdbnamehere|${DB_NAME}|"               "$WP_TESTS_DIR/wp-tests-config.php"
	sed "${SED_INPLACE[@]}" "s|yourusernamehere|${DB_USER}|"                      "$WP_TESTS_DIR/wp-tests-config.php"
	sed "${SED_INPLACE[@]}" "s|yourpasswordhere|${DB_PASS}|"                      "$WP_TESTS_DIR/wp-tests-config.php"
	sed "${SED_INPLACE[@]}" "s|localhost|${DB_HOST}|"                             "$WP_TESTS_DIR/wp-tests-config.php"
	rm -f "$WP_TESTS_DIR/wp-tests-config.php.bak"
fi

# ---------------------------------------------------------------------------
# Create the test database (drop and recreate for a clean slate)
# ---------------------------------------------------------------------------

EXTRA_ARGS=()
if [ "$DB_HOST" != "localhost" ]; then
	EXTRA_ARGS+=( "--host=${DB_HOST}" )
fi

mysql \
	"${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}" \
	--user="$DB_USER" \
	--password="$DB_PASS" \
	--execute="DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`;" \
	2>/dev/null || true

echo "WordPress test suite installed."
echo "  WP core:   ${WP_CORE_DIR}"
echo "  Test lib:  ${WP_TESTS_DIR}"
echo "  Test DB:   ${DB_NAME}@${DB_HOST}"

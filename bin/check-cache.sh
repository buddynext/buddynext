#!/usr/bin/env bash
#
# Cache conformance gate.
#
# Enforces the caching standard mechanically, so drift is caught by CI instead of by an
# audit once a year. Checks, per service that calls wp_cache_set():
#
#   1. it declares a CACHE_GROUP const
#   2. the group is namespaced for its repo (buddynext_* in free, buddynextpro_* in pro)
#   3. it declares a CACHE_TTL const (never a bare literal at the call site)
#   4. it has SOME invalidation path — a delete-by-key, a version/generation bump, or an
#      explicit opt-out comment saying the TTL is the mechanism on purpose
#
# (4) is the one that matters. A cached read with no bust is invisible on a dev box —
# wp_cache_* is request-local without Redis — and only breaks on production sites that
# installed a persistent object cache. See the CACHING standard (buddynext-pro/free-internal/docs/standards/CACHING.md) sections 4b and 4c.
#
# Opt out of (4) at the cache site with:
#   // cache-ttl-only: <reason>
#
# Usage: bin/check-cache.sh [--repo free|pro]

set -uo pipefail

REPO_KIND="free"
[[ "${1:-}" == "--repo" && -n "${2:-}" ]] && REPO_KIND="$2"

if [[ "$REPO_KIND" == "pro" ]]; then
	WANT_PREFIX="buddynextpro_"
else
	WANT_PREFIX="buddynext_"
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAIL=0

while IFS= read -r file; do
	grep -q "wp_cache_set(" "$file" || continue

	rel="${file#"$ROOT"/}"

	group="$(grep -oE "CACHE_GROUP\s*=\s*'[^']+'" "$file" | head -1 | sed "s/.*'\(.*\)'/\1/")"
	has_ttl="$(grep -cE "CACHE_TTL\s*=\s*[0-9]+|_TTL\s*=\s*[0-9]+" "$file")"

	# Any sanctioned invalidation mechanism (standard section 4b).
	has_delete="$(grep -cE "wp_cache_delete\(" "$file")"
	has_version="$(grep -cE "\{\\\$(gen|ver|version)\w*\}|_version\(" "$file")"
	has_flushgroup="$(grep -cE "wp_cache_flush_group\(" "$file")"
	has_optout="$(grep -cE "cache-ttl-only:" "$file")"

	if [[ -z "$group" ]]; then
		echo "  MISSING GROUP    $rel — wp_cache_set() with no CACHE_GROUP const"
		FAIL=1
	elif [[ "$group" != ${WANT_PREFIX}* ]]; then
		echo "  BAD GROUP        $rel — '$group' must start with '${WANT_PREFIX}'"
		FAIL=1
	fi

	if [[ "$has_ttl" -eq 0 ]]; then
		echo "  BARE TTL         $rel — declare the TTL as a const, not a literal"
		FAIL=1
	fi

	# Group flush is NOT a per-write invalidation path. It is a silent no-op on any object
	# cache drop-in that does not implement flush_group, AND even where it works it nukes the
	# whole group for every user on one member's write. Standard section 5.
	if [[ "$has_flushgroup" -gt 0 && "$rel" != *"Core/CacheService.php" ]]; then
		echo "  GROUP FLUSH      $rel — wp_cache_flush_group() is not a per-write bust."
		echo "                   Silent no-op on drop-ins without flush_group support, and it"
		echo "                   destroys every user's cache on one member's write. Use"
		echo "                   delete-by-key, or a version key for unbounded key sets."
		FAIL=1
	fi

	if [[ "$has_delete" -eq 0 && "$has_version" -eq 0 && "$has_optout" -eq 0 ]]; then
		echo "  NO INVALIDATION  $rel — cached read with no bust path."
		echo "                   Use delete-by-key, a version key, or mark it:  // cache-ttl-only: <reason>"
		FAIL=1
	fi
done < <(find "$ROOT/includes" -name '*.php' -type f 2>/dev/null | sort)

if [[ "$FAIL" -eq 0 ]]; then
	echo "✓ cache conformance clean"
else
	echo ""
	echo "✗ cache drift — see the CACHING standard (buddynext-pro/free-internal/docs/standards/CACHING.md) (sections 4b + 4c)"
fi

exit "$FAIL"

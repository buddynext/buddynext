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

	# ── Match against CODE, not comments ──────────────────────────────────────
	#
	# This gate used to grep the raw file, so a DOCBLOCK mentioning a function counted
	# as a call to it. FeedCache was reported as a GROUP FLUSH offender because its
	# docblock explains that it deliberately AVOIDS wp_cache_flush_group() and bumps a
	# version instead — the gate flagged the file for saying so.
	#
	# That is the same failure that produced 8 phantom "contested tables" in the seam
	# audit: grep finds strings, not code. A gate that cries wolf gets switched off by
	# the first person it blocks, which is exactly why this one sat unwired.
	#
	# `php -w` strips comments and returns the code. Fall back to the raw file if PHP
	# is unavailable, so the gate degrades to its old (noisier) behaviour rather than
	# silently passing everything.
	code="$(php -w "$file" 2>/dev/null)" || code=""
	[[ -z "$code" ]] && code="$(cat "$file")"

	# A TRAIT declares neither — its consts come from the consuming class. A per-file grep
	# cannot see across that boundary, so it reported CachesAggregates (the trait that EXISTS to
	# do caching correctly) as having no group and no TTL. A gate that fails the reference
	# implementation of the thing it is checking is not measuring anything.
	is_trait="$(printf '%s' "$code" | grep -cE "^\s*trait\s+[A-Za-z_]")"

	# ANY const whose name ends in GROUP — not only one literally called CACHE_GROUP.
	# CacheService uses GROUP, FeedCache uses GROUP_GLOBAL/GROUP_USER, PresenceService uses
	# THROTTLE_GROUP. All three declare a group. All three were reported as MISSING GROUP.
	group="$(printf '%s' "$code" | grep -oE "[A-Z_]*GROUP[A-Z_]*\s*=\s*'[^']+'" | head -1 | sed "s/.*'\(.*\)'/\1/")"

	# A file may legitimately use a group const declared by a COLLABORATOR — WidgetService
	# passes WidgetCache::GROUP_GLOBAL, which is the group being owned in one place, exactly as
	# the standard asks. Treat that as having a group.
	if [[ -z "$group" ]] && printf '%s' "$code" | grep -qE "wp_cache_(get|set|delete)\([^;]*[A-Za-z_]+::[A-Z_]*GROUP"; then
		group="${WANT_PREFIX}_delegated"
	fi

	# BARE TTL is a property of the CALL SITE, not of whether a const exists somewhere in the
	# file. The old check grepped for a const declaration, so it flagged CacheService and
	# RateLimiter — which take the TTL as a PARAMETER (`wp_cache_set( $key, $value, self::GROUP,
	# $ttl )`), the most correct shape there is — while a file with an unused const and a magic
	# number at the call site passed.
	#
	# So: count wp_cache_set() calls whose 4th argument is a bare number. Anything else — a
	# variable, self::CONST, or a named constant like HOUR_IN_SECONDS — is fine.
	# [^()]* — the call must contain no NESTED call. Without that anchor this regex matched the
	# closing paren of an inner wp_rand( 1, 99999 ) and reported FollowService and
	# SpaceSuggestionService, both of which pass self::CACHE_TTL correctly. Conservative on
	# purpose: a gate that cries wolf is a gate that gets switched off.
	#
	# A TTL of literal 0 is exempt — it means "no expiry", which is the right thing for a
	# version key and is not a magic number.
	bare_ttl_calls="$(printf '%s' "$code" | grep -cE "wp_cache_set\([^()]*,\s*[1-9][0-9]*\s*\)")"
	has_ttl=1
	[[ "$bare_ttl_calls" -gt 0 ]] && has_ttl=0

	# Any sanctioned invalidation mechanism (standard §4b).
	has_delete="$(printf '%s' "$code" | grep -cE "wp_cache_delete\(")"
	has_version="$(printf '%s' "$code" | grep -cE "\{\\\$(gen|ver|version)\w*\}|_version\(|generation")"
	has_flushgroup="$(printf '%s' "$code" | grep -cE "wp_cache_flush_group\(")"

	# DELEGATED invalidation. A service may hand its busting to a collaborator rather
	# than calling wp_cache_delete() itself — FeedService does exactly this
	# (`$this->cache?->invalidate_writer( $user_id )`). The old per-file grep could not
	# see it and reported the file as having NO invalidation at all, which is false.
	has_delegate="$(printf '%s' "$code" | grep -cE "\->(invalidate|forget|bust|purge)[a-z_]*\(")"

	# The opt-out marker is deliberately a COMMENT, so it must be read from the raw
	# file — the stripped code no longer contains it.
	has_optout="$(grep -cE "cache-ttl-only:" "$file")"

	if [[ "$is_trait" -gt 0 ]]; then
		# The consuming class owns the consts. Nothing to check here.
		:
	elif [[ -z "$group" ]]; then
		echo "  MISSING GROUP    $rel — wp_cache_set() with no *GROUP const"
		FAIL=1
	elif [[ "$group" != "${WANT_PREFIX%_}" && "$group" != ${WANT_PREFIX}* ]]; then
		# The bare prefix itself is the SHARED group (CacheService uses it), and it is
		# namespaced by definition. What is not allowed is a group that does not start with the
		# prefix at all — like the 'bn_media' MediaRenderer used to use.
		echo "  BAD GROUP        $rel — '$group' must be '${WANT_PREFIX%_}' or '${WANT_PREFIX}<domain>'"
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

	if [[ "$has_delete" -eq 0 && "$has_version" -eq 0 && "$has_delegate" -eq 0 && "$has_optout" -eq 0 ]]; then
		echo "  NO INVALIDATION  $rel — cached read with no bust path."
		echo "                   Use delete-by-key, a version key, delegate to a collaborator,"
		echo "                   or mark it:  // cache-ttl-only: <reason>"
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

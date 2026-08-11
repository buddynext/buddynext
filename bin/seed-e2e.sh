#!/usr/bin/env bash
#
# Seed the deterministic world the BuddyNext E2E browser suite needs, idempotently.
#
# Safe to re-run. Used by the CI `e2e` job after activating the plugin, and reusable
# locally against any WP install (set BN_WP_PATH to target one; without it, wp-cli's
# own path resolution applies). The suite drives every ACTION through the real
# browser UI — this only stands up the fixed world those journeys read: a populated
# community plus the second actor the owner-gate / two-actor specs need.
#
# Usage:
#   BN_WP_PATH=/path/to/wp bash bin/seed-e2e.sh
#
# Environment:
#   BN_WP_PATH          WP root to target (optional).
#   BN_TEST_OTHER_USER  login for the second, non-owner member (default bn_e2e_other).
#
set -uo pipefail

WP=(wp)
if [ -n "${BN_WP_PATH:-}" ]; then
	WP=(wp "--path=${BN_WP_PATH}")
fi

log() { printf '  seed: %s\n' "$*"; }

# 1. A realistic demo community — members, spaces, posts, social graph, profile
#    fields — everything the directory / feed / spaces journeys read. The seeder
#    no-ops when the site is already seeded, so this is safe to re-run.
log 'demo community (wp buddynext demo seed)'
"${WP[@]}" buddynext demo seed || log 'demo seed step skipped (already seeded or unavailable)'

# 2. A second, non-owner member for the owner-gate / two-actor specs
#    (BN_TEST_OTHER_USER). The PRIMARY actor is the site admin (user 1), logged in
#    by the dev-auto-login mu-plugin via ?autologin=1.
OTHER="${BN_TEST_OTHER_USER:-bn_e2e_other}"
if ! "${WP[@]}" user get "$OTHER" --field=ID >/dev/null 2>&1; then
	log "member ${OTHER}"
	"${WP[@]}" user create "$OTHER" "${OTHER}@example.com" \
		--display_name="E2E Other" --role=subscriber --user_pass=password >/dev/null \
		|| log "could not create ${OTHER}"
fi

# 3. Best-effort un-gating: register one member-type so the member-type journeys run
#    instead of softSkipping. Never fatal — a failure here only leaves those few
#    specs skipping (a known harness gap), it must not fail the whole seed. The
#    default profile fields (incl. pronouns) come from step 1's seeder.
"${WP[@]}" eval '
if ( function_exists( "buddynext_service" ) ) {
	$svc = buddynext_service( "member_types" );
	if ( $svc && method_exists( $svc, "create" ) ) {
		$have = false;
		foreach ( (array) $svc->get_all_with_counts() as $t ) {
			if ( ( $t["slug"] ?? "" ) === "e2e-type" ) { $have = true; break; }
		}
		if ( ! $have ) {
			try { $svc->create( array( "slug" => "e2e-type", "name" => "E2E Type" ) ); } catch ( \Throwable $e ) {}
		}
	}
}
' >/dev/null 2>&1 || log 'member-type best-effort step skipped'

log 'done'

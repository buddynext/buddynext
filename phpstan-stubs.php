<?php
/**
 * PHPStan stubs for symbols that exist at RUNTIME but not in analysis scope.
 *
 * These are not our code and not bugs — they are external symbols PHPStan cannot
 * see: WP-CLI's namespaced helpers (only loaded in a `wp` process) and the
 * optional wb-gamification and Jetonomy plugins (soft integrations we call only
 * behind a function_exists() guard). Stubbing them here is the correct root fix
 * — never an @phpstan-ignore on the call site, which would also hide a real typo.
 *
 * Loaded via phpstan.neon `bootstrapFiles`. Braced namespaces because this file
 * declares symbols in several namespaces; each guard keeps it inert if a real
 * definition is ever autoloaded during analysis.
 *
 * @package BuddyNext
 */

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
		/**
		 * @param string               $format Output format (table|csv|json|yaml|count|ids).
		 * @param array<int,mixed>      $items  Rows to render.
		 * @param array<int,string>|string $fields Columns.
		 * @return void
		 */
		function format_items( $format, $items, $fields ) {} // phpcs:ignore
	}
}

namespace {
	if ( ! function_exists( 'wb_gam_send_kudos' ) ) {
		/**
		 * Stub for the wb-gamification public helper. GamificationKudos::register()
		 * wires the send path only when the real function exists.
		 *
		 * @return true|\WP_Error
		 */
		function wb_gam_send_kudos( int $giver_id, int $receiver_id, string $message = '' ) { // phpcs:ignore
			return true;
		}
	}
}

namespace Jetonomy {
	if ( ! function_exists( 'Jetonomy\\route_url' ) ) {
		/**
		 * Stub for Jetonomy's route helper. BuddyNext calls it only after
		 * GamificationBridge::leaderboard_deferred(), which checks it exists.
		 */
		function route_url( string $route, ...$args ): string { // phpcs:ignore
			return '';
		}
	}

	if ( ! function_exists( 'Jetonomy\\notification_targets_visible' ) ) {
		/**
		 * Stub for Jetonomy's notification visibility rule for mirrored rows.
		 * JetonomyBridgeListener::filter_visible_rows() checks it exists first.
		 *
		 * @param int                                   $viewer_id Recipient.
		 * @param array<int|string,array<string,mixed>> $targets   Keyed targets.
		 * @return array<int|string,bool>
		 */
		function notification_targets_visible( int $viewer_id, array $targets ): array { // phpcs:ignore
			return array();
		}
	}
}

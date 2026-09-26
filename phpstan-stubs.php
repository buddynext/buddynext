<?php
/**
 * PHPStan stubs for symbols that exist at RUNTIME but not in analysis scope.
 *
 * These are not our code and not bugs — they are external symbols PHPStan cannot
 * see: WP-CLI's namespaced helpers (only loaded in a `wp` process) and the
 * optional wb-gamification plugin (a soft integration we call only behind an
 * is_callable()/class_exists() guard). Stubbing them here is the correct root fix
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

namespace WBGam\Engine {
	if ( ! class_exists( 'WBGam\\Engine\\BadgeShare' ) ) {
		/**
		 * Stub for the optional wb-gamification badge-share engine. BuddyNext calls
		 * it only behind is_callable( [ '\WBGam\Engine\BadgeShare', 'shared_badges' ] ).
		 */
		class BadgeShare {
			/**
			 * @param int $user_id Member whose shared badges to return.
			 * @return array<int,int> Post ids of the member's publicly shared badges.
			 */
			public static function shared_badges( $user_id ) { // phpcs:ignore
				return array();
			}
		}
	}

	if ( ! class_exists( 'WBGam\\Engine\\KudosEngine' ) ) {
		/**
		 * Stub for the optional wb-gamification kudos engine (signatures from 1.6.5).
		 * Every call site is behind an is_callable() guard.
		 */
		class KudosEngine {
			public static function send( int $giver_id, int $receiver_id, string $message = '' ): bool|\WP_Error { // phpcs:ignore
				return true;
			}
			public static function can_send( int $giver_id ): bool { // phpcs:ignore
				return true;
			}
			public static function has_recent_kudos_to_receiver( int $giver_id, int $receiver_id, int $cooldown_seconds ): bool { // phpcs:ignore
				return false;
			}
			public static function get_received_count( int $user_id ): int { // phpcs:ignore
				return 0;
			}
			/**
			 * @return array<int,array<string,mixed>>
			 */
			public static function get_received( int $user_id, int $limit = 20 ): array { // phpcs:ignore
				return array();
			}
		}
	}

	if ( ! class_exists( 'WBGam\\Engine\\Registry' ) ) {
		/**
		 * Stub for the wb-gamification action registry (1.6.5 category labels).
		 */
		class Registry {
			public static function category_label( string $slug ): string { // phpcs:ignore
				return $slug;
			}
		}
	}
}

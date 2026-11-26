<?php
/**
 * PHPStan stubs for symbols that exist at RUNTIME but not in analysis scope.
 *
 * These are not our code and not bugs — they are external symbols PHPStan cannot
 * see: WP-CLI's namespaced helpers (only loaded in a `wp` process) and the
 * optional wb-gamification, Jetonomy and WPMediaVerse plugins (soft integrations we call only
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
		 * @param string                   $format Output format (table|csv|json|yaml|count|ids).
		 * @param array<int,mixed>         $items  Rows to render.
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
	if ( ! class_exists( 'Jetonomy\\Avatar' ) ) {
		/**
		 * Stub for Jetonomy's avatar resolver (signature copied from
		 * includes/class-avatar.php). AvatarService calls prime() behind method_exists().
		 */
		class Avatar {
			/**
			 * Prime the avatar lookup for many users in one query.
			 *
			 * @param int[] $user_ids User ids.
			 * @return void
			 */
			public static function prime( array $user_ids ): void {} // phpcs:ignore
		}
	}

	if ( ! function_exists( 'Jetonomy\\table' ) ) {
		/**
		 * Stub for Jetonomy's table-name helper (includes/functions.php).
		 * NotificationService calls it only after function_exists().
		 */
		function table( string $name ): string { // phpcs:ignore
			return $name;
		}
	}

	if ( ! function_exists( 'Jetonomy\\route_url' ) ) {
		/**
		 * Stub for Jetonomy's route helper. BuddyNext calls it only after
		 * GamificationBridge::leaderboard_deferred(), which checks it exists.
		 */
		function route_url( string $route, ...$args ): string { // phpcs:ignore
			return '';
		}
	}
}

namespace Jetonomy\Models {
	if ( ! class_exists( 'Jetonomy\\Models\\Notification' ) ) {
		/**
		 * Stub for Jetonomy's notification model (signatures copied from Jetonomy 2.0.7
		 * includes/models/class-notification.php). NotificationService calls it only
		 * behind class_exists().
		 */
		class Notification { // phpcs:ignore
			public static function mark_all_read( int $user_id ): void {} // phpcs:ignore
			public static function mark_read_for_user( int $user_id, array $ids ): int { // phpcs:ignore
				return 0;
			}
		}
	}

	if ( ! class_exists( 'Jetonomy\\Models\\SpaceMember' ) ) {
		/**
		 * Stub for Jetonomy's forum roster model (signatures copied from Jetonomy 2.0.2
		 * includes/models/class-space-member.php). JetonomyBridge registers the hooks that
		 * call it only when the class exists.
		 */
		class SpaceMember { // phpcs:ignore
			public static function add( int $space_id, int $user_id, string $role = 'member', string $source = 'manual' ): \WP_Error|bool { // phpcs:ignore
				return true;
			}
			public static function remove( int $space_id, int $user_id, bool $keep_last_admin = false ): \WP_Error|bool { // phpcs:ignore
				return true;
			}
			public static function set_role( int $space_id, int $user_id, string $role, bool $keep_last_admin = false ): \WP_Error|bool { // phpcs:ignore
				return true;
			}
			public static function is_member( int $space_id, int $user_id ): bool { // phpcs:ignore
				return false;
			}
		}
	}

	if ( ! class_exists( 'Jetonomy\\Models\\Flag' ) ) {
		/**
		 * Stub for Jetonomy's flag model (signatures copied from Jetonomy 2.0.7
		 * includes/models/class-flag.php). JetonomyBridge calls it only from Jetonomy's
		 * own hooks or behind class_exists().
		 */
		class Flag { // phpcs:ignore
			public static function find( int $id ): ?object { // phpcs:ignore
				return null;
			}
			public static function reason_labels(): array { // phpcs:ignore
				return array();
			}
			public static function resolve_siblings_for( string $object_type, int $object_id, int $resolved_by, string $status, int $exclude_flag_id ): int { // phpcs:ignore
				return 0;
			}
		}
	}
}

namespace Jetonomy\Moderation {
	if ( ! class_exists( 'Jetonomy\\Moderation\\Moderation_Service' ) ) {
		/**
		 * Stub for Jetonomy's moderation service (signature copied from Jetonomy 2.0.7
		 * includes/moderation/class-moderation-service.php). JetonomyBridge calls it only
		 * behind class_exists().
		 */
		class Moderation_Service { // phpcs:ignore
			public static function system_set_object_status( string $type, int $id, string $action, int $actor_id = 0 ): \WP_Error|bool { // phpcs:ignore
				return true;
			}
		}
	}
}

namespace WPMediaVerse\Services {
	if ( ! class_exists( 'WPMediaVerse\\Services\\ProfileService' ) ) {
		/**
		 * Stub for WPMediaVerse's profile service ("who can message you", 2.6.0).
		 * BuddyNext calls it only behind is_callable()/class_exists() guards.
		 */
		class ProfileService {
			/**
			 * @return string[]
			 */
			public static function dm_access_choices(): array { // phpcs:ignore
				return array();
			}
			/**
			 * @return array<string,string>
			 */
			public static function dm_access_options(): array { // phpcs:ignore
				return array();
			}
			public static function effective_dm_access( int $user_id ): string { // phpcs:ignore
				return 'everyone';
			}
			/**
			 * @param array<string,mixed> $fields Fields.
			 * @return true|\WP_Error
			 */
			public function update_profile( int $user_id, array $fields ) { // phpcs:ignore
				return true;
			}
		}
	}
}

namespace WBGam\Engine {
	if ( ! class_exists( 'WBGam\\Engine\\BadgeEngine' ) ) {
		/**
		 * Stub for WB Gamification's badge engine (signature copied from
		 * src/Engine/BadgeEngine.php). GamificationBridge calls it only when the
		 * class exists.
		 */
		class BadgeEngine { // phpcs:ignore
			/** @return array{id: string, name: string, description: string, image_url: string|null, is_credential: bool}|null */
			public static function get_badge_def( string $badge_id ): ?array { // phpcs:ignore
				return null;
			}
		}
	}
}

namespace WPMediaVerse\Core {
	if ( ! class_exists( 'WPMediaVerse\\Core\\TemplateHelpers' ) ) {
		/**
		 * Stub for WPMediaVerse's template helpers (stories, 2.7.0). BuddyNext
		 * calls these only behind method_exists() guards.
		 */
		class TemplateHelpers {
			public static function can_post_story(): bool { // phpcs:ignore
				return true;
			}
			public static function stories_available(): bool { // phpcs:ignore
				return false;
			}
			public static function story_length_label(): string { // phpcs:ignore
				return '24 hours';
			}
			public static function default_video_poster_url(): string { // phpcs:ignore
				return '';
			}
		}
	}
}

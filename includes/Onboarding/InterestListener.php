<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Interest-signal cache invalidation.
 *
 * Interest edits are rare, so the suggestion engines invalidate on the edit
 * (cache-bust) instead of polling a short TTL: when a member saves their
 * interest picks (onboarding step 2, POST /me/interests, or profile edit —
 * all funnel through ProfileService::save_profile(), which fires
 * buddynext_member_interests_updated), this listener busts that member's
 * per-viewer follow-suggestion and space-suggestion caches so both engines
 * re-rank with the new picks on the next fetch.
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Busts per-viewer suggestion caches when a member's interests change.
 */
final class InterestListener implements ListenerInterface {

	/**
	 * Hook the invalidation trigger.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_member_interests_updated', array( $this, 'on_interests_updated' ), 10, 1 );
	}

	/**
	 * Flush both suggestion engines' caches for the member who edited.
	 *
	 * @param int $user_id Member whose interest picks changed.
	 * @return void
	 */
	public function on_interests_updated( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$follows = function_exists( 'buddynext_service' ) ? buddynext_service( 'follows' ) : null;
		if ( $follows && method_exists( $follows, 'flush_suggestions_for' ) ) {
			$follows->flush_suggestions_for( $user_id );
		}

		( new \BuddyNext\Spaces\SpaceSuggestionService() )->flush_for_user( $user_id );
	}
}

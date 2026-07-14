<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Cache-bust hooks for the Explore decks.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Keeps the Explore decks from lying about the two things that matter.
 *
 * The decks are cached per viewer with a short TTL, which is fine for a discovery
 * surface — except for two events, where waiting out a TTL is not a delay, it is a bug:
 *
 *   A BLOCK. Blocking somebody and then being shown their posts for the next five
 *   minutes is the block failing at the only job it has. This is a safety control, not a
 *   preference, and it has to bite on the very next page load.
 *
 *   A MEMBER'S OWN NEW POST. Posting and not seeing it on the landing surface reads as
 *   the post having been lost, and the member posts it again.
 *
 * Space creation and new members bust it too, for the same reason in a milder form: a
 * discovery surface that does not show the thing that was just created is not
 * discovering anything.
 */
class ExploreListener implements ListenerInterface {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach (
			array(
				'buddynext_block',
				'buddynext_unblock',
				'buddynext_post_created',
				'buddynext_space_created',
				'user_register',
			) as $hook
		) {
			add_action( $hook, array( $this, 'flush' ), 10, 0 );
		}
	}

	/**
	 * Bust every cached Explore deck.
	 *
	 * @return void
	 */
	public function flush(): void {
		ExploreService::flush_decks();
	}
}

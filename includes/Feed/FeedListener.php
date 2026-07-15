<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Feed cache-bust hook registrar.
 *
 * Layer 2 Listener per docs/specs/MODULAR-ARCHITECTURE.md: hooks domain
 * events that mutate the feed and busts the corresponding cache keys
 * via FeedCache.
 *
 * @package BuddyNext\Feed
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Wires cache-bust hooks for the home-feed page-1 cache.
 */
class FeedListener implements ListenerInterface {

	/**
	 * Cache layer.
	 *
	 * @var FeedCache
	 */
	private FeedCache $cache;

	/**
	 * Inject dependencies.
	 *
	 * @param FeedCache $cache Cache layer.
	 */
	public function __construct( FeedCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'buddynext_post_created', array( $this, 'bust_writer' ), 10, 2 );
		add_action( 'buddynext_post_deleted', array( $this, 'bust_writer' ), 10, 2 );

		// A post created in or deleted from a space changes that space's feed for every
		// viewer (A9). buddynext_space_posts_changed carries the space id on BOTH events --
		// the delete path reads space_id before the row is gone, precisely so this can fire.
		add_action( 'buddynext_space_posts_changed', array( $this, 'bust_space' ), 10, 1 );
	}

	/**
	 * Invalidate the writer's first-page home feed cache.
	 *
	 * This also busts the writer's PROFILE feed page-1, which is keyed on the profile
	 * owner's user version -- so the owner's new or deleted post shows on their profile for
	 * every viewer at once, with no separate hook.
	 *
	 * @param int $post_id Post that changed (unused — shape only).
	 * @param int $user_id Author / actor.
	 * @return void
	 */
	public function bust_writer( int $post_id, int $user_id ): void {
		unset( $post_id );
		$this->cache->invalidate_writer( $user_id );
	}

	/**
	 * Invalidate every viewer's cached page-1 feed for a space.
	 *
	 * @param int $space_id Space whose post set changed.
	 * @return void
	 */
	public function bust_space( int $space_id ): void {
		$this->cache->invalidate_space( $space_id );
	}
}

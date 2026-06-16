<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Reaction service.
 *
 * Manages emoji reactions on any object type (posts, comments, messages).
 * Each user may have at most one reaction per object (enforced by the UNIQUE
 * KEY on bn_reactions). React/unreact invalidate the count cache; toggling
 * re-uses react() and unreact() internally.
 *
 * @package BuddyNext\Reactions
 */

declare( strict_types=1 );

namespace BuddyNext\Reactions;

/**
 * Handles reaction add, remove, toggle, and count reads.
 */
class ReactionService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_reactions';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Add a reaction from a user on an object.
	 *
	 * Uses INSERT IGNORE so duplicate reactions are silently skipped.
	 *
	 * @param int    $user_id     Reacting user.
	 * @param string $object_type Object type (e.g. 'post', 'comment').
	 * @param int    $object_id   Object ID.
	 * @param string $emoji       Emoji identifier (e.g. 'like', 'heart').
	 * @return true
	 */
	public function react( int $user_id, string $object_type, int $object_id, string $emoji = 'like' ): true {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_reactions (user_id, object_type, object_id, emoji, created_at)
				 VALUES (%d, %s, %d, %s, %s)",
				$user_id,
				sanitize_key( $object_type ),
				$object_id,
				sanitize_key( $emoji ),
				current_time( 'mysql', true )
			)
		);

		if ( $wpdb->rows_affected > 0 ) {
			$reaction_id = (int) $wpdb->insert_id;

			$this->invalidate_cache( $object_type, $object_id, $user_id );

			if ( 'post' === $object_type ) {
				buddynext_service( 'post_service' )->increment_counter( $object_id, 'reaction_count' );
			}

			/**
			 * Fires after a reaction is added to an object.
			 *
			 * @param string $object_type Object type ('post', 'comment', etc.).
			 * @param int    $object_id   Object ID.
			 * @param int    $user_id     Reacting user.
			 * @param string $emoji       Emoji identifier.
			 */
			do_action( 'buddynext_reaction_added', $object_type, $object_id, $user_id, $emoji );

			if ( 'post' === $object_type ) {
				$author_id = buddynext_service( 'post_service' )->get_author_id( $object_id );

				if ( $author_id > 0 && $author_id !== $user_id ) {
					/**
					 * Fires from the post author's perspective when their post
					 * receives a reaction from someone else. Recipient-side
					 * mirror of `buddynext_reaction_added` so gamification
					 * plugins can award the post author (the user whose work
					 * is being engaged with) instead of the reactor.
					 *
					 * @param int    $post_id    Post that received the reaction.
					 * @param int    $author_id  Post author (recipient).
					 * @param int    $reactor_id User who reacted (actor).
					 * @param string $emoji      Emoji identifier.
					 */
					do_action( 'buddynext_post_reaction_received', $object_id, $author_id, $user_id, $emoji );
				}
			}
		}

		return true;
	}

	/**
	 * Remove a reaction from a user on an object.
	 *
	 * @param int    $user_id     User whose reaction to remove.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	public function unreact( int $user_id, string $object_type, int $object_id ): void {
		global $wpdb;

		// Fetch the emoji before deleting so it can be passed to the hook.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$emoji = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT emoji FROM {$wpdb->prefix}bn_reactions WHERE user_id = %d AND object_type = %s AND object_id = %d",
				$user_id,
				sanitize_key( $object_type ),
				$object_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_reactions',
			array(
				'user_id'     => $user_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
			),
			array( '%d', '%s', '%d' )
		);

		if ( $wpdb->rows_affected > 0 ) {
			if ( 'post' === $object_type ) {
				buddynext_service( 'post_service' )->decrement_counter( $object_id, 'reaction_count' );
			}

			/**
			 * Fires after a reaction is removed from an object.
			 *
			 * @param string $object_type Object type ('post', 'comment', etc.).
			 * @param int    $object_id   Object ID.
			 * @param int    $user_id     User who removed their reaction.
			 */
			do_action( 'buddynext_reaction_removed', $object_type, $object_id, $user_id );
		}

		$this->invalidate_cache( $object_type, $object_id, $user_id );
	}

	/**
	 * Allowed reaction types — the canonical six.
	 *
	 * Use reaction_types() instead of this constant when you need the filterable
	 * list — Pro extends this via the buddynext_reaction_types filter.
	 */
	public const REACTION_TYPES = array( 'like', 'love', 'haha', 'wow', 'sad', 'angry' );

	/**
	 * Return the filterable list of allowed reaction type slugs.
	 *
	 * Pro plugins extend this by hooking buddynext_reaction_types to inject
	 * additional reaction types (e.g. 'celebrate', 'insightful'). Free returns
	 * the canonical six defined in REACTION_TYPES.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Ordered list of reaction type slugs.
	 */
	public static function reaction_types(): array {
		// Site-owner control: buddynext_enabled_reactions (Settings → Activity Feed)
		// is the owner-chosen subset of the canonical six, in canonical order.
		// Empty/unset falls back to all six so reactions are never fully disabled.
		$enabled = array_values( array_intersect( self::REACTION_TYPES, (array) get_option( 'buddynext_enabled_reactions', self::REACTION_TYPES ) ) );
		if ( empty( $enabled ) ) {
			$enabled = self::REACTION_TYPES;
		}

		/**
		 * Filter the allowed reaction type slugs.
		 *
		 * Return an array of lowercase alphanumeric slugs. Each slug must have a
		 * corresponding SVG at assets/icons/reaction-{slug}.svg and a CSS colour
		 * token --bn-reaction-{slug}. Adding a new type here without providing
		 * the icon/CSS will cause a broken UI in the reaction picker.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types The owner-enabled list of reaction type slugs.
		 */
		return (array) apply_filters( 'buddynext_reaction_types', $enabled );
	}

	/**
	 * Toggle a reaction and return the updated counts for all types.
	 *
	 * Spec-named alias for toggle() that also returns a full counts array.
	 *
	 * - Same emoji as existing: removes the reaction.
	 * - Different emoji from existing: replaces the existing reaction.
	 * - No existing reaction: adds the reaction.
	 *
	 * @param int    $user_id     Reacting user.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $reaction_type Emoji identifier (one of the six allowed types).
	 * @return array New reaction counts keyed by emoji slug.
	 */
	public function toggle_reaction( int $user_id, string $object_type, int $object_id, string $reaction_type ): array {
		$this->toggle( $user_id, $object_type, $object_id, $reaction_type );
		return $this->get_counts( $object_type, $object_id );
	}

	/**
	 * Return the reaction type a user used on an object, or null if none.
	 *
	 * Spec-named alias for get_user_emoji().
	 *
	 * @param int    $user_id     User to check.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string|null
	 */
	public function get_user_reaction( int $user_id, string $object_type, int $object_id ): ?string {
		return $this->get_user_emoji( $user_id, $object_type, $object_id );
	}

	/**
	 * Toggle a reaction.
	 *
	 * - Same emoji as existing: removes the reaction.
	 * - Different emoji from existing: replaces the existing reaction.
	 * - No existing reaction: adds the reaction.
	 *
	 * @param int    $user_id     Reacting user.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $emoji       Emoji identifier.
	 */
	public function toggle( int $user_id, string $object_type, int $object_id, string $emoji = 'like' ): void {
		// Empty emoji means the client wants to remove the reaction entirely.
		if ( '' === $emoji ) {
			$this->unreact( $user_id, $object_type, $object_id );
			return;
		}

		$current = $this->get_user_emoji( $user_id, $object_type, $object_id );

		if ( null === $current ) {
			$this->react( $user_id, $object_type, $object_id, $emoji );
			return;
		}

		if ( $current === $emoji ) {
			$this->unreact( $user_id, $object_type, $object_id );
			return;
		}

		// Replace the existing emoji with the new one.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_reactions',
			array( 'emoji' => sanitize_key( $emoji ) ),
			array(
				'user_id'     => $user_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
			),
			array( '%s' ),
			array( '%d', '%s', '%d' )
		);

		$this->invalidate_cache( $object_type, $object_id, $user_id );
	}

	/**
	 * Return per-emoji reaction counts for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<string, int> Emoji slug to count map.
	 */
	public function get_counts( string $object_type, int $object_id ): array {
		$cache_key = "counts_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT emoji, COUNT(*) AS cnt FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = %s AND object_id = %d
				 GROUP BY emoji",
				sanitize_key( $object_type ),
				$object_id
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row['emoji'] ] = (int) $row['cnt'];
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::CACHE_TTL );

		return $counts;
	}

	/**
	 * Check whether a user has reacted to an object.
	 *
	 * @param int    $user_id     User to check.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return bool
	 */
	public function has_reacted( int $user_id, string $object_type, int $object_id ): bool {
		return null !== $this->get_user_emoji( $user_id, $object_type, $object_id );
	}

	/**
	 * Return the list of users who reacted to an object, with their emoji.
	 *
	 * Powers the FB-style "who reacted" popover. Ordered newest-first
	 * so the most recent reactor is at the top, capped at 100 rows to
	 * keep the payload reasonable on viral posts (admins can extend via
	 * the `buddynext_reactors_limit` filter if needed).
	 *
	 * Restrict gate: same rule as the comment list. On a post, any
	 * reactor the post owner has restricted is dropped from the result
	 * for every viewer except themselves (no signal), the owner (still
	 * moderates), and admins. The raw count from get_counts() is not
	 * adjusted — the badge stays factual.
	 *
	 * @param string $object_type Object type ('post' or 'comment').
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Optional. Max rows. Default 100.
	 * @param int    $viewer_id   Optional. Current viewer. Default get_current_user_id().
	 * @return array<int,array{user_id:int,emoji:string,created_at:string}>
	 */
	public function get_reactors( string $object_type, int $object_id, int $limit = 100, int $viewer_id = 0 ): array {
		/**
		 * Filter the maximum number of reactors returned for an object.
		 *
		 * @param int    $max         Default ceiling (100).
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
		 */
		$max   = (int) apply_filters( 'buddynext_reactors_limit', 100, $object_type, $object_id );
		$limit = max( 1, min( max( 1, $max ), $limit ) );
		if ( 0 === $viewer_id ) {
			$viewer_id = get_current_user_id();
		}

		global $wpdb;

		// bn_reactions has a composite PRIMARY KEY (user_id, object_type, object_id)
		// and no `id` column, so order by created_at only — the old query
		// referenced a non-existent `id` and was silently erroring out.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, emoji, created_at FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = %s AND object_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d",
				sanitize_key( $object_type ),
				$object_id,
				$limit
			),
			ARRAY_A
		);

		$restricted_ids = array();
		$post_owner_id  = 0;
		if ( 'post' === $object_type && function_exists( 'buddynext_service' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_owner_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d",
					$object_id
				)
			);
			if ( $post_owner_id > 0 ) {
				$restricted_ids = buddynext_service( 'blocks' )->restricted_users( $post_owner_id );
			}
		}
		$is_owner = $viewer_id > 0 && $viewer_id === $post_owner_id;
		$is_admin = $viewer_id > 0 && user_can( $viewer_id, 'manage_options' );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$author_id = (int) $row['user_id'];
			if (
				! empty( $restricted_ids )
				&& in_array( $author_id, $restricted_ids, true )
				&& $author_id !== $viewer_id
				&& ! $is_owner
				&& ! $is_admin
			) {
				continue;
			}
			$out[] = array(
				'user_id'    => $author_id,
				'emoji'      => (string) $row['emoji'],
				'created_at' => (string) $row['created_at'],
			);
		}

		return $out;
	}

	/**
	 * Return the emoji a user reacted with, or null if they have not reacted.
	 *
	 * @param int    $user_id     User to check.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return string|null
	 */
	public function get_user_emoji( int $user_id, string $object_type, int $object_id ): ?string {
		$cache_key = "user_emoji_{$user_id}_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return ( '' === $cached ) ? null : (string) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$emoji = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT emoji FROM {$wpdb->prefix}bn_reactions
				 WHERE user_id = %d AND object_type = %s AND object_id = %d",
				$user_id,
				sanitize_key( $object_type ),
				$object_id
			)
		);

		wp_cache_set( $cache_key, $emoji ?? '', self::CACHE_GROUP, self::CACHE_TTL );

		return $emoji;
	}

	/**
	 * Return the total reaction count for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int
	 */
	public function count( string $object_type, int $object_id ): int {
		$cache_key = "count_{$object_type}_{$object_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = %s AND object_id = %d",
				sanitize_key( $object_type ),
				$object_id
			)
		);

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Invalidate cache entries for an object and optionally a specific user.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     User whose personal cache to also clear.
	 */
	private function invalidate_cache( string $object_type, int $object_id, int $user_id ): void {
		wp_cache_delete( "count_{$object_type}_{$object_id}", self::CACHE_GROUP );
		wp_cache_delete( "counts_{$object_type}_{$object_id}", self::CACHE_GROUP );
		wp_cache_delete( "user_emoji_{$user_id}_{$object_type}_{$object_id}", self::CACHE_GROUP );
	}
}

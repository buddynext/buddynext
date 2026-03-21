<?php
/**
 * Hashtag service.
 *
 * Extracts hashtags from content, keeps the bn_hashtags registry up to date,
 * and maintains the bn_post_hashtags link table. Re-syncing a post replaces
 * the old tag set with the new one (old links deleted, new ones inserted).
 *
 * @package BuddyNext\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Hashtags;

/**
 * Handles hashtag extraction, sync, and lookup.
 */
class HashtagService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_hashtags';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Extract unique hashtag slugs from a string.
	 *
	 * @param string $content Raw content that may contain #tags.
	 * @return string[] Lowercased, deduplicated array of tag slugs (no leading #).
	 */
	public function extract( string $content ): array {
		preg_match_all( '/#([a-zA-Z][a-zA-Z0-9_]{0,49})/u', $content, $matches );

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$slugs = array_map( 'strtolower', $matches[1] );

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Synchronise hashtags for a post/object.
	 *
	 * Upserts each tag into bn_hashtags, then replaces the bn_post_hashtags
	 * link set for this object so that stale tags are removed automatically.
	 *
	 * @param string   $object_type Object type (e.g. 'post', 'comment').
	 * @param int      $object_id   Object ID.
	 * @param string[] $slugs       Tag slugs to set (empty array removes all tags).
	 */
	public function sync( string $object_type, int $object_id, array $slugs ): void {
		global $wpdb;

		$object_type = sanitize_key( $object_type );

		// Remove all existing links for this object first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_post_hashtags',
			array(
				'post_id'     => $object_id,
				'object_type' => $object_type,
			),
			array( '%d', '%s' )
		);

		if ( empty( $slugs ) ) {
			return;
		}

		foreach ( $slugs as $slug ) {
			$slug = sanitize_key( $slug );

			if ( '' === $slug ) {
				continue;
			}

			// Upsert the hashtag registry row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}bn_hashtags (name, slug)
					 VALUES (%s, %s)
					 ON DUPLICATE KEY UPDATE name = VALUES(name)",
					$slug,
					$slug
				)
			);

			// Retrieve the hashtag ID.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$hashtag_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_hashtags WHERE slug = %s",
					$slug
				)
			);

			if ( 0 === $hashtag_id ) {
				continue;
			}

			// Insert the post↔hashtag link (ignore if it already exists).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}bn_post_hashtags (post_id, object_type, hashtag_id)
					 VALUES (%d, %s, %d)",
					$object_id,
					$object_type,
					$hashtag_id
				)
			);

			wp_cache_delete( "hashtag_{$slug}", self::CACHE_GROUP );
		}
	}

	/**
	 * Return a hashtag by its slug, or null if not found.
	 *
	 * @param string $slug Hashtag slug (without leading #).
	 * @return array|null
	 */
	public function get_by_slug( string $slug ): ?array {
		$slug      = sanitize_key( $slug );
		$cache_key = "hashtag_{$slug}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return empty( $cached ) ? null : (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_hashtags WHERE slug = %s",
				$slug
			),
			ARRAY_A
		);

		$hydrated = null !== $row ? $this->hydrate( $row ) : null;

		wp_cache_set( $cache_key, $hydrated ?? array(), self::CACHE_GROUP, self::CACHE_TTL );

		return $hydrated;
	}

	/**
	 * Hydrate a raw DB row into a typed hashtag array.
	 *
	 * @param array $row Raw ARRAY_A row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'name'           => $row['name'],
			'slug'           => $row['slug'],
			'post_count'     => (int) $row['post_count'],
			'follower_count' => (int) $row['follower_count'],
			'created_at'     => $row['created_at'],
		);
	}
}

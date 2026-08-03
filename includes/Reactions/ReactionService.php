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

use WP_Error;
use BuddyNext\Moderation\InteractionGuard;

/**
 * Handles reaction add, remove, toggle, and count reads.
 */
class ReactionService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_reactions';

	/**
	 * Rows purged per statement when removing a custom reaction.
	 *
	 * Small enough that the lock on bn_reactions — the hottest write path in the product — is
	 * never held long on a shared host.
	 */
	private const PURGE_BATCH = 500;

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Largest face-stack top_reactors_map() will build for one object.
	 *
	 * A stack is a glanceable signal, not a list — the who-reacted sheet is where
	 * the full set belongs. The ceiling also bounds the per-object slice below.
	 */
	private const TOP_REACTORS_MAX = 10;

	/**
	 * Extra rows read per object so the restrict filter can drop some.
	 *
	 * Without headroom a hidden reactor would shorten the stack instead of
	 * promoting the next visible one.
	 */
	private const TOP_REACTORS_BUFFER = 5;

	/**
	 * Objects per UNION ALL read.
	 *
	 * Keeps a single statement to a sane size on an unusually large page while
	 * still costing one round trip for any normal feed page.
	 */
	private const TOP_REACTORS_CHUNK = 50;

	/**
	 * Add a reaction from a user on an object.
	 *
	 * Uses INSERT IGNORE so duplicate reactions are silently skipped.
	 *
	 * @param int         $user_id     Reacting user.
	 * @param string      $object_type Object type (e.g. 'post', 'comment').
	 * @param int         $object_id   Object ID.
	 * @param string      $emoji       Emoji identifier (e.g. 'like', 'heart').
	 * @param string|null $created_at  Optional backdated UTC timestamp (importer
	 *                                 seam — see Core\Backdate). Clamped to now.
	 * @return true|WP_Error True on success; WP_Error(403) when the actor is
	 *                       suspended or blocked from the object's author.
	 */
	public function react( int $user_id, string $object_type, int $object_id, string $emoji = 'like', ?string $created_at = null ): bool|WP_Error {
		// Trust-&-Safety gate before any DB write: a suspended actor cannot
		// react, and neither party of a block may react on the other's content.
		$guard = InteractionGuard::check( $user_id, $object_type, $object_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		// Validate the emoji against the allow-list (the owner-enabled, Pro-filterable
		// reaction types). An unknown slug falls back to the first ENABLED type so a
		// stray or disabled type never lands in the table — hardcoding 'like' here
		// broke that guarantee precisely when the owner had disabled 'like'.
		$emoji = self::coerce_to_enabled( $emoji );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_reactions (user_id, object_type, object_id, emoji, created_at)
				 VALUES (%d, %s, %d, %s, %s)",
				$user_id,
				sanitize_key( $object_type ),
				$object_id,
				$emoji,
				\BuddyNext\Core\Backdate::resolve( $created_at )
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
			do_action( 'buddynext_reaction_removed', $object_type, $object_id, $user_id, $emoji );
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
	 * Normalise an incoming reaction slug to one the owner actually has enabled.
	 *
	 * Single guard used by both write paths (react() and the toggle() replace
	 * branch), so a slug the owner disabled — or a stray/legacy one a client
	 * sends — can never land in bn_reactions. Out-of-set slugs coerce to the
	 * FIRST enabled type rather than the literal 'like': the settings sanitizer
	 * only enforces a non-empty set, so 'like' itself may be disabled, and
	 * coercing to it would write exactly the slug the owner turned off.
	 *
	 * reaction_types() is guaranteed non-empty (it falls back to the canonical
	 * six when the option is empty); 'like' remains only as a defensive default
	 * if a filter returns an empty list.
	 *
	 * @param string $emoji Raw reaction slug from the client.
	 * @return string A slug guaranteed to be in the owner-enabled set.
	 */
	private static function coerce_to_enabled( string $emoji ): string {
		$emoji   = sanitize_key( $emoji );
		$enabled = self::reaction_types();

		if ( in_array( $emoji, $enabled, true ) ) {
			return $emoji;
		}

		$first = reset( $enabled );

		return ( false === $first || '' === $first ) ? 'like' : (string) $first;
	}

	/**
	 * The owner-enabled reaction set with full render metadata.
	 *
	 * Single source the app can read to render the reaction picker without
	 * hardcoding the six defaults — Pro custom reactions (added via
	 * buddynext_reaction_types) appear here too, each carrying its label, emoji
	 * char, colour token, and SVG icon URL (buddynext_reaction_meta supplies the
	 * char/colour; Pro custom slugs ship their own reaction-{slug}.svg).
	 *
	 * @return array<int,array{slug:string,label:string,char:string,color:string,icon_url:string}>
	 */
	public static function enabled_reactions(): array {
		$reactions = array();

		foreach ( self::reaction_types() as $slug ) {
			$meta = (array) apply_filters(
				'buddynext_reaction_meta',
				array(
					'label' => self::label( $slug ),
					'char'  => '',
					'color' => '',
				),
				$slug
			);

			$reactions[] = array(
				'slug'     => $slug,
				'label'    => self::label( $slug ),
				'char'     => (string) ( $meta['char'] ?? '' ),
				'color'    => (string) ( $meta['color'] ?? '' ),
				'icon_url' => defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL . 'assets/icons/reaction-' . rawurlencode( $slug ) . '.svg' : '',
			);
		}

		return $reactions;
	}

	/**
	 * Human-readable, translatable label for a reaction slug.
	 *
	 * Single source of truth for reaction labels across the picker, the React
	 * button, and the post-card context. Built-in slugs map to their canonical
	 * label; unknown (Pro custom) slugs fall back to a humanised slug, and the
	 * buddynext_reaction_meta filter can override any of them.
	 *
	 * @param string $slug Reaction type slug.
	 * @return string Translated label.
	 */
	public static function label( string $slug ): string {
		$slug    = sanitize_key( $slug );
		$builtin = array(
			'like'  => __( 'Like', 'buddynext' ),
			'love'  => __( 'Love', 'buddynext' ),
			'haha'  => __( 'Haha', 'buddynext' ),
			'wow'   => __( 'Wow', 'buddynext' ),
			'sad'   => __( 'Sad', 'buddynext' ),
			'angry' => __( 'Angry', 'buddynext' ),
		);
		$label   = $builtin[ $slug ] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );
		$meta    = (array) apply_filters(
			'buddynext_reaction_meta',
			array(
				'label' => $label,
				'char'  => '',
				'color' => '',
			),
			$slug
		);
		return isset( $meta['label'] ) && '' !== (string) $meta['label'] ? (string) $meta['label'] : $label;
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
	 * @return true|WP_Error True on success (including a pure removal); WP_Error(403)
	 *                       when the actor is suspended or blocked and is adding /
	 *                       changing a reaction.
	 */
	public function toggle( int $user_id, string $object_type, int $object_id, string $emoji = 'like' ): bool|WP_Error {
		// Empty emoji means the client wants to remove the reaction entirely.
		// Removing one's own existing reaction is always permitted (cleanup), so
		// it is not gated by the interaction guard.
		if ( '' === $emoji ) {
			$this->unreact( $user_id, $object_type, $object_id );
			return true;
		}

		$current = $this->get_user_emoji( $user_id, $object_type, $object_id );

		// Toggling off the current emoji is a removal — also always permitted.
		if ( $current === $emoji ) {
			$this->unreact( $user_id, $object_type, $object_id );
			return true;
		}

		// Adding a new reaction or switching to a different emoji is a write of
		// fresh engagement: enforce the Trust-&-Safety gate before it lands.
		$guard = InteractionGuard::check( $user_id, $object_type, $object_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( null === $current ) {
			return $this->react( $user_id, $object_type, $object_id, $emoji );
		}

		// Replace the existing emoji with the new one. Validate against the
		// allow-list (mirrors react()) so a switch never writes an unknown or
		// owner-disabled slug.
		$emoji = self::coerce_to_enabled( $emoji );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_reactions',
			array( 'emoji' => $emoji ),
			array(
				'user_id'     => $user_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
			),
			array( '%s' ),
			array( '%d', '%s', '%d' )
		);

		$this->invalidate_cache( $object_type, $object_id, $user_id );

		return true;
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
	 * Leading reactors for a page of objects, resolved in one bounded read.
	 *
	 * The batched sibling of get_reactors(), for the feed's face-stack. get_reactors()
	 * answers for a single object and spends three queries doing it — the slice, an
	 * owner lookup, and the owner's restrict list — which is correct for the
	 * who-reacted sheet and ruinous in a feed loop, where it becomes sixty queries on
	 * a twenty-post page.
	 *
	 * Two things make the batched form bounded rather than merely batched. The slice
	 * is read as one UNION ALL of per-object subqueries, so each arm is an index range
	 * scan on object_recent (object_type, object_id, created_at) that reads a handful
	 * of rows and stops — a post with fifty thousand reactions costs the same as a post
	 * with five. And the owner ids are supplied by the caller, which already holds them,
	 * so the per-post owner lookup disappears entirely.
	 *
	 * Each arm reads a buffer above the requested size because the restrict filter runs
	 * afterwards in PHP: without headroom, hiding one reactor would leave a short stack
	 * rather than promoting the next visible one.
	 *
	 * Privacy is the same gate get_reactors() applies, and deliberately so — restrict is
	 * one-way and silent. The restricted person still sees themselves, the object's owner
	 * still sees everyone they moderate, and admins see through it; only the ordinary
	 * onlooker loses the signal.
	 *
	 * @param string         $object_type Object type ('post' or 'comment').
	 * @param int[]          $object_ids  Objects on the page.
	 * @param array<int,int> $owner_map   object_id => owner user id. Supply it to skip
	 *                                    the owner lookup; missing ids are resolved in
	 *                                    one extra query rather than losing the gate.
	 * @param int            $limit       Optional. Reactors per object. Default 3.
	 * @param int            $viewer_id   Optional. Current viewer. Default current user.
	 * @return array<int,array<int,array{user_id:int,display_name:string,avatar_url:string}>>
	 *         object_id => reactors, most recent first. Every requested id is present.
	 */
	public function top_reactors_map( string $object_type, array $object_ids, array $owner_map = array(), int $limit = 3, int $viewer_id = 0 ): array {
		$object_type = sanitize_key( $object_type );
		$object_ids  = array_values( array_unique( array_filter( array_map( 'absint', $object_ids ) ) ) );
		if ( empty( $object_ids ) ) {
			return array();
		}

		$limit = max( 1, min( self::TOP_REACTORS_MAX, $limit ) );
		if ( 0 === $viewer_id ) {
			$viewer_id = get_current_user_id();
		}

		// Every requested id answers, so callers read the map without guarding.
		$map = array_fill_keys( $object_ids, array() );

		global $wpdb;

		// Read a slice per object, all in one round trip. The buffer above $limit is
		// what lets the restrict filter drop rows without shortening the stack.
		$slice  = $limit + self::TOP_REACTORS_BUFFER;
		$rows   = array();
		$chunks = array_chunk( $object_ids, self::TOP_REACTORS_CHUNK );
		foreach ( $chunks as $chunk ) {
			$arms = array();
			$args = array();
			foreach ( $chunk as $oid ) {
				$arms[] = "(SELECT user_id, object_id, created_at FROM {$wpdb->prefix}bn_reactions
					WHERE object_type = %s AND object_id = %d
					ORDER BY created_at DESC, user_id DESC
					LIMIT %d)";
				$args[] = $object_type;
				$args[] = $oid;
				$args[] = $slice;
			}
			$sql = implode( ' UNION ALL ', $arms );

			// Every arm of $sql is a literal with %s/%d placeholders and $args carries one
			// value per placeholder, in order; the sniffs cannot follow a built-up query.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$chunk_rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );
			if ( ! empty( $chunk_rows ) ) {
				$rows = array_merge( $rows, (array) $chunk_rows );
			}
		}

		if ( empty( $rows ) ) {
			return $map;
		}

		// Group by object. UNION ALL does not promise to preserve each arm's ordering,
		// so the sort is reapplied per group — at a handful of rows apiece it is free.
		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ (int) $row['object_id'] ][] = array(
				'user_id'    => (int) $row['user_id'],
				'created_at' => (string) $row['created_at'],
			);
		}

		$owner_map = $this->resolve_owner_map( $object_type, array_keys( $grouped ), $owner_map );
		$is_admin  = $viewer_id > 0 && user_can( $viewer_id, 'manage_options' );

		// One read for every owner on the page. Per-owner caching alone would still cost
		// a query per distinct author on a cold cache, which is a page-size-shaped cost
		// on precisely the feed where authors rarely repeat.
		$restricted_map = function_exists( 'buddynext_service' )
			? buddynext_service( 'blocks' )->restricted_users_map( array_values( array_unique( array_map( 'intval', $owner_map ) ) ) )
			: array();

		$survivors = array();
		foreach ( $grouped as $oid => $entries ) {
			usort(
				$entries,
				static function ( array $a, array $b ): int {
					return array( $b['created_at'], $b['user_id'] ) <=> array( $a['created_at'], $a['user_id'] );
				}
			);

			$owner_id   = (int) ( $owner_map[ $oid ] ?? 0 );
			$is_owner   = $viewer_id > 0 && $viewer_id === $owner_id;
			$restricted = $restricted_map[ $owner_id ] ?? array();

			$kept = array();
			foreach ( $entries as $entry ) {
				$uid = $entry['user_id'];
				if (
					! empty( $restricted )
					&& in_array( $uid, $restricted, true )
					&& $uid !== $viewer_id
					&& ! $is_owner
					&& ! $is_admin
				) {
					continue;
				}
				$kept[]      = $uid;
				$survivors[] = $uid;
				if ( count( $kept ) >= $limit ) {
					break;
				}
			}
			$map[ $oid ] = $kept;
		}

		// One priming pass for every face on the page, so the hydrate below is O(1).
		if ( ! empty( $survivors ) ) {
			cache_users( array_values( array_unique( $survivors ) ) );
		}

		foreach ( $map as $oid => $user_ids ) {
			$map[ $oid ] = array_map(
				static function ( int $uid ): array {
					$user = get_userdata( $uid );

					return array(
						'user_id'      => $uid,
						'display_name' => $user ? $user->display_name : __( 'Community Member', 'buddynext' ),
						'avatar_url'   => (string) get_avatar_url( $uid, array( 'size' => 96 ) ),
					);
				},
				$user_ids
			);
		}

		return $map;
	}

	/**
	 * Fill in any object owners the caller did not supply, in one query.
	 *
	 * The gate in top_reactors_map() needs an owner to resolve a restrict list. A
	 * caller that already holds them (the feed does) passes them straight through and
	 * this costs nothing. A caller that does not still gets the gate rather than
	 * silently losing it, which is the failure mode worth spending a query to avoid.
	 *
	 * @param string         $object_type Object type.
	 * @param int[]          $object_ids  Objects needing an owner.
	 * @param array<int,int> $owner_map   Owners already known.
	 * @return array<int,int> object_id => owner user id.
	 */
	private function resolve_owner_map( string $object_type, array $object_ids, array $owner_map ): array {
		if ( 'post' !== $object_type ) {
			return $owner_map;
		}

		$missing = array_values( array_diff( $object_ids, array_keys( $owner_map ) ) );
		if ( empty( $missing ) ) {
			return $owner_map;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
		$sql          = "SELECT id, user_id FROM {$wpdb->prefix}bn_posts WHERE id IN ({$placeholders})";

		// $placeholders is a run of %d built from a counted int array, so the query is
		// fully placeholdered and every value below is bound; the sniffs cannot see that.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$missing ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$owner_map[ (int) $row['id'] ] = (int) $row['user_id'];
		}

		return $owner_map;
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
	 * Batch-fetch a viewer's reactions across many objects in ONE query.
	 *
	 * Returns a map of object_id => emoji (or null where the viewer has not
	 * reacted). Replaces calling get_user_emoji() in a loop — the per-card N+1 that
	 * made the feed unusable at scale. Also warms the per-object cache so any later
	 * singular get_user_emoji() (e.g. the SSR post-card) is a free cache hit.
	 *
	 * @param int    $user_id     Viewer.
	 * @param string $object_type Object type (e.g. 'post').
	 * @param int[]  $object_ids  Object IDs to look up.
	 * @return array<int,string|null> object_id => emoji|null.
	 */
	public function get_user_emoji_map( int $user_id, string $object_type, array $object_ids ): array {
		$object_type = sanitize_key( $object_type );
		$object_ids  = array_values( array_unique( array_filter( array_map( 'absint', $object_ids ) ) ) );

		$map = array();
		foreach ( $object_ids as $id ) {
			$map[ $id ] = null;
		}

		if ( 0 === $user_id || empty( $object_ids ) ) {
			return $map;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$params       = array_merge( array( $user_id, $object_type ), $object_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, emoji FROM {$wpdb->prefix}bn_reactions
				 WHERE user_id = %d AND object_type = %s AND object_id IN ( {$placeholders} )",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['object_id'] ] = (string) $row['emoji'];
		}

		// Warm the singular per-object cache for every requested id (hit or miss),
		// so a subsequent get_user_emoji() during SSR never re-queries.
		foreach ( $map as $id => $emoji ) {
			wp_cache_set( "user_emoji_{$user_id}_{$object_type}_{$id}", $emoji ?? '', self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $map;
	}

	/**
	 * Batch-fetch the total reaction count for many objects in ONE query.
	 *
	 * Returns a map of object_id => total count (0 where none). Warms the singular
	 * per-object count cache so a later count() (e.g. per-comment enrichment) is a
	 * free cache hit — the whole thread's like counts cost this one query instead
	 * of one per comment.
	 *
	 * @param string $object_type Object type (e.g. 'comment').
	 * @param int[]  $object_ids  Object IDs to count.
	 * @return array<int,int> object_id => count.
	 */
	public function count_map( string $object_type, array $object_ids ): array {
		$object_type = sanitize_key( $object_type );
		$object_ids  = array_values( array_unique( array_filter( array_map( 'absint', $object_ids ) ) ) );

		$map = array();
		foreach ( $object_ids as $id ) {
			$map[ $id ] = 0;
		}

		if ( empty( $object_ids ) ) {
			return $map;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$params       = array_merge( array( $object_type ), $object_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, COUNT(*) AS cnt FROM {$wpdb->prefix}bn_reactions
				 WHERE object_type = %s AND object_id IN ( {$placeholders} )
				 GROUP BY object_id",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['object_id'] ] = (int) $row['cnt'];
		}

		foreach ( $map as $id => $cnt ) {
			wp_cache_set( "count_{$object_type}_{$id}", $cnt, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $map;
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
	 * Return the top-N emoji slugs on an object, most-used first.
	 *
	 * Derived from get_counts() (so it shares its cache), trimmed to the busiest
	 * $limit reaction types. Powers the inline "top reactions" cluster on the
	 * post card without the template summing counts itself. Ties break by the
	 * canonical reaction order.
	 *
	 * @param string $object_type Object type ('post', 'comment', etc.).
	 * @param int    $object_id   Object ID.
	 * @param int    $limit       Max emoji slugs to return (1-6). Default 3.
	 * @return array<int,string> Ordered emoji slugs.
	 */
	public function top_reactions( string $object_type, int $object_id, int $limit = 3 ): array {
		$limit  = max( 1, min( count( self::REACTION_TYPES ), $limit ) );
		$counts = $this->get_counts( $object_type, $object_id );
		if ( empty( $counts ) ) {
			return array();
		}

		// Stable sort: count DESC, then canonical reaction order ASC so equal
		// counts render in the same order everywhere.
		$order = array_flip( self::REACTION_TYPES );
		uksort(
			$counts,
			static function ( string $a, string $b ) use ( $counts, $order ): int {
				if ( $counts[ $a ] !== $counts[ $b ] ) {
					return $counts[ $b ] <=> $counts[ $a ];
				}
				return ( $order[ $a ] ?? PHP_INT_MAX ) <=> ( $order[ $b ] ?? PHP_INT_MAX );
			}
		);

		return array_slice( array_keys( $counts ), 0, $limit );
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

	/**
	 * Delete every reaction row for one emoji slug and reconcile the affected
	 * objects' denormalised counts.
	 *
	 * Called when a custom reaction is removed (Pro): leaving the rows behind made
	 * get_counts() keep returning a slug the picker had dropped and label() could
	 * no longer name — an unpickable, unlabeled ghost count. Purging the rows and
	 * decrementing each affected post's reaction_count by however many it lost
	 * keeps the totals honest.
	 *
	 * @param string $emoji Reaction slug to purge.
	 * @return int Number of reaction rows deleted.
	 */
	public function purge_emoji( string $emoji ): int {
		$emoji = sanitize_key( $emoji );
		if ( '' === $emoji ) {
			return 0;
		}

		global $wpdb;

		// BATCHED, and deliberately WITHOUT an index on `emoji`.
		//
		// This was: a GROUP BY over the whole table to find the affected objects, then a single
		// unbounded DELETE. `emoji` is in no index — bn_reactions has PRIMARY (user_id,
		// object_type, object_id), object_recent and reaction_created — so the read is a full
		// scan of the LARGEST TABLE IN THE PRODUCT (one row per like), and the DELETE takes a
		// long table lock on the hottest write path there is. On shared hosting that is an
		// outage, not a slow admin action.
		//
		// The obvious fix is KEY emoji (emoji). It is the WRONG trade: every like pays an extra
		// index write, forever, to speed up something an owner does a handful of times in the
		// life of a site. Say no to it here so the next reader does not "helpfully" add it.
		//
		// So the scan stays (it is rare) and the LOCK goes: read a page, delete exactly those
		// rows by primary key, tally what each object lost, repeat. Every statement is short.
		$affected_map = array();
		$deleted      = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$page = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, object_type, object_id
					 FROM {$wpdb->prefix}bn_reactions
					 WHERE emoji = %s
					 LIMIT %d",
					$emoji,
					self::PURGE_BATCH
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$found = count( (array) $page );

			foreach ( (array) $page as $row ) {
				$key = (string) $row['object_type'] . ':' . (int) $row['object_id'];

				$affected_map[ $key ] = ( $affected_map[ $key ] ?? 0 ) + 1;

				// Delete by PRIMARY KEY — a point delete, no scan, no lock worth the name.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$deleted += (int) $wpdb->delete(
					$wpdb->prefix . 'bn_reactions',
					array(
						'user_id'     => (int) $row['user_id'],
						'object_type' => (string) $row['object_type'],
						'object_id'   => (int) $row['object_id'],
					),
					array( '%d', '%s', '%d' )
				);
			}
		} while ( self::PURGE_BATCH === $found );

		if ( empty( $affected_map ) ) {
			return 0;
		}

		$affected = array();
		foreach ( $affected_map as $key => $lost ) {
			list( $o_type, $o_id ) = explode( ':', (string) $key, 2 );

			$affected[] = array(
				'object_type' => $o_type,
				'object_id'   => $o_id,
				'n'           => $lost,
			);
		}

		foreach ( (array) $affected as $row ) {
			$object_type = (string) $row['object_type'];
			$object_id   = (int) $row['object_id'];
			$lost        = (int) $row['n'];

			// $lost is always >= 1: the map is built by counting rows we actually deleted.
			if ( 'post' === $object_type && $object_id > 0 ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_posts
						 SET reaction_count = GREATEST( 0, CAST( reaction_count AS SIGNED ) - %d )
						 WHERE id = %d",
						$lost,
						$object_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				wp_cache_delete( "post_{$object_id}", 'buddynext_posts' );
			}

			$this->invalidate_cache( $object_type, $object_id, 0 );
		}

		return $deleted;
	}
}

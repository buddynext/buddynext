<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Search index service.
 *
 * Manages the bn_search_index table — a lightweight unified index over posts,
 * users, and spaces. Index writes use INSERT ... ON DUPLICATE KEY UPDATE so
 * they are idempotent. Reads use FULLTEXT MATCH … AGAINST when the ft_search
 * index exists on the production table, and fall back to LIKE-based matching
 * in test environments where TEMPORARY tables do not support FULLTEXT indexes.
 *
 * @package BuddyNext\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Search;

/**
 * Handles search index writes and full-text reads.
 */
class SearchService {

	/**
	 * Default results per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Hard ceiling on rows reachable across search pagination.
	 *
	 * SCALE-CONTRACT §1: "Search results cap at 100/page with hard ceiling at
	 * 1000 across pagination." Bounds the OFFSET scan so deep pages can never
	 * walk the full ~6M-row search index, and bounds the result COUNT.
	 */
	private const MAX_RESULTS = 1000;

	/**
	 * Rows removed per statement by deindex_type(). Small enough that the lock on the
	 * search index is never held long on a shared host.
	 */
	/**
	 * Object-cache group.
	 */
	private const CACHE_GROUP = 'buddynext_search';

	/**
	 * Cache lifetime, in seconds.
	 */
	private const CACHE_TTL = 300;

	private const DEINDEX_BATCH = 2000;

	/**
	 * Shortest term the fuzzy fallback will run for. Below this, LIKE '%a%' matches
	 * nearly every row and SOUNDEX is noise.
	 */
	private const FUZZY_MIN_TERM_LENGTH = 3;

	/**
	 * Largest index on which the fuzzy full scan is still an acceptable trade.
	 */
	private const FUZZY_MAX_INDEX_ROWS = 50000;

	/**
	 * Upsert an object into the search index.
	 *
	 * @param string $object_type Type identifier (e.g. 'post', 'user', 'space').
	 * @param int    $object_id   ID of the object within its type.
	 * @param string $title       Searchable title string.
	 * @param string $content     Searchable body content (may be empty).
	 * @param int    $author_id   User who owns/created this object.
	 * @param string $visibility  'public' or 'private'.
	 * @param int    $space_id    Space ID for space-scoped content (0 = no space).
	 */
	public function index(
		string $object_type,
		int $object_id,
		string $title,
		string $content,
		int $author_id,
		string $visibility = 'public',
		int $space_id = 0
	): void {
		global $wpdb;

		// Nothing may be indexed as more visible than the space holding it.
		//
		// This is enforced HERE, at the single write door, and not at the call
		// sites, because the call sites are exactly what drifted: three of them
		// (the single-post indexer, the full-reindex loop, and the Jetonomy
		// discussion bridge) each decided visibility on their own and all three
		// got it wrong the same way — they read the object's own privacy and
		// ignored the space entirely. The guest search gate is literally
		// `visibility = 'public'`, so a public post written inside a PRIVATE or
		// SECRET space was returned, title and body, to anonymous visitors.
		//
		// Putting the rule at the door means an addon calling index() cannot
		// reintroduce the leak, and a fourth call site added later inherits it.
		if ( 'public' === $visibility && $space_id > 0 ) {
			$visibility = self::space_visibility_ceiling( $space_id );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_search_index
				    (object_type, object_id, title, content, author_id, space_id, visibility)
				 VALUES (%s, %d, %s, %s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE
				    title = VALUES(title),
				    content = VALUES(content),
				    author_id = VALUES(author_id),
				    space_id = VALUES(space_id),
				    visibility = VALUES(visibility),
				    updated_at = NOW()",
				$object_type,
				$object_id,
				$title,
				$content,
				$author_id,
				$space_id,
				$visibility
			)
		);

		// Surface a failed index write rather than silently dropping the object
		// from search (it would never appear in results with no signal).
		if ( false === $result ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'BuddyNext: search index write failed for %s#%d: %s', $object_type, $object_id, $wpdb->last_error )
			);
		}
	}

	/**
	 * The spaces whose content this viewer may find in search.
	 *
	 * Membership is the floor, not the whole answer. A space can be readable for a
	 * reason that has nothing to do with joining it: an OPEN space carrying a
	 * `required_ability` is readable by anyone holding the plan, and joining is
	 * optional. `can_view_content()` gets that right. This list did not, so a
	 * member who had paid for access found none of that space's content -
	 * reproduced with an open space gated on `tier:pro`: the viewer could open and
	 * read it, and searching a word that appears only in its posts returned
	 * nothing (card 10221739624).
	 *
	 * Free cannot answer the entitlement question - it ships no ability system - so
	 * it asks, and Pro answers. Same Free-asks/Pro-answers shape as the content
	 * gate itself.
	 *
	 * ## This seam may only WIDEN, and Free enforces that rather than trusting it
	 *
	 * The mirror of the narrow-only guard on
	 * {@see \BuddyNext\Spaces\SpaceVisibility::can_view_content()}. There the
	 * filter can refuse but never grant; here it can grant but never refuse, and
	 * the same reasoning applies - a seam into the search index for any plugin on
	 * the site would be a bigger hole than the one it was added to close. So:
	 *
	 *  - every id membership already granted is kept, whatever the listener returns,
	 *    so this cannot become a narrowing seam by the back door;
	 *  - every id the listener ADDS is checked against `can_view_content()` before
	 *    it is believed. A listener that returns every space on the site widens
	 *    the viewer's reach by exactly nothing.
	 *
	 * That check is the cost, so it is bounded and memoised: at most
	 * `buddynext_search_entitled_space_limit` additions are considered (default
	 * 200), and the resolved list is held per request per viewer, because a single
	 * search request runs this for each of its four queries.
	 *
	 * @since 1.1.6
	 *
	 * @param int $viewer_id Logged-in viewer.
	 * @return int[] Space ids, membership plus verified entitlements.
	 */
	private static function viewer_space_ids( int $viewer_id ): array {
		$memo = &self::viewer_space_memo();

		if ( isset( $memo[ $viewer_id ] ) ) {
			return $memo[ $viewer_id ];
		}

		$member_service = function_exists( 'buddynext_service' ) ? buddynext_service( 'space_members' ) : null;
		$membership     = ( is_object( $member_service ) && method_exists( $member_service, 'spaces_for_user' ) )
			? array_values( array_unique( array_filter( array_map( 'intval', (array) $member_service->spaces_for_user( $viewer_id ) ) ) ) )
			: array();

		/**
		 * Filter the spaces whose content a viewer may find in search.
		 *
		 * Answer with ADDITIONAL space ids the viewer is entitled to read without
		 * having joined - Pro adds gated spaces whose `required_ability` the viewer
		 * holds. Returning fewer ids than you were given has no effect: membership
		 * is re-added below, and every addition is verified against
		 * `can_view_content()`, so a listener cannot grant reach into a space the
		 * viewer may not actually read.
		 *
		 * @since 1.1.6
		 *
		 * @param int[] $space_ids Space ids from membership.
		 * @param int   $viewer_id Logged-in viewer.
		 */
		$candidates = (array) apply_filters( 'buddynext_search_viewer_spaces', $membership, $viewer_id );
		$candidates = array_values( array_unique( array_filter( array_map( 'intval', $candidates ) ) ) );

		$added = array_values( array_diff( $candidates, $membership ) );

		if ( array() !== $added ) {
			/**
			 * How many entitled-but-unjoined spaces a single search will verify.
			 *
			 * Each one costs a space fetch and an ability check. A community with
			 * thousands of gated spaces should not pay that on every keystroke, so
			 * the list is cut rather than allowed to grow without limit.
			 *
			 * @since 1.1.6
			 *
			 * @param int $limit     Maximum additions considered. Default 200.
			 * @param int $viewer_id Logged-in viewer.
			 */
			$limit = (int) apply_filters( 'buddynext_search_entitled_space_limit', 200, $viewer_id );

			if ( $limit > 0 && count( $added ) > $limit ) {
				$added = array_slice( $added, 0, $limit );
			}

			$spaces = function_exists( 'buddynext_service' ) ? buddynext_service( 'spaces' ) : null;

			foreach ( $added as $space_id ) {
				$space = is_object( $spaces ) ? $spaces->get( $space_id ) : null;

				if ( is_array( $space ) && \BuddyNext\Spaces\SpaceVisibility::can_view_content( $space, $viewer_id ) ) {
					$membership[] = $space_id;
				}
			}
		}

		$memo[ $viewer_id ] = array_values( array_unique( $membership ) );

		return $memo[ $viewer_id ];
	}

	/**
	 * The per-request memo behind viewer_space_ids().
	 *
	 * Returned by reference for the same reason as {@see self::ceiling_cache()} -
	 * so the resolver and the flush below cannot end up talking to two different
	 * static arrays.
	 *
	 * @since 1.1.6
	 *
	 * @return array<int, int[]>
	 */
	private static function &viewer_space_memo(): array {
		static $memo = array();

		return $memo;
	}

	/**
	 * Forget the memoised viewer-space lists.
	 *
	 * One search request runs four queries and each asks for the same list, so the
	 * memo is what keeps the entitlement checks from running four times over. The
	 * cost is that the list is fixed for the rest of the request.
	 *
	 * That is right almost always - membership and entitlement do not change
	 * mid-request - but it is not right when something in the same request CHANGES
	 * them: a member joins a space and then searches, or a test registers the
	 * listener after a first search has already been run. Both need to say so.
	 *
	 * @since 1.1.6
	 *
	 * @param int $viewer_id Viewer to forget, or 0 for all of them.
	 * @return void
	 */
	public static function flush_viewer_space_memo( int $viewer_id = 0 ): void {
		$memo = &self::viewer_space_memo();

		if ( $viewer_id > 0 ) {
			unset( $memo[ $viewer_id ] );

			return;
		}

		$memo = array();
	}

	/**
	 * The highest visibility content inside a given space may be indexed with.
	 *
	 * Registry-derived rather than a literal type list, so a site that registers
	 * a custom public space type is not silently demoted. A space that cannot be
	 * resolved returns 'private': failing closed is the only safe direction for a
	 * search index.
	 *
	 * A PLAN GATE also caps it, and that is the second half of the question this
	 * used to skip. `required_ability` on an OPEN space means the content is paid
	 * for; the type says 'open', so the ceiling came out 'public', and the guest
	 * query is literally `si.visibility = 'public'`. Gated posts were therefore
	 * searchable by anyone on the internet. Reproduced on the site: two posts in an
	 * open space carrying `tier:vip` sat in the index as `public`.
	 *
	 * Free deliberately does NOT evaluate the ability here — it cannot, and it does
	 * not need to. The presence of a gate is a fact about its own table, and it is
	 * enough to know the content is not public.
	 *
	 * Known narrowing, accepted: 'private' rows are matched by space MEMBERSHIP
	 * (`si.space_id IN (viewer_spaces)`), so a member who holds the plan but has
	 * not JOINED the gated space stops finding its content in search, even though
	 * they can read it. Leaking to the whole internet is the worse failure, so this
	 * fails closed; restoring reach for entitled non-members needs the viewer-space
	 * list to become entitlement-aware, which is its own change.
	 *
	 * Memoised per request because the full reindex walks every post on the site —
	 * without it, 100k posts across 200 spaces would issue 100k space lookups.
	 *
	 * @param int $space_id Space the content belongs to.
	 * @return string 'public' or 'private'.
	 */
	private static function space_visibility_ceiling( int $space_id ): string {
		$ceilings = &self::ceiling_cache();

		if ( isset( $ceilings[ $space_id ] ) ) {
			return $ceilings[ $space_id ];
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );

		if ( null === $space ) {
			$ceilings[ $space_id ] = 'private';

			return $ceilings[ $space_id ];
		}

		$members_only = \BuddyNext\Spaces\SpaceTypeRegistry::instance()
			->content_requires_membership( (string) ( $space['type'] ?? '' ) );

		$gated = '' !== (string) ( $space['required_ability'] ?? '' );

		$ceilings[ $space_id ] = ( $members_only || $gated ) ? 'private' : 'public';

		return $ceilings[ $space_id ];
	}

	/**
	 * The per-request memo behind space_visibility_ceiling().
	 *
	 * Held in one place and returned by reference so the resolver and the flush
	 * below cannot end up talking to two different static arrays.
	 *
	 * @return array<int, string>
	 */
	private static function &ceiling_cache(): array {
		static $ceilings = array();

		return $ceilings;
	}

	/**
	 * Forget a memoised space ceiling.
	 *
	 * The memo exists because a full reindex walks every post on the site and
	 * would otherwise issue one space lookup per post. That is per-request and
	 * harmless while a space's type is stable - but a space's type is exactly
	 * what changes in the request that matters. Anything that read the ceiling
	 * earlier in that request (indexing another post in the same space, say)
	 * left the OLD value cached, so the re-index that follows the change writes
	 * the visibility the space no longer has: content clamped shut stays shut
	 * after the space is re-opened, and - the direction that matters - content
	 * can be written back as public from a memo taken before it was closed.
	 *
	 * @param int|null $space_id Space to forget, or null to clear everything.
	 * @return void
	 */
	public static function flush_space_ceiling( ?int $space_id = null ): void {
		$ceilings = &self::ceiling_cache();

		if ( null === $space_id ) {
			$ceilings = array();
			return;
		}

		unset( $ceilings[ $space_id ] );
	}

	/**
	 * Re-apply a space's visibility ceiling to content already in the index.
	 *
	 * The write door applies this ceiling as content is written, which protects
	 * anything indexed AFTER a space became private. It does nothing for rows already
	 * sitting in the index, and flipping a space's type does not rewrite them:
	 * SearchIndexListener::on_space_updated() re-indexed the SPACE row and
	 * nothing else. So an open space full of public posts, switched to private,
	 * kept every one of those posts at visibility 'public' - and the guest search
	 * gate is literally `visibility = 'public'`, so anonymous visitors could still
	 * pull their titles and bodies out of a space they cannot open.
	 *
	 * Clamping down is done here, in one UPDATE, rather than by re-indexing each
	 * post: it is the security-critical direction, it must not depend on an async
	 * worker running, and it costs the same whether the space holds ten posts or
	 * fifty thousand. Restoring the other way - private space re-opened - cannot
	 * be done from the index alone, because a row's 'private' does not say whether
	 * it came from this ceiling or from the post's own privacy or a private
	 * author account; that direction re-indexes the posts so each one is decided
	 * by the same rule that wrote it originally.
	 *
	 * @param int $space_id Space whose indexed content should be re-clamped.
	 * @return int Rows changed.
	 */
	public function clamp_space_visibility( int $space_id ): int {
		global $wpdb;

		if ( $space_id <= 0 ) {
			return 0;
		}

		// Same ceiling the write door uses. Not a second copy of the rule.
		if ( 'private' !== self::space_visibility_ceiling( $space_id ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_search_index
				    SET visibility = 'private', updated_at = NOW()
				  WHERE space_id = %d
				    AND visibility = 'public'",
				$space_id
			)
		);

		return (int) $changed;
	}

	/**
	 * Run a grouped search across all indexed content types.
	 *
	 * Returns up to $per_group results per content type, keyed by type.
	 * Types searched are discovered dynamically from the index table so the
	 * result adapts automatically as new object types are indexed by addons.
	 *
	 * @param string $query     Search term.
	 * @param int    $viewer_id Viewer user ID for block/suspension exclusions.
	 *                          Pass 0 to skip block filtering.
	 * @param int    $per_group Max results per content type.
	 * @return array {
	 *     @type array[] $types {
	 *         @type string  $type    Object type slug (e.g. 'user', 'space', 'post').
	 *         @type array[] $results Flat result rows (search() items decorated with
	 *                                a uniform `title`, canonical `url`, and `subtitle`
	 *                                so REST consumers can render + link directly).
	 *         @type int     $total   Total matching records for this type in the index.
	 *     }
	 * }
	 */
	public function grouped_search( string $query, int $viewer_id, int $per_group = 5 ): array {
		global $wpdb;

		// Discover active object types from the index — adapts to addon content.
		// Cached for 5 minutes: the type list is stable between deploys and does
		// not need to reflect brand-new addon registrations immediately.
		$types = wp_cache_get( 'search_object_types', self::CACHE_GROUP );
		if ( false === $types ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$types = $wpdb->get_col( "SELECT DISTINCT object_type FROM {$wpdb->prefix}bn_search_index" );
			// cache-ttl-only: the type list is derived from the index itself and is flushed by
			// deindex_type(); a 5-minute window on a newly-appearing type is not a defect.
			wp_cache_set( 'search_object_types', $types, self::CACHE_GROUP, self::CACHE_TTL );
		}

		$type_groups = array();

		foreach ( (array) $types as $type ) {
			$type = sanitize_key( (string) $type );
			if ( '' === $type ) {
				continue;
			}

			$result = $this->search( $query, $type, $per_group, 1, $viewer_id );

			if ( empty( $result['items'] ) ) {
				continue;
			}

			$type_groups[] = array(
				'type'    => $type,
				'results' => array_map(
					fn( array $item ): array => $this->decorate_grouped_item( $item, $type ),
					$result['items']
				),
				'total'   => $result['total'],
			);
		}

		return array( 'types' => $type_groups );
	}

	/**
	 * List the distinct object types currently present in the search index.
	 *
	 * Mirrors the discovery grouped_search() uses so callers (e.g. the web
	 * /search results template) can surface a tab + section for every indexed
	 * type — including addon types like `job` (Career Board) or `listing`
	 * (Listora) — without hard-coding a type list. Cached 5 minutes under the
	 * same key grouped_search() warms.
	 *
	 * @return string[] Sanitized object_type slugs (may be empty).
	 */
	public function available_types(): array {
		global $wpdb;

		$types = wp_cache_get( 'search_object_types', self::CACHE_GROUP );
		if ( false === $types ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$types = $wpdb->get_col( "SELECT DISTINCT object_type FROM {$wpdb->prefix}bn_search_index" );
			// cache-ttl-only: the type list is derived from the index itself and is flushed by
			// deindex_type(); a 5-minute window on a newly-appearing type is not a defect.
			wp_cache_set( 'search_object_types', $types, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return array_values(
			array_filter(
				array_map(
					static fn( $t ): string => sanitize_key( (string) $t ),
					(array) $types
				)
			)
		);
	}

	/**
	 * Add a uniform `title` + `url` to a raw search() row for grouped output.
	 *
	 * The raw search index row only carries object_type / object_id / title /
	 * content / author_id, so REST consumers (the cmd+K typeahead overlay and the
	 * native app) cannot link anywhere without re-querying. This adds the two
	 * fields every consumer needs — a display title and a canonical link — while
	 * staying intentionally lightweight: it does NOT run the heavy per-type
	 * enrich_results() pipeline used by the on-page /search sections.
	 *
	 * @param array  $item Raw search() row (object_type, object_id, title, …).
	 * @param string $type Object-type slug for this group.
	 * @return array The item with `title`, `url`, and `subtitle` guaranteed.
	 */
	private function decorate_grouped_item( array $item, string $type ): array {
		$object_id = (int) ( $item['object_id'] ?? 0 );

		// Members store no human title in the index — resolve the display name.
		if ( in_array( $type, array( 'user', 'member' ), true ) ) {
			$user          = get_userdata( $object_id );
			$item['title'] = $user ? $user->display_name : __( 'Unknown', 'buddynext' );
		} elseif ( '' === (string) ( $item['title'] ?? '' ) ) {
			$item['title'] = __( 'Untitled', 'buddynext' );
		}

		$item['url']      = $this->resolve_item_url( $type, $object_id );
		$item['subtitle'] = (string) ( $item['subtitle'] ?? '' );

		return $item;
	}

	/**
	 * Resolve the canonical front-end URL for an indexed object.
	 *
	 * Delegates entirely to PageRouter — the same helpers enrich_results() uses —
	 * so grouped REST output links to exactly where the on-page sections do.
	 *
	 * @param string $type      Object-type slug (user/member, space, post, media).
	 * @param int    $object_id Object ID within its type.
	 * @return string Absolute URL, or '' when the type has no known route.
	 */
	private function resolve_item_url( string $type, int $object_id ): string {
		switch ( $type ) {
			case 'user':
			case 'member':
				return (string) \BuddyNext\Core\PageRouter::profile_url( $object_id );
			case 'space':
				return (string) \BuddyNext\Core\PageRouter::space_url( $object_id );
			case 'post':
			case 'media':
				return (string) \BuddyNext\Core\PageRouter::post_url( $object_id );
			default:
				// Addon/Pro content types (jobs, listings, courses, …) are WP
				// posts with their own permalinks; fall back to the post permalink
				// so indexed addon types still link somewhere real, not '#'.
				$permalink = get_permalink( $object_id );
				return false !== $permalink ? (string) $permalink : '';
		}
	}

	/**
	 * Whether the fuzzy fallback is affordable on this index.
	 *
	 * The fuzzy path is LIKE '%term%' plus SOUNDEX() — both non-sargable, so it is a FULL SCAN
	 * of bn_search_index. It fires whenever FULLTEXT matched nothing, i.e. on any typo, from an
	 * endpoint that does not require login. A stranger could scan the whole index by asking for
	 * gibberish, repeatedly. That is not a slow query; it is a denial-of-service surface.
	 *
	 * So it is bounded twice:
	 *
	 *   - Not on a large index. Full-scanning a million rows to be kind about a typo is not a
	 *     trade worth making; on a big community FULLTEXT is good enough on its own.
	 *   - Not on a 1-2 character term, where LIKE '%a%' matches nearly everything and SOUNDEX
	 *     is noise anyway.
	 *
	 * A site that wants it back can raise the ceiling with buddynext_search_fuzzy_max_rows.
	 *
	 * @param string $query The sanitised search term.
	 * @return bool
	 */
	private function fuzzy_is_affordable( string $query ): bool {
		if ( mb_strlen( $query ) < self::FUZZY_MIN_TERM_LENGTH ) {
			return false;
		}

		/**
		 * Largest search index on which the fuzzy fallback still runs.
		 *
		 * @since 1.0.8
		 *
		 * @param int $max_rows Row ceiling. 0 disables fuzzy entirely.
		 */
		$max_rows = (int) apply_filters( 'buddynext_search_fuzzy_max_rows', self::FUZZY_MAX_INDEX_ROWS );

		if ( $max_rows <= 0 ) {
			return false;
		}

		return $this->index_row_count() <= $max_rows;
	}

	/**
	 * Approximate size of the search index, cached — this must not itself be a cost.
	 *
	 * @return int
	 */
	private function index_row_count(): int {
		$cached = wp_cache_get( 'bn_search_index_rows', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index" );

		// cache-ttl-only: an approximate index size is all this needs; being 5 minutes stale
		// cannot change the answer near the ceiling in any way that matters.
		wp_cache_set( 'bn_search_index_rows', $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Remove an object from the search index.
	 *
	 * @param string $object_type Type identifier.
	 * @param int    $object_id   Object ID.
	 */
	public function deindex( string $object_type, int $object_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);

		// A failed delete means removed content keeps surfacing in search — log it.
		if ( false === $deleted ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf( 'BuddyNext: search deindex failed for %s#%d: %s', $object_type, $object_id, $wpdb->last_error )
			);
		}
	}

	/**
	 * Remove EVERY row of one object type from the search index.
	 *
	 * The bulk counterpart to deindex(), for when an owner switches an integration's
	 * search off. Gating the write path alone is not enough: everything indexed while the
	 * integration was on would keep surfacing forever, and the owner has no way to reach it.
	 *
	 * The cache flush is load-bearing, not hygiene. available_types() is `SELECT DISTINCT
	 * object_type FROM bn_search_index`, cached for 5 minutes, and the results template
	 * builds its type tabs from it. Delete the rows without flushing and the disabled
	 * integration keeps its own search tab — now an empty one — for up to five minutes.
	 *
	 * Deleted in batches: a site that has indexed a million job listings would otherwise
	 * hold a long table lock on the search index, which on shared hosting is an outage.
	 *
	 * @param string $object_type Type identifier (e.g. 'job', 'listing', 'discussion').
	 * @return int Rows removed.
	 */
	public function deindex_type( string $object_type ): int {
		global $wpdb;

		$object_type = sanitize_key( $object_type );
		if ( '' === $object_type ) {
			return 0;
		}

		$total = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}bn_search_index WHERE object_type = %s LIMIT %d",
					$object_type,
					self::DEINDEX_BATCH
				)
			);

			$total += $rows;
		} while ( $rows >= self::DEINDEX_BATCH );

		wp_cache_delete( 'search_object_types', self::CACHE_GROUP );

		return $total;
	}

	/**
	 * Normalise a caller-supplied type filter to the singular object_type the
	 * index stores. The index uses singular values (user/post/space/hashtag) but
	 * callers (and the documented API) often pass plurals (users/posts/…); without
	 * this map a plural filter silently matched nothing. Unknown values pass
	 * through sanitised so a future type is not swallowed.
	 *
	 * @param string $type Raw type filter (singular or plural).
	 * @return string Singular object_type for the WHERE clause.
	 */
	private static function normalize_object_type( string $type ): string {
		$type = sanitize_key( $type );
		$map  = array(
			'users'    => 'user',
			'posts'    => 'post',
			'spaces'   => 'space',
			'hashtags' => 'hashtag',
			'members'  => 'user',
		);
		return $map[ $type ] ?? $type;
	}

	/**
	 * Search the index for the given query string.
	 *
	 * Uses FULLTEXT MATCH … AGAINST when the ft_search index exists on the
	 * production table. Falls back to LIKE matching in environments where the
	 * index is absent (e.g. the WP test suite's TEMPORARY tables).
	 *
	 * @param string $query     Raw search string (sanitised internally).
	 * @param string $type      Optional object_type filter (singular or plural,
	 *                          e.g. user/users). Empty = all types.
	 * @param int    $per_page  Results per page (max 50).
	 * @param int    $page      1-based page number.
	 * @param int    $viewer_id ID of the requesting user. When non-zero, users
	 *                          who have a block relationship with this viewer are
	 *                          excluded from results. Pass 0 to skip filtering.
	 * @return array{items: array[], total: int}
	 */
	public function search( string $query, string $type = '', int $per_page = self::DEFAULT_LIMIT, int $page = 1, int $viewer_id = 0 ): array {
		global $wpdb;

		$per_page   = min( $per_page, 50 );
		$page       = max( 1, $page );
		$offset     = ( $page - 1 ) * $per_page;
		$safe_query = sanitize_text_field( $query );

		// A query shorter than the FULLTEXT minimum token size can never match in
		// BOOLEAN MODE, so short queries returned nothing. Detect them and route to
		// the LIKE path (substring), which matches a 1-2 char term. Normal-length
		// queries still use FULLTEXT as the primary path.
		$min_token    = $this->fulltext_min_token();
		$is_short     = mb_strlen( $safe_query ) < $min_token;
		$use_fulltext = $this->has_fulltext_index() && ! $is_short;

		// The `media` pseudo-type is posts that carry attachments. It reuses the
		// whole post-search pipeline (FULLTEXT/LIKE seam, block + suspension
		// exclusions, date window, sort) and only adds a join to bn_posts on a
		// non-empty media_ids column. Map it to the real `post` object_type and
		// raise a flag so the SQL builders add that join; the BOOLEAN-MODE + date
		// logic the template used to own now lives entirely here.
		$media_only = ( 'media' === $type );
		if ( $media_only ) {
			$type = 'post';
		}

		// SCALE-CONTRACT §1: hard 1000-row ceiling across pagination. Bound the
		// scanned window so a deep `page` cannot OFFSET past the ceiling. Past
		// it, $row_limit is 0 (LIMIT 0 → no rows) — there is nothing reachable.
		$offset    = min( $offset, self::MAX_RESULTS );
		$row_limit = max( 0, min( $per_page, self::MAX_RESULTS - $offset ) );

		$type_where  = '';
		$type_params = array();

		if ( '' !== $type ) {
			$type_where  = ' AND si.object_type = %s';
			$type_params = array( self::normalize_object_type( $type ) );
		}

		$block_where  = '';
		$block_params = array();

		if ( $viewer_id > 0 ) {
			$privacy = buddynext_service( 'privacy' );

			// Search semantics: exclude any block relationship (all types, both
			// directions) on the item subject, plus authors the viewer has
			// `restrict`ed (a search-surface limit, forward only). Both routed
			// through the one canonical builder so the rules can't drift.
			[ $subject_sql, $subject_params ]   = $privacy->block_exclude_sql( $viewer_id, 'si.object_id', null, null );
			[ $restrict_sql, $restrict_params ] = $privacy->block_exclude_sql( $viewer_id, 'si.author_id', array( 'restrict' ), array() );

			if ( '' !== $subject_sql ) {
				$block_where .= ' AND ' . $subject_sql;
				$block_params = array_merge( $block_params, $subject_params );
			}
			if ( '' !== $restrict_sql ) {
				$block_where .= ' AND ' . $restrict_sql;
				$block_params = array_merge( $block_params, $restrict_params );
			}
		}

		// Media-only join: restrict post results to those that have attachments.
		// Static SQL — no user data — so it is safe to interpolate alongside the
		// other fragment strings.
		$media_join  = $media_only ? " INNER JOIN {$wpdb->prefix}bn_posts mp ON mp.id = si.object_id" : '';
		$media_where = $media_only ? " AND mp.media_ids IS NOT NULL AND mp.media_ids != ''" : '';

		// Exclude suspended + shadow-banned authors' content from all search results
		// via the shared discovery gate (ANY active suspension — the discoverability
		// rule, not the hide_posts content variant). One definition shared with the
		// member directory + explore. See ModerationService::discovery_exclude_sql().
		$excluded_where = ' ' . buddynext_service( 'moderation' )->discovery_exclude_sql( 'si.author_id' );

		/**
		 * Filter the query args before SQL is built for the search.
		 *
		 * Use this filter to modify per_page, page, or append additional WHERE
		 * constraints before the database query executes. Complements the
		 * buddynext_search_results filter which operates on the result set.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args      Query args: per_page, page, type, viewer_id.
		 *                          Also honoured when set by a contributor:
		 *                          `date` (week|month|year), `sort` (recent) and
		 *                          `scope_space_id` (restrict CONTENT results to
		 *                          one space; distinct from the Pro-owned
		 *                          `space_id` member filter, which Pro strips
		 *                          from unentitled viewers).
		 * @param string $query     Raw (unsanitised) search string.
		 * @param int    $viewer_id Viewing user ID.
		 */
		$search_args = apply_filters(
			'buddynext_search_query_args',
			array(
				'per_page'  => $per_page,
				'page'      => $page,
				'type'      => $type,
				'viewer_id' => $viewer_id,
			),
			$query,
			$viewer_id
		);

		$per_page = min( (int) ( $search_args['per_page'] ?? $per_page ), 50 );
		$page     = max( 1, (int) ( $search_args['page'] ?? $page ) );

		// Re-derive BOTH bounds from the post-filter page/per_page, and re-apply
		// the SCALE-CONTRACT §1 clamp with them.
		//
		// This used to recompute $offset only. $row_limit kept the value computed
		// further up from the PRE-filter per_page, so a contributor that changed
		// per_page on this seam left the two disagreeing — asking for 50 rows and
		// receiving 20, at an offset derived from the 50. The ceiling clamp
		// applied up there was also discarded here, making it dead for the offset
		// that actually reaches the SQL.
		//
		// With no contributor the values are identical to the earlier pass, so
		// this changes nothing for the ordinary path; it only stops the filtered
		// path from being internally inconsistent.
		$offset    = min( ( $page - 1 ) * $per_page, self::MAX_RESULTS );
		$row_limit = max( 0, min( $per_page, self::MAX_RESULTS - $offset ) );

		// ------------------------------------------------------------------ //
		// Optional date window + sort order. Sourced from the seam args so the
		// web /search page (and any caller) can pass `date` / `sort` without a
		// signature change. `date` accepts week|month|year (anything else = no
		// window). `sort` accepts recent (updated_at DESC) — relevance is the
		// default when the FULLTEXT path is active. All values map to literal
		// SQL fragments; no user data is interpolated.
		// ------------------------------------------------------------------ //
		$date_where = '';
		$date_key   = isset( $search_args['date'] ) ? sanitize_key( (string) $search_args['date'] ) : '';
		switch ( $date_key ) {
			case 'week':
				$date_where = ' AND si.updated_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )';
				break;
			case 'month':
				$date_where = ' AND si.updated_at >= DATE_SUB( NOW(), INTERVAL 1 MONTH )';
				break;
			case 'year':
				$date_where = ' AND si.updated_at >= DATE_SUB( NOW(), INTERVAL 1 YEAR )';
				break;
		}
		$sort_recent = isset( $search_args['sort'] ) && 'recent' === sanitize_key( (string) $search_args['sort'] );

		// ------------------------------------------------------------------ //
		// Optional single-space scope, for searching inside one space rather
		// than the whole community. Narrowing only: it is ANDed with the
		// visibility gate below, never instead of it, so scoping to a space the
		// viewer cannot see returns nothing rather than leaking its contents.
		// That gate is the one place space access is decided for search, and
		// this must not become a second one.
		//
		// absint()'d and embedded directly, exactly as the $viewer_spaces IN()
		// list below is — an integer cannot carry injection, and keeping it out
		// of the bound params leaves all four query builders' parameter order
		// untouched. `space` is a BTREE index on this column, so the scope is a
		// key lookup rather than a scan.
		//
		// The key is `scope_space_id`, NOT `space_id`, and the distinction is
		// load-bearing rather than cosmetic. `space_id` already existed as one of
		// the five PRO-owned advanced keys, meaning "members of this space" —
		// Pro answers it on the buddynext_search_advanced_where seam above by
		// joining bn_space_members, and AdvancedSearchFilters::apply_pro_args()
		// STRIPS it from these args whenever the viewer fails the
		// search.saved_advanced entitlement (which includes every anonymous
		// visitor). Scoping a FREE feature to that key would therefore work on a
		// Free-only site and silently stop working the moment monetization was
		// switched on, handing a member the whole community's results while the
		// UI told them they had searched one space. Wrong results are worse than
		// a missing feature, so the two concerns get two keys.
		//
		// Still not applied to member searches: user rows carry no space_id of
		// their own, so the column test would match nothing. The type test
		// mirrors the advanced-where block above; the two must agree on which
		// types are member searches.
		// ------------------------------------------------------------------ //
		$is_member_search = in_array( $type, array( 'user', 'member' ), true );
		$scope_space      = ( ! $is_member_search && isset( $search_args['scope_space_id'] ) ) ? absint( $search_args['scope_space_id'] ) : 0;
		$space_where      = $scope_space > 0 ? " AND si.space_id = {$scope_space}" : '';

		/*
		 * Advanced member-search WHERE clauses — contributed by whoever OWNS the tables.
		 *
		 * Free used to build these itself: EXISTS subqueries against bn_subscriptions,
		 * bn_membership_tiers, bn_member_label_assignments, bn_member_labels and
		 * bn_analytics_events. Every one of those tables is created by buddynext-PRO, and on a
		 * Free-only site none of them exist.
		 *
		 * The comment that used to sit here said: "When Pro is inactive, no caller populates these
		 * args so no clause is emitted and the missing tables are never referenced."
		 *
		 * It was false, and FREE was what made it false. SearchController::collect_advanced_args()
		 * reads tier_slug / member_label / active_within_days straight off the REST request — a
		 * route whose permission_callback is __return_true — and merges them into this very args
		 * array at priority 5. Free populated the args, then Free consumed them. Pro was never in
		 * the loop.
		 *
		 * So on a Free-only site, ?q=a&type=user&member_label=vip emitted SQL against a table that
		 * does not exist: MySQL 1146, get_results() returns null, (array) null is [], and search
		 * returned ZERO results with HTTP 200 — silently, to anonymous visitors, with a database
		 * error in the log on every request. The comment then guaranteed nobody grepping for the
		 * cause would find it, because it asserted the exact opposite of what the code did.
		 *
		 * FREE-PRO-SEAM.md §7: Free must NEVER reference a Pro-owned table. So Free no longer
		 * knows how to filter by any of them. It offers a seam and applies whatever comes back;
		 * with nothing listening, an unserveable filter is simply ignored rather than poisoning the
		 * whole query. Refusing to answer a filter is a fine outcome. Silently answering "nothing
		 * matched" is not — the caller cannot tell it apart from an empty community.
		 */
		$advanced_where  = '';
		$advanced_params = array();

		if ( in_array( $type, array( 'user', 'member' ), true ) ) {
			/**
			 * Contribute an advanced member-search WHERE clause.
			 *
			 * The clause is appended to the member-search query and its params bound in order, so
			 * every value MUST be a `%s`/`%d` placeholder — never an interpolated value.
			 *
			 * This is the seam that keeps Free out of Pro's tables: an extension that owns a table
			 * answers here, and Free never learns the table's name.
			 *
			 * @param array{where: string, params: array<int, mixed>} $clause      Clause so far.
			 * @param array<string, mixed>                            $search_args Search args (incl. any advanced keys).
			 * @param string                                          $type        Search type ('user'|'member').
			 * @param int                                             $viewer_id   Viewer, 0 when anonymous.
			 */
			$clause = apply_filters(
				'buddynext_search_advanced_where',
				array(
					'where'  => '',
					'params' => array(),
				),
				$search_args,
				$type,
				$viewer_id
			);

			if ( is_array( $clause ) ) {
				$advanced_where  = (string) ( $clause['where'] ?? '' );
				$advanced_params = array_values( (array) ( $clause['params'] ?? array() ) );
			}

			// A clause whose placeholders and params disagree would shift every later binding in
			// the query. Drop the contribution rather than corrupt the search.
			if ( substr_count( $advanced_where, '%' ) !== count( $advanced_params ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'BuddyNext search: a buddynext_search_advanced_where contributor returned %d placeholder(s) but %d param(s). Clause discarded.',
						substr_count( $advanced_where, '%' ),
						count( $advanced_params )
					)
				);
				$advanced_where  = '';
				$advanced_params = array();
			}
		}

		/**
		 * Allow an external search driver (Elasticsearch, Algolia, etc.) to
		 * short-circuit the built-in SQL search. Return a non-null value from
		 * this filter — shaped as `array{ items: array[], total: int }` — to
		 * bypass the default query entirely.
		 *
		 * @since 1.0.0
		 *
		 * @param array|null $driver_result Null by default; return a result array to override.
		 * @param string     $query         Raw (unsanitised) search string.
		 * @param string     $type          Object-type filter, or '' for all types.
		 * @param int        $per_page      Results per page.
		 * @param int        $page          1-based page number.
		 */
		$driver_result = apply_filters( 'buddynext_search_results', null, $query, $type, $per_page, $page );
		if ( null !== $driver_result ) {
			return $driver_result;
		}

		// Visibility gate. Guests and the default path see only public rows. A
		// logged-in viewer additionally sees content in spaces they belong to: the
		// private space itself (space rows store their id in object_id, space_id 0)
		// and any content whose space_id is one of their spaces. Space ids are cast
		// to int and embedded directly (an all-integer IN() list is injection-safe),
		// so the four search queries below need no extra prepared params.
		$visibility_where = "si.visibility = 'public'";
		if ( $viewer_id > 0 ) {
			$viewer_spaces = self::viewer_space_ids( $viewer_id );
			if ( ! empty( $viewer_spaces ) ) {
				$space_in         = implode( ',', array_map( 'absint', $viewer_spaces ) );
				$visibility_where = "( si.visibility = 'public'"
					. " OR ( si.object_type = 'space' AND si.object_id IN ({$space_in}) )"
					. " OR ( si.space_id IN ({$space_in}) ) )";
			}
		}

		$total = 0;
		$rows  = array();

		if ( $use_fulltext ) {
			// FULLTEXT path — uses ft_search index for performance on production.
			// $search_condition is the output of a prior $wpdb->prepare() call — it is already
			// escaped and safe to embed. $excluded_where and $block_where contain only
			// table/column names with no user-supplied data. ReplacementsWrongNumber is a
			// false-positive here because $search_condition contains MATCH syntax with '%'.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$search_condition = $wpdb->prepare(
				'MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE)',
				$safe_query . '*'
			);

			// Members-tier profile values live in their own column with their own FULLTEXT index,
			// and this is the ONLY place that names it. An anonymous searcher never reaches this
			// branch, so a field a member chose to show only to other members cannot surface to a
			// stranger — the boundary is structural, not a flag someone can get wrong later.
			//
			// MATCH() must name exactly one index's column list, which is why this is a second
			// MATCH OR'd in rather than three columns in one.
			if ( $viewer_id > 0 ) {
				$search_condition = '( ' . $search_condition . ' OR ' . $wpdb->prepare(
					'MATCH(si.content_members) AGAINST(%s IN BOOLEAN MODE)',
					$safe_query . '*'
				) . ' )';
			}

			// Exact/prefix name boost: FULLTEXT BOOLEAN MODE ranks title + content
			// equally, and member rows store the name in content with an empty title,
			// so an exact-name query was not surfaced first. This pre-prepared
			// fragment (same safe-to-embed pattern as $search_condition) sorts an
			// exact title/content match first (0), then a prefix match (1), then the
			// rest (2) — so searching a member's name puts that member at the top.
			$boost_like   = $wpdb->esc_like( $safe_query );
			$name_boost   = $wpdb->prepare(
				'(CASE WHEN si.title = %s OR si.content = %s THEN 0'
				. ' WHEN si.title LIKE %s OR si.content LIKE %s THEN 1 ELSE 2 END)',
				$safe_query,
				$safe_query,
				$boost_like . '%',
				$boost_like . '%'
			);
			$order_clause = $sort_recent
				? 'si.updated_at DESC'
				: $name_boost . ' ASC, relevance DESC, si.updated_at DESC';

			// SCALE-CONTRACT §3: bound the COUNT so it never scans past the
			// 1000-row ceiling. Totals beyond it render as "1000+" in the UI.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
						SELECT 1
						 FROM {$wpdb->prefix}bn_search_index si
						 {$media_join}
						 WHERE {$visibility_where}
						   AND {$search_condition}
						   {$type_where}
						   {$media_where}
						   {$block_where}
						   {$excluded_where}
						   {$advanced_where}
						   {$date_where}
					   {$space_where}
						 LIMIT %d
					) bn_bounded",
					...array_merge( $type_params, $block_params, $advanced_params, array( self::MAX_RESULTS + 1 ) )
				)
			);

			// The WHERE reuses $search_condition — the SAME string the COUNT above used. It used to
			// rebuild `MATCH(si.title, si.content)` inline here instead, which meant the count and
			// the rows could disagree: once the members tier was OR'd into $search_condition, the
			// COUNT found the member and this query did not, so search reported a total and
			// returned nothing. Two copies of one condition will always drift; there is now one.
			//
			// `relevance` stays scored on the public columns only. A members-tier hit still ranks
			// (it falls to the bottom on relevance and is ordered by the name boost / recency),
			// and scoring it would mean a second AGAINST on every row for a rare case.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT si.object_type, si.object_id, si.title, si.content, si.author_id, si.visibility, si.created_at,
					        MATCH(si.title, si.content) AGAINST(%s IN BOOLEAN MODE) AS relevance
					 FROM {$wpdb->prefix}bn_search_index si
					 {$media_join}
					 WHERE {$visibility_where}
					   AND {$search_condition}
					   {$type_where}
					   {$media_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}
					   {$date_where}
					   {$space_where}
					 ORDER BY {$order_clause}
					 LIMIT %d OFFSET %d",
					...array_merge( array( $safe_query . '*' ), $type_params, $block_params, $advanced_params, array( $row_limit, $offset ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		// LIKE path. Runs as the PRIMARY search for short queries (below the FULLTEXT
		// token size) and when no FULLTEXT index exists, AND as a FUZZY fallback when
		// the FULLTEXT path matched nothing — so a typo'd or too-short query still
		// returns results instead of an empty page. When acting as the fuzzy
		// fallback, SOUNDEX (phonetic) matching is added so a misspelled name
		// ("jhon" → "John") still surfaces.
		$fuzzy = $use_fulltext && 0 === $total && $this->fuzzy_is_affordable( $safe_query );
		if ( ! $use_fulltext || $fuzzy ) {
			$like = '%' . $wpdb->esc_like( $safe_query ) . '%';

			if ( $fuzzy ) {
				// SOUNDEX on si.CONTENT is gone, and not only because it is slow.
				//
				// SOUNDEX() encodes the first letter plus a few consonants of the WHOLE string
				// into a 4-character code. On a document body that is, in effect,
				// SOUNDEX(first word) — comparing it to SOUNDEX('jhon') is meaningless. It was
				// a non-sargable full scan of bn_search_index computing an answer that was also
				// wrong. Phonetic matching only makes sense on a short field, so it stays on
				// the title.
				$like_match  = '( si.title LIKE %s OR si.content LIKE %s OR SOUNDEX(si.title) = SOUNDEX(%s) )';
				$like_params = array( $like, $like, $safe_query );
			} else {
				$like_match  = '( si.title LIKE %s OR si.content LIKE %s )';
				$like_params = array( $like, $like );
			}

			// The members tier, on the fallback path too — and ONLY for a logged-in viewer. The
			// FULLTEXT branch is the production path, but this is the one that runs on short
			// queries, on a fuzzy retry, and on any host without a FULLTEXT index. Fixing privacy
			// on one path and not the other would leave the hole open exactly where nobody looks.
			if ( $viewer_id > 0 ) {
				$like_match    = '( ' . $like_match . ' OR si.content_members LIKE %s )';
				$like_params[] = $like;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// SCALE-CONTRACT §3: bound the COUNT to the 1000-row ceiling.
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
						SELECT 1
						 FROM {$wpdb->prefix}bn_search_index si
						 {$media_join}
						 WHERE {$visibility_where}
						   AND {$like_match}
						   {$type_where}
						   {$media_where}
						   {$block_where}
						   {$excluded_where}
						   {$advanced_where}
						   {$date_where}
					   {$space_where}
						 LIMIT %d
					) bn_bounded",
					...array_merge( $like_params, $type_params, $block_params, $advanced_params, array( self::MAX_RESULTS + 1 ) )
				)
			);

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT si.object_type, si.object_id, si.title, si.content, si.author_id, si.visibility, si.created_at
					 FROM {$wpdb->prefix}bn_search_index si
					 {$media_join}
					 WHERE {$visibility_where}
					   AND {$like_match}
					   {$type_where}
					   {$media_where}
					   {$block_where}
					   {$excluded_where}
					   {$advanced_where}
					   {$date_where}
					   {$space_where}
					 ORDER BY si.updated_at DESC
					 LIMIT %d OFFSET %d",
					...array_merge( $like_params, $type_params, $block_params, $advanced_params, array( $row_limit, $offset ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}

		$items = array_map(
			fn( $r ) => array(
				'object_type' => $r['object_type'],
				'object_id'   => (int) $r['object_id'],
				'title'       => $r['title'],
				'content'     => $r['content'] ?? '',
				'author_id'   => (int) $r['author_id'],
				'created_at'  => $r['created_at'],
			),
			(array) $rows
		);

		/**
		 * Filter each search result item before the set is returned.
		 *
		 * Fires once per item on the built-in SQL path (it does NOT run when an
		 * external driver short-circuits buddynext_search_results). Use it to
		 * enrich or annotate rows. To remove results, exclude them at the query
		 * level via buddynext_search_query_args — dropping items here would
		 * desync the `total` count and cursor, so this filter only mutates.
		 *
		 * @param array  $item      Result row (object_type, object_id, title, content, author_id, created_at).
		 * @param string $query     Sanitised search query.
		 * @param string $type      Object-type filter ('' for all).
		 * @param int    $viewer_id Viewing user ID (0 = anonymous).
		 * @param array  $args      Query args (per_page, page, type, viewer_id).
		 */
		$items = array_map(
			function ( $item ) use ( $safe_query, $type, $viewer_id, $per_page, $page ) {
				return (array) apply_filters(
					'buddynext_search_item',
					$item,
					$safe_query,
					$type,
					$viewer_id,
					array(
						'per_page'  => $per_page,
						'page'      => $page,
						'type'      => $type,
						'viewer_id' => $viewer_id,
					)
				);
			},
			$items
		);

		$results = array(
			'items' => $items,
			'total' => $total,
		);

		// Apply the existing buddynext_search_results filter (results side).
		// Note: the filter is already applied above in the driver short-circuit path.
		// This second call is intentionally skipped here to avoid double-filtering.

		/**
		 * Fires after a search is performed and results are computed.
		 *
		 * Use: Pro saved searches, AI relevance signals. Viewer ID is available
		 * for personalised logging without altering the returned result set.
		 *
		 * @since 1.0.0
		 *
		 * @param string $query     Sanitised search query string.
		 * @param int    $viewer_id Viewing user ID (0 = anonymous).
		 * @param array  $args      Query args used: per_page, page, type, viewer_id.
		 * @param array  $results   Result set: items[], total.
		 */
		do_action(
			'buddynext_search_performed',
			$safe_query,
			$viewer_id,
			array(
				'per_page'  => $per_page,
				'page'      => $page,
				'type'      => $type,
				'viewer_id' => $viewer_id,
			),
			$results
		);

		return $results;
	}

	/**
	 * Return the member (user) IDs matching a free-text term from the shared
	 * bn_search_index, FULLTEXT when the ft_search index is present (LIKE fallback
	 * otherwise — same seam search() uses). This is the single member-search-by-term
	 * primitive the member directory routes through, so the directory search box and
	 * the unified search return the same members for a query.
	 *
	 * Pure term-match (no moderation/visibility gate) — the directory's own query
	 * applies the suspended/shadowban/block/dir-opt-out exclusion on top, so this stays
	 * a thin, reusable lookup. Bounded by $limit (people browse a few pages of results).
	 *
	 * Matching is kept deliberately IDENTICAL to search()'s FULLTEXT branch, because
	 * the two surfaces are searching the same column of the same table and a member
	 * cannot be expected to know which box applies which rule. Two differences used
	 * to make the directory strictly narrower, and both read to a customer as
	 * "profile fields are not searchable under Members":
	 *
	 *   1. No prefix wildcard. search() appends '*' (:708); this passed the bare
	 *      term, so only whole indexed words of at least innodb_ft_min_token_size
	 *      characters could match. Measured on a stock install: 'Priy' returned 0
	 *      here and 6 from Explore, for the same term against the same rows.
	 *   2. No members-tier column. search() ORs MATCH(content_members) for a
	 *      logged-in viewer (:719-722); this never read that column, so a field a
	 *      member limited to members was searchable in Explore and invisible in the
	 *      directory. $viewer_id gates it exactly as search() does — an anonymous
	 *      caller never reaches that branch, so the privacy boundary stays
	 *      structural.
	 *
	 * @param string $term      Search term.
	 * @param int    $limit     Max IDs (clamped 1..1000).
	 * @param int    $viewer_id Viewer, or 0 for anonymous. Non-zero also searches
	 *                          members-visibility profile values.
	 * @return int[] Matching member user IDs, most-relevant first under FULLTEXT.
	 */
	public function match_member_ids( string $term, int $limit = 500, int $viewer_id = 0 ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return array();
		}

		global $wpdb;
		$limit = max( 1, min( 1000, $limit ) );

		// A query shorter than the FULLTEXT minimum token size can never match in
		// BOOLEAN MODE even with the wildcard, so short terms route to the LIKE
		// path — the same seam and the same reason as search() (:462-464). Without
		// this the directory still disagreed with Explore on 1-2 character terms.
		$use_fulltext = $this->has_fulltext_index()
			&& mb_strlen( $term ) >= $this->fulltext_min_token();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $use_fulltext ) {
			$public_match = $wpdb->prepare(
				'MATCH(title, content) AGAINST(%s IN BOOLEAN MODE)',
				$term . '*'
			);

			$where_match = $public_match;
			if ( $viewer_id > 0 ) {
				// MATCH() must name exactly one index's column list, so the
				// members-tier column is a second MATCH OR'd in rather than a third
				// column here — same shape as search().
				$where_match = '( ' . $public_match . ' OR ' . $wpdb->prepare(
					'MATCH(content_members) AGAINST(%s IN BOOLEAN MODE)',
					$term . '*'
				) . ' )';
			}

			$rows = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_match is prepare() output.
					"SELECT object_id FROM {$wpdb->prefix}bn_search_index
					 WHERE object_type IN ('user','member')
					   AND {$where_match}
					 ORDER BY {$public_match} DESC
					 LIMIT %d",
					$limit
				)
			);
		} elseif ( $viewer_id > 0 ) {
			// Members-tier values are searchable on this path too, for the same
			// logged-in-only reason as the FULLTEXT branch above.
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->prefix}bn_search_index
					 WHERE object_type IN ('user','member')
					   AND ( title LIKE %s OR content LIKE %s OR content_members LIKE %s )
					 LIMIT %d",
					$like,
					$like,
					$like,
					$limit
				)
			);
		} else {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT object_id FROM {$wpdb->prefix}bn_search_index
					 WHERE object_type IN ('user','member') AND ( title LIKE %s OR content LIKE %s )
					 LIMIT %d",
					$like,
					$like,
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
	}

	/**
	 * Turn raw search() items into presentation-ready rows for a result section.
	 *
	 * The search index only stores object_id / title / content / author_id, so a
	 * naive section part re-queries the owning table (and the follow / membership
	 * tables) once per row — an N+1. This method does the enrichment in batch:
	 * spaces hydrate through SpaceService + a single membership_map; members
	 * resolve display name / bio / following-state via one following_map; posts
	 * hydrate through PostService::hydrate(). The section parts then render the
	 * returned arrays without touching the database.
	 *
	 * @param array[] $items     Items from search() (object_id / content / author_id).
	 * @param string  $type      One of 'user' | 'member', 'post', 'space', 'media'.
	 * @param int     $viewer_id Viewing user ID (0 = anonymous).
	 * @return array[] Presentation rows; shape depends on $type (see inline docs).
	 */
	public function enrich_results( array $items, string $type, int $viewer_id = 0 ): array {
		if ( empty( $items ) ) {
			return array();
		}

		$object_ids = array_values(
			array_filter(
				array_map(
					static fn( $item ): int => (int) ( $item['object_id'] ?? 0 ),
					$items
				)
			)
		);

		if ( empty( $object_ids ) ) {
			return array();
		}

		switch ( $type ) {
			case 'user':
			case 'member':
				return $this->enrich_members( $object_ids, $viewer_id );
			case 'space':
				return $this->enrich_spaces( $object_ids, $viewer_id );
			case 'post':
			case 'media':
				return $this->enrich_posts( $items );
			default:
				return array();
		}
	}

	/**
	 * Enrich member result rows: name, initials, bio, following-state in batch.
	 *
	 * @param int[] $user_ids  Member user IDs.
	 * @param int   $viewer_id Viewing user ID.
	 * @return array[] Each row: id, name, initials, bio, profile_url, is_following, is_self.
	 */
	private function enrich_members( array $user_ids, int $viewer_id ): array {
		$following = ( $viewer_id > 0 )
			? buddynext_service( 'follows' )->following_map( $viewer_id, $user_ids )
			: array();

		// Prime the user + usermeta caches in two batched queries so the loop's
		// per-row get_userdata() / get_user_meta() reads hit cache (was an N+1).
		if ( ! empty( $user_ids ) ) {
			cache_users( $user_ids );
			update_meta_cache( 'user', $user_ids );
		}

		$rows = array();
		foreach ( $user_ids as $uid ) {
			$user = get_userdata( $uid );
			$name = $user ? $user->display_name : __( 'Unknown', 'buddynext' );

			$rows[] = array(
				'id'           => $uid,
				'name'         => $name,
				'initials'     => \BuddyNext\Profile\AvatarService::initials_for( $name ),
				// Read never written: see ProfileService::bios_for(). Members were never indexed by
				// their bio, so nobody could be found by what they wrote about themselves.
				'bio'          => buddynext_service( 'profiles' )->bio_for( $uid ),
				'profile_url'  => (string) \BuddyNext\Core\PageRouter::profile_url( $uid ),
				'is_self'      => ( $uid === $viewer_id ),
				'is_following' => ! empty( $following[ $uid ] ),
			);
		}

		return $rows;
	}

	/**
	 * Enrich space result rows: name, description, member count, membership in batch.
	 *
	 * @param int[] $space_ids Space IDs.
	 * @param int   $viewer_id Viewing user ID.
	 * @return array[] Each row: id, name, initials, description, member_count, space_url, is_member.
	 */
	private function enrich_spaces( array $space_ids, int $viewer_id ): array {
		$spaces     = buddynext_service( 'spaces' );
		$membership = ( $viewer_id > 0 )
			? buddynext_service( 'space_members' )->membership_map( $viewer_id, $space_ids )
			: array();

		$rows = array();
		foreach ( $space_ids as $sid ) {
			$space = $spaces->get( $sid );
			if ( null === $space ) {
				continue;
			}
			$name = (string) ( $space['name'] ?? '' );

			$rows[] = array(
				'id'           => $sid,
				'name'         => $name,
				'initials'     => \BuddyNext\Profile\AvatarService::initials_for( $name ),
				'description'  => (string) ( $space['description'] ?? '' ),
				'member_count' => (int) ( $space['member_count'] ?? 0 ),
				'space_url'    => (string) \BuddyNext\Core\PageRouter::space_url( $sid ),
				'is_member'    => isset( $membership[ $sid ] ),
			);
		}

		return $rows;
	}

	/**
	 * Enrich post (and media) result rows: author, age, stats, snippet source.
	 *
	 * Posts hydrate through PostService::hydrate() so the section part renders the
	 * canonical post shape rather than a hand-built row. The original search
	 * `content` is preserved as `snippet_source` so the highlight helper can still
	 * mark the matched terms.
	 *
	 * @param array[] $items search() items (object_id / content / author_id).
	 * @return array[] Each row: id, author_id, author_name, author_initials, age, reactions, comments, shares, snippet_source.
	 */
	private function enrich_posts( array $items ): array {
		$posts = buddynext_service( 'post_service' );

		$rows = array();
		foreach ( $items as $item ) {
			$post_id = (int) ( $item['object_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$post = $posts->get( $post_id );

			$author_id   = $post ? (int) $post['user_id'] : (int) ( $item['author_id'] ?? 0 );
			$author_user = $author_id ? get_userdata( $author_id ) : null;
			$author_name = $author_user ? $author_user->display_name : __( 'Unknown', 'buddynext' );

			$rows[] = array(
				'id'              => $post_id,
				'author_id'       => $author_id,
				'author_name'     => $author_name,
				'author_initials' => \BuddyNext\Profile\AvatarService::initials_for( $author_name ),
				'age'             => $post ? buddynext_time_ago( (string) ( $post['created_at'] ?? '' ) ) : '',
				'reactions'       => $post ? (int) $post['reaction_count'] : 0,
				'comments'        => $post ? (int) $post['comment_count'] : 0,
				'shares'          => $post ? (int) $post['share_count'] : 0,
				'snippet_source'  => (string) ( $item['content'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * The server's InnoDB FULLTEXT minimum token size (default 3). A query shorter
	 * than this can never match in BOOLEAN MODE, so search() routes short queries to
	 * the LIKE path instead. Cached per-request.
	 *
	 * @return int Minimum token length (at least 1).
	 */
	private function fulltext_min_token(): int {
		static $min = null;
		if ( null !== $min ) {
			return $min;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( 'SELECT @@innodb_ft_min_token_size' );
		$min   = ( null !== $value ) ? max( 1, (int) $value ) : 3;
		return $min;
	}

	/**
	 * Check whether the FULLTEXT index exists on the search index table.
	 *
	 * Returns false in test environments where the table is a TEMPORARY table
	 * (which does not support FULLTEXT). The result is not cached because the
	 * index may be added after initial activation.
	 *
	 * @return bool True when the ft_search FULLTEXT index is present.
	 */
	private function has_fulltext_index(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_search_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				'ft_search'
			)
		);
		return (bool) $result;
	}

	/**
	 * Health snapshot of the search index for the admin Tools screen: how many
	 * rows are indexed (total + per object type), whether the FULLTEXT index is in
	 * place, and when the index was last fully rebuilt. Lets the owner diagnose the
	 * "search returns nothing" case (empty/stale index) instead of guessing.
	 *
	 * @return array{total:int,by_type:array<string,int>,fulltext:bool,last_reindex:int}
	 */
	public function index_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_search_index';

		// $table is a trusted, code-derived identifier ($wpdb->prefix . constant); a
		// table name can't be a bound placeholder.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( "SELECT object_type, COUNT(*) AS n FROM {$table} GROUP BY object_type", ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$by_type = array();
		foreach ( (array) $rows as $row ) {
			$by_type[ (string) $row['object_type'] ] = (int) $row['n'];
		}

		return array(
			'total'        => $total,
			'by_type'      => $by_type,
			'fulltext'     => $this->has_fulltext_index(),
			'last_reindex' => (int) get_option( 'buddynext_search_last_reindex', 0 ),
		);
	}

	/**
	 * Enqueue a full re-index of all users and spaces.
	 *
	 * Called on plugin activation. Uses Action Scheduler when available,
	 * otherwise runs synchronously (development/small-site fallback).
	 *
	 * @return void
	 */
	public static function schedule_reindex_all(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_reindex_all', array(), 'buddynext' );
		} elseif ( ! wp_next_scheduled( 'buddynext_reindex_all_cron' ) ) {
			// Schedule via WP-Cron so the container is fully bootstrapped before
			// reindex_all_sync() calls buddynext_service(). Running it inline here
			// would fire before plugins_loaded and the container would be empty.
			wp_schedule_single_event( time() + 30, 'buddynext_reindex_all_cron' );
		}
	}

	/**
	 * WP-Cron callback for the one-time post-activation reindex.
	 *
	 * Registered in Plugin::init() so the container is fully bootstrapped
	 * by the time this fires (unlike Installer::run() which runs at activation).
	 *
	 * @return void
	 */
	public static function reindex_all_cron(): void {
		self::reindex_all_sync();
	}

	/**
	 * Synchronously re-index everything when Action Scheduler is absent.
	 *
	 * Delegates to the SAME comprehensive handler the async path uses
	 * (SearchIndexListener::handle_reindex_all via the buddynext_reindex_all
	 * action) so posts, users, AND spaces are indexed in batches. The previous
	 * implementation indexed only users (and capped at 500), leaving posts and
	 * spaces unsearchable on sites without Action Scheduler. The handler never
	 * re-enqueues, so firing it here runs the work inline without looping.
	 *
	 * @return void
	 */
	private static function reindex_all_sync(): void {
		do_action( 'buddynext_reindex_all' );
	}
}

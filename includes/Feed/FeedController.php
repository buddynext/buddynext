<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for feed endpoints.
 *
 * Routes (all under buddynext/v1):
 *   GET /feed/home             — home feed (auth required)
 *   GET /feed/explore          — explore feed (public)
 *   GET /users/{id}/feed       — profile feed (public)
 *   GET /spaces/{id}/feed      — space feed (public; secret spaces gated to members)
 *
 * All feeds support cursor-based pagination via ?cursor= and ?per_page= params.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use BuddyNext\REST\BaseRestController;

/**
 * Serves home, explore, and profile feeds over REST.
 */
class FeedController extends BaseRestController {

	/**
	 * Ceiling on the ids GET /feed/viewer-state will answer for in one call.
	 *
	 * Public because the app has to chunk on this number, and the only way it can
	 * is if /app/config tells it — a client that guesses high gets a silently short
	 * answer and renders those cards as un-reacted. AppConfigController emits it
	 * from here rather than restating the literal, so the two cannot drift.
	 *
	 * @var int
	 */
	public const VIEWER_STATE_MAX_IDS = 100;

	/**
	 * Leading reactors carried on each feed item for the face-stack.
	 *
	 * Public for the same reason VIEWER_STATE_MAX_IDS is: the client renders
	 * "Amina, Marcus +12" by subtracting the faces it received from reaction_count,
	 * so it has to know how many faces the payload promises. AppConfigController
	 * emits it rather than restating the literal, so the two cannot drift.
	 *
	 * @var int
	 */
	public const FACE_STACK_SIZE = 3;

	/**
	 * The pagination contract every feed surface shares.
	 *
	 * Declared, not just read. Each feed route used to read cursor and per_page in
	 * its handler while declaring no args at all, which has three costs: the params
	 * are invisible in openapi.json (generated from the route registry, so an app
	 * team reading route truth concludes the feeds are unpaginated), nothing
	 * sanitises or validates them, and per_page reached FeedService unbounded below
	 * — min($per_page, 50) has no floor, so a negative arrived at the SQL as a
	 * negative LIMIT.
	 *
	 * validate_callback is set EXPLICITLY and must stay that way. Core only runs
	 * validation when the arg carries one — WP_REST_Request::has_valid_params() gates
	 * on `! empty( $arg['validate_callback'] )`, and register_rest_route() defaults it
	 * only for schema-derived args, never for a hand-written block like this. Without
	 * it, minimum/maximum/enum are documentation: they appear in the spec, they read
	 * as enforcement, and nothing checks them.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function feed_pagination_args(): array {
		return array(
			'cursor'   => array(
				'type'              => 'string',
				'required'          => false,
				'description'       => 'Opaque keyset cursor from a previous response.',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 50,
				'description'       => 'Items per page (1-50).',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/feed/home',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'home_feed' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => $this->feed_pagination_args() + array(
					'filter' => array(
						'type'              => 'string',
						'default'           => 'for-you',
						'enum'              => FeedService::HOME_FILTERS,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'feed_counts' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/viewer-state',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'viewer_state' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'post_ids' => array(
						'required'    => true,
						'description' => 'Comma-separated post IDs to fetch the viewer\'s reaction/bookmark/vote state for.',
						'type'        => 'string',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/new-count',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'feed_new_count' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'after_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'filter'   => array(
						'type'              => 'string',
						'default'           => 'for-you',
						'enum'              => FeedService::HOME_FILTERS,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/explore',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explore_feed' ),
				'permission_callback' => array( $this, 'require_public_explore' ),
				'args'                => $this->feed_pagination_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/users/(?P<id>[\d]+)/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'profile_feed' ),
				'permission_callback' => '__return_true',
				'args'                => $this->feed_pagination_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>[\d]+)/feed',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_space_feed' ),
				'permission_callback' => '__return_true',
				'args'                => $this->feed_pagination_args(),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/home/page',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'home_feed_page' ),
				'permission_callback' => array( $this, 'require_auth' ),
				'args'                => array(
					'filter' => array(
						'type'              => 'string',
						'default'           => 'for-you',
						'enum'              => FeedService::HOME_FILTERS,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/announcements',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_announcements' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/explore/page',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'explore_feed_page' ),
				'permission_callback' => array( $this, 'require_public_explore' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/announcements/(?P<id>[\d]+)/dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dismiss_announcement' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);

		register_rest_route(
			'buddynext/v1',
			'/feed/announcements/(?P<id>[\d]+)/end',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'end_announcement' ),
				'permission_callback' => array( $this, 'require_auth' ),
			)
		);
	}

	/**
	 * Permission gate for the explore feed.
	 *
	 * Logged-in members can always read explore. Guests can read it only when
	 * the site owner has left "Public explore feed" on
	 * (buddynext_public_explore, default true). When that option is off, the
	 * explore feed becomes a members-only surface and guests get a 401 — the
	 * REST mirror of the PageRouter redirect that gates the /explore/ page.
	 *
	 * @return true|WP_Error
	 */
	public function require_public_explore(): bool|WP_Error {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( (bool) get_option( 'buddynext_public_explore', true ) ) {
			return true;
		}

		return new WP_Error(
			'rest_explore_members_only',
			__( 'The explore feed is available to members only.', 'buddynext' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Return the authenticated user's home feed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function home_feed( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;
		$filter   = (string) ( $request->get_param( 'filter' ) ?? 'for-you' );
		if ( ! in_array( $filter, FeedService::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$result = $this->feed_service()->home_feed( $user_id, $cursor, $per_page, $filter );

		return $this->enriched_response( $result, $user_id );
	}

	/**
	 * Batch-prime the viewer-relative maps for a page of posts.
	 *
	 * One place, so the feed and the refresh route cannot answer differently. Every
	 * map is a single bounded query keyed on the ids of THIS page.
	 *
	 * @param int   $viewer   Current user ID (0 when logged out).
	 * @param int[] $post_ids Posts on this page.
	 * @param int[] $poll_ids The subset that are polls.
	 * @return array{reactions:array,votes:array,bookmarks:array,shares:array}
	 */
	private function prime_viewer_maps( int $viewer, array $post_ids, array $poll_ids = array() ): array {
		if ( $viewer <= 0 || empty( $post_ids ) ) {
			return array(
				'reactions' => array(),
				'votes'     => array(),
				'bookmarks' => array(),
				'shares'    => array(),
			);
		}

		return array(
			'reactions' => buddynext_service( 'reactions' )->get_user_emoji_map( $viewer, 'post', $post_ids ),
			'votes'     => buddynext_service( 'polls' )->user_votes_map( $viewer, $poll_ids ),
			// Only the posts ON THIS PAGE — bounded, PK-indexed, no filesort. Loading the
			// viewer's entire bookmark/share history to render twenty posts is what these
			// replace.
			'bookmarks' => buddynext_service( 'bookmarks' )->bookmarked_among( $viewer, $post_ids ),
			'shares'    => buddynext_service( 'shares' )->shared_among( $viewer, $post_ids ),
		);
	}

	/**
	 * The viewer-relative block for one post.
	 *
	 * Built here for both the feed and GET /feed/viewer-state, because the two used
	 * to construct it independently and had already drifted: the refresh route never
	 * carried my_share, so an app that re-polled state lost the un-share affordance
	 * on every card it refreshed.
	 *
	 * Everything here is VOLATILE — it can change without the post changing, which is
	 * what makes it worth re-polling. can_edit is deliberately NOT here: authorship
	 * does not change between two polls of the same post, so it belongs with the
	 * initial shape (see enrich_items_for_rest) and re-sending it on every refresh
	 * would be waste.
	 *
	 * @param int   $post_id Post.
	 * @param array $maps    Output of prime_viewer_maps().
	 * @return array<string,mixed>
	 */
	private function viewer_state_for( int $post_id, array $maps ): array {
		return array(
			'my_reaction'        => $maps['reactions'][ $post_id ] ?? null,
			'is_bookmarked'      => isset( $maps['bookmarks'][ $post_id ] ),
			'my_voted_option_id' => isset( $maps['votes'][ $post_id ] ) ? (int) $maps['votes'][ $post_id ] : 0,
			'my_share'           => isset( $maps['shares'][ $post_id ] ),
		);
	}

	/**
	 * Enrich a feed result and respond.
	 *
	 * Every feed surface returns through here, deliberately. Enrichment used to be
	 * an inline step in home_feed() only, so explore, profile and space feeds each
	 * shipped raw hydrate() rows — three of the four surfaces a native client
	 * actually reads. Nothing said so; each handler simply ended with
	 * `return new WP_REST_Response( $result, 200 )` and looked complete.
	 *
	 * Routing every handler through one door makes the omission structurally
	 * impossible rather than a thing to remember: a handler cannot return a feed
	 * without enriching it, because returning a feed IS this method.
	 *
	 * @param array $result Feed result from FeedService ({items, next_cursor, …}).
	 * @param int   $viewer Current user ID (0 when logged out).
	 * @return WP_REST_Response
	 */
	private function enriched_response( array $result, int $viewer ): WP_REST_Response {
		if ( ! empty( $result['items'] ) && is_array( $result['items'] ) ) {
			$result['items'] = $this->enrich_items_for_rest( (array) $result['items'], $viewer );
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Public entry point so the single-post read and the bookmark `expand=posts` read share
	 * the feed's EXACT enrichment (author, content_html, viewer_state, media). One builder,
	 * so a post opened cold via deeplink looks identical to the same card in the feed
	 * (#10104866988 - three post-returning surfaces had drifted to raw rows).
	 *
	 * @param array<int,array<string,mixed>> $items  Hydrated post arrays.
	 * @param int                            $viewer Current user ID.
	 * @return array<int,array<string,mixed>> Enriched items.
	 */
	public function enrich_for_rest( array $items, int $viewer ): array {
		return $this->enrich_items_for_rest( $items, $viewer );
	}

	/**
	 * Enrich raw feed rows into an app-renderable shape.
	 *
	 * The JSON feed previously returned raw hydrate() rows (author as a bare
	 * user_id, no viewer state, no rendered content), so the native app could not
	 * render what the web SSR renders without N extra calls per card. This adds,
	 * per item: an author object (display_name + avatar), formatted content_html
	 * (the same buddynext_format_content() the comment REST uses), and a
	 * viewer_state block (my_reaction / is_bookmarked / my_voted_option_id /
	 * can_edit). Everything is primed ONCE for the whole page — author cache via
	 * cache_users(), reactions + poll votes via single batch maps, bookmarks via
	 * the already-cached user_bookmarks() — so enrichment adds a constant handful
	 * of queries, not four per card.
	 *
	 * Reached from every feed surface via enriched_response(). It was written for
	 * home_feed() and, for a while, only home_feed() called it — so explore,
	 * profile and space feeds went on returning exactly the raw rows this
	 * describes replacing. Call it through enriched_response(), never inline, or
	 * the next surface repeats that.
	 *
	 * @param array $items         Raw feed rows from a FeedService feed method.
	 * @param int   $viewer        Current user ID (0 when logged out).
	 * @param bool  $embed_shared  Embed each re-share's original post; false when enriching
	 *                             those originals so a share of a share does not recurse.
	 * @return array Enriched items.
	 */
	private function enrich_items_for_rest( array $items, int $viewer, bool $embed_shared = true ): array {
		if ( empty( $items ) ) {
			return $items;
		}

		$post_ids   = array();
		$author_ids = array();
		$poll_ids   = array();
		$media_ids  = array();
		$owner_map  = array();
		foreach ( $items as $item ) {
			$pid = absint( $item['id'] ?? 0 );
			$aid = absint( $item['user_id'] ?? 0 );
			if ( $pid ) {
				$post_ids[] = $pid;
			}
			if ( $aid ) {
				$author_ids[] = $aid;
			}
			if ( $pid && $aid ) {
				// The reactor gate needs each post's owner; the page already knows them,
				// so handing them over keeps top_reactors_map() off a per-post lookup.
				$owner_map[ $pid ] = $aid;
			}
			if ( $pid && 'poll' === ( $item['type'] ?? '' ) ) {
				$poll_ids[] = $pid;
			}
			foreach ( self::normalize_media_ids( $item['media_ids'] ?? null ) as $mid ) {
				$media_ids[] = $mid;
			}
		}

		// Prime once for the whole page (no per-card lookups below).
		if ( ! empty( $author_ids ) ) {
			cache_users( array_values( array_unique( $author_ids ) ) );
		}
		// Warm attachment post + meta caches for every image on the page in one pass, so
		// the per-item media resolve below is O(1) — no N+1 at 20 photo cards.
		if ( ! empty( $media_ids ) ) {
			_prime_post_caches( array_values( array_unique( $media_ids ) ), false, true );
		}

		$maps = $this->prime_viewer_maps( $viewer, $post_ids, $poll_ids );

		// Community signals, primed for the page like everything above it. Presence is
		// one read for every author; the face-stacks are one bounded read for every
		// post. Resolved per card instead, these are the two cheapest ways to turn a
		// twenty-post feed into a sixty-query one.
		$presence = ! empty( $author_ids )
			? \BuddyNext\Realtime\PresenceService::last_active_map( $author_ids )
			: array();
		$blocks   = buddynext_service( 'blocks' );
		if ( ! empty( $author_ids ) ) {
			// is_user_online_at() consults the restrict and block gates per author, and
			// both are per-pair caches — cold, that is two queries for every distinct
			// author on the page. These two primers already exist for exactly this shape
			// (the directory and DM rails use them), so the presence dot reuses them
			// rather than growing a third way to ask the same question.
			$blocks->prime_restricted_cache( $viewer, $author_ids );
			$blocks->blocking_either_map( $viewer, $author_ids );
		}
		$reactors = ! empty( $post_ids )
			? buddynext_service( 'reactions' )->top_reactors_map( 'post', $post_ids, $owner_map, self::FACE_STACK_SIZE, $viewer )
			: array();

		$format = function_exists( 'buddynext_format_content' );

		foreach ( $items as &$item ) {
			$pid    = absint( $item['id'] ?? 0 );
			$aid    = absint( $item['user_id'] ?? 0 );
			$author = $aid ? get_userdata( $aid ) : null;

			$item['author'] = array(
				'id'           => $aid,
				'display_name' => $author ? $author->display_name : __( 'Community Member', 'buddynext' ),
				'avatar_url'   => $aid ? get_avatar_url( $aid, array( 'size' => 96 ) ) : '',
				// From the primed map, never last_active_at() — same reason the member
				// directory reads presence out of its own query rather than per card.
				'is_online'    => $aid > 0 && $blocks->is_user_online_at( $viewer, $aid, (int) ( $presence[ $aid ] ?? 0 ) ),
			);

			// Up to FACE_STACK_SIZE leading reactors, so a client can render the stack
			// beside reaction_count without a second request per card.
			$item['top_reactors'] = $reactors[ $pid ] ?? array();

			$item['content_html'] = $format
				? buddynext_format_content( (string) ( $item['content'] ?? '' ) )
				: (string) ( $item['content'] ?? '' );

			$item['viewer_state'] = $this->viewer_state_for( $pid, $maps ) + array(
				// Stable, so it rides the initial shape only and not the refresh route.
				// Baseline: author or admin. Under-reports for space moderators
				// (safe direction — never shows an edit affordance the API rejects).
				'can_edit' => $viewer > 0 && ( $viewer === $aid || user_can( $viewer, 'manage_options' ) ),
			);

			// Resolve attachment IDs to real URLs so a client can render the image. Without
			// this a photo post carries only `media_ids` (bare ints) and the app has nothing
			// to load. `media_ids` is kept for back-compat; `media` is the render source.
			$item['media'] = self::resolve_media( $item['media_ids'] ?? null );
		}
		unset( $item );

		// Embed the ORIGINAL post inside a re-share, enriched the same way, so a client can
		// render the quoted card (author + body + media) instead of a dangling shared_post_id.
		// One level only — a share of a share does not recurse ($embed_shared = false below).
		if ( $embed_shared ) {
			$this->embed_shared_posts( $items, $viewer );
		}

		return $items;
	}

	/**
	 * Attach an enriched `shared_post` to every re-share item, honouring the viewer's
	 * visibility gates (a private / blocked / secret-space original is embedded as null so a
	 * client shows "post unavailable", never the content). Originals are enriched one level
	 * deep — a share of a share does not recurse.
	 *
	 * @param array<int,array<string,mixed>> $items  Enriched items, edited in place.
	 * @param int                            $viewer Current user ID.
	 * @return void
	 */
	private function embed_shared_posts( array &$items, int $viewer ): void {
		$shared_ids = array();
		foreach ( $items as $item ) {
			$sid = absint( $item['shared_post_id'] ?? 0 );
			if ( $sid ) {
				$shared_ids[ $sid ] = true;
			}
		}
		if ( empty( $shared_ids ) ) {
			return;
		}

		$service   = new PostService();
		$originals = array();
		foreach ( array_keys( $shared_ids ) as $sid ) {
			$orig = $service->get( (int) $sid );
			if ( ! is_array( $orig ) || empty( $orig ) ) {
				continue;
			}
			// Never embed content the viewer could not open directly.
			if ( $service->visibility_error( (int) $sid, $viewer ) instanceof WP_Error ) {
				continue;
			}
			$originals[] = $orig;
		}

		$by_id = array();
		foreach ( $this->enrich_items_for_rest( $originals, $viewer, false ) as $enriched_original ) {
			$by_id[ absint( $enriched_original['id'] ?? 0 ) ] = $enriched_original;
		}

		foreach ( $items as &$item ) {
			$sid                 = absint( $item['shared_post_id'] ?? 0 );
			$item['shared_post'] = ( $sid && isset( $by_id[ $sid ] ) ) ? $by_id[ $sid ] : null;
		}
		unset( $item );
	}

	/**
	 * Normalize a post's media_ids field to an array of positive ints.
	 *
	 * The hydrate() path usually decodes the JSON column to an array, but a raw row can still
	 * carry the JSON string (or an empty '[]'), so tolerate both shapes.
	 *
	 * @param mixed $raw The media_ids value from a post row (array | JSON string | null).
	 * @return int[] Attachment IDs, de-duplicated of zeros.
	 */
	private static function normalize_media_ids( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	/**
	 * Resolve a post's media_ids to renderable image objects.
	 *
	 * Each entry: { id, url (large), thumb_url (medium), width, height, mime }. Non-image
	 * attachments (a stray upload) are skipped rather than returned as an unrenderable box.
	 * Attachment caches are primed by the caller, so this is O(1) per id.
	 *
	 * @param mixed $raw The post's media_ids value.
	 * @return array<int,array<string,mixed>> Ordered media objects; empty for a text post.
	 */
	private static function resolve_media( $raw ): array {
		$media = array();
		foreach ( self::normalize_media_ids( $raw ) as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$mime = (string) get_post_mime_type( $id );
			if ( 0 !== strpos( $mime, 'image/' ) ) {
				continue;
			}
			$full  = wp_get_attachment_image_src( $id, 'large' );
			$thumb = wp_get_attachment_image_src( $id, 'medium' );
			if ( ! $full ) {
				continue;
			}
			$media[] = array(
				'id'        => $id,
				'url'       => $full[0],
				'thumb_url' => $thumb ? $thumb[0] : $full[0],
				'width'     => (int) $full[1],
				'height'    => (int) $full[2],
				'mime'      => $mime,
				'alt'       => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			);
		}
		return $media;
	}

	/**
	 * Return per-tab counts for the home feed filter strip.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function feed_counts( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_id = get_current_user_id();
		$counts  = $this->feed_service()->home_feed_counts( $user_id );

		return new WP_REST_Response( $counts, 200 );
	}

	/**
	 * GET /feed/announcements — the active announcements the viewer should see.
	 *
	 * The site-wide announcement plus one per space the viewer belongs to, each
	 * respecting the viewer's dismissals + expiry. Lets the native app fetch/badge/
	 * push announcements instead of scraping the /feed/home page-1 prepend.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_announcements( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$viewer = get_current_user_id();
		$feed   = $this->feed_service();
		$items  = array();

		$site = $feed->active_announcement( $viewer );
		if ( is_array( $site ) ) {
			$items[] = $this->shape_announcement( $site );
		}

		if ( $viewer > 0 ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT space_id FROM {$wpdb->prefix}bn_space_members WHERE user_id = %d AND status = 'active'",
					$viewer
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $space_ids as $sid ) {
				$ann = $feed->space_announcement( (int) $sid, $viewer );
				if ( is_array( $ann ) ) {
					$items[] = $this->shape_announcement( $ann );
				}
			}
		}

		return new WP_REST_Response( array( 'announcements' => $items ), 200 );
	}

	/**
	 * Shape an announcement post for the REST payload.
	 *
	 * @param array $post Hydrated announcement post row.
	 * @return array
	 */
	private function shape_announcement( array $post ): array {
		$author_id = (int) ( $post['user_id'] ?? 0 );
		$author    = $author_id ? get_userdata( $author_id ) : null;
		$content   = (string) ( $post['content'] ?? '' );

		return array(
			'id'           => (int) ( $post['id'] ?? 0 ),
			'space_id'     => (int) ( $post['space_id'] ?? 0 ),
			'content'      => $content,
			'content_html' => function_exists( 'buddynext_format_content' ) ? buddynext_format_content( $content ) : $content,
			'created_at'   => (string) ( $post['created_at'] ?? '' ),
			'expires_at'   => $post['site_pin_expires_at'] ?? null,
			'author'       => array(
				'id'           => $author_id,
				'display_name' => $author ? $author->display_name : '',
				'avatar_url'   => $author_id ? get_avatar_url( $author_id, array( 'size' => 96 ) ) : '',
			),
		);
	}

	/**
	 * Batch viewer-state for a set of posts.
	 *
	 * Lets the app refresh reaction / bookmark / vote state for posts it already
	 * holds (e.g. after a scroll or a cross-feed merge) in ONE request instead of
	 * one call per post. Uses the same batch primitives as the feed enrichment, so
	 * it stays O(1) in queries regardless of how many ids are passed.
	 *
	 * @param WP_REST_Request $request Incoming request (post_ids: CSV of IDs).
	 * @return WP_REST_Response Map of post_id => { my_reaction, is_bookmarked, my_voted_option_id }.
	 */
	public function viewer_state( WP_REST_Request $request ): WP_REST_Response {
		$viewer   = get_current_user_id();
		$raw      = (string) $request->get_param( 'post_ids' );
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );

		// Bound the batch — but say so. This used to slice in silence, which is the
		// worst shape for the caller: a client that sent 150 ids got 100 answers and
		// no indication, so fifty cards rendered as un-reacted and un-bookmarked and
		// looked like real state. A wrong answer that admits nothing is worse than an
		// error. Report the ceiling and whether it bit, so the app can chunk instead
		// of quietly mis-rendering.
		$requested = count( $post_ids );
		$post_ids  = array_slice( $post_ids, 0, self::VIEWER_STATE_MAX_IDS );
		$truncated = $requested > count( $post_ids );

		if ( empty( $post_ids ) ) {
			return new WP_REST_Response(
				array(
					'states'    => (object) array(),
					'requested' => $requested,
					'returned'  => 0,
					'truncated' => false,
					'max_ids'   => self::VIEWER_STATE_MAX_IDS,
				),
				200
			);
		}

		// Poll votes are keyed by post id here too: the caller holds a mixed page and
		// does not tell us which of them are polls.
		$maps = $this->prime_viewer_maps( $viewer, $post_ids, $post_ids );

		$states = array();
		foreach ( $post_ids as $pid ) {
			$states[ $pid ] = $this->viewer_state_for( $pid, $maps );
		}

		return new WP_REST_Response(
			array(
				'states'    => $states,
				'requested' => $requested,
				'returned'  => count( $states ),
				'truncated' => $truncated,
				'max_ids'   => self::VIEWER_STATE_MAX_IDS,
			),
			200
		);
	}

	/**
	 * Return the number of home-feed posts newer than the client's top-of-feed id.
	 *
	 * Drives the Free "N new posts" pill on /activity: the client passes the id
	 * of the post currently at the top of its rendered feed (`after_id`) plus the
	 * active filter, and receives a lightweight count + the newest visible id so
	 * the poll can advance its watermark. Authors' own posts are excluded so a
	 * member's own composer submission never inflates the pill.
	 *
	 * Response shape: { count: int, newest_id: int }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function feed_new_count( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$after_id = (int) $request->get_param( 'after_id' );
		$filter   = (string) ( $request->get_param( 'filter' ) ?? 'for-you' );
		if ( ! in_array( $filter, FeedService::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$result = $this->feed_service()->home_feed_new_count( $user_id, $after_id, $filter );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Return the public explore feed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function explore_feed( WP_REST_Request $request ): WP_REST_Response {
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;

		$result = $this->feed_service()->explore_feed( $cursor, $per_page );

		return $this->enriched_response( $result, get_current_user_id() );
	}

	/**
	 * Return a user's profile feed.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function profile_feed( WP_REST_Request $request ): WP_REST_Response {
		$profile_user_id = (int) $request->get_param( 'id' );
		$viewer_id       = get_current_user_id();
		$cursor          = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page        = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;

		$result = $this->feed_service()->profile_feed( $profile_user_id, $viewer_id, $cursor, $per_page );

		return $this->enriched_response( $result, $viewer_id );
	}

	/**
	 * Return the feed for a given space.
	 *
	 * Enforces secret-space membership before returning posts: secret spaces
	 * are only readable by active members and site admins. Open and private
	 * spaces are listed publicly (private spaces show metadata; member-only
	 * content is gated by the post-privacy layer in PostController::get_post).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_space_feed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$space_id  = (int) $request->get_param( 'id' );
		$viewer_id = get_current_user_id();
		$cursor    = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page  = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;

		$space = ( new SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return new WP_Error(
				'space_not_found',
				__( 'Space not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Only OPEN spaces are publicly readable. Private AND secret both require
		// membership to read posts — previously only 'secret' was gated, so a
		// private (membership-restricted) space leaked its posts to non-members /
		// guests. Secret stays fully hidden (404); private exists and is
		// discoverable, but its posts are members-only (403).
		$space_type = (string) ( $space['type'] ?? '' );
		if ( 'open' !== $space_type ) {
			$is_member = $viewer_id > 0 && ( new SpaceMemberService() )->is_member( $space_id, $viewer_id );
			if ( ! $is_member && ! user_can( $viewer_id, 'manage_options' ) ) {
				if ( 'secret' === $space_type ) {
					return new WP_Error(
						'space_not_found',
						__( 'Space not found.', 'buddynext' ),
						array( 'status' => 404 )
					);
				}
				return new WP_Error(
					'space_members_only',
					__( 'This space is members only. Join to view its posts.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		$result = $this->feed_service()->space_feed( $space_id, $viewer_id, $cursor, $per_page );

		return $this->enriched_response( $result, $viewer_id );
	}

	/**
	 * Dismiss a site-wide announcement for the current user.
	 *
	 * Persists the dismissal as a user_meta entry so the announcement no longer
	 * appears in the user's home feed. Idempotent — safe to call multiple times.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_announcement( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Announcements feature gate — when off, no announcement is surfaced, so a
		// dismiss request can only be stale; treat it as not found.
		if ( ! buddynext_feature_enabled( 'announcements' ) ) {
			return new WP_Error(
				'feature_disabled',
				__( 'Announcements are disabled.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$post_id = (int) $request->get_param( 'id' );
		$user_id = get_current_user_id();

		if ( null === buddynext_service( 'post_service' )->get_announcement( $post_id ) ) {
			return new WP_Error(
				'not_found',
				__( 'Announcement not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		FeedService::dismiss_announcement( $user_id, $post_id );

		// Bust this user's page-1 home feed so the dismissed announcement is gone
		// on the next load, not after the 30s TTL.
		$this->feed_service()->flush_home_cache( $user_id );

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * End a site-wide announcement (admin only) by expiring its pin now, so
	 * active_announcement() stops surfacing it for everyone.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function end_announcement( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do this.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		$post_id = (int) $request->get_param( 'id' );

		if ( ! buddynext_service( 'post_service' )->end_announcement( $post_id ) ) {
			return new WP_Error(
				'not_found',
				__( 'Announcement not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		// Ending an announcement affects everyone — bust all page-1 home feeds so it
		// disappears immediately rather than after the 30s TTL.
		$this->feed_service()->flush_all_home_caches();

		return new WP_REST_Response( array( 'ended' => true ), 200 );
	}

	/**
	 * Return the home feed as pre-rendered HTML for infinite-scroll appending.
	 *
	 * Calls home_feed() then renders each item with the canonical
	 * `partials/post-card.php` partial so the appended cards are byte-identical
	 * to first-paint cards. The client only needs to inject the returned HTML
	 * and update the cursor — no card markup is duplicated in JS.
	 *
	 * Response shape:
	 *   { html: string, next_cursor: string|null, count: int }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function home_feed_page( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 15;
		$filter   = (string) ( $request->get_param( 'filter' ) ?? 'for-you' );
		if ( ! in_array( $filter, FeedService::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$result = $this->feed_service()->home_feed( $user_id, $cursor, $per_page, $filter );
		$html   = $this->render_items_html( (array) ( $result['items'] ?? array() ), $user_id, 'home' );

		return new WP_REST_Response(
			array(
				'html'        => $html,
				'next_cursor' => $result['next_cursor'] ?? null,
				'count'       => count( (array) ( $result['items'] ?? array() ) ),
			),
			200
		);
	}

	/**
	 * Return the explore feed as pre-rendered HTML for infinite-scroll appending.
	 *
	 * Public endpoint — visible to guests. Same response shape as
	 * {@see self::home_feed_page()}.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function explore_feed_page( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$cursor   = $request->get_param( 'cursor' ) ? (string) $request->get_param( 'cursor' ) : null;
		$per_page = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 12;
		$filter   = $request->get_param( 'filter' ) ? sanitize_key( (string) $request->get_param( 'filter' ) ) : 'all';
		if ( ! in_array( $filter, ExploreService::FILTERS, true ) ) {
			$filter = 'all';
		}

		$result = ( new ExploreService( $this->feed_service() ) )->deck( $filter, $cursor, $per_page );
		$cards  = (array) ( $result['items'] ?? array() );
		$html   = $this->render_explore_cards_html( $cards, $user_id );

		return new WP_REST_Response(
			array(
				'html'        => $html,
				'next_cursor' => $result['next_cursor'] ?? null,
				'count'       => count( $cards ),
			),
			200
		);
	}

	/**
	 * Render a list of Explore discovery cards to a single HTML string.
	 *
	 * Mirrors the SSR loop in templates/feed/explore.php so appended pages match
	 * the first paint byte-for-byte: each normalized card payload is delegated to
	 * partials/explore-card.php inside an output buffer.
	 *
	 * @param array<int,array<string,mixed>> $cards  Normalized card payloads from ExploreService.
	 * @param int                            $viewer Viewing user ID (0 for guests).
	 * @return string HTML markup ready to inject into the explore grid container.
	 */
	private function render_explore_cards_html( array $cards, int $viewer ): string {
		if ( empty( $cards ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		ob_start();
		foreach ( $cards as $card ) {
			buddynext_get_template(
				'partials/explore-card.php',
				array(
					'card'            => $card,
					'current_user_id' => $viewer,
				)
			);
		}
		return (string) ob_get_clean();
	}

	/**
	 * Render a list of hydrated post records to a single HTML string.
	 *
	 * Loops through items, hydrates poll options for poll posts (the feed query
	 * doesn't join them), and delegates each card to `partials/post-card.php`
	 * inside an output buffer.
	 *
	 * @param array<int,array<string,mixed>> $items   Post records (hydrated by FeedService).
	 * @param int                            $viewer  Viewing user ID (0 for guests).
	 * @param string                         $context Feed context ('home' or 'explore').
	 * @return string HTML markup ready to inject into the feed list container.
	 */
	/**
	 * Render a single feed post card to its canonical server-side HTML.
	 *
	 * Reuses the exact `partials/post-card.php` pipeline the feed and
	 * infinite-scroll use, so a freshly-created post can be prepended into the
	 * feed in place (no reload) and hydrate identically. Returns '' when the
	 * template helper is unavailable.
	 *
	 * @param array  $post    Hydrated post array (PostService::hydrate shape).
	 * @param int    $viewer  Current user ID.
	 * @param string $context Render context (default 'home').
	 * @return string Escape-on-output card markup, or '' if unavailable.
	 */
	public static function render_card_html( array $post, int $viewer, string $context = 'home' ): string {
		if ( empty( $post ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		ob_start();
		buddynext_get_template(
			'partials/post-card.php',
			array(
				'post'            => PostService::attach_poll_options( $post ),
				'current_user_id' => $viewer,
				'context'         => $context,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render a list of feed post cards to their canonical server-side HTML.
	 *
	 * Loops the hydrated items through the same `partials/post-card.php`
	 * pipeline as a single card, so infinite-scroll pages hydrate identically
	 * to the initial render. Returns '' when there is nothing to render or the
	 * template helper is unavailable.
	 *
	 * @param array<int,array<string,mixed>> $items   Hydrated post arrays (PostService::hydrate shape).
	 * @param int                            $viewer  Current user ID.
	 * @param string                         $context Render context (e.g. 'home').
	 * @return string Escape-on-output card markup, or '' if unavailable.
	 */
	private function render_items_html( array $items, int $viewer, string $context ): string {
		if ( empty( $items ) || ! function_exists( 'buddynext_get_template' ) ) {
			return '';
		}

		$this->feed_service()->prime_viewer_state( $items, $viewer );

		ob_start();
		foreach ( $items as $item ) {
			$post = PostService::attach_poll_options( $item );

			buddynext_get_template(
				'partials/post-card.php',
				array(
					'post'            => $post,
					'current_user_id' => $viewer,
					'context'         => $context,
				)
			);
		}
		return (string) ob_get_clean();
	}

	/**
	 * Permission callback: require an authenticated user.
	 *
	 * @return true|WP_Error
	 */

	/**
	 * Resolve the active feed service.
	 *
	 * Prefers the container-bound `feed` service so that any rebind — notably
	 * the Pro `AiRankedFeedService`, which fires the `buddynext_feed_query_args`
	 * / `buddynext_feed_order_by` ranking hooks — is honoured on every REST
	 * pagination and filter-switch request. Without this, infinite scroll would
	 * revert to chronological order even when the first SSR paint was AI-ranked.
	 *
	 * Falls back to a directly-constructed FeedService when the container is
	 * unavailable (e.g. the front-end isolation harness strips the bootstrap),
	 * so the endpoints never fatal — they simply serve the chronological feed.
	 *
	 * @return FeedService
	 */
	private function feed_service(): FeedService {
		if ( function_exists( 'buddynext_service' ) ) {
			$service = buddynext_service( 'feed' );
			if ( $service instanceof FeedService ) {
				return $service;
			}
		}

		return new FeedService( new FollowService(), new PostService() );
	}
}

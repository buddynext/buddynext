<?php
/**
 * Central registry of REST response item schemas (BUILD-TIME source of truth).
 *
 * One static method per RESOURCE (not per route), each returning a WordPress
 * item schema (JSON Schema draft-04, the shape `get_item_schema()` returns), plus
 * a `map()` that says which route+method returns which resource and in what shape
 * (single item / bare array / paginated envelope).
 *
 * **Read at BUILD TIME only.** `bin/gen-openapi.php` reads these methods + `map()`
 * to emit OpenAPI `components.schemas` and `$ref` each 200 response body; nothing
 * here is wired onto `register_rest_route`. That is deliberate: BuddyNext runs on
 * 400+ live sites, and wrapping ~280 runtime route registrations to attach schema
 * callbacks is churn/risk in the shipped REST layer for a benefit (OPTIONS-
 * queryable schema) almost no consumer uses. The spec is a build artifact, so the
 * registry gives the full typed spec with ZERO runtime footprint. (If a route ever
 * needs a runtime-queryable schema, it can add one incrementally then.)
 *
 * Each schema is authored from the LIVE response shape (introspected with
 * `rest_do_request` as admin on a seeded site) so it matches what the API
 * actually returns, not an invented ideal. Every method sets `title` to the
 * resource name — the generator keys `components.schemas` by it. The drift gate
 * (`bin/check-openapi.php`) re-introspects each resource's live response and fails
 * the build if a field appears/disappears versus its schema here.
 *
 * @package BuddyNext\REST
 */

declare( strict_types=1 );

namespace BuddyNext\REST;

/**
 * Response item-schema registry. See file docblock for the wiring contract.
 */
final class ResponseSchema {

	/**
	 * Route → resource map the generator reads to attach a 200 body to each op.
	 *
	 * Each entry: method, namespace-relative templatized path (as gen-openapi.php
	 * emits it, e.g. `/spaces/{id}`), the resource whose schema applies, and the
	 * response shape:
	 *   - `item`      : a single resource object.
	 *   - `array`     : a bare array of the resource (a list route not yet on the
	 *                   pagination envelope — its true current shape).
	 *   - `paginated` : the `Paginated<Resource>` envelope `{items,next_cursor,total}`.
	 *
	 * A route absent from the map keeps the generic 200 (no-op), so the map can
	 * grow one resource at a time. Fan-out adds entries here; no route files change.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function map(): array {
		return array(
			array( 'method' => 'GET', 'path' => '/members',     'resource' => 'member', 'shape' => 'paginated' ),
			array( 'method' => 'GET', 'path' => '/spaces',      'resource' => 'space',  'shape' => 'array' ),
			array( 'method' => 'GET', 'path' => '/spaces/{id}', 'resource' => 'space_detail', 'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/feed/home',                    'resource' => 'post',              'shape' => 'paginated' ),
			array( 'method' => 'GET', 'path' => '/posts/{id}',                   'resource' => 'post',              'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/comments',                     'resource' => 'comment',           'shape' => 'paginated' ),
			array( 'method' => 'GET', 'path' => '/reactions/list',               'resource' => 'reaction',          'shape' => 'paginated' ),
			array( 'method' => 'GET', 'path' => '/posts/{id}/poll',              'resource' => 'poll',              'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/me/drafts',                    'resource' => 'draft',             'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/users/{id}/connection/status', 'resource' => 'connection',        'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/users/{id}/follow/status',     'resource' => 'follow_edge',       'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/member-types',                 'resource' => 'member_type',       'shape' => 'array' ),
			array( 'method' => 'GET', 'path' => '/search',                       'resource' => 'search_result',     'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/users/{id}/achievements',      'resource' => 'achievement',       'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/me/notifications',             'resource' => 'notification',      'shape' => 'paginated' ),
			array( 'method' => 'GET', 'path' => '/reports/queue',                'resource' => 'moderation_report', 'shape' => 'paginated' ),
			array( 'method' => 'GET', 'path' => '/webhooks',                     'resource' => 'webhook',           'shape' => 'array' ),
			array( 'method' => 'GET', 'path' => '/app/config',                   'resource' => 'app_config',        'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/account/2fa',                  'resource' => 'twofa_status',      'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/space-categories',             'resource' => 'space_category',    'shape' => 'array' ),
			array( 'method' => 'GET', 'path' => '/members/{id}/gamification',    'resource' => 'gamification',      'shape' => 'item' ),
			array( 'method' => 'GET', 'path' => '/members/{id}/blog',            'resource' => 'member_blog',       'shape' => 'item' ),
		);
	}

	/**
	 * Shared shape for a write/action route that has no natural resource item,
	 * so every operation still declares a 200 body. e.g. POST /follow, DELETE /x.
	 *
	 * @return array<string,mixed>
	 */
	public static function action_result(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'action-result',
			'type'       => 'object',
			'properties' => array(
				'ok' => array(
					'type'        => 'boolean',
					'description' => 'Whether the action succeeded.',
				),
			),
		);
	}

	/**
	 * A member directory / profile item (GET /members, GET /members/{id}).
	 *
	 * @return array<string,mixed>
	 */
	public static function member(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'member',
			'type'       => 'object',
			'properties' => array(
				'user_id'        => array( 'type' => 'integer' ),
				'display_name'   => array( 'type' => 'string' ),
				'handle'         => array( 'type' => 'string' ),
				'headline'       => array( 'type' => 'string' ),
				'avatar_url'     => array( 'type' => 'string', 'format' => 'uri' ),
				'cover_url'      => array( 'type' => 'string', 'format' => 'uri' ),
				'profile_url'    => array( 'type' => 'string', 'format' => 'uri' ),
				'messages_url'   => array( 'type' => 'string', 'format' => 'uri' ),
				'bio_excerpt'    => array( 'type' => 'string' ),
				'is_online'      => array( 'type' => 'boolean' ),
				'follower_count' => array( 'type' => 'integer' ),
				'mutual_count'   => array( 'type' => 'integer' ),
				'member_type'    => array( 'type' => array( 'object', 'null' ) ),
				'is_self'        => array( 'type' => 'boolean' ),
				'can_interact'   => array( 'type' => 'boolean' ),
				'can_follow'     => array( 'type' => 'boolean' ),
				'can_connect'    => array( 'type' => 'boolean' ),
				'is_following'   => array( 'type' => 'boolean' ),
				'is_muted'       => array( 'type' => 'boolean' ),
				'connection'     => array( 'type' => array( 'object', 'null' ) ),
				'labels'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
			),
		);
	}

	/**
	 * Member gamification standing (GET /members/{id}/gamification) — the
	 * Achievements panel read model. Mirrors GamificationAchievements::standing_data().
	 *
	 * @return array<string,mixed>
	 */
	public static function gamification(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'gamification',
			'type'       => 'object',
			'properties' => array(
				'available'      => array( 'type' => 'boolean' ),
				'has_standing'   => array( 'type' => 'boolean' ),
				'points'         => array( 'type' => 'integer' ),
				'current_streak' => array( 'type' => 'integer' ),
				'badges'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'            => array( 'type' => 'string' ),
							'name'          => array( 'type' => 'string' ),
							'description'   => array( 'type' => 'string' ),
							'image_url'     => array( 'type' => 'string', 'format' => 'uri' ),
							'is_credential' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Member articles panel (GET /members/{id}/blog) — the Articles tab read
	 * model. Mirrors MemberBlogBridge::articles_data().
	 *
	 * @return array<string,mixed>
	 */
	public static function member_blog(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'member-blog',
			'type'       => 'object',
			'properties' => array(
				'available'     => array( 'type' => 'boolean' ),
				'enabled'       => array( 'type' => 'boolean' ),
				'is_owner'      => array( 'type' => 'boolean' ),
				'total'         => array( 'type' => 'integer' ),
				'page'          => array( 'type' => 'integer' ),
				'total_pages'   => array( 'type' => 'integer' ),
				'dashboard_url' => array( 'type' => 'string' ),
				'items'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'           => array( 'type' => 'integer' ),
							'title'        => array( 'type' => 'string' ),
							'url'          => array( 'type' => 'string', 'format' => 'uri' ),
							'date'         => array( 'type' => 'string' ),
							'date_display' => array( 'type' => 'string' ),
							'excerpt'      => array( 'type' => 'string' ),
							'cover'        => array( 'type' => array( 'string', 'null' ), 'format' => 'uri' ),
							'status'       => array( 'type' => 'string' ),
							'status_label' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * A space DETAIL object (GET /spaces/{id}) — richer than the list item:
	 * carries `fields` + `parent`, omits the list-only display extras.
	 *
	 * @return array<string,mixed>
	 */
	public static function space_detail(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'space-detail',
			'type'       => 'object',
			'properties' => array(
				'id'                => array( 'type' => 'integer' ),
				'name'              => array( 'type' => 'string' ),
				'slug'              => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'category_id'       => array( 'type' => 'integer' ),
				'parent_id'         => array( 'type' => array( 'integer', 'null' ) ),
				'type'              => array( 'type' => 'string' ),
				'owner_id'          => array( 'type' => 'integer' ),
				'member_count'      => array( 'type' => 'integer' ),
				'avatar_url'        => array( 'type' => 'string', 'format' => 'uri' ),
				'cover_image_url'   => array( 'type' => 'string', 'format' => 'uri' ),
				'rules'             => array( 'type' => 'string' ),
				'required_ability'  => array( 'type' => 'string' ),
				'is_archived'       => array( 'type' => 'boolean' ),
				'archived_at'       => array( 'type' => array( 'string', 'null' ) ),
				'created_at'        => array( 'type' => 'string' ),
				'created_at_gmt'    => array( 'type' => 'string', 'format' => 'date-time' ),
				'viewer_role'       => array( 'type' => 'string' ),
				'fields'            => array( 'type' => array( 'object', 'array' ) ),
				'parent'            => array( 'type' => array( 'object', 'null' ) ),
				'subspace_count'    => array( 'type' => 'integer' ),
				'join_method'       => array( 'type' => 'string' ),
				'membership_role'   => array( 'type' => array( 'string', 'null' ) ),
				'membership_status' => array( 'type' => array( 'string', 'null' ) ),
				'can_invite'        => array( 'type' => 'boolean' ),
				'can_manage'        => array( 'type' => 'boolean' ),
				'can_edit_space'    => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * A space LIST item (GET /spaces) — the directory summary shape: carries the
	 * resolved category/type display fields, omits the detail-only `fields`/`parent`.
	 *
	 * @return array<string,mixed>
	 */
	public static function space(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'space',
			'type'       => 'object',
			'properties' => array(
				'id'                => array( 'type' => 'integer' ),
				'name'              => array( 'type' => 'string' ),
				'slug'              => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'category_id'       => array( 'type' => 'integer' ),
				'parent_id'         => array( 'type' => array( 'integer', 'null' ) ),
				'type'              => array( 'type' => 'string' ),
				'owner_id'          => array( 'type' => 'integer' ),
				'member_count'      => array( 'type' => 'integer' ),
				'avatar_url'        => array( 'type' => 'string', 'format' => 'uri' ),
				'cover_image_url'   => array( 'type' => 'string', 'format' => 'uri' ),
				'rules'             => array( 'type' => 'string' ),
				'required_ability'  => array( 'type' => 'string' ),
				'is_archived'       => array( 'type' => 'boolean' ),
				'archived_at'       => array( 'type' => array( 'string', 'null' ) ),
				'created_at'        => array( 'type' => 'string' ),
				'created_at_gmt'    => array( 'type' => 'string', 'format' => 'date-time' ),
				'viewer_role'       => array( 'type' => 'string' ),
				'category_name'     => array( 'type' => 'string' ),
				'category_slug'     => array( 'type' => 'string' ),
				'subspace_count'    => array( 'type' => 'integer' ),
				'cover_tone'        => array( 'type' => 'string' ),
				'type_label'        => array( 'type' => 'string' ),
				'type_tone'         => array( 'type' => 'string' ),
				'join_method'       => array( 'type' => 'string' ),
				'membership_role'   => array( 'type' => array( 'string', 'null' ) ),
				'membership_status' => array( 'type' => array( 'string', 'null' ) ),
				'can_invite'        => array( 'type' => 'boolean' ),
				'can_manage'        => array( 'type' => 'boolean' ),
				'can_edit_space'    => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * A feed/post item (GET /feed/home, GET /posts/{id}, and the other feed
	 * routes). List item and single-post detail return the identical key set,
	 * so one schema serves both — there is no separate post_detail.
	 *
	 * @return array<string,mixed>
	 */
	public static function post(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'post',
			'type'       => 'object',
			'properties' => array(
				'id'                   => array( 'type' => 'integer' ),
				'user_id'              => array( 'type' => 'integer' ),
				'space_id'             => array( 'type' => array( 'integer', 'null' ) ),
				'shared_post_id'       => array( 'type' => array( 'integer', 'null' ) ),
				'type'                 => array( 'type' => 'string' ),
				'content'              => array( 'type' => 'string' ),
				'media_ids'            => array( 'type' => array( 'array', 'null' ), 'items' => array( 'type' => 'integer' ) ),
				'link_url'             => array( 'type' => array( 'string', 'null' ), 'format' => 'uri' ),
				'link_meta'            => array( 'type' => array( 'object', 'null' ) ),
				'privacy'              => array( 'type' => 'string' ),
				'reaction_count'       => array( 'type' => 'integer' ),
				'comment_count'        => array( 'type' => 'integer' ),
				'share_count'          => array( 'type' => 'integer' ),
				'is_pinned'            => array( 'type' => 'integer' ),
				'is_announcement'      => array( 'type' => 'integer' ),
				'content_warning'      => array( 'type' => 'boolean' ),
				'content_warning_type' => array( 'type' => array( 'string', 'null' ) ),
				'members_only'         => array( 'type' => 'boolean' ),
				'status'               => array( 'type' => 'string' ),
				'site_pin_expires_at'  => array( 'type' => array( 'string', 'null' ) ),
				'edited_at'            => array( 'type' => array( 'string', 'null' ) ),
				'scheduled_at'         => array( 'type' => array( 'string', 'null' ) ),
				'created_at'           => array( 'type' => 'string' ),
				'updated_at'           => array( 'type' => 'string' ),
				'author'               => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'display_name' => array( 'type' => 'string' ),
						'avatar_url'   => array( 'type' => 'string', 'format' => 'uri' ),
						'is_online'    => array( 'type' => 'boolean' ),
					),
				),
				'top_reactors'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'content_html'         => array( 'type' => 'string' ),
				'viewer_state'         => array(
					'type'       => 'object',
					'properties' => array(
						'my_reaction'        => array( 'type' => array( 'string', 'null' ) ),
						'is_bookmarked'      => array( 'type' => 'boolean' ),
						'my_voted_option_id' => array( 'type' => array( 'integer', 'null' ) ),
						'my_share'           => array( 'type' => array( 'object', 'null' ) ),
						'can_edit'           => array( 'type' => 'boolean' ),
					),
				),
				'media'                => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'created_at_gmt'       => array( 'type' => 'string', 'format' => 'date-time' ),
				'updated_at_gmt'       => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);
	}

	/**
	 * A comment item (GET /comments — requires object_type + object_id). Envelope
	 * is {items,total,replies_truncated}; `replies` nests this same shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function comment(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'comment',
			'type'       => 'object',
			'properties' => array(
				'id'                => array( 'type' => 'integer' ),
				'user_id'           => array( 'type' => 'integer' ),
				'object_type'       => array( 'type' => 'string' ),
				'object_id'         => array( 'type' => 'integer' ),
				'parent_id'         => array( 'type' => array( 'integer', 'null' ) ),
				'content'           => array( 'type' => 'string' ),
				'is_edited'         => array( 'type' => 'boolean' ),
				'is_deleted'        => array( 'type' => 'boolean' ),
				'created_at'        => array( 'type' => 'string' ),
				'updated_at'        => array( 'type' => 'string' ),
				'replies'           => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'author_name'       => array( 'type' => 'string' ),
				'author_avatar_url' => array( 'type' => 'string', 'format' => 'uri' ),
				'like_count'        => array( 'type' => 'integer' ),
				'viewer_liked'      => array( 'type' => 'boolean' ),
				'viewer_reaction'   => array( 'type' => array( 'string', 'null' ) ),
				'can_edit'          => array( 'type' => 'boolean' ),
				'can_delete'        => array( 'type' => 'boolean' ),
				'can_pin'           => array( 'type' => 'boolean' ),
				'is_pinned'         => array( 'type' => 'boolean' ),
				'author_meta_html'  => array( 'type' => 'string' ),
				'content_html'      => array( 'type' => 'string' ),
				'created_at_gmt'    => array( 'type' => 'string', 'format' => 'date-time' ),
				'updated_at_gmt'    => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);
	}

	/**
	 * A reactor entry (GET /reactions/list — requires object_type + object_id).
	 * Envelope {items,total}; each item is a member who reacted + emoji slug.
	 *
	 * @return array<string,mixed>
	 */
	public static function reaction(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'reaction',
			'type'       => 'object',
			'properties' => array(
				'user_id'        => array( 'type' => 'integer' ),
				'display_name'   => array( 'type' => 'string' ),
				'avatar_url'     => array( 'type' => 'string', 'format' => 'uri' ),
				'emoji'          => array( 'type' => 'string' ),
				'created_at'     => array( 'type' => 'string' ),
				'created_at_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);
	}

	/**
	 * A poll result set (GET /posts/{id}/poll). Sole key `results` is the ordered
	 * option list with live vote counts.
	 *
	 * @return array<string,mixed>
	 */
	public static function poll(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'poll',
			'type'       => 'object',
			'properties' => array(
				'results' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'            => array( 'type' => 'integer' ),
							'option_text'   => array( 'type' => 'string' ),
							'display_order' => array( 'type' => 'integer' ),
							'vote_count'    => array( 'type' => 'integer' ),
						),
					),
				),
			),
		);
	}

	/**
	 * The current user's composer draft (GET /me/drafts). {payload:<object>}; the
	 * payload is stored verbatim from the client composer, so it is a freeform object.
	 *
	 * @return array<string,mixed>
	 */
	public static function draft(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'draft',
			'type'       => 'object',
			'properties' => array(
				'payload' => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * A connection status object (GET /users/{id}/connection/status).
	 *
	 * @return array<string,mixed>
	 */
	public static function connection(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'connection',
			'type'       => 'object',
			'properties' => array(
				'status'     => array( 'type' => array( 'string', 'null' ) ),
				'connection' => array(
					'type'       => 'object',
					'properties' => array(
						'state'       => array( 'type' => 'string' ),
						'can_message' => array( 'type' => 'boolean' ),
					),
				),
			),
		);
	}

	/**
	 * A follow-edge status object (GET /users/{id}/follow/status).
	 *
	 * @return array<string,mixed>
	 */
	public static function follow_edge(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'follow-edge',
			'type'       => 'object',
			'properties' => array(
				'is_following' => array( 'type' => 'boolean' ),
				'is_pending'   => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * A member type (GET /member-types) — directory taxonomy term with styling.
	 *
	 * @return array<string,mixed>
	 */
	public static function member_type(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'member-type',
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'slug'        => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'color'       => array( 'type' => 'string' ),
				'text_color'  => array( 'type' => 'string' ),
				'icon_svg'    => array( 'type' => 'string' ),
				'sort_order'  => array( 'type' => 'integer' ),
				'show_in_dir' => array( 'type' => 'boolean' ),
				'self_select' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * A grouped search result container (GET /search?q=). `results.types[]` groups
	 * hits by object type; each hit is a lightweight object reference.
	 *
	 * @return array<string,mixed>
	 */
	public static function search_result(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'search-result',
			'type'       => 'object',
			'properties' => array(
				'grouped' => array( 'type' => 'boolean' ),
				'results' => array(
					'type'       => 'object',
					'properties' => array(
						'types' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'type'    => array( 'type' => 'string' ),
									'total'   => array( 'type' => 'integer' ),
									'results' => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'object_type'    => array( 'type' => 'string' ),
												'object_id'      => array( 'type' => 'integer' ),
												'title'          => array( 'type' => 'string' ),
												'content'        => array( 'type' => 'string' ),
												'author_id'      => array( 'type' => 'integer' ),
												'created_at'     => array( 'type' => 'string' ),
												'created_at_gmt' => array( 'type' => 'string' ),
												'url'            => array( 'type' => 'string', 'format' => 'uri' ),
												'subtitle'       => array( 'type' => 'string' ),
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * A member's achievements container (GET /users/{id}/achievements) — standing
	 * stats + earned badges/credentials.
	 *
	 * @return array<string,mixed>
	 */
	public static function achievement(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'achievement',
			'type'       => 'object',
			'properties' => array(
				'has_standing' => array( 'type' => 'boolean' ),
				'standing'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'icon'  => array( 'type' => 'string' ),
							'label' => array( 'type' => 'string' ),
							'value' => array( 'type' => 'string' ),
						),
					),
				),
				'badges'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'            => array( 'type' => 'string' ),
							'name'          => array( 'type' => 'string' ),
							'image_url'     => array( 'type' => 'string', 'format' => 'uri' ),
							'is_credential' => array( 'type' => 'boolean' ),
							'earned_at'     => array( 'type' => 'string' ),
							'share_url'     => array( 'type' => 'string', 'format' => 'uri' ),
						),
					),
				),
			),
		);
	}

	/**
	 * A notification list item (GET /me/notifications).
	 *
	 * @return array<string,mixed>
	 */
	public static function notification(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'notification',
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'sender_id'      => array( 'type' => 'integer' ),
				'type'           => array( 'type' => 'string' ),
				'object_type'    => array( 'type' => array( 'string', 'null' ) ),
				'object_id'      => array( 'type' => array( 'integer', 'null' ) ),
				'group_key'      => array( 'type' => 'string' ),
				'group_count'    => array( 'type' => 'integer' ),
				'group_size'     => array( 'type' => 'integer' ),
				'group_ids'      => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
				'group_actors'   => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'is_read'        => array( 'type' => 'boolean' ),
				'created_at'     => array( 'type' => 'string' ),
				'data'           => array( 'type' => 'object' ),
				'message'        => array( 'type' => 'string' ),
				'url'            => array( 'type' => 'string', 'format' => 'uri' ),
				'icon'           => array( 'type' => 'string' ),
				'tone'           => array( 'type' => 'string' ),
				'label'          => array( 'type' => 'string' ),
				'actor_name'     => array( 'type' => 'string' ),
				'created_at_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);
	}

	/**
	 * A moderation queue report item (GET /reports/queue) — grouped pending report
	 * on an object, with aggregated reason/reporter counts.
	 *
	 * @return array<string,mixed>
	 */
	public static function moderation_report(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'moderation-report',
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'reporter_id'    => array( 'type' => 'integer' ),
				'object_type'    => array( 'type' => 'string' ),
				'object_id'      => array( 'type' => 'integer' ),
				'space_id'       => array( 'type' => 'integer' ),
				'reason'         => array( 'type' => 'string' ),
				'reasons'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'report_count'   => array( 'type' => 'integer' ),
				'reporter_count' => array( 'type' => 'integer' ),
				'notes'          => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'resolved_by'    => array( 'type' => array( 'integer', 'null' ) ),
				'resolved_at'    => array( 'type' => array( 'string', 'null' ) ),
				'created_at'     => array( 'type' => 'string' ),
				'created_at_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);
	}

	/**
	 * A webhook subscription (GET /webhooks). NB: the live API returns `id` and
	 * `is_active` as numeric STRINGS ("1"), matched here rather than idealized.
	 *
	 * @return array<string,mixed>
	 */
	public static function webhook(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'webhook',
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'string' ),
				'label'          => array( 'type' => 'string' ),
				'url'            => array( 'type' => 'string', 'format' => 'uri' ),
				'has_secret'     => array( 'type' => 'boolean' ),
				'events'         => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'is_active'      => array( 'type' => 'string' ),
				'created_at'     => array( 'type' => 'string' ),
				'updated_at'     => array( 'type' => 'string' ),
				'created_at_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'updated_at_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
			),
		);
	}

	/**
	 * The mobile app bootstrap config (GET /app/config). Nested groups are opaque
	 * config maps (not resources), declared as objects.
	 *
	 * @return array<string,mixed>
	 */
	public static function app_config(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'app-config',
			'type'       => 'object',
			'properties' => array(
				'contract_version' => array( 'type' => 'integer' ),
				'app_enabled'      => array( 'type' => 'boolean' ),
				'pro_active'       => array( 'type' => 'boolean' ),
				'min_app_version'  => array( 'type' => 'string' ),
				'branding'         => array( 'type' => 'object' ),
				'features'         => array( 'type' => 'object' ),
				'integrations'     => array( 'type' => 'object' ),
				'limits'           => array( 'type' => 'object' ),
				'time'             => array( 'type' => 'object' ),
				'locale'           => array( 'type' => 'object' ),
				'legal'            => array( 'type' => 'object' ),
				'auth'             => array( 'type' => 'object' ),
				'realtime'         => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * Current user's two-factor status (GET /account/2fa).
	 *
	 * @return array<string,mixed>
	 */
	public static function twofa_status(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'twofa-status',
			'type'       => 'object',
			'properties' => array(
				'enabled'          => array( 'type' => 'boolean' ),
				'required'         => array( 'type' => 'boolean' ),
				'backup_remaining' => array( 'type' => 'integer' ),
				'methods'          => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'email_fallback'   => array( 'type' => 'boolean' ),
				'challenge'        => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * A space category (GET /space-categories) — directory taxonomy term.
	 *
	 * @return array<string,mixed>
	 */
	public static function space_category(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'space-category',
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'color'       => array( 'type' => 'string' ),
				'text_color'  => array( 'type' => 'string' ),
				'icon_svg'    => array( 'type' => 'string' ),
				'sort_order'  => array( 'type' => 'integer' ),
				'show_in_dir' => array( 'type' => 'boolean' ),
			),
		);
	}

}

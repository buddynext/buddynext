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
			array(
				'method'   => 'GET',
				'path'     => '/members',
				'resource' => 'member',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces',
				'resource' => 'space',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}',
				'resource' => 'space_detail',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/home',
				'resource' => 'post',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/posts/{id}',
				'resource' => 'post',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/comments',
				'resource' => 'comment',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/reactions/list',
				'resource' => 'reaction',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/posts/{id}/poll',
				'resource' => 'poll',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/drafts',
				'resource' => 'draft',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/connection/status',
				'resource' => 'connection',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/follow/status',
				'resource' => 'follow_edge',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/member-types',
				'resource' => 'member_type',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/search',
				'resource' => 'search_result',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/achievements',
				'resource' => 'achievement',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/notifications',
				'resource' => 'notification',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/reports/queue',
				'resource' => 'moderation_report',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/webhooks',
				'resource' => 'webhook',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/app/config',
				'resource' => 'app_config',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/account/2fa',
				'resource' => 'twofa_status',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/space-categories',
				'resource' => 'space_category',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/members/{id}/blog',
				'resource' => 'member_blog',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/admin/slug-check',
				'resource' => 'admin_slug_check',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/albums/{id}',
				'resource' => 'albums',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/appeals',
				'resource' => 'appeals',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/auth/app-password',
				'resource' => 'auth_app_password',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/auth/nonce',
				'resource' => 'auth_nonce',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/auth/register/config',
				'resource' => 'auth_register_config',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/auth/verify/status',
				'resource' => 'auth_verify_status',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/announcements',
				'resource' => 'feed_announcements',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/counts',
				'resource' => 'feed_counts',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/explore',
				'resource' => 'feed_explore',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/explore/page',
				'resource' => 'feed_explore_page',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/home/page',
				'resource' => 'feed_explore_page',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/new-count',
				'resource' => 'feed_new_count',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/feed/viewer-state',
				'resource' => 'feed_viewer_state',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/follow-suggestions',
				'resource' => 'follow_suggestions',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/hashtags/autocomplete',
				'resource' => 'hashtags_autocomplete',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/hashtags/trending',
				'resource' => 'hashtags_trending',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/hashtags/{slug}',
				'resource' => 'hashtags_trending',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/hashtags/{slug}/contributors',
				'resource' => 'hashtags_contributors',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/hashtags/{slug}/feed',
				'resource' => 'hashtags_feed',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/hashtags/{slug}/related',
				'resource' => 'hashtags_related',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/link-preview',
				'resource' => 'link_preview',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/blocked',
				'resource' => 'me_blocked',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/bookmarks',
				'resource' => 'me_bookmarks',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/connection-requests',
				'resource' => 'me_connection_requests',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/connections',
				'resource' => 'me_connections',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/follow-requests',
				'resource' => 'me_follow_requests',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/follow-requests/count',
				'resource' => 'me_follow_requests_count',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/hashtags',
				'resource' => 'me_hashtags',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/interests',
				'resource' => 'me_interests',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/media/{media_id}/usage',
				'resource' => 'me_media_usage',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/muted',
				'resource' => 'me_blocked',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/notification-channels',
				'resource' => 'me_notification_channels',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/notification-prefs',
				'resource' => 'me_notification_prefs',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/notifications/unread-count',
				'resource' => 'me_follow_requests_count',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/onboarding',
				'resource' => 'me_onboarding',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/profile',
				'resource' => 'me_profile',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/profile-slug',
				'resource' => 'me_profile_slug',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/restricted',
				'resource' => 'me_blocked',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/shares',
				'resource' => 'me_shares',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/space-notification-prefs',
				'resource' => 'me_space_notification_prefs',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/standing',
				'resource' => 'me_standing',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/media/{media_id}/space-context',
				'resource' => 'media_space_context',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/members/{id}/discussions',
				'resource' => 'members_discussions',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/moderation/log',
				'resource' => 'moderation_log',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/moderation/pending',
				'resource' => 'moderation_pending',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/posts/{id}/content-warning',
				'resource' => 'posts_content_warning',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/posts/{id}/my-vote',
				'resource' => 'posts_my_vote',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/profile-fields',
				'resource' => 'profile_fields',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/profile-groups',
				'resource' => 'profile_groups',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/profile-slug/check',
				'resource' => 'profile_slug_check',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/pwa/manifest',
				'resource' => 'pwa_manifest',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/reactions',
				'resource' => 'reactions',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/reactions/types',
				'resource' => 'reactions_types',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/search/members',
				'resource' => 'search_members',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/search/suggest',
				'resource' => 'search_suggest',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/shell-nav',
				'resource' => 'shell_nav',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/fields',
				'resource' => 'spaces_fields',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/suggestions',
				'resource' => 'space',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/albums',
				'resource' => 'spaces_albums',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/discussion-search',
				'resource' => 'spaces_discussion_search',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/eligible-parents',
				'resource' => 'spaces_eligible_parents',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/feed',
				'resource' => 'post',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/members',
				'resource' => 'spaces_members',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/notification-pref',
				'resource' => 'spaces_notification_pref',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/subspaces',
				'resource' => 'spaces_subspaces',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/account-type',
				'resource' => 'users_account_type',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/albums',
				'resource' => 'users_albums',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/feed',
				'resource' => 'post',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/followers',
				'resource' => 'users_followers',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/following',
				'resource' => 'users_followers',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/media',
				'resource' => 'users_media',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/member-type',
				'resource' => 'member_type',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/mutual-connections',
				'resource' => 'users_mutual_connections',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/profile',
				'resource' => 'users_profile',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/shadow-ban',
				'resource' => 'users_shadow_ban',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/strikes',
				'resource' => 'users_strikes',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/suspension',
				'resource' => 'users_suspension',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/suspensions',
				'resource' => 'users_suspensions',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/users/{id}/warnings',
				'resource' => 'users_warnings',
				'shape'    => 'array',
			),
			array(
				'method'       => 'GET',
				'path'         => '/pwa/offline',
				'resource'     => '',
				'shape'        => 'text',
				'content_type' => 'text/html',
			),
			array(
				'method'       => 'GET',
				'path'         => '/pwa/sw',
				'resource'     => '',
				'shape'        => 'text',
				'content_type' => 'application/javascript',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/pending-posts',
				'resource' => 'pending_post',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/appeals',
				'resource' => 'appeals',
				'shape'    => 'array',
			),
			array(
				'method'   => 'GET',
				'path'     => '/me/data-export',
				'resource' => 'me_data_export',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/reports',
				'resource' => 'moderation_report',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/bans',
				'resource' => 'spaces_bans',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/media',
				'resource' => 'spaces_media',
				'shape'    => 'item',
			),
			array(
				'method'   => 'GET',
				'path'     => '/spaces/{id}/pending-requests',
				'resource' => 'spaces_pending_requests',
				'shape'    => 'paginated',
			),
			array(
				'method'   => 'GET',
				'path'     => '/webhooks/{id}/log',
				'resource' => 'webhooks_log',
				'shape'    => 'paginated',
			),
		);
	}

	/**
	 * Default 200 body for a write/action route with no mapped resource.
	 *
	 * Every write operation answers with a JSON object, but what it carries depends
	 * on the operation (the changed resource, a {deleted: true} flag, a new count),
	 * so this says exactly that rather than promising fields most routes do not
	 * return. A write route that returns a registered resource is mapped to it in
	 * map() and gets the precise schema instead.
	 *
	 * @return array<string,mixed>
	 */
	public static function action_result(): array {
		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'action-result',
			'type'                 => 'object',
			'description'          => 'Result of a write operation. The fields depend on the operation.',
			'additionalProperties' => true,
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
				'avatar_url'     => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cover_url'      => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'profile_url'    => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'messages_url'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
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
				'labels'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
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
							'url'          => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'date'         => array( 'type' => 'string' ),
							'date_display' => array( 'type' => 'string' ),
							'excerpt'      => array( 'type' => 'string' ),
							'cover'        => array(
								'type'   => array( 'string', 'null' ),
								'format' => 'uri',
							),
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
				'avatar_url'        => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cover_image_url'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'rules'             => array( 'type' => 'string' ),
				'required_ability'  => array( 'type' => 'string' ),
				'is_archived'       => array( 'type' => 'boolean' ),
				'archived_at'       => array( 'type' => array( 'string', 'null' ) ),
				'created_at'        => array( 'type' => 'string' ),
				'created_at_gmt'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'viewer_role'       => array( 'type' => 'string' ),
				'fields'            => array( 'type' => array( 'object', 'array' ) ),
				'parent'            => array( 'type' => array( 'object', 'null' ) ),
				'landing_tab'       => array( 'type' => 'string' ),
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
				'avatar_url'        => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cover_image_url'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'rules'             => array( 'type' => 'string' ),
				'required_ability'  => array( 'type' => 'string' ),
				'is_archived'       => array( 'type' => 'boolean' ),
				'archived_at'       => array( 'type' => array( 'string', 'null' ) ),
				'created_at'        => array( 'type' => 'string' ),
				'created_at_gmt'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
				'media_ids'            => array(
					'type'  => array( 'array', 'null' ),
					'items' => array( 'type' => 'integer' ),
				),
				'link_url'             => array(
					'type'   => array( 'string', 'null' ),
					'format' => 'uri',
				),
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
						'avatar_url'   => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'is_online'    => array( 'type' => 'boolean' ),
					),
				),
				'top_reactors'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
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
				'media'                => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'created_at_gmt'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at_gmt'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
				// Auto-hidden by the report threshold ("Under review"). Only ever true
				// for the comment's author and moderators; other viewers never receive
				// the comment at all, so they never see this field set.
				'is_hidden'         => array( 'type' => 'boolean' ),
				'created_at'        => array( 'type' => 'string' ),
				'updated_at'        => array( 'type' => 'string' ),
				'replies'           => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'author_name'       => array( 'type' => 'string' ),
				'author_avatar_url' => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'like_count'        => array( 'type' => 'integer' ),
				'viewer_liked'      => array( 'type' => 'boolean' ),
				'viewer_reaction'   => array( 'type' => array( 'string', 'null' ) ),
				'can_edit'          => array( 'type' => 'boolean' ),
				'can_delete'        => array( 'type' => 'boolean' ),
				'can_pin'           => array( 'type' => 'boolean' ),
				'is_pinned'         => array( 'type' => 'boolean' ),
				'author_meta_html'  => array( 'type' => 'string' ),
				'content_html'      => array( 'type' => 'string' ),
				'created_at_gmt'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at_gmt'    => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
				'avatar_url'     => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'emoji'          => array( 'type' => 'string' ),
				'created_at'     => array( 'type' => 'string' ),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
												'object_type' => array( 'type' => 'string' ),
												'object_id' => array( 'type' => 'integer' ),
												'title'    => array( 'type' => 'string' ),
												'content'  => array( 'type' => 'string' ),
												'author_id' => array( 'type' => 'integer' ),
												'created_at' => array( 'type' => 'string' ),
												'created_at_gmt' => array( 'type' => 'string' ),
												'url'      => array(
													'type' => 'string',
													'format' => 'uri',
												),
												'subtitle' => array( 'type' => 'string' ),
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
							'image_url'     => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'is_credential' => array( 'type' => 'boolean' ),
							'earned_at'     => array( 'type' => 'string' ),
							'share_url'     => array(
								'type'   => 'string',
								'format' => 'uri',
							),
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
				'group_ids'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'group_actors'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'is_read'        => array( 'type' => 'boolean' ),
				'created_at'     => array( 'type' => 'string' ),
				'data'           => array( 'type' => 'object' ),
				'message'        => array( 'type' => 'string' ),
				'url'            => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'icon'           => array( 'type' => 'string' ),
				'tone'           => array( 'type' => 'string' ),
				'label'          => array( 'type' => 'string' ),
				'actor_name'     => array( 'type' => 'string' ),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
				'reasons'        => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'report_count'   => array( 'type' => 'integer' ),
				'reporter_count' => array( 'type' => 'integer' ),
				'notes'          => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'resolved_by'    => array( 'type' => array( 'integer', 'null' ) ),
				'resolved_at'    => array( 'type' => array( 'string', 'null' ) ),
				'created_at'     => array( 'type' => 'string' ),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
				'url'            => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'has_secret'     => array( 'type' => 'boolean' ),
				'events'         => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'is_active'      => array( 'type' => 'string' ),
				'created_at'     => array( 'type' => 'string' ),
				'updated_at'     => array( 'type' => 'string' ),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
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
				'methods'          => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
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

	/**
	 * Response of GET /admin/slug-check (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function admin_slug_check(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'admin-slug-check',
			'type'       => 'object',
			'properties' => array(
				'status' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Response of GET /albums/{id} (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function albums(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'albums',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'type' => 'integer',
				),
				'space_id'    => array(
					'type' => 'integer',
				),
				'title'       => array(
					'type' => 'string',
				),
				'description' => array(
					'type' => 'string',
				),
				'privacy'     => array(
					'type' => 'string',
				),
				'owner'       => array(
					'type' => 'integer',
				),
				'media_count' => array(
					'type' => 'integer',
				),
				'cover_url'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'is_owner'    => array(
					'type' => 'boolean',
				),
				'html'        => array(
					'type' => 'string',
				),
				'ids'         => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'integer',
					),
				),
				'page'        => array(
					'type' => 'integer',
				),
				'per_page'    => array(
					'type' => 'integer',
				),
				'total_pages' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /appeals (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function appeals(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'appeals',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'integer',
				),
				'suspension_id'  => array(
					'type' => 'integer',
				),
				'user_id'        => array(
					'type' => 'integer',
				),
				'message'        => array(
					'type' => 'string',
				),
				'status'         => array(
					'type' => 'string',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /auth/app-password (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function auth_app_password(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'auth-app-password',
			'type'       => 'object',
			'properties' => array(
				'app_passwords' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'uuid'      => array(
								'type' => 'string',
							),
							'name'      => array(
								'type' => 'string',
							),
							'created'   => array(
								'type' => 'integer',
							),
							'last_used' => array(),
							'current'   => array(
								'type' => 'boolean',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /auth/nonce (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function auth_nonce(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'auth-nonce',
			'type'       => 'object',
			'properties' => array(
				'nonce' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Response of GET /auth/register/config (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function auth_register_config(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'auth-register-config',
			'type'       => 'object',
			'properties' => array(
				'mode'           => array(
					'type' => 'string',
				),
				'terms'          => array(
					'type' => 'boolean',
				),
				'terms_url'      => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'fields'         => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'         => array(
								'type' => 'string',
							),
							'label'       => array(
								'type' => 'string',
							),
							'type'        => array(
								'type' => 'string',
							),
							'required'    => array(
								'type' => 'boolean',
							),
							'options'     => array(
								'type' => array(
									'array',
									'object',
								),
							),
							'description' => array(
								'type' => 'string',
							),
						),
					),
				),
				'reg_token'      => array(
					'type' => 'string',
				),
				'honeypot_field' => array(
					'type' => 'string',
				),
				'challenge'      => array(
					'type'       => 'object',
					'properties' => array(
						'question' => array(
							'type' => 'string',
						),
						'token'    => array(
							'type' => 'string',
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /auth/verify/status (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function auth_verify_status(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'auth-verify-status',
			'type'       => 'object',
			'properties' => array(
				'verified'             => array(
					'type' => 'boolean',
				),
				'enabled'              => array(
					'type' => 'boolean',
				),
				'onboarding_completed' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /feed/announcements (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function feed_announcements(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'feed-announcements',
			'type'       => 'object',
			'properties' => array(
				'announcements' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array(
								'type' => 'integer',
							),
							'space_id'       => array(
								'type' => 'integer',
							),
							'content'        => array(
								'type' => 'string',
							),
							'content_html'   => array(
								'type' => 'string',
							),
							'created_at'     => array(
								'type' => 'string',
							),
							'expires_at'     => array(),
							'author'         => array(
								'type' => 'object',
							),
							'created_at_gmt' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /feed/counts (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function feed_counts(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'feed-counts',
			'type'       => 'object',
			'properties' => array(
				'for_you'   => array(
					'type' => 'integer',
				),
				'following' => array(
					'type' => 'integer',
				),
				'spaces'    => array(
					'type' => 'integer',
				),
				'network'   => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /feed/explore (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function feed_explore(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'feed-explore',
			'type'       => 'object',
			'properties' => array(
				'id'                   => array(
					'type' => 'integer',
				),
				'user_id'              => array(
					'type' => 'integer',
				),
				'space_id'             => array(),
				'shared_post_id'       => array(),
				'type'                 => array(
					'type' => 'string',
				),
				'content'              => array(
					'type' => 'string',
				),
				'media_ids'            => array(),
				'link_url'             => array(),
				'link_meta'            => array(),
				'privacy'              => array(
					'type' => 'string',
				),
				'reaction_count'       => array(
					'type' => 'integer',
				),
				'comment_count'        => array(
					'type' => 'integer',
				),
				'share_count'          => array(
					'type' => 'integer',
				),
				'is_pinned'            => array(
					'type' => 'integer',
				),
				'is_announcement'      => array(
					'type' => 'integer',
				),
				'content_warning'      => array(
					'type' => 'boolean',
				),
				'content_warning_type' => array(),
				'members_only'         => array(
					'type' => 'boolean',
				),
				'status'               => array(
					'type' => 'string',
				),
				'site_pin_expires_at'  => array(),
				'edited_at'            => array(),
				'scheduled_at'         => array(
					'type' => 'string',
				),
				'created_at'           => array(
					'type' => 'string',
				),
				'updated_at'           => array(
					'type' => 'string',
				),
				'author'               => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'type' => 'integer',
						),
						'display_name' => array(
							'type' => 'string',
						),
						'avatar_url'   => array(
							'type' => 'string',
						),
						'is_online'    => array(
							'type' => 'boolean',
						),
					),
				),
				'top_reactors'         => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'content_html'         => array(
					'type' => 'string',
				),
				'viewer_state'         => array(
					'type'       => 'object',
					'properties' => array(
						'my_reaction'        => array(),
						'is_bookmarked'      => array(
							'type' => 'boolean',
						),
						'my_voted_option_id' => array(
							'type' => 'integer',
						),
						'my_share'           => array(
							'type' => 'boolean',
						),
						'can_edit'           => array(
							'type' => 'boolean',
						),
					),
				),
				'media'                => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'created_at_gmt'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'updated_at_gmt'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'scheduled_at_gmt'     => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /feed/explore/page (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function feed_explore_page(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'feed-explore-page',
			'type'       => 'object',
			'properties' => array(
				'html'        => array(
					'type' => 'string',
				),
				'next_cursor' => array(
					'type' => 'string',
				),
				'count'       => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /feed/new-count (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function feed_new_count(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'feed-new-count',
			'type'       => 'object',
			'properties' => array(
				'count'     => array(
					'type' => 'integer',
				),
				'newest_id' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /feed/viewer-state (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function feed_viewer_state(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'feed-viewer-state',
			'type'       => 'object',
			'properties' => array(
				'states'    => array(),
				'requested' => array(
					'type' => 'integer',
				),
				'returned'  => array(
					'type' => 'integer',
				),
				'truncated' => array(
					'type' => 'boolean',
				),
				'max_ids'   => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /follow-suggestions (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function follow_suggestions(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'follow-suggestions',
			'type'       => 'object',
			'properties' => array(
				'ids' => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /hashtags/autocomplete (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function hashtags_autocomplete(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'hashtags-autocomplete',
			'type'       => 'object',
			'properties' => array(
				'id'         => array(
					'type' => 'integer',
				),
				'slug'       => array(
					'type' => 'string',
				),
				'name'       => array(
					'type' => 'string',
				),
				'post_count' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /hashtags/trending (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function hashtags_trending(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'hashtags-trending',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'integer',
				),
				'name'           => array(
					'type' => 'string',
				),
				'slug'           => array(
					'type' => 'string',
				),
				'post_count'     => array(
					'type' => 'integer',
				),
				'follower_count' => array(
					'type' => 'integer',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /hashtags/{slug}/contributors (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function hashtags_contributors(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'hashtags-contributors',
			'type'       => 'object',
			'properties' => array(
				'contributors' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'user_id'      => array(
								'type' => 'integer',
							),
							'display_name' => array(
								'type' => 'string',
							),
							'post_count'   => array(
								'type' => 'integer',
							),
						),
					),
				),
				'total'        => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /hashtags/{slug}/feed (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function hashtags_feed(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'hashtags-feed',
			'type'       => 'object',
			'properties' => array(
				'items'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'                   => array(
								'type' => 'integer',
							),
							'user_id'              => array(
								'type' => 'integer',
							),
							'space_id'             => array(),
							'shared_post_id'       => array(),
							'type'                 => array(
								'type' => 'string',
							),
							'content'              => array(
								'type' => 'string',
							),
							'media_ids'            => array(),
							'link_url'             => array(),
							'link_meta'            => array(),
							'privacy'              => array(
								'type' => 'string',
							),
							'reaction_count'       => array(
								'type' => 'integer',
							),
							'comment_count'        => array(
								'type' => 'integer',
							),
							'share_count'          => array(
								'type' => 'integer',
							),
							'is_pinned'            => array(
								'type' => 'integer',
							),
							'is_announcement'      => array(
								'type' => 'integer',
							),
							'content_warning'      => array(
								'type' => 'boolean',
							),
							'content_warning_type' => array(),
							'members_only'         => array(
								'type' => 'boolean',
							),
							'status'               => array(
								'type' => 'string',
							),
							'site_pin_expires_at'  => array(),
							'edited_at'            => array(),
							'scheduled_at'         => array(),
							'created_at'           => array(
								'type' => 'string',
							),
							'updated_at'           => array(
								'type' => 'string',
							),
							'author'               => array(
								'type' => 'object',
							),
							'top_reactors'         => array(
								'type' => 'array',
							),
							'content_html'         => array(
								'type' => 'string',
							),
							'viewer_state'         => array(
								'type' => 'object',
							),
							'media'                => array(
								'type' => array(
									'array',
									'object',
								),
							),
							'created_at_gmt'       => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
							'updated_at_gmt'       => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
				'next_cursor' => array(
					'type' => 'string',
				),
				'hashtag'     => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array(
							'type' => 'integer',
						),
						'name'           => array(
							'type' => 'string',
						),
						'slug'           => array(
							'type' => 'string',
						),
						'post_count'     => array(
							'type' => 'integer',
						),
						'follower_count' => array(
							'type' => 'integer',
						),
						'created_at'     => array(
							'type' => 'string',
						),
						'created_at_gmt' => array(
							'type'   => 'string',
							'format' => 'date-time',
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /hashtags/{slug}/related (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function hashtags_related(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'hashtags-related',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'integer',
				),
				'name'           => array(
					'type' => 'string',
				),
				'slug'           => array(
					'type' => 'string',
				),
				'post_count'     => array(
					'type' => 'integer',
				),
				'follower_count' => array(
					'type' => 'integer',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'co_occurrence'  => array(
					'type' => 'integer',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /link-preview (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function link_preview(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'link-preview',
			'type'       => 'object',
			'properties' => array(
				'url'         => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'title'       => array(
					'type' => 'string',
				),
				'description' => array(
					'type' => 'string',
				),
				'thumbnail'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
		);
	}

	/**
	 * Response of GET /me/blocked (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_blocked(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-blocked',
			'type'       => 'object',
			'properties' => array(
				'ids'     => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'members' => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/bookmarks (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_bookmarks(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-bookmarks',
			'type'       => 'object',
			'properties' => array(
				'ids' => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'integer',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/connection-requests (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_connection_requests(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-connection-requests',
			'type'       => 'object',
			'properties' => array(
				'page'     => array(
					'type' => 'integer',
				),
				'per_page' => array(
					'type' => 'integer',
				),
				'has_more' => array(
					'type' => 'boolean',
				),
				'notes'    => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'ids'      => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'integer',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/connections (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_connections(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-connections',
			'type'       => 'object',
			'properties' => array(
				'total'       => array(
					'type' => 'integer',
				),
				'per_page'    => array(
					'type' => 'integer',
				),
				'next_cursor' => array(),
				'ids'         => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'deprecated'  => array(
					'type'       => 'object',
					'properties' => array(
						'param'   => array(
							'type' => 'string',
						),
						'use'     => array(
							'type' => 'string',
						),
						'message' => array(
							'type' => 'string',
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/follow-requests (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_follow_requests(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-follow-requests',
			'type'       => 'object',
			'properties' => array(
				'total'       => array(
					'type' => 'integer',
				),
				'page'        => array(
					'type' => 'integer',
				),
				'total_pages' => array(
					'type' => 'integer',
				),
				'ids'         => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/follow-requests/count (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_follow_requests_count(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-follow-requests-count',
			'type'       => 'object',
			'properties' => array(
				'count' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /me/hashtags (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_hashtags(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-hashtags',
			'type'       => 'object',
			'properties' => array(
				'items'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array(
								'type' => 'integer',
							),
							'name'           => array(
								'type' => 'string',
							),
							'slug'           => array(
								'type' => 'string',
							),
							'post_count'     => array(
								'type' => 'integer',
							),
							'follower_count' => array(
								'type' => 'integer',
							),
							'created_at'     => array(
								'type' => 'string',
							),
							'created_at_gmt' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
				'has_more' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /me/interests (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_interests(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-interests',
			'type'       => 'object',
			'properties' => array(
				'interests' => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/media/{media_id}/usage (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_media_usage(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-media-usage',
			'type'       => 'object',
			'properties' => array(
				'media_id'     => array(
					'type' => 'integer',
				),
				'space_albums' => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /me/notification-channels (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_notification_channels(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-notification-channels',
			'type'       => 'object',
			'properties' => array(
				'channels'       => array(
					'type'       => 'object',
					'properties' => array(
						'in_app' => array(
							'type' => 'boolean',
						),
						'email'  => array(
							'type' => 'boolean',
						),
						'push'   => array(
							'type' => 'boolean',
						),
						'sound'  => array(
							'type' => 'boolean',
						),
					),
				),
				'push_available' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /me/notification-prefs (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_notification_prefs(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-notification-prefs',
			'type'       => 'object',
			'properties' => array(
				'prefs'           => array(
					'type'       => 'object',
					'properties' => array(
						'bn.new_follower'                  => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.follow_requested'              => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.connection_requested'          => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.connection_accepted'           => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.post_reacted'                  => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.comment_reacted'               => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.post_commented'                => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.comment_reply'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.post_shared'                   => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.mention'                       => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.bookmark_milestone'            => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_join'                    => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_invite'                  => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_join_requested'          => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_request_approved'        => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_ownership_received'      => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_join_declined'           => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_new_post'                => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_media_unlinked'          => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.announcement'                  => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.space_role_changed'            => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.bulk_invite'                   => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.new_message'                   => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.user_warned'                   => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.strike_warning'                => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.strike_issued'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.member_suspended'              => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.user_unsuspended'              => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.user_shadow_banned'            => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.appeal_submitted'              => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.appeal_resolved'               => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.report_resolved'               => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.new_report'                    => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.post_approved'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.post_rejected'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.content_removed'               => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.badge_awarded'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.level_up'                      => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.onboarding_nudge'              => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.daily_digest'                  => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.weekly_digest'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'digest'                           => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.media_favorited'               => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.media_reaction'                => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.media_mention'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'suite.learnomy'                   => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'suite.eventonomy'                 => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_dunning'          => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_cancelled'        => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_expired'          => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_renewal_upcoming' => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_expiring_soon'    => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_plan_changed'     => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'bn.subscription_granted'          => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
						'jt.notification'                  => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'     => array(
									'type' => 'boolean',
								),
								'email_freq'  => array(
									'type' => 'string',
								),
								'label'       => array(
									'type' => 'string',
								),
								'group'       => array(
									'type' => 'string',
								),
								'can_email'   => array(
									'type' => 'boolean',
								),
								'email_only'  => array(
									'type' => 'boolean',
								),
								'description' => array(
									'type' => 'string',
								),
							),
						),
					),
				),
				'stored'          => array(
					'type'       => 'object',
					'properties' => array(
						'bn.new_follower' => array(
							'type'       => 'object',
							'properties' => array(
								'on_site'    => array(
									'type' => 'boolean',
								),
								'email_freq' => array(
									'type' => 'string',
								),
							),
						),
					),
				),
				'digests_enabled' => array(
					'type' => 'boolean',
				),
				'updated'         => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /me/onboarding (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_onboarding(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-onboarding',
			'type'       => 'object',
			'properties' => array(
				'complete' => array(
					'type' => 'boolean',
				),
				'step'     => array(
					'type' => 'integer',
				),
				'total'    => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /me/profile (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_profile(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-profile',
			'type'       => 'object',
			'properties' => array(
				'user_id'           => array(
					'type' => 'integer',
				),
				'display_name'      => array(
					'type' => 'string',
				),
				'avatar_url'        => array(
					'type' => 'string',
				),
				'registered_at'     => array(
					'type' => 'string',
				),
				'groups'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'               => array(
								'type' => 'integer',
							),
							'group_key'        => array(
								'type' => 'string',
							),
							'label'            => array(
								'type' => 'string',
							),
							'type'             => array(
								'type' => 'string',
							),
							'visibility'       => array(
								'type' => 'string',
							),
							'is_system'        => array(
								'type' => 'boolean',
							),
							'sort_order'       => array(
								'type' => 'integer',
							),
							'locked'           => array(
								'type' => 'boolean',
							),
							'fields'           => array(
								'type' => 'array',
							),
							'entries'          => array(
								'type' => 'array',
							),
							'entry_visibility' => array(
								'type' => 'array',
							),
						),
					),
				),
				'fields'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'field_id'             => array(
								'type' => 'integer',
							),
							'field_key'            => array(
								'type' => 'string',
							),
							'label'                => array(
								'type' => 'string',
							),
							'type'                 => array(
								'type' => 'string',
							),
							'options'              => array(),
							'description'          => array(
								'type' => 'string',
							),
							'placeholder'          => array(
								'type' => 'string',
							),
							'is_required'          => array(
								'type' => 'boolean',
							),
							'sort_order'           => array(
								'type' => 'integer',
							),
							'value'                => array(
								'type' => 'string',
							),
							'value_raw'            => array(
								'type' => 'string',
							),
							'value_display'        => array(
								'type' => 'string',
							),
							'field_visibility'     => array(
								'type' => 'string',
							),
							'group_visibility'     => array(
								'type' => 'string',
							),
							'entry_visibility'     => array(
								'type' => 'string',
							),
							'effective_visibility' => array(
								'type' => 'string',
							),
						),
					),
				),
				'labels'            => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'cover_url'         => array(
					'type' => 'string',
				),
				'cover_focal'       => array(),
				'completion'        => array(
					'type'       => 'object',
					'properties' => array(
						'percent'            => array(
							'type' => 'integer',
						),
						'required_filled'    => array(
							'type' => 'integer',
						),
						'required_total'     => array(
							'type' => 'integer',
						),
						'recommended_filled' => array(
							'type' => 'integer',
						),
						'recommended_total'  => array(
							'type' => 'integer',
						),
					),
				),
				'strength'          => array(
					'type'       => 'object',
					'properties' => array(
						'tasks'   => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
						'done'    => array(
							'type' => 'integer',
						),
						'total'   => array(
							'type' => 'integer',
						),
						'percent' => array(
							'type' => 'integer',
						),
					),
				),
				'registered_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /me/profile-slug (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_profile_slug(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-profile-slug',
			'type'       => 'object',
			'properties' => array(
				'slug' => array(),
				'url'  => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
		);
	}

	/**
	 * Response of GET /me/shares (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_shares(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-shares',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'integer',
				),
				'post_id'        => array(
					'type' => 'integer',
				),
				'content'        => array(
					'type' => 'string',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /me/space-notification-prefs (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_space_notification_prefs(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-space-notification-prefs',
			'type'       => 'object',
			'properties' => array(
				'space_id'   => array(
					'type' => 'integer',
				),
				'name'       => array(
					'type' => 'string',
				),
				'slug'       => array(
					'type' => 'string',
				),
				'avatar_url' => array(
					'type' => 'string',
				),
				'pref'       => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Response of GET /me/standing (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_standing(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-standing',
			'type'       => 'object',
			'properties' => array(
				'strikes'    => array(
					'type' => 'integer',
				),
				'history'    => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'suspension' => array(),
			),
		);
	}

	/**
	 * Response of GET /media/{media_id}/space-context (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function media_space_context(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'media-space-context',
			'type'       => 'object',
			'properties' => array(
				'space_id'   => array(
					'type' => 'integer',
				),
				'can_unlink' => array(
					'type' => 'boolean',
				),
				'post_id'    => array(
					'type' => 'integer',
				),
				'bookmarked' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /members/{id}/discussions (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function members_discussions(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'members-discussions',
			'type'       => 'object',
			'properties' => array(
				'accepted_answers' => array(
					'type' => 'integer',
				),
				'reputation'       => array(
					'type' => 'integer',
				),
				'trust_level'      => array(
					'type' => 'integer',
				),
				'discussion_count' => array(
					'type' => 'integer',
				),
				'discussions'      => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array(
								'type' => 'integer',
							),
							'title'          => array(
								'type' => 'string',
							),
							'url'            => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'reply_count'    => array(
								'type' => 'integer',
							),
							'vote_score'     => array(
								'type' => 'integer',
							),
							'space_name'     => array(
								'type' => 'string',
							),
							'created_at'     => array(
								'type' => 'string',
							),
							'created_at_gmt' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /moderation/log (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function moderation_log(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'moderation-log',
			'type'       => 'object',
			'properties' => array(
				'items'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array(
								'type' => 'integer',
							),
							'actor_id'       => array(
								'type' => 'integer',
							),
							'action'         => array(
								'type' => 'string',
							),
							'object_type'    => array(
								'type' => 'string',
							),
							'object_id'      => array(
								'type' => 'integer',
							),
							'target_user_id' => array(
								'type' => 'integer',
							),
							'note'           => array(
								'type' => 'string',
							),
							'created_at'     => array(
								'type' => 'string',
							),
							'created_at_gmt' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
				'total'    => array(
					'type' => 'integer',
				),
				'page'     => array(
					'type' => 'integer',
				),
				'per_page' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /moderation/pending (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function moderation_pending(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'moderation-pending',
			'type'       => 'object',
			'properties' => array(
				'items'    => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'total'    => array(
					'type' => 'integer',
				),
				'page'     => array(
					'type' => 'integer',
				),
				'per_page' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /posts/{id}/content-warning (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function posts_content_warning(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'posts-content-warning',
			'type'       => 'object',
			'properties' => array(
				'has_warning'  => array(
					'type' => 'boolean',
				),
				'warning_type' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Response of GET /posts/{id}/my-vote (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function posts_my_vote(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'posts-my-vote',
			'type'       => 'object',
			'properties' => array(
				'option_id' => array(),
			),
		);
	}

	/**
	 * Response of GET /profile-fields (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function profile_fields(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'profile-fields',
			'type'       => 'object',
			'properties' => array(
				'groups' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'               => array(
								'type' => 'integer',
							),
							'group_key'        => array(
								'type' => 'string',
							),
							'label'            => array(
								'type' => 'string',
							),
							'type'             => array(
								'type' => 'string',
							),
							'visibility'       => array(
								'type' => 'string',
							),
							'is_system'        => array(
								'type' => 'boolean',
							),
							'sort_order'       => array(
								'type' => 'integer',
							),
							'type_restriction' => array(
								'type' => 'string',
							),
							'fields'           => array(
								'type' => 'array',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /profile-groups (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function profile_groups(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'profile-groups',
			'type'       => 'object',
			'properties' => array(
				'groups' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'               => array(
								'type' => 'integer',
							),
							'group_key'        => array(
								'type' => 'string',
							),
							'label'            => array(
								'type' => 'string',
							),
							'type'             => array(
								'type' => 'string',
							),
							'visibility'       => array(
								'type' => 'string',
							),
							'is_system'        => array(
								'type' => 'boolean',
							),
							'sort_order'       => array(
								'type' => 'integer',
							),
							'type_restriction' => array(
								'type' => 'string',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /profile-slug/check (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function profile_slug_check(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'profile-slug-check',
			'type'       => 'object',
			'properties' => array(
				'slug'      => array(
					'type' => 'string',
				),
				'available' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /pwa/manifest (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function pwa_manifest(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'pwa-manifest',
			'type'       => 'object',
			'properties' => array(
				'name'             => array(
					'type' => 'string',
				),
				'short_name'       => array(
					'type' => 'string',
				),
				'description'      => array(
					'type' => 'string',
				),
				'start_url'        => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'display'          => array(
					'type' => 'string',
				),
				'background_color' => array(
					'type' => 'string',
				),
				'theme_color'      => array(
					'type' => 'string',
				),
				'orientation'      => array(
					'type' => 'string',
				),
				'scope'            => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'categories'       => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
				'icons'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'src'     => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'sizes'   => array(
								'type' => 'string',
							),
							'type'    => array(
								'type' => 'string',
							),
							'purpose' => array(
								'type' => 'string',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /reactions (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function reactions(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'reactions',
			'type'       => 'object',
			'properties' => array(
				'count'       => array(
					'type' => 'integer',
				),
				'summary'     => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'  => array(
								'type' => 'string',
							),
							'count' => array(
								'type' => 'integer',
							),
							'emoji' => array(
								'type' => 'string',
							),
						),
					),
				),
				'has_reacted' => array(
					'type' => 'boolean',
				),
				'emoji'       => array(),
			),
		);
	}

	/**
	 * Response of GET /reactions/types (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function reactions_types(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'reactions-types',
			'type'       => 'object',
			'properties' => array(
				'reactions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'slug'     => array(
								'type' => 'string',
							),
							'label'    => array(
								'type' => 'string',
							),
							'char'     => array(
								'type' => 'string',
							),
							'color'    => array(
								'type' => 'string',
							),
							'icon_url' => array(
								'type'   => 'string',
								'format' => 'uri',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /search/members (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function search_members(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'search-members',
			'type'       => 'object',
			'properties' => array(
				'user_id'                 => array(
					'type' => 'integer',
				),
				'display_name'            => array(
					'type' => 'string',
				),
				'avatar_url'              => array(
					'type' => 'string',
				),
				'registered_at'           => array(
					'type' => 'string',
				),
				'bio'                     => array(
					'type' => 'string',
				),
				'is_online'               => array(
					'type' => 'boolean',
				),
				'follower_count'          => array(
					'type' => 'integer',
				),
				'mutual_connection_count' => array(
					'type' => 'integer',
				),
				'registered_at_gmt'       => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /search/suggest (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function search_suggest(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'search-suggest',
			'type'       => 'object',
			'properties' => array(
				'query'  => array(
					'type' => 'string',
				),
				'groups' => array(
					'type'       => 'object',
					'properties' => array(
						'types' => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /shell-nav (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function shell_nav(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'shell-nav',
			'type'       => 'object',
			'properties' => array(
				'items'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'   => array(
								'type' => 'string',
							),
							'label' => array(
								'type' => 'string',
							),
							'url'   => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'icon'  => array(
								'type' => 'string',
							),
							'badge' => array(
								'type' => 'integer',
							),
							'group' => array(
								'type' => 'string',
							),
							'order' => array(
								'type' => 'integer',
							),
						),
					),
				),
				'badges' => array(
					'type'       => 'object',
					'properties' => array(
						'notifications' => array(
							'type' => 'integer',
						),
						'messages'      => array(
							'type' => 'integer',
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/fields (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_fields(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-fields',
			'type'       => 'object',
			'properties' => array(
				'fields' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'         => array(
								'type' => 'string',
							),
							'label'       => array(
								'type' => 'string',
							),
							'description' => array(
								'type' => 'string',
							),
							'type'        => array(
								'type' => 'string',
							),
							'options'     => array(
								'type' => array(
									'array',
									'object',
								),
							),
							'section'     => array(
								'type' => 'string',
							),
							'sort_order'  => array(
								'type' => 'integer',
							),
							'visibility'  => array(
								'type' => 'string',
							),
							'is_required' => array(
								'type' => 'boolean',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/albums (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_albums(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-albums',
			'type'       => 'object',
			'properties' => array(
				'albums'   => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'page'     => array(
					'type' => 'integer',
				),
				'per_page' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/discussion-search (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_discussion_search(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-discussion-search',
			'type'       => 'object',
			'properties' => array(
				'results' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'    => array(
								'type' => 'integer',
							),
							'title' => array(
								'type' => 'string',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/eligible-parents (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_eligible_parents(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-eligible-parents',
			'type'       => 'object',
			'properties' => array(
				'id'   => array(
					'type' => 'integer',
				),
				'name' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/members (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_members(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-members',
			'type'       => 'object',
			'properties' => array(
				'user_id'       => array(
					'type' => 'integer',
				),
				'role'          => array(
					'type' => 'string',
				),
				'joined_at'     => array(
					'type' => 'string',
				),
				'display_name'  => array(
					'type' => 'string',
				),
				'user_nicename' => array(
					'type' => 'string',
				),
				'avatar_url'    => array(
					'type' => 'string',
				),
				'joined_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/notification-pref (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_notification_pref(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-notification-pref',
			'type'       => 'object',
			'properties' => array(
				'pref' => array(
					'type' => 'string',
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/subspaces (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_subspaces(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-subspaces',
			'type'       => 'object',
			'properties' => array(
				'subspaces' => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'total'     => array(
					'type' => 'integer',
				),
				'page'      => array(
					'type' => 'integer',
				),
				'per_page'  => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/account-type (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_account_type(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-account-type',
			'type'       => 'object',
			'properties' => array(
				'is_private' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/albums (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_albums(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-albums',
			'type'       => 'object',
			'properties' => array(
				'albums'   => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'          => array(
								'type' => 'integer',
							),
							'space_id'    => array(
								'type' => 'integer',
							),
							'title'       => array(
								'type' => 'string',
							),
							'description' => array(
								'type' => 'string',
							),
							'privacy'     => array(
								'type' => 'string',
							),
							'owner'       => array(
								'type' => 'integer',
							),
							'media_count' => array(
								'type' => 'integer',
							),
							'cover_url'   => array(
								'type'   => 'string',
								'format' => 'uri',
							),
						),
					),
				),
				'page'     => array(
					'type' => 'integer',
				),
				'per_page' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/followers (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_followers(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-followers',
			'type'       => 'object',
			'properties' => array(
				'total'       => array(
					'type' => 'integer',
				),
				'per_page'    => array(
					'type' => 'integer',
				),
				'next_cursor' => array(),
				'ids'         => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/media (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_media(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-media',
			'type'       => 'object',
			'properties' => array(
				'html'        => array(
					'type' => 'string',
				),
				'ids'         => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'integer',
					),
				),
				'total'       => array(
					'type' => 'integer',
				),
				'page'        => array(
					'type' => 'integer',
				),
				'per_page'    => array(
					'type' => 'integer',
				),
				'total_pages' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/mutual-connections (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_mutual_connections(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-mutual-connections',
			'type'       => 'object',
			'properties' => array(
				'total' => array(
					'type' => 'integer',
				),
				'ids'   => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/profile (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_profile(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-profile',
			'type'       => 'object',
			'properties' => array(
				'user_id'           => array(
					'type' => 'integer',
				),
				'display_name'      => array(
					'type' => 'string',
				),
				'avatar_url'        => array(
					'type' => 'string',
				),
				'registered_at'     => array(
					'type' => 'string',
				),
				'groups'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'               => array(
								'type' => 'integer',
							),
							'group_key'        => array(
								'type' => 'string',
							),
							'label'            => array(
								'type' => 'string',
							),
							'type'             => array(
								'type' => 'string',
							),
							'visibility'       => array(
								'type' => 'string',
							),
							'is_system'        => array(
								'type' => 'boolean',
							),
							'sort_order'       => array(
								'type' => 'integer',
							),
							'locked'           => array(
								'type' => 'boolean',
							),
							'fields'           => array(
								'type' => 'array',
							),
							'entries'          => array(
								'type' => 'array',
							),
							'entry_visibility' => array(
								'type' => 'array',
							),
						),
					),
				),
				'fields'            => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'field_id'             => array(
								'type' => 'integer',
							),
							'field_key'            => array(
								'type' => 'string',
							),
							'label'                => array(
								'type' => 'string',
							),
							'type'                 => array(
								'type' => 'string',
							),
							'options'              => array(),
							'description'          => array(
								'type' => 'string',
							),
							'placeholder'          => array(
								'type' => 'string',
							),
							'is_required'          => array(
								'type' => 'boolean',
							),
							'sort_order'           => array(
								'type' => 'integer',
							),
							'value'                => array(
								'type' => 'string',
							),
							'value_raw'            => array(
								'type' => 'string',
							),
							'value_display'        => array(
								'type' => 'string',
							),
							'field_visibility'     => array(
								'type' => 'string',
							),
							'group_visibility'     => array(
								'type' => 'string',
							),
							'entry_visibility'     => array(
								'type' => 'string',
							),
							'effective_visibility' => array(
								'type' => 'string',
							),
						),
					),
				),
				'labels'            => array(
					'type' => array(
						'array',
						'object',
					),
				),
				'cover_url'         => array(
					'type' => 'string',
				),
				'cover_focal'       => array(),
				'completion'        => array(
					'type'       => 'object',
					'properties' => array(
						'percent'            => array(
							'type' => 'integer',
						),
						'required_filled'    => array(
							'type' => 'integer',
						),
						'required_total'     => array(
							'type' => 'integer',
						),
						'recommended_filled' => array(
							'type' => 'integer',
						),
						'recommended_total'  => array(
							'type' => 'integer',
						),
					),
				),
				'strength'          => array(
					'type'       => 'object',
					'properties' => array(
						'tasks'   => array(
							'type'  => 'array',
							'items' => array(
								'type' => 'object',
							),
						),
						'done'    => array(
							'type' => 'integer',
						),
						'total'   => array(
							'type' => 'integer',
						),
						'percent' => array(
							'type' => 'integer',
						),
					),
				),
				'follower_count'    => array(
					'type' => 'integer',
				),
				'following_count'   => array(
					'type' => 'integer',
				),
				'is_self'           => array(
					'type' => 'boolean',
				),
				'is_following'      => array(
					'type' => 'boolean',
				),
				'post_count'        => array(
					'type' => 'integer',
				),
				'connection'        => array(
					'type'       => 'object',
					'properties' => array(
						'state'       => array(
							'type' => 'string',
						),
						'can_message' => array(
							'type' => 'boolean',
						),
					),
				),
				'is_pending'        => array(
					'type' => 'boolean',
				),
				'can_follow'        => array(
					'type' => 'boolean',
				),
				'bio'               => array(
					'type' => 'string',
				),
				'member_type'       => array(
					'type'       => 'object',
					'properties' => array(
						'slug'       => array(
							'type' => 'string',
						),
						'name'       => array(
							'type' => 'string',
						),
						'icon_svg'   => array(
							'type' => 'string',
						),
						'color'      => array(
							'type' => 'string',
						),
						'text_color' => array(
							'type' => 'string',
						),
					),
				),
				'account_status'    => array(
					'type'       => 'object',
					'properties' => array(
						'scope'            => array(
							'type' => 'string',
						),
						'strikes'          => array(
							'type' => 'integer',
						),
						'is_suspended'     => array(
							'type' => 'boolean',
						),
						'suspension'       => array(),
						'is_shadow_banned' => array(
							'type' => 'boolean',
						),
					),
				),
				'registered_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/shadow-ban (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_shadow_ban(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-shadow-ban',
			'type'       => 'object',
			'properties' => array(
				'user_id'       => array(
					'type' => 'integer',
				),
				'shadow_banned' => array(
					'type' => 'boolean',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/strikes (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_strikes(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-strikes',
			'type'       => 'object',
			'properties' => array(
				'user_id' => array(
					'type' => 'integer',
				),
				'count'   => array(
					'type' => 'integer',
				),
				'strikes' => array(
					'type' => array(
						'array',
						'object',
					),
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/suspension (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_suspension(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-suspension',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'string',
				),
				'user_id'        => array(
					'type' => 'string',
				),
				'suspended_by'   => array(
					'type' => 'string',
				),
				'reason'         => array(
					'type' => 'string',
				),
				'duration_days'  => array(),
				'hide_posts'     => array(
					'type' => 'string',
				),
				'expires_at'     => array(
					'type' => 'string',
				),
				'lifted_at'      => array(),
				'lifted_by'      => array(),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'expires_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/suspensions (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_suspensions(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-suspensions',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'string',
				),
				'user_id'        => array(
					'type' => 'string',
				),
				'suspended_by'   => array(
					'type' => 'string',
				),
				'reason'         => array(
					'type' => 'string',
				),
				'duration_days'  => array(),
				'hide_posts'     => array(
					'type' => 'string',
				),
				'expires_at'     => array(
					'type' => 'string',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'expires_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /users/{id}/warnings (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function users_warnings(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'users-warnings',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'string',
				),
				'actor_id'       => array(
					'type' => 'string',
				),
				'action'         => array(
					'type' => 'string',
				),
				'note'           => array(
					'type' => 'string',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * A post held for review (GET /me/pending-posts). Database row fields as
	 * stored; ids and dates arrive as strings.
	 *
	 * @return array<string,mixed>
	 */
	public static function pending_post(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'pending-post',
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'string' ),
				'space_id'   => array( 'type' => array( 'string', 'null' ) ),
				'type'       => array( 'type' => 'string' ),
				'content'    => array( 'type' => 'string' ),
				'link_url'   => array( 'type' => array( 'string', 'null' ) ),
				'created_at' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Response of GET /me/data-export (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function me_data_export(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'me-data-export',
			'type'       => 'object',
			'properties' => array(
				'generated_at' => array(
					'type' => 'string',
				),
				'user'         => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'type' => 'integer',
						),
						'username'     => array(
							'type' => 'string',
						),
						'email'        => array(
							'type' => 'string',
						),
						'display_name' => array(
							'type' => 'string',
						),
						'registered'   => array(
							'type' => 'string',
						),
					),
				),
				'items'        => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'group_id'    => array(
								'type' => 'string',
							),
							'group_label' => array(
								'type' => 'string',
							),
							'item_id'     => array(
								'type' => 'string',
							),
							'data'        => array(
								'type' => 'array',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/bans (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_bans(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-bans',
			'type'       => 'object',
			'properties' => array(
				'space_id'       => array(
					'type' => 'integer',
				),
				'user_id'        => array(
					'type' => 'integer',
				),
				'display_name'   => array(
					'type' => 'string',
				),
				'user_nicename'  => array(
					'type' => 'string',
				),
				'avatar_url'     => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'reason'         => array(
					'type' => 'string',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'banned_by'      => array(
					'type' => 'integer',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/media (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_media(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-media',
			'type'       => 'object',
			'properties' => array(
				'items'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'             => array(
								'type' => 'integer',
							),
							'type'           => array(
								'type' => 'string',
							),
							'url'            => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'thumb'          => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'title'          => array(
								'type' => 'string',
							),
							'width'          => array(
								'type' => 'integer',
							),
							'height'         => array(
								'type' => 'integer',
							),
							'duration'       => array(
								'type' => 'string',
							),
							'permalink'      => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'media_id'       => array(
								'type' => 'integer',
							),
							'post_id'        => array(
								'type' => 'integer',
							),
							'user_id'        => array(
								'type' => 'integer',
							),
							'created_at'     => array(
								'type' => 'string',
							),
							'created_at_gmt' => array(
								'type'   => 'string',
								'format' => 'date-time',
							),
						),
					),
				),
				'page'        => array(
					'type' => 'integer',
				),
				'per_page'    => array(
					'type' => 'integer',
				),
				'total'       => array(
					'type' => 'integer',
				),
				'total_pages' => array(
					'type' => 'integer',
				),
			),
		);
	}

	/**
	 * Response of GET /spaces/{id}/pending-requests (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function spaces_pending_requests(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'spaces-pending-requests',
			'type'       => 'object',
			'properties' => array(
				'user_id'      => array(
					'type' => 'integer',
				),
				'requested_at' => array(
					'type' => 'string',
				),
				'display_name' => array(
					'type' => 'string',
				),
				'avatar_url'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
		);
	}

	/**
	 * Response of GET /webhooks/{id}/log (authored from the live response).
	 *
	 * @return array<string,mixed>
	 */
	public static function webhooks_log(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'webhooks-log',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type' => 'string',
				),
				'webhook_id'     => array(
					'type' => 'string',
				),
				'event'          => array(
					'type' => 'string',
				),
				'response_code'  => array(
					'type' => 'string',
				),
				'response_body'  => array(
					'type' => 'string',
				),
				'status'         => array(
					'type' => 'string',
				),
				'created_at'     => array(
					'type' => 'string',
				),
				'created_at_gmt' => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
			),
		);
	}
}

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
}

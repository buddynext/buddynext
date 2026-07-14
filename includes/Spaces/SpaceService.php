<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Space lifecycle service.
 *
 * Manages space creation, retrieval, updates, and deletion. Spaces are
 * community containers that users can join. Each space has a unique slug,
 * a type (open / private / secret), and is owned by the creator.
 *
 * When a space is created the owner is automatically inserted into
 * bn_space_members with role='owner' and the member_count is set to 1.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use WP_Error;

/**
 * Handles space CRUD.
 */
class SpaceService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_spaces';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Slugs reserved for spaces-hub routes — a member must never create a space
	 * whose slug shadows a real route (e.g. `mine` would collide with the
	 * /spaces/mine/ "My Spaces" view). Defence-in-depth alongside rewrite-rule
	 * ordering. Addons that register their own /spaces/<word>/ routes (e.g.
	 * Learnomy's plans/buy/manage) extend the list via buddynext_reserved_space_slugs.
	 *
	 * @var string[]
	 */
	private const RESERVED_SLUGS = array( 'mine', 'managed', 'joined' );

	/**
	 * How many top contributors are computed and cached per space.
	 *
	 * One cached list per space, sliced in PHP for whatever the caller asked for. This
	 * is the cap on that list, and it matches top_contributors()'s own limit ceiling —
	 * so no caller can ask for more than we cache and silently get a truncated answer.
	 */
	private const MAX_TOP_CONTRIBUTORS = 50;

	/**
	 * Space type — listed in directory; anyone can join.
	 */
	public const TYPE_OPEN = 'open';

	/**
	 * Space type — listed but join requires approval.
	 */
	public const TYPE_PRIVATE = 'private';

	/**
	 * Space type — hidden from non-members; admin-invite only.
	 *
	 * These three constants document the canonical core slugs. Validation and
	 * semantics now live in {@see SpaceTypeRegistry}, which also lets third
	 * parties register custom types via the `buddynext_space_types` filter.
	 */
	public const TYPE_SECRET = 'secret';

	/**
	 * Whether a slug is reserved for a spaces-hub route and so cannot be used as a
	 * space slug (defence-in-depth alongside rewrite-rule ordering — e.g. `mine`
	 * shadows /spaces/mine/). Filterable via buddynext_reserved_space_slugs so
	 * addon routes (Learnomy plans/buy/manage, etc.) can extend the list.
	 *
	 * @param string $slug Sanitized slug.
	 * @return bool
	 */
	public static function is_reserved_slug( string $slug ): bool {
		/**
		 * Reserved space slugs.
		 *
		 * @param string[] $reserved Reserved slug list.
		 */
		$reserved = (array) apply_filters( 'buddynext_reserved_space_slugs', self::RESERVED_SLUGS );

		return in_array( $slug, array_map( 'strval', $reserved ), true );
	}

	/**
	 * Return the i18n'd human-readable label for a space type.
	 *
	 * Canonical labels: open -> "Open", private -> "Private", secret -> "Secret".
	 * Use everywhere a type is rendered to a user (directory chips, hero badge,
	 * settings forms) so the surface vocabulary never drifts from the data layer.
	 *
	 * @param string $type One of 'open' | 'private' | 'secret'. Unknown values fall back to 'Open'.
	 * @return string Translated human label.
	 */
	public static function type_label( string $type ): string {
		return SpaceTypeRegistry::instance()->label( $type );
	}

	/**
	 * The space row as an object with category name/slug resolved.
	 *
	 * The one loader for the shared parts: the bare `get()` row plus its category
	 * lookup, cast to the object shape every part reads (hero, about, members,
	 * feed, sidebar). Used by the space hub shell AND each space panel render, so
	 * the hub and a panel never resolve the row two different ways.
	 *
	 * @param int $space_id Space ID.
	 * @return object|null Null when the space does not exist.
	 */
	public function get_object( int $space_id ): ?object {
		$row = $this->get( $space_id );
		if ( null === $row ) {
			return null;
		}
		$row['category_name'] = '';
		$row['category_slug'] = '';
		if ( ! empty( $row['category_id'] ) ) {
			$category = ( new SpaceCategoryService() )->get_by_id( (int) $row['category_id'] );
			if ( is_array( $category ) ) {
				$row['category_name'] = (string) ( $category['name'] ?? '' );
				$row['category_slug'] = (string) ( $category['slug'] ?? '' );
			}
		}
		return (object) $row;
	}

	/**
	 * Presentation meta shared by the space hub header and the About panel: the
	 * privacy type's label + tone and the formatted member count. One source so a
	 * panel and the shell never format the same facts two different ways.
	 *
	 * @param object $space Space object from {@see get_object()}.
	 * @return array{privacy_label:string,privacy_tone:string,member_count_fmt:string}
	 */
	public static function display_meta( object $space ): array {
		$type = isset( $space->type ) ? (string) $space->type : '';
		return array(
			'privacy_label'    => self::type_label( $type ),
			'privacy_tone'     => SpaceTypeRegistry::instance()->tone( $type ),
			'member_count_fmt' => number_format_i18n( (int) ( $space->member_count ?? 0 ) ),
		);
	}

	/**
	 * Return a single space by URL slug.
	 *
	 * @param string $slug Sanitized space slug.
	 * @return array|null Null when not found.
	 */
	public function get_by_slug( string $slug ): ?array {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$cache_key = "space_slug_{$slug}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces WHERE slug = %s LIMIT 1",
				$slug
			),
			ARRAY_A
		);

		if ( null === $row ) {
			wp_cache_set( $cache_key, 0, self::CACHE_GROUP, self::CACHE_TTL );
			return null;
		}

		$space = $this->hydrate( $row );
		wp_cache_set( $cache_key, $space, self::CACHE_GROUP, self::CACHE_TTL );
		return $space;
	}

	/**
	 * Space categories as an id => name map, ordered for admin pickers.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}bn_space_categories ORDER BY sort_order ASC, name ASC" );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = (string) $row->name;
		}
		return $out;
	}

	/**
	 * Space categories as full hydrated rows, ordered for admin/directory use.
	 *
	 * Unlike get_categories() (id => name map), this returns the complete row
	 * for each category — including colour, text colour, icon and directory
	 * visibility — so the unified taxonomy editor and the front-end directory
	 * can paint category colour.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_categories_full(): array {
		return ( new SpaceCategoryService() )->get_all();
	}

	/**
	 * Create a new space.
	 *
	 * @param int   $owner_id Creator/owner user ID.
	 * @param array $data     Space data: name (required), slug (required), type, description, category_id, parent_id.
	 * @return int|WP_Error Inserted space ID or WP_Error on validation failure.
	 */
	public function create( int $owner_id, array $data ): int|WP_Error {
		global $wpdb;

		$slug = sanitize_title( $data['slug'] ?? '' );

		if ( '' === $slug ) {
			return new WP_Error( 'missing_slug', __( 'A space slug is required.', 'buddynext' ) );
		}

		if ( self::is_reserved_slug( $slug ) ) {
			return new WP_Error( 'slug_reserved', __( 'That slug is reserved. Please choose another.', 'buddynext' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE slug = %s",
				$slug
			)
		);

		if ( null !== $existing ) {
			return new WP_Error( 'slug_taken', __( 'This slug is already taken.', 'buddynext' ) );
		}

		// Per-member space cap (Settings → Spaces → "Max spaces per member").
		// 0 = unlimited. Admins are exempt so site operators are never blocked.
		/**
		 * Filter the maximum number of spaces a single member may own.
		 *
		 * Default is the site-wide "Max spaces per member" setting; 0 = unlimited.
		 * Pro hooks this to return the member's `limits.spaces_created` plan
		 * entitlement, so the cap becomes per-plan rather than per-site. The count
		 * below is a COUNT(*) over the indexed `owner_id` column, so a returned cap
		 * is always enforced server-side before any INSERT.
		 *
		 * @since 1.0.8
		 *
		 * @param int $max_per_member Maximum owned, non-archived spaces. 0 = unlimited.
		 * @param int $owner_id       The user attempting to create a space.
		 */
		$max_per_member = (int) apply_filters(
			'buddynext_space_max_per_member',
			(int) get_option( 'buddynext_space_max_per_member', 0 ),
			$owner_id
		);
		if ( $max_per_member > 0 && ! user_can( $owner_id, 'manage_options' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$owned = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE owner_id = %d AND is_archived = 0",
					$owner_id
				)
			);
			if ( $owned >= $max_per_member ) {
				$error = new WP_Error(
					'max_spaces_per_member',
					sprintf(
						/* translators: %d: maximum number of spaces a member can create. */
						__( 'You have reached the maximum of %d spaces.', 'buddynext' ),
						$max_per_member
					),
					array( 'status' => 422 )
				);

				/** This filter is documented in includes/Feed/PostService.php. */
				return apply_filters( 'buddynext_plan_limit_error', $error, 'spaces_created', $max_per_member, $owner_id );
			}
		}

		// Default visibility for new spaces (Settings → Spaces → New-space defaults).
		$req_type = (string) ( $data['type'] ?? get_option( 'buddynext_space_default_type', 'open' ) );
		$type     = SpaceTypeRegistry::instance()->is_valid( $req_type ) ? $req_type : 'open';

		// Enforce two-level sub-space depth limit.
		$parent_id = isset( $data['parent_id'] ) ? (int) $data['parent_id'] : null;
		if ( null !== $parent_id && $parent_id > 0 ) {
			// Sub-spaces can be switched off entirely (Settings → Spaces). Stored
			// as the string flag '1'/'0' so the off-state persists reliably.
			if ( '0' === (string) get_option( 'buddynext_space_allow_sub', '1' ) ) {
				return new WP_Error(
					'sub_spaces_disabled',
					__( 'Sub-spaces are disabled on this community.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}

			// The parent must EXIST before we reason about its depth.
			//
			// This used to go straight to the grandparent lookup below, and read a
			// missing parent as a passing depth check: get_var() returns NULL for a
			// row that does not exist, which is indistinguishable from a real parent
			// whose own parent_id is NULL — and `null !== $grandparent` treats both as
			// "no grandparent, therefore fine". So a create() with a nonexistent
			// parent_id produced an ORPHAN sub-space: a row pointing at nothing,
			// invisible under any parent, unreachable in the tree.
			//
			// validate_parent_move() has always guarded this correctly (it returns
			// parent_not_found). Same rule, two code paths, one of them wrong.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$parent_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, parent_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
					$parent_id
				),
				ARRAY_A
			);
			if ( ! $parent_row ) {
				return new WP_Error(
					'parent_not_found',
					__( 'The selected parent space does not exist.', 'buddynext' ),
					array( 'status' => 422 )
				);
			}

			$grandparent = $parent_row['parent_id'];
			if ( null !== $grandparent && (int) $grandparent > 0 ) {
				return new WP_Error(
					'max_depth_exceeded',
					__( 'Spaces may only be nested two levels deep.', 'buddynext' )
				);
			}

			// Enforce the configured per-parent sub-space cap (Settings → Spaces →
			// "Max Sub-Spaces"). 0 = unlimited.
			$max_sub = (int) get_option( 'buddynext_space_max_sub_spaces', 0 );
			if ( $max_sub > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$existing_sub = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE parent_id = %d",
						$parent_id
					)
				);
				if ( $existing_sub >= $max_sub ) {
					return new WP_Error(
						'max_sub_spaces_exceeded',
						sprintf(
							/* translators: %d: maximum number of sub-spaces allowed per parent. */
							__( 'This space already has the maximum of %d sub-spaces.', 'buddynext' ),
							$max_sub
						),
						array( 'status' => 422 )
					);
				}
			}
		}

		// Fall back to the configured default category when none is chosen.
		$category_id = ( isset( $data['category_id'] ) && (int) $data['category_id'] > 0 )
			? (int) $data['category_id']
			: (int) get_option( 'buddynext_space_default_category', 0 );
		$category_id = $category_id > 0 ? $category_id : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'         => sanitize_text_field( $data['name'] ?? '' ),
				'slug'         => $slug,
				'description'  => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : null,
				'category_id'  => $category_id,
				'parent_id'    => $parent_id,
				'type'         => $type,
				'owner_id'     => $owner_id,
				'member_count' => 1,
				// UTC write so the "Created" date localizes correctly via
				// buddynext_date_local() under any site timezone.
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s' )
		);

		$space_id = (int) $wpdb->insert_id;

		// Auto-add owner as member.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id'  => $space_id,
				'user_id'   => $owner_id,
				'role'      => 'owner',
				'joined_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		/**
		 * Fires after a new space is created.
		 *
		 * @param int $space_id  Newly created space ID.
		 * @param int $owner_id  ID of the space owner.
		 */
		do_action( 'buddynext_space_created', $space_id, $owner_id );

		return $space_id;
	}

	/**
	 * Return a single space by ID.
	 *
	 * @param int $space_id Space ID.
	 * @return array|null Null if not found.
	 */
	public function get( int $space_id ): ?array {
		$cache_key = "space_{$space_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
				$space_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		$space = $this->hydrate( $row );

		wp_cache_set( $cache_key, $space, self::CACHE_GROUP, self::CACHE_TTL );

		return $space;
	}

	/**
	 * Update a space.
	 *
	 * Only the owner (or a user with manage_options) may update a space.
	 *
	 * @param int   $space_id  Space to update.
	 * @param int   $user_id   User requesting the update.
	 * @param array $data      Fields to update: name, description, type, category_id.
	 * @return true|WP_Error
	 */
	public function update( int $space_id, int $user_id, array $data ): bool|WP_Error {
		$space = $this->get( $space_id );

		if ( null === $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ) );
		}

		if ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to update this space.', 'buddynext' ) );
		}

		global $wpdb;

		$fields = array();
		$format = array();

		if ( isset( $data['name'] ) ) {
			$fields['name'] = sanitize_text_field( $data['name'] );
			$format[]       = '%s';
		}
		if ( isset( $data['description'] ) ) {
			$fields['description'] = sanitize_textarea_field( $data['description'] );
			$format[]              = '%s';
		}
		if ( isset( $data['type'] ) && SpaceTypeRegistry::instance()->is_valid( (string) $data['type'] ) ) {
			$fields['type'] = $data['type'];
			$format[]       = '%s';
		}
		if ( isset( $data['category_id'] ) ) {
			$fields['category_id'] = (int) $data['category_id'];
			$format[]              = '%d';
		}
		if ( isset( $data['slug'] ) ) {
			$new_slug = sanitize_title( $data['slug'] );
			if ( '' !== $new_slug && $new_slug !== $space['slug'] ) {
				if ( self::is_reserved_slug( $new_slug ) ) {
					return new WP_Error( 'slug_reserved', __( 'That slug is reserved. Please choose another.', 'buddynext' ) );
				}
				// Ensure new slug is unique.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$conflict = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE slug = %s AND id != %d LIMIT 1",
						$new_slug,
						$space_id
					)
				);
				if ( null === $conflict ) {
					$fields['slug'] = $new_slug;
					$format[]       = '%s';
				}
			}
		}
		if ( isset( $data['avatar_url'] ) ) {
			$fields['avatar_url'] = esc_url_raw( $data['avatar_url'] );
			$format[]             = '%s';
		}
		if ( isset( $data['cover_image_url'] ) ) {
			$fields['cover_image_url'] = esc_url_raw( $data['cover_image_url'] );
			$format[]                  = '%s';
		}
		if ( isset( $data['rules'] ) ) {
			$fields['rules'] = sanitize_textarea_field( (string) $data['rules'] );
			$format[]        = '%s';
		}

		// required_ability gates the space behind a plan/ability. Free ships no
		// ability system, so this is a seam: the filter returns null (field ignored)
		// unless Pro's GatedSpacesIntegration validates the submitted slug and
		// returns it (or '' to clear the gate).
		if ( array_key_exists( 'required_ability', $data ) ) {
			/**
			 * Validate a space's required_ability before it is persisted.
			 *
			 * @param string|null $ability  Validated ability slug, '' to clear, or null to ignore (default).
			 * @param mixed       $raw      Submitted value.
			 * @param int         $space_id Space being updated.
			 * @param int         $user_id  Actor.
			 */
			$ability = apply_filters( 'buddynext_sanitize_space_required_ability', null, $data['required_ability'], $space_id, $user_id );
			if ( null !== $ability ) {
				$fields['required_ability'] = (string) $ability;
				$format[]                   = '%s';
			}
		}

		// Move under a new parent, or detach to the top level. Validated for depth,
		// cycles, the per-parent cap, and manage permission on the new parent.
		if ( array_key_exists( 'parent_id', $data ) ) {
			$new_parent     = (int) $data['parent_id'];
			$current_parent = (int) ( $space['parent_id'] ?? 0 );
			if ( $new_parent !== $current_parent ) {
				$resolved = $this->validate_parent_move( $space_id, $space, $user_id, $new_parent );
				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}
				$fields['parent_id'] = $resolved; // int (move) or null (detach).
				$format[]            = '%d';
			}
		}

		if ( ! empty( $fields ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_spaces',
				$fields,
				array( 'id' => $space_id ),
				$format,
				array( '%d' )
			);
		}

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );
		if ( isset( $space['slug'] ) && '' !== $space['slug'] ) {
			wp_cache_delete( "space_slug_{$space['slug']}", self::CACHE_GROUP );
		}

		/**
		 * Fires after a space is updated.
		 *
		 * @param int   $space_id Space ID.
		 * @param int   $user_id  User who updated the space.
		 * @param array $data     Fields that were updated.
		 */
		do_action( 'buddynext_space_updated', $space_id, $user_id, $fields );

		return true;
	}

	/**
	 * Archive a space. An archived space stays viewable to its members but
	 * accepts no new activity — posts, comments and joins are refused at their
	 * write entry points, which read is_archived() below. Owner or site admin
	 * only.
	 *
	 * @param int $space_id Space to archive.
	 * @param int $actor_id Acting user (owner or admin).
	 * @return true|WP_Error
	 */
	public function archive( int $space_id, int $actor_id ): bool|WP_Error {
		return $this->set_archived( $space_id, $actor_id, true );
	}

	/**
	 * Restore an archived space to active. Owner or site admin only.
	 *
	 * @param int $space_id Space to restore.
	 * @param int $actor_id Acting user (owner or admin).
	 * @return true|WP_Error
	 */
	public function unarchive( int $space_id, int $actor_id ): bool|WP_Error {
		return $this->set_archived( $space_id, $actor_id, false );
	}

	/**
	 * Toggle the archived flag with a permission check, cache bust and hook.
	 *
	 * @param int  $space_id Space ID.
	 * @param int  $actor_id Acting user.
	 * @param bool $archived Target state.
	 * @return true|WP_Error
	 */
	private function set_archived( int $space_id, int $actor_id, bool $archived ): bool|WP_Error {
		$space = $this->get( $space_id );
		if ( null === $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}
		if ( ! buddynext_service( 'permissions' )->can( $actor_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to archive this space.', 'buddynext' ), array( 'status' => 403 ) );
		}
		if ( ! empty( $space['is_archived'] ) === $archived ) {
			return true; // Already in the requested state.
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_spaces',
			array(
				'is_archived' => $archived ? 1 : 0,
				'archived_at' => $archived ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => $space_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );
		if ( isset( $space['slug'] ) && '' !== $space['slug'] ) {
			wp_cache_delete( "space_slug_{$space['slug']}", self::CACHE_GROUP );
		}

		/**
		 * Fires after a space is archived or unarchived.
		 *
		 * @param int $space_id Space ID.
		 * @param int $actor_id User who changed the state.
		 */
		do_action( $archived ? 'buddynext_space_archived' : 'buddynext_space_unarchived', $space_id, $actor_id );

		return true;
	}

	/**
	 * Whether a space is archived. Reads the wp_cache-backed space row, so the
	 * read-only guards on the post/comment/join paths add no extra query when
	 * the space is already loaded.
	 *
	 * @param int $space_id Space ID (0 = not in a space → never archived).
	 * @return bool
	 */
	public function is_archived( int $space_id ): bool {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 ) {
			return false;
		}
		$space = $this->get( $space_id );
		return null !== $space && ! empty( $space['is_archived'] );
	}

	/**
	 * Move a space's ownership.
	 *
	 * @deprecated 1.0.8 Use assign_owner(), which is atomic and enforces its own
	 *             authorization. This wrapper had no capability check (so a
	 *             moderator on the moderator-gated settings screen could seize the
	 *             space), demoted the actor instead of the owner, and wrote
	 *             owner_id even when the role changes failed.
	 *
	 * @param int $space_id     Space id.
	 * @param int $new_owner_id Member who becomes the owner.
	 * @param int $actor_id     User performing the transfer.
	 * @return bool|WP_Error True on success, WP_Error otherwise.
	 */
	public function transfer_ownership( int $space_id, int $new_owner_id, int $actor_id ): bool|WP_Error {
		_deprecated_function( __METHOD__, '1.0.8', 'SpaceService::assign_owner()' );

		return $this->assign_owner( $space_id, $new_owner_id, $actor_id );
	}

	/**
	 * Move a space's ownership across BOTH tables, or change nothing.
	 *
	 * The single primitive for every ownership change: the settings-screen
	 * transfer, automatic succession when an owner is deleted, and the CLI
	 * repair sweep. Ownership is denormalised across two places that must agree —
	 * `bn_spaces.owner_id` and the `role = 'owner'` row in `bn_space_members` —
	 * and this method exists to make divergence between them impossible.
	 *
	 * Three writes, in a fixed order: (W1) the heir is upserted to an active
	 * owner, (W2) EVERY owner row that is not the heir's is demoted, (W3) the
	 * denormalised pointer moves last. WHAT IS ACTUALLY GUARANTEED, and how:
	 *
	 * 1. CONVERGENCE, NOT A MATCHING POINTER. The method returns success without
	 *    writing only when the space is already converged on the heir: the
	 *    pointer names them, they hold an ACTIVE OWNER row, and it is the ONLY
	 *    owner row. A matching `owner_id` on its own is never enough — it is
	 *    exactly what a space wrecked by the old transfer_ownership() looks
	 *    like, and the CLI repair sweep TRUSTS this return value. Divergence
	 *    falls through to the writes, which are idempotent (W1 upserts, W2 has
	 *    nothing to demote on a clean space, W3 rewrites the same pointer), so
	 *    the primitive repairs a broken space instead of certifying it.
	 * 2. SHORT-CIRCUIT (always). Each write's return value is checked before the
	 *    next one is issued. A failing W1 means W2 and W3 never run, so the
	 *    method can never demote the incumbent and move the pointer to a user it
	 *    failed to promote — the ownerless-space outcome.
	 * 3. ONE OWNER ROW, ALWAYS. W2 demotes every owner row other than the heir's,
	 *    not just the user the pointer named. That self-heals a space that
	 *    already carried a stray owner row, and it closes the concurrent-transfer
	 *    race in which A->B and A->C both read "previous owner = A", one of them
	 *    demotes A, the other matches nothing, and B and C both keep role='owner'.
	 * 4. TRANSACTION (production). The three writes run inside a single
	 *    `START TRANSACTION` / `COMMIT`, rolled back on any failure, so no
	 *    concurrent reader observes a half-applied transfer. Skipped when the
	 *    WordPress test suite is running (`WP_TESTS_DOMAIN`), because
	 *    WP_UnitTestCase already wraps every test in its own transaction and
	 *    MySQL implicitly COMMITs it on a nested `START TRANSACTION` — which
	 *    would leak committed rows into the test database. Overridable via the
	 *    `buddynext_space_use_db_transaction` filter. If `START TRANSACTION`
	 *    itself fails, the method degrades to (5) rather than trusting a
	 *    transaction that never opened — believing in it would disarm BOTH nets.
	 * 5. COMPENSATION (whenever the transaction is skipped or failed to open).
	 *    Because no transaction means no rollback, a failing W2 explicitly undoes
	 *    W1, and a failing W3 undoes W2 and W1 — restoring the heir's exact prior
	 *    row (or deleting it, if they had none) and re-promoting the incumbent.
	 *    A stray owner row W2 swept up is NOT restored; see abort_owner_transfer().
	 *
	 * NOT guaranteed: on the non-transactional path the compensating undo is
	 * itself a DB write, so a database that has stopped accepting writes
	 * entirely can still leave a partially applied transfer. Nor can a
	 * transaction that MySQL only PRETENDS to run be detected here: on a
	 * non-transactional storage engine (our tables carry no explicit ENGINE
	 * clause) `START TRANSACTION` succeeds and `COMMIT` / `ROLLBACK` are silent
	 * no-ops, so the rollback net is inert while the compensation net is off.
	 * `wp buddynext spaces repair-owners` remains the backstop for both — and
	 * repair works precisely because (1) makes this method fix a divergent space
	 * rather than declare it healthy. Every failure path, compensated or not,
	 * busts the same caches the success path does, so a stale role cache never
	 * outlives the rows it described.
	 *
	 * Authorization lives HERE, not in the caller — the older
	 * transfer_ownership() delegated it to callers and a moderator-gated
	 * template was able to rewrite owner_id. A banned user is rejected outright:
	 * only `status = 'active'` members are ever eligible for anything.
	 *
	 * The native return type stays `bool|WP_Error` rather than `true|WP_Error`
	 * because the standalone `true` type is PHP 8.2+ and this plugin supports
	 * 8.1 (see "Requires PHP" and the CI matrix). Callers should treat the
	 * success value as `true` — the @return annotation below is what static
	 * analysis reads.
	 *
	 * @param int      $space_id     Space whose ownership moves.
	 * @param int      $new_owner_id User who becomes the owner. Joined as an
	 *                               active member (with role promoted to
	 *                               'owner') if not one already. Must not be
	 *                               banned from the space.
	 * @param int|null $actor_id     User performing the change; requires
	 *                               `buddynext-manage-space` on this space.
	 *                               Pass null for SYSTEM context (succession /
	 *                               CLI), which performs no capability check.
	 * @return true|WP_Error True on success — the space is converged on the new
	 *                       owner in BOTH tables, whether that took writes or the
	 *                       space was already converged. WP_Error on a
	 *                       missing space (`space_not_found`), unknown user
	 *                       (`invalid_owner`), unauthorised actor (`forbidden`),
	 *                       banned heir (`heir_banned`), or a failed write
	 *                       (`assign_owner_failed`).
	 */
	public function assign_owner( int $space_id, int $new_owner_id, ?int $actor_id = null ): bool|WP_Error {
		global $wpdb;

		$space = $this->get( $space_id );
		if ( null === $space ) {
			return new WP_Error( 'space_not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		if ( $new_owner_id <= 0 || ! get_userdata( $new_owner_id ) ) {
			return new WP_Error( 'invalid_owner', __( 'That user does not exist.', 'buddynext' ), array( 'status' => 422 ) );
		}

		// User context: owner-only (or manage_options, via PermissionService's
		// admin bypass). System context (actor null) intentionally skips this —
		// used by succession and the CLI repair sweep.
		if ( null !== $actor_id && ! buddynext_service( 'permissions' )->can( $actor_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'Only the space owner can transfer ownership.', 'buddynext' ), array( 'status' => 403 ) );
		}

		// A banned user is never eligible. Without this the W1 upsert's
		// `status = 'active'` clause would silently resurrect a soft-banned
		// member as the owner; and a hard ban (a bn_space_bans row) would make
		// PermissionService::is_space_banned() hard-deny every space capability
		// to the person who now nominally owns the space — a fresh divergence.
		// is_space_banned() covers BOTH surfaces (bn_space_bans + the
		// status = 'banned' membership row), so it is the single check here.
		if ( buddynext_service( 'permissions' )->is_space_banned( $new_owner_id, $space_id ) ) {
			return new WP_Error( 'heir_banned', __( 'A banned user cannot be made the owner of this space.', 'buddynext' ), array( 'status' => 409 ) );
		}

		$previous_owner_id = (int) ( $space['owner_id'] ?? 0 );
		$spaces_table      = $wpdb->prefix . 'bn_spaces';

		// The heir's row BEFORE W1: it decides the no-op test below, the
		// member_count delta, and it is the undo state if a later write fails on
		// the non-transactional path.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$heir_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT role, status FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$space_id,
				$new_owner_id
			),
			ARRAY_A
		);

		$heir_was_active = is_array( $heir_row ) && 'active' === $heir_row['status'];

		// Every user holding an owner row BEFORE the writes. Its size decides the
		// no-op test below; the users in it other than the heir are the ones W2
		// demotes, so their role caches must be flushed too.
		$owner_row_user_ids = $this->owner_row_user_ids( $space_id );
		$owner_row_count    = count( $owner_row_user_ids );

		// NO-OP — but only for a space that is genuinely CONVERGED on the heir.
		// A matching owner_id alone proves nothing: the pointer can name a user
		// who holds no owner row (the divergence the old transfer_ownership()
		// produced), or a stray second owner row can survive alongside theirs.
		// Certifying either of those as healthy would make the CLI repair sweep
		// a no-op against exactly the spaces it exists to fix. So the fast path
		// demands all three: the pointer names the heir, the heir holds an
		// active owner row, and it is the ONLY owner row. Anything else falls
		// through to the write path, which is idempotent (W1 upserts, W2 is a
		// no-op when there is nothing to demote, W3 rewrites the same pointer).
		if ( $previous_owner_id === $new_owner_id
			&& is_array( $heir_row )
			&& 'owner' === $heir_row['role']
			&& 'active' === $heir_row['status']
			&& 1 === $owner_row_count ) {
			return true;
		}

		/**
		 * Filters whether an ownership write runs inside an explicit SQL transaction.
		 *
		 * Defaults to true everywhere EXCEPT the WordPress test suite, which
		 * already wraps each test in its own transaction that a nested
		 * `START TRANSACTION` would implicitly COMMIT — leaking the test's rows
		 * into the database. When this returns false the method falls back to
		 * short-circuiting plus compensating undo (see the method docblock).
		 *
		 * @param bool   $use_transaction Whether to open a transaction.
		 * @param string $operation       The ownership operation being performed.
		 */
		$use_txn = (bool) apply_filters( 'buddynext_space_use_db_transaction', ! defined( 'WP_TESTS_DOMAIN' ), 'assign_owner' );

		// If the transaction cannot be opened, DO NOT keep believing in it: a
		// $use_txn of true also disables the compensating-undo path, so trusting
		// a transaction that never started would leave the writes with neither
		// safety net. MySQL also accepts START TRANSACTION / COMMIT / ROLLBACK
		// as silent no-ops on a non-transactional storage engine, which our
		// tables can land on — Installer::schema() specifies no ENGINE clause —
		// but that failure is invisible here; the explicit false below is the
		// case we CAN see, and degrading on it costs nothing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $use_txn && false === $wpdb->query( 'START TRANSACTION' ) ) {
			$use_txn = false;
		}

		// W1. The heir becomes an active owner (UPSERT — covers member, moderator, or non-member).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$promoted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_space_members ( space_id, user_id, role, status, joined_at )
				 VALUES ( %d, %d, 'owner', 'active', %s )
				 ON DUPLICATE KEY UPDATE role = 'owner', status = 'active'",
				$space_id,
				$new_owner_id,
				current_time( 'mysql' )
			)
		);

		// Nothing else has been written yet, so there is nothing to undo — but
		// bail NOW, before W2 demotes the incumbent and W3 points the space at a
		// user who was never promoted.
		if ( false === $promoted ) {
			return $this->abort_owner_transfer( $space, $new_owner_id, $previous_owner_id, $use_txn, false, $heir_row, false, $owner_row_user_ids );
		}

		// W2. EVERY other owner row is demoted — not just the one the pointer
		// named. Demoting only the believed incumbent leaves two owners whenever
		// the space was already divergent (a stray owner row), and loses a race:
		// two concurrent transfers A->B and A->C both read prev = A, T1 demotes
		// A, T2 matches zero rows, and B and C both end up holding role='owner'.
		// A `user_id <> heir` sweep is self-healing in both cases. Zero rows
		// affected is not a failure — a solo owner's row may already be gone
		// (deleted user), and the sweep is a no-op on an already-clean space.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$demoted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_space_members SET role = 'member' WHERE space_id = %d AND role = 'owner' AND user_id <> %d",
				$space_id,
				$new_owner_id
			)
		);

		if ( false === $demoted ) {
			return $this->abort_owner_transfer( $space, $new_owner_id, $previous_owner_id, $use_txn, true, $heir_row, false, $owner_row_user_ids );
		}

		// $demoted IS the affected-row count wpdb::query() returned; reading
		// $wpdb->rows_affected instead would break the moment another query slips
		// in between.
		$demoted_rows = (int) $demoted;

		// W3. The denormalised pointer moves LAST.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pointer = $wpdb->update(
			$spaces_table,
			array( 'owner_id' => $new_owner_id ),
			array( 'id' => $space_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $pointer ) {
			return $this->abort_owner_transfer( $space, $new_owner_id, $previous_owner_id, $use_txn, true, $heir_row, $demoted_rows > 0, $owner_row_user_ids );
		}

		if ( $use_txn ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
		}

		if ( ! $heir_was_active ) {
			( new SpaceMemberService() )->adjust_member_count( $space_id, 1 );
		}

		// W2 may have demoted owner rows beyond the incumbent, so every user who
		// held one is flushed, not just the two the pointer knows about.
		$this->flush_ownership_caches( $space, array_merge( array( $new_owner_id, $previous_owner_id ), $owner_row_user_ids ) );

		/**
		 * Fires after a space's ownership moves, however it moved.
		 *
		 * @param int      $space_id          Space whose ownership moved.
		 * @param int      $new_owner_id      The new owner.
		 * @param int|null $actor_id          Actor, or null for a system reassignment.
		 * @param int      $previous_owner_id The outgoing owner (0 if none).
		 */
		do_action( 'buddynext_space_ownership_transferred', $space_id, $new_owner_id, $actor_id, $previous_owner_id );

		return true;
	}

	/**
	 * Unwind a failed ownership transfer and report it.
	 *
	 * On the transactional path a single ROLLBACK discards whatever W1..W3
	 * managed to write. When the transaction was skipped (the test suite, or a
	 * site that filtered it off) there is nothing to roll back, so each write
	 * that DID land is undone by hand, in reverse order: the incumbent is
	 * re-promoted, then the heir's row is restored to exactly what it was — or
	 * deleted outright, if they had no row before W1 inserted one.
	 *
	 * The hand-undo restores the INCUMBENT only. W2 sweeps away every owner row
	 * that is not the heir's, so it can also have demoted a stray owner row —
	 * one the space should never have had. Those are not resurrected: putting
	 * known divergence back is not an undo worth having, and the caller sees a
	 * WP_Error either way.
	 *
	 * Either way the caches are busted before returning, so a partial write can
	 * never outlive itself in a stale role cache.
	 *
	 * @param array      $space              The space row as read before the writes.
	 * @param int        $new_owner_id       The heir W1 tried to promote.
	 * @param int        $previous_owner_id  The incumbent W2 tried to demote (0 if none).
	 * @param bool       $used_transaction   Whether the writes ran inside a transaction.
	 * @param bool       $undo_promotion     Whether W1 actually landed. False when W1 is
	 *                                       itself the write that failed — nothing was
	 *                                       written, so the heir's row must be left alone.
	 * @param array|null $heir_row           The heir's `role`/`status` as read BEFORE W1,
	 *                                       or null when they had no membership row at all
	 *                                       (so undoing W1 means deleting the row it
	 *                                       inserted). Only consulted when $undo_promotion.
	 * @param bool       $undo_demotion      Whether W2 actually demoted a row.
	 * @param array      $owner_row_user_ids Users who held an owner row BEFORE the writes;
	 *                                       flushed alongside the heir and the incumbent so
	 *                                       a swept-away stray leaves no stale role cache.
	 * @return WP_Error Always — this is the failure path.
	 */
	private function abort_owner_transfer( array $space, int $new_owner_id, int $previous_owner_id, bool $used_transaction, bool $undo_promotion, ?array $heir_row, bool $undo_demotion, array $owner_row_user_ids = array() ): WP_Error {
		global $wpdb;

		$space_id      = (int) $space['id'];
		$members_table = $wpdb->prefix . 'bn_space_members';

		if ( $used_transaction ) {
			// One ROLLBACK discards whatever W1..W3 managed to write.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
		} else {
			// No transaction to roll back: undo by hand, in reverse write order.
			if ( $undo_demotion && $previous_owner_id > 0 && $previous_owner_id !== $new_owner_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}bn_space_members SET role = 'owner' WHERE space_id = %d AND user_id = %d",
						$space_id,
						$previous_owner_id
					)
				);
			}

			if ( $undo_promotion ) {
				if ( is_array( $heir_row ) ) {
					// The heir already had a row: put its role and status back exactly.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$members_table,
						array(
							'role'   => (string) $heir_row['role'],
							'status' => (string) $heir_row['status'],
						),
						array(
							'space_id' => $space_id,
							'user_id'  => $new_owner_id,
						),
						array( '%s', '%s' ),
						array( '%d', '%d' )
					);
				} else {
					// The heir had no row at all — W1's INSERT is undone by deleting it.
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->delete(
						$members_table,
						array(
							'space_id' => $space_id,
							'user_id'  => $new_owner_id,
						),
						array( '%d', '%d' )
					);
				}
			}
		}

		$this->flush_ownership_caches( $space, array_merge( array( $new_owner_id, $previous_owner_id ), $owner_row_user_ids ) );

		return new WP_Error( 'assign_owner_failed', __( 'Could not transfer ownership.', 'buddynext' ), array( 'status' => 500 ) );
	}

	/**
	 * Bust every cache an ownership write invalidates.
	 *
	 * Ownership writes hit bn_space_members directly rather than going through
	 * SpaceMemberService's mutators, so that service's per-user role/status
	 * caches must be flushed by hand — otherwise a later get_role() for either
	 * user returns a stale value. Called on the success path AND on every
	 * failure path, since a failed-and-compensated write still touched rows.
	 *
	 * @param array $space    The space row (needs `id`, and `slug` if cached).
	 * @param array $user_ids Users whose membership caches to flush; zeroes are ignored.
	 * @return void
	 */
	private function flush_ownership_caches( array $space, array $user_ids ): void {
		$space_id = (int) $space['id'];

		( new SpaceMemberService() )->flush_user_caches( $space_id, array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) ) );

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );
		if ( ! empty( $space['slug'] ) ) {
			wp_cache_delete( 'space_slug_' . $space['slug'], self::CACHE_GROUP );
		}
	}

	/**
	 * List every user currently holding a `role = 'owner'` row in a space.
	 *
	 * The assign_owner() path reads this BEFORE it writes, for two reasons: a healthy
	 * space has exactly one such row (so anything else means the space is
	 * divergent and the no-op fast path must not be taken), and every user in
	 * the list other than the heir is one W2 demotes — so their role caches have
	 * to be flushed. Deliberately unfiltered by status: a `banned` owner row is
	 * divergence too, and it must be counted and swept like any other.
	 *
	 * Read uncached, straight from the table: it is the divergence check itself,
	 * and a cached answer is exactly the thing it cannot trust.
	 *
	 * @param int $space_id Space to inspect.
	 * @return int[] User ids holding an owner row, ascending. Empty when the
	 *               space has no owner row at all.
	 */
	private function owner_row_user_ids( int $space_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND role = 'owner' ORDER BY user_id ASC",
				$space_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete a space.
	 *
	 * Only the owner (or manage_options) may delete. Also removes all
	 * member rows for this space.
	 *
	 * @param int $space_id Space to delete.
	 * @param int $user_id  User requesting the deletion.
	 * @return true|WP_Error
	 */
	public function delete( int $space_id, int $user_id ): bool|WP_Error {
		$space = $this->get( $space_id );

		if ( null === $space ) {
			return new WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ) );
		}

		if ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete this space.', 'buddynext' ) );
		}

		global $wpdb;

		// Re-home any direct sub-spaces to the top level before this space is torn
		// down. Spaces are shared community data (their own members, posts and
		// sub-tree) that must survive a parent's deletion — leaving them pointing
		// at a now-deleted parent_id would orphan them out of the directory and
		// every get_subspaces() query. Detaching to root (parent_id = NULL) keeps
		// each child and its own sub-tree intact as an independent top-level space,
		// mirroring how update() detaches a space.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$child_spaces = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, slug FROM {$wpdb->prefix}bn_spaces WHERE parent_id = %d", $space_id ) );
		if ( $child_spaces ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_spaces',
				array( 'parent_id' => null ),
				array( 'parent_id' => $space_id ),
				array( '%d' ),
				array( '%d' )
			);
			foreach ( $child_spaces as $child ) {
				$child_id = (int) $child->id;
				wp_cache_delete( "space_{$child_id}", self::CACHE_GROUP );
				if ( ! empty( $child->slug ) ) {
					wp_cache_delete( 'space_slug_' . $child->slug, self::CACHE_GROUP );
				}

				/** This action is documented in includes/Spaces/SpaceService.php */
				do_action( 'buddynext_space_updated', $child_id, $user_id, array( 'parent_id' => null ) );
			}
		}

		// Capture every user whose membership / ban cache must be flushed once the
		// space and its rows are gone — the bulk deletes below fire no per-user
		// hooks, so cached role / status / ban entries would otherwise survive the
		// space (the BUG-1 invalidation gap on bn_space_bans + bn_space_members).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected_user_ids = array_values(
			array_unique(
				array_map(
					'intval',
					array_merge(
						(array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d", $space_id ) ),
						(array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_space_bans WHERE space_id = %d", $space_id ) )
					)
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Delete the space's posts first — while membership still exists, so the
		// deleter's space-moderation authority resolves in PostService::delete().
		// Routing each post through PostService::delete() cascades its child rows
		// (reactions, comments, bookmarks, shares, hashtags, poll data, feed
		// items, reports) instead of orphaning them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE space_id = %d", $space_id ) );

		if ( $post_ids ) {
			$post_service = buddynext_service( 'post_service' );
			foreach ( $post_ids as $post_id ) {
				$post_service->delete( (int) $post_id, $user_id );
			}
		}

		// Remove the moderation rows that reference this space directly so they
		// are not left pointing at a space that no longer exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_space_bans', array( 'space_id' => $space_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_reports', array( 'space_id' => $space_id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_mod_log', array( 'space_id' => $space_id ), array( '%d' ) );

		// Remove all members.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array( 'space_id' => $space_id ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_spaces',
			array( 'id' => $space_id ),
			array( '%d' )
		);

		// Remove this space's metadata. The built-in settings and any
		// developer-registered space fields all live in bn_space_meta now, so a
		// single delete clears them — no orphaned rows after the space is gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->bn_spacemeta, array( 'bn_space_id' => $space_id ), array( '%d' ) );

		// Back-compat: an integration that still keeps its own per-space option can
		// register its suffix here so a deleted space leaves nothing behind. Core
		// no longer stores any option this way (default is empty).
		$bn_space_option_suffixes = (array) apply_filters( 'buddynext_space_option_suffixes', array() );
		foreach ( $bn_space_option_suffixes as $bn_suffix ) {
			delete_option( 'bn_space_' . $space_id . '_' . sanitize_key( (string) $bn_suffix ) );
		}

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );
		if ( isset( $space['slug'] ) && '' !== $space['slug'] ) {
			wp_cache_delete( "space_slug_{$space['slug']}", self::CACHE_GROUP );
		}

		// Flush the membership / ban cache for everyone who was in (or banned from)
		// the now-deleted space, so no stale role / status / ban entry survives it.
		if ( $affected_user_ids ) {
			( new SpaceMemberService() )->flush_user_caches( $space_id, $affected_user_ids );
		}

		/**
		 * Fires after a space is deleted.
		 *
		 * @param int $space_id Deleted space ID.
		 * @param int $user_id  User who deleted it.
		 */
		do_action( 'buddynext_space_deleted', $space_id, $user_id );

		/**
		 * Fires so add-ons can purge their own per-space data on deletion — the
		 * space-side parity of buddynext_purge_user_data (the member-purge cleanup
		 * hook). Kept separate from buddynext_space_deleted so cleanup listeners
		 * have a dedicated, clearly-named hook.
		 *
		 * @param int $space_id Deleted space ID.
		 * @param int $user_id  User who deleted it.
		 */
		do_action( 'buddynext_purge_space_data', $space_id, $user_id );

		return true;
	}

	/**
	 * Return a paginated list of spaces.
	 *
	 * Supported args:
	 *   per_page    int     Spaces per page. Default 12. Max 100.
	 *   page        int     1-based page number. Default 1.
	 *   type        string  Filter by type ('open', 'private', 'secret'). Default: exclude secret.
	 *   category_id int     Filter by category. Default: no filter.
	 *   member      int     Filter to spaces the given user_id is an active member of. When set,
	 *                       the secret-type exclusion is lifted so the user can see their own
	 *                       secret spaces.
	 *   orderby     string  'member_count' | 'name' | 'created_at'. Default 'member_count'.
	 *   order       string  'ASC' | 'DESC'. Default 'DESC'.
	 *   viewer      int     Viewer user ID (0 = logged out). Drives secret + archived scope.
	 *   is_admin    bool    Site admin — sees secret and archived spaces.
	 *
	 * Archived spaces are excluded for everyone except the space's owner and site
	 * admins (see archive_scope()).
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array[]
	 */
	public function list_spaces( array $args = array() ): array {
		global $wpdb;

		$scope           = $this->list_query_scope( $args );
		$member_id       = $scope['member_id'];
		$member_role_sql = $scope['member_role_sql'];
		$where_sql       = $scope['where_sql'];
		$orderby         = $scope['orderby'];
		$order           = $scope['order'];
		$per_page        = $scope['per_page'];
		$offset          = $scope['offset'];

		$params   = $scope['params'];
		$params[] = $per_page;
		$params[] = $offset;

		// $where_sql contains only hardcoded strings or validated enum values.
		// $orderby is validated against an allowlist; $order is either 'ASC' or 'DESC'.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( $member_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.*, sm.role AS viewer_role FROM {$wpdb->prefix}bn_spaces s INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active'{$member_role_sql} {$where_sql} ORDER BY s.{$orderby} {$order} LIMIT %d OFFSET %d",
					$member_id,
					...$params
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}bn_spaces {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
					...$params
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return a paginated list of spaces together with the total row count.
	 *
	 * Same args and visibility/WHERE semantics as {@see list_spaces()} (it reuses
	 * the identical scope builder), but also runs a matching COUNT(*) so callers
	 * can paginate. This lets the spaces directory drop its inline grid + count
	 * queries and read items + total from the service the REST controller uses.
	 *
	 * @param array<string, mixed> $args Query arguments (see list_spaces()).
	 * @return array{items: array[], total: int}
	 */
	public function list_spaces_with_total( array $args = array() ): array {
		global $wpdb;

		$scope           = $this->list_query_scope( $args );
		$member_id       = $scope['member_id'];
		$member_role_sql = $scope['member_role_sql'];
		$where_sql       = $scope['where_sql'];

		// $where_sql contains only hardcoded strings or validated enum values; the
		// embedded placeholders are bound through prepare() with $scope['params'].
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( $member_id > 0 ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces s INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active'{$member_role_sql} {$where_sql}",
					$member_id,
					...$scope['params']
				)
			);
		} elseif ( $scope['params'] ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces {$where_sql}",
					...$scope['params']
				)
			);
		} else {
			// No bound params, but $where_sql may still carry a param-less predicate
			// (e.g. roots_only's `parent_id IS NULL`). It MUST be included here or the
			// count ignores the filter and returns every row — inflating the directory
			// total by sub-spaces/unlisted rows the grid never shows. The earlier
			// version omitted $where_sql on this branch, which was the bug. No prepare()
			// since there are no placeholders to bind.
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces {$where_sql}" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array(
			'items' => $this->list_spaces( $args ),
			'total' => $total,
		);
	}

	/**
	 * Build the shared WHERE / visibility scope for list_spaces() and
	 * list_spaces_with_total() so the directory and its count never drift.
	 *
	 * Returns the prepared WHERE fragment (placeholders only — never user input),
	 * its bound params, the validated orderby/order, and the resolved pagination.
	 *
	 * @param array<string, mixed> $args Query arguments (see list_spaces()).
	 * @return array{where_sql: string, params: array<int, mixed>, orderby: string, order: string, per_page: int, offset: int, member_id: int, member_role_sql: string}
	 */
	private function list_query_scope( array $args ): array {
		global $wpdb;

		$per_page    = max( 1, min( 100, absint( $args['per_page'] ?? 12 ) ) );
		$page        = max( 1, absint( $args['page'] ?? 1 ) );
		$offset      = ( $page - 1 ) * $per_page;
		$type        = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		$category_id = isset( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		$member_id   = isset( $args['member'] ) ? absint( $args['member'] ) : 0;
		$viewer_id   = isset( $args['viewer'] ) ? absint( $args['viewer'] ) : 0;
		$is_admin    = ! empty( $args['is_admin'] );

		// Member-scoped lists ("my spaces") can be narrowed to the viewer's
		// relationship: 'manage' = spaces they own or moderate, 'joined' = plain
		// membership. The fragment extends the membership JOIN in list_spaces*().
		// Role values are hardcoded enums, so they are safe to interpolate.
		$member_role     = $member_id > 0 && isset( $args['member_role'] ) ? (string) $args['member_role'] : '';
		$member_role_sql = '';
		if ( 'manage' === $member_role ) {
			$member_role_sql = " AND sm.role IN ( 'owner', 'moderator' )";
		} elseif ( 'joined' === $member_role ) {
			$member_role_sql = " AND sm.role = 'member'";
		}

		$allowed_orderby = array( 'member_count', 'name', 'created_at' );
		$raw_orderby     = isset( $args['orderby'] ) ? (string) $args['orderby'] : 'member_count';
		$orderby         = in_array( $raw_orderby, $allowed_orderby, true ) ? $raw_orderby : 'member_count';
		$order           = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$params = array();
		$where  = array();

		// Visibility scope for unlisted (secret-equivalent) spaces. Site admins
		// see all; other viewers see only secret spaces they own or actively
		// belong to. Owner/membership rows live in bn_space_members; ownership is
		// also denormalised onto bn_spaces.owner_id. Mirrors directory.php.
		// The unprefixed branch below selects from bn_spaces with no alias, so
		// the subquery references bare column names.
		$members_table = $wpdb->prefix . 'bn_space_members';

		if ( '' !== $type ) {
			$where[]  = 'type = %s';
			$params[] = $type;

			// Secret-type chip: when not a site admin, restrict to the viewer's
			// own spaces so others' secret spaces stay hidden.
			if ( ! $is_admin && ! SpaceTypeRegistry::instance()->is_listed( $type ) && $member_id <= 0 ) {
				if ( $viewer_id > 0 ) {
					$where[]  = '( ' . $this->owned_or_member_subquery( $members_table ) . ' )';
					$params[] = $viewer_id;
					$params[] = $viewer_id;
				} else {
					// No viewer context: no secret spaces are visible.
					$where[] = '1=0';
				}
			}
		} elseif ( 0 === $member_id ) {
			// Exclude unlisted (secret-equivalent) types from the public
			// directory, EXCEPT the viewer's own (owned or active membership).
			// Site admins skip the exclusion entirely.
			$unlisted = SpaceTypeRegistry::instance()->unlisted_keys();
			if ( $unlisted && ! $is_admin ) {
				// Slugs are sanitize_key()'d in the registry, so safe to interpolate.
				$placeholders = implode( ', ', array_map( static fn( $t ) => "'" . $t . "'", $unlisted ) );
				if ( $viewer_id > 0 ) {
					$where[]  = "( type NOT IN ( {$placeholders} ) OR " . $this->owned_or_member_subquery( $members_table ) . ' )';
					$params[] = $viewer_id;
					$params[] = $viewer_id;
				} else {
					$where[] = "type NOT IN ( {$placeholders} )";
				}
			}
		}

		// Archived spaces leave every listing. Archive is the ONLY non-destructive
		// way to retire a space; while archived rows kept showing in the directory,
		// owners concluded archive was broken and reached for Delete — a hard
		// cascade that destroys every post in the space. The predicate lives IN THE
		// QUERY (never a post-fetch PHP filter) and mirrors subspace_visibility_filter(),
		// which already excluded archived children.
		list( $archive_where, $archive_params ) = $this->archive_scope( $viewer_id, $is_admin );
		foreach ( $archive_where as $archive_clause ) {
			$where[] = $archive_clause;
		}
		foreach ( $archive_params as $archive_param ) {
			$params[] = $archive_param;
		}

		if ( $category_id > 0 ) {
			$where[]  = 'category_id = %d';
			$params[] = $category_id;
		}

		// Top-level directory browse shows root spaces only — sub-spaces are
		// discovered from their parent, so 50k spaces never flatten into one grid.
		// Member-scoped lists ("my spaces") and search are unaffected.
		if ( ! empty( $args['roots_only'] ) ) {
			$where[] = 'parent_id IS NULL';
		}

		// Bound the result to / exclude an explicit id set — used by the suggestion
		// engine (hydrate ranked candidates, visibility-safe) and "spaces I'm not in"
		// views. Ids are integer-cast, so the inlined IN lists are injection-safe.
		if ( ! empty( $args['include_space_ids'] ) ) {
			$bn_inc = implode( ',', array_map( 'absint', (array) $args['include_space_ids'] ) );
			if ( '' !== $bn_inc ) {
				$where[] = "id IN ({$bn_inc})";
			}
		}
		if ( ! empty( $args['exclude_space_ids'] ) ) {
			$bn_exc = implode( ',', array_map( 'absint', (array) $args['exclude_space_ids'] ) );
			if ( '' !== $bn_exc ) {
				$where[] = "id NOT IN ({$bn_exc})";
			}
		}

		return array(
			'where_sql'       => $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '',
			'params'          => $params,
			'orderby'         => $orderby,
			'order'           => $order,
			'per_page'        => $per_page,
			'offset'          => $offset,
			'member_id'       => $member_id,
			'member_role_sql' => $member_role_sql,
		);
	}

	/**
	 * Search spaces by name or description.
	 *
	 * Secret spaces are excluded from search results, EXCEPT the viewer's own
	 * (owned or active membership). Site admins (is_admin arg) see all matches.
	 * Archived spaces are excluded for everyone except their owner and site admins,
	 * so a retired space cannot be found here after it left the directory.
	 *
	 * @param string               $term Search term.
	 * @param array<string, mixed> $args Optional per_page / page / viewer / is_admin.
	 * @return array[]
	 */
	public function search( string $term, array $args = array() ): array {
		global $wpdb;

		$like      = '%' . $wpdb->esc_like( $term ) . '%';
		$per_page  = max( 1, min( 100, absint( $args['per_page'] ?? 12 ) ) );
		$page      = max( 1, absint( $args['page'] ?? 1 ) );
		$offset    = ( $page - 1 ) * $per_page;
		$viewer_id = isset( $args['viewer'] ) ? absint( $args['viewer'] ) : 0;
		$is_admin  = ! empty( $args['is_admin'] );
		$member_id = isset( $args['member'] ) ? absint( $args['member'] ) : 0;

		$params = array();

		// "My Spaces" scope — restrict to spaces the searcher owns or actively
		// belongs to (mirrors list_spaces()'s `member` arg) so search composes
		// with the directory's My Spaces filter.
		$mine_sql = '1=1';
		if ( $member_id > 0 ) {
			$mine_members = $wpdb->prefix . 'bn_space_members';
			$mine_sql     = '( ' . $this->owned_or_member_subquery( $mine_members ) . ' )';
		}

		// Exclude unlisted (secret-equivalent) types from search. Slugs are
		// sanitize_key()'d in the registry, so the IN list is safe to interpolate.
		// Site admins see all; other viewers also see secret spaces they own or
		// actively belong to. Mirrors list_spaces() / directory.php.
		$unlisted = SpaceTypeRegistry::instance()->unlisted_keys();
		if ( ! $unlisted || $is_admin ) {
			$exclude_sql = '1=1';
		} else {
			$placeholders  = implode( ', ', array_map( static fn( $t ) => "'" . $t . "'", $unlisted ) );
			$members_table = $wpdb->prefix . 'bn_space_members';
			if ( $viewer_id > 0 ) {
				$exclude_sql = "( type NOT IN ( {$placeholders} ) OR " . $this->owned_or_member_subquery( $members_table ) . ' )';
				$params[]    = $viewer_id;
				$params[]    = $viewer_id;
			} else {
				$exclude_sql = "type NOT IN ( {$placeholders} )";
			}
		}

		// Archived spaces are retired — they leave search exactly as they leave the
		// directory (owner + site admin excepted). Same predicate, one definition.
		list( $archive_where, $archive_params ) = $this->archive_scope( $viewer_id, $is_admin );
		$archive_sql                            = $archive_where ? implode( ' AND ', $archive_where ) : '1=1';
		foreach ( $archive_params as $archive_param ) {
			$params[] = $archive_param;
		}

		// Mine-scope placeholders follow the exclude- and archive-scope ones, before the LIKEs.
		if ( $member_id > 0 ) {
			$params[] = $member_id;
			$params[] = $member_id;
		}

		$params[] = $like;
		$params[] = $like;
		$params[] = $per_page;
		$params[] = $offset;

		// $exclude_sql / $archive_sql / $mine_sql hold only literal SQL plus %d
		// placeholders bound through $params; their placeholder count is dynamic, so
		// the analyser reports ReplacementsWrongNumber here even though the binding
		// is correct.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces
				 WHERE {$exclude_sql} AND {$archive_sql} AND {$mine_sql} AND (name LIKE %s OR description LIKE %s)
				 ORDER BY member_count DESC
				 LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return the top post contributors in a space.
	 *
	 * Counts published posts per author in the space (scheduled posts whose time
	 * has not arrived are excluded), highest first. Joined to wp_users so callers
	 * render a name without a second lookup. Mirrors the inline query the space
	 * home sidebar used.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Max contributors to return. Default 3. Capped at 50.
	 * @return array[] Each item: user_id, display_name, post_count.
	 */
	public function top_contributors( int $space_id, int $limit = 3 ): array {
		$space_id = absint( $space_id );
		$limit    = max( 1, min( 50, $limit ) );

		if ( $space_id <= 0 ) {
			return array();
		}

		// This is a GROUP BY over EVERY published post in the space, and it ran on every
		// single render of the space home sidebar — uncached, to show three names. At
		// 100k it is a table scan per page view.
		//
		// remember_persistent(), not remember(): the target deployment has no persistent
		// object cache, so a plain wp_cache would be discarded at the end of the request
		// and the scan would still run every time. See CacheService::remember_persistent.
		//
		// ONE key per space, holding the top MAX_TOP_CONTRIBUTORS, sliced in PHP for the
		// caller's limit. Keying by limit as well would mean invalidation had to delete
		// every limit that might exist — 50 transient DELETEs on every post created in
		// the space, which trades a read problem for a write problem.
		$all = (array) buddynext_service( 'cache' )->remember_persistent(
			self::top_contributors_key( $space_id ),
			15 * MINUTE_IN_SECONDS,
			static function () use ( $space_id ) {
				global $wpdb;

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT p.user_id, u.display_name, COUNT(*) AS post_count
						 FROM {$wpdb->prefix}bn_posts p
						 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
						 WHERE p.space_id = %d AND p.status = 'published'
						   AND ( p.scheduled_at IS NULL OR p.scheduled_at <= UTC_TIMESTAMP() )
						 GROUP BY p.user_id, u.display_name
						 ORDER BY post_count DESC
						 LIMIT %d",
						$space_id,
						self::MAX_TOP_CONTRIBUTORS
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				return array_map(
					static fn( $r ) => array(
						'user_id'      => (int) $r['user_id'],
						'display_name' => (string) $r['display_name'],
						'post_count'   => (int) $r['post_count'],
					),
					(array) $rows
				);
			}
		);

		return array_slice( $all, 0, $limit );
	}

	/**
	 * Cache key for a space's top-contributor list. One key per space — see the note in
	 * top_contributors() on why it is NOT keyed by limit.
	 *
	 * @param int $space_id Space ID.
	 * @return string
	 */
	private static function top_contributors_key( int $space_id ): string {
		return "space_topcontrib_{$space_id}";
	}

	/**
	 * Drop a space's cached top-contributor list.
	 *
	 * Called when a post is created or removed in the space — the only two events that
	 * can change who is on the list, or in what order.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public static function bust_top_contributors( int $space_id ): void {
		$space_id = absint( $space_id );
		if ( $space_id > 0 ) {
			buddynext_service( 'cache' )->forget_persistent( self::top_contributors_key( $space_id ) );
		}
	}

	/**
	 * Return the root (non-sub) spaces a user owns, hydrated.
	 *
	 * Root spaces are those with no parent_id, so a settings screen can offer
	 * them as parents for a new sub-space without nesting beyond two levels.
	 * Ordered by name for a stable picker.
	 *
	 * @param int $user_id Owner user ID.
	 * @return array[] Hydrated space rows.
	 */
	public function owned_root_spaces( int $user_id ): array {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces
				 WHERE owner_id = %d AND parent_id IS NULL
				 ORDER BY name ASC",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return a parent space's sub-spaces, most-active first.
	 *
	 * Index-backed by KEY parent (parent_id). Bounded by $limit (1-50). A secret
	 * child is hidden from a viewer who is neither a site admin nor an active
	 * member/owner of that child — mirrors the directory's secret-scope so a
	 * sub-space is exactly as discoverable as a top-level space of the same type.
	 *
	 * @param int  $parent_id Parent space ID.
	 * @param int  $limit     Max rows (clamped 1-50).
	 * @param int  $offset    Row offset.
	 * @param int  $viewer_id Viewer ID for visibility scoping (0 = logged out).
	 * @param bool $is_admin  Site admin sees every child.
	 * @return array[] Hydrated sub-space rows.
	 */
	public function get_subspaces( int $parent_id, int $limit = 24, int $offset = 0, int $viewer_id = 0, bool $is_admin = false ): array {
		global $wpdb;

		$parent_id = absint( $parent_id );
		if ( $parent_id <= 0 ) {
			return array();
		}

		$limit  = max( 1, min( 50, $limit ) );
		$offset = max( 0, $offset );

		list( $where, $params ) = $this->subspace_visibility_where( $parent_id, $viewer_id, $is_admin );
		$where_sql              = 'WHERE ' . implode( ' AND ', $where );
		$params[]               = $limit;
		$params[]               = $offset;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces {$where_sql} ORDER BY member_count DESC, name ASC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * The "viewer owns it, or is an active member of it" visibility sub-predicate.
	 *
	 * This `owner_id = %d OR id IN ( active-membership )` fragment is the heart of
	 * every secret/unlisted-space visibility check — it was copied verbatim across
	 * the directory list, search, and sub-space queries. Centralised here so the
	 * security predicate has a single definition and can never drift between the
	 * surfaces that must agree. Returns SQL carrying two %d placeholders (both
	 * bound to the viewer ID, in order); callers wrap it and push the two params.
	 *
	 * @param string $members_table Fully-qualified bn_space_members table name.
	 * @return string
	 */
	private function owned_or_member_subquery( string $members_table ): string {
		return "owner_id = %d OR id IN ( SELECT space_id FROM {$members_table} WHERE user_id = %d AND status = 'active' )";
	}

	/**
	 * Build the shared "archived spaces are retired" predicate for the listings.
	 *
	 * An archived space drops out of the directory, the search results, and the
	 * suggestion engine — that is what archiving MEANS. The two people who must
	 * still find it are the ones who can bring it back: its owner (who archived
	 * it) and a site admin. Everyone else, including its members, sees a retired
	 * space only by URL.
	 *
	 * One definition, used by list_query_scope() (directory list + total) and
	 * search(), so the grid, its count, and search can never drift apart. Returns
	 * SQL fragments carrying %d placeholders; callers append the params in order.
	 *
	 * @param int  $viewer_id Viewer ID (0 = logged out).
	 * @param bool $is_admin  Site admin sees archived spaces everywhere.
	 * @return array{0: string[], 1: array<int, mixed>} [ where-clauses, prepared params ].
	 */
	private function archive_scope( int $viewer_id, bool $is_admin ): array {
		if ( $is_admin ) {
			return array( array(), array() );
		}

		if ( $viewer_id > 0 ) {
			return array( array( '( is_archived = 0 OR owner_id = %d )' ), array( $viewer_id ) );
		}

		return array( array( 'is_archived = 0' ), array() );
	}

	/**
	 * Build the shared visibility-scoped WHERE for a parent's sub-spaces.
	 *
	 * One source of truth so get_subspaces() (the visible LIST) and
	 * count_visible_subspaces() (the displayed COUNT) can never diverge — a member must
	 * never see a sub-space total that includes secret/unlisted children they can't open.
	 * Non-admins see a child only when its type is listed, OR they own it, OR they are an
	 * active member of it.
	 *
	 * @param int  $parent_id Parent space ID (already absint-ed by the caller).
	 * @param int  $viewer_id Viewer ID (0 = logged out).
	 * @param bool $is_admin  Site admin sees every child.
	 * @return array{0: string[], 1: array<int, mixed>} [ where-clauses, prepared params ].
	 */
	private function subspace_visibility_where( int $parent_id, int $viewer_id, bool $is_admin ): array {
		list( $where, $params ) = $this->subspace_visibility_filter( $viewer_id, $is_admin );
		array_unshift( $where, 'parent_id = %d' );
		array_unshift( $params, $parent_id );

		return array( $where, $params );
	}

	/**
	 * The viewer-scoped, parent-independent visibility filter for sub-spaces.
	 *
	 * Just the "this child is visible to the viewer" half of subspace_visibility_where()
	 * — is_archived plus the secret/unlisted scope (listed types, or the viewer owns/
	 * actively belongs to the child). Lifted out so the single-parent WHERE and the
	 * batched count_visible_subspaces_for() share ONE definition of the visibility
	 * boundary; a parent clause is prepended by each caller.
	 *
	 * @param int  $viewer_id Viewer ID (0 = logged out).
	 * @param bool $is_admin  Site admin sees every child.
	 * @return array{0: string[], 1: array<int, mixed>} [ where-clauses, prepared params ].
	 */
	private function subspace_visibility_filter( int $viewer_id, bool $is_admin ): array {
		global $wpdb;

		$where  = array( 'is_archived = 0' );
		$params = array();

		if ( ! $is_admin ) {
			$unlisted = SpaceTypeRegistry::instance()->unlisted_keys();
			if ( $unlisted ) {
				// Slugs are sanitize_key()'d in the registry, so safe to interpolate.
				$placeholders = implode( ', ', array_map( static fn( $t ) => "'" . $t . "'", $unlisted ) );
				if ( $viewer_id > 0 ) {
					$members_table = $wpdb->prefix . 'bn_space_members';
					$where[]       = "( type NOT IN ( {$placeholders} ) OR " . $this->owned_or_member_subquery( $members_table ) . ' )';
					$params[]      = $viewer_id;
					$params[]      = $viewer_id;
				} else {
					$where[] = "type NOT IN ( {$placeholders} )";
				}
			}
		}

		return array( $where, $params );
	}

	/**
	 * Count a parent's sub-spaces VISIBLE to a viewer (non-archived).
	 *
	 * The display-safe sibling of count_subspaces(): it applies the SAME visibility
	 * scope as get_subspaces(), so the "N sub-spaces" a member sees matches the list they
	 * can actually open — no secret-child leak in the count. count_subspaces() stays the
	 * unscoped structural count, correct for move/cap validation.
	 *
	 * @param int  $parent_id Parent space ID.
	 * @param int  $viewer_id Viewer ID (0 = logged out).
	 * @param bool $is_admin  Site admin counts every child.
	 * @return int
	 */
	public function count_visible_subspaces( int $parent_id, int $viewer_id = 0, bool $is_admin = false ): int {
		global $wpdb;

		$parent_id = absint( $parent_id );
		if ( $parent_id <= 0 ) {
			return 0;
		}

		list( $where, $params ) = $this->subspace_visibility_where( $parent_id, $viewer_id, $is_admin );
		$where_sql              = 'WHERE ' . implode( ' AND ', $where );

		// The %d placeholders live inside the interpolated $where_sql (parent + viewer),
		// so the query IS prepared — phpcs just can't see them in the literal string.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces {$where_sql}",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Visible sub-space counts for many parents in one query.
	 *
	 * The batched sibling of count_visible_subspaces(), for a directory page that
	 * shows a "N sub-spaces" chip per card: counting per card would be an N+1. Applies
	 * the SAME visibility scope (so secret children never inflate a count the viewer
	 * can't open) across all parents, grouped by parent_id.
	 *
	 * @param int[] $parent_ids Parent space IDs on the page.
	 * @param int   $viewer_id  Viewer ID (0 = logged out).
	 * @param bool  $is_admin   Site admin counts every child.
	 * @return array<int, int> Parent-ID keyed count map; every requested parent is present (0 if none).
	 */
	public function count_visible_subspaces_for( array $parent_ids, int $viewer_id = 0, bool $is_admin = false ): array {
		global $wpdb;

		$parent_ids = array_values( array_unique( array_filter( array_map( 'intval', $parent_ids ) ) ) );
		if ( empty( $parent_ids ) ) {
			return array();
		}

		// Seed every requested parent to 0 so parents with no visible children are
		// still present in the map (a missing key would read as "unknown", not zero).
		$counts = array_fill_keys( $parent_ids, 0 );

		list( $where, $params ) = $this->subspace_visibility_filter( $viewer_id, $is_admin );
		$placeholders           = implode( ', ', array_fill( 0, count( $parent_ids ), '%d' ) );
		array_unshift( $where, "parent_id IN ( {$placeholders} )" );
		$params    = array_merge( $parent_ids, $params );
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		// The %d placeholders live inside the interpolated $where_sql (parent IN list
		// + viewer), so the query IS prepared — phpcs just can't see them in the literal.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT parent_id, COUNT(*) AS n FROM {$wpdb->prefix}bn_spaces {$where_sql} GROUP BY parent_id",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		foreach ( (array) $rows as $r ) {
			$counts[ (int) $r->parent_id ] = (int) $r->n;
		}

		return $counts;
	}

	/**
	 * Count a parent's active (non-archived) sub-spaces. Index-backed by parent.
	 *
	 * @param int $parent_id Parent space ID.
	 * @return int
	 */
	public function count_subspaces( int $parent_id ): int {
		global $wpdb;

		$parent_id = absint( $parent_id );
		if ( $parent_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE parent_id = %d AND is_archived = 0",
				$parent_id
			)
		);
	}

	/**
	 * A compact summary of a space's parent, for breadcrumb navigation.
	 *
	 * Reads the cached parent row (no extra join on list rows), returning just the
	 * fields a breadcrumb needs. Viewer-scoped: a secret/unlisted parent the viewer
	 * cannot see resolves to null so a child never leaks the parent's name + link.
	 *
	 * @param int $parent_id Parent space ID.
	 * @param int $viewer_id Viewer user ID (0 = anonymous) for the visibility scope.
	 * @return array{id:int,name:string,slug:string}|null Null when no/unseeable parent.
	 */
	public function parent_summary( int $parent_id, int $viewer_id = 0 ): ?array {
		$parent_id = absint( $parent_id );
		if ( $parent_id <= 0 ) {
			return null;
		}

		$parent = $this->get( $parent_id );
		if ( null === $parent ) {
			return null;
		}

		// Visibility scope: an unlisted (secret) parent is hidden from a viewer who
		// neither owns it, actively belongs to it, nor is a site admin — so an open
		// child nested under a secret parent never leaks the parent's name + link.
		$type = (string) ( $parent['type'] ?? 'open' );
		if ( ! SpaceTypeRegistry::instance()->is_listed( $type ) ) {
			$can_see = current_user_can( 'manage_options' )
				|| ( $viewer_id > 0
					&& ( (int) ( $parent['owner_id'] ?? 0 ) === $viewer_id
						|| ( new SpaceMemberService() )->is_member( $parent_id, $viewer_id ) ) );
			if ( ! $can_see ) {
				return null;
			}
		}

		return array(
			'id'   => (int) $parent['id'],
			'name' => (string) $parent['name'],
			'slug' => (string) $parent['slug'],
		);
	}

	/**
	 * The spaces a given space may be moved UNDER, for the actor.
	 *
	 * The move itself has been fully implemented and validated over REST for a while
	 * (depth, cycles, per-parent cap, manage permission) — there was simply no UI, so
	 * an owner could not promote a sub-space to the top level or re-parent it without
	 * making an API call. This is the list that control needs.
	 *
	 * Mirrors validate_parent_move() exactly, and deliberately so: the picker must not
	 * be able to offer a move the service would then reject. A candidate must be
	 *   - a ROOT space (nesting under a sub-space would be three levels deep),
	 *   - not the space itself,
	 *   - not archived,
	 *   - manageable by the actor (owner-only; a site admin manages everything),
	 *   - and below the per-parent cap, when one is set.
	 *
	 * Returns an empty list when sub-spaces are switched off for the community, or
	 * when the space has children of its own (it cannot be nested at all then — its
	 * children would end up three deep). Callers should read that empty list as
	 * "no move is possible", and say why.
	 *
	 * @param int $space_id The space being moved.
	 * @param int $user_id  Acting user.
	 * @return array<int,array{id:int,name:string}> Candidate parents, by name.
	 */
	public function eligible_parents( int $space_id, int $user_id ): array {
		$space_id = absint( $space_id );
		$user_id  = absint( $user_id );

		if ( $space_id <= 0 || $user_id <= 0 ) {
			return array();
		}

		if ( '0' === (string) get_option( 'buddynext_space_allow_sub', '1' ) ) {
			return array();
		}

		// A space that already has sub-spaces can only ever be a root.
		if ( $this->count_subspaces( $space_id ) > 0 ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded owner-scoped read on an indexed column, for one settings screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name FROM {$wpdb->prefix}bn_spaces
				 WHERE ( parent_id IS NULL OR parent_id = 0 )
				   AND id <> %d
				   AND is_archived = 0
				 ORDER BY name ASC",
				$space_id
			),
			ARRAY_A
		);

		$max_sub = (int) get_option( 'buddynext_space_max_sub_spaces', 0 );
		$out     = array();

		foreach ( (array) $rows as $row ) {
			$candidate = (int) $row['id'];

			// Owner-only, exactly as the service enforces on save.
			if ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $candidate ) ) ) {
				continue;
			}

			if ( $max_sub > 0 && $this->count_subspaces( $candidate ) >= $max_sub ) {
				continue;
			}

			$out[] = array(
				'id'   => $candidate,
				'name' => (string) $row['name'],
			);
		}

		return $out;
	}

	/**
	 * Validate a parent change for {@see update()} — move under a new parent, or
	 * detach to the top level.
	 *
	 * Returns the resolved parent id (int) to move, null to detach, or a WP_Error
	 * when the move would break the two-level depth rule, form a cycle, exceed the
	 * per-parent cap, or the actor cannot manage the target parent.
	 *
	 * @param int                 $space_id   The space being moved.
	 * @param array<string,mixed> $space      Hydrated row of the space being moved.
	 * @param int                 $user_id    Acting user.
	 * @param int                 $new_parent Requested parent id (0 = detach).
	 * @return int|null|WP_Error
	 */
	private function validate_parent_move( int $space_id, array $space, int $user_id, int $new_parent ): int|null|WP_Error {
		unset( $space );

		// Detach to the top level — always safe (a child simply becomes a root).
		if ( $new_parent <= 0 ) {
			return null;
		}

		if ( '0' === (string) get_option( 'buddynext_space_allow_sub', '1' ) ) {
			return new WP_Error( 'sub_spaces_disabled', __( 'Sub-spaces are disabled on this community.', 'buddynext' ), array( 'status' => 403 ) );
		}

		if ( $new_parent === $space_id ) {
			return new WP_Error( 'invalid_parent', __( 'A space cannot be its own parent.', 'buddynext' ), array( 'status' => 422 ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$parent_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, parent_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $new_parent ),
			ARRAY_A
		);
		if ( null === $parent_row ) {
			return new WP_Error( 'parent_not_found', __( 'The selected parent space does not exist.', 'buddynext' ), array( 'status' => 422 ) );
		}

		// The target must be a root: nesting under a sub-space would be three deep.
		if ( null !== $parent_row['parent_id'] && (int) $parent_row['parent_id'] > 0 ) {
			return new WP_Error( 'max_depth_exceeded', __( 'Spaces may only be nested two levels deep.', 'buddynext' ), array( 'status' => 422 ) );
		}

		// A space that itself has sub-spaces cannot be nested — its children would
		// become three levels deep.
		if ( $this->count_subspaces( $space_id ) > 0 ) {
			return new WP_Error( 'has_children', __( 'Move or remove this space\'s sub-spaces before nesting it under another space.', 'buddynext' ), array( 'status' => 422 ) );
		}

		if ( ! buddynext_service( 'permissions' )->can( $user_id, 'buddynext-manage-space', array( 'space_id' => $new_parent ) ) ) {
			return new WP_Error( 'forbidden', __( 'You can only move a space under one you manage.', 'buddynext' ), array( 'status' => 403 ) );
		}

		$max_sub = (int) get_option( 'buddynext_space_max_sub_spaces', 0 );
		if ( $max_sub > 0 && $this->count_subspaces( $new_parent ) >= $max_sub ) {
			return new WP_Error(
				'max_sub_spaces_exceeded',
				sprintf(
					/* translators: %d: maximum number of sub-spaces allowed per parent. */
					__( 'This space already has the maximum of %d sub-spaces.', 'buddynext' ),
					$max_sub
				),
				array( 'status' => 422 )
			);
		}

		return $new_parent;
	}

	/**
	 * Return space categories, each carrying a live space count.
	 *
	 * Delegates to the category service (single owner of bn_space_categories),
	 * which joins a per-row COUNT of spaces in each category. Pass $limit > 0 to
	 * cap the directory chip row.
	 *
	 * @param int $limit Max categories to return; 0 = no limit.
	 * @return array<int, array<string, mixed>> Hydrated category rows + space_count.
	 */
	public function categories_with_counts( int $limit = 0 ): array {
		$rows = ( new SpaceCategoryService() )->get_all_with_counts();

		if ( $limit > 0 && count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		return $rows;
	}

	/**
	 * Return pending join requests for a space, enriched with member identity.
	 *
	 * Joins wp_users so the moderation "pending members" tab renders a name and
	 * email without a per-row lookup. Pagination is mandatory here (the request
	 * queue is shown in bounded batches). Use count_pending_joins() for the
	 * matching total. The unbounded user_id-only variant lives on
	 * {@see SpaceMemberService::get_pending_requests()}.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Max rows to return. Capped at 100.
	 * @param int $offset   Row offset.
	 * @return array[] Each item: user_id, display_name, user_email, requested_at.
	 */
	public function get_pending_join_requests( int $space_id, int $limit, int $offset ): array {
		global $wpdb;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.joined_at, u.display_name, u.user_email
				 FROM {$wpdb->prefix}bn_space_members sm
				 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 WHERE sm.space_id = %d AND sm.status = 'pending'
				 ORDER BY sm.joined_at ASC
				 LIMIT %d OFFSET %d",
				$space_id,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			static fn( $r ) => array(
				'user_id'      => (int) $r['user_id'],
				'display_name' => (string) $r['display_name'],
				'user_email'   => (string) $r['user_email'],
				'requested_at' => (string) $r['joined_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Count pending join requests for a space, without loading the rows.
	 *
	 * Matches get_pending_join_requests()'s filter so the count and the page
	 * never disagree.
	 *
	 * @param int $space_id Space ID.
	 * @return int
	 */
	public function count_pending_joins( int $space_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND status = 'pending'",
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count pending join requests across every space (site-wide).
	 *
	 * The cross-space counterpart to {@see count_pending_joins()}, for the
	 * community-admin overview where a single manager triages requests from all
	 * spaces in one stream.
	 *
	 * @return int
	 */
	public function count_pending_joins_all(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE status = 'pending'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Return pending join requests across every space (site-wide), enriched with
	 * member and space identity in one query so the cross-space admin queue
	 * renders without a per-row lookup.
	 *
	 * The cross-space counterpart to {@see get_pending_join_requests()}; ordered
	 * oldest-first so the longest-waiting request surfaces at the top.
	 *
	 * @param int $limit Max rows to return. Capped at 100.
	 * @return array<int, array{user_id:int, space_id:int, requested_at:string, member_name:string, space_name:string, space_slug:string}>
	 */
	public function get_pending_join_requests_all( int $limit = 10 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.space_id, sm.joined_at,
				        u.display_name AS member_name,
				        s.name AS space_name, s.slug AS space_slug
				 FROM {$wpdb->prefix}bn_space_members sm
				 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 INNER JOIN {$wpdb->prefix}bn_spaces s ON s.id = sm.space_id
				 WHERE sm.status = 'pending'
				 ORDER BY sm.joined_at ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			static fn( $r ) => array(
				'user_id'      => (int) $r['user_id'],
				'space_id'     => (int) $r['space_id'],
				'requested_at' => (string) $r['joined_at'],
				'member_name'  => (string) $r['member_name'],
				'space_name'   => (string) $r['space_name'],
				'space_slug'   => (string) $r['space_slug'],
			),
			(array) $rows
		);
	}

	/**
	 * Hydrate a raw DB row into a typed space array.
	 *
	 * @param array $row Raw ARRAY_A row from bn_spaces.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		$space = array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'name'             => $row['name'] ?? '',
			'slug'             => $row['slug'] ?? '',
			'description'      => $row['description'] ?? '',
			'category_id'      => isset( $row['category_id'] ) ? (int) $row['category_id'] : null,
			'parent_id'        => isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			'type'             => $row['type'] ?? 'open',
			'owner_id'         => (int) ( $row['owner_id'] ?? 0 ),
			'member_count'     => (int) ( $row['member_count'] ?? 0 ),
			'avatar_url'       => $row['avatar_url'] ?? null,
			'cover_image_url'  => $row['cover_image_url'] ?? null,
			'rules'            => $row['rules'] ?? null,
			// The Pro entitlement gate on the space (`tier:<slug>`), or null when the
			// space is ungated. REST already ACCEPTS this on PUT, and Pro admin writes
			// it — but it was never returned, so no client could tell a space was
			// paywalled. The native app cannot render a gated space if it cannot see
			// that the space is gated. It is not a secret: the whole point of a paywall
			// is that the person outside it is told what would let them in.
			'required_ability' => isset( $row['required_ability'] ) && '' !== (string) $row['required_ability']
				? (string) $row['required_ability']
				: null,
			'is_archived'      => ! empty( $row['is_archived'] ),
			'archived_at'      => $row['archived_at'] ?? null,
			'created_at'       => $row['created_at'] ?? '',
			// Present only on member-scoped lists (the viewer's role in the space:
			// owner | moderator | member). Null elsewhere. Lets clients group
			// "spaces you manage" vs "spaces you've joined" without a second query.
			'viewer_role'      => $row['viewer_role'] ?? null,
		);

		/**
		 * Filter a hydrated space array. Every space payload — single-space REST
		 * responses, list/directory rows — passes through here, so add-ons (native
		 * app, bridges) can attach computed fields to a space on every surface.
		 *
		 * @param array<string,mixed> $space The hydrated space.
		 * @param array<string,mixed> $row   The raw bn_spaces row.
		 */
		return (array) apply_filters( 'buddynext_prepare_space', $space, $row );
	}
}

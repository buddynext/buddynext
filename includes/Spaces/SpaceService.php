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
	 * Return the i18n'd human-readable label for a space type.
	 *
	 * Canonical labels:
	 *   open    -> "Open"
	 *   private -> "Private"
	 *   secret  -> "Secret"
	 *
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
		$max_per_member = (int) get_option( 'buddynext_space_max_per_member', 0 );
		if ( $max_per_member > 0 && ! user_can( $owner_id, 'manage_options' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$owned = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE owner_id = %d AND is_archived = 0",
					$owner_id
				)
			);
			if ( $owned >= $max_per_member ) {
				return new WP_Error(
					'max_spaces_per_member',
					sprintf(
						/* translators: %d: maximum number of spaces a member can create. */
						__( 'You have reached the maximum of %d spaces.', 'buddynext' ),
						$max_per_member
					),
					array( 'status' => 422 )
				);
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$grandparent = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT parent_id FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
					$parent_id
				)
			);
			if ( null !== $grandparent && $grandparent > 0 ) {
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
	 * Transfer space ownership to an existing member.
	 *
	 * Owns the bn_spaces.owner_id write (change_role alone does not touch it) so
	 * the REST controller does not query the table directly. Callers validate
	 * permission and that the new owner is a member before calling.
	 *
	 * @param int $space_id     Space id.
	 * @param int $new_owner_id Member who becomes the owner.
	 * @param int $actor_id     Current owner performing the transfer.
	 * @return void
	 */
	public function transfer_ownership( int $space_id, int $new_owner_id, int $actor_id ): void {
		$members = new SpaceMemberService();

		// Demote the current owner, promote the new owner (same order the
		// controller used previously).
		$members->change_role( $space_id, $actor_id, 'member', $actor_id );
		$members->change_role( $space_id, $new_owner_id, 'owner', $actor_id );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_spaces',
			array( 'owner_id' => $new_owner_id ),
			array( 'id' => $space_id ),
			array( '%d' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "space_{$space_id}", self::CACHE_GROUP );

		/**
		 * Fires after a space's ownership is transferred.
		 *
		 * The whole transfer chain was previously silent (change_role fired no
		 * hook either), so no webhook / notification could observe this major
		 * domain event.
		 *
		 * @param int $space_id     Space whose ownership moved.
		 * @param int $new_owner_id The new owner.
		 * @param int $actor_id     User who performed the transfer.
		 */
		do_action( 'buddynext_space_ownership_transferred', $space_id, $new_owner_id, $actor_id );
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

		// Remove the per-space option rows (bn_space_{id}_*) so deleting a space
		// doesn't leave orphaned autoloaded options behind. delete_option() keeps
		// the options cache coherent (a raw LIKE delete would leave a stale
		// alloptions entry for the request). Integrations that store their own
		// per-space option register the suffix via the filter.
		$bn_space_option_suffixes = apply_filters(
			'buddynext_space_option_suffixes',
			array( 'who_can_post', 'require_join_approval', 'jetonomy_forum_id', 'mvs_media_tab', 'banned_words' )
		);
		foreach ( (array) $bn_space_option_suffixes as $bn_suffix ) {
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
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array[]
	 */
	public function list_spaces( array $args = array() ): array {
		global $wpdb;

		$scope     = $this->list_query_scope( $args );
		$member_id = $scope['member_id'];
		$where_sql = $scope['where_sql'];
		$orderby   = $scope['orderby'];
		$order     = $scope['order'];
		$per_page  = $scope['per_page'];
		$offset    = $scope['offset'];

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
					"SELECT s.* FROM {$wpdb->prefix}bn_spaces s INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active' {$where_sql} ORDER BY s.{$orderby} {$order} LIMIT %d OFFSET %d",
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

		$scope     = $this->list_query_scope( $args );
		$member_id = $scope['member_id'];
		$where_sql = $scope['where_sql'];

		// $where_sql contains only hardcoded strings or validated enum values; the
		// embedded placeholders are bound through prepare() with $scope['params'].
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( $member_id > 0 ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces s INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active' {$where_sql}",
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
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces" );
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
	 * @return array{where_sql: string, params: array<int, mixed>, orderby: string, order: string, per_page: int, offset: int, member_id: int}
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
					$where[]  = "( owner_id = %d OR id IN ( SELECT space_id FROM {$members_table} WHERE user_id = %d AND status = 'active' ) )";
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
					$where[]  = "( type NOT IN ( {$placeholders} ) OR owner_id = %d OR id IN ( SELECT space_id FROM {$members_table} WHERE user_id = %d AND status = 'active' ) )";
					$params[] = $viewer_id;
					$params[] = $viewer_id;
				} else {
					$where[] = "type NOT IN ( {$placeholders} )";
				}
			}
		}

		if ( $category_id > 0 ) {
			$where[]  = 'category_id = %d';
			$params[] = $category_id;
		}

		return array(
			'where_sql' => $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '',
			'params'    => $params,
			'orderby'   => $orderby,
			'order'     => $order,
			'per_page'  => $per_page,
			'offset'    => $offset,
			'member_id' => $member_id,
		);
	}

	/**
	 * Search spaces by name or description.
	 *
	 * Secret spaces are excluded from search results, EXCEPT the viewer's own
	 * (owned or active membership). Site admins (is_admin arg) see all matches.
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
			$mine_sql     = "( owner_id = %d OR id IN ( SELECT space_id FROM {$mine_members} WHERE user_id = %d AND status = 'active' ) )";
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
				$exclude_sql = "( type NOT IN ( {$placeholders} ) OR owner_id = %d OR id IN ( SELECT space_id FROM {$members_table} WHERE user_id = %d AND status = 'active' ) )";
				$params[]    = $viewer_id;
				$params[]    = $viewer_id;
			} else {
				$exclude_sql = "type NOT IN ( {$placeholders} )";
			}
		}

		// Mine-scope placeholders follow the exclude-scope ones, before the LIKEs.
		if ( $member_id > 0 ) {
			$params[] = $member_id;
			$params[] = $member_id;
		}

		$params[] = $like;
		$params[] = $like;
		$params[] = $per_page;
		$params[] = $offset;

		// $exclude_sql / $mine_sql hold only literal SQL plus %d placeholders bound
		// through $params; their placeholder count is dynamic, so the analyser
		// reports ReplacementsWrongNumber here even though the binding is correct.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces
				 WHERE {$exclude_sql} AND {$mine_sql} AND (name LIKE %s OR description LIKE %s)
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
		global $wpdb;

		$limit = max( 1, min( 50, $limit ) );

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
				$limit
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
		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'name'            => $row['name'] ?? '',
			'slug'            => $row['slug'] ?? '',
			'description'     => $row['description'] ?? '',
			'category_id'     => isset( $row['category_id'] ) ? (int) $row['category_id'] : null,
			'parent_id'       => isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			'type'            => $row['type'] ?? 'open',
			'owner_id'        => (int) ( $row['owner_id'] ?? 0 ),
			'member_count'    => (int) ( $row['member_count'] ?? 0 ),
			'avatar_url'      => $row['avatar_url'] ?? null,
			'cover_image_url' => $row['cover_image_url'] ?? null,
			'rules'           => $row['rules'] ?? null,
			'is_archived'     => ! empty( $row['is_archived'] ),
			'archived_at'     => $row['archived_at'] ?? null,
			'created_at'      => $row['created_at'] ?? '',
		);
	}
}

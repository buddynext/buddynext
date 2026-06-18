<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- PSR-4 naming; all queries use custom bn_profile_* tables.
/**
 * Profile fields and values service.
 *
 * Manages profile group definitions (bn_profile_groups), custom field
 * definitions (bn_profile_fields), and per-user values (bn_profile_values).
 *
 * Reads are cache-backed; writes invalidate the relevant keys.
 *
 * Visibility is enforced at the group level: a group's visibility setting
 * gates access to all fields within it. Per-entry overrides are stored in
 * entry_visibility and respected inside get_profile() when present.
 *
 * Repeater groups store multiple indexed entries (entry_index > 0 allowed).
 * Flat groups always use entry_index = 0 and are also denormalised to usermeta
 * (bn_field_{key}) for fast WP_User_Query filtering in the member directory.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Handles profile groups, custom profile fields, and user profile reads/writes.
 */
class ProfileService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_profiles';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Completion score cache TTL in seconds (5 minutes).
	 */
	private const COMPLETION_CACHE_TTL = 300;

	/**
	 * Return all profile groups with their nested field definitions.
	 *
	 * Return shape:
	 * [
	 *   [
	 *     'id'         => 1,
	 *     'group_key'  => 'basic_info',
	 *     'label'      => 'Basic Info',
	 *     'type'       => 'flat',
	 *     'visibility' => 'public',
	 *     'sort_order' => 1,
	 *     'fields'     => [ [...], ... ],
	 *   ],
	 *   ...
	 * ]
	 *
	 * @return array[]
	 */
	public function get_fields(): array {
		$cached = wp_cache_get( 'all_fields', self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT
				g.id           AS group_id,
				g.group_key,
				g.label        AS group_label,
				g.type         AS group_type,
				g.visibility   AS group_visibility,
				g.is_system    AS group_is_system,
				g.sort_order   AS group_sort_order,
				f.id           AS field_id,
				f.field_key,
				f.label        AS field_label,
				f.type         AS field_type,
				f.options,
				f.is_required,
				f.is_searchable,
				f.show_on_register,
				f.visibility   AS field_visibility,
				f.sort_order   AS field_sort_order
			FROM {$wpdb->prefix}bn_profile_groups g
			LEFT JOIN {$wpdb->prefix}bn_profile_fields f ON f.group_id = g.id
			ORDER BY g.sort_order ASC, g.id ASC, f.sort_order ASC, f.id ASC",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groups = array();

		foreach ( (array) $rows as $row ) {
			$gid = (int) $row['group_id'];

			if ( ! isset( $groups[ $gid ] ) ) {
				$groups[ $gid ] = array(
					'id'         => $gid,
					'group_key'  => $row['group_key'],
					'label'      => $row['group_label'],
					'type'       => $row['group_type'],
					'visibility' => $row['group_visibility'],
					'is_system'  => (bool) $row['group_is_system'],
					'sort_order' => (int) $row['group_sort_order'],
					'fields'     => array(),
				);
			}

			if ( null !== $row['field_id'] ) {
				$groups[ $gid ]['fields'][] = array(
					'id'               => (int) $row['field_id'],
					'group_id'         => $gid,
					'field_key'        => $row['field_key'],
					'label'            => $row['field_label'],
					'type'             => $row['field_type'],
					'options'          => isset( $row['options'] ) ? json_decode( $row['options'], true ) : null,
					'is_required'      => (bool) $row['is_required'],
					'is_searchable'    => (bool) $row['is_searchable'],
					'show_on_register' => (bool) ( $row['show_on_register'] ?? false ),
					'visibility'       => $row['field_visibility'] ?? 'public',
					'sort_order'       => (int) $row['field_sort_order'],
				);
			}
		}

		$result = array_values( $groups );

		wp_cache_set( 'all_fields', $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $this->filter_fields( $result );
	}

	/**
	 * Apply the runtime field-registration filter to the DB-derived group tree.
	 *
	 * Lets addons inject virtual groups/fields in code (no DB write) via
	 * `buddynext_profile_fields`. Runs on every call — the DB rows are what get
	 * cached, filters layer on top so a plugin loading/unloading is reflected
	 * immediately. Every injected field is normalized so a malformed filter
	 * cannot break the editor or the signup form.
	 *
	 * @param array<int, array<string, mixed>> $groups DB-derived group tree.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_fields( array $groups ): array {
		/**
		 * Filter the full profile group + field tree.
		 *
		 * Each group is an array with a `fields` array. Addons may add groups or
		 * push fields onto an existing group's `fields`. See
		 * normalize_field_row() for the per-field shape that is enforced.
		 *
		 * @param array<int, array<string, mixed>> $groups Group tree (each with a `fields` list).
		 */
		$groups = (array) apply_filters( 'buddynext_profile_fields', $groups );

		// Normalize every field row so downstream code (editor, signup, save) can
		// trust the shape regardless of what a third-party filter supplied.
		foreach ( $groups as $gi => $group ) {
			if ( ! is_array( $group ) ) {
				unset( $groups[ $gi ] );
				continue;
			}
			$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();
			$clean  = array();
			foreach ( $fields as $field ) {
				$norm = $this->normalize_field_row( is_array( $field ) ? $field : array(), (int) ( $group['id'] ?? 0 ) );
				if ( null !== $norm ) {
					$clean[] = $norm;
				}
			}
			$groups[ $gi ]['fields'] = $clean;
		}

		return array_values( $groups );
	}

	/**
	 * Coerce a field row to the canonical shape. Returns null when the row lacks
	 * the minimum identity (a field_key + label), so a broken filter entry is
	 * dropped rather than rendered.
	 *
	 * @param array<string, mixed> $field    Raw field row (DB- or filter-sourced).
	 * @param int                  $group_id Owning group id.
	 * @return array<string, mixed>|null
	 */
	private function normalize_field_row( array $field, int $group_id ): ?array {
		$field_key = sanitize_key( (string) ( $field['field_key'] ?? '' ) );
		$label     = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
		if ( '' === $field_key || '' === $label ) {
			return null;
		}

		$visibility = (string) ( $field['visibility'] ?? 'public' );
		if ( ! in_array( $visibility, array( 'public', 'followers', 'connections', 'private' ), true ) ) {
			$visibility = 'public';
		}

		return array(
			'id'               => (int) ( $field['id'] ?? 0 ),
			'group_id'         => (int) ( $field['group_id'] ?? $group_id ),
			'field_key'        => $field_key,
			'label'            => $label,
			'type'             => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
			'options'          => $field['options'] ?? null,
			'is_required'      => ! empty( $field['is_required'] ),
			'is_searchable'    => ! empty( $field['is_searchable'] ),
			'show_on_register' => ! empty( $field['show_on_register'] ),
			'visibility'       => $visibility,
			'sort_order'       => (int) ( $field['sort_order'] ?? 0 ),
			'is_virtual'       => empty( $field['id'] ),
		);
	}

	/**
	 * Return the flat fields an owner has opted into the registration form,
	 * each decorated with its group_key, ordered by group then field sort order.
	 *
	 * Repeater-group fields are excluded — a signup form is single-entry by
	 * nature, so multi-entry groups never surface there.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_registration_fields(): array {
		$reg = array();
		foreach ( $this->get_fields() as $group ) {
			if ( 'repeater' === ( $group['type'] ?? '' ) ) {
				continue;
			}
			foreach ( $group['fields'] as $field ) {
				if ( empty( $field['show_on_register'] ) ) {
					continue;
				}
				$field['group_key'] = $group['group_key'] ?? '';
				$reg[]              = $field;
			}
		}

		return $reg;
	}

	/**
	 * Return all profile group definitions without nested fields.
	 *
	 * Used by admin UI to list and manage groups.
	 *
	 * @return array[]
	 */
	public function get_groups(): array {
		$cached = wp_cache_get( 'all_groups', self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, group_key, label, type, visibility, is_system, sort_order
			 FROM {$wpdb->prefix}bn_profile_groups
			 ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groups = array_map(
			static function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'group_key'  => $row['group_key'],
					'label'      => $row['label'],
					'type'       => $row['type'],
					'visibility' => $row['visibility'],
					'is_system'  => (bool) $row['is_system'],
					'sort_order' => (int) $row['sort_order'],
				);
			},
			(array) $rows
		);

		wp_cache_set( 'all_groups', $groups, self::CACHE_GROUP, self::CACHE_TTL );

		/**
		 * Filter the profile group list (no fields). Lets addons register a
		 * virtual group in code. Runs on every call so it layers on top of the
		 * cached DB rows. The richer group+field tree is filterable via
		 * `buddynext_profile_fields` in get_fields().
		 *
		 * @param array<int, array<string, mixed>> $groups Group rows.
		 */
		return (array) apply_filters( 'buddynext_profile_groups', $groups );
	}

	/**
	 * Resolve a field's key by its numeric id. Empty string when not found.
	 *
	 * Lets extensions (e.g. Pro advanced field types) map a field id to its key
	 * without querying bn_profile_fields directly.
	 *
	 * @param int $field_id Field id.
	 * @return string
	 */
	public function get_field_key( int $field_id ): string {
		if ( $field_id <= 0 ) {
			return '';
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT field_key FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Create a new profile group.
	 *
	 * @param array $data Group data: group_key, label, type, visibility, sort_order.
	 * @return int Inserted group ID.
	 */
	public function create_group( array $data ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key'  => sanitize_key( $data['group_key'] ),
				'label'      => sanitize_text_field( $data['label'] ),
				'type'       => $data['type'] ?? 'flat',
				'visibility' => $data['visibility'] ?? 'public',
				'is_system'  => 0,
				'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( 'all_groups', self::CACHE_GROUP );
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Create a new profile field definition.
	 *
	 * Accepts either group_id (int) or group_name (string). When group_name is
	 * provided, the group is looked up by group_key and created on-the-fly if it
	 * does not yet exist.
	 *
	 * @param array $data Field data: group_id|group_name, field_key, label, type,
	 *                    options, is_required, is_searchable, visibility, sort_order.
	 * @return int Inserted field ID.
	 */
	public function create_field( array $data ): int {
		global $wpdb;

		// Resolve group_id from group_name when not supplied directly.
		$group_id = (int) ( $data['group_id'] ?? 0 );
		if ( $group_id <= 0 && isset( $data['group_name'] ) ) {
			$group_key = sanitize_key( (string) $data['group_name'] );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$group_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_profile_groups WHERE group_key = %s",
					$group_key
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
			if ( ! $group_id ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$wpdb->prefix . 'bn_profile_groups',
					array(
						'group_key'  => $group_key,
						'label'      => ucwords( str_replace( '_', ' ', $group_key ) ),
						'type'       => 'flat',
						'visibility' => 'public',
						'is_system'  => 0,
						'sort_order' => 0,
					),
					array( '%s', '%s', '%s', '%s', '%d', '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$group_id = (int) $wpdb->insert_id;
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$field_key = sanitize_key( (string) ( $data['field_key'] ?? '' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_profile_fields
					(group_id, field_key, label, type, options, is_required, is_searchable, visibility, sort_order)
				 VALUES (%d, %s, %s, %s, %s, %d, %d, %s, %d)",
				$group_id,
				$field_key,
				sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
				$data['type'] ?? 'text',
				wp_json_encode( $data['options'] ?? null ),
				(int) ( $data['is_required'] ?? 0 ),
				(int) ( $data['is_searchable'] ?? 0 ),
				$data['visibility'] ?? 'public',
				(int) ( $data['sort_order'] ?? 0 )
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		// If INSERT IGNORE skipped due to a duplicate key, fetch the existing ID.
		$field_id = (int) $wpdb->insert_id;
		if ( ! $field_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$field_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = %s",
					$field_key
				)
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( 'all_fields', self::CACHE_GROUP );

		return $field_id;
	}

	/**
	 * Save profile field values for a user.
	 *
	 * Flat fields: keyed directly as field_key => value.
	 * Repeater fields: keyed as group_key[n][field_key] and optionally
	 *   group_key[n][_visibility] for per-entry visibility override.
	 *
	 * Only writes values for field_keys that exist in bn_profile_fields.
	 * Unknown keys are silently ignored (no mass-assignment risk).
	 * Flat + searchable fields are additionally denormalised to usermeta
	 * as bn_field_{key} for fast WP_User_Query filtering.
	 *
	 * @param int   $user_id User whose profile to update.
	 * @param array $data    Flat and/or repeater field data.
	 * @return true
	 */
	public function save_profile( int $user_id, array $data ): true {
		global $wpdb;

		$flat_fields  = $this->get_flat_fields();
		$field_by_key = array_column( $flat_fields, null, 'field_key' );

		// Build a group_key => group metadata map for repeater detection.
		$group_by_key = array();
		foreach ( $this->get_fields() as $group ) {
			$group_by_key[ $group['group_key'] ] = $group;
		}

		foreach ( $data as $key => $value ) {
			// Repeater groups arrive keyed by group_key with an array of entry arrays.
			if ( isset( $group_by_key[ $key ] ) && 'repeater' === $group_by_key[ $key ]['type'] && is_array( $value ) ) {
				$group = $group_by_key[ $key ];

				foreach ( $value as $entry_index => $entry_data ) {
					if ( ! is_array( $entry_data ) ) {
						continue;
					}

					$entry_index = (int) $entry_index;

					foreach ( $entry_data as $field_key => $field_value ) {
						if ( '_visibility' === $field_key ) {
							continue;
						}

						if ( ! isset( $field_by_key[ $field_key ] ) ) {
							continue;
						}

						// Verify field belongs to this group.
						if ( (int) $field_by_key[ $field_key ]['group_id'] !== (int) $group['id'] ) {
							continue;
						}

						$field_id   = (int) $field_by_key[ $field_key ]['id'];
						$field_def  = $field_by_key[ $field_key ];
						$field_type = isset( $field_def['type'] ) ? (string) $field_def['type'] : 'text';

						// A3: route sanitisation through the field-type engine.
						$sanitized_val = \BuddyNext\Profile\FieldType::sanitize( $field_def, $field_value );

						if ( is_wp_error( $sanitized_val ) ) {
							continue;
						}

						$sanitized_val = (string) $sanitized_val;

						/**
						 * Validate a profile-field value before persistence.
						 *
						 * Pro field types (e.g. location, file, number_advanced)
						 * hook here to enforce per-type rules — Free's default
						 * pass-through (true) allows the value through unchanged.
						 *
						 * @since 1.1.0
						 *
						 * @param true|\WP_Error       $result  True (pass) by default.
						 * @param string               $type    Field type slug.
						 * @param mixed                $value   Submitted raw value (sanitized).
						 * @param array<string, mixed> $field   Full field row from bn_profile_fields.
						 * @param int                  $user_id User being saved.
						 */
						$validation = apply_filters(
							'buddynext_profile_field_validate',
							true,
							$field_type,
							$sanitized_val,
							$field_def,
							$user_id
						);

						if ( is_wp_error( $validation ) ) {
							continue;
						}

						// A4: clamp the per-entry _visibility override to be
						// equal-or-more restrictive than the field admin default.
						$chosen_visibility = isset( $entry_data['_visibility'] )
							? sanitize_key( (string) $entry_data['_visibility'] )
							: null;
						$entry_visibility  = $this->clamp_visibility(
							$chosen_visibility,
							(string) ( $field_def['visibility'] ?? 'public' )
						);

						$this->upsert_value( $user_id, $field_id, $entry_index, $sanitized_val, $entry_visibility );
					}
				}

				continue;
			}

			// Skip the per-field visibility companion keys — handled with their field.
			if ( is_string( $key ) && str_ends_with( $key, '__visibility' ) ) {
				continue;
			}

			// Flat field.
			if ( ! isset( $field_by_key[ $key ] ) ) {
				continue;
			}

			$field      = $field_by_key[ $key ];
			$field_id   = (int) $field['id'];
			$field_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			// A3: route sanitisation through the field-type engine. Returns a
			// storable scalar (multi → comma-joined slugs) or a WP_Error.
			$sanitized_val = \BuddyNext\Profile\FieldType::sanitize( $field, $value );

			if ( is_wp_error( $sanitized_val ) ) {
				continue;
			}

			$sanitized_val = (string) $sanitized_val;

			/** This filter is documented above in the repeater branch. */
			$validation = apply_filters(
				'buddynext_profile_field_validate',
				true,
				$field_type,
				$sanitized_val,
				$field,
				$user_id
			);

			if ( is_wp_error( $validation ) ) {
				continue;
			}

			// A4: accept {field_key}__visibility and clamp to be equal-or-more
			// restrictive than the field admin default before storing.
			$chosen_visibility = isset( $data[ $key . '__visibility' ] )
				? sanitize_key( (string) $data[ $key . '__visibility' ] )
				: null;
			$entry_visibility  = $this->clamp_visibility(
				$chosen_visibility,
				(string) ( $field['visibility'] ?? 'public' )
			);

			$this->upsert_value( $user_id, $field_id, 0, $sanitized_val, $entry_visibility );

			// A2: write/delete the privacy-safe search mirror.
			$this->sync_search_mirror( $user_id, $field, $sanitized_val, $entry_visibility );

			// Denormalise the headline into a dedicated bn_headline usermeta key.
			// Member-list surfaces (onboarding suggestions) LEFT JOIN this key to
			// avoid resolving bn_profile_values per row. Previously only
			// DemoDataService wrote it, so real users showed no headline there;
			// keep it in lockstep with the canonical bn_profile_values row.
			if ( 'headline' === $key ) {
				if ( '' !== $sanitized_val ) {
					update_user_meta( $user_id, 'bn_headline', $sanitized_val );
				} else {
					delete_user_meta( $user_id, 'bn_headline' );
				}
			}
		}

		// Handle profile URL slug separately — stored in usermeta, not bn_profile_values.
		if ( array_key_exists( 'profile_slug', $data ) ) {
			$requested_slug = sanitize_title( (string) $data['profile_slug'] );
			if ( '' !== $requested_slug && \BuddyNext\Core\PageRouter::is_slug_available( $requested_slug, $user_id ) ) {
				update_user_meta( $user_id, 'bn_profile_slug', $requested_slug );
			}
		}

		$this->bust_profile_cache( $user_id );
		wp_cache_delete( "completion_{$user_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Bust every viewer-relationship cache bucket for a user's profile.
	 *
	 * Clears the owner bucket plus all follower/connection combinations keyed by
	 * get_profile(). Centralised so the key shape lives in one place.
	 *
	 * @param int $user_id Profile owner whose cached views to invalidate.
	 * @return void
	 */
	private function bust_profile_cache( int $user_id ): void {
		wp_cache_delete( "profile_{$user_id}_viewer_owner", self::CACHE_GROUP );

		foreach ( array( 0, 1 ) as $follower ) {
			foreach ( array( 0, 1 ) as $connection ) {
				wp_cache_delete(
					sprintf( 'profile_%d_viewer_f%d_c%d', $user_id, $follower, $connection ),
					self::CACHE_GROUP
				);
			}
		}
	}

	/**
	 * Return the full profile for a user as seen by the given viewer.
	 *
	 * Includes WordPress core fields (display_name, avatar_url) plus all
	 * custom field values organised by group. Visibility is enforced at the
	 * group level: groups whose visibility the viewer does not meet are omitted
	 * entirely. Per-entry entry_visibility overrides the group visibility when
	 * present.
	 *
	 * Return shape for groups key:
	 * - flat group:     { ..., 'fields': [ { field_key, value, ... } ] }
	 * - repeater group: { ..., 'entries': [ [ { field_key, value, ... } ] ] }
	 *
	 * @param int $profile_user_id User whose profile to return.
	 * @param int $viewer_id       Viewing user ID (0 = anonymous).
	 * @return array|null Null if the WP user does not exist.
	 */
	public function get_profile( int $profile_user_id, int $viewer_id ): ?array {
		$wp_user = get_userdata( $profile_user_id );

		if ( ! $wp_user ) {
			return null;
		}

		// Return minimal profile for suspended users (unless viewer is an admin).
		$is_suspended = buddynext_service( 'moderation' )->is_suspended( $profile_user_id );
		if ( $is_suspended && ! current_user_can( 'manage_options' ) ) {
			return array(
				'user_id'      => $profile_user_id,
				'display_name' => __( 'Suspended User', 'buddynext' ),
				'is_suspended' => true,
				'groups'       => array(),
				'avatar_url'   => '',
			);
		}

		$is_owner = ( $viewer_id === $profile_user_id );

		// Resolve follower AND connection status before the cache lookup so the
		// cache key fully captures the viewer's relationship to the owner. Without
		// the connection state in the key, a connection's privileged result could
		// leak to a stranger sharing the same "follower"/"public" cache bucket.
		$viewer_is_follower = $viewer_id && ! $is_owner
			? buddynext_service( 'follows' )->is_following( $viewer_id, $profile_user_id )
			: false;

		$viewer_is_connection = $viewer_id && ! $is_owner
			? buddynext_service( 'connections' )->are_connected( $viewer_id, $profile_user_id )
			: false;

		// Key on owner + follower + connection state so each distinct viewer
		// relationship gets its own cache bucket (no cross-relationship leak).
		if ( $is_owner ) {
			$cache_key = "profile_{$profile_user_id}_viewer_owner";
		} else {
			$cache_key = sprintf(
				'profile_%d_viewer_f%d_c%d',
				$profile_user_id,
				$viewer_is_follower ? 1 : 0,
				$viewer_is_connection ? 1 : 0
			);
		}

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// Load field values joined with field and group definitions.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					g.id           AS group_id,
					g.group_key,
					g.label        AS group_label,
					g.type         AS group_type,
					g.visibility   AS group_visibility,
					g.sort_order   AS group_sort_order,
					f.id           AS field_id,
					f.field_key,
					f.label        AS field_label,
					f.type         AS field_type,
					f.options,
					f.is_required  AS field_is_required,
					f.visibility   AS field_visibility,
					f.sort_order   AS field_sort_order,
					v.entry_index,
					v.value,
					v.entry_visibility
				FROM {$wpdb->prefix}bn_profile_groups g
				INNER JOIN {$wpdb->prefix}bn_profile_fields f ON f.group_id = g.id
				LEFT JOIN {$wpdb->prefix}bn_profile_values v
				       ON v.field_id = f.id AND v.user_id = %d
				ORDER BY g.sort_order ASC, g.id ASC, v.entry_index ASC, f.sort_order ASC, f.id ASC",
				$profile_user_id
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Organise rows into groups → entries → fields.
		$raw_groups = array();

		foreach ( (array) $rows as $row ) {
			$gid   = (int) $row['group_id'];
			$fid   = (int) $row['field_id'];
			$eidx  = (int) ( $row['entry_index'] ?? 0 );
			$gtype = $row['group_type'];
			$gvis  = $row['group_visibility'];

			// Enforce group/field/entry visibility for non-owners (most restrictive wins).
			if ( ! $is_owner ) {
				$fvis          = $row['field_visibility'] ?? 'public';
				$evis          = $row['entry_visibility'] ?? 'public';
				$effective_vis = 'public';
				foreach ( array( $gvis, $fvis, $evis ) as $v ) {
					if ( 'private' === $v ) {
						$effective_vis = 'private';
						break;
					}
					if ( 'connections' === $v ) {
						$effective_vis = 'connections';
					}
					if ( 'followers' === $v && 'connections' !== $effective_vis ) {
						$effective_vis = 'followers';
					}
				}
				if ( 'private' === $effective_vis ) {
					continue;
				}
				// Reuse the connection flag resolved once before the cache lookup —
				// no per-row SQL. The cache key already captures this relationship.
				if ( 'connections' === $effective_vis && ! $viewer_is_connection ) {
					continue;
				}
				if ( 'followers' === $effective_vis && ! $viewer_is_follower ) {
					continue;
				}
			}

			if ( ! isset( $raw_groups[ $gid ] ) ) {
				$raw_groups[ $gid ] = array(
					'id'         => $gid,
					'group_key'  => $row['group_key'],
					'label'      => $row['group_label'],
					'type'       => $gtype,
					'visibility' => $gvis,
					'sort_order' => (int) $row['group_sort_order'],
					'_entries'   => array(),
				);
			}

			if ( ! isset( $raw_groups[ $gid ]['_entries'][ $eidx ] ) ) {
				$raw_groups[ $gid ]['_entries'][ $eidx ] = array();
			}

			$raw_groups[ $gid ]['_entries'][ $eidx ][ $fid ] = array(
				'field_id'         => $fid,
				'field_key'        => $row['field_key'],
				'label'            => $row['field_label'],
				'type'             => $row['field_type'],
				'options'          => isset( $row['options'] ) ? json_decode( $row['options'], true ) : null,
				'is_required'      => (bool) ( $row['field_is_required'] ?? false ),
				'sort_order'       => (int) $row['field_sort_order'],
				'value'            => $row['value'],
				// Visibility surfaced so the edit-form privacy selector can show
				// the admin default (field_visibility, falling back to the group)
				// and the member's saved choice (entry_visibility). See workstream D.
				'field_visibility' => $row['field_visibility'] ?? 'public',
				'group_visibility' => $gvis,
				'entry_visibility' => $row['entry_visibility'] ?? null,
			);
		}

		// Build the final groups array, shaping flat vs repeater output.
		$output_groups = array();

		foreach ( $raw_groups as $group ) {
			$entries = $group['_entries'];
			ksort( $entries );

			$out = array(
				'id'         => $group['id'],
				'group_key'  => $group['group_key'],
				'label'      => $group['label'],
				'type'       => $group['type'],
				'visibility' => $group['visibility'],
				'sort_order' => $group['sort_order'],
			);

			if ( 'repeater' === $group['type'] ) {
				$out['entries'] = array();
				foreach ( $entries as $entry_fields ) {
					$sorted = array_values( $entry_fields );
					usort( $sorted, static fn( $a, $b ) => $a['sort_order'] <=> $b['sort_order'] );
					$out['entries'][] = $sorted;
				}
			} else {
				// Flat group — always entry_index 0.
				$flat_fields = isset( $entries[0] ) ? array_values( $entries[0] ) : array();
				usort( $flat_fields, static fn( $a, $b ) => $a['sort_order'] <=> $b['sort_order'] );
				$out['fields'] = $flat_fields;
			}

			$output_groups[] = $out;
		}

		// Collect a flat list of all fields from non-repeater groups for quick access.
		$flat_fields = array();
		foreach ( $output_groups as $group ) {
			if ( isset( $group['fields'] ) ) {
				foreach ( $group['fields'] as $field ) {
					$flat_fields[] = $field;
				}
			}
		}

		$profile = array(
			'user_id'       => $profile_user_id,
			'display_name'  => $wp_user->display_name,
			'avatar_url'    => get_avatar_url( $profile_user_id, array( 'size' => 96 ) ),
			'registered_at' => $wp_user->user_registered,
			'groups'        => $output_groups,
			'fields'        => $flat_fields,
			/**
			 * Editorial member labels (Verified / Expert / Staff) for this user.
			 *
			 * Free ships no label store, so the default is an empty array and the
			 * key is always present for app/REST clients. Pro answers this filter
			 * (ProfileLabelInjector) with an ordered list of label objects keyed
			 * slug/name/color/icon. Absent Pro, the payload degrades to `[]` — no
			 * fatal, no missing key.
			 *
			 * @since 1.1.0
			 *
			 * @param array<int, array<string, mixed>> $labels          Label objects (default empty).
			 * @param int                              $profile_user_id User whose labels to return.
			 */
			'labels'        => (array) apply_filters( 'buddynext_profile_labels', array(), $profile_user_id ),
		);

		wp_cache_set( $cache_key, $profile, self::CACHE_GROUP, self::CACHE_TTL );

		return $profile;
	}

	/**
	 * Return the profile completion score for a user.
	 *
	 * Only flat fields are counted (required + recommended). Repeater fields
	 * are excluded from the score because their count is unbounded and not
	 * meaningful as a completion signal.
	 *
	 * Fires 'buddynext_profile_completion_changed' with the new percentage
	 * every time the score is freshly calculated (cache miss).
	 *
	 * @param int $user_id User to score.
	 * @return array {
	 *     @type int $percent            Overall completion percentage (0–100).
	 *     @type int $required_filled    Number of required fields filled.
	 *     @type int $required_total     Total required fields.
	 *     @type int $recommended_filled Number of non-required fields filled.
	 *     @type int $recommended_total  Total non-required fields.
	 * }
	 */
	public function get_completion_score( int $user_id ): array {
		$cache_key = "completion_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// Fetch only flat-group fields for completion scoring.
		// No user input in this query — safe static interpolation.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$fields = $wpdb->get_results(
			"SELECT f.id, f.is_required
			 FROM {$wpdb->prefix}bn_profile_fields f
			 INNER JOIN {$wpdb->prefix}bn_profile_groups g ON g.id = f.group_id
			 WHERE g.type = 'flat'
			 ORDER BY f.sort_order ASC, f.id ASC",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$fields = (array) $fields;

		if ( empty( $fields ) ) {
			$score = array(
				'percent'            => 0,
				'required_filled'    => 0,
				'required_total'     => 0,
				'recommended_filled' => 0,
				'recommended_total'  => 0,
			);

			wp_cache_set( $cache_key, $score, self::CACHE_GROUP, self::COMPLETION_CACHE_TTL );
			do_action( 'buddynext_profile_completion_changed', $user_id, 0 );

			return $score;
		}

		$field_ids    = array_column( $fields, 'id' );
		$placeholders = implode( ', ', array_fill( 0, count( $field_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$filled_rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT field_id FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND entry_index = 0 AND field_id IN ({$placeholders}) AND value IS NOT NULL AND value <> ''",
				...array_merge( array( $user_id ), $field_ids )
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$filled_ids = array_column( (array) $filled_rows, 'field_id' );
		$filled_set = array_flip( $filled_ids );

		$required_total     = 0;
		$required_filled    = 0;
		$recommended_total  = 0;
		$recommended_filled = 0;

		foreach ( $fields as $field ) {
			$is_required = (bool) $field['is_required'];
			$is_filled   = isset( $filled_set[ $field['id'] ] );

			if ( $is_required ) {
				++$required_total;
				if ( $is_filled ) {
					++$required_filled;
				}
			} else {
				++$recommended_total;
				if ( $is_filled ) {
					++$recommended_filled;
				}
			}
		}

		$total_fields = $required_total + $recommended_total;
		$total_filled = $required_filled + $recommended_filled;
		$percent      = $total_fields > 0 ? (int) round( ( $total_filled / $total_fields ) * 100 ) : 0;

		$score = array(
			'percent'            => $percent,
			'required_filled'    => $required_filled,
			'required_total'     => $required_total,
			'recommended_filled' => $recommended_filled,
			'recommended_total'  => $recommended_total,
		);

		wp_cache_set( $cache_key, $score, self::CACHE_GROUP, self::COMPLETION_CACHE_TTL );
		do_action( 'buddynext_profile_completion_changed', $user_id, $percent );

		return $score;
	}

	/**
	 * Write or refresh this user's entry in bn_search_index.
	 *
	 * Called after profile saves so the search index stays current.
	 *
	 * @param int $user_id User to index.
	 */
	public function index_user( int $user_id ): void {
		$wp_user = get_userdata( $user_id );

		if ( ! $wp_user ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_search_index
				    (object_type, object_id, title, content, author_id, visibility)
				 VALUES ('user', %d, %s, '', %d, 'public')
				 ON DUPLICATE KEY UPDATE title = VALUES(title), updated_at = NOW()",
				$user_id,
				$wp_user->display_name,
				$user_id
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);
	}

	/**
	 * Update a profile group's metadata.
	 *
	 * Allowed $data keys: label, visibility, sort_order.
	 * Unknown keys are ignored. Busts 'all_groups' and 'all_fields' cache keys.
	 *
	 * @param int   $id   Profile group ID.
	 * @param array $data Associative array of fields to update.
	 * @return void
	 */
	public function update_group( int $id, array $data ): void {
		global $wpdb;

		$update = array();
		$format = array();

		if ( isset( $data['label'] ) ) {
			$update['label'] = sanitize_text_field( (string) $data['label'] );
			$format[]        = '%s';
		}

		if ( isset( $data['visibility'] ) ) {
			$update['visibility'] = sanitize_key( (string) $data['visibility'] );
			$format[]             = '%s';
		}

		if ( isset( $data['sort_order'] ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$format[]             = '%d';
		}

		if ( empty( $update ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_groups',
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( 'all_groups', self::CACHE_GROUP );
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
	}

	/**
	 * Delete a profile group.
	 *
	 * All fields belonging to the group are deleted first via delete_field(),
	 * which cascades to bn_profile_values. The group row is removed last.
	 * Busts 'all_groups' and 'all_fields' cache keys.
	 *
	 * @param int $id Profile group ID.
	 * @return void
	 */
	public function delete_group( int $id ): void {
		global $wpdb;

		// Cascade: delete each field (and its stored values) first.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$field_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d",
				$id
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		foreach ( (array) $field_ids as $field_id ) {
			$this->delete_field( (int) $field_id );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_profile_groups',
			array( 'id' => $id ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( 'all_groups', self::CACHE_GROUP );
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
	}

	/**
	 * Update a profile field definition.
	 *
	 * Allowed $data keys: label, type, options (null, array, or JSON string),
	 * is_required, visibility, sort_order. Unknown keys are ignored.
	 * When 'options' is an array it is json_encoded before saving.
	 * Busts 'all_fields' cache key.
	 *
	 * @param int   $id   Profile field ID.
	 * @param array $data Associative array of fields to update.
	 * @return void
	 */
	public function update_field( int $id, array $data ): void {
		global $wpdb;

		$update = array();
		$format = array();

		if ( isset( $data['label'] ) ) {
			$update['label'] = sanitize_text_field( (string) $data['label'] );
			$format[]        = '%s';
		}

		if ( isset( $data['type'] ) ) {
			$update['type'] = sanitize_key( (string) $data['type'] );
			$format[]       = '%s';
		}

		if ( array_key_exists( 'options', $data ) ) {
			if ( null === $data['options'] ) {
				$update['options'] = null;
				$format[]          = '%s';
			} elseif ( is_array( $data['options'] ) ) {
				$update['options'] = wp_json_encode( $data['options'] );
				$format[]          = '%s';
			} else {
				$update['options'] = (string) $data['options'];
				$format[]          = '%s';
			}
		}

		if ( isset( $data['is_required'] ) ) {
			$update['is_required'] = (int) $data['is_required'];
			$format[]              = '%d';
		}

		if ( isset( $data['visibility'] ) ) {
			$update['visibility'] = sanitize_key( (string) $data['visibility'] );
			$format[]             = '%s';
		}

		if ( isset( $data['sort_order'] ) ) {
			$update['sort_order'] = (int) $data['sort_order'];
			$format[]             = '%d';
		}

		if ( empty( $update ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			$update,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
	}

	/**
	 * Delete a profile field and all its stored values.
	 *
	 * Removes all rows from bn_profile_values where field_id = $id first,
	 * then removes the field definition row from bn_profile_fields.
	 * Busts 'all_fields' cache key.
	 *
	 * @param int $id Profile field ID.
	 * @return void
	 */
	public function delete_field( int $id ): void {
		global $wpdb;

		// Capture the field key before removing the definition so its search-
		// mirror usermeta (bn_field_{key}, written by sync_search_mirror) can be
		// purged across every user — otherwise stale mirrors linger and can leak
		// into search results.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$field_key = (string) $wpdb->get_var( $wpdb->prepare( "SELECT field_key FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Delete stored values for this field across all users.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_profile_values',
			array( 'field_id' => $id ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Delete the field definition.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'id' => $id ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Purge the search-mirror usermeta for this field across all users.
		if ( '' !== $field_key ) {
			delete_metadata( 'user', 0, 'bn_field_' . $field_key, '', true );
		}

		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
	}

	/**
	 * Move a profile group one position up or down.
	 *
	 * Swaps sort_order with the adjacent group in the ordered list.
	 * Does nothing if the group is already at the boundary.
	 * Busts 'all_groups' and 'all_fields' cache keys.
	 *
	 * @param int    $id        Profile group ID.
	 * @param string $direction 'up' or 'down'.
	 * @return void
	 */
	public function reorder_group( int $id, string $direction ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all = $wpdb->get_results(
			"SELECT id, sort_order FROM {$wpdb->prefix}bn_profile_groups ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_column( $all, 'id' );
		$pos = array_search( (string) $id, $ids, true );

		if ( false === $pos ) {
			return;
		}

		$swap_pos = 'up' === $direction ? $pos - 1 : $pos + 1;

		if ( ! isset( $ids[ $swap_pos ] ) ) {
			return;
		}

		$a_id    = (int) $ids[ $pos ];
		$b_id    = (int) $ids[ $swap_pos ];
		$a_order = (int) $all[ $pos ]['sort_order'];
		$b_order = (int) $all[ $swap_pos ]['sort_order'];

		// Ensure distinct values so the swap is meaningful.
		if ( $a_order === $b_order ) {
			$a_order = (int) $pos;
			$b_order = (int) $swap_pos;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_groups',
			array( 'sort_order' => $b_order ),
			array( 'id' => $a_id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_groups',
			array( 'sort_order' => $a_order ),
			array( 'id' => $b_id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( 'all_groups', self::CACHE_GROUP );
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
	}

	/**
	 * Move a profile field one position up or down within its group.
	 *
	 * Swaps sort_order with the adjacent field that shares the same group_id.
	 * Does nothing if the field is already at the boundary.
	 * Busts 'all_fields' cache key.
	 *
	 * @param int    $id        Profile field ID.
	 * @param string $direction 'up' or 'down'.
	 * @return void
	 */
	public function reorder_field( int $id, string $direction ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$group_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_id FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d",
				$id
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		if ( $group_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sort_order FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d ORDER BY sort_order ASC, id ASC",
				$group_id
			),
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_column( $all, 'id' );
		$pos = array_search( (string) $id, $ids, true );

		if ( false === $pos ) {
			return;
		}

		$swap_pos = 'up' === $direction ? $pos - 1 : $pos + 1;

		if ( ! isset( $ids[ $swap_pos ] ) ) {
			return;
		}

		$a_id    = (int) $ids[ $pos ];
		$b_id    = (int) $ids[ $swap_pos ];
		$a_order = (int) $all[ $pos ]['sort_order'];
		$b_order = (int) $all[ $swap_pos ]['sort_order'];

		// Ensure distinct values so the swap is meaningful.
		if ( $a_order === $b_order ) {
			$a_order = (int) $pos;
			$b_order = (int) $swap_pos;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'sort_order' => $b_order ),
			array( 'id' => $a_id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'sort_order' => $a_order ),
			array( 'id' => $b_id ),
			array( '%d' ),
			array( '%d' )
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
	}

	/**
	 * Return a flat array of all field definitions (with group_id and group type).
	 *
	 * Used internally by save_profile() and get_completion_score() to build
	 * field lookup maps without the group nesting.
	 *
	 * @return array[] Each element: id, group_id, group_type, field_key, type,
	 *                 options, is_required, is_searchable, visibility, group_visibility.
	 */
	public function get_flat_fields(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT
				f.id,
				f.group_id,
				g.type       AS group_type,
				g.visibility AS group_visibility,
				f.field_key,
				f.label,
				f.type,
				f.options,
				f.is_required,
				f.is_searchable,
				f.visibility,
				f.sort_order
			 FROM {$wpdb->prefix}bn_profile_fields f
			 INNER JOIN {$wpdb->prefix}bn_profile_groups g ON g.id = f.group_id
			 ORDER BY f.sort_order ASC, f.id ASC",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map(
			static function ( array $row ): array {
				return array(
					'id'               => (int) $row['id'],
					'group_id'         => (int) $row['group_id'],
					'group_type'       => $row['group_type'],
					'group_visibility' => $row['group_visibility'] ?? 'public',
					'field_key'        => $row['field_key'],
					'label'            => $row['label'] ?? $row['field_key'],
					'type'             => $row['type'],
					'options'          => isset( $row['options'] ) ? json_decode( (string) $row['options'], true ) : null,
					'is_required'      => (bool) $row['is_required'],
					'is_searchable'    => (bool) $row['is_searchable'],
					'visibility'       => $row['visibility'] ?? 'public',
					'sort_order'       => (int) $row['sort_order'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Visibility restrictiveness rank (higher = more restrictive).
	 *
	 * Order per spec: private > connections > followers > public.
	 *
	 * @param string $visibility One of the visibility_enum values.
	 * @return int Rank; unknown values fall back to the most permissive (public).
	 */
	private static function visibility_rank( string $visibility ): int {
		$ranks = array(
			'public'      => 0,
			'followers'   => 1,
			'connections' => 2,
			'private'     => 3,
		);

		// An unknown value silently ranking as public would leak a field that was
		// meant to be restricted. Surface it (the safe rank stays the MOST
		// restrictive, not public) so a bad ENUM is caught instead of hidden.
		if ( ! isset( $ranks[ $visibility ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Unknown profile-field visibility "%s"; treating as private.', $visibility ) ),
				'1.0.0'
			);
			return 3;
		}

		return $ranks[ $visibility ];
	}

	/**
	 * Clamp a member-chosen visibility to be equal-or-more restrictive than the
	 * field admin default. A member may only TIGHTEN, never loosen.
	 *
	 * @param string|null $chosen  Member-submitted visibility, or null (no choice).
	 * @param string      $default Field admin-default visibility.
	 * @return string|null Clamped visibility, or null when no member choice was made.
	 */
	private function clamp_visibility( ?string $chosen, string $default ): ?string {
		if ( null === $chosen || '' === $chosen ) {
			return null;
		}

		$allowed = array( 'public', 'followers', 'connections', 'private' );
		if ( ! in_array( $chosen, $allowed, true ) ) {
			return null;
		}

		// A looser-than-default choice is clamped up to the admin default.
		if ( self::visibility_rank( $chosen ) < self::visibility_rank( $default ) ) {
			return $default;
		}

		return $chosen;
	}

	/**
	 * Compute the effective visibility for a stored flat value: the MOST
	 * restrictive of (group default, field default, entry override).
	 *
	 * @param array       $field            Flat field definition (group_visibility + visibility).
	 * @param string|null $entry_visibility Clamped per-entry override, or null.
	 * @return string Effective visibility (visibility_enum value).
	 */
	private function effective_visibility( array $field, ?string $entry_visibility ): string {
		$candidates = array(
			(string) ( $field['group_visibility'] ?? 'public' ),
			(string) ( $field['visibility'] ?? 'public' ),
		);

		if ( null !== $entry_visibility ) {
			$candidates[] = $entry_visibility;
		}

		$effective = 'public';
		foreach ( $candidates as $candidate ) {
			if ( self::visibility_rank( $candidate ) > self::visibility_rank( $effective ) ) {
				$effective = $candidate;
			}
		}

		return $effective;
	}

	/**
	 * Write or delete the bn_field_{key} usermeta search mirror per the
	 * searchable_mirror contract.
	 *
	 * The mirror exists ONLY when the field is searchable, its type is free-text
	 * searchable, AND the value's effective visibility resolves to `public` — so
	 * directory search is inherently privacy-safe without per-row checks. Multi
	 * types mirror the comma-joined option LABELS for human-readable matching.
	 * Any other case deletes the mirror.
	 *
	 * @param int         $user_id          User the value belongs to.
	 * @param array       $field            Flat field definition.
	 * @param string      $stored_value     Sanitised value as stored (multi → comma-joined slugs).
	 * @param string|null $entry_visibility Clamped per-entry override, or null.
	 * @return void
	 */
	private function sync_search_mirror( int $user_id, array $field, string $stored_value, ?string $entry_visibility ): void {
		$meta_key   = 'bn_field_' . $field['field_key'];
		$type       = isset( $field['type'] ) ? (string) $field['type'] : 'text';
		$searchable = ! empty( $field['is_searchable'] )
			&& \BuddyNext\Profile\FieldType::is_text_searchable( $type )
			&& 'public' === $this->effective_visibility( $field, $entry_visibility )
			&& '' !== $stored_value;

		if ( ! $searchable ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			delete_user_meta( $user_id, $meta_key );
			return;
		}

		$mirror_value = $this->mirror_value( $field, $stored_value );

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		update_user_meta( $user_id, $meta_key, $mirror_value );
	}

	/**
	 * Recompute the search mirror for every member who has a value for a field
	 * after its definition changes (is_searchable toggled, default visibility
	 * changed). Hooked to buddynext_profile_field_updated so an admin edit
	 * backfills existing members' mirrors instead of waiting for each to re-save.
	 *
	 * Flat fields only — the bn_field_{key} mirror is single-valued per user.
	 *
	 * @param int $field_id Edited field ID.
	 * @return void
	 */
	public function rebuild_field_mirror( int $field_id ): void {
		if ( $field_id <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$def = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.field_key, f.type, f.is_searchable, f.options,
				        f.visibility AS visibility, g.visibility AS group_visibility, g.type AS group_type
				 FROM {$wpdb->prefix}bn_profile_fields f
				 INNER JOIN {$wpdb->prefix}bn_profile_groups g ON g.id = f.group_id
				 WHERE f.id = %d",
				$field_id
			),
			ARRAY_A
		);

		if ( ! $def || 'flat' !== $def['group_type'] ) {
			// Repeater fields have no single-valued mirror; nothing to backfill.
			wp_cache_delete( 'bn_dir_searchable_mirrors', 'buddynext' );
			return;
		}

		$field = array(
			'field_key'        => (string) $def['field_key'],
			'type'             => (string) $def['type'],
			'is_searchable'    => (int) $def['is_searchable'],
			'options'          => isset( $def['options'] ) ? json_decode( (string) $def['options'], true ) : null,
			'visibility'       => (string) $def['visibility'],
			'group_visibility' => (string) $def['group_visibility'],
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, value, entry_visibility
				 FROM {$wpdb->prefix}bn_profile_values
				 WHERE field_id = %d AND entry_index = 0",
				$field_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$this->sync_search_mirror(
				(int) $row['user_id'],
				$field,
				(string) $row['value'],
				isset( $row['entry_visibility'] ) ? $row['entry_visibility'] : null
			);
		}

		// The directory's searchable-mirror key list may have changed.
		wp_cache_delete( 'bn_dir_searchable_mirrors', 'buddynext' );
	}

	/**
	 * Map a stored value to its human-readable mirror representation.
	 *
	 * Multi types store comma-joined option slugs; the mirror records the
	 * comma-joined option LABELS so directory search matches what users see.
	 *
	 * @param array  $field        Flat field definition (with decoded options).
	 * @param string $stored_value Stored value.
	 * @return string Mirror value.
	 */
	private function mirror_value( array $field, string $stored_value ): string {
		$type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		if ( 'multiselect' !== $type ) {
			return $stored_value;
		}

		$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();

		// Build slug => label map; options may be [ slug => label ] or a list of
		// [ 'value' => slug, 'label' => label ] pairs.
		$labels = array();
		foreach ( $options as $opt_key => $opt_val ) {
			if ( is_array( $opt_val ) ) {
				$slug            = (string) ( $opt_val['value'] ?? $opt_val['slug'] ?? $opt_key );
				$labels[ $slug ] = (string) ( $opt_val['label'] ?? $opt_val['value'] ?? $slug );
			} else {
				$labels[ (string) $opt_key ] = (string) $opt_val;
			}
		}

		$slugs  = array_filter( array_map( 'trim', explode( ',', $stored_value ) ) );
		$mapped = array();
		foreach ( $slugs as $slug ) {
			$mapped[] = $labels[ $slug ] ?? $slug;
		}

		return implode( ', ', $mapped );
	}

	/**
	 * Store a custom avatar URL for the given user and bust all related caches.
	 *
	 * Routes all avatar writes through the service layer so that the profile
	 * cache (which embeds the avatar URL) is always invalidated after a change.
	 *
	 * @param int    $user_id Target user ID.
	 * @param string $url     Absolute URL of the uploaded avatar image.
	 * @return void
	 */
	public function update_avatar( int $user_id, string $url ): void {
		update_user_meta( $user_id, 'bn_avatar', $url );
		// Bust profile cache — avatar URL is embedded in cached profile payload.
		$this->bust_profile_cache( $user_id );
	}

	/**
	 * Remove the custom avatar for the given user and bust all related caches.
	 *
	 * @param int $user_id Target user ID.
	 * @return void
	 */
	public function delete_avatar( int $user_id ): void {
		delete_user_meta( $user_id, 'bn_avatar' );
		// Remove the stored image variations from disk — usermeta alone would
		// leave the uploads/bn-avatars/{user_id}/ files orphaned forever.
		( new \BuddyNext\Media\ImageStorageService() )->delete( 'avatar', 'user', $user_id );
		$this->bust_profile_cache( $user_id );
	}

	/**
	 * Insert or update a single profile value row.
	 *
	 * @param int         $user_id          User ID.
	 * @param int         $field_id         Field ID.
	 * @param int         $entry_index      Entry index (0 for flat fields).
	 * @param string      $value            Sanitised field value.
	 * @param string|null $entry_visibility Per-entry visibility override, or null to inherit group default.
	 */
	private function upsert_value( int $user_id, int $field_id, int $entry_index, string $value, ?string $entry_visibility ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d AND entry_index = %d",
				$user_id,
				$field_id,
				$entry_index
			)
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);

		if ( null !== $existing ) {
			$update_data   = array( 'value' => $value );
			$update_format = array( '%s' );

			if ( null !== $entry_visibility ) {
				$update_data['entry_visibility'] = $entry_visibility;
				$update_format[]                 = '%s';
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_profile_values',
				$update_data,
				array(
					'user_id'     => $user_id,
					'field_id'    => $field_id,
					'entry_index' => $entry_index,
				),
				$update_format,
				array( '%d', '%d', '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$insert_data   = array(
				'user_id'     => $user_id,
				'field_id'    => $field_id,
				'entry_index' => $entry_index,
				'value'       => $value,
			);
			$insert_format = array( '%d', '%d', '%d', '%s' );

			if ( null !== $entry_visibility ) {
				$insert_data['entry_visibility'] = $entry_visibility;
				$insert_format[]                 = '%s';
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_profile_values',
				$insert_data,
				$insert_format
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Count posts a user has published in the trailing 7 days — the "+N this
	 * week" growth chip beside the profile post-count stat tile.
	 *
	 * @param int $user_id Profile user ID.
	 * @return int
	 */
	public function post_delta_7d( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
				 WHERE user_id = %d AND status = 'published'
				   AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )",
				$user_id
			)
		);
	}

	/**
	 * Count followers gained in the trailing 7 days.
	 *
	 * Filters status = 'approved' so pending follow-requests (the private-account
	 * gate) don't inflate the delta, keeping it consistent with
	 * FollowService::follower_count().
	 *
	 * @param int $user_id Profile user ID.
	 * @return int
	 */
	public function follower_delta_7d( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows
				 WHERE following_id = %d AND status = 'approved'
				   AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )",
				$user_id
			)
		);
	}

	/**
	 * Count accounts the user started following in the trailing 7 days.
	 *
	 * @param int $user_id Profile user ID.
	 * @return int
	 */
	public function following_delta_7d( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows
				 WHERE follower_id = %d AND status = 'approved'
				   AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )",
				$user_id
			)
		);
	}

	/**
	 * Count connections accepted in the trailing 7 days (either direction).
	 *
	 * @param int $user_id Profile user ID.
	 * @return int
	 */
	public function connection_delta_7d( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'
				   AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )",
				$user_id,
				$user_id
			)
		);
	}
}

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
					'id'            => (int) $row['field_id'],
					'group_id'      => $gid,
					'field_key'     => $row['field_key'],
					'label'         => $row['field_label'],
					'type'          => $row['field_type'],
					'options'       => isset( $row['options'] ) ? json_decode( $row['options'], true ) : null,
					'is_required'   => (bool) $row['is_required'],
					'is_searchable' => (bool) $row['is_searchable'],
					'visibility'    => $row['field_visibility'] ?? 'public',
					'sort_order'    => (int) $row['field_sort_order'],
				);
			}
		}

		$result = array_values( $groups );

		wp_cache_set( 'all_fields', $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
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

		return $groups;
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

					$entry_index      = (int) $entry_index;
					$entry_visibility = isset( $entry_data['_visibility'] )
						? sanitize_key( $entry_data['_visibility'] )
						: null;

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

						$field_id      = (int) $field_by_key[ $field_key ]['id'];
						$sanitized_val = sanitize_textarea_field( (string) $field_value );

						$this->upsert_value( $user_id, $field_id, $entry_index, $sanitized_val, $entry_visibility );
					}
				}

				continue;
			}

			// Flat field.
			if ( ! isset( $field_by_key[ $key ] ) ) {
				continue;
			}

			$field         = $field_by_key[ $key ];
			$field_id      = (int) $field['id'];
			$sanitized_val = sanitize_textarea_field( (string) $value );

			$this->upsert_value( $user_id, $field_id, 0, $sanitized_val, null );

			// Denormalize flat+searchable fields to usermeta for fast directory filtering.
			if ( $field['is_searchable'] ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				update_user_meta( $user_id, 'bn_field_' . $key, $sanitized_val );
			}
		}

		// Handle profile URL slug separately — stored in usermeta, not bn_profile_values.
		if ( array_key_exists( 'profile_slug', $data ) ) {
			$requested_slug = sanitize_title( (string) $data['profile_slug'] );
			if ( '' !== $requested_slug && \BuddyNext\Core\PageRouter::is_slug_available( $requested_slug, $user_id ) ) {
				update_user_meta( $user_id, 'bn_profile_slug', $requested_slug );
			}
		}

		wp_cache_delete( "profile_{$user_id}_viewer_owner", self::CACHE_GROUP );
		wp_cache_delete( "profile_{$user_id}_viewer_follower", self::CACHE_GROUP );
		wp_cache_delete( "profile_{$user_id}_viewer_public", self::CACHE_GROUP );
		wp_cache_delete( "completion_{$user_id}", self::CACHE_GROUP );

		return true;
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

		// Resolve follower status before cache lookup so the cache key captures it.
		$viewer_is_follower = $viewer_id && ! $is_owner
			? buddynext_service( 'follows' )->is_following( $viewer_id, $profile_user_id )
			: false;

		if ( $is_owner ) {
			$cache_key = "profile_{$profile_user_id}_viewer_owner";
		} elseif ( $viewer_is_follower ) {
			$cache_key = "profile_{$profile_user_id}_viewer_follower";
		} else {
			$cache_key = "profile_{$profile_user_id}_viewer_public";
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
				if ( 'connections' === $effective_vis ) {
					// Check mutual connection.
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$is_connected = (bool) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT 1 FROM {$wpdb->prefix}bn_connections
							 WHERE status = 'accepted'
							   AND ( (requester_id = %d AND recipient_id = %d) OR (requester_id = %d AND recipient_id = %d) )",
							$viewer_id,
							$profile_user_id,
							$profile_user_id,
							$viewer_id
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( ! $is_connected ) {
						continue;
					}
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
				'field_id'   => $fid,
				'field_key'  => $row['field_key'],
				'label'      => $row['field_label'],
				'type'       => $row['field_type'],
				'options'    => isset( $row['options'] ) ? json_decode( $row['options'], true ) : null,
				'sort_order' => (int) $row['field_sort_order'],
				'value'      => $row['value'],
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
	 * @return array[] Each element: id, group_id, group_type, field_key, type, is_required, is_searchable.
	 */
	private function get_flat_fields(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT
				f.id,
				f.group_id,
				g.type AS group_type,
				f.field_key,
				f.type,
				f.is_required,
				f.is_searchable,
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
					'id'            => (int) $row['id'],
					'group_id'      => (int) $row['group_id'],
					'group_type'    => $row['group_type'],
					'field_key'     => $row['field_key'],
					'type'          => $row['type'],
					'is_required'   => (bool) $row['is_required'],
					'is_searchable' => (bool) $row['is_searchable'],
					'sort_order'    => (int) $row['sort_order'],
				);
			},
			(array) $rows
		);
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
		wp_cache_delete( "profile_{$user_id}_viewer_owner", self::CACHE_GROUP );
		wp_cache_delete( "profile_{$user_id}_viewer_follower", self::CACHE_GROUP );
		wp_cache_delete( "profile_{$user_id}_viewer_public", self::CACHE_GROUP );
		\BuddyNext\Core\Plugin::bust_avatar_cache( $user_id );
	}

	/**
	 * Remove the custom avatar for the given user and bust all related caches.
	 *
	 * @param int $user_id Target user ID.
	 * @return void
	 */
	public function delete_avatar( int $user_id ): void {
		delete_user_meta( $user_id, 'bn_avatar' );
		wp_cache_delete( "profile_{$user_id}_viewer_owner", self::CACHE_GROUP );
		wp_cache_delete( "profile_{$user_id}_viewer_follower", self::CACHE_GROUP );
		wp_cache_delete( "profile_{$user_id}_viewer_public", self::CACHE_GROUP );
		\BuddyNext\Core\Plugin::bust_avatar_cache( $user_id );
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
}

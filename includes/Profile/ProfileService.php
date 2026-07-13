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
	 * Usermeta prefix for the MEMBERS-tier search mirror.
	 *
	 * Deliberately a different key from the public `bn_field_` mirror rather than a flag on the
	 * same one: the anonymous search path never reads this prefix, so a value visible only to
	 * members cannot leak into a public result by getting a boolean check wrong somewhere.
	 *
	 * @var string
	 */
	public const MEMBERS_MIRROR_PREFIX = 'bn_mfield_';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Completion score cache TTL in seconds (5 minutes).
	 */
	private const COMPLETION_CACHE_TTL = 300;

	/**
	 * Rows deleted per bn_profile_values purge batch (§4.3 batched purge).
	 *
	 * Sized so a single DELETE stays a short, index-backed (field_idx) operation
	 * that never stalls concurrent profile saves at 100k members.
	 */
	private const VALUE_PURGE_BATCH = 500;

	/**
	 * Members processed per search-mirror rebuild batch.
	 *
	 * A field's searchable/visibility flip has to touch every member who holds a
	 * value for it — usermeta mirror write + bn_search_index rebuild. At 100k
	 * members that is not a single-request job, so rebuild_field_mirror() walks
	 * the value rows in keyset batches of this size and re-enqueues itself.
	 */
	private const MIRROR_REBUILD_BATCH = 200;

	/**
	 * Option key prefix for the rebuild_field_mirror() keyset cursor.
	 *
	 * Stores the last user_id processed for a field so the next batch resumes
	 * where the previous one stopped. Deleted when the rebuild completes.
	 */
	private const MIRROR_CURSOR_OPTION = 'bn_field_mirror_cursor_';

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
				g.type_restriction AS group_type_restriction,
				f.id           AS field_id,
				f.field_key,
				f.label        AS field_label,
				f.type         AS field_type,
				f.options,
				f.description,
				f.placeholder,
				f.is_required,
				f.is_searchable,
				f.show_on_register,
				f.is_system    AS field_is_system,
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
					'id'               => $gid,
					'group_key'        => $row['group_key'],
					'label'            => $row['group_label'],
					'type'             => $row['group_type'],
					'visibility'       => $row['group_visibility'],
					'is_system'        => (bool) $row['group_is_system'],
					'sort_order'       => (int) $row['group_sort_order'],
					'type_restriction' => isset( $row['group_type_restriction'] ) ? (string) $row['group_type_restriction'] : '',
					'fields'           => array(),
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
					'description'      => (string) ( $row['description'] ?? '' ),
					'placeholder'      => (string) ( $row['placeholder'] ?? '' ),
					'is_required'      => (bool) $row['is_required'],
					'is_searchable'    => (bool) $row['is_searchable'],
					'show_on_register' => (bool) ( $row['show_on_register'] ?? false ),
					'is_system'        => (bool) ( $row['field_is_system'] ?? false ),
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
		// Accept 'key' as an alias for 'field_key': buddynext_register_member_field()/
		// buddynext_register_profile_field() register with 'key', and without this every
		// code-registered field was silently dropped here (no field_key -> null), so it
		// never reached get_fields() and the save path could not persist it.
		$field_key = sanitize_key( (string) ( $field['field_key'] ?? $field['key'] ?? '' ) );
		$label     = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
		if ( '' === $field_key || '' === $label ) {
			return null;
		}

		$visibility = (string) ( $field['visibility'] ?? 'public' );
		if ( ! in_array( $visibility, array( 'public', 'members', 'followers', 'connections', 'private' ), true ) ) {
			$visibility = 'public';
		}

		return array(
			'id'               => (int) ( $field['id'] ?? 0 ),
			'group_id'         => (int) ( $field['group_id'] ?? $group_id ),
			'field_key'        => $field_key,
			'label'            => $label,
			'type'             => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
			'options'          => $field['options'] ?? null,
			'description'      => sanitize_text_field( (string) ( $field['description'] ?? '' ) ),
			'placeholder'      => sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) ),
			'is_required'      => ! empty( $field['is_required'] ),
			'is_searchable'    => ! empty( $field['is_searchable'] ),
			'show_on_register' => ! empty( $field['show_on_register'] ),
			'is_system'        => ! empty( $field['is_system'] ),
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
			// G2: a member-type-restricted group never surfaces at registration —
			// a registrant has no member type yet, so its fields don't exist for
			// them (per-member-type profiles enrich AFTER the type is assigned).
			if ( '' !== (string) ( $group['type_restriction'] ?? '' ) ) {
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
			"SELECT id, group_key, label, type, visibility, is_system, sort_order, type_restriction
			 FROM {$wpdb->prefix}bn_profile_groups
			 ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groups = array_map(
			static function ( array $row ): array {
				return array(
					'id'               => (int) $row['id'],
					'group_key'        => $row['group_key'],
					'label'            => $row['label'],
					'type'             => $row['type'],
					'visibility'       => $row['visibility'],
					'is_system'        => (bool) $row['is_system'],
					'sort_order'       => (int) $row['sort_order'],
					'type_restriction' => isset( $row['type_restriction'] ) ? (string) $row['type_restriction'] : '',
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
	 * Flush the cached profile GROUP + FIELD definitions.
	 *
	 * The `all_groups` / `all_fields` entries cache the definition rows (label,
	 * visibility, type_restriction, …). Any service that mutates those rows from
	 * outside this class — e.g. MemberTypeService rewriting
	 * bn_profile_groups.type_restriction when a member type is renamed or deleted
	 * — must call this, or the definitions stay stale until the TTL expires and
	 * the change is invisible to every read path (get_groups / get_fields /
	 * get_profile / get_registration_fields).
	 *
	 * @return void
	 */
	public static function flush_definition_cache(): void {
		wp_cache_delete( 'all_groups', self::CACHE_GROUP );
		wp_cache_delete( 'all_fields', self::CACHE_GROUP );
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
	 *                    options, description, placeholder, is_required,
	 *                    is_searchable, visibility, sort_order.
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

				// A group was auto-created — bust the groups cache too, otherwise
				// get_groups() serves the pre-create list until the TTL expires and
				// the new group/field appears to be missing.
				wp_cache_delete( 'all_groups', self::CACHE_GROUP );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$field_key = sanitize_key( (string) ( $data['field_key'] ?? '' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_profile_fields
					(group_id, field_key, label, type, options, description, placeholder, is_required, is_searchable, show_on_register, visibility, sort_order)
				 VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, %d)",
				$group_id,
				$field_key,
				sanitize_text_field( (string) ( $data['label'] ?? '' ) ),
				$data['type'] ?? 'text',
				wp_json_encode( $data['options'] ?? null ),
				sanitize_text_field( (string) ( $data['description'] ?? '' ) ),
				sanitize_text_field( (string) ( $data['placeholder'] ?? '' ) ),
				(int) ( $data['is_required'] ?? 0 ),
				(int) ( $data['is_searchable'] ?? 0 ),
				(int) ( $data['show_on_register'] ?? 0 ),
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
	 * Collect submitted free-text values from a save_profile payload into one string
	 * for a single auto-moderation scan. Skips visibility markers and non-strings.
	 *
	 * @param array<string,mixed> $data Submitted profile data (flat and/or repeater).
	 * @return string
	 */
	private static function collect_text_values( array $data ): string {
		$parts = array();
		array_walk_recursive(
			$data,
			static function ( $value, $key ) use ( &$parts ): void {
				if ( '_visibility' === $key ) {
					return;
				}
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$parts[] = $value;
				}
			}
		);
		return implode( "\n", $parts );
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
	 * Field values that fail sanitisation or the
	 * `buddynext_profile_field_validate` filter are skipped (valid fields still
	 * persist) and recorded; if any field was rejected the method returns a
	 * WP_Error('profile_fields_invalid') carrying a `fields` => message map so
	 * callers (e.g. the admin editor) can surface inline errors instead of
	 * silently reporting success. The user-facing PUT /me/profile endpoint
	 * validates upfront and returns a 422 before reaching this path.
	 *
	 * @param int   $user_id User whose profile to update.
	 * @param array $data    Flat and/or repeater field data.
	 * @return true|\WP_Error True when every submitted field saved; WP_Error
	 *                        (data['fields'] = field => message) when one or more
	 *                        values were rejected.
	 */
	public function save_profile( int $user_id, array $data ): bool|\WP_Error {
		global $wpdb;

		// Auto-moderation: scan the submitted free-text values so banned words / links
		// can't be planted in a bio or custom field. Posts and comments run the same
		// safeguard; profile fields previously did not. One check over the joined text
		// keeps it to a single evaluation before any DB write.
		if ( function_exists( 'buddynext_service' ) ) {
			$profile_text = self::collect_text_values( $data );
			if ( '' !== $profile_text ) {
				$guard = buddynext_service( 'safeguard' );
				if ( is_object( $guard ) && method_exists( $guard, 'check_content' ) ) {
					$verdict = $guard->check_content( $profile_text, '', $user_id );
					if ( is_wp_error( $verdict ) ) {
						return $verdict;
					}
				}
			}
		}

		$flat_fields  = $this->get_flat_fields();
		$field_by_key = array_column( $flat_fields, null, 'field_key' );

		// Layer in code-registered (virtual) fields. get_flat_fields() is a DB-only
		// query, so without this a buddynext_register_member_field() value can't be
		// saved — the flat loop below skips any submitted key not in $field_by_key, and
		// the virtual branch (field id 0) then writes it to bn_field_{key}.
		foreach ( $this->get_fields() as $vgroup ) {
			foreach ( (array) ( $vgroup['fields'] ?? array() ) as $vfield ) {
				$vkey = (string) ( $vfield['field_key'] ?? '' );
				if ( ! empty( $vfield['is_virtual'] ) && '' !== $vkey && ! isset( $field_by_key[ $vkey ] ) ) {
					$field_by_key[ $vkey ] = $vfield;
				}
			}
		}

		// Accumulate per-field rejection messages so the method can report an
		// honest result instead of always claiming success.
		$field_errors = array();

		// Repeater searchable sub-fields can't use the single-valued bn_field_{key}
		// mirror per-entry (each entry would clobber the last). Collect the public,
		// searchable values across ALL entries of a repeater sub-field here and
		// write one space-joined mirror after the loop — the same "join into one
		// mirror" contract flat multi-value fields already use, so directory LIKE
		// search can match a member by a value in any entry. Keyed by field_key;
		// presence of the key (even with an empty list) means "submitted → flush".
		$repeater_mirror = array();

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
							$field_errors[ "{$key}[{$entry_index}][{$field_key}]" ] = $sanitized_val->get_error_message();
							continue;
						}

						$sanitized_val = (string) $sanitized_val;

						// G3: enforce is_required at the persistence layer. A required
						// sub-field submitted empty is rejected (the stored value is
						// never cleared) and reported in the error map — every caller
						// (REST, admin editor, onboarding) gets the same contract.
						if ( ! empty( $field_def['is_required'] ) && '' === $sanitized_val ) {
							$field_errors[ "{$key}[{$entry_index}][{$field_key}]" ] = sprintf(
								/* translators: %s: profile field label. */
								__( '%s is required.', 'buddynext' ),
								(string) ( $field_def['label'] ?? $field_key )
							);
							continue;
						}

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
							$field_errors[ "{$key}[{$entry_index}][{$field_key}]" ] = $validation->get_error_message();
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

						// Collect the value for the aggregated repeater mirror. Mark
						// the field as submitted (empty list) so a now-cleared field
						// gets its stale mirror deleted after the loop, then append
						// only public + searchable + text-type values.
						if ( ! isset( $repeater_mirror[ $field_key ] ) ) {
							$repeater_mirror[ $field_key ] = array(
								'field'  => $field_def,
								'values' => array(),
							);
						}
						$mirror_type = isset( $field_def['type'] ) ? (string) $field_def['type'] : 'text';
						if (
							! empty( $field_def['is_searchable'] )
							&& \BuddyNext\Profile\FieldType::is_text_searchable( $mirror_type )
							&& 'public' === $this->effective_visibility( $field_def, $entry_visibility )
							&& '' !== $sanitized_val
						) {
							$repeater_mirror[ $field_key ]['values'][] = $this->mirror_value( $field_def, $sanitized_val );
						}
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
				$field_errors[ (string) $key ] = $sanitized_val->get_error_message();
				continue;
			}

			$sanitized_val = (string) $sanitized_val;

			// A field that is not ACTIVE for this submission is invisible to whoever is
			// filling the form, so required-enforcement cannot apply to it. Pro's
			// `conditional` field type is the canonical case: its wrapper is hidden by
			// JS but its inner input still posts an empty string.
			//
			// ProfileController already asked this question — but only on the REST
			// self-edit path. The enforcement below runs at the PERSISTENCE layer, which
			// every entry point shares, so the admin member-editor (which calls
			// save_profile() directly, bypassing the controller) had no skip at all: an
			// admin editing a member who has a JS-hidden required conditional field was
			// blocked with a 422 and no way through. Asking here means all three entry
			// points — REST self-edit, admin editor, onboarding — get the same answer.
			//
			// Note this gates the required CHECK only, not the write. An inactive field's
			// value still persists exactly as it does today on the self-edit path (where
			// the controller skips validation and save_profile then writes it), so this
			// removes the spurious 422 without changing what lands in the database.
			$field_active = (bool) apply_filters( 'buddynext_profile_field_is_active', true, $field, $data, $user_id );

			// A field belonging to a group locked to a member type this member does not hold is not
			// theirs to fill — it is never rendered for them — so it cannot be required OF them.
			//
			// Zoho #40859: set a field required, restrict its group to one member type, and every
			// member of every other type was told "Birthday is required." for a field that was not
			// on their screen and never would be. No action available to them cleared it. They
			// could not save their profile again, ever.
			//
			// The REST controller already asked this question. The PERSISTENCE layer — the one the
			// admin member editor and onboarding call directly — never did, so the same bug was
			// fixed on one entry point while still shipping on the other two. Both now call the one
			// predicate.
			if ( ! $this->field_applies_to_user( $field, $user_id ) ) {
				$field_active = false;
			}

			// G3: enforce is_required at the persistence layer (Bugs card
			// 10055873101). Submitting an empty value for a required field is
			// rejected — the stored value is never cleared and the caller gets a
			// per-field error. An OMITTED key is a partial update and stays legal,
			// so the registration path (which only submits its own opted-in
			// fields) is unchanged.
			if ( $field_active && ! empty( $field['is_required'] ) && '' === $sanitized_val ) {
				$field_errors[ (string) $key ] = sprintf(
					/* translators: %s: profile field label. */
					__( '%s is required.', 'buddynext' ),
					(string) ( $field['label'] ?? $key )
				);
				continue;
			}

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
				$field_errors[ (string) $key ] = $validation->get_error_message();
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

			// Code-registered (virtual) field — id 0, no bn_profile_fields row. Its
			// value lives in the bn_field_{key} usermeta that get_profile()'s virtual
			// merge (and, for searchable fields, the directory mirror) reads — the same
			// key the registration-time save path uses. upsert_value() would instead
			// orphan a bn_profile_values row on field_id 0, which nothing reads.
			if ( 0 === $field_id ) {
				$vkey = 'bn_field_' . sanitize_key( (string) $key );
				if ( '' !== $sanitized_val ) {
					update_user_meta( $user_id, $vkey, $sanitized_val );
				} else {
					delete_user_meta( $user_id, $vkey );
				}
				continue;
			}

			// Set-valued flat field (e.g. category_multiselect): stored as ONE
			// bn_profile_values row per pick (entry_index 0..n) so the picks stay
			// individually matchable via the (field_id, value) index — a joined
			// CSV value could never back a "members with pick X" lookup. The
			// sanitised CSV is only the in-memory transport; the search mirror
			// still gets one human-readable value like every other flat field.
			if ( \BuddyNext\Profile\FieldType::is_multi_entry( $field_type ) ) {
				$this->save_multi_entry_value( $user_id, $field_id, $sanitized_val, $entry_visibility );
				$this->sync_search_mirror( $user_id, $field, $sanitized_val, $entry_visibility );

				// Every interests write funnels through this branch (onboarding
				// step 2 / POST /me/interests alias / profile edit), so this is
				// the single choke point for the suggestion engines' signal.
				if ( 'interests' === (string) $key ) {
					/**
					 * Fires when a member's interest picks are saved.
					 *
					 * InterestListener busts the per-viewer follow- and
					 * space-suggestion caches here so suggestions shift on
					 * the next fetch after an interest edit.
					 *
					 * @since 1.0.4
					 *
					 * @param int $user_id The member whose interests changed.
					 */
					do_action( 'buddynext_member_interests_updated', $user_id );
				}
				continue;
			}

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

		// Flush the aggregated repeater search mirrors. A submitted repeater
		// sub-field with one or more public searchable values writes a single
		// space-joined bn_field_{key} mirror; a submitted-but-now-empty one
		// deletes any stale mirror so search results don't lag the data.
		foreach ( $repeater_mirror as $field_key => $mirror ) {
			$meta_key = 'bn_field_' . $field_key;
			$values   = array_values( array_unique( array_filter( $mirror['values'], 'strlen' ) ) );
			if ( empty( $values ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				delete_user_meta( $user_id, $meta_key );
				continue;
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			update_user_meta( $user_id, $meta_key, implode( ' ', $values ) );
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

		// Honest result: any rejected field returns a WP_Error carrying the
		// field => message map (HTTP 422) so the admin editor can show inline
		// errors. Valid fields above were still persisted.
		if ( ! empty( $field_errors ) ) {
			return new \WP_Error(
				'profile_fields_invalid',
				__( 'Some fields could not be saved.', 'buddynext' ),
				array(
					'fields' => $field_errors,
					'status' => 422,
				)
			);
		}

		return true;
	}

	/**
	 * Public cache invalidation for events that change what a profile renders
	 * without going through save_profile() — e.g. a member-type assignment or
	 * removal flips which type-restricted groups exist on the profile (G2).
	 *
	 * @param int $user_id Profile owner whose cached views to invalidate.
	 * @return void
	 */
	public function invalidate_profile_cache( int $user_id ): void {
		$this->bust_profile_cache( $user_id );
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

		// Must match every dimension of the read cache key (member + follower +
		// connection) — the read gate keys on _m%d_f%d_c%d, so an update has to bust
		// all member×follower×connection buckets or a members-tier field goes stale.
		foreach ( array( 0, 1 ) as $member ) {
			foreach ( array( 0, 1 ) as $follower ) {
				foreach ( array( 0, 1 ) as $connection ) {
					wp_cache_delete(
						sprintf( 'profile_%d_viewer_m%d_f%d_c%d', $user_id, $member, $follower, $connection ),
						self::CACHE_GROUP
					);
				}
			}
		}
	}

	/**
	 * Does this field apply to this member at all?
	 *
	 * A profile group can be restricted to a single member type. A member who does not hold that
	 * type never sees the group, so none of its fields exist for them — and a field that does not
	 * exist for you cannot be REQUIRED of you.
	 *
	 * That was the bug (Zoho #40859): set a field required, restrict its group to one member type,
	 * and every member of every OTHER type is told "Birthday is required." for a field that is not
	 * on their screen and never will be. There is no action available to them that clears it. They
	 * cannot save their profile again — ever. It is the worst shape a validation bug can take,
	 * because the member cannot even see what they are being blamed for.
	 *
	 * This predicate is the single answer to that question, deliberately. The REST controller had
	 * grown its own copy of the check while the persistence layer — the one the ADMIN member editor
	 * and onboarding actually call — had none, so the same bug was fixed on one entry point and
	 * still shipping on the other two. One predicate, three callers, no drift.
	 *
	 * An empty restriction means "applies to everyone", which is the default and the common case.
	 *
	 * @param array<string, mixed> $field_def Flat field definition (must carry group_type_restriction).
	 * @param int                  $user_id   Member whose profile is being saved.
	 * @return bool False when the field belongs to a member type this member does not hold.
	 */
	public function field_applies_to_user( array $field_def, int $user_id ): bool {
		$restriction = (string) ( $field_def['group_type_restriction'] ?? '' );

		if ( '' === $restriction ) {
			return true;
		}

		return $restriction === $this->member_type_slug( $user_id );
	}

	/**
	 * A member's member-type slug, or '' when they hold none.
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public function member_type_slug( int $user_id ): string {
		if ( $user_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return '';
		}

		$member_types = buddynext_service( 'member_types' );
		if ( ! is_object( $member_types ) || ! method_exists( $member_types, 'get_user_type' ) ) {
			return '';
		}

		$type = $member_types->get_user_type( $user_id );

		return is_array( $type ) ? (string) ( $type['slug'] ?? '' ) : '';
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

		// A logged-in (non-owner) viewer is a "member" and may see `members`-tier
		// fields; an anonymous viewer (viewer_id 0) may not.
		$viewer_is_member = $viewer_id > 0 && ! $is_owner;

		// Key on owner + member + follower + connection state so each distinct
		// viewer relationship gets its own cache bucket (no cross-relationship
		// leak). The member flag is REQUIRED: without it a logged-in stranger and
		// an anonymous visitor both hash to f0_c0 and a `members`-tier field cached
		// for one would leak to the other.
		if ( $is_owner ) {
			$cache_key = "profile_{$profile_user_id}_viewer_owner";
		} else {
			$cache_key = sprintf(
				'profile_%d_viewer_m%d_f%d_c%d',
				$profile_user_id,
				$viewer_is_member ? 1 : 0,
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
					g.is_system    AS group_is_system,
					g.type_restriction AS group_type_restriction,
					f.id           AS field_id,
					f.field_key,
					f.label        AS field_label,
					f.type         AS field_type,
					f.options,
					f.description,
					f.placeholder,
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

		// G2 (per-member-type profiles): a group restricted to a member type
		// exists only on profiles of members who HOLD that type — resolved once
		// per profile, from the profile owner (not the viewer). NULL/empty
		// restriction = visible to all (the default; existing sites unchanged).
		// Values of non-matching members are retained in bn_profile_values,
		// just never rendered.
		$owner_type_slug = '';
		if ( function_exists( 'buddynext_service' ) ) {
			$member_types = buddynext_service( 'member_types' );
			if ( is_object( $member_types ) && method_exists( $member_types, 'get_user_type' ) ) {
				$owner_type      = $member_types->get_user_type( $profile_user_id );
				$owner_type_slug = is_array( $owner_type ) ? (string) ( $owner_type['slug'] ?? '' ) : '';
			}
		}

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Organise rows into groups → entries → fields.
		$raw_groups = array();

		foreach ( (array) $rows as $row ) {
			$gid   = (int) $row['group_id'];
			$fid   = (int) $row['field_id'];
			$eidx  = (int) ( $row['entry_index'] ?? 0 );
			$gtype = $row['group_type'];
			$gvis  = $row['group_visibility'];

			// G2: skip groups restricted to a member type the profile owner does
			// not hold — applies to every viewer, including the owner (the group
			// does not exist for them until the type is assigned).
			$g_restriction = (string) ( $row['group_type_restriction'] ?? '' );
			if ( '' !== $g_restriction && $g_restriction !== $owner_type_slug ) {
				continue;
			}

			// Enforce group/field/entry visibility for non-owners (most restrictive
			// wins). Rank-driven so the ladder lives in one place (visibility_rank).
			if ( ! $is_owner ) {
				$fvis          = (string) ( $row['field_visibility'] ?? 'public' );
				$evis          = (string) ( $row['entry_visibility'] ?? 'public' );
				$effective_vis = 'public';
				foreach ( array( (string) $gvis, $fvis, $evis ) as $v ) {
					if ( self::visibility_rank( $v ) > self::visibility_rank( $effective_vis ) ) {
						$effective_vis = $v;
					}
				}
				if ( 'private' === $effective_vis ) {
					continue;
				}
				// Reuse the relationship flags resolved once before the cache lookup
				// — no per-row SQL. The cache key already captures each relationship.
				if ( 'connections' === $effective_vis && ! $viewer_is_connection ) {
					continue;
				}
				if ( 'followers' === $effective_vis && ! $viewer_is_follower ) {
					continue;
				}
				// `members` = any logged-in viewer; deny only the anonymous public web.
				if ( 'members' === $effective_vis && ! $viewer_is_member ) {
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
					'is_system'  => (bool) ( $row['group_is_system'] ?? false ),
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
				'description'      => (string) ( $row['description'] ?? '' ),
				'placeholder'      => (string) ( $row['placeholder'] ?? '' ),
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
				'is_system'  => ! empty( $group['is_system'] ),
				'sort_order' => $group['sort_order'],
			);

			if ( 'repeater' === $group['type'] ) {
				$out['entries'] = array();
				foreach ( $entries as $entry_fields ) {
					$sorted = array_values( $entry_fields );
					usort( $sorted, static fn( $a, $b ) => $a['sort_order'] <=> $b['sort_order'] );

					// Surface the entry's saved privacy as `_visibility` so the edit
					// form can pre-select it on reload — it is stored on every value
					// row of the entry, mirroring the `group_key[n][_visibility]`
					// save contract. Without this the per-entry privacy lock always
					// rendered the default even after the member tightened it.
					$entry_out = $sorted;
					foreach ( $sorted as $sorted_field ) {
						if ( isset( $sorted_field['entry_visibility'] ) && '' !== $sorted_field['entry_visibility'] ) {
							$entry_out['_visibility'] = (string) $sorted_field['entry_visibility'];
							break;
						}
					}
					$out['entries'][] = $entry_out;
				}
			} else {
				// Flat group — scalar fields live at entry_index 0; set-valued
				// fields (one row per pick, e.g. category_multiselect) span
				// entry_index 0..n, so their picks are aggregated across every
				// entry into an ordered array value. Missing field or zero picks
				// both yield an empty array — callers never see an error.
				$flat_fields = isset( $entries[0] ) ? $entries[0] : array();

				foreach ( $flat_fields as $flat_fid => $flat_field ) {
					if ( ! \BuddyNext\Profile\FieldType::is_multi_entry( (string) ( $flat_field['type'] ?? '' ) ) ) {
						continue;
					}

					$picks = array();
					foreach ( $entries as $entry_fields ) {
						$pick = $entry_fields[ $flat_fid ]['value'] ?? null;
						if ( null !== $pick && '' !== (string) $pick ) {
							$picks[] = (string) $pick;
						}
					}
					$flat_fields[ $flat_fid ]['value'] = $picks;
				}

				$flat_fields = array_values( $flat_fields );
				usort( $flat_fields, static fn( $a, $b ) => $a['sort_order'] <=> $b['sort_order'] );
				$out['fields'] = $flat_fields;
			}

			$output_groups[] = $out;
		}

		// Merge code-registered (virtual) fields so a developer's
		// buddynext_register_member_field() / buddynext_register_profile_field() fields
		// actually appear on the member's profile + edit UI. get_profile() is the path
		// the edit template and the member REST read, and it builds from the DB — so
		// without this the filter-registered fields only ever showed in get_fields().
		$output_groups = $this->merge_virtual_fields(
			$output_groups,
			$profile_user_id,
			$is_owner,
			$viewer_is_member,
			$viewer_is_follower,
			$viewer_is_connection
		);

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
	 * Merge code-registered (virtual) fields into a profile's group list.
	 *
	 * The `buddynext_profile_fields` filter (populated by
	 * buddynext_register_member_field()/buddynext_register_profile_field()) is applied
	 * to an EMPTY group set to harvest just the virtual fields, each shaped like a DB
	 * field with its value read from the `bn_field_{key}` usermeta the save path
	 * writes. Visibility is gated for non-owners exactly like DB fields, and a virtual
	 * field whose key a DB field already owns is skipped (the DB field wins).
	 *
	 * @param array<int,array<string,mixed>> $output_groups        DB-built groups.
	 * @param int                            $profile_user_id      Profile owner.
	 * @param bool                           $is_owner             Viewer is the owner.
	 * @param bool                           $viewer_is_member     Viewer is a logged-in member (non-owner).
	 * @param bool                           $viewer_is_follower   Viewer follows the owner.
	 * @param bool                           $viewer_is_connection Viewer is connected to the owner.
	 * @return array<int,array<string,mixed>> Groups with virtual fields merged in.
	 */
	private function merge_virtual_fields( array $output_groups, int $profile_user_id, bool $is_owner, bool $viewer_is_member, bool $viewer_is_follower, bool $viewer_is_connection ): array {
		$virtual = (array) apply_filters( 'buddynext_profile_fields', array() );
		if ( empty( $virtual ) ) {
			return $output_groups;
		}

		// Index flat groups by key + collect every field key already present so a DB
		// field is never duplicated by a same-key virtual registration.
		$flat_group_index = array();
		$seen_keys        = array();
		foreach ( $output_groups as $gi => $g ) {
			if ( isset( $g['fields'] ) && is_array( $g['fields'] ) ) {
				$flat_group_index[ (string) ( $g['group_key'] ?? '' ) ] = $gi;
				foreach ( $g['fields'] as $f ) {
					$seen_keys[ (string) ( $f['field_key'] ?? '' ) ] = true;
				}
			}
		}

		foreach ( $virtual as $vg ) {
			$gkey = sanitize_key( (string) ( $vg['group_key'] ?? 'details' ) );
			foreach ( (array) ( $vg['fields'] ?? array() ) as $vf ) {
				$fkey = sanitize_key( (string) ( $vf['key'] ?? $vf['field_key'] ?? '' ) );
				if ( '' === $fkey || isset( $seen_keys[ $fkey ] ) ) {
					continue;
				}

				$fvis = (string) ( $vf['visibility'] ?? 'public' );
				if ( ! $is_owner ) {
					if ( 'private' === $fvis
						|| ( 'connections' === $fvis && ! $viewer_is_connection )
						|| ( 'followers' === $fvis && ! $viewer_is_follower )
						|| ( 'members' === $fvis && ! $viewer_is_member ) ) {
						continue;
					}
				}

				$field = array(
					'field_id'         => 0,
					'field_key'        => $fkey,
					'label'            => (string) ( $vf['label'] ?? $fkey ),
					'type'             => (string) ( $vf['type'] ?? 'text' ),
					'options'          => $vf['options'] ?? null,
					'is_required'      => (bool) ( $vf['is_required'] ?? false ),
					'sort_order'       => (int) ( $vf['sort_order'] ?? 99 ),
					'value'            => get_user_meta( $profile_user_id, 'bn_field_' . $fkey, true ),
					'field_visibility' => $fvis,
					'group_visibility' => 'public',
					'entry_visibility' => null,
					'is_virtual'       => true,
				);

				if ( isset( $flat_group_index[ $gkey ] ) ) {
					$output_groups[ $flat_group_index[ $gkey ] ]['fields'][] = $field;
				} else {
					$output_groups[]           = array(
						'id'         => 0,
						'group_key'  => $gkey,
						'label'      => (string) ( $vg['label'] ?? ucwords( str_replace( array( '_', '-' ), ' ', $gkey ) ) ),
						'type'       => 'flat',
						'visibility' => 'public',
						'sort_order' => (int) ( $vg['sort_order'] ?? 99 ),
						'fields'     => array( $field ),
					);
					$flat_group_index[ $gkey ] = count( $output_groups ) - 1;
				}
				$seen_keys[ $fkey ] = true;
			}
		}

		return $output_groups;
	}

	/**
	 * Return the profile completion score for a user.
	 *
	 * Only flat fields are counted (required + recommended). Repeater fields
	 * are excluded from the score because their count is unbounded and not
	 * meaningful as a completion signal.
	 *
	 * Fires 'buddynext_profile_completion_changed' only when the percentage
	 * actually differs from the last persisted value (bn_profile_completion_pct
	 * user meta) — never on a mere recalculation. Consumers treat the hook as a
	 * member action (wb-gamification awards points on it), so firing it per
	 * cache miss meant every profile pageview on a non-persistent-object-cache
	 * site re-attempted an award: a cooldown toast on every page and a
	 * refresh-to-farm-points exploit.
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
			$this->maybe_fire_completion_changed( $user_id, 0 );

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
		$this->maybe_fire_completion_changed( $user_id, $percent );

		return $score;
	}

	/**
	 * Fire 'buddynext_profile_completion_changed' only on a real change.
	 *
	 * Compares against the last persisted percentage in user meta so the hook
	 * keeps its contract (a change happened) on sites without a persistent
	 * object cache, where get_completion_score() recalculates on every request.
	 * The first-ever calculation records the baseline silently — viewing a
	 * profile is not a completion change.
	 *
	 * @param int $user_id User whose completion was calculated.
	 * @param int $percent Freshly calculated completion percentage.
	 */
	private function maybe_fire_completion_changed( int $user_id, int $percent ): void {
		$stored = get_user_meta( $user_id, 'bn_profile_completion_pct', true );

		if ( '' !== $stored && (int) $stored === $percent ) {
			return;
		}

		update_user_meta( $user_id, 'bn_profile_completion_pct', $percent );

		if ( '' === $stored ) {
			return;
		}

		/**
		 * Fires when a user's profile completion percentage changes.
		 *
		 * Guaranteed to fire only on an actual value change (never on a mere
		 * recalculation), so consumers may treat it as a member action.
		 *
		 * @param int $user_id User whose completion changed.
		 * @param int $percent New completion percentage (0-100).
		 */
		do_action( 'buddynext_profile_completion_changed', $user_id, $percent );
	}

	/**
	 * Return the member-facing profile-strength checklist and percentage.
	 *
	 * This is the metric behind the Profile Strength ring, the mobile hero
	 * chip, and member-facing rewards: every task is VISIBLE and actionable,
	 * so finishing the checklist always lands on 100%. It is intentionally
	 * different from get_completion_score(), whose all-fields percentage feeds
	 * REST/analytics but gave members no task list — driving member-visible
	 * surfaces off it left the ring stuck below 100% with no way to see what
	 * was missing, and let reward systems promise a "profile completed" award
	 * the member could never trigger.
	 *
	 * The task list is derived from the LIVE schema (see the granularity rules
	 * at the derivation below) — never from seeded field/group keys or
	 * hardcoded names, because site owners rename labels, delete the preset
	 * sections, and build custom schemas. Renames and custom fields flow
	 * through automatically; the buddynext_profile_strength_tasks filter
	 * remains the per-site override.
	 *
	 * @param int        $user_id Profile owner.
	 * @param array|null $profile Optional preloaded get_profile( $user_id, $user_id )
	 *                            output — callers that already hold it (the profile
	 *                            template) pass it to avoid a second load.
	 * @return array{tasks: array<int, array{label: string, done: bool}>, done: int, total: int, percent: int}
	 */
	public function get_strength( int $user_id, ?array $profile = null ): array {
		if ( null === $profile ) {
			$profile = $this->get_profile( $user_id, $user_id );
		}

		$groups = array();
		if ( is_array( $profile ) ) {
			foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
				if ( isset( $group['group_key'] ) ) {
					$groups[ (string) $group['group_key'] ] = $group;
				}
			}
		}

		// SCHEMA-DRIVEN, never keyed on seeded field/group keys or English names:
		// site owners rename labels, delete the preset groups, and build custom
		// schemas, so tasks derive from what the schema actually contains.
		// Granularity comes from schema FLAGS only:
		// - SYSTEM flat groups (the code-consumed spine: basics, skills,
		// interests) -> one task per field, labelled from the field's live
		// admin-set label.
		// - NON-SYSTEM flat groups (social links and any custom cluster) ->
		// one rollup task per group, done when any field in it is filled.
		// - Repeater groups (work experience, education, customs) -> one task
		// per group, done when it has at least one non-empty entry.
		$filled = static fn( $value ): bool => '' !== trim(
			is_array( $value ) ? implode( '', array_map( 'strval', $value ) ) : (string) $value
		);

		$tasks = array();

		foreach ( $groups as $group ) {
			$group_label = (string) ( $group['label'] ?? '' );

			if ( 'repeater' === ( $group['type'] ?? 'flat' ) ) {
				$has_entry = false;
				foreach ( (array) ( $group['entries'] ?? array() ) as $entry ) {
					foreach ( (array) $entry as $f ) {
						if ( is_array( $f ) && isset( $f['field_key'] ) && $filled( $f['value'] ?? '' ) ) {
							$has_entry = true;
							break 2;
						}
					}
				}
				$tasks[] = array(
					'label' => sprintf(
						/* translators: %s: profile section name (owner-defined, e.g. "Work Experience") */
						__( 'Add %s', 'buddynext' ),
						$group_label
					),
					'done'  => $has_entry,
				);
				continue;
			}

			$fields = array_filter( (array) ( $group['fields'] ?? array() ), 'is_array' );
			if ( array() === $fields ) {
				continue;
			}

			if ( ! empty( $group['is_system'] ) ) {
				foreach ( $fields as $f ) {
					$tasks[] = array(
						'label' => sprintf(
							/* translators: %s: profile field name (owner-defined, e.g. "Bio") */
							__( 'Add %s', 'buddynext' ),
							(string) ( $f['label'] ?? '' )
						),
						'done'  => $filled( $f['value'] ?? '' ),
					);
				}
				continue;
			}

			$any_filled = false;
			foreach ( $fields as $f ) {
				if ( $filled( $f['value'] ?? '' ) ) {
					$any_filled = true;
					break;
				}
			}
			$tasks[] = array(
				'label' => sprintf(
					/* translators: %s: profile section name (owner-defined, e.g. "Social Links") */
					__( 'Add %s', 'buddynext' ),
					$group_label
				),
				'done'  => $any_filled,
			);
		}

		/**
		 * Filter the profile-strength checklist for a member.
		 *
		 * The defaults above cover BuddyNext's installer-created system schema
		 * (bio / headline / location are the deletion-protected core fields) and
		 * are existence-filtered, but a site running a custom profile schema can
		 * replace or extend the checklist here — each task is
		 * array{label: string, done: bool}. The percentage, the Profile Strength
		 * ring, and the buddynext_profile_strength_changed hook all follow
		 * whatever this filter returns.
		 *
		 * @param array      $tasks   Task list: array{label: string, done: bool}[].
		 * @param int        $user_id Profile owner.
		 * @param array|null $profile get_profile() output the tasks were derived from.
		 */
		$tasks = (array) apply_filters( 'buddynext_profile_strength_tasks', $tasks, $user_id, $profile );

		$total   = count( $tasks );
		$done    = count( array_filter( array_column( $tasks, 'done' ) ) );
		$percent = $total > 0 ? (int) round( ( $done / $total ) * 100 ) : 0;

		$this->maybe_fire_strength_changed( $user_id, $percent );

		return array(
			'tasks'   => $tasks,
			'done'    => $done,
			'total'   => $total,
			'percent' => $percent,
		);
	}

	/**
	 * Fire 'buddynext_profile_strength_changed' only on a real change.
	 *
	 * Same change-gate pattern as maybe_fire_completion_changed(): compared
	 * against the last persisted percentage in user meta, first calculation
	 * records the baseline silently.
	 *
	 * @param int $user_id Profile owner.
	 * @param int $percent Freshly calculated strength percentage.
	 */
	private function maybe_fire_strength_changed( int $user_id, int $percent ): void {
		$stored = get_user_meta( $user_id, 'bn_profile_strength_pct', true );

		if ( '' !== $stored && (int) $stored === $percent ) {
			return;
		}

		update_user_meta( $user_id, 'bn_profile_strength_pct', $percent );

		if ( '' === $stored ) {
			return;
		}

		/**
		 * Fires when a member's profile-strength percentage changes.
		 *
		 * Strength is the curated member-facing checklist (the Profile Strength
		 * ring) — the metric the member actually sees. Reward systems keying a
		 * "profile completed" promise should listen HERE (percent === 100), not
		 * to buddynext_profile_completion_changed, whose all-fields score can
		 * stay below 100 forever while the member's widget says "All set".
		 * Guaranteed to fire only on an actual value change.
		 *
		 * @param int $user_id Member whose profile strength changed.
		 * @param int $percent New strength percentage (0-100).
		 */
		do_action( 'buddynext_profile_strength_changed', $user_id, $percent );
	}

	/**
	 * Write or refresh this user's entry in bn_search_index.
	 *
	 * Called after profile saves so the search index stays current. The `content`
	 * column carries the member's headline, bio, and every PUBLIC searchable
	 * profile-field value (the bn_field_{key} mirror is written public-only, so
	 * private values are never indexed), making a member findable by their profile
	 * attributes — not just their display name — in BOTH the unified search and the
	 * directory search (which both read this one index). Title stays the display name.
	 *
	 * @param int $user_id User to index.
	 */
	public function index_user( int $user_id ): void {
		$wp_user = get_userdata( $user_id );

		if ( ! $wp_user ) {
			return;
		}

		$parts    = array();
		$headline = (string) get_user_meta( $user_id, 'bn_headline', true );
		if ( '' !== $headline ) {
			$parts[] = $headline;
		}
		$bio = (string) get_user_meta( $user_id, 'bn_field_bio', true );
		if ( '' !== $bio ) {
			$parts[] = $bio;
		}
		// PUBLIC searchable field values → content (matched for everyone, including strangers).
		// MEMBERS-tier values            → content_members (matched only for a logged-in viewer).
		//
		// Two columns, not one column plus a filter, because the anonymous query then never even
		// NAMES content_members — the privacy boundary is structural instead of conditional, and
		// there is no flag left to get wrong.
		$member_parts = array();
		$directory    = buddynext_service( 'member_directory' );
		if ( is_object( $directory ) && method_exists( $directory, 'searchable_mirror_keys' ) ) {
			foreach ( $directory->searchable_mirror_keys() as $mirror_key ) {
				$mirror_val = (string) get_user_meta( $user_id, (string) $mirror_key, true );
				if ( '' !== $mirror_val ) {
					$parts[] = $mirror_val;
				}

				// Same field, members-tier mirror.
				$members_key = self::MEMBERS_MIRROR_PREFIX . substr( (string) $mirror_key, strlen( 'bn_field_' ) );
				$members_val = (string) get_user_meta( $user_id, $members_key, true );
				if ( '' !== $members_val ) {
					$member_parts[] = $members_val;
				}
			}
		}
		$content         = trim( implode( ' ', array_values( array_unique( $parts ) ) ) );
		$content_members = trim( implode( ' ', array_values( array_unique( $member_parts ) ) ) );

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_search_index
				    (object_type, object_id, title, content, content_members, author_id, visibility)
				 VALUES ('user', %d, %s, %s, %s, %d, 'public')
				 ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), content_members = VALUES(content_members), updated_at = NOW()",
				$user_id,
				$wp_user->display_name,
				$content,
				$content_members,
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
	 * Busts 'all_groups' and 'all_fields' cache keys. System groups cannot be
	 * deleted (returns WP_Error).
	 *
	 * @param int $id Profile group ID.
	 * @return true|\WP_Error True on success; WP_Error('system_group') for a built-in group.
	 */
	public function delete_group( int $id ) {
		global $wpdb;

		// Guard: system groups (Basic Info, etc.) must never be destroyed — deleting
		// one cascades away its fields and every member's stored values. The admin UI
		// only hides the button, so without this guard a direct DELETE request wipes a
		// core group and all its data.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$is_system = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT is_system FROM {$wpdb->prefix}bn_profile_groups WHERE id = %d", $id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 === $is_system ) {
			return new \WP_Error(
				'system_group',
				__( 'This is a built-in profile group and cannot be deleted.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

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
			// Force: the group itself passed the system-group guard above, so its
			// fields go with it even if one carries a (misplaced) system flag.
			$this->delete_field( (int) $field_id, true );
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

		return true;
	}

	/**
	 * Update a profile field definition.
	 *
	 * Allowed $data keys: label, type, options (null, array, or JSON string),
	 * description, placeholder, is_required, is_searchable, show_on_register,
	 * visibility, sort_order.
	 * Unknown keys are ignored.
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

		if ( isset( $data['description'] ) ) {
			$update['description'] = sanitize_text_field( (string) $data['description'] );
			$format[]              = '%s';
		}

		if ( isset( $data['placeholder'] ) ) {
			$update['placeholder'] = sanitize_text_field( (string) $data['placeholder'] );
			$format[]              = '%s';
		}

		if ( isset( $data['is_required'] ) ) {
			$update['is_required'] = (int) $data['is_required'];
			$format[]              = '%d';
		}

		if ( isset( $data['is_searchable'] ) ) {
			$update['is_searchable'] = (int) $data['is_searchable'];
			$format[]                = '%d';
		}

		if ( isset( $data['show_on_register'] ) ) {
			$update['show_on_register'] = (int) $data['show_on_register'];
			$format[]                   = '%d';
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

		// Mirror the admin editor: announce the definition change so existing
		// members' search mirrors are backfilled when is_searchable/visibility
		// flips (rebuild_field_mirror is wired to this in Plugin.php). Without
		// this the REST path would persist the toggle but leave stale mirrors.
		do_action( 'buddynext_profile_field_updated', $id );
	}

	/**
	 * Delete a profile field and all its stored values.
	 *
	 * Removes all rows from bn_profile_values where field_id = $id first,
	 * then removes the field definition row from bn_profile_fields.
	 * Busts 'all_fields' cache key.
	 *
	 * System fields (is_system = 1: bio, headline, location) cannot be deleted —
	 * search indexing, directory cards/filters, and the profile hero read them
	 * by key, so removing one silently breaks those surfaces. The admin UI hides
	 * the delete control, but this guard is the enforcement: a direct REST or
	 * admin-post request is refused. Owners can still relabel, reorder, or
	 * change visibility on a system field.
	 *
	 * @param int  $id    Profile field ID.
	 * @param bool $force Internal: bypass the system-field guard. Only
	 *                    delete_group() passes true, so a (non-system) group
	 *                    delete can cascade through its fields.
	 * @return true|\WP_Error True on success; WP_Error('system_field') for a protected field.
	 */
	public function delete_field( int $id, bool $force = false ): bool|\WP_Error {
		global $wpdb;

		// Capture the field key before removing the definition so its search-
		// mirror usermeta (bn_field_{key}, written by sync_search_mirror) can be
		// purged across every user — otherwise stale mirrors linger and can leak
		// into search results. is_system rides the same lookup for the guard.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$def = $wpdb->get_row(
			$wpdb->prepare( "SELECT field_key, is_system FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$field_key = is_array( $def ) ? (string) ( $def['field_key'] ?? '' ) : '';

		if ( ! $force && is_array( $def ) && ! empty( $def['is_system'] ) ) {
			return new \WP_Error(
				'system_field',
				__( 'This is a core field used by search and member cards - it cannot be deleted.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Delete the field definition FIRST so the field disappears from every
		// surface (admin row, edit form, profile view — all join on the
		// definition) immediately; the orphaned bn_profile_values rows are then
		// purged in the background (§4.3), never as one unbounded DELETE that
		// could hold a lock storm at 100k members.
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

		// Batched value purge (§4.3, BACKGROUND-JOBS.md pattern 2: reactive
		// single-shot). With Action Scheduler present the worker chunks through
		// bn_profile_values and re-enqueues itself while full batches remain;
		// absent AS the same worker drains inline, still bounded per query.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_purge_field_values', array( $id ), 'buddynext' );
		} else {
			while ( self::VALUE_PURGE_BATCH === $this->purge_field_values( $id ) ) {
				continue;
			}
		}

		return true;
	}

	/**
	 * Delete one bounded batch of orphaned values for a removed field.
	 *
	 * Runs as the buddynext_purge_field_values Action Scheduler worker (group
	 * 'buddynext'); a full batch re-enqueues itself for the next chunk. The
	 * field definition is already gone when this runs, so every reader
	 * (INNER JOIN on bn_profile_fields) ignores the rows in the interim.
	 *
	 * @param int $field_id Deleted field whose stored values to purge.
	 * @return int Rows deleted this batch.
	 */
	public function purge_field_values( int $field_id ): int {
		if ( $field_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bn_profile_values WHERE field_id = %d LIMIT %d",
				$field_id,
				self::VALUE_PURGE_BATCH
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( self::VALUE_PURGE_BATCH === $deleted && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'buddynext_purge_field_values', array( $field_id ), 'buddynext' );
		}

		return $deleted;
	}

	/**
	 * Count DISTINCT members holding a stored value for any of the given fields.
	 *
	 * One indexed COUNT (field_idx on bn_profile_values) — powers the
	 * impact-confirm on destructive deletes (§4.2: "permanently deletes values
	 * for N members") for both single fields and whole groups.
	 *
	 * @param int[] $field_ids Field definition ids.
	 * @return int
	 */
	public function count_users_with_field_values( array $field_ids ): int {
		$field_ids = array_values( array_filter( array_map( 'intval', $field_ids ) ) );
		if ( empty( $field_ids ) ) {
			return 0;
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $field_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bn_profile_values WHERE field_id IN ({$placeholders})",
				...$field_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Per-field and per-group affected-member counts for the field manager.
	 *
	 * Two aggregate queries total (never one COUNT per row) so the admin screen
	 * renders the §4.2 impact numbers at any schema size. Keys are definition
	 * ids; a field/group with no stored values is simply absent from its map.
	 *
	 * @return array{fields: array<int, int>, groups: array<int, int>}
	 */
	public function value_user_counts(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$field_rows = $wpdb->get_results(
			"SELECT field_id, COUNT(DISTINCT user_id) AS users
			   FROM {$wpdb->prefix}bn_profile_values
			  GROUP BY field_id",
			ARRAY_A
		);

		$group_rows = $wpdb->get_results(
			"SELECT f.group_id, COUNT(DISTINCT v.user_id) AS users
			   FROM {$wpdb->prefix}bn_profile_values v
			  INNER JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id
			  GROUP BY f.group_id",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$fields = array();
		foreach ( (array) $field_rows as $row ) {
			$fields[ (int) $row['field_id'] ] = (int) $row['users'];
		}

		$groups = array();
		foreach ( (array) $group_rows as $row ) {
			$groups[ (int) $row['group_id'] ] = (int) $row['users'];
		}

		return array(
			'fields' => $fields,
			'groups' => $groups,
		);
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
				g.group_key  AS group_key,
				g.type       AS group_type,
				g.visibility AS group_visibility,
				g.type_restriction AS group_type_restriction,
				f.field_key,
				f.label,
				f.type,
				f.options,
				f.description,
				f.placeholder,
				f.is_required,
				f.is_searchable,
				f.is_system,
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
					'id'                     => (int) $row['id'],
					'group_id'               => (int) $row['group_id'],
					'group_key'              => (string) ( $row['group_key'] ?? '' ),
					'group_type'             => $row['group_type'],
					'group_visibility'       => $row['group_visibility'] ?? 'public',
					'group_type_restriction' => (string) ( $row['group_type_restriction'] ?? '' ),
					'field_key'              => $row['field_key'],
					'label'                  => $row['label'] ?? $row['field_key'],
					'type'                   => $row['type'],
					'options'                => isset( $row['options'] ) ? json_decode( (string) $row['options'], true ) : null,
					'description'            => (string) ( $row['description'] ?? '' ),
					'placeholder'            => (string) ( $row['placeholder'] ?? '' ),
					'is_required'            => (bool) $row['is_required'],
					'is_searchable'          => (bool) $row['is_searchable'],
					'is_system'              => (bool) ( $row['is_system'] ?? false ),
					'visibility'             => $row['visibility'] ?? 'public',
					'sort_order'             => (int) $row['sort_order'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * Visibility restrictiveness rank (higher = more restrictive).
	 *
	 * Order per spec: private > connections > followers > members > public.
	 * `members` = any logged-in member (broader than followers/connections, but
	 * narrower than the public web).
	 *
	 * @param string $visibility One of the visibility_enum values.
	 * @return int Rank; unknown values fall back to the most restrictive (private).
	 */
	private static function visibility_rank( string $visibility ): int {
		$ranks = array(
			'public'      => 0,
			'members'     => 1,
			'followers'   => 2,
			'connections' => 3,
			'private'     => 4,
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
			return 4;
		}

		return $ranks[ $visibility ];
	}

	/**
	 * Clamp a member-chosen visibility to be equal-or-more restrictive than the
	 * field admin default. A member may only TIGHTEN, never loosen.
	 *
	 * @param string|null $chosen        Member-submitted visibility, or null (no choice).
	 * @param string      $admin_default Field admin-default visibility.
	 * @return string|null Clamped visibility, or null when no member choice was made.
	 */
	private function clamp_visibility( ?string $chosen, string $admin_default ): ?string {
		if ( null === $chosen || '' === $chosen ) {
			return null;
		}

		$allowed = array( 'public', 'members', 'followers', 'connections', 'private' );
		if ( ! in_array( $chosen, $allowed, true ) ) {
			return null;
		}

		// A looser-than-default choice is clamped up to the admin default.
		if ( self::visibility_rank( $chosen ) < self::visibility_rank( $admin_default ) ) {
			return $admin_default;
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
		$public_key  = 'bn_field_' . $field['field_key'];
		$members_key = self::MEMBERS_MIRROR_PREFIX . $field['field_key'];
		$type        = isset( $field['type'] ) ? (string) $field['type'] : 'text';

		$indexable = ! empty( $field['is_searchable'] )
			&& \BuddyNext\Profile\FieldType::is_text_searchable( $type )
			&& '' !== $stored_value;

		$visibility = $this->effective_visibility( $field, $entry_visibility );

		// The mirror a value lands in is decided by WHO MAY SEE IT, and nothing else.
		//
		// `public`  → bn_field_{key}   → indexed into search_index.content         → anyone.
		// `members` → bn_mfield_{key}  → indexed into search_index.content_members → logged in.
		// anything else (followers / connections / private) → NO mirror at all.
		//
		// Before this, only `public` was ever mirrored, so a field marked searchable but visible
		// to members was silently un-searchable: the owner ticked the box, saved, and nothing told
		// them it could not work (Zoho #40859). Ticking "searchable" on a members-visible field now
		// means what an owner would assume it means — a logged-in member can find it, a stranger
		// cannot.
		//
		// followers / connections stay unindexed on purpose: answering them means resolving the
		// searcher's relationship to every candidate at query time, which is a different (and much
		// heavier) feature. What matters is that we no longer PRETEND to index them — the admin
		// says so instead of the box quietly doing nothing.
		$public_value  = ( $indexable && 'public' === $visibility ) ? $this->mirror_value( $field, $stored_value ) : '';
		$members_value = ( $indexable && 'members' === $visibility ) ? $this->mirror_value( $field, $stored_value ) : '';

		$this->write_mirror( $user_id, $public_key, $public_value );
		$this->write_mirror( $user_id, $members_key, $members_value );
	}

	/**
	 * Write or delete one search-mirror usermeta row.
	 *
	 * @param int    $user_id  Member.
	 * @param string $meta_key Mirror key.
	 * @param string $value    Value; '' deletes the mirror.
	 * @return void
	 */
	private function write_mirror( int $user_id, string $meta_key, string $value ): void {
		if ( '' === $value ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			delete_user_meta( $user_id, $meta_key );

			return;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		update_user_meta( $user_id, $meta_key, $value );
	}

	/**
	 * Recompute the search mirror AND the FULLTEXT search index for every member
	 * who has a value for a field after its definition changes (is_searchable
	 * toggled, default visibility changed). Hooked to
	 * buddynext_profile_field_updated so an admin edit backfills existing members
	 * instead of waiting for each of them to re-save their profile.
	 *
	 * Two stores must move together:
	 *   1. the bn_field_{key} usermeta mirror (sync_search_mirror), and
	 *   2. the bn_search_index.content FULLTEXT column (index_user), which is what
	 *      BOTH the members directory and unified search actually read.
	 * Backfilling only (1) — the previous behaviour — left existing members
	 * unfindable by the newly-searchable field until each re-saved their profile.
	 *
	 * Big-site shape: the value rows are walked in keyset batches of
	 * MIRROR_REBUILD_BATCH (cursor persisted per field) and each batch re-enqueues
	 * itself on its own hook via Action Scheduler, so a 100k-member field flip
	 * never runs inline in the admin request. The per-user reindex is likewise
	 * queued (buddynext_async_index_user), which also dodges the per-request
	 * static memo in MemberDirectoryService::searchable_mirror_keys() — each queued
	 * run resolves a fresh key list.
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

		// Bust the definition caches BEFORE any indexing runs. ProfileFieldsManager
		// fires buddynext_profile_field_updated *before* it clears 'all_fields', and
		// searchable_mirror_keys() derives its key list from get_fields() — so
		// without this the reindex would read the pre-edit field definitions and
		// rebuild the index with the OLD searchable key list.
		self::flush_definition_cache();
		wp_cache_delete( 'bn_dir_searchable_mirrors', 'buddynext' );
		// The wp_cache key was being cleared while a per-request memo of the SAME list was not, so
		// anything that had already asked in this request kept indexing with the pre-edit key list.
		MemberDirectoryService::flush_mirror_keys_memo();

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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $def || 'flat' !== $def['group_type'] ) {
			// Repeater fields have no single-valued mirror; nothing to backfill.
			delete_option( self::MIRROR_CURSOR_OPTION . $field_id );
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

		$is_multi   = \BuddyNext\Profile\FieldType::is_multi_entry( (string) $def['type'] );
		$queued     = function_exists( 'as_enqueue_async_action' );
		$cursor     = (int) get_option( self::MIRROR_CURSOR_OPTION . $field_id, 0 );
		$batch_size = self::MIRROR_REBUILD_BATCH;

		do {
			$rows   = $this->field_value_batch( $field_id, $is_multi, $cursor, $batch_size );
			$found  = count( $rows );
			$cursor = $this->rebuild_mirror_batch( $rows, $field, $queued );

			if ( $found < $batch_size ) {
				// Last batch — the rebuild is complete.
				delete_option( self::MIRROR_CURSOR_OPTION . $field_id );
				return;
			}

			if ( $queued ) {
				// Park the cursor and hand the next batch to Action Scheduler. The
				// only listener on this hook is rebuild_field_mirror() itself
				// (Plugin.php), so re-firing it resumes exactly here.
				update_option( self::MIRROR_CURSOR_OPTION . $field_id, $cursor, false );
				as_enqueue_async_action( 'buddynext_profile_field_updated', array( $field_id ), 'buddynext' );
				return;
			}

			// No Action Scheduler on this host: finish inline, batch by batch, so a
			// searchable flip is never silently left half-applied.
		} while ( true );
	}

	/**
	 * Fetch one keyset batch of a field's stored values, ordered by user_id.
	 *
	 * Keyset (user_id > cursor) rather than OFFSET so the walk stays index-backed
	 * and cannot skip or repeat rows when members save concurrently.
	 *
	 * @param int  $field_id Field whose values to read.
	 * @param bool $is_multi Whether the field is multi-entry (one row per pick).
	 * @param int  $cursor   Last user_id processed (0 = start).
	 * @param int  $limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>> Rows of user_id, value, entry_visibility.
	 */
	private function field_value_batch( int $field_id, bool $is_multi, int $cursor, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $is_multi ) {
			// Set-valued field: one row per pick — rejoin every user's picks so the
			// rebuilt mirror covers the full selection, not just entry 0.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id,
					        GROUP_CONCAT(value ORDER BY entry_index SEPARATOR ',') AS value,
					        MIN(entry_visibility) AS entry_visibility
					 FROM {$wpdb->prefix}bn_profile_values
					 WHERE field_id = %d AND user_id > %d
					 GROUP BY user_id
					 ORDER BY user_id ASC
					 LIMIT %d",
					$field_id,
					$cursor,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, value, entry_visibility
					 FROM {$wpdb->prefix}bn_profile_values
					 WHERE field_id = %d AND entry_index = 0 AND user_id > %d
					 ORDER BY user_id ASC
					 LIMIT %d",
					$field_id,
					$cursor,
					$limit
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (array) $rows;
	}

	/**
	 * Rewrite the usermeta mirror + reindex each member in one rebuild batch.
	 *
	 * @param array<int, array<string, mixed>> $rows   Value rows (user_id ASC).
	 * @param array<string, mixed>             $field  Flat field definition.
	 * @param bool                             $queued Whether Action Scheduler is available.
	 * @return int The highest user_id processed (the next keyset cursor).
	 */
	private function rebuild_mirror_batch( array $rows, array $field, bool $queued ): int {
		$cursor = 0;

		foreach ( $rows as $row ) {
			$user_id = (int) $row['user_id'];

			$this->sync_search_mirror(
				$user_id,
				$field,
				(string) $row['value'],
				$row['entry_visibility'] ?? null
			);

			// Rebuild bn_search_index.content for this member. Queued when possible:
			// buddynext_index_user runs index_user() INLINE by design
			// (SearchIndexListener), which would mean one FULLTEXT upsert per member
			// inside the admin request.
			if ( $queued ) {
				as_enqueue_async_action( 'buddynext_async_index_user', array( $user_id ), 'buddynext' );
			} else {
				do_action( 'buddynext_index_user', $user_id );
			}

			$cursor = max( $cursor, $user_id );
		}

		return $cursor;
	}

	/**
	 * Map a stored value to its human-readable mirror representation.
	 *
	 * Choice types (select, radio, and the multiselect family) store option
	 * SLUGS; the mirror must record the option LABELS, because the mirror is what
	 * directory search matches against and members search for what they can see.
	 *
	 * A slug is not a quiet variation on its label — sanitize_title() runs
	 * remove_accents(), which is locale-dependent and lossy in both directions:
	 *
	 *   - German (de_DE): 'Flügelhorn' slugs to 'fluegelhorn' (ü -> ue), while the
	 *     search term 'Flügelhorn' collates to 'flugelhorn' under utf8mb4_*_ci
	 *     (ü -> u). Mirroring the slug made the member PERMANENTLY unfindable.
	 *     On an English site the same code is harmless (ü -> u on both sides),
	 *     which is exactly why this was reported as "cannot reproduce".
	 *   - Any locale: 'French Horn' slugs to 'french-horn', so every multi-word
	 *     label was unfindable on every site.
	 *
	 * FieldType::searchable_text() already resolves every type to its label text,
	 * so this delegates rather than keeping a second, subtly-wrong copy of that
	 * mapping. The caller has already established is_text_searchable() (see the
	 * $indexable gate in write_search_mirrors()), so the type gate inside
	 * searchable_text() is a no-op here.
	 *
	 * @param array  $field        Flat field definition (with decoded options).
	 * @param string $stored_value Stored value (option slug for choice types).
	 * @return string Mirror text — option LABELS for choice types, value otherwise.
	 */
	private function mirror_value( array $field, string $stored_value ): string {
		return \BuddyNext\Profile\FieldType::searchable_text( $field, $stored_value );
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
	 * Delete every stored profile-field value for a user (the canonical
	 * bn_profile_values rows). Used when removing an account — e.g. the demo
	 * seeder's cleanup — so values do not orphan after the user is gone. The
	 * searchable usermeta mirror is removed by wp_delete_user with the rest of
	 * the user's meta; shared field DEFINITIONS are never touched.
	 *
	 * @param int $user_id User whose stored values to clear.
	 * @return void
	 */
	public function delete_user_values( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_profile_values', array( 'user_id' => $user_id ), array( '%d' ) );
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
	 * Persist a set-valued flat field as one bn_profile_values row per pick.
	 *
	 * The sanitised transport value is a comma-joined list (e.g. category IDs);
	 * each element is written at its ordinal entry_index, and surplus rows from
	 * a previous, larger selection are deleted. Bounded by the pick count —
	 * never a table scan. An empty value clears every row (the field reads as
	 * "no picks").
	 *
	 * @param int         $user_id          User ID.
	 * @param int         $field_id         Field ID.
	 * @param string      $joined           Sanitised comma-joined picks ('' clears all).
	 * @param string|null $entry_visibility Clamped per-entry visibility, or null.
	 * @return void
	 */
	private function save_multi_entry_value( int $user_id, int $field_id, string $joined, ?string $entry_visibility ): void {
		global $wpdb;

		$picks = '' === $joined ? array() : explode( ',', $joined );

		foreach ( $picks as $index => $pick ) {
			$this->upsert_value( $user_id, $field_id, (int) $index, (string) $pick, $entry_visibility );
		}

		// Drop rows beyond the new selection (a previous save had more picks).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}bn_profile_values
				  WHERE user_id = %d AND field_id = %d AND entry_index >= %d",
				$user_id,
				$field_id,
				count( $picks )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

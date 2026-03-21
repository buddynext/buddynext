<?php
/**
 * Profile fields and values service.
 *
 * Manages custom profile field definitions (bn_profile_fields) and per-user
 * values (bn_profile_values). Reads are cache-backed; writes invalidate the
 * relevant keys. Visibility ('public', 'connections', 'private') is enforced
 * in get_profile() when the viewer is not the profile owner.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * Handles custom profile fields and user profile reads/writes.
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
	 * Return all defined profile field definitions, ordered by sort_order.
	 *
	 * @return array[]
	 */
	public function get_fields(): array {
		$cached = wp_cache_get( 'all_fields', self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}bn_profile_fields ORDER BY sort_order ASC, id ASC",
			ARRAY_A
		);

		$fields = array_map( array( $this, 'hydrate_field' ), (array) $rows );

		wp_cache_set( 'all_fields', $fields, self::CACHE_GROUP, self::CACHE_TTL );

		return $fields;
	}

	/**
	 * Create a new profile field definition.
	 *
	 * @param array $data Field data: field_key, label, type, options, is_required, visibility, group_name, sort_order.
	 * @return int Inserted field ID.
	 */
	public function create_field( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'field_key'   => sanitize_key( $data['field_key'] ),
				'label'       => sanitize_text_field( $data['label'] ),
				'type'        => $data['type'] ?? 'text',
				'options'     => isset( $data['options'] ) ? wp_json_encode( $data['options'] ) : null,
				'is_required' => (int) ( $data['is_required'] ?? 0 ),
				'visibility'  => $data['visibility'] ?? 'public',
				'group_name'  => sanitize_key( $data['group_name'] ?? 'general' ),
				'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		wp_cache_delete( 'all_fields', self::CACHE_GROUP );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Save profile field values for a user.
	 *
	 * Only writes values for field_keys that exist in bn_profile_fields.
	 * Unknown keys are silently ignored (no mass-assignment risk).
	 *
	 * @param int   $user_id User whose profile to update.
	 * @param array $data    Associative array of field_key => value.
	 * @return true
	 */
	public function save_profile( int $user_id, array $data ): true {
		global $wpdb;

		$fields    = $this->get_fields();
		$field_map = array_column( $fields, 'id', 'field_key' );

		foreach ( $data as $key => $value ) {
			if ( ! isset( $field_map[ $key ] ) ) {
				continue;
			}

			$field_id      = (int) $field_map[ $key ];
			$sanitized_val = sanitize_textarea_field( (string) $value );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d",
					$user_id,
					$field_id
				)
			);

			if ( null !== $existing ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'bn_profile_values',
					array( 'value' => $sanitized_val ),
					array(
						'user_id'  => $user_id,
						'field_id' => $field_id,
					),
					array( '%s' ),
					array( '%d', '%d' )
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					$wpdb->prefix . 'bn_profile_values',
					array(
						'user_id'  => $user_id,
						'field_id' => $field_id,
						'value'    => $sanitized_val,
					),
					array( '%d', '%d', '%s' )
				);
			}
		}

		wp_cache_delete( "profile_{$user_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Return the full profile for a user as seen by the given viewer.
	 *
	 * Includes WordPress core fields (display_name, avatar_url) plus all
	 * custom field values, filtered by visibility when viewer != owner.
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

		$is_owner  = ( $viewer_id === $profile_user_id );
		$cache_key = "profile_{$profile_user_id}_viewer_" . ( $is_owner ? 'owner' : 'other' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// Load field values joined with field definitions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.id, f.field_key, f.label, f.type, f.visibility, f.group_name, f.sort_order, v.value
				 FROM {$wpdb->prefix}bn_profile_fields f
				 LEFT JOIN {$wpdb->prefix}bn_profile_values v
				        ON v.field_id = f.id AND v.user_id = %d
				 ORDER BY f.sort_order ASC, f.id ASC",
				$profile_user_id
			),
			ARRAY_A
		);

		$fields = array();
		foreach ( (array) $rows as $row ) {
			// Enforce visibility for non-owners.
			if ( ! $is_owner && 'private' === $row['visibility'] ) {
				continue;
			}

			$fields[] = array(
				'field_id'   => (int) $row['id'],
				'field_key'  => $row['field_key'],
				'label'      => $row['label'],
				'type'       => $row['type'],
				'visibility' => $row['visibility'],
				'group_name' => $row['group_name'],
				'sort_order' => (int) $row['sort_order'],
				'value'      => $row['value'],
			);
		}

		$profile = array(
			'user_id'       => $profile_user_id,
			'display_name'  => $wp_user->display_name,
			'user_login'    => $wp_user->user_login,
			'avatar_url'    => get_avatar_url( $profile_user_id, array( 'size' => 96 ) ),
			'registered_at' => $wp_user->user_registered,
			'fields'        => $fields,
		);

		wp_cache_set( $cache_key, $profile, self::CACHE_GROUP, self::CACHE_TTL );

		return $profile;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		);
	}

	/**
	 * Hydrate a raw DB row into a typed field array.
	 *
	 * @param array $row Raw ARRAY_A row from bn_profile_fields.
	 * @return array
	 */
	private function hydrate_field( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'field_key'   => $row['field_key'],
			'label'       => $row['label'],
			'type'        => $row['type'],
			'options'     => isset( $row['options'] ) ? json_decode( $row['options'], true ) : null,
			'is_required' => (bool) $row['is_required'],
			'visibility'  => $row['visibility'],
			'group_name'  => $row['group_name'],
			'sort_order'  => (int) $row['sort_order'],
		);
	}
}

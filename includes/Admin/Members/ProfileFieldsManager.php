<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase
/**
 * BuddyNext profile fields manager.
 *
 * Handles all admin_post_ actions for creating, updating, deleting, and
 * reordering profile field groups and fields, plus rendering the Profile
 * Fields tab on the Members admin page.
 *
 * @package BuddyNext\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Admin\Members;

/**
 * Profile field group and field CRUD + tab renderer.
 */
class ProfileFieldsManager {

	/**
	 * Allowed field types for profile fields.
	 *
	 * Use field_types() instead of this constant where you need the filterable
	 * list — Pro extends the available types via buddynext_profile_field_types.
	 *
	 * @var string[]
	 */
	private const FIELD_TYPES = array(
		'text',
		'textarea',
		'email',
		'phone',
		'url',
		'social',
		'number',
		'date',
		'daterange',
		'select',
		'multiselect',
		'radio',
		'checkbox',
		'toggle',
		'rating',
	);

	/**
	 * Return the filterable list of allowed profile field type slugs.
	 *
	 * Pro plugins add custom field types (e.g. 'file', 'video', 'map') by
	 * hooking buddynext_profile_field_types. The whitelist enforcement in
	 * handle_create_field() and handle_update_field() calls this method so
	 * Pro-registered types are automatically accepted.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Ordered list of field type slugs.
	 */
	public static function field_types(): array {
		/**
		 * Filter the allowed profile field type slugs.
		 *
		 * Return an array of lowercase, hyphen/underscore-safe slugs. Each Pro
		 * type must be handled in the render layer and in ProfileService::get_field_value().
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types The current list of field type slugs.
		 */
		return (array) apply_filters( 'buddynext_profile_field_types', self::FIELD_TYPES );
	}

	/**
	 * Field types that require an options list (stored as JSON array).
	 *
	 * @var string[]
	 */
	private const CHOICE_TYPES = array( 'select', 'multiselect', 'radio', 'checkbox' );

	/**
	 * Field types that require date display configuration.
	 *
	 * @var string[]
	 */
	private const DATE_TYPES = array( 'date', 'daterange' );

	/**
	 * Allowed date display modes.
	 *
	 * @var string[]
	 */
	private const DATE_DISPLAY = array( 'date', 'month_year', 'year', 'age' );

	/**
	 * Allowed group types.
	 *
	 * @var string[]
	 */
	private const GROUP_TYPES = array( 'flat', 'repeater' );

	/**
	 * Allowed visibility values.
	 *
	 * @var string[]
	 */
	private const VISIBILITY_VALUES = array( 'public', 'followers', 'private' );

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_bn_create_profile_group', array( $this, 'handle_create_group' ) );
		add_action( 'admin_post_bn_create_profile_field', array( $this, 'handle_create_field' ) );
		add_action( 'admin_post_bn_delete_profile_group', array( $this, 'handle_delete_group' ) );
		add_action( 'admin_post_bn_delete_profile_field', array( $this, 'handle_delete_field' ) );
		add_action( 'admin_post_bn_update_profile_group', array( $this, 'handle_update_group' ) );
		add_action( 'admin_post_bn_update_profile_field', array( $this, 'handle_update_field' ) );
		add_action( 'admin_post_bn_edit_profile_field', array( $this, 'handle_edit_field' ) );
		add_action( 'admin_post_bn_reorder_group', array( $this, 'handle_reorder_group' ) );
		add_action( 'admin_post_bn_reorder_field', array( $this, 'handle_reorder_field' ) );
	}

	/**
	 * Enqueue the Profile Fields tab JS on the Members admin page.
	 *
	 * Loads only when the active tab is "profile-fields".
	 *
	 * @param string $hook_suffix Hook suffix for the current admin page.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'buddynext-members' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) );
		if ( 'profile-fields' !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'bn-profile-fields',
			BUDDYNEXT_URL . 'assets/js/admin/profile-fields.js',
			array(),
			BUDDYNEXT_VERSION,
			true
		);
	}

	/**
	 * Handle admin_post_bn_create_profile_group form submission.
	 *
	 * @return void
	 */
	public function handle_create_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_create_profile_group' );

		$label      = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$type       = sanitize_key( wp_unslash( $_POST['type'] ?? 'flat' ) );
		$visibility = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'public' ) );

		// Auto-generate group_key from label — no technical input required from admins.
		$group_key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );

		if ( ! in_array( $type, self::GROUP_TYPES, true ) ) {
			$type = 'flat';
		}

		if ( ! in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
			$visibility = 'public';
		}

		if ( '' !== $group_key && '' !== $label ) {
			buddynext_service( 'profiles' )->create_group(
				array(
					'group_key'  => $group_key,
					'label'      => $label,
					'type'       => $type,
					'visibility' => $visibility,
					'sort_order' => 0,
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_create_profile_field form submission.
	 *
	 * @return void
	 */
	public function handle_create_field(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_create_profile_field' );

		$group_id    = absint( wp_unslash( $_POST['group_id'] ?? 0 ) );
		$label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$type        = sanitize_key( wp_unslash( $_POST['type'] ?? 'text' ) );
		$is_required = absint( wp_unslash( $_POST['is_required'] ?? 0 ) );
		$visibility  = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'public' ) );
		// Route options by field type.
		if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
			$options_raw = sanitize_textarea_field( wp_unslash( $_POST['options'] ?? '' ) );
			$parsed_opts = $this->parse_options_textarea( $options_raw );
		} elseif ( in_array( $type, self::DATE_TYPES, true ) ) {
			$date_display = sanitize_key( wp_unslash( $_POST['date_display'] ?? 'date' ) );
			$parsed_opts  = array( 'display' => in_array( $date_display, self::DATE_DISPLAY, true ) ? $date_display : 'date' );
		} else {
			$parsed_opts = null;
		}

		// Auto-generate field_key from label — no technical input required from admins.
		$field_key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );

		if ( ! in_array( $type, self::field_types(), true ) ) {
			$type = 'text';
		}

		if ( ! in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
			$visibility = 'public';
		}

		if ( $group_id > 0 && '' !== $field_key && '' !== $label ) {
			global $wpdb;
			// Append at end: fetch current max sort_order for this group.
			$max_order  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COALESCE(MAX(sort_order), -1) FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d",
					$group_id
				)
			);
			$sort_order = $max_order + 1;

			buddynext_service( 'profiles' )->create_field(
				array(
					'group_id'    => $group_id,
					'field_key'   => $field_key,
					'label'       => $label,
					'type'        => $type,
					'options'     => $parsed_opts,
					'is_required' => $is_required > 0 ? 1 : 0,
					'visibility'  => $visibility,
					'sort_order'  => $sort_order,
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Parse a textarea of options (one per line) into an array, or null if empty.
	 *
	 * Each non-blank line becomes one option value. Blank lines are ignored.
	 *
	 * @param string $raw Sanitized textarea content.
	 * @return array<int,string>|null Array of option strings, or null when input is blank.
	 */
	private function parse_options_textarea( string $raw ): ?array {
		if ( '' === trim( $raw ) ) {
			return null;
		}

		$lines = array_values(
			array_filter(
				array_map( 'sanitize_text_field', explode( "\n", $raw ) ),
				static fn( string $l ) => '' !== $l
			)
		);

		return ! empty( $lines ) ? $lines : null;
	}

	/**
	 * Handle admin_post_bn_delete_profile_group form submission.
	 *
	 * Delegates to ProfileService::delete_group() which enforces the system-group
	 * guard and cascades deletes through fields and their stored values.
	 *
	 * @return void
	 */
	public function handle_delete_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$group_id = absint( wp_unslash( $_POST['group_id'] ?? 0 ) );

		check_admin_referer( 'bn_delete_profile_group_' . $group_id );

		if ( $group_id > 0 ) {
			buddynext_service( 'profiles' )->delete_group( $group_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_delete_profile_field form submission.
	 *
	 * Delegates to ProfileService::delete_field() which cascades the delete to
	 * bn_profile_values before removing the field definition row.
	 *
	 * @return void
	 */
	public function handle_delete_field(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$field_id = absint( wp_unslash( $_POST['field_id'] ?? 0 ) );

		check_admin_referer( 'bn_delete_profile_field_' . $field_id );

		if ( $field_id > 0 ) {
			buddynext_service( 'profiles' )->delete_field( $field_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_update_profile_group — update visibility on a group.
	 *
	 * @return void
	 */
	public function handle_update_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$group_id = absint( wp_unslash( $_POST['group_id'] ?? 0 ) );

		check_admin_referer( 'bn_update_profile_group_' . $group_id );

		$visibility = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'public' ) );

		if ( ! in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
			$visibility = 'public';
		}

		if ( $group_id > 0 ) {
			global $wpdb;

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prefix . 'bn_profile_groups',
				array( 'visibility' => $visibility ),
				array( 'id' => $group_id ),
				array( '%s' ),
				array( '%d' )
			);

			wp_cache_delete( 'all_groups', 'buddynext_profiles' );
			wp_cache_delete( 'all_fields', 'buddynext_profiles' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_update_profile_field — update is_required or visibility on a field.
	 *
	 * @return void
	 */
	public function handle_update_field(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$field_id = absint( wp_unslash( $_POST['field_id'] ?? 0 ) );

		check_admin_referer( 'bn_update_profile_field_' . $field_id );

		if ( $field_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'buddynext-members',
						'tab'  => 'profile-fields',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$attr = sanitize_key( wp_unslash( $_POST['attr'] ?? '' ) );
		global $wpdb;

		if ( 'is_required' === $attr ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prefix . 'bn_profile_fields',
				array( 'is_required' => isset( $_POST['is_required'] ) ? 1 : 0 ),
				array( 'id' => $field_id ),
				array( '%d' ),
				array( '%d' )
			);
		} elseif ( 'visibility' === $attr ) {
			$visibility = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'public' ) );
			if ( in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prefix . 'bn_profile_fields',
					array( 'visibility' => $visibility ),
					array( 'id' => $field_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		wp_cache_delete( 'all_fields', 'buddynext_profiles' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_edit_profile_field — full field update (label, type, options, required, visibility).
	 *
	 * @return void
	 */
	public function handle_edit_field(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$field_id = absint( wp_unslash( $_POST['field_id'] ?? 0 ) );

		check_admin_referer( 'bn_edit_profile_field_' . $field_id );

		if ( $field_id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'buddynext-members',
						'tab'  => 'profile-fields',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$label      = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$type       = sanitize_key( wp_unslash( $_POST['type'] ?? 'text' ) );
		$visibility = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'public' ) );
		// Route options by field type.
		if ( in_array( $type, self::CHOICE_TYPES, true ) ) {
			$options_raw = sanitize_textarea_field( wp_unslash( $_POST['options'] ?? '' ) );
			$parsed_opts = $this->parse_options_textarea( $options_raw );
		} elseif ( in_array( $type, self::DATE_TYPES, true ) ) {
			$date_display = sanitize_key( wp_unslash( $_POST['date_display'] ?? 'date' ) );
			$parsed_opts  = array( 'display' => in_array( $date_display, self::DATE_DISPLAY, true ) ? $date_display : 'date' );
		} else {
			$parsed_opts = null;
		}
		$is_required = isset( $_POST['is_required'] ) ? 1 : 0;

		if ( '' === $label ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'buddynext-members',
						'tab'  => 'profile-fields',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( ! in_array( $type, self::field_types(), true ) ) {
			$type = 'text';
		}

		if ( ! in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
			$visibility = 'public';
		}

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'label'       => $label,
				'type'        => $type,
				'options'     => null !== $parsed_opts ? wp_json_encode( $parsed_opts ) : null,
				'is_required' => $is_required,
				'visibility'  => $visibility,
			),
			array( 'id' => $field_id ),
			array( '%s', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		wp_cache_delete( 'all_fields', 'buddynext_profiles' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_reorder_group — swap a group's sort_order with the adjacent one.
	 *
	 * @return void
	 */
	public function handle_reorder_group(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$group_id  = absint( wp_unslash( $_POST['group_id'] ?? 0 ) );
		$direction = sanitize_key( wp_unslash( $_POST['direction'] ?? '' ) );

		check_admin_referer( 'bn_reorder_group_' . $group_id );

		if ( $group_id > 0 && in_array( $direction, array( 'up', 'down' ), true ) ) {
			global $wpdb;

			$all = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT id, sort_order FROM {$wpdb->prefix}bn_profile_groups ORDER BY sort_order ASC, id ASC",
				ARRAY_A
			);

			$ids = array_column( $all, 'id' );
			$pos = array_search( (string) $group_id, $ids, true );

			if ( false !== $pos ) {
				$swap_pos = 'up' === $direction ? $pos - 1 : $pos + 1;

				if ( isset( $ids[ $swap_pos ] ) ) {
					$a_id    = (int) $ids[ $pos ];
					$b_id    = (int) $ids[ $swap_pos ];
					$a_order = (int) $all[ $pos ]['sort_order'];
					$b_order = (int) $all[ $swap_pos ]['sort_order'];

					// Ensure distinct sort_order values so the swap is meaningful.
					if ( $a_order === $b_order ) {
						$a_order = $pos;
						$b_order = $swap_pos;
					}

					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->prefix . 'bn_profile_groups',
						array( 'sort_order' => $b_order ),
						array( 'id' => $a_id ),
						array( '%d' ),
						array( '%d' )
					);
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->prefix . 'bn_profile_groups',
						array( 'sort_order' => $a_order ),
						array( 'id' => $b_id ),
						array( '%d' ),
						array( '%d' )
					);

					wp_cache_delete( 'all_groups', 'buddynext_profiles' );
					wp_cache_delete( 'all_fields', 'buddynext_profiles' );
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin_post_bn_reorder_field — swap a field's sort_order with the adjacent one.
	 *
	 * @return void
	 */
	public function handle_reorder_field(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'buddynext' ), 403 );
		}

		$field_id  = absint( wp_unslash( $_POST['field_id'] ?? 0 ) );
		$direction = sanitize_key( wp_unslash( $_POST['direction'] ?? '' ) );

		check_admin_referer( 'bn_reorder_field_' . $field_id );

		if ( $field_id > 0 && in_array( $direction, array( 'up', 'down' ), true ) ) {
			global $wpdb;

			// Get the group this field belongs to.
			$group_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT group_id FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d",
					$field_id
				)
			);

			if ( $group_id > 0 ) {
				$all = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT id, sort_order FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d ORDER BY sort_order ASC, id ASC",
						$group_id
					),
					ARRAY_A
				);

				$ids = array_column( $all, 'id' );
				$pos = array_search( (string) $field_id, $ids, true );

				if ( false !== $pos ) {
					$swap_pos = 'up' === $direction ? $pos - 1 : $pos + 1;

					if ( isset( $ids[ $swap_pos ] ) ) {
						$a_id    = (int) $ids[ $pos ];
						$b_id    = (int) $ids[ $swap_pos ];
						$a_order = (int) $all[ $pos ]['sort_order'];
						$b_order = (int) $all[ $swap_pos ]['sort_order'];

						if ( $a_order === $b_order ) {
							$a_order = $pos;
							$b_order = $swap_pos;
						}

						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->prefix . 'bn_profile_fields',
							array( 'sort_order' => $b_order ),
							array( 'id' => $a_id ),
							array( '%d' ),
							array( '%d' )
						);
						$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->prefix . 'bn_profile_fields',
							array( 'sort_order' => $a_order ),
							array( 'id' => $b_id ),
							array( '%d' ),
							array( '%d' )
						);

						wp_cache_delete( 'all_fields', 'buddynext_profiles' );
					}
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'buddynext-members',
					'tab'  => 'profile-fields',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the Profile Fields tab: group cards with nested field lists,
	 * inline add-group and add-field forms.
	 *
	 * @return void
	 */
	public function render_profile_fields_tab(): void {
		$groups      = buddynext_service( 'profiles' )->get_fields();
		$post_url    = admin_url( 'admin-post.php' );
		$base_url    = admin_url( 'admin.php?page=buddynext-members&tab=profile-fields' );
		$group_count = count( $groups );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show_add_group = absint( wp_unslash( $_GET['add_group'] ?? 0 ) );
		$add_group_url  = add_query_arg( 'add_group', '1', $base_url );

		$field_type_labels = array(
			// Text types.
			'text'        => __( 'Short Text', 'buddynext' ),
			'textarea'    => __( 'Long Text', 'buddynext' ),
			'email'       => __( 'Email', 'buddynext' ),
			'phone'       => __( 'Phone', 'buddynext' ),
			'url'         => __( 'URL', 'buddynext' ),
			'social'      => __( 'Social Profile', 'buddynext' ),
			// Numeric types.
			'number'      => __( 'Number', 'buddynext' ),
			'rating'      => __( 'Star Rating', 'buddynext' ),
			// Date types.
			'date'        => __( 'Date', 'buddynext' ),
			'daterange'   => __( 'Date Range', 'buddynext' ),
			// Choice types — need an options list.
			'select'      => __( 'Dropdown', 'buddynext' ),
			'multiselect' => __( 'Multi-select', 'buddynext' ),
			'radio'       => __( 'Radio Buttons', 'buddynext' ),
			'checkbox'    => __( 'Checkboxes', 'buddynext' ),
			// Binary type.
			'toggle'      => __( 'Toggle (Yes / No)', 'buddynext' ),
		);

		/**
		 * Filter the human-readable labels for profile field types shown in
		 * the admin field builder.
		 *
		 * Pro extensions that register new types via
		 * buddynext_profile_field_types should also append labels here so the
		 * type dropdown displays them with a friendly name.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $field_type_labels Map of slug => label.
		 */
		$field_type_labels = (array) apply_filters( 'buddynext_profile_field_type_labels', $field_type_labels );

		$vis_labels = array(
			'public'    => __( 'Public', 'buddynext' ),
			'followers' => __( 'Followers only', 'buddynext' ),
			'private'   => __( 'Only me', 'buddynext' ),
		);
		?>

		<div class="bn-pf-wrap">

		<?php foreach ( $groups as $gi => $group ) : ?>
			<?php
			$gid          = absint( $group['id'] );
			$is_system    = ! empty( $group['is_system'] );
			$field_count  = count( $group['fields'] );
			$is_first_grp = ( 0 === $gi );
			$is_last_grp  = ( $group_count - 1 === $gi );
			$panel_id     = 'bn-pf-panel-' . $gid;
			?>
			<div class="bn-pf-card">

				<!-- Group header -->
				<div class="bn-pf-card-head">
					<span class="bn-pf-group-name"><?php echo esc_html( $group['label'] ); ?></span>

					<div class="bn-pf-meta">
						<?php if ( $is_system ) : ?>
							<span class="bn-pf-badge bn-pf-b-system"><?php esc_html_e( 'System', 'buddynext' ); ?></span>
						<?php endif; ?>

						<span class="bn-pf-badge <?php echo 'repeater' === $group['type'] ? 'bn-pf-b-repeater' : 'bn-pf-b-flat'; ?>">
							<?php echo 'repeater' === $group['type'] ? esc_html__( 'Multiple entries', 'buddynext' ) : esc_html__( 'Single entry', 'buddynext' ); ?>
						</span>

						<!-- Inline group visibility -->
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
							<input type="hidden" name="action" value="bn_update_profile_group">
							<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
							<?php wp_nonce_field( 'bn_update_profile_group_' . $gid ); ?>
							<select class="bn-pf-vis-grp" name="visibility" data-bn-autosubmit title="<?php esc_attr_e( 'Group visibility', 'buddynext' ); ?>">
								<?php foreach ( $vis_labels as $vis_val => $vis_lbl ) : ?>
									<option value="<?php echo esc_attr( $vis_val ); ?>" <?php selected( $group['visibility'], $vis_val ); ?>>
										<?php echo esc_html( $vis_lbl ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</form>

						<span class="bn-pf-field-count">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of fields */
									_n( '%d field', '%d fields', $field_count, 'buddynext' ),
									$field_count
								)
							);
							?>
						</span>
					</div><!-- .bn-pf-meta -->

					<div class="bn-pf-head-actions">
						<?php if ( ! $is_first_grp ) : ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
								<input type="hidden" name="action" value="bn_reorder_group">
								<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
								<input type="hidden" name="direction" value="up">
								<?php wp_nonce_field( 'bn_reorder_group_' . $gid ); ?>
								<button type="submit" class="bn-pf-icon-btn" title="<?php esc_attr_e( 'Move group up', 'buddynext' ); ?>">↑</button>
							</form>
						<?php endif; ?>

						<?php if ( ! $is_last_grp ) : ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
								<input type="hidden" name="action" value="bn_reorder_group">
								<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
								<input type="hidden" name="direction" value="down">
								<?php wp_nonce_field( 'bn_reorder_group_' . $gid ); ?>
								<button type="submit" class="bn-pf-icon-btn" title="<?php esc_attr_e( 'Move group down', 'buddynext' ); ?>">↓</button>
							</form>
						<?php endif; ?>

						<a href="#<?php echo esc_attr( $panel_id ); ?>"
							class="bn-pf-add-btn"
							data-bn-pf-toggle="<?php echo esc_attr( $panel_id ); ?>">
							+ <?php esc_html_e( 'Add Field', 'buddynext' ); ?>
						</a>

						<?php if ( ! $is_system ) : ?>
							<?php $del_nonce = wp_create_nonce( 'bn_delete_profile_group_' . $gid ); ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;" class="bn-del-form">
								<input type="hidden" name="action" value="bn_delete_profile_group">
								<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $del_nonce ); ?>">
								<button type="button" class="bn-pf-del-group-btn bn-del-trigger"><?php esc_html_e( 'Delete Group', 'buddynext' ); ?></button>
								<button type="submit" class="bn-del-confirm" style="display:none;"><?php esc_html_e( 'Yes, delete', 'buddynext' ); ?></button>
								<button type="button" class="bn-del-cancel" style="display:none;"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
							</form>
						<?php endif; ?>
					</div><!-- .bn-pf-head-actions -->
				</div><!-- .bn-pf-card-head -->

				<!-- Fields table -->
				<?php if ( ! empty( $group['fields'] ) ) : ?>
					<table class="bn-pf-table">
						<thead>
							<tr>
								<th style="width:64px;"><?php esc_html_e( 'Order', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Field Name', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Required', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Visible to', 'buddynext' ); ?></th>
								<th style="width:64px;"></th>
							</tr>
						</thead>
						<tbody>
						<?php
						$field_list  = $group['fields'];
						$field_total = count( $field_list );
						foreach ( $field_list as $fi => $field ) :
							$fid          = absint( $field['id'] );
							$is_first_fld = ( 0 === $fi );
							$is_last_fld  = ( $field_total - 1 === $fi );
							$type_lbl     = $field_type_labels[ $field['type'] ] ?? esc_html( $field['type'] );
							$field_vis    = $field['visibility'] ?? 'public';
							?>
							<tr>
								<!-- Order -->
								<td>
									<div class="bn-pf-order-cell">
										<?php if ( ! $is_first_fld ) : ?>
											<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
												<input type="hidden" name="action" value="bn_reorder_field">
												<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
												<input type="hidden" name="direction" value="up">
												<?php wp_nonce_field( 'bn_reorder_field_' . $fid ); ?>
												<button type="submit" class="bn-pf-icon-btn" title="<?php esc_attr_e( 'Move up', 'buddynext' ); ?>">↑</button>
											</form>
										<?php endif; ?>
										<?php if ( ! $is_last_fld ) : ?>
											<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
												<input type="hidden" name="action" value="bn_reorder_field">
												<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
												<input type="hidden" name="direction" value="down">
												<?php wp_nonce_field( 'bn_reorder_field_' . $fid ); ?>
												<button type="submit" class="bn-pf-icon-btn" title="<?php esc_attr_e( 'Move down', 'buddynext' ); ?>">↓</button>
											</form>
										<?php endif; ?>
									</div>
								</td>

								<!-- Field name -->
								<td><span class="bn-pf-field-name"><?php echo esc_html( $field['label'] ); ?></span></td>

								<!-- Type -->
								<td><span class="bn-pf-type-pill"><?php echo esc_html( $type_lbl ); ?></span></td>

								<!-- Required inline toggle -->
								<td>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
										<input type="hidden" name="action" value="bn_update_profile_field">
										<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
										<input type="hidden" name="attr" value="is_required">
										<?php wp_nonce_field( 'bn_update_profile_field_' . $fid ); ?>
										<label class="bn-pf-req-wrap">
											<input type="checkbox"
												class="bn-pf-req-check"
												name="is_required"
												value="1"
												<?php checked( $field['is_required'] ); ?>
												data-bn-autosubmit>
											<span><?php esc_html_e( 'Required', 'buddynext' ); ?></span>
										</label>
									</form>
								</td>

								<!-- Visibility per-field -->
								<td>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;">
										<input type="hidden" name="action" value="bn_update_profile_field">
										<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
										<input type="hidden" name="attr" value="visibility">
										<?php wp_nonce_field( 'bn_update_profile_field_' . $fid ); ?>
										<select class="bn-pf-vis-field" name="visibility" data-bn-autosubmit>
											<?php foreach ( $vis_labels as $vis_val => $vis_lbl ) : ?>
												<option value="<?php echo esc_attr( $vis_val ); ?>" <?php selected( $field_vis, $vis_val ); ?>>
													<?php echo esc_html( $vis_lbl ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</form>
								</td>

								<!-- Edit / Delete -->
								<td>
									<div class="bn-pf-action-cell">
										<button type="button" class="bn-pf-edit-btn"
											data-bn-pf-toggle-edit="bn-ef-row-<?php echo absint( $fid ); ?>"
											title="<?php esc_attr_e( 'Edit field', 'buddynext' ); ?>"><?php buddynext_icon( 'edit' ); ?></button>
										<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin:0;" class="bn-del-form">
											<input type="hidden" name="action" value="bn_delete_profile_field">
											<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
											<?php wp_nonce_field( 'bn_delete_profile_field_' . $fid ); ?>
											<button type="button" class="bn-pf-del-field bn-del-trigger" title="<?php esc_attr_e( 'Remove field', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
											<button type="submit" class="bn-del-confirm" style="display:none;"><?php esc_html_e( 'Delete?', 'buddynext' ); ?></button>
											<button type="button" class="bn-del-cancel" style="display:none;"><?php esc_html_e( 'No', 'buddynext' ); ?></button>
										</form>
									</div>
								</td>
							</tr>
							<!-- Edit panel row -->
							<?php
							$edit_panel_id    = 'bn-ef-row-' . $fid;
							$is_choice_type   = in_array( $field['type'], self::CHOICE_TYPES, true );
							$is_date_type     = in_array( $field['type'], self::DATE_TYPES, true );
							$opts_text        = $is_choice_type && ! empty( $field['options'] ) ? implode( "\n", (array) $field['options'] ) : '';
							$date_display_val = ( $is_date_type && is_array( $field['options'] ) ) ? ( $field['options']['display'] ?? 'date' ) : 'date';
							?>
							<tr id="<?php echo esc_attr( $edit_panel_id ); ?>" style="display:none;">
								<td colspan="6" style="padding:0;">
									<div class="bn-pf-ef-panel">
										<p class="bn-pf-af-title"><?php esc_html_e( 'Edit field', 'buddynext' ); ?></p>
										<form method="post" action="<?php echo esc_url( $post_url ); ?>">
											<input type="hidden" name="action" value="bn_edit_profile_field">
											<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
											<?php wp_nonce_field( 'bn_edit_profile_field_' . $fid ); ?>
											<div class="bn-pf-af-row">
												<div class="bn-pf-af-field">
													<label for="bn-ef-lbl-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Field Name', 'buddynext' ); ?></label>
													<input type="text"
														id="bn-ef-lbl-<?php echo absint( $fid ); ?>"
														name="label"
														value="<?php echo esc_attr( $field['label'] ); ?>"
														required>
												</div>
												<div class="bn-pf-af-field" style="flex:0 0 180px;">
													<label for="bn-ef-type-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Field Type', 'buddynext' ); ?></label>
													<select id="bn-ef-type-<?php echo absint( $fid ); ?>" name="type"
														data-bn-pf-opts-wrap="bn-ef-opts-<?php echo absint( $fid ); ?>" data-bn-pf-date-wrap="bn-ef-date-<?php echo absint( $fid ); ?>">
														<?php foreach ( $field_type_labels as $ft_val => $ft_lbl ) : ?>
														<option value="<?php echo esc_attr( $ft_val ); ?>" <?php selected( $field['type'], $ft_val ); ?>>
															<?php echo esc_html( $ft_lbl ); ?>
														</option>
														<?php endforeach; ?>
													</select>
												</div>
												<div class="bn-pf-af-field" style="flex:0 0 160px;">
													<label for="bn-ef-vis-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Visible to', 'buddynext' ); ?></label>
													<select id="bn-ef-vis-<?php echo absint( $fid ); ?>" name="visibility">
														<?php foreach ( $vis_labels as $vis_val => $vis_lbl ) : ?>
														<option value="<?php echo esc_attr( $vis_val ); ?>" <?php selected( $field['visibility'], $vis_val ); ?>>
															<?php echo esc_html( $vis_lbl ); ?>
														</option>
														<?php endforeach; ?>
													</select>
												</div>
											</div>
											<div id="bn-ef-opts-<?php echo absint( $fid ); ?>" class="bn-pf-opts-wrap" style="<?php echo $is_choice_type ? '' : 'display:none;'; ?>">
												<label for="bn-ef-opts-ta-<?php echo absint( $fid ); ?>">
													<?php esc_html_e( 'Options (one per line)', 'buddynext' ); ?>
												</label>
												<textarea id="bn-ef-opts-ta-<?php echo absint( $fid ); ?>"
													name="options"
													class="bn-pf-opts-textarea"
													rows="6"
													placeholder="<?php esc_attr_e( 'Option 1', 'buddynext' ); ?>"><?php echo esc_textarea( $opts_text ); ?></textarea>
												<p class="bn-pf-opts-hint"><?php esc_html_e( 'Each line becomes one selectable option. Example: United States, Canada, United Kingdom â each on its own line.', 'buddynext' ); ?></p>
											</div>
											<?php
											/**
											 * Fires inside the per-field edit panel after the
											 * core options textarea, before the date-display
											 * config. Pro plugins emit type-specific option
											 * inputs here (e.g. file MIME, number unit,
											 * conditional trigger field).
											 *
											 * Output is rendered verbatim inside the edit
											 * form — handlers must escape on output.
											 *
											 * @since 1.1.0
											 *
											 * @param string               $type  Field type slug.
											 * @param array<string, mixed> $field Existing field row.
											 */
											do_action( 'buddynext_profile_field_type_options', (string) $field['type'], $field );
											?>
											<!-- Date display config (shown for date / daterange types) -->
											<div id="bn-ef-date-<?php echo absint( $fid ); ?>" class="bn-pf-opts-wrap" style="<?php echo $is_date_type ? '' : 'display:none;'; ?>">
												<label for="bn-ef-date-d-<?php echo absint( $fid ); ?>">
													<?php esc_html_e( 'Display as', 'buddynext' ); ?>
												</label>
												<select id="bn-ef-date-d-<?php echo absint( $fid ); ?>" name="date_display">
													<option value="date" <?php selected( $date_display_val, 'date' ); ?>><?php esc_html_e( 'Full date â Jan 15, 1990', 'buddynext' ); ?></option>
													<option value="month_year" <?php selected( $date_display_val, 'month_year' ); ?>><?php esc_html_e( 'Month â Year â Jan 1990', 'buddynext' ); ?></option>
													<option value="year" <?php selected( $date_display_val, 'year' ); ?>><?php esc_html_e( 'Year only â 1990', 'buddynext' ); ?></option>
													<option value="age" <?php selected( $date_display_val, 'age' ); ?>><?php esc_html_e( 'Calculated age â 34 years old', 'buddynext' ); ?></option>
												</select>
												<p class="bn-pf-opts-hint"><?php esc_html_e( 'How this date appears on profiles. Always stored as YYYY-MM-DD internally.', 'buddynext' ); ?></p>
											</div>
											<div class="bn-pf-af-req-row">
												<input type="checkbox" id="bn-ef-req-<?php echo absint( $fid ); ?>" name="is_required" value="1" <?php checked( $field['is_required'] ); ?>>
												<label for="bn-ef-req-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Make this field required', 'buddynext' ); ?></label>
											</div>
											<div class="bn-pf-af-actions">
												<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'buddynext' ); ?></button>
												<button type="button" class="button" data-bn-pf-toggle-edit="<?php echo esc_attr( $edit_panel_id ); ?>"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
											</div>
										</form>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="bn-pf-empty"><?php esc_html_e( 'No fields yet. Click "Add Field" to create the first field in this group.', 'buddynext' ); ?></p>
				<?php endif; ?>

				<!-- Add field panel (hidden until toggled) -->
				<div id="<?php echo esc_attr( $panel_id ); ?>" class="bn-pf-af-panel">
					<p class="bn-pf-af-title"><?php esc_html_e( 'Add a new field', 'buddynext' ); ?></p>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>">
						<input type="hidden" name="action" value="bn_create_profile_field">
						<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
						<?php wp_nonce_field( 'bn_create_profile_field' ); ?>
						<div class="bn-pf-af-row">
							<div class="bn-pf-af-field">
								<label for="bn-af-lbl-<?php echo absint( $gid ); ?>"><?php esc_html_e( 'Field Name', 'buddynext' ); ?></label>
								<input type="text"
									id="bn-af-lbl-<?php echo absint( $gid ); ?>"
									name="label"
									placeholder="<?php esc_attr_e( 'e.g. Job Title', 'buddynext' ); ?>"
									required>
							</div>
							<div class="bn-pf-af-field" style="flex:0 0 180px;">
								<label for="bn-af-type-<?php echo absint( $gid ); ?>"><?php esc_html_e( 'Field Type', 'buddynext' ); ?></label>
								<select id="bn-af-type-<?php echo absint( $gid ); ?>" name="type"
									data-bn-pf-opts-wrap="bn-af-opts-<?php echo absint( $gid ); ?>" data-bn-pf-date-wrap="bn-af-date-<?php echo absint( $gid ); ?>">
									<?php foreach ( $field_type_labels as $ft_val => $ft_lbl ) : ?>
										<option value="<?php echo esc_attr( $ft_val ); ?>"><?php echo esc_html( $ft_lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="bn-pf-af-field" style="flex:0 0 160px;">
								<label for="bn-af-vis-<?php echo absint( $gid ); ?>"><?php esc_html_e( 'Visible to', 'buddynext' ); ?></label>
								<select id="bn-af-vis-<?php echo absint( $gid ); ?>" name="visibility">
									<?php foreach ( $vis_labels as $vis_val => $vis_lbl ) : ?>
										<option value="<?php echo esc_attr( $vis_val ); ?>"><?php echo esc_html( $vis_lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						<div id="bn-af-opts-<?php echo absint( $gid ); ?>" class="bn-pf-opts-wrap" style="display:none;">
							<label for="bn-af-opts-ta-<?php echo absint( $gid ); ?>">
								<?php esc_html_e( 'Options (one per line)', 'buddynext' ); ?>
							</label>
							<textarea id="bn-af-opts-ta-<?php echo absint( $gid ); ?>"
								name="options"
								class="bn-pf-opts-textarea"
								rows="5"
								placeholder="<?php esc_attr_e( 'Option 1', 'buddynext' ); ?>"></textarea>
							<p class="bn-pf-opts-hint"><?php esc_html_e( 'Each line becomes one selectable option. Example: United States, Canada, United Kingdom — each on its own line.', 'buddynext' ); ?></p>
						</div>
						<?php
						/**
						 * Fires inside the add-field panel after the core options
						 * textarea, before the date-display config. Pro plugins
						 * emit type-specific option inputs here for new fields.
						 *
						 * Output is rendered verbatim — handlers must escape on output.
						 *
						 * @since 1.1.0
						 *
						 * @param string               $type  Field type slug ('' for new fields, no preselected type).
						 * @param array<string, mixed> $field Empty array for new fields.
						 */
						do_action( 'buddynext_profile_field_type_options', '', array() );
						?>
						<!-- Date display config -->
						<div id="bn-af-date-<?php echo absint( $gid ); ?>" class="bn-pf-opts-wrap" style="display:none;">
							<label for="bn-af-date-d-<?php echo absint( $gid ); ?>">
								<?php esc_html_e( 'Display as', 'buddynext' ); ?>
							</label>
							<select id="bn-af-date-d-<?php echo absint( $gid ); ?>" name="date_display">
								<option value="date"><?php esc_html_e( 'Full date — Jan 15, 1990', 'buddynext' ); ?></option>
								<option value="month_year"><?php esc_html_e( 'Month & Year — Jan 1990', 'buddynext' ); ?></option>
								<option value="year"><?php esc_html_e( 'Year only — 1990', 'buddynext' ); ?></option>
								<option value="age"><?php esc_html_e( 'Calculated age — 34 years old', 'buddynext' ); ?></option>
							</select>
							<p class="bn-pf-opts-hint"><?php esc_html_e( 'How this date appears on profiles. Always stored as YYYY-MM-DD internally.', 'buddynext' ); ?></p>
						</div>
						<div class="bn-pf-af-req-row">
							<input type="checkbox" id="bn-af-req-<?php echo absint( $gid ); ?>" name="is_required" value="1">
							<label for="bn-af-req-<?php echo absint( $gid ); ?>"><?php esc_html_e( 'Make this field required', 'buddynext' ); ?></label>
						</div>
						<div class="bn-pf-af-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Field', 'buddynext' ); ?></button>
							<button type="button" class="button" data-bn-pf-toggle="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
						</div>
					</form>
				</div><!-- .bn-pf-af-panel -->

			</div><!-- .bn-pf-card -->
		<?php endforeach; ?>

		<!-- Add Group -->
		<div>
			<?php if ( ! $show_add_group ) : ?>
				<a href="<?php echo esc_url( $add_group_url ); ?>" class="bn-pf-add-group-btn">
					+ <?php esc_html_e( 'Add Group', 'buddynext' ); ?>
				</a>
			<?php else : ?>
				<div class="bn-pf-ag-card">
					<h3><?php esc_html_e( 'Add a new profile group', 'buddynext' ); ?></h3>
					<form method="post" action="<?php echo esc_url( $post_url ); ?>">
						<input type="hidden" name="action" value="bn_create_profile_group">
						<?php wp_nonce_field( 'bn_create_profile_group' ); ?>
						<div class="bn-pf-ag-row">
							<div class="bn-pf-ag-field">
								<label for="bn-ag-label"><?php esc_html_e( 'Group Name', 'buddynext' ); ?></label>
								<input type="text" id="bn-ag-label" name="label"
									placeholder="<?php esc_attr_e( 'e.g. Work Experience', 'buddynext' ); ?>" required>
							</div>
							<div class="bn-pf-ag-field" style="flex:0 0 220px;">
								<label for="bn-ag-type"><?php esc_html_e( 'Group Type', 'buddynext' ); ?></label>
								<select id="bn-ag-type" name="type">
									<option value="flat"><?php esc_html_e( 'Single entry', 'buddynext' ); ?></option>
									<option value="repeater"><?php esc_html_e( 'Multiple entries (e.g. past jobs)', 'buddynext' ); ?></option>
								</select>
							</div>
							<div class="bn-pf-ag-field" style="flex:0 0 180px;">
								<label for="bn-ag-vis"><?php esc_html_e( 'Default visibility', 'buddynext' ); ?></label>
								<select id="bn-ag-vis" name="visibility">
									<option value="public"><?php esc_html_e( 'Everyone', 'buddynext' ); ?></option>
									<option value="followers"><?php esc_html_e( 'Followers only', 'buddynext' ); ?></option>
									<option value="private"><?php esc_html_e( 'Only me', 'buddynext' ); ?></option>
								</select>
							</div>
						</div>
						<p class="bn-pf-ag-note">
							<?php esc_html_e( 'Single entry: one value per field (e.g. bio, headline). Multiple entries: members can add several items (e.g. multiple jobs).', 'buddynext' ); ?>
						</p>
						<div class="bn-pf-ag-actions">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Group', 'buddynext' ); ?></button>
							<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>

		</div><!-- .bn-pf-wrap -->
		<?php
	}
}

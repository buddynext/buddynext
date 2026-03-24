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

		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
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

		if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
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

		$vis_labels = array(
			'public'    => __( 'Public', 'buddynext' ),
			'followers' => __( 'Followers only', 'buddynext' ),
			'private'   => __( 'Only me', 'buddynext' ),
		);
		?>

		<style>
		/* === BuddyNext Profile Fields Admin — Premium UI === */
		.bn-pf-wrap { display:flex; flex-direction:column; gap:20px; margin-top:20px; }

		/* Group card */
		.bn-pf-card { background:#fff; border:1px solid #e8e8e5; border-radius:12px; overflow:hidden; }
		.bn-pf-card-head { display:flex; align-items:center; gap:10px; padding:16px 20px; background:#f8f8f7; border-bottom:1px solid #e8e8e5; flex-wrap:wrap; }
		.bn-pf-card-head [data-theme="dark"] { background:#202020; }
		.bn-pf-group-name { font-family:'Plus Jakarta Sans','Inter',sans-serif; font-size:16px; font-weight:700; color:#37352f; flex:1; min-width:100px; }
		.bn-pf-meta { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

		/* Badges */
		.bn-pf-badge { display:inline-flex; align-items:center; padding:3px 9px; border-radius:99px; font-size:11px; font-weight:700; white-space:nowrap; }
		.bn-pf-b-system   { background:#fffbeb; color:#d97706; }
		.bn-pf-b-flat     { background:#e8f4fb; color:#0073aa; }
		.bn-pf-b-repeater { background:#f5f3ff; color:#5b21b6; }
		.bn-pf-b-public   { background:#ecfdf5; color:#059669; }
		.bn-pf-b-followers{ background:#fffbeb; color:#d97706; }
		.bn-pf-b-private  { background:#fef2f2; color:#dc2626; }
		.bn-pf-field-count{ font-size:12px; color:#aeaca8; }

		/* Group header actions */
		.bn-pf-head-actions { display:flex; align-items:center; gap:6px; flex-shrink:0; margin-left:auto; }
		.bn-pf-icon-btn { background:#fff; border:1px solid #e8e8e5; border-radius:6px; padding:5px 9px; font-size:13px; color:#787774; cursor:pointer; line-height:1.2; }
		.bn-pf-icon-btn:hover { background:#f1f1f0; color:#37352f; }
		.bn-pf-add-btn { display:inline-flex; align-items:center; gap:4px; padding:6px 12px; background:#fff; border:1px solid #0073aa; color:#0073aa; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; }
		.bn-pf-add-btn:hover { background:#e8f4fb; }
		.bn-pf-del-group-btn { padding:6px 12px; background:#fff; border:1px solid #e8e8e5; color:#787774; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; }
		.bn-pf-del-group-btn:hover { border-color:#dc2626; color:#dc2626; background:#fef2f2; }

		/* Visibility inline form in header */
		.bn-pf-vis-grp { font-size:12px; border:1px solid #e8e8e5; border-radius:6px; padding:4px 8px; background:#fff; color:#37352f; cursor:pointer; }
		.bn-pf-vis-grp:focus { outline:none; border-color:#0073aa; }

		/* Fields table */
		.bn-pf-table { width:100%; border-collapse:collapse; font-family:'Inter',sans-serif; }
		.bn-pf-table th { padding:9px 16px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#aeaca8; text-align:left; background:#f8f8f7; border-bottom:1px solid #f1f1ee; }
		.bn-pf-table td { padding:13px 16px; font-size:13px; color:#37352f; border-bottom:1px solid #f1f1ee; vertical-align:middle; }
		.bn-pf-table tbody tr:last-child td { border-bottom:none; }
		.bn-pf-table tbody tr:hover td { background:#f8f8f7; }
		.bn-pf-field-name { font-weight:600; color:#37352f; }
		.bn-pf-type-pill { display:inline-flex; align-items:center; padding:3px 9px; border-radius:99px; font-size:11px; font-weight:600; background:#f1f1f0; color:#787774; border:1px solid #e8e8e5; }

		/* Required toggle */
		.bn-pf-req-wrap { display:flex; align-items:center; gap:6px; font-size:13px; color:#37352f; }
		.bn-pf-req-check { width:16px; height:16px; cursor:pointer; accent-color:#0073aa; flex-shrink:0; }

		/* Per-field visibility select */
		.bn-pf-vis-field { font-size:12px; border:1px solid #e8e8e5; border-radius:6px; padding:5px 8px; background:#fff; color:#37352f; cursor:pointer; min-width:120px; }
		.bn-pf-vis-field:focus { outline:none; border-color:#0073aa; }

		/* Order buttons */
		.bn-pf-order-cell { display:flex; gap:3px; align-items:center; }

		/* Edit / delete field action cell */
		.bn-pf-action-cell { display:flex; gap:3px; align-items:center; justify-content:flex-end; }
		.bn-pf-edit-btn { background:none; border:none; padding:5px 7px; cursor:pointer; color:#787774; font-size:15px; line-height:1; border-radius:6px; }
		.bn-pf-edit-btn:hover { color:#0073aa; background:#e8f4fb; }
		.bn-pf-del-field { background:none; border:none; padding:5px 7px; cursor:pointer; color:#aeaca8; font-size:16px; line-height:1; border-radius:6px; }
		.bn-pf-del-field:hover { color:#dc2626; background:#fef2f2; }
		.bn-del-confirm { background:#fef2f2; border:1px solid #dc2626; color:#dc2626; padding:3px 10px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
		.bn-del-confirm:hover { background:#dc2626; color:#fff; }
		.bn-del-cancel { background:none; border:1px solid #e8e8e5; color:#787774; padding:3px 8px; border-radius:6px; cursor:pointer; font-size:13px; margin-left:2px; }
		.bn-del-cancel:hover { background:#f1f1f0; }

		/* Edit field inline panel */
		.bn-pf-ef-panel { padding:18px 20px; background:#f8f8f7; border-top:1px dashed #e8e8e5; }
		.bn-pf-ef-panel tr.bn-pf-ef-row > td { padding:0; }

		/* Options textarea (select / multiselect / checkbox) */
		.bn-pf-opts-wrap { margin-top:12px; }
		.bn-pf-opts-wrap > label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#787774; display:block; margin-bottom:5px; }
		.bn-pf-opts-textarea { width:100%; border:1px solid #e8e8e5; border-radius:6px; padding:8px 10px; font-size:13px; font-family:'Inter',sans-serif; resize:vertical; min-height:100px; background:#fff; color:#37352f; box-sizing:border-box; }
		.bn-pf-opts-textarea:focus { outline:none; border-color:#0073aa; box-shadow:0 0 0 2px #e8f4fb; }
		.bn-pf-opts-hint { font-size:11px; color:#aeaca8; margin:5px 0 0; }

		/* Empty state */
		.bn-pf-empty { padding:24px; text-align:center; color:#aeaca8; font-size:13px; font-style:italic; }

		/* Add field panel */
		.bn-pf-af-panel { padding:18px 20px; background:#f8f8f7; border-top:1px dashed #e8e8e5; display:none; }
		.bn-pf-af-panel.bn-open { display:block; }
		.bn-pf-af-title { font-size:13px; font-weight:700; color:#37352f; margin:0 0 14px; }
		.bn-pf-af-row { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
		.bn-pf-af-row .bn-pf-af-field { flex:1; min-width:150px; }
		.bn-pf-af-row .bn-pf-af-field label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#787774; display:block; margin-bottom:5px; }
		.bn-pf-af-row .bn-pf-af-field input[type="text"],
		.bn-pf-af-row .bn-pf-af-field select { font-size:13px; width:100%; border:1px solid #e8e8e5; border-radius:6px; padding:8px 10px; background:#fff; color:#37352f; box-sizing:border-box; }
		.bn-pf-af-row .bn-pf-af-field input:focus,
		.bn-pf-af-row .bn-pf-af-field select:focus { outline:none; border-color:#0073aa; box-shadow:0 0 0 2px #e8f4fb; }
		.bn-pf-af-req-row { display:flex; align-items:center; gap:8px; font-size:13px; color:#37352f; margin-top:10px; }
		.bn-pf-af-req-row input[type="checkbox"] { width:16px; height:16px; accent-color:#0073aa; }
		.bn-pf-af-actions { display:flex; gap:8px; margin-top:14px; }

		/* Add group section */
		.bn-pf-add-group-btn { display:inline-flex; align-items:center; gap:6px; padding:9px 16px; border:1px solid #0073aa; border-radius:6px; color:#0073aa; background:#fff; font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; }
		.bn-pf-add-group-btn:hover { background:#e8f4fb; }
		.bn-pf-ag-card { background:#fff; border:1px solid #e8e8e5; border-radius:12px; padding:20px; }
		.bn-pf-ag-card h3 { font-family:'Plus Jakarta Sans','Inter',sans-serif; font-size:15px; font-weight:700; color:#37352f; margin:0 0 16px; }
		.bn-pf-ag-row { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; }
		.bn-pf-ag-row .bn-pf-ag-field { flex:1; min-width:150px; }
		.bn-pf-ag-row .bn-pf-ag-field label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:#787774; display:block; margin-bottom:5px; }
		.bn-pf-ag-row .bn-pf-ag-field input[type="text"],
		.bn-pf-ag-row .bn-pf-ag-field select { font-size:13px; width:100%; border:1px solid #e8e8e5; border-radius:6px; padding:8px 10px; background:#fff; color:#37352f; box-sizing:border-box; }
		.bn-pf-ag-row .bn-pf-ag-field input:focus,
		.bn-pf-ag-row .bn-pf-ag-field select:focus { outline:none; border-color:#0073aa; box-shadow:0 0 0 2px #e8f4fb; }
		.bn-pf-ag-note { font-size:12px; color:#aeaca8; margin-top:10px; }
		.bn-pf-ag-actions { display:flex; gap:8px; margin-top:16px; }

		/* Dark mode */
		[data-theme="dark"] .bn-pf-card { background:#252525; border-color:#333330; }
		[data-theme="dark"] .bn-pf-card-head { background:#202020; border-color:#333330; }
		[data-theme="dark"] .bn-pf-group-name { color:#e8e8e6; }
		[data-theme="dark"] .bn-pf-b-flat { background:#1a2e3a; color:#4dabdb; }
		[data-theme="dark"] .bn-pf-table th { background:#202020; color:#6b6b67; border-color:#2c2c2a; }
		[data-theme="dark"] .bn-pf-table td { color:#e8e8e6; border-color:#2c2c2a; }
		[data-theme="dark"] .bn-pf-table tbody tr:hover td { background:#202020; }
		[data-theme="dark"] .bn-pf-type-pill { background:#2a2a2a; color:#9b9b97; border-color:#333330; }
		[data-theme="dark"] .bn-pf-icon-btn,
		[data-theme="dark"] .bn-pf-vis-grp,
		[data-theme="dark"] .bn-pf-vis-field { background:#252525; border-color:#333330; color:#9b9b97; }
		[data-theme="dark"] .bn-pf-af-panel,
		[data-theme="dark"] .bn-pf-ef-panel { background:#202020; }
		[data-theme="dark"] .bn-pf-af-row .bn-pf-af-field input,
		[data-theme="dark"] .bn-pf-af-row .bn-pf-af-field select { background:#252525; border-color:#333330; color:#e8e8e6; }
		[data-theme="dark"] .bn-pf-opts-textarea { background:#252525; border-color:#333330; color:#e8e8e6; }

		/* Responsive */
		@media (max-width: 1024px) {
			.bn-pf-head-actions { gap:4px; }
		}
		@media (max-width: 782px) {
			.bn-pf-card-head { gap:8px; }
			.bn-pf-table th:nth-child(5), .bn-pf-table td:nth-child(5) { display:none; }
			.bn-pf-af-row, .bn-pf-ag-row { flex-direction:column; }
		}
		@media (max-width: 640px) {
			.bn-pf-table th:nth-child(3), .bn-pf-table td:nth-child(3) { display:none; }
			.bn-pf-table th:nth-child(4), .bn-pf-table td:nth-child(4) { display:none; }
		}
		</style>

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
							<select class="bn-pf-vis-grp" name="visibility" onchange="this.form.submit()" title="<?php esc_attr_e( 'Group visibility', 'buddynext' ); ?>">
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
							onclick="event.preventDefault();bnPfToggle('<?php echo esc_js( $panel_id ); ?>');">
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
												onchange="this.form.submit()">
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
										<select class="bn-pf-vis-field" name="visibility" onchange="this.form.submit()">
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
											onclick="bnPfToggleEdit('bn-ef-row-<?php echo absint( $fid ); ?>')"
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
														onchange="bnPfOnTypeChange(this,'bn-ef-opts-<?php echo absint( $fid ); ?>','bn-ef-date-<?php echo absint( $fid ); ?>')">
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
												<button type="button" class="button" onclick="bnPfToggleEdit('<?php echo esc_js( $edit_panel_id ); ?>')"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
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
									onchange="bnPfOnTypeChange(this,'bn-af-opts-<?php echo absint( $gid ); ?>','bn-af-date-<?php echo absint( $gid ); ?>')">
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
							<button type="button" class="button" onclick="bnPfToggle('<?php echo esc_js( $panel_id ); ?>')"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
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

		<script>
		var BN_CHOICE_TYPES = ['select', 'multiselect', 'radio', 'checkbox'];
		var BN_DATE_TYPES   = ['date', 'daterange'];

		function bnPfToggle(panelId) {
			var el = document.getElementById(panelId);
			if (!el) return;
			if (el.classList.contains('bn-open')) {
				el.classList.remove('bn-open');
			} else {
				document.querySelectorAll('.bn-pf-af-panel.bn-open').forEach(function(p) { p.classList.remove('bn-open'); });
				el.classList.add('bn-open');
				var inp = el.querySelector('input[type="text"]');
				if (inp) { inp.focus(); }
			}
		}

		function bnPfToggleEdit(rowId) {
			var row = document.getElementById(rowId);
			if (!row) return;
			var isHidden = (row.style.display === 'none' || row.style.display === '');
			document.querySelectorAll('tr[id^="bn-ef-row-"]').forEach(function(r) {
				r.style.display = 'none';
			});
			if (isHidden) {
				row.style.display = 'table-row';
			}
		}

		function bnPfOnTypeChange(selectEl, optWrapId, dateWrapId) {
			var type     = selectEl.value;
			var optWrap  = document.getElementById(optWrapId);
			var dateWrap = dateWrapId ? document.getElementById(dateWrapId) : null;
			if (optWrap)  { optWrap.style.display  = BN_CHOICE_TYPES.indexOf(type) >= 0 ? 'block' : 'none'; }
			if (dateWrap) { dateWrap.style.display = BN_DATE_TYPES.indexOf(type) >= 0 ? 'block' : 'none'; }
		}

		// Inline two-step delete confirmation — no browser dialogs.
		document.addEventListener('click', function(e) {
			if (e.target.matches('.bn-del-trigger')) {
				var form = e.target.closest('.bn-del-form');
				if (!form) return;
				e.target.style.display = 'none';
				form.querySelector('.bn-del-confirm').style.display = 'inline-flex';
				form.querySelector('.bn-del-cancel').style.display  = 'inline-flex';
			}
			if (e.target.matches('.bn-del-cancel')) {
				var form = e.target.closest('.bn-del-form');
				if (!form) return;
				form.querySelector('.bn-del-trigger').style.display = 'inline-flex';
				form.querySelector('.bn-del-confirm').style.display = 'none';
				form.querySelector('.bn-del-cancel').style.display  = 'none';
			}
		});
		</script>
		<?php
	}
}

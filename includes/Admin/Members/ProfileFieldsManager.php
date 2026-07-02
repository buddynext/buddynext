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
	 * Return the full field-type matrix the admin editor offers.
	 *
	 * The single source of truth is BuddyNext\Profile\FieldType::types()
	 * (workstream F). Each entry is keyed by slug and carries:
	 *   - label                 (string) Human-readable name.
	 *   - is_choice             (bool)   Needs an options editor (select/radio/multiselect).
	 *   - is_searchable_capable (bool)   May be flagged is_searchable in the directory.
	 *   - value_kind            (string) scalar|multi|bool.
	 *
	 * Falls back to a built-in matrix that mirrors the
	 * member-fields-search-privacy.yaml contract when the engine class is not
	 * yet loaded, so the editor keeps working during parallel development.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label:string,is_choice:bool,is_searchable_capable:bool,value_kind:string}>
	 */
	public static function field_type_matrix(): array {
		if ( class_exists( '\\BuddyNext\\Profile\\FieldType' ) ) {
			$matrix = array();
			foreach ( (array) \BuddyNext\Profile\FieldType::types() as $slug => $meta ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug ) {
					continue;
				}
				$matrix[ $slug ] = array(
					'label'                 => (string) ( $meta['label'] ?? ucfirst( $slug ) ),
					'is_choice'             => ! empty( $meta['is_choice'] ),
					'is_searchable_capable' => ! empty( $meta['is_searchable_capable'] ),
					'value_kind'            => (string) ( $meta['value_kind'] ?? 'scalar' ),
				);
			}

			if ( ! empty( $matrix ) ) {
				return $matrix;
			}
		}

		// Fallback mirrors contracts.field_types from the build spec.
		return array(
			'text'        => array(
				'label'                 => __( 'Short Text', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'textarea'    => array(
				'label'                 => __( 'Long Text', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'url'         => array(
				'label'                 => __( 'URL', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'email'       => array(
				'label'                 => __( 'Email', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'phone'       => array(
				'label'                 => __( 'Phone', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'number'      => array(
				'label'                 => __( 'Number', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => false,
				'value_kind'            => 'scalar',
			),
			'date'        => array(
				'label'                 => __( 'Date', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => false,
				'value_kind'            => 'scalar',
			),
			'boolean'     => array(
				'label'                 => __( 'Yes / No', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => false,
				'value_kind'            => 'bool',
			),
			'select'      => array(
				'label'                 => __( 'Dropdown', 'buddynext' ),
				'is_choice'             => true,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'radio'       => array(
				'label'                 => __( 'Radio Buttons', 'buddynext' ),
				'is_choice'             => true,
				'is_searchable_capable' => true,
				'value_kind'            => 'scalar',
			),
			'multiselect' => array(
				'label'                 => __( 'Multi-select', 'buddynext' ),
				'is_choice'             => true,
				'is_searchable_capable' => true,
				'value_kind'            => 'multi',
			),
			'color'       => array(
				'label'                 => __( 'Color', 'buddynext' ),
				'is_choice'             => false,
				'is_searchable_capable' => false,
				'value_kind'            => 'scalar',
			),
		);
	}

	/**
	 * Return the filterable list of allowed profile field type slugs.
	 *
	 * Pro plugins add custom field types (e.g. 'file', 'video', 'map') by
	 * hooking buddynext_field_types (in FieldType) or buddynext_profile_field_types.
	 * The whitelist enforcement in handle_create_field() and handle_edit_field()
	 * calls this method so registered types are automatically accepted.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Ordered list of field type slugs.
	 */
	public static function field_types(): array {
		$types = array_keys( self::field_type_matrix() );

		/**
		 * Filter the allowed profile field type slugs.
		 *
		 * Return an array of lowercase, hyphen/underscore-safe slugs. Each
		 * type must be handled by BuddyNext\Profile\FieldType.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types The current list of field type slugs.
		 */
		return (array) apply_filters( 'buddynext_profile_field_types', $types );
	}

	/**
	 * Slugs of field types that need an options editor (select/radio/multiselect).
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private static function choice_types(): array {
		$slugs = array();
		foreach ( self::field_type_matrix() as $slug => $meta ) {
			if ( ! empty( $meta['is_choice'] ) ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Slugs of field types that may be flagged as searchable in the directory.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private static function searchable_capable_types(): array {
		$slugs = array();
		foreach ( self::field_type_matrix() as $slug => $meta ) {
			if ( ! empty( $meta['is_searchable_capable'] ) ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

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
	 * Matches contracts.visibility_enum in the build spec.
	 *
	 * @var string[]
	 */
	private const VISIBILITY_VALUES = array( 'public', 'followers', 'connections', 'private' );

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
			array( 'wp-i18n' ),
			BUDDYNEXT_VERSION,
			true
		);

		wp_set_script_translations( 'bn-profile-fields', 'buddynext', BUDDYNEXT_DIR . 'languages' );

		// Expose the field-type matrix to the editor JS so the options editor
		// and the is_searchable control react to the selected type. The
		// bn-admin-members handle (members.js) is registered on this page by
		// BuddyNext\Admin\Members and carries the type-driven behaviour.
		$matrix    = self::field_type_matrix();
		$js_matrix = array();
		foreach ( $matrix as $slug => $meta ) {
			$js_matrix[ $slug ] = array(
				'isChoice'            => (bool) $meta['is_choice'],
				'isSearchableCapable' => (bool) $meta['is_searchable_capable'],
				'valueKind'           => (string) $meta['value_kind'],
			);
		}

		$handle = wp_script_is( 'bn-admin-members', 'enqueued' ) || wp_script_is( 'bn-admin-members', 'registered' )
			? 'bn-admin-members'
			: 'bn-profile-fields';

		wp_localize_script(
			$handle,
			'bnProfileFieldTypes',
			$js_matrix
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
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
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

		if ( ! in_array( $type, self::field_types(), true ) ) {
			$type = 'text';
		}

		if ( ! in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
			$visibility = 'public';
		}

		// is_searchable only applies to types that support free-text search.
		$is_searchable = ( isset( $_POST['is_searchable'] ) && in_array( $type, self::searchable_capable_types(), true ) ) ? 1 : 0;

		// Route options by field type.
		if ( in_array( $type, self::choice_types(), true ) ) {
			$options_raw = sanitize_textarea_field( wp_unslash( $_POST['options'] ?? '' ) );
			$parsed_opts = $this->parse_options_textarea( $options_raw );
		} elseif ( in_array( $type, self::DATE_TYPES, true ) ) {
			$date_display = sanitize_key( wp_unslash( $_POST['date_display'] ?? 'date' ) );
			$parsed_opts  = array( 'display' => in_array( $date_display, self::DATE_DISPLAY, true ) ? $date_display : 'date' );
		} else {
			$parsed_opts = null;
		}

		// Merge per-type option config (file MIME, number min/max/unit,
		// conditional trigger, advanced choices) submitted by Pro field types
		// under bn_field_options[*]. Merged — not overwritten — so a choice
		// type's core `options` list and Pro's extra keys coexist.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer() above; sanitized via sanitize_field_options().
		$pro_opts = $this->sanitize_field_options( isset( $_POST['bn_field_options'] ) ? wp_unslash( $_POST['bn_field_options'] ) : null );
		if ( null !== $pro_opts ) {
			$parsed_opts = is_array( $parsed_opts )
				? array_merge( $parsed_opts, $pro_opts )
				: $pro_opts;
		}

		// Auto-generate field_key from label — no technical input required from admins.
		$field_key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );

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
					'group_id'      => $group_id,
					'field_key'     => $field_key,
					'label'         => $label,
					'type'          => $type,
					'options'       => $parsed_opts,
					'is_required'   => $is_required > 0 ? 1 : 0,
					'is_searchable' => $is_searchable,
					'visibility'    => $visibility,
					'sort_order'    => $sort_order,
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize the per-type option matrix submitted under bn_field_options[*].
	 *
	 * Pro field types (file, number_advanced, conditional, multi_select_advanced,
	 * etc.) render their config inputs into Free's field-builder form under the
	 * shared `bn_field_options` array. Each key is sanitized to the kind the
	 * downstream renderer/validator expects:
	 *
	 *   - allowed_mime ............ comma-separated MIME list (text)
	 *   - max_size_mb ............. positive integer
	 *   - unit .................... text label
	 *   - min / max / step ........ numeric (decimals preserved; the inputs use
	 *                               step="any" and the renderer casts to float)
	 *   - trigger_field_id ........ positive integer (field row id)
	 *   - trigger_value ........... text
	 *   - choices_raw ............. one "value|label" per line → choices array
	 *
	 * Unknown keys pass through sanitize_text_field so future Pro types degrade
	 * gracefully without a Free code change. Returns null when nothing usable was
	 * submitted so callers can leave the options column untouched.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $raw Raw $_POST['bn_field_options'] value (already unslashed).
	 * @return array<string,mixed>|null Sanitized option map, or null when empty.
	 */
	private function sanitize_field_options( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$out = array();

		foreach ( $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			switch ( $key ) {
				case 'max_size_mb':
				case 'trigger_field_id':
					$int = absint( $value );
					if ( $int > 0 ) {
						$out[ $key ] = $int;
					}
					break;

				case 'min':
				case 'max':
				case 'step':
					// Numeric; preserve decimals (inputs use step="any"). Blank
					// means "no constraint" so skip rather than store 0.
					$num = is_scalar( $value ) ? trim( (string) $value ) : '';
					if ( '' !== $num && is_numeric( $num ) ) {
						$out[ $key ] = ( false === strpos( $num, '.' ) )
							? (int) $num
							: (float) $num;
					}
					break;

				case 'choices_raw':
					$choices = $this->parse_choice_pairs( is_scalar( $value ) ? (string) $value : '' );
					if ( null !== $choices ) {
						$out['choices'] = $choices;
					}
					break;

				case 'allowed_mime':
				case 'unit':
				case 'trigger_value':
				default:
					$clean = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
					if ( '' !== $clean ) {
						$out[ $key ] = $clean;
					}
					break;
			}
		}

		return ! empty( $out ) ? $out : null;
	}

	/**
	 * Parse a "value|Label" textarea (one pair per line) into a choices array.
	 *
	 * The pipe separator is optional: a bare line uses its value as both the
	 * stored value and the display label. Matches the shape the multi-select
	 * renderer and the Pro options editor read back (`options['choices']`).
	 *
	 * @since 1.1.0
	 *
	 * @param string $raw Raw textarea content.
	 * @return array<int,array{value:string,label:string}>|null Choices, or null when blank.
	 */
	private function parse_choice_pairs( string $raw ): ?array {
		if ( '' === trim( $raw ) ) {
			return null;
		}

		$choices = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			if ( false !== strpos( $line, '|' ) ) {
				list( $value, $label ) = array_pad( explode( '|', $line, 2 ), 2, '' );
			} else {
				$value = $line;
				$label = $line;
			}

			$value = sanitize_text_field( $value );
			$label = sanitize_text_field( '' !== trim( (string) $label ) ? $label : $value );

			if ( '' !== $value ) {
				$choices[] = array(
					'value' => $value,
					'label' => $label,
				);
			}
		}

		return ! empty( $choices ) ? $choices : null;
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

		$notice = 'deleted';
		if ( $group_id > 0 ) {
			global $wpdb;

			$service = buddynext_service( 'profiles' );

			// §4.2 impact-confirm: when the group's fields hold stored values,
			// the delete must arrive with a matching type-to-confirm token (the
			// group name or DELETE). The admin UI collects it; this server check
			// is the enforcement, so a hand-crafted POST cannot skip it.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$group_label = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT label FROM {$wpdb->prefix}bn_profile_groups WHERE id = %d", $group_id )
			);
			$field_ids   = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d", $group_id )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$affected = $service->count_users_with_field_values( $field_ids );

			if ( $affected > 0 && ! $this->confirm_text_matches( $group_label ) ) {
				$notice = 'confirm';
			} else {
				$result = $service->delete_group( $group_id );
				if ( is_wp_error( $result ) ) {
					// System groups refuse deletion — surface the refusal instead
					// of a false "Deleted." success.
					$notice = 'locked';
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => $notice,
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

		$notice = 'deleted';
		if ( $field_id > 0 ) {
			global $wpdb;

			$service = buddynext_service( 'profiles' );

			// §4.2 impact-confirm: a field with stored member values only deletes
			// when the request carries a matching type-to-confirm token (the
			// field name or DELETE). Server-side enforcement — the UI input alone
			// is not the gate.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$field_label = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT label FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$affected = $service->count_users_with_field_values( array( $field_id ) );

			if ( $affected > 0 && ! $this->confirm_text_matches( $field_label ) ) {
				$notice = 'confirm';
			} else {
				$result = $service->delete_field( $field_id );
				if ( is_wp_error( $result ) ) {
					// System fields refuse deletion — surface the refusal instead
					// of a false "Deleted." success.
					$notice = 'locked';
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Whether the submitted type-to-confirm token authorises a destructive
	 * delete (§4.2).
	 *
	 * Accepts the item's current name or the literal word DELETE, both
	 * case-insensitively and whitespace-trimmed. Called only after the nonce
	 * has been verified by the delete handlers.
	 *
	 * @param string $label Current field/group label the admin must retype.
	 * @return bool
	 */
	private function confirm_text_matches( string $label ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the calling delete handler.
		$input = trim( sanitize_text_field( wp_unslash( $_POST['bn_confirm_text'] ?? '' ) ) );

		if ( '' === $input ) {
			return false;
		}

		if ( 0 === strcasecmp( $input, 'DELETE' ) ) {
			return true;
		}

		return mb_strtolower( $input ) === mb_strtolower( trim( $label ) );
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
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
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
						'page'         => 'buddynext-members',
						'tab'          => 'profile-fields',
						'bn_pf_notice' => 'error',
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
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
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
						'page'         => 'buddynext-members',
						'tab'          => 'profile-fields',
						'bn_pf_notice' => 'error',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$label      = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$type       = sanitize_key( wp_unslash( $_POST['type'] ?? 'text' ) );
		$visibility = sanitize_key( wp_unslash( $_POST['visibility'] ?? 'public' ) );

		if ( ! in_array( $type, self::field_types(), true ) ) {
			$type = 'text';
		}

		if ( ! in_array( $visibility, self::VISIBILITY_VALUES, true ) ) {
			$visibility = 'public';
		}

		// Route options by field type.
		if ( in_array( $type, self::choice_types(), true ) ) {
			$options_raw = sanitize_textarea_field( wp_unslash( $_POST['options'] ?? '' ) );
			$parsed_opts = $this->parse_options_textarea( $options_raw );
		} elseif ( in_array( $type, self::DATE_TYPES, true ) ) {
			$date_display = sanitize_key( wp_unslash( $_POST['date_display'] ?? 'date' ) );
			$parsed_opts  = array( 'display' => in_array( $date_display, self::DATE_DISPLAY, true ) ? $date_display : 'date' );
		} else {
			$parsed_opts = null;
		}

		// Merge per-type option config (file MIME, number min/max/unit,
		// conditional trigger, advanced choices) submitted by Pro field types
		// under bn_field_options[*]. Merged — not overwritten — so a choice
		// type's core `options` list and Pro's extra keys coexist.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via check_admin_referer() above; sanitized via sanitize_field_options().
		$pro_opts = $this->sanitize_field_options( isset( $_POST['bn_field_options'] ) ? wp_unslash( $_POST['bn_field_options'] ) : null );
		if ( null !== $pro_opts ) {
			$parsed_opts = is_array( $parsed_opts )
				? array_merge( $parsed_opts, $pro_opts )
				: $pro_opts;
		}

		$is_required      = isset( $_POST['is_required'] ) ? 1 : 0;
		$is_searchable    = ( isset( $_POST['is_searchable'] ) && in_array( $type, self::searchable_capable_types(), true ) ) ? 1 : 0;
		$show_on_register = isset( $_POST['show_on_register'] ) ? 1 : 0;

		if ( '' === $label ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => 'buddynext-members',
						'tab'          => 'profile-fields',
						'bn_pf_notice' => 'error',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'label'            => $label,
				'type'             => $type,
				'options'          => null !== $parsed_opts ? wp_json_encode( $parsed_opts ) : null,
				'is_required'      => $is_required,
				'is_searchable'    => $is_searchable,
				'show_on_register' => $show_on_register,
				'visibility'       => $visibility,
			),
			array( 'id' => $field_id ),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);

		// is_searchable changed → ProfileService rebuilds the searchable mirror
		// for affected users via the searchable_mirror contract.
		do_action( 'buddynext_profile_field_updated', $field_id );

		wp_cache_delete( 'all_fields', 'buddynext_profiles' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
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
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
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
					'page'         => 'buddynext-members',
					'tab'          => 'profile-fields',
					'bn_pf_notice' => 'saved',
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
		// CRUD result feedback. Every create/update/delete/reorder handler redirects
		// here with bn_pf_notice (the screen used to render nothing, so saves and
		// failures alike were silent).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bn_pf_notice = isset( $_GET['bn_pf_notice'] ) ? sanitize_key( wp_unslash( $_GET['bn_pf_notice'] ) ) : '';
		if ( 'saved' === $bn_pf_notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Profile fields saved.', 'buddynext' ) . '</p></div>';
		} elseif ( 'deleted' === $bn_pf_notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'buddynext' ) . '</p></div>';
		} elseif ( 'error' === $bn_pf_notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Not saved — please check the field name and try again.', 'buddynext' ) . '</p></div>';
		} elseif ( 'locked' === $bn_pf_notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'This is a core field used by search and member cards - it cannot be deleted.', 'buddynext' ) . '</p></div>';
		} elseif ( 'confirm' === $bn_pf_notice ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Not deleted - the confirmation text did not match. Type the exact name (or DELETE) to remove an item that has stored member values.', 'buddynext' ) . '</p></div>';
		}

		$groups      = buddynext_service( 'profiles' )->get_fields();
		$post_url    = admin_url( 'admin-post.php' );
		$base_url    = admin_url( 'admin.php?page=buddynext-members&tab=profile-fields' );
		$group_count = count( $groups );

		// §4.2 impact-confirm: per-field and per-group affected-member counts
		// (two aggregate queries total). A field/group with stored values renders
		// a type-to-confirm step on its delete control; the delete handlers
		// re-verify server-side.
		$impact_counts = buddynext_service( 'profiles' )->value_user_counts();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$show_add_group = absint( wp_unslash( $_GET['add_group'] ?? 0 ) );
		$add_group_url  = add_query_arg( 'add_group', '1', $base_url );

		// Single source of truth for type behaviour — drives the dropdown,
		// the options editor, and the searchable control.
		$type_matrix       = self::field_type_matrix();
		$field_type_labels = array();
		foreach ( $type_matrix as $slug => $meta ) {
			$field_type_labels[ $slug ] = (string) $meta['label'];
		}

		/**
		 * Filter the human-readable labels for profile field types shown in
		 * the admin field builder.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $field_type_labels Map of slug => label.
		 */
		$field_type_labels = (array) apply_filters( 'buddynext_profile_field_type_labels', $field_type_labels );

		// Choice + searchable-capable type slugs, for per-option data attributes.
		$choice_type_slugs     = self::choice_types();
		$searchable_type_slugs = self::searchable_capable_types();

		$vis_labels = array(
			'public'      => __( 'Public', 'buddynext' ),
			'followers'   => __( 'Followers only', 'buddynext' ),
			'connections' => __( 'Connections only', 'buddynext' ),
			'private'     => __( 'Only me', 'buddynext' ),
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
			<div class="bn-settings-section bn-pf-card">

				<!-- Group header -->
				<div class="bn-ss-header bn-pf-card-head">
					<span class="bn-ss-title bn-pf-group-name"><?php echo esc_html( $group['label'] ); ?></span>

					<div class="bn-pf-meta">
						<?php if ( $is_system ) : ?>
							<span class="bn-pf-badge bn-pf-b-system"><?php esc_html_e( 'System', 'buddynext' ); ?></span>
						<?php endif; ?>

						<span class="bn-pf-badge <?php echo 'repeater' === $group['type'] ? 'bn-pf-b-repeater' : 'bn-pf-b-flat'; ?>">
							<?php echo 'repeater' === $group['type'] ? esc_html__( 'Multiple entries', 'buddynext' ) : esc_html__( 'Single entry', 'buddynext' ); ?>
						</span>

						<!-- Inline group visibility -->
						<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
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
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
								<input type="hidden" name="action" value="bn_reorder_group">
								<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
								<input type="hidden" name="direction" value="up">
								<?php wp_nonce_field( 'bn_reorder_group_' . $gid ); ?>
								<button type="submit" class="bn-pf-icon-btn" title="<?php esc_attr_e( 'Move group up', 'buddynext' ); ?>">↑</button>
							</form>
						<?php endif; ?>

						<?php if ( ! $is_last_grp ) : ?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
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
							<?php
							$del_nonce    = wp_create_nonce( 'bn_delete_profile_group_' . $gid );
							$grp_affected = (int) ( $impact_counts['groups'][ $gid ] ?? 0 );
							?>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form bn-del-form"
								<?php if ( $grp_affected > 0 ) : ?>
									data-bn-confirm-label="<?php echo esc_attr( $group['label'] ); ?>"
								<?php endif; ?>
							>
								<input type="hidden" name="action" value="bn_delete_profile_group">
								<input type="hidden" name="group_id" value="<?php echo absint( $gid ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $del_nonce ); ?>">
								<button type="button" class="bn-pf-del-group-btn bn-del-trigger"><?php esc_html_e( 'Delete Group', 'buddynext' ); ?></button>
								<?php if ( $grp_affected > 0 ) : ?>
									<span class="bn-del-impact" hidden>
										<span class="bn-del-impact-text">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: number of members with stored values, 2: group name. */
													_n(
														'This permanently deletes stored values for %1$d member. Type "%2$s" or DELETE to confirm.',
														'This permanently deletes stored values for %1$d members. Type "%2$s" or DELETE to confirm.',
														$grp_affected,
														'buddynext'
													),
													$grp_affected,
													$group['label']
												)
											);
											?>
										</span>
										<input type="text" name="bn_confirm_text" class="bn-del-confirm-input"
											autocomplete="off"
											aria-label="<?php esc_attr_e( 'Type the name or DELETE to confirm', 'buddynext' ); ?>">
									</span>
								<?php endif; ?>
								<button type="submit" class="bn-del-confirm" style="display:none;" <?php disabled( $grp_affected > 0 ); ?>><?php esc_html_e( 'Yes, delete', 'buddynext' ); ?></button>
								<button type="button" class="bn-del-cancel" style="display:none;"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
							</form>
						<?php endif; ?>
					</div><!-- .bn-pf-head-actions -->
				</div><!-- .bn-ss-header / .bn-pf-card-head -->

				<div class="bn-ss-body bn-pf-card-body">
				<!-- Fields table -->
				<?php if ( ! empty( $group['fields'] ) ) : ?>
					<table class="bn-pf-table">
						<thead>
							<tr>
								<th class="bn-a-pf-swatch"><?php esc_html_e( 'Order', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Field Name', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Type', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Required', 'buddynext' ); ?></th>
								<th><?php esc_html_e( 'Visible to', 'buddynext' ); ?></th>
								<th class="bn-a-pf-swatch"></th>
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
											<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
												<input type="hidden" name="action" value="bn_reorder_field">
												<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
												<input type="hidden" name="direction" value="up">
												<?php wp_nonce_field( 'bn_reorder_field_' . $fid ); ?>
												<button type="submit" class="bn-pf-icon-btn" title="<?php esc_attr_e( 'Move up', 'buddynext' ); ?>">↑</button>
											</form>
										<?php endif; ?>
										<?php if ( ! $is_last_fld ) : ?>
											<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
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
								<td><span class="bn-badge" data-tone="neutral"><?php echo esc_html( $type_lbl ); ?></span></td>

								<!-- Required inline toggle -->
								<td>
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
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
									<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form">
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
										<?php if ( ! empty( $field['is_system'] ) ) : ?>
											<?php // System field: no delete control (the service guard also refuses direct requests). Relabel/reorder/visibility stay editable. ?>
											<span class="bn-badge" data-tone="neutral" title="<?php esc_attr_e( 'Core field - used by search and member cards. It cannot be deleted.', 'buddynext' ); ?>"><?php esc_html_e( 'Core', 'buddynext' ); ?></span>
										<?php else : ?>
											<?php $fld_affected = (int) ( $impact_counts['fields'][ $fid ] ?? 0 ); ?>
											<form method="post" action="<?php echo esc_url( $post_url ); ?>" class="bn-pf-inline-form bn-del-form"
												<?php if ( $fld_affected > 0 ) : ?>
													data-bn-confirm-label="<?php echo esc_attr( $field['label'] ); ?>"
												<?php endif; ?>
											>
												<input type="hidden" name="action" value="bn_delete_profile_field">
												<input type="hidden" name="field_id" value="<?php echo absint( $fid ); ?>">
												<?php wp_nonce_field( 'bn_delete_profile_field_' . $fid ); ?>
												<button type="button" class="bn-pf-del-field bn-del-trigger" title="<?php esc_attr_e( 'Remove field', 'buddynext' ); ?>"><?php buddynext_icon( 'x' ); ?></button>
												<?php if ( $fld_affected > 0 ) : ?>
													<span class="bn-del-impact" hidden>
														<span class="bn-del-impact-text">
															<?php
															echo esc_html(
																sprintf(
																	/* translators: 1: number of members with stored values, 2: field name. */
																	_n(
																		'This permanently deletes stored values for %1$d member. Type "%2$s" or DELETE to confirm.',
																		'This permanently deletes stored values for %1$d members. Type "%2$s" or DELETE to confirm.',
																		$fld_affected,
																		'buddynext'
																	),
																	$fld_affected,
																	$field['label']
																)
															);
															?>
														</span>
														<input type="text" name="bn_confirm_text" class="bn-del-confirm-input"
															autocomplete="off"
															aria-label="<?php esc_attr_e( 'Type the name or DELETE to confirm', 'buddynext' ); ?>">
													</span>
												<?php endif; ?>
												<button type="submit" class="bn-del-confirm" style="display:none;" <?php disabled( $fld_affected > 0 ); ?>><?php esc_html_e( 'Delete?', 'buddynext' ); ?></button>
												<button type="button" class="bn-del-cancel" style="display:none;"><?php esc_html_e( 'No', 'buddynext' ); ?></button>
											</form>
										<?php endif; ?>
									</div>
								</td>
							</tr>
							<!-- Edit panel row -->
							<?php
							$edit_panel_id     = 'bn-ef-row-' . $fid;
							$is_choice_type    = in_array( $field['type'], $choice_type_slugs, true );
							$is_date_type      = in_array( $field['type'], self::DATE_TYPES, true );
							$is_search_capable = in_array( $field['type'], $searchable_type_slugs, true );
							$field_searchable  = ! empty( $field['is_searchable'] );
							$opts_text         = $is_choice_type && ! empty( $field['options'] ) ? implode( "\n", (array) $field['options'] ) : '';
							$date_display_val  = ( $is_date_type && is_array( $field['options'] ) ) ? ( $field['options']['display'] ?? 'date' ) : 'date';
							?>
							<tr id="<?php echo esc_attr( $edit_panel_id ); ?>" style="display:none;">
								<td colspan="6" class="bn-a-flush">
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
												<div class="bn-pf-af-field bn-a-pf-col">
													<label for="bn-ef-type-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Field Type', 'buddynext' ); ?></label>
													<select id="bn-ef-type-<?php echo absint( $fid ); ?>" name="type"
														data-bn-pf-opts-wrap="bn-ef-opts-<?php echo absint( $fid ); ?>"
														data-bn-pf-date-wrap="bn-ef-date-<?php echo absint( $fid ); ?>"
														data-bn-pf-search-wrap="bn-ef-search-<?php echo absint( $fid ); ?>">
														<?php foreach ( $field_type_labels as $ft_val => $ft_lbl ) : ?>
														<option value="<?php echo esc_attr( $ft_val ); ?>"
															data-is-choice="<?php echo in_array( (string) $ft_val, $choice_type_slugs, true ) ? '1' : '0'; ?>"
															data-is-searchable-capable="<?php echo in_array( (string) $ft_val, $searchable_type_slugs, true ) ? '1' : '0'; ?>"
															<?php selected( $field['type'], $ft_val ); ?>>
															<?php echo esc_html( $ft_lbl ); ?>
														</option>
														<?php endforeach; ?>
													</select>
												</div>
												<div class="bn-pf-af-field bn-a-pf-col-narrow">
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
											// Contained in a table: the buddynext_profile_field_type_options
											// contract emits <tr><td> rows (Pro's AdvancedFieldsAdmin), which
											// are invalid in this div-based panel and would foster-parent out,
											// breaking the panel. The wrapper keeps the rows valid + contained.
											?>
											<table class="bn-pf-hook-rows"><tbody>
												<?php do_action( 'buddynext_profile_field_type_options', (string) $field['type'], $field ); ?>
											</tbody></table>
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
											<div id="bn-ef-search-<?php echo absint( $fid ); ?>" class="bn-pf-af-req-row" style="<?php echo $is_search_capable ? '' : 'display:none;'; ?>">
												<input type="checkbox" id="bn-ef-search-c-<?php echo absint( $fid ); ?>" name="is_searchable" value="1" <?php checked( $field_searchable ); ?>>
												<label for="bn-ef-search-c-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Searchable in the member directory', 'buddynext' ); ?></label>
											</div>
											<?php // Registration form opt-in. Single-entry groups only — a signup form cannot collect repeating entries. ?>
											<?php if ( 'repeater' !== $group['type'] ) : ?>
												<div class="bn-pf-af-req-row">
													<input type="checkbox" id="bn-ef-reg-<?php echo absint( $fid ); ?>" name="show_on_register" value="1" <?php checked( ! empty( $field['show_on_register'] ) ); ?>>
													<label for="bn-ef-reg-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Ask for this on the registration form', 'buddynext' ); ?></label>
												</div>
											<?php endif; ?>
											<div class="bn-pf-af-actions">
												<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save Changes', 'buddynext' ); ?></button>
												<button type="button" class="bn-btn" data-variant="secondary" data-bn-pf-toggle-edit="<?php echo esc_attr( $edit_panel_id ); ?>"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
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
							<div class="bn-pf-af-field bn-a-pf-col">
								<label for="bn-af-type-<?php echo absint( $gid ); ?>"><?php esc_html_e( 'Field Type', 'buddynext' ); ?></label>
								<select id="bn-af-type-<?php echo absint( $gid ); ?>" name="type"
									data-bn-pf-opts-wrap="bn-af-opts-<?php echo absint( $gid ); ?>"
									data-bn-pf-date-wrap="bn-af-date-<?php echo absint( $gid ); ?>"
									data-bn-pf-search-wrap="bn-af-search-<?php echo absint( $gid ); ?>">
									<?php foreach ( $field_type_labels as $ft_val => $ft_lbl ) : ?>
										<option value="<?php echo esc_attr( $ft_val ); ?>"
											data-is-choice="<?php echo in_array( (string) $ft_val, $choice_type_slugs, true ) ? '1' : '0'; ?>"
											data-is-searchable-capable="<?php echo in_array( (string) $ft_val, $searchable_type_slugs, true ) ? '1' : '0'; ?>"><?php echo esc_html( $ft_lbl ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="bn-pf-af-field bn-a-pf-col-narrow">
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
						// Contained in a table (see the matching note in the edit panel):
						// the hook contract emits <tr><td> rows, invalid in this div panel.
						?>
						<table class="bn-pf-hook-rows"><tbody>
							<?php do_action( 'buddynext_profile_field_type_options', '', array() ); ?>
						</tbody></table>
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
						<?php
						// The first option in the type dropdown is searchable-capable, so the
						// control starts visible; members.js hides it for non-capable types.
						$first_type_searchable = ! empty( $field_type_labels ) && in_array( (string) array_key_first( $field_type_labels ), $searchable_type_slugs, true );
						?>
						<div id="bn-af-search-<?php echo absint( $gid ); ?>" class="bn-pf-af-req-row" style="<?php echo $first_type_searchable ? '' : 'display:none;'; ?>">
							<input type="checkbox" id="bn-af-search-c-<?php echo absint( $gid ); ?>" name="is_searchable" value="1">
							<label for="bn-af-search-c-<?php echo absint( $gid ); ?>"><?php esc_html_e( 'Searchable in the member directory', 'buddynext' ); ?></label>
						</div>
						<div class="bn-pf-af-actions">
							<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Save Field', 'buddynext' ); ?></button>
							<button type="button" class="bn-btn" data-variant="secondary" data-bn-pf-toggle="<?php echo esc_attr( $panel_id ); ?>"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></button>
						</div>
					</form>
				</div><!-- .bn-pf-af-panel -->

			</div><!-- .bn-ss-body / .bn-pf-card-body -->
			</div><!-- .bn-settings-section / .bn-pf-card -->
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
							<div class="bn-pf-ag-field bn-a-pf-col-wide">
								<label for="bn-ag-type"><?php esc_html_e( 'Group Type', 'buddynext' ); ?></label>
								<select id="bn-ag-type" name="type">
									<option value="flat"><?php esc_html_e( 'Single entry', 'buddynext' ); ?></option>
									<option value="repeater"><?php esc_html_e( 'Multiple entries (e.g. past jobs)', 'buddynext' ); ?></option>
								</select>
							</div>
							<div class="bn-pf-ag-field bn-a-pf-col">
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
							<button type="submit" class="bn-btn" data-variant="primary"><?php esc_html_e( 'Create Group', 'buddynext' ); ?></button>
							<a href="<?php echo esc_url( $base_url ); ?>" class="bn-btn" data-variant="secondary"><?php esc_html_e( 'Cancel', 'buddynext' ); ?></a>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</div>

		</div><!-- .bn-pf-wrap -->
		<?php
	}
}
